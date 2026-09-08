<?php

namespace Modules\Turma\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Turma\Models\Turno;

class EliminarTurnoAction
{
    public function executar(Turno $turno): void
    {
        if ($turno->turmas()->exists()) {
            throw ValidationException::withMessages([
                'turno' => 'Este turno está associado a turmas e não pode ser eliminado.',
            ]);
        }

        $turno->delete();
    }
}
