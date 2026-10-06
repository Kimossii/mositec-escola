<?php

namespace Modules\Core\Tenancy\Exceptions;

use LogicException;

class AlteracaoDeTenantProibida extends LogicException
{
    public function __construct(string $model)
    {
        parent::__construct("Um registo de {$model} só pode ser alterado ou apagado dentro do seu próprio tenant, e o tenant_id não pode ser definido à mão nem alterado.");
    }
}
