<?php

namespace Modules\Turma\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Turma\Models\NivelAcademico;

class EliminarNivelAcademicoAction
{
    public function executar(NivelAcademico $nivelAcademico): void
    {
        if ($nivelAcademico->turmas()->exists()) {
            throw ValidationException::withMessages([
                'nivelAcademico' => 'Este nível académico está associado a turmas e não pode ser eliminado.',
            ]);
        }

        if ($nivelAcademico->planosCurriculares()->exists()) {
            throw ValidationException::withMessages([
                'nivelAcademico' => 'Este nível académico está associado a planos curriculares e não pode ser eliminado.',
            ]);
        }

        $nivelAcademico->delete();
    }
}
