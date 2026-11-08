<?php

namespace Modules\Avaliacao\Services;

use Modules\Avaliacao\Actions\GarantirRegrasDoAnoAction;
use Modules\Avaliacao\Models\AvaliacaoRegra;
use Modules\Avaliacao\Models\AvaliacaoRegraNivel;
use Modules\Avaliacao\Models\AvaliacaoRegraQualitativa;
use Modules\Turma\Models\Turma;

/** A regra de avaliação de uma turma: a regra do ano lectivo a que a classe da turma está ligada. */
class RegraAvaliacaoService
{
    public function __construct(private GarantirRegrasDoAnoAction $garantir)
    {
    }

    /**
     * A regra numérica da classe da turma. Um ano lectivo sem regras arranca com as por defeito (ou as do ano anterior);
     * é `null` para as classes sem regra numérica (Creche, Pré-Escolar, ou uma classe que a escola ainda não ligou).
     */
    public function paraTurma(Turma $turma): ?AvaliacaoRegra
    {
        return $this->ligacao($turma)?->regra;
    }

    /** A regra qualitativa da classe da turma (Creche, Pré-Escolar), ou `null`. */
    public function qualitativaParaTurma(Turma $turma): ?AvaliacaoRegraQualitativa
    {
        return $this->ligacao($turma)?->regraQualitativa()->with('escala')->first();
    }

    private function ligacao(Turma $turma): ?AvaliacaoRegraNivel
    {
        $this->garantir->executar($turma->ano_lectivo_id);

        return AvaliacaoRegraNivel::query()
            ->where('ano_lectivo_id', $turma->ano_lectivo_id)->where('nivel_academico_id', $turma->nivel_academico_id)
            ->with('regra')->first();
    }
}
