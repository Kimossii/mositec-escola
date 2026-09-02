<?php

namespace Modules\Core\Actions\Horario;

use Modules\Core\DTO\HorarioDTO;
use Modules\Core\Models\Horario;
use Modules\Core\Services\HorarioService;

class AtualizarHorarioAction
{
    public function __construct(
        private readonly HorarioService $horarioService
    ) {
    }

    public function execute(Horario $horario, HorarioDTO $dto): Horario
    {
        return $this->horarioService->atualizar($horario, $dto);
    }
}
