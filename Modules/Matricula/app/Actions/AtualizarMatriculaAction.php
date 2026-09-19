<?php

namespace Modules\Matricula\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Matricula\DTO\MatriculaDTO;
use Modules\Matricula\Models\Matricula;
use Modules\Matricula\Services\ValidadorMatriculaService;
use Modules\Turma\Models\Turma;

class AtualizarMatriculaAction
{
    public function __construct(
        private ValidadorMatriculaService $validador,
    ) {
    }

    public function executar(
        Matricula $matricula,
        MatriculaDTO $dto,
        ?int $utilizadorId = null,
    ): Matricula {
        return DB::transaction(function () use ($matricula, $dto, $utilizadorId) {
            if ($matricula->estado->eTerminal()) {
                throw ValidationException::withMessages([
                    'estado' => 'Não é possível editar uma matrícula já concluída, cancelada ou transferida.',
                ]);
            }

            $turma = Turma::query()
                ->with(['curso', 'anoLectivo'])
                ->findOrFail($dto->turmaId);

            $this->validador->validarTurma($turma, $dto->anoLectivoId);

            $aluno = $matricula->aluno;

            $this->validador->garantirEnquadramentoAcademico($aluno, $turma, $utilizadorId);
            $this->validador->validarMatriculaNaoDuplicada($aluno, $turma, $matricula->id);

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
}
