<?php

namespace Modules\Avaliacao\DTO;

readonly class DefinicoesDeAvaliacaoDTO
{
    public function __construct(
        /** quantas avaliações contínuas por trimestre, no máximo */
        public int $max_avaliacoes = 10,
        /** quantas avaliações a escola espera, no mínimo, por trimestre (aviso; não entra no cálculo) */
        public int $min_avaliacoes = 5,
    ) {
    }
}
