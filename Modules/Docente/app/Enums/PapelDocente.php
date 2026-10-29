<?php

namespace Modules\Docente\Enums;

enum PapelDocente: int
{
    case TITULAR = 0;
    case SUBSTITUTO = 1;

    public function label(): string
    {
        return match ($this) {
            self::TITULAR => 'Titular',
            self::SUBSTITUTO => 'Substituto',
        };
    }
}
