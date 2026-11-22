<?php

namespace Modules\Licenciamento\Exceptions;

use RuntimeException;

/** Entradas do licença recusadas, com os erros por campo (campo => mensagem). */
class DadosDeLicencaInvalidos extends RuntimeException
{
    /** @param array<string, string> $erros */
    public function __construct(public readonly array $erros)
    {
        parent::__construct(implode(' ', $erros));
    }
}
