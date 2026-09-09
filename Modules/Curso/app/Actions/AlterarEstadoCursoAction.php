<?php

namespace Modules\Curso\Actions;

use Modules\Core\Enums\Estado;
use Modules\Curso\Models\Curso;

class AlterarEstadoCursoAction
{
    public function executar(Curso $curso, Estado $novoEstado): Curso
    {
        $curso->estado = $novoEstado->value;
        $curso->save();

        return $curso->fresh();
    }
}
