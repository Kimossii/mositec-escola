<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Financeiro\Enums\Periodicidade;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

class PlanoPropinaPeriodosTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    public function test_o_plano_conhece_as_duracoes_o_aviso_e_o_total(): void
    {
        $ano = $this->anoLectivo();
        $trimestral = $this->plano($ano, 'Trimestral', ['periodicidade' => Periodicidade::TRIMESTRAL, 'intervalo_meses' => 3]);
        $mensal = $this->plano($ano, 'Mensal');

        $this->assertSame([3, 3, 3, 1], $trimestral->duracoesDosPeriodos());
        $this->assertSame(array_column($trimestral->periodos(), 'meses'), $trimestral->duracoesDosPeriodos());
        $this->assertTrue($trimestral->ultimoPeriodoMaisCurto());
        $this->assertSame(10_000_000, $trimestral->valorTotal()->unidadesMenores()); // 4 × 25.000,00 (valor integral no último)

        $this->assertSame(array_fill(0, 10, 1), $mensal->duracoesDosPeriodos());
        $this->assertFalse($mensal->ultimoPeriodoMaisCurto());
        $this->assertSame(25_000_000, $mensal->valorTotal()->unidadesMenores());
    }

    public function test_a_lista_expoe_duracoes_total_aviso_e_se_o_plano_tem_propinas(): void
    {
        ['ano' => $ano, 'plano' => $mensal, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->plano($ano, 'Trimestral', ['periodicidade' => Periodicidade::TRIMESTRAL, 'intervalo_meses' => 3]);
        $this->propina($matricula, $mensal);

        $this->actingAs($this->adminEscola())
            ->get(route('financeiro.configuracao.planos-propina.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('planos.data', 2)
                ->where('planos.data.0.nome', 'Propina Mensal')
                ->where('planos.data.0.duracoes_periodos', array_fill(0, 10, 1))
                ->where('planos.data.0.ultimo_periodo_mais_curto', false)
                ->where('planos.data.0.valor_total', 25_000_000)
                ->where('planos.data.0.tem_propinas', true)
                ->where('planos.data.1.nome', 'Trimestral')
                ->where('planos.data.1.duracoes_periodos', [3, 3, 3, 1])
                ->where('planos.data.1.ultimo_periodo_mais_curto', true)
                ->where('planos.data.1.valor_total', 10_000_000)
                ->where('planos.data.1.periodos_total', 4)
                ->where('planos.data.1.tem_propinas', false));
    }

    public function test_a_lista_expoe_periodicidade_outra_com_ultimo_periodo_mais_curto(): void
    {
        $ano = $this->anoLectivo();
        $this->plano($ano, 'Semestral', ['periodicidade' => Periodicidade::OUTRA, 'intervalo_meses' => 6]);
        $this->plano($ano, 'Anual', ['periodicidade' => Periodicidade::OUTRA, 'intervalo_meses' => 12]);

        $this->actingAs($this->adminEscola())
            ->get(route('financeiro.configuracao.planos-propina.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('planos.data', 2)
                ->where('planos.data.0.nome', 'Anual')
                ->where('planos.data.0.duracoes_periodos', [10])
                ->where('planos.data.0.ultimo_periodo_mais_curto', true)
                ->where('planos.data.0.valor_total', 2_500_000)
                ->where('planos.data.1.nome', 'Semestral')
                ->where('planos.data.1.duracoes_periodos', [6, 4])
                ->where('planos.data.1.ultimo_periodo_mais_curto', true)
                ->where('planos.data.1.valor_total', 5_000_000));
    }
}
