<?php

namespace Modules\Turma\Actions;

use Illuminate\Support\Facades\Auth;
use Modules\Turma\DTO\TurmaDTO;
use Modules\Turma\Models\Turma;

class CriarTurmaAction
{
    public function executar(TurmaDTO $dto): Turma
    {
        return Turma::create([
            'ano_lectivo_id' => $dto->ano_lectivo_id,
            'nivel_academico_id' => $dto->nivel_academico_id,
            'codigo' => $dto->codigo,
            'nome' => $dto->nome,
            'turno_id' => $dto->turno_id,
            'estado' => 1,
            'estado_descricao' => 'Ativo',
            'criado_por' => Auth::id(),
        ]);
    }
}
