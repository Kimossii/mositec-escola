<?php

namespace Modules\Financeiro\Actions;

use Modules\Financeiro\Models\Cambio;

class EliminarCambioAction
{
    public function executar(Cambio $cambio): void
    {
        $cambio->delete();
    }
}
