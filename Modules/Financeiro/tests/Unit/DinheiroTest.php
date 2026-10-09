<?php

namespace Modules\Financeiro\Tests\Unit;

use InvalidArgumentException;
use Modules\Financeiro\Support\Dinheiro;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DinheiroTest extends TestCase
{
    public function test_rejeita_valor_negativo(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Dinheiro::deCentimos(-1);
    }

    public function test_somar_e_subtrair(): void
    {
        $a = Dinheiro::deCentimos(2_500_000);
        $b = Dinheiro::deCentimos(100);

        $this->assertSame(2_500_100, $a->somar($b)->centimos());
        $this->assertSame(2_499_900, $a->subtrair($b)->centimos());
    }

    public function test_subtrair_nunca_fica_negativo(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Dinheiro::deCentimos(100)->subtrair(Dinheiro::deCentimos(101));
    }

    public function test_percentagem_arredonda_para_baixo(): void
    {
        // 10% de 25.333,33 Kz = 2.533,333 Kz -> 2.533,33 Kz
        $this->assertSame(253_333, Dinheiro::deCentimos(2_533_333)->percentagem(10)->centimos());
        $this->assertSame(0, Dinheiro::deCentimos(2_533_333)->percentagem(0)->centimos());
        $this->assertSame(2_533_333, Dinheiro::deCentimos(2_533_333)->percentagem(100)->centimos());
    }

    public function test_percentagem_fora_da_faixa_e_rejeitada(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Dinheiro::deCentimos(100)->percentagem(101);
    }

    public function test_dividir_a_ultima_parcela_absorve_o_resto(): void
    {
        // 25.000,00 Kz / 3
        $partes = Dinheiro::deCentimos(2_500_000)->dividir(3);

        $this->assertSame([833_333, 833_333, 833_334], array_map(fn (Dinheiro $d) => $d->centimos(), $partes));
        $this->assertSame(2_500_000, array_sum(array_map(fn (Dinheiro $d) => $d->centimos(), $partes)));
    }

    public function test_dividir_por_menos_de_uma_parte_e_rejeitado(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Dinheiro::deCentimos(100)->dividir(0);
    }

    public function test_formatar(): void
    {
        $this->assertSame('25.000,00 Kz', Dinheiro::deCentimos(2_500_000)->formatar());
        $this->assertSame('0,05 Kz', Dinheiro::deCentimos(5)->formatar());
        $this->assertSame('1.234.567,89 Kz', Dinheiro::deCentimos(123_456_789)->formatar());
    }

    public function test_de_kwanzas_converte_inteiros_e_texto_para_centimos(): void
    {
        $this->assertSame(2_500_000, Dinheiro::deKwanzas(25_000)->centimos());
        $this->assertSame(2_500_000, Dinheiro::deKwanzas('25000')->centimos());
        $this->assertSame(2_500_050, Dinheiro::deKwanzas('25000.5')->centimos());
        $this->assertSame(2_500_050, Dinheiro::deKwanzas('25000,50')->centimos());
        $this->assertSame(5, Dinheiro::deKwanzas('0.05')->centimos());
        $this->assertSame(0, Dinheiro::deKwanzas('0')->centimos());
        $this->assertSame(0, Dinheiro::deKwanzas(0)->centimos());
    }

    #[DataProvider('kwanzasInvalidos')]
    public function test_de_kwanzas_rejeita_valores_invalidos(int|string $valor): void
    {
        $this->expectException(InvalidArgumentException::class);

        Dinheiro::deKwanzas($valor);
    }

    public static function kwanzasInvalidos(): array
    {
        return [
            'vazio' => [''],
            'negativo texto' => ['-1'],
            'negativo inteiro' => [-1],
            'letras' => ['abc'],
            'três casas decimais' => ['12.345'],
            'milhares com ponto' => ['25.000,50'],
            'notação científica' => ['1e3'],
            'espaços' => ['25 000'],
            'quebra de linha final' => ["25000\n"],
            'demasiado grande' => ['1234567890123'],
        ];
    }
}
