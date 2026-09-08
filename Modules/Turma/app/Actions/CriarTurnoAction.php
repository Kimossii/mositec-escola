<?php

namespace Modules\Turma\Actions;

use Modules\Turma\DTO\TurnoDTO;
use Modules\Turma\Models\Turno;

class CriarTurnoAction
{
    public function executar(TurnoDTO $dto): Turno
    {
        return Turno::create([
            'nome' => $dto->nome,
            'descricao' => $dto->descricao,
        ]);
    }
}
