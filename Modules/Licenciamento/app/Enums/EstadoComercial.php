<?php

namespace Modules\Licenciamento\Enums;

/** Estado comercial de uma licença emitida (não é o estado técnico, que decorre das datas). */
enum EstadoComercial: int
{
    case ACTIVA = 1;
    case SUSPENSA = 2;
    case EXPIRADA = 3;
    case CANCELADA = 4;
    /** Trocada por uma licença nova (renovação ou extensão): não é expiração por data. */
    case SUBSTITUIDA = 5;

    public function label(): string
    {
        return match ($this) {
            self::ACTIVA => 'Activa',
            self::SUSPENSA => 'Suspensa',
            self::EXPIRADA => 'Expirada',
            self::CANCELADA => 'Cancelada',
            self::SUBSTITUIDA => 'Substituída',
        };
    }
}
