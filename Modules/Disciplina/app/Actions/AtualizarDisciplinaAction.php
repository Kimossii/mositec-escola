<?php

namespace Modules\Disciplina\Actions;

use Modules\Disciplina\DTO\DisciplinaDTO;
use Modules\Disciplina\Models\Disciplina;

class AtualizarDisciplinaAction
{
    public function executar(Disciplina $disciplina, DisciplinaDTO $dto): Disciplina
    {
        $disciplina->fill([
            'codigo' => $dto->codigo,
            'nome' => $dto->nome,
            'descricao' => $dto->descricao,
        ]);

        $disciplina->save();

        return $disciplina->fresh();
    }
}
