<?php

namespace Modules\Turma\Actions;

use Modules\Core\Enums\Estado;
use Modules\Turma\Models\Turno;
use Modules\Core\Traits\LogService;

class AlterarEstadoTurnoAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Turno $turno, Estado $novoEstado): Turno
    {
        return $this->comLog('Alterar estado turno', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($turno, $novoEstado));
    }

    private function executarSemRegisto(Turno $turno, Estado $novoEstado): Turno
    {
        $turno->estado = $novoEstado->value;
        $turno->save();

        return $turno->fresh();
    }
}
