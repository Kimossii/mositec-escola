<?php

namespace Modules\Turma\Actions;

use Modules\Core\Enums\Estado;
use Modules\Turma\Models\NivelAcademico;

class AlterarEstadoNivelAcademicoAction
{
    public function executar(NivelAcademico $nivelAcademico, Estado $novoEstado): NivelAcademico
    {
        $nivelAcademico->estado = $novoEstado->value;
        $nivelAcademico->save();

        return $nivelAcademico->fresh();
    }
}
