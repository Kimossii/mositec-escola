<?php

namespace Modules\Infraestrutura\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Infraestrutura\Models\Sala;
use Modules\Core\Traits\LogService;

class EliminarSalaAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Sala $sala): void
    {
        $this->comLog('Eliminar sala', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($sala));
    }

    private function executarSemRegisto(Sala $sala): void
    {
        if ($sala->turmaSalas()->exists()) {
            throw ValidationException::withMessages([
                'sala' => 'Esta sala está associada a uma ou mais turmas e não pode ser eliminada.',
            ]);
        }

        $sala->delete();
    }
}
