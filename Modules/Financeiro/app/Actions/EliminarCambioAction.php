<?php

namespace Modules\Financeiro\Actions;

use Modules\Financeiro\Models\Cambio;
use Modules\Financeiro\Support\ViolacaoDeChave;

class EliminarCambioAction
{
    public function executar(Cambio $cambio): void
    {
        ViolacaoDeChave::comoValidacao(fn () => $cambio->delete());
    }
}
