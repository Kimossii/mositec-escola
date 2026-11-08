<?php

namespace Modules\Avaliacao\DTO;

readonly class LancarNotasEmLoteDTO
{
    /**
     * @param  list<LancarNotaDTO>  $notas  avaliações da mini-pauta
     * @param  list<LancarExameDTO>  $exames
     * @param  list<LancarExameDTO>  $recursos
     */
    public function __construct(
        public array $notas = [],
        public array $exames = [],
        public array $recursos = [],
    ) {
    }
}
