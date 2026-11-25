<?php

namespace Modules\Frequencia\Enums;

enum EventoFrequencia: int
{
    case MARCACAO = 1;
    case ALTERACAO = 2;
    case JUSTIFICACAO = 3;
    case REMOCAO_JUSTIFICACAO = 4;

    public function label(): string
    {
        return match ($this) {
            self::MARCACAO => 'Marcação',
            self::ALTERACAO => 'Alteração',
            self::JUSTIFICACAO => 'Justificação',
            self::REMOCAO_JUSTIFICACAO => 'Remoção da justificação',
        };
    }
}
