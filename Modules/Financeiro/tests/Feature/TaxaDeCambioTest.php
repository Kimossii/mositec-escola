<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Support\Facades\Validator;
use Modules\Financeiro\Rules\TaxaDeCambio;
use Tests\TestCase;

class TaxaDeCambioTest extends TestCase
{
    private function passa(mixed $valor): bool
    {
        return Validator::make(['t' => $valor], ['t' => ['required', new TaxaDeCambio()]])->passes();
    }

    public function test_aceita_taxas_validas(): void
    {
        foreach (['910', '910,5', '910.123456', 910, 1] as $valor) {
            $this->assertTrue($this->passa($valor), var_export($valor, true));
        }
    }

    public function test_rejeita_inteiros_gigantes_sem_rebentar(): void
    {
        foreach ([1_000_000_000, PHP_INT_MAX, 99999999999999999999] as $valor) {
            $this->assertFalse($this->passa($valor), var_export($valor, true));
        }
    }

    public function test_rejeita_taxas_invalidas_com_mensagem_em_portugues(): void
    {
        foreach (['0', '-1', 'abc', '910,1234567', "910\n", [1]] as $valor) {
            $this->assertFalse($this->passa($valor), var_export($valor, true));
        }

        $erros = Validator::make(['t' => '0'], ['t' => [new TaxaDeCambio()]])->errors();
        $this->assertSame('A taxa de câmbio é inválida: use um número maior que zero, com até 6 casas decimais (ex.: 910 ou 910,50).', $erros->first('t'));
    }
}
