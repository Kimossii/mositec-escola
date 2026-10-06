<?php

namespace Modules\Tenant\Exceptions;

use RuntimeException;

class InstalacaoUnicaInvalida extends RuntimeException
{
    public function __construct(int $quantidade)
    {
        parent::__construct("TENANCY_MODO=unico exige exactamente 1 tenant nesta instalação; existem {$quantidade}.");
    }
}
