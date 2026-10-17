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
 * Dentro de cada propina o dinheiro paga primeiro o CAPITAL e só depois a MULTA dela (imputação capital → multa).
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

    /** @return Collection<int, Propina> com capital OU multa em dívida (não Cancelada/Anulada), da matrícula (sem bloqueio). */
    public function propinasPagaveis(Matricula $matricula): Collection
    {
        return Propina::query()
            ->where('matricula_id', $matricula->getKey())
            ->whereIn('estado', [EstadoCobranca::EM_ABERTO->value, EstadoCobranca::PARCIALMENTE_PAGA->value, EstadoCobranca::PAGA->value])
            ->with('multa')
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
            ->filter(fn (Propina $p) => in_array($p->estado, [EstadoCobranca::EM_ABERTO, EstadoCobranca::PARCIALMENTE_PAGA, EstadoCobranca::PAGA], true)
                && ($p->saldo()->unidadesMenores() > 0 || $p->multaPendente() > 0))
            ->sort(fn (Propina $a, Propina $b) => [$a->periodo_inicio->toDateString(), $a->ordem, $a->id] <=> [$b->periodo_inicio->toDateString(), $b->ordem, $b->id])
            ->values();
        $total = $dinheiro + $creditoPedido;

        $alvos = $distribuicao === null
            ? $this->automatica($pagaveis, $total, $dataEfeito, $regras)
            : $this->explicita($distribuicao, $pagaveis, $total, $dataEfeito, $regras);
        $somaAlvos = array_sum(array_map(fn (array $alvo) => $alvo['capital'] + $alvo['multa'], $alvos));

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
        $restanteDinheiro = $dinheiro;
        $linhas = [];
        $utilizacoes = [];

        foreach ($alvos as $alvo) {
            $propina = $alvo['propina'];
            $capitalSaldo = $propina->saldo()->unidadesMenores();
            $multaPendente = $propina->multaPendente();

            // Capital primeiro: crédito e depois dinheiro novo.
            $creditoCapital = min($restanteCredito, $alvo['capital']);
            $restanteCredito -= $creditoCapital;
            $dinheiroCapital = min($restanteDinheiro, $alvo['capital'] - $creditoCapital);
            $restanteDinheiro -= $dinheiroCapital;

            // Multa só depois de o capital ficar a zero, com a mesma ordem crédito → dinheiro.
            $creditoMulta = 0;
            $dinheiroMulta = 0;

            if ($creditoCapital + $dinheiroCapital === $alvo['capital']) {
                $creditoMulta = min($restanteCredito, $alvo['multa']);
                $restanteCredito -= $creditoMulta;
                $dinheiroMulta = min($restanteDinheiro, $alvo['multa'] - $creditoMulta);
                $restanteDinheiro -= $dinheiroMulta;
            }

            $this->consumirCredito($creditoCapital, (int) $propina->id, null, $fila, $utilizacoes);

            if ($creditoMulta > 0) {
                $this->consumirCredito($creditoMulta, null, $alvo['multa_id'], $fila, $utilizacoes);
            }

            $inicio = $propina->periodo_inicio->toDateString();
            $linhas[] = [
                'propina_id' => (int) $propina->id,
                'multa_id' => $alvo['multa_id'],
                'periodo' => RotuloDePeriodo::periodo($inicio, $propina->periodo_fim->toDateString()),
                'periodo_inicio' => $inicio,
                'saldo_antes' => $capitalSaldo,
                'credito' => $creditoCapital,
                'dinheiro' => $dinheiroCapital,
                'saldo_depois' => $capitalSaldo - $alvo['capital'],
                'multa_pendente_antes' => $multaPendente,
                'multa_credito' => $creditoMulta,
                'multa_dinheiro' => $dinheiroMulta,
                'multa_pendente_depois' => $multaPendente - $creditoMulta - $dinheiroMulta,
            ];
        }

        $dinheiroDistribuido = array_sum(array_column($linhas, 'dinheiro')) + array_sum(array_column($linhas, 'multa_dinheiro'));

        return new PlanoDePagamentoDTO($dinheiro, $creditoPedido, $linhas, $utilizacoes, $dinheiro - $dinheiroDistribuido);
    }

    /**
     * Consome crédito da fila (mais antigo primeiro) e regista cada parcela como uma utilização para a propina OU
     * para a multa indicada.
     *
     * @param  list<array{id: int, saldo: int}>  $fila
     * @param  list<array{credito_id: int, propina_id: int|null, propina_multa_id: int|null, valor: int}>  $utilizacoes
     */
    private function consumirCredito(int $valor, ?int $propinaId, ?int $multaId, array &$fila, array &$utilizacoes): void
    {
        $porCobrir = $valor;

        while ($porCobrir > 0) {
            $uso = min($fila[0]['saldo'], $porCobrir);
            $utilizacoes[] = ['credito_id' => $fila[0]['id'], 'propina_id' => $propinaId, 'propina_multa_id' => $multaId, 'valor' => $uso];
            $fila[0]['saldo'] -= $uso;
            $porCobrir -= $uso;

            if ($fila[0]['saldo'] === 0) {
                array_shift($fila);
            }
        }
    }

    /**
     * @param  Collection<int, Propina>  $pagaveis  ordenadas
     * @return list<array{propina: Propina, capital: int, multa: int, multa_id: int|null}>
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

            $capital = $propina->saldo()->unidadesMenores();
            $multa = $propina->multaPendente();
            $multaId = $propina->multa?->id;

            if ($regras->permite_pagamento_parcial) {
                $cap = min($restante, $capital);
                $restante -= $cap;
                $mult = 0;

                if ($cap === $capital) {
                    $mult = min($restante, $multa);
                    $restante -= $mult;
                }

                if ($cap + $mult > 0) {
                    $alvos[] = ['propina' => $propina, 'capital' => $cap, 'multa' => $mult, 'multa_id' => $mult > 0 ? $multaId : null];
                }
            } else {
                // Sem parcial: capital + multa por inteiro, ou não se toca (nunca se salta a propina mais antiga).
                $totalDevido = $capital + $multa;

                if ($restante >= $totalDevido) {
                    $alvos[] = ['propina' => $propina, 'capital' => $capital, 'multa' => $multa, 'multa_id' => $multa > 0 ? $multaId : null];
                    $restante -= $totalDevido;
                } else {
                    break;
                }
            }
        }

        return $alvos;
    }

    /**
     * @param  list<LinhaDeDistribuicao>  $distribuicao
     * @param  Collection<int, Propina>  $pagaveis  ordenadas
     * @return list<array{propina: Propina, capital: int, multa: int, multa_id: int|null}>
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
            $capital = $propina->saldo()->unidadesMenores();
            $multa = $propina->multaPendente();

            if ($valor > $capital + $multa) {
                throw ValidationException::withMessages(["distribuicao.{$i}.valor" => 'O valor excede o saldo em dívida desta propina (' . $this->formatar($capital + $multa) . ').']);
            }

            if (! $regras->permite_pagamento_parcial && $valor !== $capital + $multa) {
                throw ValidationException::withMessages(["distribuicao.{$i}.valor" => 'A escola não permite pagamento parcial: pague o saldo inteiro desta propina (' . $this->formatar($capital + $multa) . ').']);
            }

            if ($propina->periodo_inicio->toDateString() > $dataEfeito->toDateString() && ! $regras->permite_pagamento_antecipado) {
                throw ValidationException::withMessages(["distribuicao.{$i}.propina_id" => 'A escola não permite pagamento antecipado: esta propina ainda não tinha começado na data do pagamento.']);
            }

            $cap = min($valor, $capital);
            $mult = $cap === $capital ? $valor - $cap : 0;

            $alvos[] = ['propina' => $propina, 'capital' => $cap, 'multa' => $mult, 'multa_id' => $mult > 0 ? $propina->multa?->id : null];
        }

        if (array_sum(array_map(fn (array $alvo) => $alvo['capital'] + $alvo['multa'], $alvos)) > $total) {
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
