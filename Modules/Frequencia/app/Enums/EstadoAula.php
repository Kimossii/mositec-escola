<?php

namespace Modules\Frequencia\Enums;

enum EstadoAula: int
{
    case REALIZADA = 1;
    case NAO_LECCIONADA = 2;

    public function label(): string
    {
        return match ($this) {
            self::REALIZADA => 'Realizada',
            self::NAO_LECCIONADA => 'Não leccionada',
        };
    }
}
