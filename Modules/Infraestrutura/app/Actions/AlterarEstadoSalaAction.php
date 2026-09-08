<?php

namespace Modules\Infraestrutura\Actions;

use Modules\Infraestrutura\Enums\EstadoSala;
use Modules\Infraestrutura\Models\Sala;

class AlterarEstadoSalaAction
{
    public function alterar(Sala $sala, EstadoSala $novoEstado): Sala
    {
        $sala->update(['estado' => $novoEstado->value]);

        return $sala->fresh();
    }
}
