<?php

namespace Modules\Turma\Actions;

use Modules\Turma\DTO\AdicionarHorarioTurnoDTO;
use Modules\Turma\Models\Turno;
use Modules\Turma\Models\TurnoHorario;

class AdicionarHorarioTurnoAction
{
    public function executar(
        Turno $turno,
        AdicionarHorarioTurnoDTO $dto
    ): TurnoHorario {
        return TurnoHorario::create([
            'turno_id' => $turno->id,
            'horario_id' => $dto->horario_id,
            'ordem' => $dto->ordem,
        ]);
    }
}
