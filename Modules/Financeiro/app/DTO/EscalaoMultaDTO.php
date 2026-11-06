<?php

namespace Modules\Financeiro\DTO;

use Modules\Financeiro\Enums\TipoMulta;

class EscalaoMultaDTO
{
    public function __construct(
        public readonly int $ordem,
        public readonly int $dias_atraso,
        public readonly TipoMulta $tipo,
        public readonly int $valor,
    ) {
    }
}
