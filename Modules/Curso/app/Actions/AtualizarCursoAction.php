<?php

namespace Modules\Curso\Actions;

use Modules\Curso\DTO\CursoDTO;
use Modules\Curso\Models\Curso;

class AtualizarCursoAction
{
    public function executar(Curso $curso, CursoDTO $dto): Curso
    {
        $curso->fill([
            'codigo' => $dto->codigo,
            'nome' => $dto->nome,
            'descricao' => $dto->descricao,
        ]);

        $curso->save();

        return $curso->fresh();
    }
}
