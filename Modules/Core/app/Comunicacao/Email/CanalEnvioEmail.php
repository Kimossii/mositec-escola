<?php

namespace Modules\Core\Comunicacao\Email;

enum CanalEnvioEmail: int
{
    case CENTRAL = 0;
    case PROPRIO = 1;

    public function label(): string
    {
        return match ($this) {
            self::CENTRAL => 'Central',
            self::PROPRIO => 'Infra própria da escola',
        };
    }
}
