<?php

namespace Modules\Plataforma\Exceptions;

use RuntimeException;

/** O limitador de login do painel bloqueou o pedido (e-mail + IP ou IP). */
class DemasiadasTentativasDeLogin extends RuntimeException
{
    public function __construct(public readonly int $segundos)
    {
        parent::__construct("Muitas tentativas de início de sessão. Tente novamente em {$segundos} segundos.");
    }
}
