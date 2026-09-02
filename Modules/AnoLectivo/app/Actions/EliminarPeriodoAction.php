<?php

namespace Modules\AnoLectivo\Actions;

use Modules\AnoLectivo\Models\Periodo;

class EliminarPeriodoAction
{
    public function executar(Periodo $periodo): void
    {
        // Sem dependentes noutros módulos nesta fase. Quando
        // `Avaliacao.periodo_id` existir, esta Action tem de ganhar a
        // mesma verificação de dependentes que `EliminarAnoLectivoAction`.
        $periodo->delete();
    }
}
