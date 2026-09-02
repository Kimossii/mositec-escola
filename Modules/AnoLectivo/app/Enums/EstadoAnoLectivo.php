<?php

namespace Modules\AnoLectivo\Enums;

enum EstadoAnoLectivo: int
{
    case PLANEADO = 0;
    case ATIVO = 1;
    case ENCERRADO = 2;

    public function label(): string
    {
        return match ($this) {
            self::PLANEADO => 'Planeado',
            self::ATIVO => 'Activo',
            self::ENCERRADO => 'Encerrado',
        };
    }
}
