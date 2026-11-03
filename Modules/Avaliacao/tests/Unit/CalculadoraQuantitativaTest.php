<?php

namespace Modules\Avaliacao\Tests\Unit;

use InvalidArgumentException;
use Modules\Avaliacao\Enums\ModoArredondamento;
use Modules\Avaliacao\Support\Calculo\CalculadoraQuantitativa;
use PHPUnit\Framework\TestCase;

class CalculadoraQuantitativaTest extends TestCase
{
    private function calc(): CalculadoraQuantitativa
    {
        return new CalculadoraQuantitativa();
    }

    public function test_mac_ana_7_50(): void
    {
        $this->assertSame('7.5000000000', $this->calc()->media(['7', '8', '6', '9']));
    }

    public function test_mac_joao_conta_o_zero_e_divide_por_3(): void
    {
        $this->assertSame('3.0000000000', $this->calc()->media(['5', '4', '0']));
    }

    public function test_mac_de_lista_vazia_e_null(): void
    {
        $this->assertNull($this->calc()->media([]));
    }

    public function test_mt_6_165(): void
    {
        $this->assertSame('6.1650000000', $this->calc()->mt('5.33', '7', '50', '50'));
    }

    public function test_mt_com_pesos_60_40(): void
    {
        $this->assertSame('6.8000000000', $this->calc()->mt('8', '5', '60', '40'));
    }

    public function test_mt_sem_mac_ou_sem_pt_e_null(): void
    {
        $this->assertNull($this->calc()->mt(null, '7', '50', '50'));
        $this->assertNull($this->calc()->mt('7', null, '50', '50'));
    }

    public function test_mt_rejeita_pesos_que_nao_somam_100(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc()->mt('5', '7', '50', '40');
    }

    public function test_mfd_7_055(): void
    {
        $this->assertSame('7.0550000000', $this->calc()->mfd(['6.165', '6.5', '8.5']));
    }

    public function test_mfd_com_pesos_diferentes(): void
    {
        $this->assertSame('8.0000000000', $this->calc()->mfd(['10', '8', '6'], ['25', '50', '25']));
    }

    public function test_mfd_sem_tres_trimestres_e_null(): void
    {
        $this->assertNull($this->calc()->mfd(['6', null, '8']));
        $this->assertNull($this->calc()->mfd(['6', '7']));
    }

    public function test_mfd_nao_arredonda_antes(): void
    {
        $this->assertSame('6.6650000000', $this->calc()->mfd(['6.665', '6.665', '6.665']));
    }

    public function test_arredondar_half_up(): void
    {
        $this->assertSame('5', $this->calc()->arredondar('4.5', 0, ModoArredondamento::HALF_UP));
        $this->assertSame('4', $this->calc()->arredondar('4.49', 0, ModoArredondamento::HALF_UP));
        $this->assertSame('6.17', $this->calc()->arredondar('6.165', 2, ModoArredondamento::HALF_UP));
    }

    public function test_arredondar_half_even_e_truncar(): void
    {
        $this->assertSame('4', $this->calc()->arredondar('4.5', 0, ModoArredondamento::HALF_EVEN));
        $this->assertSame('6', $this->calc()->arredondar('5.5', 0, ModoArredondamento::HALF_EVEN));
        $this->assertSame('4', $this->calc()->arredondar('4.99', 0, ModoArredondamento::TRUNCAR));
    }

    public function test_aprovado_exige_arredondado_maior_ou_igual_ao_minimo(): void
    {
        $this->assertTrue($this->calc()->aprovadoNaDisciplina('5', '5'));
        $this->assertFalse($this->calc()->aprovadoNaDisciplina('4.99', '5'));
    }

    public function test_exame_10_e_10_dao_10_sem_dividir_por_2(): void
    {
        $this->assertSame('10.0000000000', $this->calc()->mfComExame('10', '10', '40', '60'));
        $this->assertSame('20.0000000000', $this->calc()->mfComExame('20', '20', '40', '60'));
    }

    public function test_exame_12_e_14_dao_13_2_e_os_pesos_sao_configuraveis(): void
    {
        $this->assertSame('13.2000000000', $this->calc()->mfComExame('12', '14', '40', '60'));
        $this->assertSame('13.0000000000', $this->calc()->mfComExame('12', '14', '50', '50'));
    }

    public function test_exame_rejeita_pesos_que_nao_somam_100(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc()->mfComExame('10', '10', '40', '50');
    }
}
