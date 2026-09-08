<?php

namespace Modules\Turma\Actions;

use Illuminate\Support\Facades\Auth;
use Modules\Turma\DTO\TurmaDTO;
use Modules\Turma\Models\Turma;

class AtualizarTurmaAction
{
    public function executar(
        Turma $turma,
        TurmaDTO $dto
    ): Turma {
        $turma->fill([
            'nivel_academico_id' => $dto->nivel_academico_id,
            'codigo' => $dto->codigo,
            'nome' => $dto->nome,
            'turno_id' => $dto->turno_id,
            'editado_por' => Auth::id(),
        ]);

        $turma->save();

        return $turma->fresh();
    }
}
