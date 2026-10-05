<?php

namespace Modules\Core\Tenancy\Exceptions;

use Modules\Core\Tenancy\Enums\MotivoRecusaRecuperacao;
use RuntimeException;

/** A recuperação do administrador foi recusada por uma regra de domínio; o motivo diz qual. */
class RecuperacaoDeAdministradorRecusada extends RuntimeException
{
    public function __construct(string $message, public readonly MotivoRecusaRecuperacao $motivo)
    {
        parent::__construct($message);
    }
}
