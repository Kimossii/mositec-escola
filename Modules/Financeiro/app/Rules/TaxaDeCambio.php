<?php

namespace Modules\Financeiro\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;
use Modules\Financeiro\Support\TaxaCambio;

class TaxaDeCambio implements ValidationRule
{
    private const MENSAGEM = 'A taxa de câmbio é inválida: use um número maior que zero, com até 6 casas decimais (ex.: 910 ou 910,50).';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            $fail(self::MENSAGEM);

            return;
        }

        try {
            TaxaCambio::deDecimal(is_int($value) ? $value : (string) $value);
        } catch (InvalidArgumentException) {
            $fail(self::MENSAGEM);
        }
    }
}
