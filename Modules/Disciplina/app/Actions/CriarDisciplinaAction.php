<?php

namespace Modules\Disciplina\Actions;

use Modules\Disciplina\DTO\DisciplinaDTO;
use Modules\Disciplina\Models\Disciplina;
use Modules\Estabelecimento\Models\Estabelecimento;

class CriarDisciplinaAction
{
    public function executar(DisciplinaDTO $dto): Disciplina
    {
        return Disciplina::create([
            'estabelecimento_id' => Estabelecimento::current()?->id,
            'codigo' => $dto->codigo,
            'nome' => $dto->nome,
            'descricao' => $dto->descricao,
        ]);
    }
}
