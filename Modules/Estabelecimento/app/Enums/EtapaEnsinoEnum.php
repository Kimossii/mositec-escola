<?php

namespace Modules\Estabelecimento\Enums;

enum EtapaEnsinoEnum: int
{
    case CRECHE = 1;
    case PRE_ESCOLAR = 2;
    case PRIMARIO = 3;
    case SECUNDARIO = 4;
    case SUPERIOR = 5;

    public function label(): string
    {
        return match ($this) {
            self::CRECHE => 'Creche',
            self::PRE_ESCOLAR => 'Pré-Escolar',
            self::PRIMARIO => 'Ensino Primário',
            self::SECUNDARIO => 'Ensino Secundário',
            self::SUPERIOR => 'Ensino Superior',
        };
    }

    public function exigeCurso(): bool
    {
        return match ($this) {
            self::SECUNDARIO, self::SUPERIOR => true,
            default => false,
        };
    }
}
