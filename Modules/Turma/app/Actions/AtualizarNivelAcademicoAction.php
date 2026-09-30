<?php

namespace Modules\Turma\Actions;

use Modules\Turma\DTO\NivelAcademicoDTO;
use Modules\Turma\Models\NivelAcademico;

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
            'etapa_ensino' => $dto->etapa_ensino,
        ]);

        $nivelAcademico->save();

        return $nivelAcademico->fresh();
    }
}
