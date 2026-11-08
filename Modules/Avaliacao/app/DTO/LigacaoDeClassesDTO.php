<?php

namespace Modules\Avaliacao\DTO;

/** As classes a ligar a uma regra de um ano lectivo (numérica ou qualitativa). */
readonly class LigacaoDeClassesDTO
{
    /** @param  list<int>  $nivel_ids */
    public function __construct(
        public int $ano_lectivo_id,
        public array $nivel_ids,
        public bool $qualitativa,
        public int $regra_id,
    ) {
    }
}
