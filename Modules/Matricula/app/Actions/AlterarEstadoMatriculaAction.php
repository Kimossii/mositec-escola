<?php

namespace Modules\Matricula\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;

class AlterarEstadoMatriculaAction
{
    public function executar(
        Matricula $matricula,
        EstadoMatriculaEnum $novoEstado,
        ?int $utilizadorId = null,
    ): Matricula {
        $estadoActual = $matricula->estado;

        if (! $estadoActual->podeTransitarPara($novoEstado)) {
            throw ValidationException::withMessages([
                'estado' => "Não é possível alterar o estado de {$estadoActual->label()} para {$novoEstado->label()}.",
            ]);
        }

        $matricula->estado = $novoEstado;
        $matricula->editado_por = $utilizadorId;
        $matricula->save();

        return $matricula->fresh();
    }
}
