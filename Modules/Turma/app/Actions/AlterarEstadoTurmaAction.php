<?php

namespace Modules\Turma\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Core\Enums\Estado;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Turma\Models\Turma;
use Modules\Core\Traits\LogService;

class AlterarEstadoTurmaAction
{
    use LogService;

    /** Regista a operação (conclusão ou falha) e delega em `executarSemRegisto()`. */
    public function executar(Turma $turma, Estado $novoEstado): Turma
    {
        return $this->comLog('Alterar estado turma', $this->contextoDeArgumentos(func_get_args(), 'executar'), fn () => $this->executarSemRegisto($turma, $novoEstado));
    }

    private function executarSemRegisto(Turma $turma, Estado $novoEstado): Turma
    {
        if ($novoEstado === Estado::INATIVO && $this->temMatriculasOcupadas($turma)) {
            throw ValidationException::withMessages([
                'turma' => 'Esta turma tem matrículas activas ou pendentes e não pode ser desactivada.',
            ]);
        }

        $turma->estado = $novoEstado->value;
        $turma->save();

        return $turma->fresh();
    }

    private function temMatriculasOcupadas(Turma $turma): bool
    {
        return Matricula::where('turma_id', $turma->id)
            ->whereIn('estado', [
                EstadoMatriculaEnum::PENDENTE->value,
                EstadoMatriculaEnum::ACTIVA->value,
            ])
            ->exists();
    }
}
