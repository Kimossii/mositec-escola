<?php

namespace Modules\Infraestrutura\Enums;

enum EstadoSala: int
{
    case ATIVA = 0;
    case MANUTENCAO = 1;
    case INATIVA = 2;

    public function label(): string
    {
        return match ($this) {
            self::ATIVA => 'Ativa',
            self::MANUTENCAO => 'Em Manutenção',
            self::INATIVA => 'Inativa',
        };
    }
}
