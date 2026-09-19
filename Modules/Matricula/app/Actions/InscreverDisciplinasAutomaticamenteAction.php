<?php

namespace Modules\Matricula\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Matricula\Models\Matricula;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\Turma\Models\Turma;

class InscreverDisciplinasAutomaticamenteAction
{
    public function __construct(
        private CriarInscricaoDisciplinaAction $criarInscricao,
    ) {
    }

    /**
     * Único sítio que sabe resolver "qual o Plano Curricular confirmado
     * desta turma" — reaproveitado tanto por `garantirPlanoCurricularConfirmado()`
     * (validação, chamada antes de criar a Matrícula) como por `executar()`
     * (inscrição, chamada depois).
     */
    public function resolverPlanoCurricular(Turma $turma): ?PlanoCurricular
    {
        return PlanoCurricular::query()
            ->where('curso_id', $turma->curso_id)
            ->where('nivel_academico_id', $turma->nivel_academico_id)
            ->where('estado', 1)
            ->whereHas('anosLectivos', function ($query) use ($turma) {
                $query->where('ano_lectivo_id', $turma->ano_lectivo_id)->where('estado', 1);
            })
            ->with('disciplinas')
            ->first();
    }

    /**
     * Uma Matrícula sem Plano Curricular confirmado deixaria o aluno numa
     * turma sem estrutura curricular — por isso esta exigência bloqueia a
     * criação da Matrícula (`CriarMatriculaAction`, Task 4, chama isto antes
     * de persistir), SEMPRE, em qualquer contexto: quais disciplinas do
     * plano entram automaticamente é decidido disciplina a disciplina (ver
     * `executar()`), não por tipo de ensino — mas o plano em si continua a
     * ser sempre necessário.
     */
    public function garantirPlanoCurricularConfirmado(Turma $turma): void
    {
        if ($this->resolverPlanoCurricular($turma) === null) {
            throw ValidationException::withMessages([
                'turma_id' => 'Não existe um Plano Curricular confirmado para esta turma. Contacte a coordenação pedagógica antes de matricular alunos.',
            ]);
        }
    }

    /**
     * Inscreve automaticamente só as disciplinas do plano marcadas
     * `inscricao_automatica = true` — a regra é transversal (o mesmo plano
     * pode ter, lado a lado, disciplinas obrigatórias automáticas e
     * optativas/manuais, em qualquer tipo de ensino, incluindo o Superior).
     * Esta Action nunca consulta `tipo_ensino`: quem chama
     * (`CriarMatriculaAction`, Task 4) chama-a sempre, incondicionalmente —
     * a decisão de "o quê" automatizar vive inteiramente no Plano
     * Curricular, disciplina a disciplina, não no chamador.
     */
    public function executar(Matricula $matricula, ?int $utilizadorId = null): int
    {
        // Uma matrícula terminal nunca deveria chegar aqui — esta guard evita
        // que falhas na tentativa de inscrever caiam no catch de duplicatas abaixo,
        // mantendo esse catch restrito ao seu caso documentado (reinscrição).
        if ($matricula->estado->eTerminal()) {
            return 0;
        }

        $plano = $this->resolverPlanoCurricular($matricula->turma);

        if ($plano === null) {
            return 0;
        }

        $total = 0;

        foreach ($plano->disciplinas as $planoCurricularDisciplina) {
            if (! $planoCurricularDisciplina->inscricao_automatica) {
                continue;
            }

            try {
                $this->criarInscricao->executar($matricula, $planoCurricularDisciplina, $utilizadorId);
                $total++;
            } catch (ValidationException) {
                // Já inscrito (ex.: reinscrição na mesma turma) — ignora e segue para a próxima.
            }
        }

        return $total;
    }
}
