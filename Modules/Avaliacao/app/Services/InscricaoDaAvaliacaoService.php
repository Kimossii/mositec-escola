<?php

namespace Modules\Avaliacao\Services;

use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Turma\Models\Turma;

/** Verificações partilhadas pelas Actions que lançam notas (nota, exame, recurso): a inscrição é da turma e disciplina, e o 3.º trimestre existe. */
class InscricaoDaAvaliacaoService
{
    /** A inscrição tem de ser do aluno nesta turma e disciplina; devolve-a com a matrícula e o plano carregados. */
    public function exigir(int $inscricaoId, int $turmaId, int $disciplinaId): InscricaoDisciplina
    {
        $inscricao = InscricaoDisciplina::query()->with(['matricula', 'planoCurricularDisciplina'])->findOrFail($inscricaoId);

        if ($inscricao->matricula->turma_id !== $turmaId || $inscricao->planoCurricularDisciplina->disciplina_id !== $disciplinaId) {
            throw ValidationException::withMessages(['inscricao_disciplina_id' => 'O aluno não está inscrito nesta disciplina e turma.']);
        }

        return $inscricao;
    }

    public function terceiroTrimestre(Turma $turma): Periodo
    {
        return Periodo::query()->where('ano_lectivo_id', $turma->ano_lectivo_id)->where('tipo', TipoPeriodo::TRIMESTRE)->where('numero', 3)->first()
            ?? throw ValidationException::withMessages(['turma_id' => 'O ano lectivo desta turma não tem o 3.º trimestre.']);
    }
}
