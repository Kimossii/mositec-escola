<?php

namespace Modules\Core\Comunicacao\Email;

enum EstadoEnvioEmail: int
{
    case ENVIADO = 0;
    case FALHOU = 1;
    case DESCARTADO_LIMITE = 2;
    case DESCARTADO_CONTRATO = 3;
    case DESCARTADO_QUOTA = 4;

    public function label(): string
    {
        return match ($this) {
            self::ENVIADO => 'Enviado',
            self::FALHOU => 'Falhou',
            self::DESCARTADO_LIMITE => 'Descartado (limite por hora)',
            self::DESCARTADO_CONTRATO => 'Descartado (módulo não contratado)',
            self::DESCARTADO_QUOTA => 'Descartado (quota mensal esgotada)',
        };
    }
}
