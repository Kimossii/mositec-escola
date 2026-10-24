<?php

namespace Modules\Financeiro\Enums;

/** Estado registado de um acordo. «Cumprido» não se grava: deriva de não haver nada por pagar nas propinas incluídas. */
enum EstadoAcordo: int
{
    case ACTIVO = 1;
    case CANCELADO = 2;
    case QUEBRADO = 3;

    public function label(): string
    {
        return match ($this) {
            self::ACTIVO => 'Activo',
            self::CANCELADO => 'Cancelado',
            self::QUEBRADO => 'Quebrado',
        };
    }
}
