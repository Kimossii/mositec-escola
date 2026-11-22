<?php

namespace Modules\Licenciamento\Enums;

enum Modalidade: int
{
    case ONLINE = 1;
    case LOCAL = 2;
    case HIBRIDO = 3;

    public function label(): string
    {
        return match ($this) {
            self::ONLINE => 'Online',
            self::LOCAL => 'Local',
            self::HIBRIDO => 'Híbrido',
        };
    }

    public function slug(): string
    {
        return match ($this) {
            self::ONLINE => 'online',
            self::LOCAL => 'local',
            self::HIBRIDO => 'hibrido',
        };
    }
}
