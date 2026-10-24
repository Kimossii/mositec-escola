<?php

namespace Modules\Financeiro\Enums;

enum OrigemCobranca: int
{
    case MANUAL = 1;
    case OBRIGATORIO = 2;

    public function label(): string
    {
        return match ($this) {
            self::MANUAL => 'Manual',
            self::OBRIGATORIO => 'Obrigatório',
        };
    }
}
