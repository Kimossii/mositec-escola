<?php

namespace Modules\Frequencia\Services;

use Carbon\CarbonInterface;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Frequencia\Models\FrequenciaDefinicoes;

/** Até quantos dias depois da aula o professor ainda corrige a chamada (desenho D9/D13). */
class JanelaDeCorreccaoService
{
    public function __construct(private RelogioDoTenant $relogio)
    {
    }

    public function dias(): int
    {
        return FrequenciaDefinicoes::query()->value('janela_correccao_dias') ?? FrequenciaDefinicoes::JANELA_POR_OMISSAO;
    }

    /** Hoje menos N dias ≤ data da aula (extremos inclusivos, ao dia, no fuso da escola). */
    public function dentroDaJanela(CarbonInterface $dataDaAula): bool
    {
        $limite = $this->relogio->hoje()->subDays($this->dias())->toDateString();

        return $dataDaAula->toDateString() >= $limite;
    }
}
