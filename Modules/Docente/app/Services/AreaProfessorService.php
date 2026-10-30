<?php

namespace Modules\Docente\Services;

use Modules\Core\Contracts\VerificaAtribuicaoDocente;
use Modules\Disciplina\Models\Disciplina;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Turma\Models\Turma;

/** Leitura da Área do Professor: as turmas/disciplinas que lecciona hoje e os alunos de cada uma. Só consulta. */
class AreaProfessorService
{
    public function __construct(private VerificaAtribuicaoDocente $docencia)
    {
    }

    /**
     * As atribuições em vigor do utilizador, agrupadas por turma.
     *
     * @return list<array{turma_id: int, turma_rotulo: string, disciplinas: list<array{disciplina_id: int, disciplina_nome: string, papel: int, data_inicio: string, data_fim: ?string}>}>
     */
    public function minhasTurmas(int $utilizadorId): array
    {
        $porTurma = [];

        foreach ($this->docencia->atribuicoesEmVigorDoProfessor($utilizadorId) as $a) {
            $porTurma[$a['turma_id']] ??= ['turma_id' => $a['turma_id'], 'turma_rotulo' => $a['turma_rotulo'], 'disciplinas' => []];
            $porTurma[$a['turma_id']]['disciplinas'][] = [
                'disciplina_id' => $a['disciplina_id'],
                'disciplina_nome' => $a['disciplina_nome'],
                'papel' => $a['papel'],
                'data_inicio' => $a['data_inicio'],
                'data_fim' => $a['data_fim'],
            ];
        }

        usort($porTurma, fn (array $x, array $y) => strcmp($x['turma_rotulo'], $y['turma_rotulo']));

        return array_values($porTurma);
    }

    /** Pode o utilizador ver os alunos desta turma e disciplina? (atribuição em vigor) */
    public function podeVer(int $utilizadorId, int $turmaId, int $disciplinaId): bool
    {
        return $this->docencia->podeGerir($utilizadorId, $turmaId, $disciplinaId);
    }

    /**
     * Alunos com matrícula activa na turma e inscritos na disciplina, por ordem alfabética.
     *
     * @return array{turma_rotulo: string, disciplina_nome: string, alunos: list<array{matricula_id: int, aluno_id: int, numero_matricula: ?string, nome: string}>}
     */
    public function alunos(int $turmaId, int $disciplinaId): array
    {
        $turma = Turma::query()->with(['turno:id,nome', 'anoLectivo:id,nome', 'nivelAcademico:id,nome', 'curso:id,nome'])->findOrFail($turmaId);
        $disciplina = Disciplina::query()->findOrFail($disciplinaId);

        $alunos = Matricula::query()
            ->where('turma_id', $turmaId)
            ->where('estado', EstadoMatriculaEnum::ACTIVA)
            ->whereHas('inscricoesDisciplinas', fn ($q) => $q
                ->where('estado', EstadoInscricaoDisciplinaEnum::INSCRITA)
                ->whereHas('planoCurricularDisciplina', fn ($p) => $p->where('disciplina_id', $disciplinaId)))
            ->with('aluno.dadosPessoa:id,nome_completo')
            ->get()
            ->map(fn (Matricula $m) => [
                'matricula_id' => $m->id,
                'aluno_id' => $m->aluno_id,
                'numero_matricula' => $m->aluno?->numero_matricula,
                'nome' => (string) $m->aluno?->dadosPessoa?->nome_completo,
            ])
            ->sortBy('nome', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        $rotulo = $turma->rotulo(completo: true);
        $nivel = trim((string) $turma->nivelAcademico?->nome);

        return [
            'turma_rotulo' => $rotulo,
            // Nível só se o nome da turma ainda não o diz; curso só quando a turma tem (uma 6.ª classe não tem).
            'nivel' => $nivel !== '' && mb_stripos($rotulo, $nivel) === false ? $nivel : null,
            'curso' => $turma->curso?->nome,
            'disciplina_nome' => (string) $disciplina->nome,
            'alunos' => $alunos,
        ];
    }
}
