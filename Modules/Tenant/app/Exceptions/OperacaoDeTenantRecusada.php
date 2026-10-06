<?php

namespace Modules\Tenant\Exceptions;

use RuntimeException;

/**
 * Base das recusas de gestão do tenant (transição inválida, domínio não removível,
 * tenant inexistente). A mensagem é segura para mostrar a quem opera.
 */
abstract class OperacaoDeTenantRecusada extends RuntimeException {}
