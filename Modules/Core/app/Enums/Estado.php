<?php

namespace Modules\Core\Enums;

enum Estado: int
{
    case INATIVO = 0;
    case ATIVO = 1;

    public function label(): string
    {
        return match ($this) {
            self::INATIVO => 'Inativo',
            self::ATIVO => 'Ativo',
        };
    }
}
