<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Enums\Estado;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\Enums\OrigemCobranca;
use Modules\Financeiro\Models\Cobranca;
use Modules\Financeiro\Models\CobrancaItem;
use Modules\Financeiro\Models\Produto;
use Modules\Financeiro\Models\ProdutoVariante;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Models\Servico;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Support\BloqueioDePagamentos;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Support\DividaDoAluno;
use Modules\Matricula\Models\Matricula;
use Modules\Core\Traits\LogService;

/**
 * Cria uma cobrança de produtos e serviços para uma matrícula, numa transacção: valida o catálogo (itens activos),
 * a regra «situação regularizada» (só serviços, só cobranças manuais), abate o stock dos produtos que o controlam e
 * grava as linhas com cópia do nome e do preço. Nunca altera propinas.
 */
class CriarCobrancaAction
{
    use LogService;

    public const MAX_QUANTIDADE = 9999;

    public function __construct(
        private BloqueioDePagamentos $bloqueio,
        private DividaDoAluno $divida,
        private MoedaDoTenant $moeda,
        private RelogioDoTenant $relogio,
    ) {
    }

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Matricula $matricula, array $itens, ?string $dataLimite = null, ?string $observacao = null, OrigemCobranca $origem = OrigemCobranca::MANUAL, ?int $utilizadorId = null): Cobranca
    {
        return $this->comLog('Criar cobrança', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($matricula, $itens, $dataLimite, $observacao, $origem, $utilizadorId));
    }

    /**
     * @param  list<array{tipo: string, id: int, quantidade: int, variante_id?: ?int}>  $itens
     */
    private function executarSemRegisto(Matricula $matricula, array $itens, ?string $dataLimite = null, ?string $observacao = null, OrigemCobranca $origem = OrigemCobranca::MANUAL, ?int $utilizadorId = null): Cobranca
    {
        return DB::transaction(function () use ($matricula, $itens, $dataLimite, $observacao, $origem, $utilizadorId) {
            $bloqueada = $this->bloqueio->matricula((int) $matricula->getKey())
                ?? throw ValidationException::withMessages(['matricula' => 'A matrícula não existe ou foi eliminada.']);

            if ($itens === []) {
                throw ValidationException::withMessages(['itens' => 'Escolha pelo menos um produto ou serviço.']);
            }

            $linhas = [];

            foreach ($itens as $i => $pedido) {
                $linhas[] = $this->preparar($i, $pedido);
            }

            $temServico = collect($linhas)->contains(fn (array $l) => $l['tipo'] === 'servico');

            if ($origem === OrigemCobranca::MANUAL && $temServico && (RegraCobranca::query()->first()?->exigir_regularizacao_servicos ?? true)) {
                $divida = $this->divida->de((int) $bloqueada->aluno_id, $this->relogio->hoje()->toDateString());

                if ($divida['total'] > 0) {
                    throw ValidationException::withMessages(['itens' => 'Só se emitem serviços a quem tem a situação regularizada: o aluno tem dívida por pagar.']);
                }
            }

            $total = array_sum(array_column($linhas, 'total'));
            $cobranca = new Cobranca([
                'matricula_id' => $bloqueada->id,
                'aluno_id' => $bloqueada->aluno_id,
                'moeda' => $this->moeda->atual()->codigo,
                'total' => Dinheiro::deUnidadesMenores($total),
                'origem' => $origem,
                'data_limite' => $dataLimite,
                'observacao' => $observacao,
            ]);
            $cobranca->save();

            foreach ($linhas as $linha) {
                CobrancaItem::create([
                    'cobranca_id' => $cobranca->id,
                    'tipo' => $linha['tipo'],
                    'produto_id' => $linha['produto_id'],
                    'servico_id' => $linha['servico_id'],
                    'variante_id' => $linha['variante_id'],
                    'nome' => $linha['nome'],
                    'codigo' => $linha['codigo'],
                    'variante_nome' => $linha['variante_nome'],
                    'preco_unitario' => Dinheiro::deUnidadesMenores($linha['preco']),
                    'quantidade' => $linha['quantidade'],
                    'total' => Dinheiro::deUnidadesMenores($linha['total']),
                    'stock_abatido' => $linha['stock_abatido'],
                ]);
            }

            return $cobranca->fresh('itens');
        });
    }

    /** @return array<string, mixed> */
    private function preparar(int $indice, array $pedido): array
    {
        $quantidade = (int) ($pedido['quantidade'] ?? 0);

        if ($quantidade < 1 || $quantidade > self::MAX_QUANTIDADE) {
            throw ValidationException::withMessages(["itens.{$indice}.quantidade" => 'A quantidade tem de estar entre 1 e ' . self::MAX_QUANTIDADE . '.']);
        }

        if (($pedido['tipo'] ?? '') === 'servico') {
            $servico = Servico::query()->where('estado', Estado::ATIVO->value)->find($pedido['id'] ?? 0)
                ?? throw ValidationException::withMessages(["itens.{$indice}.id" => 'Escolha um serviço activo.']);

            return $this->linha('servico', null, $servico->id, null, $servico->nome, $servico->codigo, null, $servico->preco->unidadesMenores(), $quantidade, false);
        }

        $produto = Produto::query()->where('estado', Estado::ATIVO->value)->lockForUpdate()->find($pedido['id'] ?? 0)
            ?? throw ValidationException::withMessages(["itens.{$indice}.id" => 'Escolha um produto activo.']);
        $variante = null;

        if ($produto->tem_variantes) {
            $variante = ProdutoVariante::query()->where('produto_id', $produto->id)->lockForUpdate()->find($pedido['variante_id'] ?? 0)
                ?? throw ValidationException::withMessages(["itens.{$indice}.variante_id" => "Escolha o tamanho de «{$produto->nome}»."]);
        }

        $abate = (bool) $produto->controla_stock;

        if ($abate) {
            $alvo = $variante ?? $produto;

            if ($alvo->stock_quantidade < $quantidade) {
                throw ValidationException::withMessages(["itens.{$indice}.quantidade" => "Stock insuficiente de «{$produto->nome}»" . ($variante ? " ({$variante->nome})" : '') . ": restam {$alvo->stock_quantidade}."]);
            }

            $alvo->stock_quantidade -= $quantidade;
            $alvo->save();
        }

        return $this->linha('produto', $produto->id, null, $variante?->id, $produto->nome, $produto->codigo, $variante?->nome, $produto->preco->unidadesMenores(), $quantidade, $abate);
    }

    /** @return array<string, mixed> */
    private function linha(string $tipo, ?int $produtoId, ?int $servicoId, ?int $varianteId, string $nome, ?string $codigo, ?string $varianteNome, int $preco, int $quantidade, bool $stockAbatido): array
    {
        return [
            'tipo' => $tipo, 'produto_id' => $produtoId, 'servico_id' => $servicoId, 'variante_id' => $varianteId,
            'nome' => $nome, 'codigo' => $codigo, 'variante_nome' => $varianteNome,
            'preco' => $preco, 'quantidade' => $quantidade, 'total' => $preco * $quantidade, 'stock_abatido' => $stockAbatido,
        ];
    }
}
