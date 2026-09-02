<?php

namespace Modules\AnoLectivo\Enums;

enum TipoPeriodo: int
{
    case TRIMESTRE = 0;
    case SEMESTRE = 1;
    case OUTRO = 2;

    public function label(): string
    {
        return match ($this) {
            self::TRIMESTRE => 'Trimestre',
            self::SEMESTRE => 'Semestre',
            self::OUTRO => 'Outro',
        };
    }
}
