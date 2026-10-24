<?php

namespace Modules\Financeiro\DTO;

use Modules\Financeiro\Enums\TipoMulta;

class EscalaoMultaDTO
{
    public function __construct(
        public int $ordem,
        public int $dias_atraso,
        public TipoMulta $tipo,
        public int $valor,
    ) {
    }
}
