<?php

namespace Modules\Avaliacao\Enums;

/** Uma pauta só existe em base depois de aprovada. Antes disso é provisória e calcula-se em leitura. */
enum EstadoPauta: int
{
    case DEFINITIVA = 0;
    case REABERTA = 1;

    public function label(): string
    {
        return match ($this) {
            self::DEFINITIVA => 'Definitiva',
            self::REABERTA => 'Reaberta',
        };
    }
}
