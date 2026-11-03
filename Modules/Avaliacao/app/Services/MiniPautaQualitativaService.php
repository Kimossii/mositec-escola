<?php

namespace Modules\Avaliacao\Services;

use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\Periodo;
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
    public function __construct(private RelogioDoTenant $relogio)
    {
    }

    /** @return array<string, mixed>|null `null` quando a classe não é qualitativa (ou ainda não tem escala escolhida) */
    public function paraTurmaDisciplina(Turma $turma, int $disciplinaId, ?int $periodoId): ?array
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
            'alunos' => $inscricoes->map(fn (InscricaoDisciplina $i) => [
                'inscricao_id' => $i->id,
                'nome' => (string) $i->matricula->aluno?->dadosPessoa?->nome_completo,
                'numero_matricula' => $i->matricula->aluno?->numero_matricula,
                'nivel_id' => $avaliacoes[$i->id]->escala_nivel_id ?? null,
                'observacao' => $avaliacoes[$i->id]->observacao ?? null,
            ])->sortBy('nome', SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
            'prazo' => $periodo === null ? null : ['data_limite' => $periodo->data_fim->toDateString(), 'encerrado' => $hoje > $periodo->data_fim->toDateString()],
        ];
    }
}
