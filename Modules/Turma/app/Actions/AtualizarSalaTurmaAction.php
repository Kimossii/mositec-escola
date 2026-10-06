<?php

namespace Modules\Turma\Actions;

use Modules\Turma\DTO\TurmaSalaDTO;
use Modules\Turma\Models\TurmaSala;

class AtualizarSalaTurmaAction
{
    public function executar(
        TurmaSala $turmaSala,
        TurmaSalaDTO $dto
    ): TurmaSala {
        $turmaSala->update([
            'sala_id' => $dto->sala_id,
            'inicio' => $dto->inicio,
        ]);

        return $turmaSala->fresh();
    }
}
