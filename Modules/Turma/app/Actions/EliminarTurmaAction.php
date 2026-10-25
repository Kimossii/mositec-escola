<?php

namespace Modules\Turma\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Turma\Models\Turma;
use Modules\Core\Traits\LogService;

class EliminarTurmaAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Turma $turma): void
    {
        $this->comLog('Eliminar turma', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($turma));
    }

    private function executarSemRegisto(Turma $turma): void
    {
        $temMatriculas = Matricula::where('turma_id', $turma->id)
            ->whereIn('estado', [
                EstadoMatriculaEnum::PENDENTE->value,
                EstadoMatriculaEnum::ACTIVA->value,
            ])
            ->exists();

        if ($temMatriculas) {
            throw ValidationException::withMessages([
                'turma' => 'Esta turma tem matrículas activas ou pendentes e não pode ser eliminada.',
            ]);
        }

        $turma->delete();
    }
}
