<?php

namespace Modules\Core\Tenancy\Exceptions;

use RuntimeException;

class TenantNaoResolvido extends RuntimeException
{
    public function __construct(?string $detalhe = null)
    {
        parent::__construct(
            'Operação sobre dados de tenant sem tenant resolvido. Fora de um pedido HTTP, use TenantContext::executarComo().'
            . ($detalhe !== null ? ' ' . $detalhe : ''),
        );
    }
}
