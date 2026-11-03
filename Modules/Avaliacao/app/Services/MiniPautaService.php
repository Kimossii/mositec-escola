<?php

namespace Modules\Avaliacao\Services;

use Illuminate\Support\Facades\Gate;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoNota;
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
        private RegraAvaliacaoService $regras,
        private PrazoService $prazos,
        private ResultadoDisciplinaService $resultados,
        private CalculadoraQuantitativa $calculadora,
        private DocenteDoUtilizadorService $docentes,
        private RelogioDoTenant $relogio,
        private MiniPautaQualitativaService $qualitativa,
        private RegraPadraoService $padrao,
    ) {
    }

    /** Docente atribuído à turma e disciplina, ou quem tem permissão de excepção (lançar fora do prazo ou anular). */
    public function podeAceder(User $utilizador, int $turmaId, int $disciplinaId): bool
    {
        return $this->docencia->podeGerir($utilizador->id, $turmaId, $disciplinaId) || $this->temAcessoAmplo($utilizador);
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

        if ($this->temAcessoAmplo($utilizador)) {
            foreach ($this->paresDoAnoActivo() as [$turmaId, $disciplinaId]) {
                $pares["{$turmaId}-{$disciplinaId}"] = [$turmaId, $disciplinaId];
            }
        }

        $turmas = Turma::query()->with(['turno:id,nome', 'anoLectivo:id,nome'])->findMany(array_unique(array_column($pares, 0)))->keyBy('id');
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
        $turma = Turma::query()->with(['turno:id,nome', 'anoLectivo:id,nome', 'nivelAcademico:id,codigo'])->findOrFail($turmaId);
        $disciplina = Disciplina::query()->findOrFail($disciplinaId);
        $regra = $this->regras->paraTurma($turma);

        $base = ['tipo' => 'quantitativa', 'rotulo_pt' => MiniPautaDocumentoService::rotuloDaProva($turma->nivelAcademico?->codigo), 'turma_rotulo' => $turma->rotulo(completo: true), 'disciplina_nome' => (string) $disciplina->nome, 'regra' => null];

        $qualitativa = $this->qualitativa->paraTurmaDisciplina($turma, $disciplinaId, $periodoId, $this->docentes->idDe($utilizador));
        if ($qualitativa !== null) {
            return [...$base, ...$qualitativa];
        }

        if ($regra === null) {
            return $base + ['periodos' => [], 'periodo_id' => null, 'instrumentos' => [], 'alunos' => [], 'prazos' => []];
        }

        $periodos = Periodo::query()->where('ano_lectivo_id', $turma->ano_lectivo_id)->where('tipo', TipoPeriodo::TRIMESTRE)->orderBy('numero')->get();
        $periodo = $periodos->firstWhere('id', $periodoId) ?? $this->periodoEmCurso($periodos);

        if ($periodo === null) {
            return $base + ['periodos' => [], 'periodo_id' => null, 'instrumentos' => [], 'alunos' => [], 'prazos' => []];
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
        $comExame = $periodo->numero === 3 && $this->padrao->temExameProprio((string) $turma->nivelAcademico?->codigo);
        $exame = $comExame ? AvaliacaoInstrumento::query()->where('turma_id', $turmaId)->where('disciplina_id', $disciplinaId)->where('tipo', TipoInstrumento::EXAME->value)->first() : null;
        $notasExame = $exame === null ? collect() : AvaliacaoNota::query()->where('instrumento_id', $exame->id)->whereIn('inscricao_disciplina_id', $inscricoes->pluck('id'))->get()->keyBy('inscricao_disciplina_id');
        // O exame de recurso mostra-se à parte, só no 3.º trimestre, e cria-se na primeira nota (LancarRecursoAction).
        $comRecurso = $periodo->numero === 3 && $this->padrao->temRecurso((string) $turma->nivelAcademico?->codigo);
        $recurso = $comRecurso ? AvaliacaoInstrumento::query()->where('turma_id', $turmaId)->where('disciplina_id', $disciplinaId)->where('tipo', TipoInstrumento::RECURSO->value)->first() : null;
        $notasRecurso = $recurso === null ? collect() : AvaliacaoNota::query()->where('instrumento_id', $recurso->id)->whereIn('inscricao_disciplina_id', $inscricoes->pluck('id'))->get()->keyBy('inscricao_disciplina_id');
        $resultados = $this->resultados->calcularTurma($turmaId, $disciplinaId, $inscricoes->pluck('id')->all());

        $n = $periodo->numero;
        $alunos = $inscricoes->map(function (InscricaoDisciplina $i) use ($notas, $resultados, $n, $regra, $notasExame, $notasRecurso) {
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
                'mfd' => $r->mfdArredondada,
                'ne' => isset($notasExame[$i->id]) ? [
                    'id' => $notasExame[$i->id]->id, 'valor' => $notasExame[$i->id]->valor, 'estado' => $notasExame[$i->id]->estado->value, 'estado_descricao' => $notasExame[$i->id]->estado_descricao,
                ] : null,
                'mf' => $r->mfArredondada,
                'recurso_necessario' => $r->recursoNecessario,
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
                'data_limite' => $this->prazos->dataLimite($regra, $periodo->id, $tipo, $turmaId, $disciplinaId, $docenteId)->toDateString(),
                'encerrado' => $this->prazos->prazoEncerrado($regra, $periodo->id, $tipo, $turmaId, $disciplinaId, $docenteId),
            ];
        }

        return $base + [
            'regra' => ['escala_maxima' => $regra->escala_maxima, 'nota_minima' => (string) $regra->nota_minima, 'casas_decimais' => $regra->casas_decimais],
            'periodos' => $periodos->map(fn (Periodo $p) => ['id' => $p->id, 'nome' => $p->nome, 'numero' => $p->numero])->all(),
            'periodo_id' => $periodo->id,
            'instrumentos' => $instrumentos->map(fn (AvaliacaoInstrumento $i) => [
                'id' => $i->id, 'nome' => $i->nome, 'tipo' => $i->tipo->value, 'tipo_descricao' => $i->tipo_descricao, 'obrigatorio' => $i->obrigatorio,
            ])->all(),
            'alunos' => $alunos,
            'exame' => $comExame ? ['peso_mfd' => $this->semZeros($regra->peso_mfd_exame), 'peso_exame' => $this->semZeros($regra->peso_exame)] : null,
            'recurso' => $comRecurso ? ['peso_mf' => $this->semZeros($regra->peso_mfd_exame), 'peso_recurso' => $this->semZeros($regra->peso_exame)] : null,
            'prazos' => $prazos,
        ];
    }

    /** 40.00 → 40, 33.50 → 33.5: para mostrar pesos sem zeros a mais. */
    private function semZeros(mixed $valor): string
    {
        return rtrim(rtrim((string) $valor, '0'), '.');
    }

    private function temAcessoAmplo(User $utilizador): bool
    {
        $gate = Gate::forUser($utilizador);

        return $gate->allows('nota.lancar-fora-prazo') || $gate->allows('nota.anular');
    }

    /** Valor para mostrar: o arredondamento só acontece aqui, na apresentação. */
    private function apresentar(?string $valor, AvaliacaoRegra $regra): ?string
    {
        return $valor === null ? null : $this->calculadora->arredondar($valor, $regra->casas_decimais, $regra->modo_arredondamento);
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
