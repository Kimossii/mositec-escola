<?php

namespace Modules\PlanoCurricular\Enums;

enum TipoDisciplinaPlano: int
{
    case NORMAL = 0;
    case ESTAGIO = 1;
    case OPTATIVA = 2;
    case PROJETO = 3;

    public function label(): string
    {
        return match ($this) {
            self::NORMAL => 'Normal',
            self::ESTAGIO => 'Estágio',
            self::OPTATIVA => 'Optativa',
            self::PROJETO => 'Projecto',
        };
    }
}
