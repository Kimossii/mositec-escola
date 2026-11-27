<?php

namespace Modules\Core\Comunicacao\Email;

enum EstadoConfiguracaoEmail: int
{
    case POR_VERIFICAR = 0;
    case VERIFICADO = 1;
    case COM_FALHA = 2;

    public function label(): string
    {
        return match ($this) {
            self::POR_VERIFICAR => 'Por verificar',
            self::VERIFICADO => 'Verificado',
            self::COM_FALHA => 'Com falha',
        };
    }
}
