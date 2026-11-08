<?php

namespace Modules\Avaliacao\Services;

use Modules\Avaliacao\Models\AvaliacaoDefinicoes;

/** As definições da avaliação que valem para toda a escola. Sem nada guardado, valem os valores por defeito. Só leitura. */
class DefinicoesDeAvaliacaoService
{
    public const MAXIMO_POR_DEFEITO = 10;

    public const MINIMO_POR_DEFEITO = 5;

    /** Quantas avaliações contínuas um trimestre admite. */
    public function maximo(): int
    {
        return AvaliacaoDefinicoes::query()->value('max_avaliacoes') ?? self::MAXIMO_POR_DEFEITO;
    }

    /** Quantas avaliações contínuas a escola espera, no mínimo, por trimestre (um aviso ao professor; não entra no cálculo). */
    public function minimo(): int
    {
        return AvaliacaoDefinicoes::query()->value('min_avaliacoes') ?? self::MINIMO_POR_DEFEITO;
    }

    /** @return array{max_avaliacoes: int, min_avaliacoes: int} */
    public function paraTela(): array
    {
        return ['max_avaliacoes' => $this->maximo(), 'min_avaliacoes' => $this->minimo()];
    }
}
