<?php

namespace Modules\Turma\Actions;

use Illuminate\Support\Facades\Auth;
use Modules\Turma\DTO\TurnoDTO;
use Modules\Turma\Models\Turno;

class CriarTurnoAction
{
    public function executar(TurnoDTO $dto): Turno
    {
        return Turno::create([
            'nome' => $dto->nome,
            'descricao' => $dto->descricao,
            'estado' => 1,
            'estado_descricao' => 'Ativo',
            'criado_por' => Auth::id(),
        ]);
    }
}
