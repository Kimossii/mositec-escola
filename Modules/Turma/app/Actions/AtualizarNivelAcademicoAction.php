<?php

namespace Modules\Turma\Actions;

use Illuminate\Support\Facades\Auth;
use Modules\Turma\app\Models\NivelAcademico;
use Modules\Turma\DTO\NivelAcademicoDTO;


class AtualizarNivelAcademicoAction
{
    public function executar(
        NivelAcademico $nivelAcademico,
        NivelAcademicoDTO $dto
    ): NivelAcademico {
        $nivelAcademico->fill([
            'codigo' => $dto->codigo,
            'nome' => $dto->nome,
            'ordem' => $dto->ordem,
            'editado_por' => Auth::id(),
        ]);

        $nivelAcademico->save();

        return $nivelAcademico->fresh();
    }
}
