<?php

namespace Modules\Matricula\Services;

use Illuminate\Validation\ValidationException;
use Modules\Aluno\Actions\CriarEnquadramentoAcademicoAlunoAction;
use Modules\Aluno\Enums\EstadoEnquadramentoAcademicoEnum;
use Modules\Aluno\Models\Aluno;
use Modules\Core\Enums\Estado;
use Modules\Estabelecimento\Enums\TipoEnsinoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
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
     * turma, criando um automaticamente quando necessário (a matrícula é o
     * que estabelece o enquadramento).
     *
     * Com Curso, a compatibilidade assenta só no Curso (ignora o Nível), para
     * permitir matricular o aluno em turmas de anos diferentes do mesmo curso
     * (ex.: cadeira em atraso). A exclusividade a UM curso de cada vez só se
     * aplica em estabelecimentos que não sejam de Ensino Superior — só aí
     * (`TipoEnsinoEnum::UNIVERSITARIO`) um aluno pode ter vários cursos em
     * paralelo (ex.: duas licenciaturas); um Curso Técnico continua exclusivo,
     * tal como um Nível sem Curso.
     *
     * Sem Curso (Ensino Geral/Técnico, por Nível), o enquadramento é sempre
     * exclusivo: um aluno só pode estar num Nível/classe de cada vez.
     */
    public function garantirEnquadramentoAcademico(Aluno $aluno, Turma $turma, ?int $utilizadorId = null): void
    {
        $enquadramentosActivos = $aluno->enquadramentosAcademicos()
            ->where('estado', EstadoEnquadramentoAcademicoEnum::ACTIVO->value);

        if ($turma->curso_id !== null) {
            $temEsteCurso = (clone $enquadramentosActivos)
                ->where('curso_id', $turma->curso_id)
                ->exists();

            if ($temEsteCurso) {
                return;
            }

            $permiteVariosCursosEmParalelo = Estabelecimento::current()?->tipo_ensino === TipoEnsinoEnum::UNIVERSITARIO;

            if (! $permiteVariosCursosEmParalelo && (clone $enquadramentosActivos)->whereNotNull('curso_id')->exists()) {
                throw ValidationException::withMessages([
                    'turma_id' => 'O enquadramento académico do aluno não é compatível com a turma.',
                ]);
            }

            $this->criarEnquadramentoAcademico->executar(
                $aluno,
                cursoId: $turma->curso_id,
                nivelAcademicoId: $turma->nivel_academico_id,
                utilizadorId: $utilizadorId,
            );

            return;
        }

        $temEsteNivel = (clone $enquadramentosActivos)
            ->where('nivel_academico_id', $turma->nivel_academico_id)
            ->exists();

        if ($temEsteNivel) {
            return;
        }

        if ((clone $enquadramentosActivos)->whereNull('curso_id')->exists()) {
            throw ValidationException::withMessages([
                'turma_id' => 'O enquadramento académico do aluno não é compatível com a turma.',
            ]);
        }

        $this->criarEnquadramentoAcademico->executar(
            $aluno,
            cursoId: null,
            nivelAcademicoId: $turma->nivel_academico_id,
            utilizadorId: $utilizadorId,
        );
    }

    /**
     * Rejeita uma segunda matrícula activa/pendente na mesma "identidade
     * académica" — o par (curso_id, nivel_academico_id) da Turma, que é a
     * mesma chave que o PlanoCurricular já usa para representar um ano
     * curricular de um Curso. Sem Curso (Ensino Geral/Técnico), isto reduz-se
     * ao Nível: o aluno não pode estar em duas turmas da mesma classe.
     *
     * Com Curso (Ensino Superior), o Nível passa a distinguir o ano dentro
     * do curso — por isso duas matrículas no mesmo Curso mas em anos
     * diferentes são permitidas (ex.: aluno do 2º ano com uma cadeira em
     * atraso do 1º). A inscrição em disciplinas específicas fica para quando
     * esse conceito existir; a Matrícula só cobre o vínculo à Turma.
     */
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
                $query->where('curso_id', $turma->curso_id)
                    ->where('nivel_academico_id', $turma->nivel_academico_id);
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
