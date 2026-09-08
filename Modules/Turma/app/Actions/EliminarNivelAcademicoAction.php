<?php

namespace Modules\Turma\Actions;

use Modules\Turma\Models\NivelAcademico;

class EliminarNivelAcademicoAction
{
    public function executar(NivelAcademico $nivelAcademico): void
    {
        $nivelAcademico->delete();
    }
}
