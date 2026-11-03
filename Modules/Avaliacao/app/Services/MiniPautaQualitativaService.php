<?php

namespace Modules\Avaliacao\Services;

use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Illuminate\Support\Collection;
use Modules\Avaliacao\Models\AvaliacaoCompetencia;
use Modules\Avaliacao\Models\AvaliacaoCompetenciaRegisto;
use Modules\Avaliacao\Models\AvaliacaoQualitativa;
use Modules\Avaliacao\Models\AvaliacaoRegraQualitativa;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Matricula\Enums\EstadoInscricaoDisciplinaEnum;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Turma\Models\Turma;

/** Mini-pauta de uma classe qualitativa: por criança, o nível da escala e a observação do trimestre. Só consulta. */
class MiniPautaQualitativaService
{
    public function __construct(private RelogioDoTenant $relogio, private PrazoService $prazos)
    {
    }

    /** @return array<string, mixed>|null `null` quando a classe não é qualitativa (ou ainda não tem escala escolhida) */
    public function paraTurmaDisciplina(Turma $turma, int $disciplinaId, ?int $periodoId, ?int $docenteId = null): ?array
    {
        $regra = AvaliacaoRegraQualitativa::query()->where('ano_lectivo_id', $turma->ano_lectivo_id)
            ->where('nivel_academico_id', $turma->nivel_academico_id)->with('escala.niveis')->first();

        if ($regra === null) {
            return null;
        }

        $periodos = Periodo::query()->where('ano_lectivo_id', $turma->ano_lectivo_id)->where('tipo', TipoPeriodo::TRIMESTRE)->orderBy('numero')->get();
        $hoje = $this->relogio->hoje()->toDateString();
        $periodo = $periodos->firstWhere('id', $periodoId)
            ?? $periodos->first(fn (Periodo $p) => $p->data_inicio->toDateString() <= $hoje && $p->data_fim->toDateString() >= $hoje)
            ?? $periodos->first();

        $inscricoes = InscricaoDisciplina::query()
            ->where('estado', EstadoInscricaoDisciplinaEnum::INSCRITA)
            ->whereHas('matricula', fn ($q) => $q->where('turma_id', $turma->id)->where('estado', EstadoMatriculaEnum::ACTIVA))
            ->whereHas('planoCurricularDisciplina', fn ($q) => $q->where('disciplina_id', $disciplinaId))
            ->with('matricula.aluno.dadosPessoa:id,nome_completo')->get();

        $niveis = $regra->escala->niveis->keyBy('id');
        $competencias = AvaliacaoCompetencia::query()->where('disciplina_id', $disciplinaId)->where('ativa', true)
            ->where(fn ($q) => $q->whereNull('nivel_academico_id')->orWhere('nivel_academico_id', $turma->nivel_academico_id))
            ->orderBy('ordem')->orderBy('id')->get();
        $registos = $periodo === null || $competencias->isEmpty() ? collect() : AvaliacaoCompetenciaRegisto::query()
            ->whereIn('inscricao_disciplina_id', $inscricoes->pluck('id'))->where('periodo_id', $periodo->id)->whereIn('competencia_id', $competencias->pluck('id'))
            ->get()->groupBy('inscricao_disciplina_id');

        $avaliacoes = $periodo === null ? collect() : AvaliacaoQualitativa::query()
            ->whereIn('inscricao_disciplina_id', $inscricoes->pluck('id'))->where('periodo_id', $periodo->id)->get()->keyBy('inscricao_disciplina_id');

        return [
            'tipo' => 'qualitativa',
            'regra' => ['qualitativa' => true],
            'periodos' => $periodos->map(fn (Periodo $p) => ['id' => $p->id, 'nome' => $p->nome, 'numero' => $p->numero])->all(),
            'periodo_id' => $periodo?->id,
            'escala' => [
                'nome' => $regra->escala->nome,
                'niveis' => $regra->escala->niveis->map(fn ($n) => ['id' => $n->id, 'codigo' => $n->codigo, 'designacao' => $n->designacao])->all(),
            ],
            'competencias' => $competencias->map(fn ($c) => ['id' => $c->id, 'descricao' => $c->descricao])->all(),
            'alunos' => $inscricoes->map(fn (InscricaoDisciplina $i) => [
                'inscricao_id' => $i->id,
                'nome' => (string) $i->matricula->aluno?->dadosPessoa?->nome_completo,
                'numero_matricula' => $i->matricula->aluno?->numero_matricula,
                'nivel_id' => $avaliacoes[$i->id]->escala_nivel_id ?? null,
                'observacao' => $avaliacoes[$i->id]->observacao ?? null,
                'competencias' => ($registos[$i->id] ?? collect())->pluck('escala_nivel_id', 'competencia_id')->all(),
                'sugestao' => $this->sugestao($registos[$i->id] ?? collect(), $niveis, $competencias->count()),
            ])->sortBy('nome', SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
            'prazo' => $periodo === null ? null : [
                'data_limite' => $this->prazos->dataLimite($regra, $periodo->id, TipoInstrumento::QUALITATIVA, $turma->id, $disciplinaId, $docenteId)->toDateString(),
                'encerrado' => $this->prazos->prazoEncerrado($regra, $periodo->id, TipoInstrumento::QUALITATIVA, $turma->id, $disciplinaId, $docenteId),
            ],
        ];
    }

    /**
     * Sugestão do nível da área: o nível mais frequente nas competências já avaliadas (sem médias nem números). Em empate
     * fica o mais baixo, a opção prudente. É só uma sugestão: quem decide o nível da área é o educador.
     *
     * @return array{nivel_id: int, designacao: string, avaliadas: int, total: int}|null
     */
    private function sugestao(Collection $registos, Collection $niveis, int $totalDeCompetencias): ?array
    {
        if ($registos->isEmpty()) {
            return null;
        }

        $escolhido = $registos->countBy('escala_nivel_id')
            ->map(fn (int $vezes, int $nivelId) => ['vezes' => $vezes, 'ordem' => $niveis[$nivelId]->ordem, 'id' => $nivelId])
            ->sort(fn (array $a, array $b) => [$b['vezes'], $a['ordem']] <=> [$a['vezes'], $b['ordem']])
            ->first();

        return ['nivel_id' => $escolhido['id'], 'designacao' => $niveis[$escolhido['id']]->designacao, 'avaliadas' => $registos->count(), 'total' => $totalDeCompetencias];
    }
}
