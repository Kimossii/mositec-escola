<?php

namespace Modules\Curso\Actions;

use Modules\Core\Enums\Estado;
use Modules\Curso\Models\Curso;
use Modules\Core\Traits\LogService;

class AlterarEstadoCursoAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Curso $curso, Estado $novoEstado): Curso
    {
        return $this->comLog('Alterar estado curso', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($curso, $novoEstado));
    }

    private function executarSemRegisto(Curso $curso, Estado $novoEstado): Curso
    {
        $curso->estado = $novoEstado->value;
        $curso->save();

        return $curso->fresh();
    }
}
