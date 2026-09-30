<?php

namespace Modules\Curso\Actions;

use Modules\Curso\DTO\CursoDTO;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Models\Estabelecimento;

class CriarCursoAction
{
    public function executar(CursoDTO $dto): Curso
    {
        return Curso::create([
            'estabelecimento_id' => Estabelecimento::current()?->id,
            'codigo' => $dto->codigo,
            'nome' => $dto->nome,
            'descricao' => $dto->descricao,
        ]);
    }
}
