<?php

namespace Modules\Financeiro\Tests\Unit;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\Financeiro\Support\CalendarioDePlano;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CalendarioDePlanoTest extends TestCase
{
    public function test_meses_do_periodo(): void
    {
        $this->assertSame(10, CalendarioDePlano::meses(9, 6)); // Set -> Jun atravessa o ano civil
        $this->assertSame(12, CalendarioDePlano::meses(1, 12));
        $this->assertSame(12, CalendarioDePlano::meses(9, 8));
        $this->assertSame(1, CalendarioDePlano::meses(6, 6));
        $this->assertSame(2, CalendarioDePlano::meses(12, 1));
    }

    #[DataProvider('mesesInvalidos')]
    public function test_meses_fora_de_1_a_12_sao_rejeitados(int $inicio, int $fim): void
    {
        $this->expectException(InvalidArgumentException::class);

        CalendarioDePlano::meses($inicio, $fim);
    }

    public static function mesesInvalidos(): array
    {
        return ['inicio 0' => [0, 6], 'inicio 13' => [13, 6], 'fim 0' => [9, 0], 'fim 13' => [9, 13]];
    }

    public function test_set_a_jun_com_ano_a_comecar_em_setembro_atravessa_o_ano_civil(): void
    {
        $competencias = CalendarioDePlano::competencias(9, 6, CarbonImmutable::parse('2026-09-01'));

        $this->assertCount(10, $competencias);
        $this->assertSame(['ano' => 2026, 'mes' => 9], $competencias[0]);
        $this->assertSame(['ano' => 2026, 'mes' => 12], $competencias[3]);
        $this->assertSame(['ano' => 2027, 'mes' => 1], $competencias[4]);
        $this->assertSame(['ano' => 2027, 'mes' => 6], $competencias[9]);
    }

    public function test_jan_a_dez_com_ano_a_comecar_em_setembro_comeca_no_ano_seguinte(): void
    {
        $competencias = CalendarioDePlano::competencias(1, 12, CarbonImmutable::parse('2026-09-01'));

        $this->assertCount(12, $competencias);
        $this->assertSame(['ano' => 2027, 'mes' => 1], $competencias[0]);
        $this->assertSame(['ano' => 2027, 'mes' => 12], $competencias[11]);
    }

    public function test_mes_de_inicio_igual_ao_do_ano_lectivo_fica_no_mesmo_ano(): void
    {
        $competencias = CalendarioDePlano::competencias(9, 6, CarbonImmutable::parse('2026-08-15'));

        $this->assertSame(['ano' => 2026, 'mes' => 9], $competencias[0]);
    }

    public function test_mes_de_inicio_anterior_ao_do_ano_lectivo_vai_para_o_ano_seguinte(): void
    {
        $competencias = CalendarioDePlano::competencias(9, 6, CarbonImmutable::parse('2026-10-01'));

        $this->assertSame(['ano' => 2027, 'mes' => 9], $competencias[0]);
        $this->assertSame(['ano' => 2028, 'mes' => 6], $competencias[9]);
    }

    public function test_mes_unico(): void
    {
        $this->assertSame(
            [['ano' => 2027, 'mes' => 6]],
            CalendarioDePlano::competencias(6, 6, CarbonImmutable::parse('2026-09-01')),
        );
    }

    public function test_trimestral_em_dez_meses_da_tres_tres_tres_e_um(): void
    {
        $periodos = CalendarioDePlano::periodos(9, 6, 3, CarbonImmutable::parse('2026-09-01'));

        $this->assertSame([3, 3, 3, 1], array_column($periodos, 'meses'));
        $this->assertSame([1, 2, 3, 4], array_column($periodos, 'ordem'));
        $this->assertSame(['ordem' => 1, 'meses' => 3, 'inicio' => '2026-09-01', 'fim' => '2026-11-30'], $periodos[0]);
        $this->assertSame(['ordem' => 2, 'meses' => 3, 'inicio' => '2026-12-01', 'fim' => '2027-02-28'], $periodos[1]);
        $this->assertSame(['ordem' => 4, 'meses' => 1, 'inicio' => '2027-06-01', 'fim' => '2027-06-30'], $periodos[3]);
    }

    public function test_mensal_da_um_periodo_por_mes(): void
    {
        $periodos = CalendarioDePlano::periodos(9, 6, 1, CarbonImmutable::parse('2026-09-01'));

        $this->assertCount(10, $periodos);
        $this->assertSame(range(1, 10), array_column($periodos, 'ordem'));
        $this->assertSame('2027-02-28', $periodos[5]['fim']);
    }

    public function test_anual_em_dez_meses_da_um_periodo_curto(): void
    {
        $periodos = CalendarioDePlano::periodos(9, 6, 12, CarbonImmutable::parse('2026-09-01'));

        $this->assertCount(1, $periodos);
        $this->assertSame(10, $periodos[0]['meses']);
        $this->assertSame('2027-06-30', $periodos[0]['fim']);
    }

    #[DataProvider('intervalosInvalidos')]
    public function test_intervalo_fora_de_1_a_12_e_rejeitado(int $intervalo): void
    {
        $this->expectException(InvalidArgumentException::class);

        CalendarioDePlano::periodos(9, 6, $intervalo, CarbonImmutable::parse('2026-09-01'));
    }

    public static function intervalosInvalidos(): array
    {
        return ['zero' => [0], 'treze' => [13], 'negativo' => [-1]];
    }

    public function test_fases_de_preco_set_a_dez_e_jan_a_jun_nao_se_sobrepoem(): void
    {
        $inicio = CarbonImmutable::parse('2026-09-01');
        $fase1 = CalendarioDePlano::competencias(9, 12, $inicio);
        $fase2 = CalendarioDePlano::competencias(1, 6, $inicio);

        $this->assertFalse(CalendarioDePlano::sobrepoem($fase1, $fase2));
        $this->assertFalse(CalendarioDePlano::sobrepoem($fase2, $fase1));
    }

    public function test_periodos_com_um_mes_em_comum_sobrepoem_se(): void
    {
        $inicio = CarbonImmutable::parse('2026-09-01');
        $a = CalendarioDePlano::competencias(9, 12, $inicio);
        $b = CalendarioDePlano::competencias(12, 6, $inicio); // Dez/2026 .. Jun/2027

        $this->assertTrue(CalendarioDePlano::sobrepoem($a, $b));
        $this->assertTrue(CalendarioDePlano::sobrepoem($a, $a));
    }

    public function test_contem_compara_ano_e_mes(): void
    {
        $competencias = CalendarioDePlano::competencias(9, 6, CarbonImmutable::parse('2026-09-01'));

        $this->assertTrue(CalendarioDePlano::contem($competencias, 2026, 9));
        $this->assertTrue(CalendarioDePlano::contem($competencias, 2027, 6));
        $this->assertFalse(CalendarioDePlano::contem($competencias, 2026, 8));
        $this->assertFalse(CalendarioDePlano::contem($competencias, 2027, 9)); // mesmo mês, outro ano
    }

    public function test_duracoes_dos_periodos_com_o_ultimo_mais_curto(): void
    {
        $this->assertSame([3, 3, 3, 1], CalendarioDePlano::duracoes(9, 6, 3));   // Set → Jun trimestral
        $this->assertSame(array_fill(0, 10, 1), CalendarioDePlano::duracoes(9, 6, 1));
        $this->assertSame([6, 4], CalendarioDePlano::duracoes(9, 6, 6));
        $this->assertSame([12], CalendarioDePlano::duracoes(1, 12, 12));
        $this->assertSame([2, 2, 2, 2, 2], CalendarioDePlano::duracoes(9, 6, 2));
    }

    public function test_duracoes_coincidem_com_os_periodos_para_qualquer_combinacao(): void
    {
        $inicio = CarbonImmutable::parse('2026-09-01');

        for ($mesInicio = 1; $mesInicio <= 12; $mesInicio++) {
            for ($mesFim = 1; $mesFim <= 12; $mesFim++) {
                for ($intervalo = 1; $intervalo <= 12; $intervalo++) {
                    $this->assertSame(
                        array_column(CalendarioDePlano::periodos($mesInicio, $mesFim, $intervalo, $inicio), 'meses'),
                        CalendarioDePlano::duracoes($mesInicio, $mesFim, $intervalo),
                        "{$mesInicio} → {$mesFim} de {$intervalo} em {$intervalo}",
                    );
                }
            }
        }
    }

    public function test_duracoes_recusam_intervalo_fora_de_1_a_12(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CalendarioDePlano::duracoes(9, 6, 0);
    }
}
