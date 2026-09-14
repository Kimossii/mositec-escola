<?php

namespace Modules\Matricula\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Aluno\Models\Aluno;
use Modules\Matricula\DTO\MatriculaDTO;
use Modules\Matricula\Models\Matricula;
use Modules\Turma\Models\Turma;

class AtualizarMatriculaAction
{
    public function executar(
        Matricula $matricula,
        MatriculaDTO $dto,
        ?int $utilizadorId = null,
    ): Matricula {
        return DB::transaction(function () use ($matricula, $dto, $utilizadorId) {
            $turma = Turma::query()
                ->with('curso')
                ->findOrFail($dto->turmaId);

            $this->validarTurma($turma, $dto);

            $aluno = $matricula->aluno;

            $this->validarEnquadramentoAcademico($aluno, $turma);

            $matricula->update([
                'turma_id' => $turma->id,
                'ano_lectivo_id' => $turma->ano_lectivo_id,
                'data_matricula' => $dto->dataMatricula,
                'observacoes' => $dto->observacoes,
                'editado_por' => $utilizadorId,
            ]);

            return $matricula->fresh();
        });
    }

    private function validarTurma(Turma $turma, MatriculaDTO $dto): void
    {
        if ($turma->ano_lectivo_id !== $dto->anoLectivoId) {
            throw new \DomainException(
                'A turma não pertence ao ano lectivo seleccionado.'
            );
        }

        if ($turma->estado !== 0) {
            throw new \DomainException(
                'Não é possível utilizar uma turma inactiva.'
            );
        }
    }

    private function validarEnquadramentoAcademico(
        Aluno $aluno,
        Turma $turma
    ): void {
        $query = $aluno->enquadramentosAcademicos()
            ->where('estado', 1);

        if ($turma->curso_id !== null) {
            $query->where('curso_id', $turma->curso_id);
        } else {
            $query->where('nivel_academico_id', $turma->nivel_academico_id);
        }

        if (! $query->exists()) {
            throw new \DomainException(
                'O enquadramento académico do aluno não é compatível com a turma.'
            );
        }
    }
}
