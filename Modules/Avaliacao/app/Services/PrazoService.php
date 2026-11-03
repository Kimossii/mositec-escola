<?php

namespace Modules\Avaliacao\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoPrazo;
use Modules\Avaliacao\Models\AvaliacaoPrazoExcepcao;
use Modules\Avaliacao\Models\AvaliacaoRegra;
use Modules\Avaliacao\Models\AvaliacaoRegraQualitativa;
use Modules\Estabelecimento\Services\RelogioDoTenant;

/**
 * Resolve o prazo de lançamento. Ordem: excepção mais específica > prazo da regra por (trimestre, tipo) >
 * `periodos.data_fim`. Numa excepção, o docente pesa mais que a disciplina, e esta mais que a turma; o dia do
 * prazo ainda é válido (extremo inclusivo).
 */
class PrazoService
{
    public function __construct(private RelogioDoTenant $relogio)
    {
    }

    public function dataLimite(AvaliacaoRegra|AvaliacaoRegraQualitativa $regra, int $periodoId, TipoInstrumento $tipo, int $turmaId, int $disciplinaId, ?int $docenteId = null): CarbonImmutable
    {
        $prazo = AvaliacaoPrazo::query()
            ->where($regra instanceof AvaliacaoRegraQualitativa ? 'regra_qualitativa_id' : 'regra_id', $regra->id)
            ->where('periodo_id', $periodoId)
            ->where('tipo', $tipo->value)
            ->first();

        if ($prazo === null) {
            return CarbonImmutable::parse(Periodo::query()->findOrFail($periodoId)->data_fim);
        }

        $excepcao = $prazo->excepcoes()
            ->where(fn ($q) => $q->whereNull('turma_id')->orWhere('turma_id', $turmaId))
            ->where(fn ($q) => $q->whereNull('disciplina_id')->orWhere('disciplina_id', $disciplinaId))
            ->where(fn ($q) => $docenteId === null ? $q->whereNull('docente_id') : $q->whereNull('docente_id')->orWhere('docente_id', $docenteId))
            ->get()
            ->sortByDesc(fn (AvaliacaoPrazoExcepcao $e) => [$this->especificidade($e), $e->data_limite->timestamp])
            ->first();

        return CarbonImmutable::parse($excepcao?->data_limite ?? $prazo->data_limite);
    }

    public function prazoEncerrado(AvaliacaoRegra|AvaliacaoRegraQualitativa $regra, int $periodoId, TipoInstrumento $tipo, int $turmaId, int $disciplinaId, ?int $docenteId = null, ?CarbonInterface $hoje = null): bool
    {
        $hoje = CarbonImmutable::parse(($hoje ?? $this->relogio->hoje())->toDateString());

        return $hoje->greaterThan($this->dataLimite($regra, $periodoId, $tipo, $turmaId, $disciplinaId, $docenteId)->startOfDay());
    }

    /** O prazo do instrumento (para a sua turma, disciplina e trimestre) já passou? */
    public function instrumentoEncerrado(AvaliacaoInstrumento $instrumento, AvaliacaoRegra $regra, ?int $docenteId = null, ?CarbonInterface $hoje = null): bool
    {
        return $this->prazoEncerrado($regra, $instrumento->periodo_id, $instrumento->tipo, $instrumento->turma_id, $instrumento->disciplina_id, $docenteId, $hoje);
    }

    private function especificidade(AvaliacaoPrazoExcepcao $e): int
    {
        return ($e->docente_id !== null ? 4 : 0) + ($e->disciplina_id !== null ? 2 : 0) + ($e->turma_id !== null ? 1 : 0);
    }
}
