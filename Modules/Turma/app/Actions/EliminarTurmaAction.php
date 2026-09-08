<?php

namespace Modules\Turma\Actions;

use Modules\Turma\Models\Turma;

class EliminarTurmaAction
{
    public function executar(Turma $turma): void
    {
        $turma->delete();
    }
}
