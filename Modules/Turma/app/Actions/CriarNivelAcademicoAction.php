<?php

namespace Modules\Turma\Actions;

use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Turma\DTO\NivelAcademicoDTO;
use Modules\Turma\Models\NivelAcademico;

class CriarNivelAcademicoAction
{
    public function executar(NivelAcademicoDTO $dto): NivelAcademico
    {
        return NivelAcademico::create([
            'estabelecimento_id' => Estabelecimento::current()?->id,
            'codigo' => $dto->codigo,
            'nome' => $dto->nome,
            'ordem' => $dto->ordem,
            'etapa_ensino' => $dto->etapa_ensino,
        ]);
    }
}
