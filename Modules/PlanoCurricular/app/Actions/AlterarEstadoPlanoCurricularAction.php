<?php

namespace Modules\PlanoCurricular\Actions;

use Modules\Core\Enums\Estado;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\Core\Traits\LogService;

class AlterarEstadoPlanoCurricularAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(PlanoCurricular $plano, Estado $novoEstado): PlanoCurricular
    {
        return $this->comLog('Alterar estado plano curricular', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($plano, $novoEstado));
    }

    private function executarSemRegisto(PlanoCurricular $plano, Estado $novoEstado): PlanoCurricular
    {
        $plano->estado = $novoEstado->value;
        $plano->save();

        return $plano->fresh();
    }
}
