<?php

namespace Modules\Frequencia\Support;

use Modules\Frequencia\Models\Aula;
use Modules\Turma\Contracts\DependenciasDoTempoDoTurno;
use Modules\Turma\Models\Turno;

/** Um tempo em que já se fez uma chamada de uma turma do turno não se retira do turno (a chamada guarda esse tempo). */
class TempoComChamadas implements DependenciasDoTempoDoTurno
{
    public function bloqueiaRemocao(Turno $turno, int $horarioId): ?string
    {
        $existe = Aula::query()
            ->where('horario_id', $horarioId)
            ->whereHas('turma', fn ($q) => $q->where('turno_id', $turno->id))
            ->exists();

        return $existe ? 'Já há chamadas registadas neste tempo, em turmas do turno: não se pode retirar.' : null;
    }
}
