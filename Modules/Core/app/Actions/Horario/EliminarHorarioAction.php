<?php

namespace Modules\Core\Actions\Horario;

use Modules\Core\Models\Horario;

class EliminarHorarioAction
{
    public function executar(Horario $horario): void
    {
        $horario->delete();
    }
}
