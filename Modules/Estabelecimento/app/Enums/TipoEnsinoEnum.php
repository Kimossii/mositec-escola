<?php

namespace Modules\Estabelecimento\Enums;

enum TipoEnsinoEnum: int
{
    case GERAL = 1;
    case TECNICO = 2;
    case UNIVERSITARIO = 3;

    public function label(): string
    {
        return match ($this) {
            self::GERAL => 'Ensino Geral',
            self::TECNICO => 'Ensino Técnico',
            self::UNIVERSITARIO => 'Ensino Universitário',
        };
    }
}
