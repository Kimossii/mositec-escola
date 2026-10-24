<?php

namespace Modules\Financeiro\Tests\Unit;

use InvalidArgumentException;
use Modules\Financeiro\Support\Moeda;
use PHPUnit\Framework\TestCase;

class MoedaTest extends TestCase
{
    public function test_de_devolve_a_moeda_do_registo(): void
    {
        $moeda = Moeda::de('AOA');

        $this->assertSame('AOA', $moeda->codigo);
        $this->assertSame('Kwanza angolano', $moeda->nome);
        $this->assertSame('Kz', $moeda->simbolo);
        $this->assertSame(2, $moeda->decimais);
    }

    public function test_codigo_e_normalizado(): void
    {
        $this->assertSame('AOA', Moeda::de(' aoa ')->codigo);
        $this->assertTrue(Moeda::existe('usd'));
    }

    public function test_as_casas_decimais_variam_por_moeda(): void
    {
        $this->assertSame(2, Moeda::de('EUR')->decimais);
        $this->assertSame(2, Moeda::de('USD')->decimais);
        $this->assertSame(0, Moeda::de('XOF')->decimais);
        $this->assertSame(0, Moeda::de('JPY')->decimais);
        $this->assertSame(3, Moeda::de('KWD')->decimais);
        $this->assertSame(3, Moeda::de('BHD')->decimais);
    }

    public function test_fator(): void
    {
        $this->assertSame(1, Moeda::de('JPY')->fator());
        $this->assertSame(100, Moeda::de('AOA')->fator());
        $this->assertSame(1000, Moeda::de('KWD')->fator());
    }

    public function test_moeda_desconhecida_e_rejeitada(): void
    {
        $this->assertFalse(Moeda::existe('XXX'));
        $this->assertFalse(Moeda::existe(''));

        $this->expectException(InvalidArgumentException::class);

        Moeda::de('XXX');
    }

    public function test_o_registo_e_coerente(): void
    {
        $codigos = [];

        foreach (Moeda::todas() as $moeda) {
            $this->assertMatchesRegularExpression('/^[A-Z]{3}$/', $moeda->codigo);
            $this->assertGreaterThanOrEqual(0, $moeda->decimais);
            $this->assertLessThanOrEqual(3, $moeda->decimais);
            $this->assertNotSame('', $moeda->simbolo);
            $this->assertNotSame('', $moeda->nome);
            $codigos[] = $moeda->codigo;
        }

        $this->assertSame($codigos, array_values(array_unique($codigos)));
        $ordenados = $codigos;
        sort($ordenados);
        $this->assertSame($ordenados, $codigos);
        $this->assertContains('AOA', $codigos);
        $this->assertContains('MZN', $codigos);
        $this->assertContains('USD', $codigos);
        $this->assertContains('EUR', $codigos);
    }

    public function test_opcoes_e_para_frontend(): void
    {
        $opcoes = Moeda::opcoes();

        $this->assertContains(['value' => 'AOA', 'label' => 'AOA — Kwanza angolano (Kz)'], $opcoes);
        $this->assertSame(array_column($opcoes, 'value'), array_map(fn (Moeda $m) => $m->codigo, Moeda::todas()));
        $this->assertSame(
            ['codigo' => 'EUR', 'nome' => 'Euro', 'simbolo' => '€', 'decimais' => 2],
            Moeda::de('EUR')->paraFrontend(),
        );
    }
}
