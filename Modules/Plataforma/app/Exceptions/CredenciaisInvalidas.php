<?php

namespace Modules\Plataforma\Exceptions;

use RuntimeException;

/**
 * Login recusado. A mensagem é SEMPRE a mesma: não distingue e-mail inexistente, senha errada
 * nem conta desactivada.
 */
class CredenciaisInvalidas extends RuntimeException
{
    public const MENSAGEM = 'Credenciais inválidas.';

    public function __construct()
    {
        parent::__construct(self::MENSAGEM);
    }
}
