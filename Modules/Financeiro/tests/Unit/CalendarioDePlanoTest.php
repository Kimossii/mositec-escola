<?php

namespace Modules\Financeiro\Tests\Unit;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\Financeiro\Support\CalendarioDePlano;
use PHPUnit\Framework\TestCase;

class CalendarioDePlanoTest extends TestCase
{
    private function d(string $data): CarbonImmutable
    {
        return CarbonImmutable::parse($data);
    }

    public function test_meses_contam_cada_mes_tocado_como_inteiro(): void
    {
        $this->assertSame(10, CalendarioDePlano::meses($this->d('2026-09-01'), $this->d('2027-06-30')));
        $this->assertSame(10, CalendarioDePlano::meses($this->d('2026-09-15'), $this->d('2027-06-20')));
        $this->assertSame(12, CalendarioDePlano::meses($this->d('2027-01-01'), $this->d('2027-12-31')));
        $this->assertSame(1, CalendarioDePlano::meses($this->d('2027-06-10'), $this->d('2027-06-20')));
        $this->assertSame(2, CalendarioDePlano::meses($this->d('2026-12-01'), $this->d('2027-01-31')));
    }

    public function test_competencias_atravessam_o_ano_civil_com_o_ano_das_datas(): void
    {
        $competencias = CalendarioDePlano::competencias($this->d('2026-09-01'), $this->d('2027-06-30'));

        $this->assertCount(10, $competencias);
        $this->assertSame(['ano' => 2026, 'mes' => 9], $competencias[0]);
        $this->assertSame(['ano' => 2026, 'mes' => 12], $competencias[3]);
        $this->assertSame(['ano' => 2027, 'mes' => 1], $competencias[4]);
        $this->assertSame(['ano' => 2027, 'mes' => 6], $competencias[9]);
    }

    public function test_periodos_mensais_e_trimestrais_com_ultimo_mais_curto(): void
    {
        $mensais = CalendarioDePlano::periodos($this->d('2026-09-01'), $this->d('2027-06-30'), 1);
        $this->assertCount(10, $mensais);
        $this->assertSame(['ordem' => 1, 'meses' => 1, 'inicio' => '2026-09-01', 'fim' => '2026-09-30'], $mensais[0]);

        $trimestrais = CalendarioDePlano::periodos($this->d('2026-09-01'), $this->d('2027-06-30'), 3);
        $this->assertSame([3, 3, 3, 1], array_column($trimestrais, 'meses'));
        $this->assertSame('2026-12-01', $trimestrais[1]['inicio']);
        $this->assertSame('2027-06-30', $trimestrais[3]['fim']);
        $this->assertSame([3, 3, 3, 1], CalendarioDePlano::duracoes($this->d('2026-09-01'), $this->d('2027-06-30'), 3));
    }

    public function test_intervalo_fora_de_1_a_12_e_rejeitado(): void
    {
        foreach ([0, 13] as $intervalo) {
            try {
                CalendarioDePlano::periodos($this->d('2026-09-01'), $this->d('2027-06-30'), $intervalo);
                $this->fail('Devia rejeitar o intervalo ' . $intervalo);
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_sobreposicao_e_contem(): void
    {
        $a = CalendarioDePlano::competencias($this->d('2026-09-01'), $this->d('2026-12-31'));
        $b = CalendarioDePlano::competencias($this->d('2026-12-01'), $this->d('2027-03-31'));
        $c = CalendarioDePlano::competencias($this->d('2027-01-01'), $this->d('2027-06-30'));

        $this->assertTrue(CalendarioDePlano::sobrepoem($a, $b));
        $this->assertFalse(CalendarioDePlano::sobrepoem($a, $c));
        $this->assertTrue(CalendarioDePlano::contem($a, 2026, 10));
        $this->assertFalse(CalendarioDePlano::contem($a, 2027, 1));
    }
}
