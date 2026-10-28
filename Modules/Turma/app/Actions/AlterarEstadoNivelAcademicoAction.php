<?php

namespace Modules\Turma\Actions;

use Modules\Core\Enums\Estado;
use Modules\Turma\Models\NivelAcademico;
use Modules\Core\Traits\LogService;

class AlterarEstadoNivelAcademicoAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(NivelAcademico $nivelAcademico, Estado $novoEstado): NivelAcademico
    {
        return $this->comLog('Alterar estado nível académico', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($nivelAcademico, $novoEstado));
    }

    private function executarSemRegisto(NivelAcademico $nivelAcademico, Estado $novoEstado): NivelAcademico
    {
        $nivelAcademico->estado = $novoEstado->value;
        $nivelAcademico->save();

        return $nivelAcademico->fresh();
    }
}
