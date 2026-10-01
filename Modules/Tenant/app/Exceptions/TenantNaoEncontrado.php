<?php

namespace Modules\Tenant\Exceptions;

class TenantNaoEncontrado extends OperacaoDeTenantRecusada
{
    public function __construct(string $codigo)
    {
        parent::__construct("Não existe nenhum tenant com o código '{$codigo}'.");
    }
}
