<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Financeiro\Tests\Concerns\ComAlteracaoPreco;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

class PropinaDetalheAjustesTest extends TestCase
{
    use ComAlteracaoPreco;
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_o_detalhe_lista_os_ajustes_por_ordem_com_autor_planos_e_motivo(): void
    {
        $admin = $this->adminEscola();
        $this->actingAs($admin);
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $fevereiro = $this->propina($matricula, $plano, 6);
        $primeira = $this->alterarPreco($plano, '2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS, 'Subida anual');
        $this->alterarPreco($primeira->planoNovo, '2027-02', 2_800_000, ModoAlteracaoPreco::APLICAR_GERADAS, 'Correcção');

        $this->get(route('financeiro.propinas.show', $fevereiro->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('propina.ajustes', 2)
                ->where('propina.valor', 2_800_000)
                ->where('propina.valor_original', 2_500_000)
                ->where('propina.ajustes.0.valor_anterior', 2_500_000)
                ->where('propina.ajustes.0.valor_novo', 3_000_000)
                ->where('propina.ajustes.0.valor_pago_no_momento', 0)
                ->where('propina.ajustes.0.plano_anterior', 'Propina Mensal')
                ->where('propina.ajustes.0.plano_novo', 'Propina Mensal – Fase 2')
                ->where('propina.ajustes.0.modo_descricao', 'Aplicar às propinas já geradas')
                ->where('propina.ajustes.0.mes_efeito', 'Fev/2027')
                ->where('propina.ajustes.0.motivo', 'Subida anual')
                ->where('propina.ajustes.0.por', $admin->name)
                ->where('propina.ajustes.0.em', '2026-10-16 11:00')
                ->where('propina.ajustes.1.valor_anterior', 3_000_000)
                ->where('propina.ajustes.1.valor_novo', 2_800_000)
                ->where('propina.ajustes.1.plano_novo', null)
                ->where('propina.ajustes.1.motivo', 'Correcção'));
    }
}
