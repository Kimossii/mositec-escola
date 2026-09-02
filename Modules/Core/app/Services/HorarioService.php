<?php

namespace Modules\Core\Services;

use Illuminate\Support\Facades\Auth;
use Modules\Core\DTO\HorarioDTO;
use Modules\Core\Models\Horario;

class HorarioService
{
    /**
     * Cria um novo horário.
     */
    public function criar(HorarioDTO $dto): Horario
    {
        return Horario::create([
            'nome' => $dto->nome,
            'hora_inicio' => $dto->hora_inicio,
            'hora_fim' => $dto->hora_fim,
            'estado' => $dto->estado->value,
            'criado_por' => Auth::id(),
            'editado_por' => null,
        ]);
    }

    /**
     * Atualiza um horário existente.
     */
    public function atualizar(
        Horario $horario,
        HorarioDTO $dto
    ): Horario {
        $horario->update([
            'nome' => $dto->nome,
            'hora_inicio' => $dto->hora_inicio,
            'hora_fim' => $dto->hora_fim,
            'estado' => $dto->estado->value,
            'editado_por' => Auth::id(),
        ]);

        return $horario->refresh();
    }

    /**
     * Elimina um horário.
     */
    public function eliminar(Horario $horario): void
    {
        $horario->delete();
    }
}
