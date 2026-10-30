<?php

namespace Modules\Docente\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Aluno\Models\Aluno;
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

        $alunos = $this->matriculasInscritas($turmaId, $disciplinaId)
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

    /**
     * Todos os alunos das turmas/disciplinas que o utilizador lecciona hoje, numa só lista (um aluno que está em
     * duas das suas turmas ou disciplinas aparece uma vez). Filtros opcionais por turma, disciplina e texto no nome ou n.º.
     *
     * @return array{alunos: list<array{aluno_id: int, numero_matricula: ?string, nome: string, foto_url: ?string, turmas: list<string>, disciplinas: list<string>}>, turmas: list<array{id: int, rotulo: string}>, disciplinas: list<array{id: int, nome: string}>}
     */
    public function meusAlunos(int $utilizadorId, ?int $turmaId = null, ?int $disciplinaId = null, ?string $pesquisa = null): array
    {
        $atribuicoes = $this->docencia->atribuicoesEmVigorDoProfessor($utilizadorId);

        $turmas = collect($atribuicoes)->unique('turma_id')->map(fn (array $a) => ['id' => $a['turma_id'], 'rotulo' => $a['turma_rotulo']])
            ->sortBy('rotulo', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
        $disciplinas = collect($atribuicoes)->unique('disciplina_id')->map(fn (array $a) => ['id' => $a['disciplina_id'], 'nome' => $a['disciplina_nome']])
            ->sortBy('nome', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();

        $porAluno = [];
        foreach ($atribuicoes as $a) {
            if (($turmaId && $a['turma_id'] !== $turmaId) || ($disciplinaId && $a['disciplina_id'] !== $disciplinaId)) {
                continue;
            }

            foreach ($this->matriculasInscritas($a['turma_id'], $a['disciplina_id']) as $m) {
                $porAluno[$m->aluno_id] ??= [
                    'aluno_id' => $m->aluno_id,
                    'numero_matricula' => $m->aluno?->numero_matricula,
                    'nome' => (string) $m->aluno?->dadosPessoa?->nome_completo,
                    'foto_url' => $this->fotoUrl($m->aluno),
                    'turmas' => [],
                    'disciplinas' => [],
                ];
                $porAluno[$m->aluno_id]['turmas'][$a['turma_rotulo']] = $a['turma_rotulo'];
                $porAluno[$m->aluno_id]['disciplinas'][$a['disciplina_nome']] = $a['disciplina_nome'];
            }
        }

        $termo = mb_strtolower(trim((string) $pesquisa));
        $alunos = collect($porAluno)
            ->when($termo !== '', fn ($c) => $c->filter(fn (array $x) => str_contains(mb_strtolower($x['nome']), $termo) || str_contains(mb_strtolower((string) $x['numero_matricula']), $termo)))
            ->map(fn (array $x) => [...$x, 'turmas' => array_values($x['turmas']), 'disciplinas' => array_values($x['disciplinas'])])
            ->sortBy('nome', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        return ['alunos' => $alunos, 'turmas' => $turmas, 'disciplinas' => $disciplinas];
    }

    /**
     * Ficha limitada de um aluno que o utilizador lecciona hoje: identificação e o que partilham (turmas e
     * disciplinas). Sem contactos, BI nem nada de finanças. `null` se o aluno não é seu.
     *
     * @return ?array{aluno_id: int, nome: string, numero_matricula: ?string, foto_url: ?string, turmas: list<array{turma_id: int, turma_rotulo: string, disciplinas: list<string>}>}
     */
    public function ficha(int $utilizadorId, int $alunoId): ?array
    {
        $aluno = null;
        $porTurma = [];

        foreach ($this->docencia->atribuicoesEmVigorDoProfessor($utilizadorId) as $a) {
            $m = $this->matriculasInscritas($a['turma_id'], $a['disciplina_id'])->firstWhere('aluno_id', $alunoId);
            if (! $m) {
                continue;
            }

            $aluno = $m->aluno;
            $porTurma[$a['turma_id']] ??= ['turma_id' => $a['turma_id'], 'turma_rotulo' => $a['turma_rotulo'], 'disciplinas' => []];
            $porTurma[$a['turma_id']]['disciplinas'][] = $a['disciplina_nome'];
        }

        if (! $aluno) {
            return null;
        }

        return [
            'aluno_id' => $aluno->id,
            'nome' => (string) $aluno->dadosPessoa?->nome_completo,
            'numero_matricula' => $aluno->numero_matricula,
            'foto_url' => $this->fotoUrl($aluno),
            'turmas' => array_values($porTurma),
        ];
    }

    /** A foto serve-se por rota do professor: a do módulo Aluno exige `aluno.ver`, que ele não tem. */
    private function fotoUrl(?Aluno $aluno): ?string
    {
        return $aluno?->foto_path ? route('area-professor.alunos-foto', $aluno->id) : null;
    }

    /** Matrículas activas na turma com inscrição a decorrer na disciplina. */
    private function matriculasInscritas(int $turmaId, int $disciplinaId): Collection
    {
        return Matricula::query()
            ->where('turma_id', $turmaId)
            ->where('estado', EstadoMatriculaEnum::ACTIVA)
            ->whereHas('inscricoesDisciplinas', fn ($q) => $q
                ->where('estado', EstadoInscricaoDisciplinaEnum::INSCRITA)
                ->whereHas('planoCurricularDisciplina', fn ($p) => $p->where('disciplina_id', $disciplinaId)))
            ->with('aluno.dadosPessoa:id,nome_completo')
            ->get();
    }
}
