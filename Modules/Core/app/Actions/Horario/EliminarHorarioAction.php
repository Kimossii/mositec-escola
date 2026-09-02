<?php

namespace Modules\Core\Actions\Horario;

use Modules\Core\Models\Horario;
use Modules\Core\Services\HorarioService;

class EliminarHorarioAction
{
    public function __construct(
        private readonly HorarioService $horarioService
    ) {
    }

    public function execute(Horario $horario): void
    {
        $this->horarioService->eliminar($horario);
    }
}
