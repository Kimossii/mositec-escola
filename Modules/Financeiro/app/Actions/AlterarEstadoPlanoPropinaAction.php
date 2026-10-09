<?php

namespace Modules\Financeiro\Actions;

use Modules\Core\Enums\Estado;
use Modules\Financeiro\Models\PlanoPropina;

class AlterarEstadoPlanoPropinaAction
{
    public function executar(PlanoPropina $plano, Estado $novoEstado): PlanoPropina
    {
        $plano->estado = $novoEstado->value;
        $plano->save();

        return $plano->fresh();
    }
}
