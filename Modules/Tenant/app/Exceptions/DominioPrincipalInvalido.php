<?php

namespace Modules\Tenant\Exceptions;

/** O domínio pedido não pode passar a principal (não é da escola ou já é o principal). */
class DominioPrincipalInvalido extends OperacaoDeTenantRecusada {}
