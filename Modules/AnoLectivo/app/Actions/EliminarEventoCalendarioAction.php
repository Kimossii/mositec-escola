<?php

namespace Modules\AnoLectivo\Actions;

use Modules\AnoLectivo\Models\EventoCalendario;
use Modules\Core\Traits\LogService;

class EliminarEventoCalendarioAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(EventoCalendario $evento): void
    {
        $this->comLog('Eliminar evento calendário', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($evento));
    }

    private function executarSemRegisto(EventoCalendario $evento): void
    {
        $evento->delete();
    }
}
