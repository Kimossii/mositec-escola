<?php

namespace Modules\Licenciamento\Exceptions;

use RuntimeException;

/** Entradas do plano recusadas, com os erros por campo (campo => mensagem). */
class DadosDePlanoInvalidos extends RuntimeException
{
    /** @param array<string, string> $erros */
    public function __construct(public readonly array $erros)
    {
        parent::__construct(implode(' ', $erros));
    }
}
