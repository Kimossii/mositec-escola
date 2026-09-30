<?php

namespace Modules\Disciplina\Actions;

use Modules\Core\Enums\Estado;
use Modules\Disciplina\Models\Disciplina;

class AlterarEstadoDisciplinaAction
{
    public function executar(Disciplina $disciplina, Estado $novoEstado): Disciplina
    {
        $disciplina->estado = $novoEstado->value;
        $disciplina->save();

        return $disciplina->fresh();
    }
}
