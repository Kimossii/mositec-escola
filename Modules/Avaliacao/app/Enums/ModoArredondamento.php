<?php

namespace Modules\Avaliacao\Enums;

enum ModoArredondamento: int
{
    case HALF_UP = 0;
    case HALF_EVEN = 1;
    case TRUNCAR = 2;

    public function label(): string
    {
        return match ($this) {
            self::HALF_UP => 'Arredondar para cima a partir de metade',
            self::HALF_EVEN => 'Arredondar ao par mais próximo',
            self::TRUNCAR => 'Truncar',
        };
    }
}
