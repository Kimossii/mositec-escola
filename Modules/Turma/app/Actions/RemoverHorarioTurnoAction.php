<?php

namespace Modules\Turma\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Core\Traits\LogService;
use Modules\Turma\Models\Turno;
use Modules\Turma\Models\TurnoHorario;
use Modules\Turma\Support\DependenciasRegistadasDoTurno;

/** Retira um tempo do turno (engano ao adicionar). O tempo continua no catálogo; só sai do turno, e só se ninguém o usa. */
class RemoverHorarioTurnoAction
{
    use LogService;

    public function __construct(private DependenciasRegistadasDoTurno $dependencias)
    {
    }

    public function executar(Turno $turno, TurnoHorario $ligacao): void
    {
        $this->comLog('Remover horário do turno', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($turno, $ligacao));
    }

    private function executarSemRegisto(Turno $turno, TurnoHorario $ligacao): void
    {
        if (($motivo = $this->dependencias->bloqueiaRemocaoDoTempo($turno, (int) $ligacao->horario_id)) !== null) {
            throw ValidationException::withMessages(['horario' => $motivo]);
        }

        $ligacao->delete();
    }
}
