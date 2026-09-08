<?php

namespace Modules\Infraestrutura\Actions;

use Modules\Infraestrutura\Models\Sala;

class EliminarSalaAction
{
    public function executar(Sala $sala): void
    {
        $sala->delete();
    }
}
