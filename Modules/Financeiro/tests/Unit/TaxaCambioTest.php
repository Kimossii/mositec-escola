<?php

namespace Modules\Financeiro\Tests\Unit;

use InvalidArgumentException;
use Modules\Financeiro\Support\TaxaCambio;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TaxaCambioTest extends TestCase
{
    #[DataProvider('validas')]
    public function test_converte_texto_para_micros(int|string $entrada, int $micros): void
    {
        $this->assertSame($micros, TaxaCambio::deDecimal($entrada)->micros());
    }

    public static function validas(): array
    {
        return [
            'inteiro' => [910, 910_000_000],
            'texto inteiro' => ['910', 910_000_000],
            'virgula' => ['910,5', 910_500_000],
            'ponto' => ['910.50', 910_500_000],
            'seis casas' => ['910,123456', 910_123_456],
            'menor que um' => ['0,000001', 1],
            'um' => ['1', 1_000_000],
        ];
    }

    #[DataProvider('invalidas')]
    public function test_rejeita_valores_invalidos(int|string $entrada): void
    {
        $this->expectException(InvalidArgumentException::class);

        TaxaCambio::deDecimal($entrada);
    }

    public static function invalidas(): array
    {
        return [
            'zero' => ['0'],
            'zero com casas' => ['0,000000'],
            'negativo' => ['-1'],
            'zero inteiro' => [0],
            'sete casas' => ['910,1234567'],
            'letras' => ['abc'],
            'milhares' => ['1.000,50'],
            'notação científica' => ['1e3'],
            'quebra de linha final' => ["910\n"],
            'demasiado grande' => ['1234567890'],
            'vazio' => [''],
            'inteiro acima do limite' => [1_000_000_000],
            'inteiro gigante' => [PHP_INT_MAX],
        ];
    }

    public function test_de_micros_exige_positivo(): void
    {
        $this->assertSame(910_000_000, TaxaCambio::deMicros(910_000_000)->micros());
        $this->expectException(InvalidArgumentException::class);

        TaxaCambio::deMicros(0);
    }

    public function test_um(): void
    {
        $this->assertSame(1_000_000, TaxaCambio::um()->micros());
        $this->assertSame('1,00', TaxaCambio::um()->formatar());
    }

    public function test_formatar_tem_no_minimo_duas_casas_e_sem_zeros_a_mais(): void
    {
        $this->assertSame('910,00', TaxaCambio::deMicros(910_000_000)->formatar());
        $this->assertSame('910,50', TaxaCambio::deMicros(910_500_000)->formatar());
        $this->assertSame('910,123456', TaxaCambio::deMicros(910_123_456)->formatar());
        $this->assertSame('1.234,50', TaxaCambio::deMicros(1_234_500_000)->formatar());
        $this->assertSame('0,000001', TaxaCambio::deMicros(1)->formatar());
    }

    public function test_para_input_usa_ponto_e_nao_tem_milhares(): void
    {
        $this->assertSame('910.00', TaxaCambio::deMicros(910_000_000)->paraInput());
        $this->assertSame('1234.50', TaxaCambio::deMicros(1_234_500_000)->paraInput());
    }
}
