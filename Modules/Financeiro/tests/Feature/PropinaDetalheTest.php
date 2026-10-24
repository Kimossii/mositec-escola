<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Financeiro\Actions\CancelarPropinaAction;
use Modules\Financeiro\DTO\CancelarPropinaDTO;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Modulo;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo as ModuloRegistro;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Modules\Permissao\Support\PermissaoCache;
use Tests\TestCase;

class PropinaDetalheTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    private const ROTA = 'financeiro.propinas.show';

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

    private function detalhe(int $id): TestResponse
    {
        return $this->get(route(self::ROTA, $id));
    }

    public function test_mostra_o_detalhe_com_a_forma_esperada(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano, 1);
        $this->actingAs($this->adminEscola());

        $this->detalhe($propina->id)->assertOk()->assertInertia(fn (Assert $pagina) => $pagina
            ->component('Financeiro/Propinas/Show')
            ->where('propina.id', $propina->id)
            // Set/2026: limite 15/09, hoje 16/10 → Em Atraso (resolvido), persistido Em Aberto.
            ->where('propina.estado', EstadoCobranca::EM_ATRASO->value)
            ->where('propina.estado_descricao', 'Em Atraso')
            ->where('propina.estado_persistido', EstadoCobranca::EM_ABERTO->value)
            ->where('propina.periodo', 'Set/2026')
            ->where('propina.ordem', 1)
            ->where('propina.valor_original', $plano->valor->unidadesMenores())
            ->where('propina.valor', $plano->valor->unidadesMenores())
            ->where('propina.valor_pago', 0)
            ->where('propina.saldo', $plano->valor->unidadesMenores())
            ->where('propina.data_vencimento', '2026-09-10')
            ->where('propina.dias_tolerancia', 5)
            ->where('propina.data_limite', '2026-09-15')
            ->where('propina.cambio_usd', null)
            ->where('propina.origem_geracao_descricao', 'Manual')
            ->where('propina.plano.id', $plano->id)
            ->where('propina.plano.nome', 'Propina Mensal')
            ->where('propina.matricula.id', $matricula->id)
            ->where('propina.matricula.numero', $matricula->numero_registo_matricula)
            ->where('propina.matricula.estado_descricao', 'Activa')
            ->where('propina.matricula.ano_lectivo_nome', '2026/2027')
            ->where('propina.matricula.aluno_nome', 'Aluno de Teste')
            ->where('propina.cancelamento', null)
            ->where('propina.anulacao', null)
            ->has('propina.historico', 1)
            ->where('propina.historico.0.evento', 'Gerada')
            ->where('propina.pagamentos', [])
            ->where('propina.ajustes', [])
            ->where('propina.cancelamento_bloqueado', null)
            ->where('podeCancelar', true)
            ->has('moeda.codigo'));
    }

    public function test_uma_propina_cancelada_mostra_o_cancelamento_e_o_historico(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano, 2);
        $admin = $this->adminEscola();
        app(CancelarPropinaAction::class)->executar($propina, new CancelarPropinaDTO('Lançada por engano'), $admin->id);
        $this->actingAs($admin);

        $this->detalhe($propina->id)->assertOk()->assertInertia(fn (Assert $pagina) => $pagina
            ->where('propina.estado', EstadoCobranca::CANCELADA->value)
            ->where('propina.cancelamento.motivo', 'Lançada por engano')
            ->where('propina.cancelamento.por', $admin->name)
            // 10:00 UTC = 11:00 em Luanda (fuso da escola).
            ->where('propina.cancelamento.em', '2026-10-16 11:00')
            ->has('propina.historico', 2)
            ->where('propina.historico.1.evento', 'Cancelada')
            ->where('propina.historico.1.detalhe', 'Lançada por engano')
            ->where('propina.cancelamento_bloqueado', 'A propina já está cancelada.')
            ->where('podeCancelar', false));
    }

    public function test_uma_propina_com_pagamentos_nao_pode_ser_cancelada_e_diz_porque(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->comEstado($this->propina($matricula, $plano), EstadoCobranca::PARCIALMENTE_PAGA, 1_000_000);
        $this->actingAs($this->adminEscola());

        $this->detalhe($propina->id)->assertOk()->assertInertia(fn (Assert $pagina) => $pagina
            ->where('propina.valor_pago', 1_000_000)
            ->where('podeCancelar', false)
            ->where('propina.cancelamento_bloqueado', fn (string $razao) => str_contains($razao, 'Anule primeiro os pagamentos')));
    }

    public function test_sem_propina_cancelar_ve_o_detalhe_mas_nao_pode_cancelar(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);
        RolePermissao::query()
            ->where('role_id', Role::where('nome', Perfil::ADMIN_ESCOLA->value)->value('id'))
            ->where('modulo_id', ModuloRegistro::where('nome', Modulo::PROPINA->value)->value('id'))
            ->where('acao_id', Acao::where('nome', 'cancelar')->value('id'))
            ->delete();
        app(PermissaoCache::class)->invalidarTudo();
        $this->actingAs($this->adminEscola());

        $this->detalhe($propina->id)->assertOk()->assertInertia(fn (Assert $pagina) => $pagina
            ->where('propina.cancelamento_bloqueado', null)
            ->where('podeCancelar', false));
    }

    public function test_sem_propina_ver_da_403(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->actingAs($this->professor());

        $this->detalhe($this->propina($matricula, $plano)->id)->assertForbidden();
    }

    public function test_propina_de_outra_escola_da_404(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $dela = $this->noTenant($outro, function () {
            ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

            return $this->propina($matricula, $plano)->id;
        });
        $this->actingAs($this->adminEscola());

        $this->detalhe($dela)->assertNotFound();
    }

    public function test_nao_existe_eliminacao_nem_ids_nao_numericos(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);
        $this->actingAs($this->adminEscola());

        $this->delete('/financeiro/propinas/' . $propina->id)->assertStatus(405);
        $this->get('/financeiro/propinas/abc')->assertNotFound();
        $this->assertSame(1, Propina::query()->count());
    }
}
