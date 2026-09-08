<?php

namespace Modules\Turma\Actions;

use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Turma\DTO\TurnoDTO;
use Modules\Turma\Models\Turno;

class CriarTurnoAction
{
    public function executar(TurnoDTO $dto): Turno
    {
        return Turno::create([
            'estabelecimento_id' => Estabelecimento::current()?->id,
            'nome' => $dto->nome,
            'descricao' => $dto->descricao,
        ]);
    }
}
