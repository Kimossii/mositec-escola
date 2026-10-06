<?php

namespace Modules\Plataforma\Exceptions;

use RuntimeException;

/** O super admin pedido não existe ou está desactivado: a senha não se repõe. */
class SuperAdminNaoRedefinivel extends RuntimeException {}
