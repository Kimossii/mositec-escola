<?php

namespace Modules\Core\Actions\Horario;

use Modules\Core\DTO\HorarioDTO;
use Modules\Core\Models\Horario;

class CriarHorarioAction
{
    public function criar(HorarioDTO $dto): Horario
    {
        return Horario::create([
            'nome' => $dto->nome,
            'hora_inicio' => $dto->horaInicio,
            'hora_fim' => $dto->horaFim,
            'estado' => $dto->estado->value,
        ]);
    }
}
