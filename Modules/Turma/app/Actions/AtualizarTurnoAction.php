<?php

namespace Modules\Turma\Actions;

use Modules\Turma\DTO\TurnoDTO;
use Modules\Turma\Models\Turno;

class AtualizarTurnoAction
{
    public function executar(Turno $turno, TurnoDTO $dto): Turno
    {
        $turno->fill([
            'nome' => $dto->nome,
            'descricao' => $dto->descricao,
        ]);

        $turno->save();

        return $turno->fresh();
    }
}
