<?php

namespace Modules\Turma\Actions;

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
            'curso_id' => $dto->curso_id,
            'codigo' => $dto->codigo,
            'nome' => $dto->nome,
            'turno_id' => $dto->turno_id,
        ]);

        $turma->save();

        return $turma->fresh();
    }
}
