<?php

namespace Modules\Core\Comunicacao\Email;

enum EncriptacaoSmtp: int
{
    case NENHUMA = 0;
    case STARTTLS = 1;
    case SSL = 2;

    public function label(): string
    {
        return match ($this) {
            self::NENHUMA => 'Nenhuma',
            self::STARTTLS => 'STARTTLS',
            self::SSL => 'SSL/TLS',
        };
    }
}
