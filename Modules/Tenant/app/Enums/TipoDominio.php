<?php

namespace Modules\Tenant\Enums;

enum TipoDominio: int
{
    case SUBDOMINIO = 0;
    case PERSONALIZADO = 1;

    public function label(): string
    {
        return match ($this) {
            self::SUBDOMINIO => 'Subdomínio',
            self::PERSONALIZADO => 'Domínio personalizado',
        };
    }
}
