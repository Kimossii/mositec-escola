<?php

namespace Modules\Core\Actions\Horario;

use Modules\Core\DTO\HorarioDTO;
use Modules\Core\Models\Horario;

class AtualizarHorarioAction
{
    public function atualizar(Horario $horario, HorarioDTO $dto): Horario
    {
        $horario->update([
            'nome' => $dto->nome,
            'hora_inicio' => $dto->horaInicio,
            'hora_fim' => $dto->horaFim,
            'estado' => $dto->estado->value,
        ]);

        return $horario->refresh();
    }
}
