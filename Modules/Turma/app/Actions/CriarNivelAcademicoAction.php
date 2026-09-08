<?php

namespace Modules\Turma\Actions;

use Illuminate\Support\Facades\Auth;
use Modules\Turma\app\Models\NivelAcademico;
use Modules\Turma\DTO\NivelAcademicoDTO;


class CriarNivelAcademicoAction
{
    public function executar(NivelAcademicoDTO $dto): NivelAcademico
    {
        return NivelAcademico::create([
            'estabelecimento_id' => $dto->estabelecimento_id,
            'codigo' => $dto->codigo,
            'nome' => $dto->nome,
            'ordem' => $dto->ordem,
            'estado' => 1,
            'estado_descricao' => 'Ativo',
            'criado_por' => Auth::id(),
        ]);
    }
}
