<?php

namespace Modules\Avaliacao\Services;

use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Avaliacao\Enums\Decisao;
use Modules\Avaliacao\Models\AvaliacaoDecisao;
use Modules\Avaliacao\Models\AvaliacaoPauta;
use Modules\Avaliacao\Models\AvaliacaoPautaLinhaQualitativa;
use Modules\Avaliacao\Models\AvaliacaoQualitativa;
use Modules\Avaliacao\Models\AvaliacaoRegraQualitativa;
use Modules\Disciplina\Models\Disciplina;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Matricula\Models\Matricula;
use Modules\Turma\Models\Turma;

/**
 * Pauta final qualitativa (Creche e Pré-Escolar): por área, o nível de cada trimestre e a MFD, que é o nível do último
 * trimestre (sem médias). A progressão é automática: Transita. Só se aprova com o 3.º trimestre avaliado em todas as áreas.
 * Mesmo formato de resposta da pauta quantitativa, para a mesma página. Só consulta.
 */
class PautaQualitativaService
{
    public function aplica(Turma $turma): bool
    {
        return $this->regra($turma) !== null;
    }

    /** @return array<string, mixed> */
    public function montar(Turma $turma): array
    {
        $regra = $this->regra($turma);
        $escala = $regra->escala()->first();
        $base = $this->base($turma, ['qualitativa' => true, 'escala_nome' => $escala->nome]);

        $periodos = Periodo::query()->where('ano_lectivo_id', $turma->ano_lectivo_id)->where('tipo', TipoPeriodo::TRIMESTRE)->get()->keyBy('numero');
        $matriculas = Matricula::query()->where('turma_id', $turma->id)->where('estado', EstadoMatriculaEnum::ACTIVA)->with('aluno.dadosPessoa:id,nome_completo')->get();
        $inscricoes = InscricaoDisciplina::query()->whereIn('matricula_id', $matriculas->pluck('id'))
            ->where('estado', EstadoInscricaoDisciplinaEnum::INSCRITA)->with('planoCurricularDisciplina:id,disciplina_id')->get();

        $avaliacoes = AvaliacaoQualitativa::query()->whereIn('inscricao_disciplina_id', $inscricoes->pluck('id'))->with('nivel')->get()
            ->groupBy('inscricao_disciplina_id');
        $disciplinas = Disciplina::query()->findMany($inscricoes->map(fn ($i) => $i->planoCurricularDisciplina->disciplina_id)->unique())
            ->sortBy('nome', SORT_NATURAL | SORT_FLAG_CASE)->values();

        $celulas = [];
        foreach ($inscricoes as $i) {
            $niveis = [];
            foreach ([1, 2, 3] as $n) {
                $a = isset($periodos[$n]) ? ($avaliacoes[$i->id] ?? collect())->firstWhere('periodo_id', $periodos[$n]->id) : null;
                $niveis[$n] = $a === null ? null : ['codigo' => $a->nivel->codigo, 'designacao' => $a->nivel->designacao];
            }
            $celulas[$i->matricula_id][$i->planoCurricularDisciplina->disciplina_id] = $this->celula($i->id, $niveis);
        }

        $alunos = [];
        $comPendentes = 0;
        foreach ($matriculas as $m) {
            $doAluno = $celulas[$m->id] ?? [];
            $pendente = $doAluno === [] || in_array(true, array_column($doAluno, 'pendente'), true);
            $comPendentes += $pendente ? 1 : 0;
            $alunos[] = [
                'matricula_id' => $m->id, 'nome' => (string) $m->aluno?->dadosPessoa?->nome_completo, 'numero_matricula' => $m->aluno?->numero_matricula,
                'celulas' => $doAluno,
                'decisao' => $pendente ? null : ['valor' => Decisao::TRANSITA->value, 'rotulo' => Decisao::TRANSITA->label()],
                'estado_decisao' => $pendente ? 'pendente' : 'definida',
            ];
        }
        usort($alunos, fn (array $x, array $y) => strnatcasecmp($x['nome'], $y['nome']));

        $bloqueios = [];
        if ($alunos === []) {
            $bloqueios[] = 'A turma não tem alunos com matrícula activa.';
        }
        if ($comPendentes > 0) {
            $bloqueios[] = "Falta a avaliação do 3.º trimestre em {$comPendentes} aluno(s): lance-a antes de aprovar.";
        }

        return [...$base, 'disciplinas' => $disciplinas->map(fn (Disciplina $d) => ['id' => $d->id, 'nome' => (string) $d->nome])->all(),
            'alunos' => $alunos, 'pode_aprovar' => $bloqueios === [], 'bloqueios' => $bloqueios];
    }

    /** @return array<string, mixed> */
    public function instantaneo(Turma $turma, AvaliacaoPauta $pauta): array
    {
        $linhas = AvaliacaoPautaLinhaQualitativa::query()->where('pauta_id', $pauta->id)->where('versao', $pauta->versao)->get();
        $inscricoes = InscricaoDisciplina::query()->whereIn('id', $linhas->pluck('inscricao_disciplina_id'))
            ->with(['planoCurricularDisciplina:id,disciplina_id', 'matricula.aluno.dadosPessoa:id,nome_completo'])->get()->keyBy('id');
        $decisoes = AvaliacaoDecisao::query()->where('pauta_id', $pauta->id)->where('versao', $pauta->versao)->get()->keyBy('matricula_id');
        $disciplinas = Disciplina::query()->findMany($inscricoes->pluck('planoCurricularDisciplina.disciplina_id')->unique())->sortBy('nome', SORT_NATURAL | SORT_FLAG_CASE)->values();

        $alunos = [];
        foreach ($linhas as $l) {
            $i = $inscricoes[$l->inscricao_disciplina_id];
            $m = $i->matricula;
            $d = $decisoes[$m->id]->decisao ?? null;
            $alunos[$m->id] ??= [
                'matricula_id' => $m->id, 'nome' => (string) $m->aluno?->dadosPessoa?->nome_completo, 'numero_matricula' => $m->aluno?->numero_matricula,
                'celulas' => [], 'decisao' => $d === null ? null : ['valor' => $d->value, 'rotulo' => $d->label()], 'estado_decisao' => 'definida',
            ];
            $alunos[$m->id]['celulas'][$i->planoCurricularDisciplina->disciplina_id] = $this->celula($i->id, [1 => $l->mt1, 2 => $l->mt2, 3 => $l->mt3]);
        }
        $alunos = array_values($alunos);
        usort($alunos, fn (array $x, array $y) => strnatcasecmp($x['nome'], $y['nome']));

        return [...$this->base($turma, ['qualitativa' => true, 'escala_nome' => $this->regra($turma)?->escala()->first()?->nome]),
            'estado' => 'definitiva', 'versao' => $pauta->versao, 'aprovada_em' => $pauta->aprovada_em?->toDateTimeString(), 'aprovada_por' => $pauta->aprovadaPor?->name,
            'disciplinas' => $disciplinas->map(fn (Disciplina $x) => ['id' => $x->id, 'nome' => (string) $x->nome])->all(),
            'alunos' => $alunos, 'pode_aprovar' => false, 'bloqueios' => []];
    }

    private function regra(Turma $turma): ?AvaliacaoRegraQualitativa
    {
        return AvaliacaoRegraQualitativa::query()->where('ano_lectivo_id', $turma->ano_lectivo_id)->where('nivel_academico_id', $turma->nivel_academico_id)->first();
    }

    /** @param  array<int, ?array{codigo: string, designacao: string}>  $niveis */
    private function celula(int $inscricaoId, array $niveis): array
    {
        $mfd = $niveis[3] ?? null;

        return [
            'inscricao_id' => $inscricaoId,
            'mt1' => $niveis[1]['designacao'] ?? null, 'mt2' => $niveis[2]['designacao'] ?? null, 'mt3' => $mfd['designacao'] ?? null,
            'mfd' => $mfd['designacao'] ?? null, 'mfd_exacta' => null, 'aprovado' => null, 'pendente' => $mfd === null,
            'niveis' => ['mt1' => $niveis[1] ?? null, 'mt2' => $niveis[2] ?? null, 'mt3' => $niveis[3] ?? null, 'mfd' => $mfd],
        ];
    }

    private function base(Turma $turma, array $regra): array
    {
        $turma->loadMissing(['turno:id,nome', 'anoLectivo:id,nome']);

        return ['tipo' => 'qualitativa', 'turma_id' => $turma->id, 'turma_rotulo' => $turma->rotulo(completo: true), 'regra' => $regra, 'estado' => 'provisoria',
            'versao' => null, 'aprovada_em' => null, 'aprovada_por' => null, 'disciplinas' => [], 'alunos' => [], 'pode_aprovar' => false, 'bloqueios' => []];
    }
}
