<?php

namespace Modules\Financeiro\Tests\Unit;

use InvalidArgumentException;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Support\Moeda;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DinheiroTest extends TestCase
{
    public function test_rejeita_valor_negativo(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Dinheiro::deUnidadesMenores(-1);
    }

    public function test_somar_e_subtrair(): void
    {
        $a = Dinheiro::deUnidadesMenores(2_500_000);
        $b = Dinheiro::deUnidadesMenores(100);

        $this->assertSame(2_500_100, $a->somar($b)->unidadesMenores());
        $this->assertSame(2_499_900, $a->subtrair($b)->unidadesMenores());
    }

    public function test_subtrair_nunca_fica_negativo(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Dinheiro::deUnidadesMenores(100)->subtrair(Dinheiro::deUnidadesMenores(101));
    }

    public function test_percentagem_arredonda_para_baixo(): void
    {
        // 10% de 25.333,33 = 2.533,333 -> 2.533,33
        $this->assertSame(253_333, Dinheiro::deUnidadesMenores(2_533_333)->percentagem(10)->unidadesMenores());
        $this->assertSame(0, Dinheiro::deUnidadesMenores(2_533_333)->percentagem(0)->unidadesMenores());
        $this->assertSame(2_533_333, Dinheiro::deUnidadesMenores(2_533_333)->percentagem(100)->unidadesMenores());
    }

    public function test_percentagem_fora_da_faixa_e_rejeitada(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Dinheiro::deUnidadesMenores(100)->percentagem(101);
    }

    public function test_dividir_a_ultima_parcela_absorve_o_resto(): void
    {
        $partes = Dinheiro::deUnidadesMenores(2_500_000)->dividir(3);

        $this->assertSame([833_333, 833_333, 833_334], array_map(fn (Dinheiro $d) => $d->unidadesMenores(), $partes));
        $this->assertSame(2_500_000, array_sum(array_map(fn (Dinheiro $d) => $d->unidadesMenores(), $partes)));
    }

    public function test_dividir_por_menos_de_uma_parte_e_rejeitado(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Dinheiro::deUnidadesMenores(100)->dividir(0);
    }

    public function test_formatar_usa_as_casas_e_o_simbolo_da_moeda(): void
    {
        $this->assertSame('25.000,00 Kz', Dinheiro::deUnidadesMenores(2_500_000)->formatar(Moeda::de('AOA')));
        $this->assertSame('0,05 Kz', Dinheiro::deUnidadesMenores(5)->formatar(Moeda::de('AOA')));
        $this->assertSame('1.234.567,89 Kz', Dinheiro::deUnidadesMenores(123_456_789)->formatar(Moeda::de('AOA')));
        $this->assertSame('1.234,56 €', Dinheiro::deUnidadesMenores(123_456)->formatar(Moeda::de('EUR')));
        $this->assertSame('25.000 ¥', Dinheiro::deUnidadesMenores(25_000)->formatar(Moeda::de('JPY')));
        $this->assertSame('0 ¥', Dinheiro::deUnidadesMenores(0)->formatar(Moeda::de('JPY')));
        $this->assertSame('25,125 KD', Dinheiro::deUnidadesMenores(25_125)->formatar(Moeda::de('KWD')));
        $this->assertSame('0,005 KD', Dinheiro::deUnidadesMenores(5)->formatar(Moeda::de('KWD')));
    }

    public function test_para_decimal_e_o_inverso_exacto_de_de_decimal_em_todas_as_moedas(): void
    {
        $valores = [0, 1, 5, 9, 10, 99, 100, 101, 999, 1_000, 123_456, 999_999, 1_000_000_007, 999_999_999_999];

        foreach (Moeda::todas() as $moeda) {
            foreach ($valores as $unidades) {
                $texto = Dinheiro::deUnidadesMenores($unidades)->paraDecimal($moeda);

                $this->assertSame(
                    $unidades,
                    Dinheiro::deDecimal($texto, $moeda)->unidadesMenores(),
                    "{$moeda->codigo}: {$unidades} -> '{$texto}'",
                );
            }
        }
    }

    public function test_para_decimal_tem_exactamente_as_casas_da_moeda(): void
    {
        $this->assertSame('25000.50', Dinheiro::deUnidadesMenores(2_500_050)->paraDecimal(Moeda::de('AOA')));
        $this->assertSame('0.05', Dinheiro::deUnidadesMenores(5)->paraDecimal(Moeda::de('AOA')));
        $this->assertSame('25000', Dinheiro::deUnidadesMenores(25_000)->paraDecimal(Moeda::de('JPY')));
        $this->assertSame('25.125', Dinheiro::deUnidadesMenores(25_125)->paraDecimal(Moeda::de('KWD')));
        $this->assertSame('0.005', Dinheiro::deUnidadesMenores(5)->paraDecimal(Moeda::de('KWD')));
    }

    public function test_formatar_respeita_as_casas_de_cada_moeda_do_registo(): void
    {
        foreach (Moeda::todas() as $moeda) {
            $formatado = Dinheiro::deUnidadesMenores(1_234_567)->formatar($moeda);
            $casas = $moeda->decimais === 0 ? '' : ',\d{' . $moeda->decimais . '}';

            $this->assertMatchesRegularExpression('/^\d{1,3}(\.\d{3})*' . $casas . ' \S+/u', $formatado, "{$moeda->codigo}: {$formatado}");
        }
    }

    public function test_percentagem_e_divisao_continuam_exactas_com_valores_grandes(): void
    {
        $grande = Dinheiro::deUnidadesMenores(999_999_999_999);

        $this->assertSame(999_999_999_999, $grande->percentagem(100)->unidadesMenores());
        $this->assertSame(499_999_999_999, $grande->percentagem(50)->unidadesMenores());
        $this->assertSame(999_999_999_999, array_sum(array_map(fn (Dinheiro $d) => $d->unidadesMenores(), $grande->dividir(7))));
    }

    #[DataProvider('conversoesValidas')]
    public function test_de_decimal_converte_conforme_a_moeda(string $moeda, int|string $valor, int $esperado): void
    {
        $this->assertSame($esperado, Dinheiro::deDecimal($valor, Moeda::de($moeda))->unidadesMenores());
    }

    public static function conversoesValidas(): array
    {
        return [
            'AOA inteiro' => ['AOA', 25_000, 2_500_000],
            'AOA texto inteiro' => ['AOA', '25000', 2_500_000],
            'AOA uma casa' => ['AOA', '25000.5', 2_500_050],
            'AOA virgula' => ['AOA', '25000,50', 2_500_050],
            'AOA cêntimos' => ['AOA', '0.05', 5],
            'AOA zero' => ['AOA', '0', 0],
            'JPY inteiro' => ['JPY', 25_000, 25_000],
            'JPY texto' => ['JPY', '25000', 25_000],
            'KWD três casas' => ['KWD', '25000,125', 25_000_125],
            'KWD uma casa' => ['KWD', '1,5', 1_500],
        ];
    }

    #[DataProvider('conversoesInvalidas')]
    public function test_de_decimal_rejeita_valores_invalidos(string $moeda, int|string $valor): void
    {
        $this->expectException(InvalidArgumentException::class);

        Dinheiro::deDecimal($valor, Moeda::de($moeda));
    }

    public static function conversoesInvalidas(): array
    {
        return [
            'AOA vazio' => ['AOA', ''],
            'AOA inteiro gigante' => ['AOA', PHP_INT_MAX],
            'AOA inteiro acima de 12 digitos' => ['AOA', 1_000_000_000_000],
            'KWD inteiro que transborda ao escalar' => ['KWD', 9_000_000_000_000_000],
            'AOA negativo texto' => ['AOA', '-1'],
            'AOA negativo inteiro' => ['AOA', -1],
            'AOA letras' => ['AOA', 'abc'],
            'AOA três casas' => ['AOA', '12.345'],
            'AOA milhares' => ['AOA', '25.000,50'],
            'AOA notação científica' => ['AOA', '1e3'],
            'AOA espaços' => ['AOA', '25 000'],
            'AOA quebra de linha final' => ['AOA', "25000\n"],
            'AOA demasiado grande' => ['AOA', '1234567890123'],
            'JPY com decimais' => ['JPY', '25000,5'],
            'JPY ponto decimal' => ['JPY', '25000.0'],
            'KWD quatro casas' => ['KWD', '12,3456'],
        ];
    }
}
