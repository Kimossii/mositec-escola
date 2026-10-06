<?php

namespace Modules\PlanoCurricular\Actions;

use Modules\Core\Enums\Estado;
use Modules\PlanoCurricular\Models\PlanoCurricular;

class AlterarEstadoPlanoCurricularAction
{
    public function executar(PlanoCurricular $plano, Estado $novoEstado): PlanoCurricular
    {
        $plano->estado = $novoEstado->value;
        $plano->save();

        return $plano->fresh();
    }
}
