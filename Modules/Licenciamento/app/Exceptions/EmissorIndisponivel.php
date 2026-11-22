<?php

namespace Modules\Licenciamento\Exceptions;

use RuntimeException;

/** Esta instalação não tem uma chave privada de emissão válida: só a instalação central emite licenças. */
class EmissorIndisponivel extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Esta instalação não está configurada para emitir licenças.');
    }
}
