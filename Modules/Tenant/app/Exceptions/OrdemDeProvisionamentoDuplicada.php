<?php

namespace Modules\Tenant\Exceptions;

use LogicException;

class OrdemDeProvisionamentoDuplicada extends LogicException
{
    /** @param array<int, string> $classes */
    public function __construct(int $ordem, array $classes)
    {
        parent::__construct("Mais de um provisionador com ordem {$ordem}: " . implode(', ', $classes) . '. A ordem tem de ser única.');
    }
}
