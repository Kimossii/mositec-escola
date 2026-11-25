<?php

namespace Modules\Horario\Support;

use Modules\Horario\Models\GrelhaEntrada;
use Modules\Turma\Contracts\DependenciasDoTempoDoTurno;
use Modules\Turma\Models\Turno;

/** Um tempo que está na grelha de horários de uma turma do turno (mesmo em versões antigas) não se retira do turno. */
class TempoEmUsoNaGrelha implements DependenciasDoTempoDoTurno
{
    public function bloqueiaRemocao(Turno $turno, int $horarioId): ?string
    {
        $turmas = GrelhaEntrada::query()
            ->withTrashed()
            ->where('horario_id', $horarioId)
            ->whereHas('turma', fn ($q) => $q->where('turno_id', $turno->id))
            ->distinct()
            ->count('turma_id');

        return $turmas === 0
            ? null
            : "Este tempo está na grelha de horários de {$turmas} " . ($turmas === 1 ? 'turma' : 'turmas') . ' do turno. Retire-o de lá primeiro.';
    }
}
