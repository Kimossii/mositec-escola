<?php

namespace Modules\Financeiro\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;

/**
 * ÚNICO escritor de `plano_propina_id` e `ordem` de uma propina já gerada (alteração de preço com divisão, spec §14
 * "Escolha 1"): a propina passa ao plano novo com a posição do seu período nele; período, vencimento, tolerância e
 * limite mantêm-se, e não nasce propina nova (sem cobrança dupla). O valor reajusta-se a seguir no RecalcularPropina.
 */
final class ReassociacaoDePropinas
{
    public function reassociar(Propina $bloqueada, PlanoPropina $planoNovo, int $ordem): Propina
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Reassociar propinas exige uma transacção aberta (DB::transaction) do chamador.');
        }

        if (! in_array($bloqueada->estado, [EstadoCobranca::EM_ABERTO, EstadoCobranca::PARCIALMENTE_PAGA], true)) {
            throw new LogicException("Só se reassocia uma propina Em Aberto ou Parcialmente Paga (a propina {$bloqueada->id} está {$bloqueada->estado->label()}).");
        }

        if ($ordem < 1) {
            throw new InvalidArgumentException('A ordem do período no plano novo tem de ser maior ou igual a 1.');
        }

        if ((int) $planoNovo->ano_lectivo_id !== (int) $bloqueada->ano_lectivo_id) {
            throw new LogicException('O plano novo tem de ser do mesmo ano lectivo da propina.');
        }

        $periodo = $planoNovo->periodos()[$ordem - 1] ?? null;

        if ($periodo === null || $periodo['inicio'] !== $bloqueada->periodo_inicio->toDateString()) {
            throw new LogicException("A ordem {$ordem} do plano novo não corresponde ao período da propina {$bloqueada->id}.");
        }

        $bloqueada->forceFill(['plano_propina_id' => $planoNovo->id, 'ordem' => $ordem])->save();

        return $bloqueada;
    }
}
