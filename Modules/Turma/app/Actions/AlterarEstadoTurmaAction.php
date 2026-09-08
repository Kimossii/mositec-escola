<?php

namespace Modules\Turma\Actions;

use Modules\Core\Enums\Estado;
use Modules\Turma\Models\Turma;

class AlterarEstadoTurmaAction
{
    public function executar(Turma $turma, Estado $novoEstado): Turma
    {
        $turma->estado = $novoEstado->value;
        $turma->save();

        return $turma->fresh();
    }
}
