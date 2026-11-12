<?php

namespace Modules\Avaliacao\Services;

use Modules\Avaliacao\Models\AvaliacaoCompetenciaRegisto;
use Modules\Avaliacao\Models\AvaliacaoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoPauta;
use Modules\Avaliacao\Models\AvaliacaoProgressao;
use Modules\Avaliacao\Models\AvaliacaoQualitativa;
use Modules\Avaliacao\Models\AvaliacaoRegra;
use Modules\Avaliacao\Models\AvaliacaoRegraQualitativa;
use Modules\Matricula\Models\InscricaoDisciplina;
use Modules\Matricula\Models\Matricula;
use Modules\Turma\Models\Turma;

/**
 * Uma regra está «em uso» quando já há avaliação feita nas turmas das classes que a seguem, no ano dela: avaliações
 * criadas, pautas, níveis qualitativos, competências ou progressões. Uma regra sem nada disto não tem histórico e pode
 * eliminar-se, mesmo com classes ligadas. Só leitura.
 */
class RegraEmUsoService
{
    public function emUso(AvaliacaoRegra|AvaliacaoRegraQualitativa $regra): bool
    {
        $turmas = Turma::query()->where('ano_lectivo_id', $regra->ano_lectivo_id)
            ->whereIn('nivel_academico_id', $regra->niveis()->pluck('nivel_academico_id'))->pluck('id');

        if ($turmas->isEmpty()) {
            return false;
        }

        $matriculas = Matricula::query()->whereIn('turma_id', $turmas)->select('id');
        $inscricoes = InscricaoDisciplina::query()->whereIn('matricula_id', $matriculas)->select('id');

        return AvaliacaoInstrumento::query()->whereIn('turma_id', $turmas)->exists()
            || AvaliacaoPauta::query()->whereIn('turma_id', $turmas)->exists()
            || AvaliacaoQualitativa::query()->whereIn('inscricao_disciplina_id', $inscricoes)->exists()
            || AvaliacaoCompetenciaRegisto::query()->whereIn('inscricao_disciplina_id', $inscricoes)->exists()
            || AvaliacaoProgressao::query()->whereIn('matricula_id', $matriculas)->exists();
    }
}
