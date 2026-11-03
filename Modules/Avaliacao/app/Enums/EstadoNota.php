<?php

namespace Modules\Avaliacao\Enums;

enum EstadoNota: int
{
    case LANCADA = 0;
    case ANULADA = 1;
    case ANULADA_CONTA_ZERO = 2;

    public function label(): string
    {
        return match ($this) {
            self::LANCADA => 'Lançada',
            self::ANULADA => 'Anulada',
            self::ANULADA_CONTA_ZERO => 'Anulada (conta como zero)',
        };
    }
}
