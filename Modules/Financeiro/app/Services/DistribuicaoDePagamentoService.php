<?php

namespace Modules\Financeiro\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\DTO\AplicarCreditoDTO;
use Modules\Financeiro\DTO\LinhaDeDistribuicao;
use Modules\Financeiro\DTO\PlanoDePagamentoDTO;
use Modules\Financeiro\DTO\RegistarPagamentoDTO;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\EstadoCredito;
use Modules\Financeiro\Enums\EstadoPagamento;
use Modules\Financeiro\Models\Credito;
use Modules\Financeiro\Models\Pagamento;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Support\RotuloDePeriodo;
use Modules\Matricula\Models\Matricula;

/**
 * Motor ÚNICO da divisão de uma operação de pagamento (spec Pagamentos §4; D8–D12). Só lê. Usado pela
 * pré-visualização (dados sem bloqueio) e pelas Actions (dados já bloqueados), por isso tudo o que decide vem dos
 * argumentos de `calcular`. Ordem: crédito primeiro, depois dinheiro novo, da propina mais antiga para a mais recente.
 * Pagamentos-B: depois do capital de cada propina, a multa dela (imputação capital → multa) — ponto marcado abaixo.
 */
class DistribuicaoDePagamentoService
{
    public function __construct(
        private RelogioDoTenant $relogio,
        private MoedaDoTenant $moedaDoTenant,
    ) {
    }

    public function preVisualizarPagamento(Matricula $matricula, RegistarPagamentoDTO $dto): PlanoDePagamentoDTO
    {
        $this->validarData($dto->dataPagamento, $matricula);

        return $this->calcular(
            $dto->valor->unidadesMenores(),
            $dto->valorCredito->unidadesMenores(),
            $dto->dataPagamento,
            $dto->distribuicao,
            $this->propinasPagaveis($matricula),
            $dto->valorCredito->unidadesMenores() > 0 ? $this->creditosDisponiveis($matricula) : new Collection(),
            RegraCobranca::doTenant(),
        );
    }

    public function preVisualizarCredito(Matricula $matricula, AplicarCreditoDTO $dto): PlanoDePagamentoDTO
    {
        return $this->calcular(
            0,
            $dto->valorCredito->unidadesMenores(),
            $this->relogio->hoje(),
            $dto->distribuicao,
            $this->propinasPagaveis($matricula),
            $this->creditosDisponiveis($matricula),
            RegraCobranca::doTenant(),
        );
    }

    /**
     * O1: aviso NÃO bloqueante quando a referência já foi usada num pagamento Anulado do mesmo método (o tenant vem do
     * scope global). O bloqueio contra Confirmados é outra regra (D5, índice único parcial). null = sem aviso.
     */
    public function avisoDeReferencia(int $metodoPagamentoId, ?string $referencia): ?string
    {
        $referencia = $referencia === null ? null : trim($referencia);

        if ($referencia === null || $referencia === '') {
            return null;
        }

        $anulado = Pagamento::query()
            ->where('metodo_pagamento_id', $metodoPagamentoId)
            ->where('referencia', $referencia)
            ->where('estado', EstadoPagamento::ANULADO->value)
            ->orderByDesc('id')
            ->first();

        return $anulado === null
            ? null
            : "Esta referência já foi usada no pagamento n.º {$anulado->numero}, que foi anulado. Confirme que não é um registo em duplicado.";
    }

    /** @return Collection<int, Propina> Em Aberto ou Parcialmente Paga, da matrícula (sem bloqueio). */
    public function propinasPagaveis(Matricula $matricula): Collection
    {
        return Propina::query()
            ->where('matricula_id', $matricula->getKey())
            ->whereIn('estado', [EstadoCobranca::EM_ABERTO->value, EstadoCobranca::PARCIALMENTE_PAGA->value])
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, Credito> créditos Disponíveis do aluno da matrícula, do mais antigo para o mais recente. */
    public function creditosDisponiveis(Matricula $matricula): Collection
    {
        return Credito::query()
            ->where('aluno_id', $matricula->aluno_id)
            ->where('estado', EstadoCredito::DISPONIVEL->value)
            ->orderBy('id')
            ->get();
    }

    /**
     * O3 (decidida pelo dono): nem futura nem anterior à data da matrícula. Nas Actions a matrícula chega já bloqueada
     * (`data_matricula` relida sob o lock); na pré-visualização é a lida sem bloqueio.
     */
    public function validarData(CarbonImmutable $data, Matricula $matricula): void
    {
        if ($data->toDateString() > $this->relogio->hoje()->toDateString()) {
            throw ValidationException::withMessages(['data_pagamento' => 'A data do pagamento não pode ser futura.']);
        }

        $inicio = $matricula->data_matricula;

        if ($inicio !== null && $data->toDateString() < $inicio->toDateString()) {
            throw ValidationException::withMessages(['data_pagamento' => 'A data do pagamento não pode ser anterior à data da matrícula (' . $inicio->format('d/m/Y') . ').']);
        }
    }

    /**
     * @param  list<LinhaDeDistribuicao>|null  $distribuicao  null = automática
     * @param  Collection<int, Propina>  $propinas  da matrícula (filtradas e ordenadas aqui)
     * @param  Collection<int, Credito>  $creditos  do aluno (filtrados e ordenados aqui)
     */
    public function calcular(
        int $dinheiro,
        int $creditoPedido,
        CarbonImmutable $dataEfeito,
        ?array $distribuicao,
        Collection $propinas,
        Collection $creditos,
        RegraCobranca $regras,
    ): PlanoDePagamentoDTO {
        $pagaveis = $propinas
            ->filter(fn (Propina $p) => in_array($p->estado, [EstadoCobranca::EM_ABERTO, EstadoCobranca::PARCIALMENTE_PAGA], true)
                && $p->saldo()->unidadesMenores() > 0)
            ->sort(fn (Propina $a, Propina $b) => [$a->periodo_inicio->toDateString(), $a->ordem, $a->id] <=> [$b->periodo_inicio->toDateString(), $b->ordem, $b->id])
            ->values();
        $total = $dinheiro + $creditoPedido;

        $alvos = $distribuicao === null
            ? $this->automatica($pagaveis, $total, $dataEfeito, $regras)
            : $this->explicita($distribuicao, $pagaveis, $total, $dataEfeito, $regras);
        $somaAlvos = array_sum(array_column($alvos, 'valor'));

        $fila = $creditos
            ->filter(fn (Credito $c) => $c->estado === EstadoCredito::DISPONIVEL && $c->saldo()->unidadesMenores() > 0)
            ->sortBy('id')
            ->map(fn (Credito $c) => ['id' => (int) $c->id, 'saldo' => $c->saldo()->unidadesMenores()])
            ->values()
            ->all();
        $disponivel = array_sum(array_column($fila, 'saldo'));

        if ($creditoPedido > $disponivel) {
            throw ValidationException::withMessages(['valor_credito' => 'O aluno só tem ' . $this->formatar($disponivel) . ' de crédito disponível.']);
        }

        if ($creditoPedido > $somaAlvos) {
            throw ValidationException::withMessages([
                'valor_credito' => 'O crédito a usar (' . $this->formatar($creditoPedido) . ') é maior do que o total a distribuir pelas propinas (' . $this->formatar($somaAlvos) . ').',
            ]);
        }

        $restanteCredito = $creditoPedido;
        $linhas = [];
        $utilizacoes = [];

        foreach ($alvos as ['propina' => $propina, 'valor' => $valor]) {
            $doCredito = min($restanteCredito, $valor);
            $restanteCredito -= $doCredito;
            $porCobrir = $doCredito;

            while ($porCobrir > 0) {
                $uso = min($fila[0]['saldo'], $porCobrir);
                $utilizacoes[] = ['credito_id' => $fila[0]['id'], 'propina_id' => (int) $propina->id, 'valor' => $uso];
                $fila[0]['saldo'] -= $uso;
                $porCobrir -= $uso;

                if ($fila[0]['saldo'] === 0) {
                    array_shift($fila);
                }
            }

            $saldo = $propina->saldo()->unidadesMenores();
            $inicio = $propina->periodo_inicio->toDateString();
            $linhas[] = [
                'propina_id' => (int) $propina->id,
                'periodo' => RotuloDePeriodo::periodo($inicio, $propina->periodo_fim->toDateString()),
                'periodo_inicio' => $inicio,
                'saldo_antes' => $saldo,
                'credito' => $doCredito,
                'dinheiro' => $valor - $doCredito,
                'saldo_depois' => $saldo - $valor,
            ];

            // Pagamentos-B: aqui, com o capital desta propina liquidado, a multa dela (imputação capital → multa).
        }

        $dinheiroDistribuido = array_sum(array_column($linhas, 'dinheiro'));

        return new PlanoDePagamentoDTO($dinheiro, $creditoPedido, $linhas, $utilizacoes, $dinheiro - $dinheiroDistribuido);
    }

    /**
     * @param  Collection<int, Propina>  $pagaveis  ordenadas
     * @return list<array{propina: Propina, valor: int}>
     */
    private function automatica(Collection $pagaveis, int $total, CarbonImmutable $dataEfeito, RegraCobranca $regras): array
    {
        $alvos = [];
        $restante = $total;

        foreach ($pagaveis as $propina) {
            if ($restante === 0) {
                break;
            }

            // D9: as seguintes também são futuras (ordenadas por período): sem antecipado, a lista termina aqui.
            if ($propina->periodo_inicio->toDateString() > $dataEfeito->toDateString() && ! $regras->permite_pagamento_antecipado) {
                break;
            }

            $saldo = $propina->saldo()->unidadesMenores();

            if ($restante >= $saldo) {
                $alvos[] = ['propina' => $propina, 'valor' => $saldo];
                $restante -= $saldo;

                continue;
            }

            if ($regras->permite_pagamento_parcial) {
                $alvos[] = ['propina' => $propina, 'valor' => $restante];
            }

            // Sem parcial: nunca se salta uma propina mais antiga para pagar uma mais recente (o resto vira crédito).
            break;
        }

        return $alvos;
    }

    /**
     * @param  list<LinhaDeDistribuicao>  $distribuicao
     * @param  Collection<int, Propina>  $pagaveis  ordenadas
     * @return list<array{propina: Propina, valor: int}>
     */
    private function explicita(array $distribuicao, Collection $pagaveis, int $total, CarbonImmutable $dataEfeito, RegraCobranca $regras): array
    {
        $porId = $pagaveis->keyBy('id');
        $posicao = $pagaveis->pluck('id')->flip();
        $alvos = [];

        foreach (array_values($distribuicao) as $i => $linha) {
            $propina = $porId->get($linha->propinaId) ?? throw ValidationException::withMessages([
                "distribuicao.{$i}.propina_id" => 'A propina indicada não é desta matrícula ou já não está em dívida (paga, cancelada ou anulada).',
            ]);
            $valor = $linha->valor->unidadesMenores();
            $saldo = $propina->saldo()->unidadesMenores();

            if ($valor > $saldo) {
                throw ValidationException::withMessages(["distribuicao.{$i}.valor" => 'O valor excede o saldo em dívida desta propina (' . $this->formatar($saldo) . ').']);
            }

            if (! $regras->permite_pagamento_parcial && $valor !== $saldo) {
                throw ValidationException::withMessages(["distribuicao.{$i}.valor" => 'A escola não permite pagamento parcial: pague o saldo inteiro desta propina (' . $this->formatar($saldo) . ').']);
            }

            if ($propina->periodo_inicio->toDateString() > $dataEfeito->toDateString() && ! $regras->permite_pagamento_antecipado) {
                throw ValidationException::withMessages(["distribuicao.{$i}.propina_id" => 'A escola não permite pagamento antecipado: esta propina ainda não tinha começado na data do pagamento.']);
            }

            $alvos[] = ['propina' => $propina, 'valor' => $valor];
        }

        if (array_sum(array_column($alvos, 'valor')) > $total) {
            throw ValidationException::withMessages(['distribuicao' => 'A distribuição excede o valor do pagamento mais o crédito usado (' . $this->formatar($total) . ').']);
        }

        // Crédito primeiro na propina mais antiga: as linhas seguem a ordem das propinas, não a do pedido.
        usort($alvos, fn (array $a, array $b) => $posicao[$a['propina']->id] <=> $posicao[$b['propina']->id]);

        return $alvos;
    }

    private function formatar(int $unidades): string
    {
        return Dinheiro::deUnidadesMenores($unidades)->formatar($this->moedaDoTenant->atual());
    }
}
