<?php

namespace Modules\Financeiro\Actions;

use Modules\Core\Enums\Estado;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Core\Traits\LogService;

class AlterarEstadoPlanoPropinaAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(PlanoPropina $plano, Estado $novoEstado): PlanoPropina
    {
        return $this->comLog('Alterar estado plano propina', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($plano, $novoEstado));
    }

    private function executarSemRegisto(PlanoPropina $plano, Estado $novoEstado): PlanoPropina
    {
        $plano->estado = $novoEstado->value;
        $plano->save();

        return $plano->fresh();
    }
}
