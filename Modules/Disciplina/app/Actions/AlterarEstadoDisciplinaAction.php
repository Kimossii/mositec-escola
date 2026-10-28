<?php

namespace Modules\Disciplina\Actions;

use Modules\Core\Enums\Estado;
use Modules\Disciplina\Models\Disciplina;
use Modules\Core\Traits\LogService;

class AlterarEstadoDisciplinaAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Disciplina $disciplina, Estado $novoEstado): Disciplina
    {
        return $this->comLog('Alterar estado disciplina', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($disciplina, $novoEstado));
    }

    private function executarSemRegisto(Disciplina $disciplina, Estado $novoEstado): Disciplina
    {
        $disciplina->estado = $novoEstado->value;
        $disciplina->save();

        return $disciplina->fresh();
    }
}
