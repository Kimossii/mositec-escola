<?php

namespace Modules\Turma\Actions;

use Modules\Core\Enums\Estado;
use Modules\Turma\Models\Turno;

class AlterarEstadoTurnoAction
{
    public function executar(Turno $turno, Estado $novoEstado): Turno
    {
        $turno->estado = $novoEstado->value;
        $turno->save();

        return $turno->fresh();
    }
}
