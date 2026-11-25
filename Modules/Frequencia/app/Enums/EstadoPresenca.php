<?php

namespace Modules\Frequencia\Enums;

enum EstadoPresenca: int
{
    case PRESENTE = 1;
    case FALTA = 2;
    case ATRASO = 3;

    public function label(): string
    {
        return match ($this) {
            self::PRESENTE => 'Presente',
            self::FALTA => 'Falta',
            self::ATRASO => 'Atraso',
        };
    }
}
