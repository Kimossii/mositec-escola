<?php

namespace Modules\Core\Actions\Horario;

use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\Horario;

class EliminarHorarioAction
{
    /**
     * O Core não conhece quem consome o Horario (ex: Turno, no módulo
     * Turma), então não há relação a verificar aqui — apanhamos a violação
     * de chave estrangeira genericamente, sem acoplar o Core ao chamador.
     */
    public function executar(Horario $horario): void
    {
        try {
            $horario->delete();
        } catch (QueryException $e) {
            if (str_contains(strtolower($e->getMessage()), 'foreign key')) {
                throw ValidationException::withMessages([
                    'horario' => 'Este horário está em uso e não pode ser eliminado.',
                ]);
            }

            throw $e;
        }
    }
}
