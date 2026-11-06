<?php

namespace Modules\Avaliacao\DTO;

use Modules\Avaliacao\Enums\TipoInstrumento;

/** O prazo normal de lançamento de uma regra, por trimestre e tipo. `regra_id` é a regra qualitativa quando o tipo é QUALITATIVA. */
readonly class DefinirPrazoDTO
{
    public function __construct(
        public int $regra_id,
        public int $periodo_id,
        public TipoInstrumento $tipo,
        public string $data_limite,
    ) {
    }
}
