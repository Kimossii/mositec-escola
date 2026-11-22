<?php

namespace Modules\Licenciamento\Exceptions;

use RuntimeException;

/** A licença não pode ser instalada. A mensagem é legível pelo administrador (sem detalhes técnicos). */
class LicencaRecusada extends RuntimeException
{
    public static function porque(string $motivo): self
    {
        return new self($motivo);
    }
}
