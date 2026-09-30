<?php

namespace Modules\Infraestrutura\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Infraestrutura\Models\Sala;

class EliminarSalaAction
{
    public function executar(Sala $sala): void
    {
        if ($sala->turmaSalas()->exists()) {
            throw ValidationException::withMessages([
                'sala' => 'Esta sala está associada a uma ou mais turmas e não pode ser eliminada.',
            ]);
        }

        $sala->delete();
    }
}
