<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

class DividasRecibosRelatoriosTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPagamentosFinanceiro;
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

    public function test_dividas_recibos_e_relatorio(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $set = $this->propina($matricula, $plano, 1);
        $this->propina($matricula, $plano, 2);
        $this->pagar($matricula, 2_500_000, [[$set, 2_500_000]]);
        $admin = $this->adminEscola();

        $this->actingAs($admin)->get('/financeiro/dividas')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('dividas.data.0.meses', 1)
            ->missing('totais') // diferido: não vem no primeiro pedido
            ->loadDeferredProps(fn (Assert $carregado) => $carregado->where('totais.matriculas', 1)->where('totais.propinas', 2_500_000)));

        $this->actingAs($admin)->get('/financeiro/dividas?tipo=cobrancas')->assertOk()->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $c) => $c->where('totais.matriculas', 0)));
        $this->actingAs($admin)->get('/financeiro/dividas/exportar')->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->actingAs($admin)->get('/financeiro/propinas')->assertOk()->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $c) => $c
            ->where('indicadores.propinas', 2)
            ->where('indicadores.emitido', 5_000_000)
            ->where('indicadores.recebido', 2_500_000)
            ->where('indicadores.em_atraso_valor', 2_500_000)));

        $this->actingAs($admin)->get('/financeiro/recibos')->assertOk()->assertInertia(fn (Assert $page) => $page->has('recibos.data', 1));

        $this->actingAs($admin)->get('/financeiro/relatorios?de=2026-10-01&ate=2026-10-31')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('periodo.de', '2026-10-01')
            ->loadDeferredProps(fn (Assert $c) => $c
                ->where('relatorio.recebido.total', 2_500_000)
                ->where('relatorio.recebido.propinas', 2_500_000)
                ->where('relatorio.divida.total', 2_500_000)
                ->has('relatorio.por_metodo', 1)));
        $this->actingAs($admin)->get('/financeiro/relatorios/recebimentos.csv?de=2026-10-01&ate=2026-10-31')->assertOk();
    }

    public function test_indicadores_diferidos_das_listas_do_financeiro(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $set = $this->propina($matricula, $plano, 1);
        $this->pagar($matricula, 2_500_000, [[$set, 2_500_000]]);
        $admin = $this->adminEscola();

        $this->actingAs($admin)->get('/financeiro/pagamentos')->assertOk()->assertInertia(fn (Assert $p) => $p->missing('indicadores')
            ->loadDeferredProps(fn (Assert $c) => $c->where('indicadores.recebido', 2_500_000)->where('indicadores.pagamentos', 1)));
        $this->actingAs($admin)->get('/financeiro/recibos')->assertOk()->assertInertia(fn (Assert $p) => $p
            ->loadDeferredProps(fn (Assert $c) => $c->where('indicadores.pagamentos', 1)->where('indicadores.anulados', 0)));

        foreach (['cobrancas', 'bolsas', 'acordos'] as $lista) {
            $this->actingAs($admin)->get("/financeiro/{$lista}")->assertOk()->assertInertia(fn (Assert $p) => $p->loadDeferredProps(fn (Assert $c) => $c->has('indicadores')));
        }
    }
}
