<?php

namespace Modules\Turma\Actions;

use Illuminate\Support\Facades\Auth;
use Modules\Turma\DTO\TurnoDTO;
use Modules\Turma\Models\Turno;

class AtualizarTurnoAction
{
    public function executar(Turno $turno, TurnoDTO $dto): Turno
    {
        $turno->fill([
            'nome' => $dto->nome,
            'descricao' => $dto->descricao,
            'editado_por' => Auth::id(),
        ]);

        $turno->save();

        return $turno->fresh();
    }
}
