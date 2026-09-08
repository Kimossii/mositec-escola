<?php

namespace Modules\Turma\Actions;

use Modules\Turma\DTO\TurmaDTO;
use Modules\Turma\Models\Turma;

class CriarTurmaAction
{
    public function executar(TurmaDTO $dto): Turma
    {
        return Turma::create([
            'ano_lectivo_id' => $dto->ano_lectivo_id,
            'nivel_academico_id' => $dto->nivel_academico_id,
            'codigo' => $dto->codigo,
            'nome' => $dto->nome,
            'turno_id' => $dto->turno_id,
        ]);
    }
}
