<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\DTO\MotivoDeAnulacaoDTO;
use Modules\Financeiro\Enums\EstadoPagamento;
use Modules\Financeiro\Models\Credito;
use Modules\Financeiro\Models\Pagamento;
use Modules\Financeiro\Models\PagamentoMulta;
use Modules\Financeiro\Models\PagamentoPropina;
use Modules\Financeiro\Models\PropinaMulta;
use Modules\Financeiro\Services\RecalcularCredito;
use Modules\Financeiro\Services\RecalcularPropina;
use Modules\Financeiro\Support\AnulacaoDePagamentos;
use Modules\Financeiro\Support\BloqueioDePagamentos;
use Modules\Financeiro\Support\BloqueioDePropinas;

/**
 * Anula um pagamento (spec Pagamentos §5; D14): matrícula → pagamento → propinas → crédito gerado. Bloqueado se o
 * crédito gerado tem utilizações Activas. Efeito: pagamento e crédito gerado Anulados; RecalcularPropina das propinas
 * distribuídas (regressam a Em Aberto / Parcialmente Paga). As utilizações de crédito feitas no mesmo acto são
 * independentes e não mudam (ficam rastreadas por `pagamento_id`, O8). Nada é apagado.
 */
class AnularPagamentoAction
{
    public function __construct(
        private BloqueioDePagamentos $bloqueio,
        private BloqueioDePropinas $bloqueioDePropinas,
        private RecalcularPropina $recalcularPropina,
        private RecalcularCredito $recalcularCredito,
        private RelogioDoTenant $relogio,
        private AnulacaoDePagamentos $anulacao,
    ) {
    }

    public function executar(Pagamento $pagamento, MotivoDeAnulacaoDTO $dto, ?int $utilizadorId): Pagamento
    {
        return DB::transaction(function () use ($pagamento, $dto, $utilizadorId) {
            // D13: matrícula (mesmo eliminada: um adiantamento numa Pendente apagada) → pagamento → propinas → crédito.
            $this->bloqueio->matricula((int) $pagamento->matricula_id, incluirEliminadas: true);
            $bloqueado = $this->bloqueio->pagamento((int) $pagamento->getKey())
                ?? throw ValidationException::withMessages(['pagamento' => 'O pagamento não existe.']);

            // As propinas distribuídas e as propinas das multas distribuídas (RecalcularPropina delega na multa).
            $propinas = PagamentoPropina::query()->where('pagamento_id', $bloqueado->id)->pluck('propina_id')
                ->merge(
                    PropinaMulta::query()
                        ->whereIn('id', PagamentoMulta::query()->where('pagamento_id', $bloqueado->id)->pluck('propina_multa_id'))
                        ->pluck('propina_id'),
                )
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
            $this->bloqueioDePropinas->bloquear($propinas);
            $credito = $this->bloqueio->creditos(
                Credito::query()->where('origem_pagamento_id', $bloqueado->id)->pluck('id')->map(fn ($id) => (int) $id)->all(),
            )->first();

            if (($razao = $this->anulacao->motivoParaNaoAnular($bloqueado)) !== null) {
                throw ValidationException::withMessages(['pagamento' => $razao]);
            }

            $bloqueado->forceFill([
                'estado' => EstadoPagamento::ANULADO,
                'motivo_anulacao' => $dto->motivo,
                'anulado_por' => $utilizadorId,
                'anulado_em' => $this->relogio->agora()->setTimezone(config('app.timezone')),
            ])->save();

            if ($credito !== null) {
                $this->recalcularCredito->anular($credito);
            }

            $this->recalcularPropina->executarVarias($propinas);

            return $bloqueado->fresh(['distribuicoes', 'multas', 'credito']);
        });
    }
}
