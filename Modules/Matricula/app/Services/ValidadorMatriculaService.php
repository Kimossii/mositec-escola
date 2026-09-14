<?php

namespace Modules\Matricula\Services;

use Illuminate\Validation\ValidationException;
use Modules\Aluno\Actions\CriarEnquadramentoAcademicoAlunoAction;
use Modules\Aluno\Enums\EstadoEnquadramentoAcademicoEnum;
use Modules\Aluno\Models\Aluno;
use Modules\Core\Enums\Estado;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Turma\Models\Turma;

class ValidadorMatriculaService
{
    public function __construct(
        private CriarEnquadramentoAcademicoAlunoAction $criarEnquadramentoAcademico,
    ) {
    }

    public function validarTurma(Turma $turma, int $anoLectivoId): void
    {
        if ($turma->ano_lectivo_id !== $anoLectivoId) {
            throw ValidationException::withMessages([
                'turma_id' => 'A turma não pertence ao ano lectivo seleccionado.',
            ]);
        }

        if ($turma->estado !== Estado::ATIVO->value) {
            throw ValidationException::withMessages([
                'turma_id' => 'Não é possível utilizar uma turma inactiva.',
            ]);
        }
    }

    /**
     * Confirma que o aluno tem um enquadramento académico compatível com a
     * turma. Se ainda não tiver nenhum, assume automaticamente o Curso/Nível
     * da própria turma (a matrícula é o que estabelece o enquadramento). Se
     * já tiver um enquadramento activo para um Curso/Nível diferente, rejeita
     * — evita matricular por engano num percurso académico errado.
     */
    public function garantirEnquadramentoAcademico(Aluno $aluno, Turma $turma, ?int $utilizadorId = null): void
    {
        $enquadramentosActivos = $aluno->enquadramentosAcademicos()
            ->where('estado', EstadoEnquadramentoAcademicoEnum::ACTIVO->value);

        $compativel = (clone $enquadramentosActivos)
            ->when(
                $turma->curso_id !== null,
                fn ($query) => $query->where('curso_id', $turma->curso_id),
                fn ($query) => $query->where('nivel_academico_id', $turma->nivel_academico_id),
            )
            ->exists();

        if ($compativel) {
            return;
        }

        if ($enquadramentosActivos->exists()) {
            throw ValidationException::withMessages([
                'turma_id' => 'O enquadramento académico do aluno não é compatível com a turma.',
            ]);
        }

        $this->criarEnquadramentoAcademico->executar(
            $aluno,
            cursoId: $turma->curso_id,
            nivelAcademicoId: $turma->curso_id === null ? $turma->nivel_academico_id : null,
            utilizadorId: $utilizadorId,
        );
    }

    public function validarMatriculaNaoDuplicada(
        Aluno $aluno,
        Turma $turma,
        ?int $ignorarMatriculaId = null,
    ): void {
        $query = Matricula::query()
            ->where('aluno_id', $aluno->id)
            ->whereIn('estado', [
                EstadoMatriculaEnum::PENDENTE->value,
                EstadoMatriculaEnum::ACTIVA->value,
            ])
            ->whereHas('turma', function ($query) use ($turma) {
                if ($turma->curso_id !== null) {
                    $query->where('curso_id', $turma->curso_id);
                } else {
                    $query->whereNull('curso_id')
                        ->where('nivel_academico_id', $turma->nivel_academico_id);
                }
            });

        if ($ignorarMatriculaId !== null) {
            $query->where('id', '!=', $ignorarMatriculaId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([
                'turma_id' => 'O aluno já possui uma matrícula activa ou pendente neste contexto académico.',
            ]);
        }
    }
}
