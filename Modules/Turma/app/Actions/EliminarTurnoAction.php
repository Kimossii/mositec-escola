<?php

namespace Modules\Turma\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Turma\Models\Turno;
use Modules\Core\Traits\LogService;

class EliminarTurnoAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Turno $turno): void
    {
        $this->comLog('Eliminar turno', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($turno));
    }

    private function executarSemRegisto(Turno $turno): void
    {
        if ($turno->turmas()->exists()) {
            throw ValidationException::withMessages([
                'turno' => 'Este turno está associado a turmas e não pode ser eliminado.',
            ]);
        }

        $turno->delete();
    }
}
