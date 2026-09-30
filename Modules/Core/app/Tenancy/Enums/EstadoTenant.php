<?php

namespace Modules\Core\Tenancy\Enums;

enum EstadoTenant: int
{
    case ACTIVO = 1;
    case SUSPENSO = 2;
    case ENCERRADO = 3;

    public function label(): string
    {
        return match ($this) {
            self::ACTIVO => 'Activo',
            self::SUSPENSO => 'Suspenso',
            self::ENCERRADO => 'Encerrado',
        };
    }
}
