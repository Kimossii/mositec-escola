<?php

namespace Modules\Avaliacao\Enums;

enum TipoInstrumento: int
{
    case MAC = 0;
    case PT = 1;
    case EXAME = 2;

    public function label(): string
    {
        return match ($this) {
            self::MAC => 'Avaliação contínua',
            self::PT => 'Prova trimestral',
            self::EXAME => 'Exame próprio',
        };
    }
}
