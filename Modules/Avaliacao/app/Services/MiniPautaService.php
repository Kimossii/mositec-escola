<?php

namespace Modules\Avaliacao\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Avaliacao\Enums\FasePauta;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoNota;
use Modules\Avaliacao\Models\AvaliacaoNegativaTratada;
use Modules\Avaliacao\Models\AvaliacaoRegra;
use Modules\Avaliacao\Support\Calculo\CalculadoraQuantitativa;
use Modules\Core\Contracts\VerificaAtribuicaoDocente;
use Modules\Disciplina\Models\Disciplina;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Matricula\Models\Matricula;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\User;

/** Leitura da mini-pauta: o que o utilizador pode abrir e, para uma turma, disciplina e trimestre, as notas e os resultados. Só consulta. */
class MiniPautaService
{
    public function __construct(
        private VerificaAtribuicaoDocente $docencia,
        private AcessoAMiniPautaService $acesso,
        private RegraAvaliacaoService $regras,
        private DefinicoesDeAvaliacaoService $definicoes,
        private PrazoService $prazos,
        private ResultadoDisciplinaService $resultados,
        private CalculadoraQuantitativa $calculadora,
        private DocenteDoUtilizadorService $docentes,
        private RelogioDoTenant $relogio,
        private MiniPautaQualitativaService $qualitativa,
        private ElegibilidadeRecursoService $elegibilidade,
        private PautaFinalService $pautas,
    ) {
    }

    /**
     * Tudo o que o ecrã da mini-pauta precisa: as turmas e disciplinas que o utilizador pode abrir, a selecção, a mini-pauta
     * (só se houver turma e disciplina, e o utilizador puder aceder-lhe) e o que ele pode fazer.
     *
     * @return array<string, mixed>
     * @throws AuthorizationException se o utilizador não lecciona essa disciplina nessa turma
     */
    public function pagina(User $utilizador, ?int $turmaId, ?int $disciplinaId, ?int $periodoId): array
    {
        $pauta = null;
        if ($turmaId && $disciplinaId) {
            $this->acesso->exigir($utilizador, $turmaId, $disciplinaId);
            $pauta = $this->paraTurmaDisciplina($utilizador, $turmaId, $disciplinaId, $periodoId);
        }

        $gate = Gate::forUser($utilizador);

        return [
            'opcoes' => $this->opcoes($utilizador),
            'selecao' => ['turma' => $turmaId, 'disciplina' => $disciplinaId],
            'pauta' => $pauta,
            'tipos' => $this->tiposDeAvaliacao(),
            'pode' => ['anular' => $gate->allows('nota.anular'), 'criar' => $gate->allows('nota.criar'), 'fora_prazo' => $gate->allows('nota.lancar-fora-prazo'), 'tratar_negativa' => $gate->allows('nota.tratar-negativa')],
        ];
    }

    /**
     * Cada avaliação mostra-se pela sigla do seu tipo (MAC 1, MAC 2…, a sigla da prova da escola, NE, NER), nunca por um nome livre.
     *
     * @param \Illuminate\Support\Collection<int, AvaliacaoInstrumento> $instrumentos
     * @return list<array<string, mixed>>
     */
    private function instrumentosParaTela($instrumentos, string $siglaProva): array
    {
        $contador = 0;

        return $instrumentos->map(function (AvaliacaoInstrumento $i) use (&$contador, $siglaProva) {
            $sigla = match ($i->tipo) {
                TipoInstrumento::MAC => 'MAC '.++$contador,
                TipoInstrumento::PT => $siglaProva,
                TipoInstrumento::EXAME => 'NE',
                TipoInstrumento::RECURSO => 'NER',
                default => $i->nome,
            };

            return ['id' => $i->id, 'nome' => $i->nome, 'sigla' => $sigla, 'tipo' => $i->tipo->value, 'tipo_descricao' => $i->tipo_descricao, 'obrigatorio' => $i->obrigatorio];
        })->values()->all();
    }

    /**
     * Os tipos que se criam à mão. O exame, o recurso e a avaliação qualitativa criam-se pelos seus próprios ecrãs.
     *
     * @return list<array{valor: int, nome: string}>
     */
    private function tiposDeAvaliacao(): array
    {
        $excluidos = [TipoInstrumento::EXAME, TipoInstrumento::RECURSO, TipoInstrumento::QUALITATIVA];

        return array_values(array_map(
            fn (TipoInstrumento $t) => ['valor' => $t->value, 'nome' => $t->label()],
            array_filter(TipoInstrumento::cases(), fn (TipoInstrumento $t) => ! in_array($t, $excluidos, true)),
        ));
    }

    /**
     * Turmas e disciplinas que o utilizador pode abrir: as que lecciona hoje e, com acesso amplo, todas as do ano activo.
     *
     * @return list<array{turma_id: int, turma_rotulo: string, disciplinas: list<array{disciplina_id: int, disciplina_nome: string}>}>
     */
    public function opcoes(User $utilizador): array
    {
        $pares = [];

        foreach ($this->docencia->atribuicoesEmVigorDoProfessor($utilizador->id) as $a) {
            $pares[$a['turma_id'].'-'.$a['disciplina_id']] = [$a['turma_id'], $a['disciplina_id']];
        }

        if ($this->acesso->temAcessoAmplo($utilizador)) {
            foreach ($this->paresDoAnoActivo() as [$turmaId, $disciplinaId]) {
                $pares["{$turmaId}-{$disciplinaId}"] = [$turmaId, $disciplinaId];
            }
        }

        $turmas = Turma::query()->with(['nivelAcademico:id,nome', 'turno:id,nome', 'anoLectivo:id,nome'])->findMany(array_unique(array_column($pares, 0)))->keyBy('id');
        $disciplinas = Disciplina::query()->findMany(array_unique(array_column($pares, 1)))->keyBy('id');

        $porTurma = [];
        foreach ($pares as [$turmaId, $disciplinaId]) {
            if (! isset($turmas[$turmaId], $disciplinas[$disciplinaId])) {
                continue;
            }
            $porTurma[$turmaId] ??= ['turma_id' => $turmaId, 'turma_rotulo' => $turmas[$turmaId]->rotulo(completo: true), 'disciplinas' => []];
            $porTurma[$turmaId]['disciplinas'][] = ['disciplina_id' => $disciplinaId, 'disciplina_nome' => (string) $disciplinas[$disciplinaId]->nome];
        }

        usort($porTurma, fn (array $x, array $y) => strnatcasecmp($x['turma_rotulo'], $y['turma_rotulo']));

        return array_values($porTurma);
    }

    /**
     * A mini-pauta de uma turma, disciplina e trimestre (por omissão o trimestre em curso).
     *
     * @return array<string, mixed>
     */
    public function paraTurmaDisciplina(User $utilizador, int $turmaId, int $disciplinaId, ?int $periodoId = null): array
    {
        $turma = Turma::query()->with(['turno:id,nome', 'anoLectivo:id,nome', 'nivelAcademico:id,nome,codigo'])->findOrFail($turmaId);
        $disciplina = Disciplina::query()->findOrFail($disciplinaId);
        $regra = $this->regras->paraTurma($turma);

        $base = ['tipo' => 'quantitativa', 'rotulo_pt' => $regra?->sigla_prova ?? 'PT', 'turma_rotulo' => $turma->rotulo(completo: true), 'disciplina_nome' => (string) $disciplina->nome, 'regra' => null];

        // Sem trimestres definidos no ano lectivo não há onde lançar: o ecrã explica-o em vez de abrir vazio.
        if (! Periodo::query()->where('ano_lectivo_id', $turma->ano_lectivo_id)->where('tipo', TipoPeriodo::TRIMESTRE)->exists()) {
            return [...$base, 'sem_periodos' => true, 'periodos' => [], 'periodo_id' => null, 'instrumentos' => [], 'alunos' => [], 'prazos' => []];
        }

        $qualitativa = $this->qualitativa->paraTurmaDisciplina($turma, $disciplinaId, $periodoId, $this->docentes->idDe($utilizador));
        if ($qualitativa !== null) {
            return [...$base, ...$qualitativa];
        }

        if ($regra === null) {
            return [...$base, 'periodos' => [], 'periodo_id' => null, 'instrumentos' => [], 'alunos' => [], 'prazos' => []];
        }

        $periodos = Periodo::query()->where('ano_lectivo_id', $turma->ano_lectivo_id)->where('tipo', TipoPeriodo::TRIMESTRE)->orderBy('numero')->get();
        $periodo = $periodos->firstWhere('id', $periodoId) ?? $this->periodoEmCurso($periodos);

        if ($periodo === null) {
            return [...$base, 'sem_periodos' => true, 'periodos' => [], 'periodo_id' => null, 'instrumentos' => [], 'alunos' => [], 'prazos' => []];
        }

        $instrumentos = AvaliacaoInstrumento::query()
            ->where('turma_id', $turmaId)->where('disciplina_id', $disciplinaId)->where('periodo_id', $periodo->id)
            ->whereNotIn('tipo', [TipoInstrumento::EXAME->value, TipoInstrumento::RECURSO->value])
            ->orderBy('tipo')->orderBy('id')->get();

        $inscricoes = $this->inscricoes($turmaId, $disciplinaId);
        $notas = AvaliacaoNota::query()
            ->whereIn('instrumento_id', $instrumentos->pluck('id'))->whereIn('inscricao_disciplina_id', $inscricoes->pluck('id'))
            ->get()->groupBy('inscricao_disciplina_id');
        // O exame próprio mostra-se à parte, só no 3.º trimestre, e cria-se na primeira nota (LancarExameAction).
        $comExame = $periodo->numero === 3 && $regra->com_exame;
        $exame = $comExame ? AvaliacaoInstrumento::query()->where('turma_id', $turmaId)->where('disciplina_id', $disciplinaId)->where('tipo', TipoInstrumento::EXAME->value)->first() : null;
        $notasExame = $exame === null ? collect() : AvaliacaoNota::query()->where('instrumento_id', $exame->id)->whereIn('inscricao_disciplina_id', $inscricoes->pluck('id'))->get()->keyBy('inscricao_disciplina_id');
        // O exame de recurso mostra-se à parte, só no 3.º trimestre, e cria-se na primeira nota (LancarRecursoAction).
        $comRecurso = $periodo->numero === 3 && $regra->com_recurso;
        $recurso = $comRecurso ? AvaliacaoInstrumento::query()->where('turma_id', $turmaId)->where('disciplina_id', $disciplinaId)->where('tipo', TipoInstrumento::RECURSO->value)->first() : null;
        $notasRecurso = $recurso === null ? collect() : AvaliacaoNota::query()->where('instrumento_id', $recurso->id)->whereIn('inscricao_disciplina_id', $inscricoes->pluck('id'))->get()->keyBy('inscricao_disciplina_id');
        $elegibilidade = $comRecurso ? $this->elegibilidade->paraTurma($turma, $regra) : [];
        // O recurso só abre depois de aprovada a pauta da 1.ª fase, e só para quem ela mandou ao recurso.
        $recursoAberto = $comRecurso && $this->pautas->definitiva($turmaId, FasePauta::EPOCA_NORMAL);
        $emRecurso = $recursoAberto ? $this->pautas->matriculasEmRecurso($turmaId) : [];
        $resultados = $this->resultados->calcularTurma($turmaId, $disciplinaId, $inscricoes->pluck('id')->all());

        $n = $periodo->numero;
        $tratadas = AvaliacaoNegativaTratada::query()->whereIn('inscricao_disciplina_id', $inscricoes->pluck('id'))->pluck('medida', 'inscricao_disciplina_id');
        $alunos = $inscricoes->map(function (InscricaoDisciplina $i) use ($emRecurso, $recursoAberto, $tratadas, $notas, $resultados, $n, $regra, $notasExame, $notasRecurso, $elegibilidade) {
            $r = $resultados[$i->id];

            return [
                'inscricao_id' => $i->id,
                'nome' => (string) $i->matricula->aluno?->dadosPessoa?->nome_completo,
                'numero_matricula' => $i->matricula->aluno?->numero_matricula,
                'notas' => ($notas[$i->id] ?? collect())->mapWithKeys(fn (AvaliacaoNota $x) => [$x->instrumento_id => [
                    'id' => $x->id, 'valor' => $x->valor, 'estado' => $x->estado->value, 'estado_descricao' => $x->estado_descricao,
                ]])->all(),
                'mac' => $this->apresentar($r->mac[$n], $regra),
                'pt' => $this->apresentar($r->pt[$n], $regra),
                'mt' => $this->apresentar($r->mt[$n], $regra),
                'pendente' => $r->pendentes[$n],
                'medida_negativa' => $tratadas[$i->id] ?? null,
                'negativa' => $this->temNegativa($regra, $r->baseArredondada(), $this->apresentar($r->mt[$n], $regra)),
                'mfd' => $r->mfdArredondada,
                'ne' => isset($notasExame[$i->id]) ? [
                    'id' => $notasExame[$i->id]->id, 'valor' => $notasExame[$i->id]->valor, 'estado' => $notasExame[$i->id]->estado->value, 'estado_descricao' => $notasExame[$i->id]->estado_descricao,
                ] : null,
                'mf' => $r->mfArredondada,
                'recurso_necessario' => $r->recursoNecessario && ($elegibilidade[$i->matricula_id]['elegivel'] ?? false),
                'recurso_pode_lancar' => $recursoAberto && in_array($i->matricula_id, $emRecurso, true),
                'recurso_motivo' => $r->recursoNecessario ? ($elegibilidade[$i->matricula_id]['texto'] ?? null) : null,
                'ner' => isset($notasRecurso[$i->id]) ? [
                    'id' => $notasRecurso[$i->id]->id, 'valor' => $notasRecurso[$i->id]->valor, 'estado' => $notasRecurso[$i->id]->estado->value, 'estado_descricao' => $notasRecurso[$i->id]->estado_descricao,
                ] : null,
                'mfr' => $r->mfrArredondada,
            ];
        })->sortBy('nome', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();

        $docenteId = $this->docentes->idDe($utilizador);
        $prazos = [];
        foreach (TipoInstrumento::cases() as $tipo) {
            $prazos[$tipo->name] = [
                'data_limite' => $this->prazos->dataLimite($periodo->id, $tipo, $turmaId, $disciplinaId, $docenteId)->toDateString(),
                'encerrado' => $this->prazos->prazoEncerrado($periodo->id, $tipo, $turmaId, $disciplinaId, $docenteId),
            ];
        }

        // array_merge, não `+`: o `$base` já traz `regra => null` e o `+` manteria esse valor.
        return array_merge($base, [
            'regra' => ['escala_maxima' => $regra->escala_maxima, 'nota_minima' => (string) $regra->nota_minima, 'casas_decimais' => $regra->casas_decimais, 'consequencia_negativa' => $regra->tratamento_negativas->consequencia(), 'trata_negativas' => $regra->tratamento_negativas->tratamNegativas(), ...$this->definicoes->paraTela()],
            'periodos' => $periodos->map(fn (Periodo $p) => ['id' => $p->id, 'nome' => $p->nome, 'numero' => $p->numero])->all(),
            'periodo_id' => $periodo->id,
            'instrumentos' => $this->instrumentosParaTela($instrumentos, $base['rotulo_pt'].$periodo->numero),
            'alunos' => $alunos,
            'exame' => $comExame ? ['peso_mfd' => $this->semZeros($regra->peso_mfd_exame), 'peso_exame' => $this->semZeros($regra->peso_exame)] : null,
            'recurso' => $comRecurso ? ['aberto' => $recursoAberto, 'peso_mf' => $this->semZeros($regra->peso_mfd_exame), 'peso_recurso' => $this->semZeros($regra->peso_exame)] : null,
            'prazos' => $prazos,
        ]);
    }

    /** 40.00 → 40, 33.50 → 33.5: para mostrar pesos sem zeros a mais. */
    private function semZeros(mixed $valor): string
    {
        return rtrim(rtrim((string) $valor, '0'), '.');
    }

    /** Valor para mostrar: o arredondamento só acontece aqui, na apresentação. */
    private function apresentar(?string $valor, AvaliacaoRegra $regra): ?string
    {
        return $valor === null ? null : $this->calculadora->arredondar($valor, $regra->casas_decimais, $regra->modo_arredondamento);
    }

    private function abaixoDoMinimo(AvaliacaoRegra $regra, ?string $nota): bool
    {
        return $nota !== null && ! $this->calculadora->aprovadoNaDisciplina($nota, (string) $regra->nota_minima);
    }

    /**
     * A negativa que decide: a da nota final da disciplina (MFD, ou MF com exame), quando já existe. Uma MT negativa
     * com a média final positiva não decide nada (fica só o selo informativo). Enquanto não há nota final, a MT negativa
     * é só um risco: avisa-se onde a negativa reprova, e não se pede tratamento onde o aluno tem de transitar.
     */
    private function temNegativa(AvaliacaoRegra $regra, ?string $notaFinal, ?string $mt): bool
    {
        if ($notaFinal !== null) {
            return $this->abaixoDoMinimo($regra, $notaFinal);
        }

        return ! $regra->tratamento_negativas->tratamNegativas() && $this->abaixoDoMinimo($regra, $mt);
    }

    private function periodoEmCurso($periodos): ?Periodo
    {
        $hoje = $this->relogio->hoje()->toDateString();

        return $periodos->first(fn (Periodo $p) => $p->data_inicio->toDateString() <= $hoje && $p->data_fim->toDateString() >= $hoje) ?? $periodos->first();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, InscricaoDisciplina> */
    public function inscricoes(int $turmaId, int $disciplinaId)
    {
        return InscricaoDisciplina::query()
            ->where('estado', EstadoInscricaoDisciplinaEnum::INSCRITA)
            ->whereHas('matricula', fn ($q) => $q->where('turma_id', $turmaId)->where('estado', EstadoMatriculaEnum::ACTIVA))
            ->whereHas('planoCurricularDisciplina', fn ($q) => $q->where('disciplina_id', $disciplinaId))
            ->with('matricula.aluno.dadosPessoa:id,nome_completo,sexo')
            ->get();
    }

    /** @return list<array{0: int, 1: int}> pares (turma, disciplina) com alunos inscritos no ano lectivo activo */
    private function paresDoAnoActivo(): array
    {
        $matriculas = (new Matricula)->getTable();
        $planos = (new PlanoCurricularDisciplina)->getTable();
        $turmas = (new Turma)->getTable();

        return InscricaoDisciplina::query()
            ->join($matriculas, "{$matriculas}.id", '=', 'inscricoes_disciplinas.matricula_id')
            ->join($planos, "{$planos}.id", '=', 'inscricoes_disciplinas.plano_curricular_disciplina_id')
            ->join($turmas, "{$turmas}.id", '=', "{$matriculas}.turma_id")
            ->join('ano_lectivos', 'ano_lectivos.id', '=', "{$turmas}.ano_lectivo_id")
            ->where('ano_lectivos.estado', EstadoAnoLectivo::ATIVO->value)
            ->where("{$matriculas}.estado", EstadoMatriculaEnum::ACTIVA->value)
            ->where('inscricoes_disciplinas.estado', EstadoInscricaoDisciplinaEnum::INSCRITA->value)
            ->distinct()
            ->get(["{$matriculas}.turma_id as turma_id", "{$planos}.disciplina_id as disciplina_id"])
            ->map(fn ($linha) => [(int) $linha->turma_id, (int) $linha->disciplina_id])
            ->all();
    }
}
