<?php

namespace Modules\AnoLectivo\Actions;

use Modules\AnoLectivo\Models\Periodo;
use Modules\Core\Traits\LogService;

class EliminarPeriodoAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Periodo $periodo): void
    {
        $this->comLog('Eliminar período', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($periodo));
    }

    private function executarSemRegisto(Periodo $periodo): void
    {
        // Sem dependentes noutros módulos nesta fase. Quando
        // `Avaliacao.periodo_id` existir, esta Action tem de ganhar a
        // mesma verificação de dependentes que `EliminarAnoLectivoAction`.
        $periodo->delete();
    }
}
