<?php

namespace Modules\Turma\Actions;

use Modules\Turma\DTO\TurmaSalaDTO;
use Modules\Turma\Models\TurmaSala;

class EncerrarSalaTurmaAction
{
    public function executar(
        TurmaSala $turmaSala,
        TurmaSalaDTO $dto
    ): TurmaSala {
        $turmaSala->update([
            'fim' => $dto->fim,
        ]);

        return $turmaSala->fresh();
    }
}
