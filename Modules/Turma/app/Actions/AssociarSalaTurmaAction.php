<?php

namespace Modules\Turma\Actions;

use Modules\Turma\DTO\TurmaSalaDTO;
use Modules\Turma\Models\Turma;
use Modules\Turma\Models\TurmaSala;

class AssociarSalaTurmaAction
{
    public function executar(
        Turma $turma,
        TurmaSalaDTO $dto
    ): TurmaSala {
        return $turma->turmaSalas()->create([
            'sala_id' => $dto->sala_id,
            'inicio' => $dto->inicio,
        ]);
    }
}
