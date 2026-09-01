<?php

namespace Modules\AnoLectivo\Actions;

use Modules\AnoLectivo\Models\EventoCalendario;

class EliminarEventoCalendarioAction
{
    public function executar(EventoCalendario $evento): void
    {
        $evento->delete();
    }
}
