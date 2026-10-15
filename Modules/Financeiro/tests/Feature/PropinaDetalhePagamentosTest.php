<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

class PropinaDetalhePagamentosTest extends TestCase
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
        $this->regras();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_o_detalhe_lista_pagamentos_e_so_deixa_anular_quando_tudo_foi_anulado(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano, 1);
        $this->actingAs($this->adminEscola());
        $pagamento = $this->pagar($matricula, 2_500_000, [[$propina, 2_500_000]]);

        $this->get(route('financeiro.propinas.show', $propina->id))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('propina.pagamentos.0.numero', $pagamento->numero)
            ->where('propina.pagamentos.0.activo', true)
            ->where('podeAnular', false)
            ->where('podeCancelar', false));

        $this->anularPagamento($pagamento);

        $this->get(route('financeiro.propinas.show', $propina->id))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('propina.pagamentos.0.activo', false)
            ->where('propina.anulacao_bloqueada', null)
            ->where('podeAnular', true)
            ->where('podeCancelar', false));

        $this->from(route('financeiro.propinas.show', $propina->id))
            ->patch(route('financeiro.propinas.anular', $propina->id), ['motivo' => 'Bolsa atribuída'])
            ->assertSessionHas('success', 'Propina anulada.');
        $this->assertSame(EstadoCobranca::ANULADA, $propina->fresh()->estado);
    }

    public function test_sem_historico_a_rota_recusa_e_sem_permissao_da_403(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano, 1);
        $this->assertContains('can:propina.anular', Route::getRoutes()->getByName('financeiro.propinas.anular')->gatherMiddleware());
        $this->assertSame('[0-9]+', Route::getRoutes()->getByName('financeiro.propinas.anular')->wheres['propina'] ?? null, 'Falta whereNumber(propina).');

        $this->actingAs($this->adminEscola());
        $this->from(route('financeiro.propinas.show', $propina->id))
            ->patch(route('financeiro.propinas.anular', $propina->id), ['motivo' => 'Sem histórico'])
            ->assertSessionHasErrors(['propina' => 'A propina nunca recebeu pagamentos: cancele-a em vez de a anular.']);

        $this->actingAs($this->professor());
        $this->patch(route('financeiro.propinas.anular', $propina->id), ['motivo' => 'Sem permissão'])->assertForbidden();
    }
}
