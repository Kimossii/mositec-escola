<?php

namespace Modules\Turma\Contracts;

use Modules\Turma\Models\Turno;

/**
 * Um módulo que usa os tempos de um turno (a grelha de horários, as chamadas) pode impedir que um tempo seja retirado
 * do turno. Regista-se com a etiqueta de `DependenciasRegistadasDoTurno`; o Turma nunca importa esses módulos.
 */
interface DependenciasDoTempoDoTurno
{
    /** O motivo (em português, para mostrar ao utilizador) de não se poder retirar o tempo do turno; null se pode. */
    public function bloqueiaRemocao(Turno $turno, int $horarioId): ?string;
}
