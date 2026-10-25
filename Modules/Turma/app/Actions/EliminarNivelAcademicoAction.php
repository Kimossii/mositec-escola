<?php

namespace Modules\Turma\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Turma\Models\NivelAcademico;
use Modules\Core\Traits\LogService;

class EliminarNivelAcademicoAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(NivelAcademico $nivelAcademico): void
    {
        $this->comLog('Eliminar nível académico', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($nivelAcademico));
    }

    private function executarSemRegisto(NivelAcademico $nivelAcademico): void
    {
        if ($nivelAcademico->turmas()->exists()) {
            throw ValidationException::withMessages([
                'nivelAcademico' => 'Este nível académico está associado a turmas e não pode ser eliminado.',
            ]);
        }

        if ($nivelAcademico->planosCurriculares()->exists()) {
            throw ValidationException::withMessages([
                'nivelAcademico' => 'Este nível académico está associado a planos curriculares e não pode ser eliminado.',
            ]);
        }

        $nivelAcademico->delete();
    }
}
