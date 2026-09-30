<?php

namespace Modules\Matricula\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;

class EliminarMatriculaAction
{
    public function executar(Matricula $matricula): void
    {
        if ($matricula->estado !== EstadoMatriculaEnum::PENDENTE) {
            throw ValidationException::withMessages([
                'matricula' => 'Só é possível eliminar uma matrícula ainda Pendente. Para as restantes, altere o estado para Cancelada.',
            ]);
        }

        $matricula->delete();
    }
}
