<?php

namespace Modules\Tenant\Exceptions;

use RuntimeException;

/**
 * O provisioning não tem tudo o que um tenant utilizável exige (provisionador em falta,
 * módulo desactivado, administrador não criado). Nada fica criado.
 */
class ProvisionamentoIncompleto extends RuntimeException
{
}
