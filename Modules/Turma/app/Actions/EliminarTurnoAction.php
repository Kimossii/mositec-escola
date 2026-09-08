<?php

namespace Modules\Turma\Actions;

use Modules\Turma\Models\Turno;

class EliminarTurnoAction
{
    public function executar(Turno $turno): void
    {
        $turno->delete();
    }
}
