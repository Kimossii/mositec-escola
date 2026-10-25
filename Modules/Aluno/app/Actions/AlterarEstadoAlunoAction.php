<?php

namespace Modules\Aluno\Actions;

use Modules\Aluno\Models\Aluno;
use Modules\Core\Enums\Estado;
use Modules\Core\Traits\LogService;

class AlterarEstadoAlunoAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Aluno $aluno, Estado $novoEstado): Aluno
    {
        return $this->comLog('Alterar estado aluno', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($aluno, $novoEstado));
    }

    private function executarSemRegisto(Aluno $aluno, Estado $novoEstado): Aluno
    {
        $aluno->estado = $novoEstado->value;
        $aluno->save();

        return $aluno->fresh();
    }
}
