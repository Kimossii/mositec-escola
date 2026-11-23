<?php

namespace Modules\Frequencia\Enums;

enum TipoAula: int
{
    case TEMPO = 1;
    case DIA = 2;

    public function label(): string
    {
        return match ($this) {
            self::TEMPO => 'Tempo',
            self::DIA => 'Dia',
        };
    }
}
