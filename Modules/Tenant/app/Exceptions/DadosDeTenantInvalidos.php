<?php

namespace Modules\Tenant\Exceptions;

use RuntimeException;

/**
 * Entradas recusadas antes de criar seja o que for. Os erros vêm por campo
 * (nome, admin_nome, admin_email, dominio, codigo, modo).
 */
class DadosDeTenantInvalidos extends RuntimeException
{
    /** @param array<string, string> $erros */
    public function __construct(public readonly array $erros)
    {
        parent::__construct(implode(' ', $erros));
    }
}
