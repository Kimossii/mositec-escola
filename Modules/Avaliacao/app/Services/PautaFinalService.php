<?php

namespace Modules\Avaliacao\Services;

use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\Avaliacao\Enums\Decisao;
use Modules\Avaliacao\Enums\EstadoPauta;
use Modules\Avaliacao\Models\AvaliacaoDecisao;
use Modules\Avaliacao\Models\AvaliacaoPauta;
use Modules\Avaliacao\Models\AvaliacaoPautaLinha;
use Modules\Avaliacao\Models\AvaliacaoPautaLinhaQualitativa;
use Modules\Avaliacao\Models\AvaliacaoRegra;
use Modules\Avaliacao\Support\Calculo\CalculadoraQuantitativa;
use Modules\Avaliacao\Support\Calculo\DecisaoFinal;
use Modules\Disciplina\Models\Disciplina;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Matricula\Models\Matricula;
use Modules\Turma\Models\Turma;

/**
 * Pauta final de uma turma. Provisória (e reaberta) calcula-se em leitura a partir das notas; definitiva lê o
 * instantâneo gravado na aprovação e já não depende das notas nem da regra de hoje. Só consulta.
 */
class PautaFinalService
{
    public function __construct(
        private ResultadoDisciplinaService $resultados,
        private RegraAvaliacaoService $regras,
        private RegraPadraoService $padrao,
        private DecisaoFinal $decisao,
        private CalculadoraQuantitativa $calculadora,
        private PautaQualitativaService $qualitativa,
    ) {
    }

    /**
     * Turmas do ano lectivo activo (e as que já têm pauta) com o estado da pauta final.
     *
     * @return list<array{id: int, rotulo: string, estado: string}>
     */
    public function turmas(): array
    {
        $pautas = AvaliacaoPauta::query()->get()->keyBy('turma_id');

        return Turma::query()
            ->with(['turno:id,nome', 'anoLectivo:id,nome'])
            ->where(fn ($q) => $q->whereHas('anoLectivo', fn ($a) => $a->where('estado', EstadoAnoLectivo::ATIVO))->orWhereIn('id', $pautas->keys()))
            ->get()
            ->map(fn (Turma $t) => ['id' => $t->id, 'rotulo' => $t->rotulo(completo: true), 'estado' => match ($pautas[$t->id]->estado ?? null) {
                EstadoPauta::DEFINITIVA => 'definitiva', EstadoPauta::REABERTA => 'reaberta', default => 'provisoria',
            }])
            ->sortBy('rotulo', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    /** Há pauta definitiva para a turma? Enquanto houver, as notas dessa turma não se alteram. */
    public function estaFechada(int $turmaId): bool
    {
        return AvaliacaoPauta::query()->where('turma_id', $turmaId)->where('estado', EstadoPauta::DEFINITIVA)->exists();
    }

    /** Recusa alterações às notas de uma turma cuja pauta final está definitiva (é preciso reabri-la primeiro). */
    public function exigirAberta(int $turmaId): void
    {
        if ($this->estaFechada($turmaId)) {
            throw ValidationException::withMessages(['pauta' => 'A pauta final desta turma é definitiva. Peça a sua reabertura para alterar notas.']);
        }
    }

    /** @return array<string, mixed> */
    public function paraTurma(int $turmaId): array
    {
        $pauta = AvaliacaoPauta::query()->with('aprovadaPor:id,name')->where('turma_id', $turmaId)->first();

        if ($pauta?->estado === EstadoPauta::DEFINITIVA) {
            return $this->instantaneo($turmaId, $pauta);
        }

        $viva = $this->montar($turmaId);
        $viva['estado'] = $pauta === null ? 'provisoria' : 'reaberta';
        $viva['versao'] = $pauta?->versao;

        return $viva;
    }

    /**
     * A pauta calculada agora, com os motivos que impedem a aprovação.
     *
     * @return array<string, mixed>
     */
    public function montar(int $turmaId): array
    {
        $turma = Turma::query()->with(['turno:id,nome', 'anoLectivo:id,nome', 'nivelAcademico:id,codigo'])->findOrFail($turmaId);
        if ($this->qualitativa->aplica($turma)) {
            return $this->qualitativa->montar($turma);
        }

        $regra = $this->regras->paraTurma($turma);
        $base = ['tipo' => 'quantitativa', 'turma_id' => $turma->id, 'turma_rotulo' => $turma->rotulo(completo: true), 'regra' => null, 'estado' => 'provisoria', 'versao' => null, 'com_exame' => false,
            'aprovada_em' => null, 'aprovada_por' => null, 'disciplinas' => [], 'alunos' => [], 'pode_aprovar' => false, 'bloqueios' => []];

        if ($regra === null) {
            return [...$base, 'bloqueios' => ['A escola ainda não configurou a regra de avaliação desta classe.']];
        }

        $base['regra'] = $this->regraParaTela($regra);
        $comExame = $this->padrao->temExameProprio((string) $turma->nivelAcademico?->codigo);

        $matriculas = Matricula::query()->where('turma_id', $turmaId)->where('estado', EstadoMatriculaEnum::ACTIVA)
            ->with('aluno.dadosPessoa:id,nome_completo,sexo')->get();
        $inscricoes = InscricaoDisciplina::query()->whereIn('matricula_id', $matriculas->pluck('id'))
            ->where('estado', EstadoInscricaoDisciplinaEnum::INSCRITA)->with('planoCurricularDisciplina:id,disciplina_id')->get();

        $porDisciplina = $inscricoes->groupBy(fn (InscricaoDisciplina $i) => $i->planoCurricularDisciplina->disciplina_id);
        $disciplinas = Disciplina::query()->findMany($porDisciplina->keys())->sortBy('nome', SORT_NATURAL | SORT_FLAG_CASE)->values();

        $celulas = [];
        foreach ($disciplinas as $disciplina) {
            $ids = $porDisciplina[$disciplina->id]->pluck('id')->all();
            foreach ($this->resultados->calcularTurma($turmaId, $disciplina->id, $ids) as $inscricaoId => $r) {
                $matriculaId = $porDisciplina[$disciplina->id]->firstWhere('id', $inscricaoId)->matricula_id;
                $celulas[$matriculaId][$disciplina->id] = [
                    'inscricao_id' => $inscricaoId,
                    'mt1' => $this->apresentar($r->mt[1], $regra), 'mt2' => $this->apresentar($r->mt[2], $regra), 'mt3' => $this->apresentar($r->mt[3], $regra),
                    'ne' => $r->ne === null ? null : $this->apresentar($r->ne, $regra),
                    'mfd_exacta' => $r->mfd,
                    'mfd' => $r->mfdArredondada,
                    'mf_exacta' => $r->mf,
                    'mf' => $r->mfArredondada,
                    'nota_final' => $r->notaFinalArredondada(),
                    'aprovado' => $r->notaFinalArredondada() === null ? null : $this->calculadora->aprovadoNaDisciplina($r->notaFinalArredondada(), (string) $regra->nota_minima),
                    'pendente' => $r->notaFinalArredondada() === null,
                    'exame_em_falta' => $comExame && $r->ne === null,
                ];
            }
        }

        $alunos = [];
        $comPendentes = 0;
        foreach ($matriculas as $m) {
            $doAluno = $celulas[$m->id] ?? [];
            $decisao = $this->decisao->decidir(array_column($doAluno, 'nota_final'), (string) $regra->nota_minima, $regra->reprova_por_aproveitamento, $comExame);
            $pendente = in_array(true, array_column($doAluno, 'pendente'), true) || $doAluno === [];
            $comPendentes += $pendente ? 1 : 0;

            $alunos[] = [
                'matricula_id' => $m->id,
                'nome' => (string) $m->aluno?->dadosPessoa?->nome_completo,
                'numero_matricula' => $m->aluno?->numero_matricula,
                'sexo' => $m->aluno?->dadosPessoa?->sexo,
                'celulas' => $doAluno,
                'decisao' => $this->decisaoParaTela($decisao),
                'estado_decisao' => $decisao !== null ? 'definida' : 'pendente',
            ];
        }
        usort($alunos, fn (array $x, array $y) => strnatcasecmp($x['nome'], $y['nome']));

        $bloqueios = [];
        if ($alunos === []) {
            $bloqueios[] = 'A turma não tem alunos com matrícula activa.';
        }
        $semExame = count(array_filter($alunos, fn (array $a) => in_array(true, array_column($a['celulas'], 'exame_em_falta'), true)));
        if ($comExame && $semExame > 0) {
            $bloqueios[] = "Faltam notas de exame em {$semExame} aluno(s): lance-as antes de aprovar.";
        }
        if ($comPendentes > 0) {
            $bloqueios[] = "Faltam resultados em {$comPendentes} aluno(s): lance as notas em falta antes de aprovar.";
        }

        return [
            ...$base,
            'com_exame' => $comExame,
            'disciplinas' => $disciplinas->map(fn (Disciplina $d) => ['id' => $d->id, 'nome' => (string) $d->nome])->all(),
            'alunos' => $alunos,
            'pode_aprovar' => $bloqueios === [],
            'bloqueios' => $bloqueios,
        ];
    }

    /** @return array<string, mixed> */
    private function instantaneo(int $turmaId, AvaliacaoPauta $pauta): array
    {
        $turma = Turma::query()->with(['turno:id,nome', 'anoLectivo:id,nome'])->findOrFail($turmaId);

        if (AvaliacaoPautaLinhaQualitativa::query()->where('pauta_id', $pauta->id)->where('versao', $pauta->versao)->exists()) {
            return $this->qualitativa->instantaneo($turma, $pauta);
        }

        $regra = $this->regras->paraTurma($turma);

        $linhas = AvaliacaoPautaLinha::query()->where('pauta_id', $pauta->id)->where('versao', $pauta->versao)->get();
        $inscricoes = InscricaoDisciplina::query()->whereIn('id', $linhas->pluck('inscricao_disciplina_id'))
            ->with(['planoCurricularDisciplina:id,disciplina_id', 'matricula.aluno.dadosPessoa:id,nome_completo,sexo,sexo'])->get()->keyBy('id');
        $decisoes = AvaliacaoDecisao::query()->where('pauta_id', $pauta->id)->where('versao', $pauta->versao)->get()->keyBy('matricula_id');
        $disciplinas = Disciplina::query()->findMany($inscricoes->pluck('planoCurricularDisciplina.disciplina_id')->unique())
            ->sortBy('nome', SORT_NATURAL | SORT_FLAG_CASE)->values();
        $casas = $pauta->casas_decimais;

        $alunos = [];
        foreach ($linhas as $l) {
            $i = $inscricoes[$l->inscricao_disciplina_id];
            $m = $i->matricula;
            $alunos[$m->id] ??= [
                'matricula_id' => $m->id,
                'nome' => (string) $m->aluno?->dadosPessoa?->nome_completo,
                'numero_matricula' => $m->aluno?->numero_matricula,
                'sexo' => $m->aluno?->dadosPessoa?->sexo,
                'celulas' => [],
                'decisao' => $this->decisaoParaTela($decisoes[$m->id]->decisao ?? null),
                'estado_decisao' => 'definida',
            ];
            $alunos[$m->id]['celulas'][$i->planoCurricularDisciplina->disciplina_id] = [
                'inscricao_id' => $i->id,
                'mt1' => $this->formatar($l->mt1, $casas), 'mt2' => $this->formatar($l->mt2, $casas), 'mt3' => $this->formatar($l->mt3, $casas),
                'ne' => $this->formatar($l->ne, $casas),
                'mfd_exacta' => $l->mfd_exacta, 'mfd' => $this->formatar($l->mfd, $casas), 'mf_exacta' => $l->mf_exacta, 'mf' => $this->formatar($l->mf, $casas),
                'nota_final' => $this->formatar($l->mf ?? $l->mfd, $casas), 'aprovado' => $l->aprovado, 'pendente' => $l->mfd === null, 'exame_em_falta' => false,
            ];
        }
        $alunos = array_values($alunos);
        usort($alunos, fn (array $x, array $y) => strnatcasecmp($x['nome'], $y['nome']));

        return [
            'tipo' => 'quantitativa',
            'com_exame' => $linhas->contains(fn (AvaliacaoPautaLinha $l) => $l->mf !== null),
            'turma_id' => $turma->id,
            'turma_rotulo' => $turma->rotulo(completo: true),
            'regra' => $regra === null ? null : $this->regraParaTela($regra),
            'estado' => 'definitiva',
            'versao' => $pauta->versao,
            'aprovada_em' => $pauta->aprovada_em?->toDateTimeString(),
            'aprovada_por' => $pauta->aprovadaPor?->name,
            'disciplinas' => $disciplinas->map(fn (Disciplina $d) => ['id' => $d->id, 'nome' => (string) $d->nome])->all(),
            'alunos' => $alunos,
            'pode_aprovar' => false,
            'bloqueios' => [],
        ];
    }

    private function regraParaTela(AvaliacaoRegra $regra): array
    {
        return ['escala_maxima' => $regra->escala_maxima, 'nota_minima' => (string) $regra->nota_minima, 'casas_decimais' => $regra->casas_decimais];
    }

    private function apresentar(?string $valor, AvaliacaoRegra $regra): ?string
    {
        return $valor === null ? null : $this->calculadora->arredondar($valor, $regra->casas_decimais, $regra->modo_arredondamento);
    }

    private function formatar(?string $valor, int $casas): ?string
    {
        return $valor === null ? null : bcadd($valor, '0', $casas);
    }

    private function decisaoParaTela(?Decisao $decisao): ?array
    {
        return $decisao === null ? null : ['valor' => $decisao->value, 'rotulo' => $decisao->label()];
    }
}
