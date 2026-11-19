<?php

namespace Modules\Avaliacao\Enums;

/** O que aconteceu a uma pauta: foi aprovada (passou a definitiva) ou foi reaberta para alterações. */
enum EventoDaPauta: int
{
    case APROVADA = 0;
    case REABERTA = 1;

    public function label(): string
    {
        return match ($this) {
            self::APROVADA => 'Aprovada',
            self::REABERTA => 'Reaberta',
        };
    }
}
