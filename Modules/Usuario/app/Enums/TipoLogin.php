<?php

namespace Modules\Usuario\Enums;

enum TipoLogin: int
{
    case EMAIL = 0;
    case MATRICULA = 1;

    public static function fromLabel(string $label): self
    {
        return match ($label) {
            'email' => self::EMAIL,
            'matricula' => self::MATRICULA,
        };
    }
}
