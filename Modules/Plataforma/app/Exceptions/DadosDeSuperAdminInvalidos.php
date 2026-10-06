<?php

namespace Modules\Plataforma\Exceptions;

use RuntimeException;

/**
 * Entradas recusadas antes de criar um super admin. Os erros vêm por campo (nome, email).
 */
class DadosDeSuperAdminInvalidos extends RuntimeException
{
    /** @param array<string, string> $erros */
    public function __construct(public readonly array $erros)
    {
        parent::__construct(implode(' ', $erros));
    }
}
