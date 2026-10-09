<?php

namespace Modules\Financeiro\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Support\Dinheiro;

/**
 * Valida um montante vindo de um formulário na MOEDA DA ESCOLA (casas decimais incluídas).
 * Ponto único da regra: Planos, Produtos e Serviços usam-na em vez de repetir o regex.
 */
class ValorMonetario implements ValidationRule
{
    public function __construct(private bool $permiteZero = true)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $moeda = app(MoedaDoTenant::class)->atual();

        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            $fail($this->mensagemInvalido($moeda->decimais));

            return;
        }

        try {
            $dinheiro = Dinheiro::deDecimal(is_int($value) ? $value : (string) $value, $moeda);
        } catch (InvalidArgumentException) {
            $fail($this->mensagemInvalido($moeda->decimais));

            return;
        }

        if (! $this->permiteZero && $dinheiro->unidadesMenores() === 0) {
            $fail('O valor tem de ser superior a zero.');
        }
    }

    private function mensagemInvalido(int $decimais): string
    {
        if ($decimais === 0) {
            return 'O valor é inválido: use apenas dígitos inteiros (ex.: 25000).';
        }

        $exemplo = '25000,' . str_pad('5', $decimais, '0');

        return "O valor é inválido: use dígitos com até {$decimais} casas decimais (ex.: 25000 ou {$exemplo}).";
    }
}
