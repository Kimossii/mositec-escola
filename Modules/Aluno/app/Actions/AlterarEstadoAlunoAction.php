<?php

namespace Modules\Aluno\Actions;

use Modules\Aluno\Models\Aluno;
use Modules\Core\Enums\Estado;

class AlterarEstadoAlunoAction
{
    public function executar(Aluno $aluno, Estado $novoEstado): Aluno
    {
        $aluno->estado = $novoEstado->value;
        $aluno->save();

        return $aluno->fresh();
    }
}
