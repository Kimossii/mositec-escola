<?php

namespace Modules\PlanoCurricular\Enums;

enum ComponentePlanoCurricular: int
{
    case GERAL = 1;
    case TECNICA = 2;
    case PRATICA = 3;

    public function label(): string
    {
        return match ($this) {
            self::GERAL => 'Geral',
            self::TECNICA => 'Técnica',
            self::PRATICA => 'Prática',
        };
    }
}
