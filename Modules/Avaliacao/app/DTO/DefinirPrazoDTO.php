<?php

namespace Modules\Avaliacao\DTO;

use Modules\Avaliacao\Enums\TipoInstrumento;

/** O prazo normal de lançamento da escola, por trimestre e tipo. */
readonly class DefinirPrazoDTO
{
    public function __construct(
        public int $periodo_id,
        public TipoInstrumento $tipo,
        public string $data_limite,
    ) {
    }
}
