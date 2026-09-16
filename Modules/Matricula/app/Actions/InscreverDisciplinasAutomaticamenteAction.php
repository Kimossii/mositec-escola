<?php

namespace Modules\Matricula\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Estabelecimento\Enums\TipoEnsinoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
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
     * Fora do Ensino Superior, uma Matrícula sem Plano Curricular confirmado
     * deixaria o aluno numa turma sem estrutura curricular — por isso esta
     * exigência bloqueia a criação da Matrícula (`CriarMatriculaAction`,
     * Task 4, chama isto antes de persistir). No Ensino Superior a
     * inscrição é sempre manual, logo não há exigência: é a MESMA decisão
     * de `tipo_ensino` da Decisão 9, aplicada aqui à pré-condição em vez de
     * à execução (Decisão 10).
     */
    public function garantirPlanoCurricularConfirmado(Turma $turma): void
    {
        if (Estabelecimento::current()?->tipo_ensino === TipoEnsinoEnum::UNIVERSITARIO) {
            return;
        }

        if ($this->resolverPlanoCurricular($turma) === null) {
            throw ValidationException::withMessages([
                'turma_id' => 'Não existe um Plano Curricular confirmado para esta turma. Contacte a coordenação pedagógica antes de matricular alunos.',
            ]);
        }
    }

    /**
     * Só inscreve automaticamente fora do Ensino Superior — no Superior a
     * escolha de disciplinas é sempre manual (electivas, cadeiras em
     * atraso). Esta é a ÚNICA decisão sobre "quando automatizar" em todo o
     * fluxo: quem chama esta Action (`CriarMatriculaAction`, Task 4) chama-a
     * sempre, sem saber desta regra. O `null` de `resolverPlanoCurricular()`
     * aqui dentro é só defensivo — fora do Superior,
     * `garantirPlanoCurricularConfirmado()` já garantiu que existe plano
     * antes de a Matrícula sequer existir.
     */
    public function executar(Matricula $matricula, ?int $utilizadorId = null): int
    {
        if (Estabelecimento::current()?->tipo_ensino === TipoEnsinoEnum::UNIVERSITARIO) {
            return 0;
        }

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
