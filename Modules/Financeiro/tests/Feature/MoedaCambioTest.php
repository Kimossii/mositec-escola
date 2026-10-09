<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Financeiro\Database\Seeders\CambioPlataformaSeeder;
use Modules\Financeiro\Models\Cambio;
use Modules\Financeiro\Models\ConfiguracaoMonetaria;
use Modules\Financeiro\Models\Produto;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Support\TaxaCambio;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

class MoedaCambioTest extends TestCase
{
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    private const BASE = 'financeiro.configuracao.moeda-cambio.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function cambio(string $data, string $taxa, string $moeda = 'AOA'): Cambio
    {
        return Cambio::create([
            'moeda_cotada' => $moeda, 'moeda_base' => 'USD', 'data' => $data,
            'taxa' => TaxaCambio::deDecimal($taxa)->micros(),
        ]);
    }

    public function test_show_mostra_a_configuracao_e_o_cambio_padrao(): void
    {
        $this->seed(CambioPlataformaSeeder::class);

        $this->actingAs($this->adminEscola())->get(route(self::BASE . 'show'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Financeiro/MoedaCambio/Edit')
                ->where('configuracao.moeda', 'AOA')
                ->where('configuracao.cambio_manual', false)
                ->where('configuracao.pode_alterar_moeda', true)
                ->where('moeda.simbolo', 'Kz')
                ->where('moeda.decimais', 2)
                ->has('moedas')
                ->where('cambioVigente.taxa', '910,00')
                ->where('cambioVigente.origem', 'plataforma')
                ->has('historico.data', 0)
                ->missing('configuracao.tenant_id'));
    }

    public function test_show_sem_cambio_devolve_nulo(): void
    {
        $this->actingAs($this->adminEscola())->get(route(self::BASE . 'show'))
            ->assertInertia(fn (Assert $page) => $page->where('cambioVigente', null));
    }

    public function test_show_com_usd_vale_um(): void
    {
        ConfiguracaoMonetaria::doTenant()->update(['moeda' => 'USD']);

        $this->actingAs($this->adminEscola())->get(route(self::BASE . 'show'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('cambioVigente.taxa', '1,00')
                ->where('cambioVigente.origem', 'usd'));
    }

    public function test_show_indica_que_a_moeda_esta_bloqueada_com_preco_configurado(): void
    {
        Produto::create(['nome' => 'P', 'preco' => Dinheiro::deUnidadesMenores(100)]);

        $this->actingAs($this->adminEscola())->get(route(self::BASE . 'show'))
            ->assertInertia(fn (Assert $page) => $page->where('configuracao.pode_alterar_moeda', false));
    }

    public function test_historico_so_mostra_a_moeda_actual_do_mais_recente_para_o_mais_antigo(): void
    {
        $this->cambio('2026-10-01', '910');
        $this->cambio('2026-10-03', '915,5');
        $this->cambio('2026-10-02', '930', 'EUR');
        ConfiguracaoMonetaria::doTenant()->update(['cambio_manual' => true]);

        $this->actingAs($this->adminEscola())->get(route(self::BASE . 'show'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('historico.data', 2)
                ->where('historico.data.0.taxa_formatada', '915,50')
                ->where('historico.data.0.taxa', 915_500_000)
                ->where('historico.data.1.taxa_formatada', '910,00')
                ->where('cambioVigente.origem', 'escola'));
    }

    public function test_altera_a_moeda_e_o_modo_manual_quando_nada_a_bloqueia(): void
    {
        $this->actingAs($this->adminEscola())
            ->put(route(self::BASE . 'atualizar'), ['moeda' => 'MZN', 'cambio_manual' => true])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $configuracao = ConfiguracaoMonetaria::doTenant();
        $this->assertSame('MZN', $configuracao->moeda);
        $this->assertTrue($configuracao->cambio_manual);
    }

    public function test_moeda_bloqueada_nao_muda_mas_o_modo_manual_sim(): void
    {
        Produto::create(['nome' => 'P', 'preco' => Dinheiro::deUnidadesMenores(100)]);
        $this->actingAs($this->adminEscola())->from('/x');

        $this->put(route(self::BASE . 'atualizar'), ['moeda' => 'EUR', 'cambio_manual' => true])
            ->assertSessionHasErrors(['moeda' => 'Não é possível alterar a moeda: já existem preços configurados ou registos financeiros.']);
        $this->assertSame('AOA', ConfiguracaoMonetaria::doTenant()->moeda);
        $this->assertFalse(ConfiguracaoMonetaria::doTenant()->cambio_manual);

        $this->put(route(self::BASE . 'atualizar'), ['moeda' => 'AOA', 'cambio_manual' => true])
            ->assertSessionHasNoErrors();
        $this->assertTrue(ConfiguracaoMonetaria::doTenant()->cambio_manual);
    }

    public function test_moeda_invalida_e_campos_em_falta_sao_rejeitados(): void
    {
        $this->actingAs($this->adminEscola())->from('/x');

        $this->put(route(self::BASE . 'atualizar'), ['moeda' => 'XXX', 'cambio_manual' => true])->assertSessionHasErrors('moeda');
        $this->assertFalse(ConfiguracaoMonetaria::doTenant()->cambio_manual);
        $this->put(route(self::BASE . 'atualizar'), [])->assertSessionHasErrors(['moeda', 'cambio_manual']);
        $this->put(route(self::BASE . 'atualizar'), ['moeda' => 'AOA', 'cambio_manual' => 'talvez'])->assertSessionHasErrors('cambio_manual');

        $this->assertSame('AOA', ConfiguracaoMonetaria::doTenant()->moeda);
    }

    public function test_regista_um_cambio_e_o_mesmo_dia_actualiza_a_linha(): void
    {
        $admin = $this->adminEscola();
        $this->actingAs($admin);

        $this->post(route(self::BASE . 'cambios.store'), ['data' => '2026-10-01', 'taxa' => '910'])
            ->assertSessionHasNoErrors();
        $this->post(route(self::BASE . 'cambios.store'), ['data' => '2026-10-01', 'taxa' => '915,5'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Cambio::count());
        $cambio = Cambio::first();
        $this->assertSame('AOA', $cambio->moeda_cotada);
        $this->assertSame('USD', $cambio->moeda_base);
        $this->assertSame(915_500_000, $cambio->taxa);
        $this->assertSame($this->tenant->id, $cambio->tenant_id);
        $this->assertSame($admin->id, $cambio->criado_por);
    }

    public function test_cambio_com_data_futura_taxa_invalida_ou_em_falta_e_rejeitado(): void
    {
        $this->actingAs($this->adminEscola())->from('/x');

        $this->post(route(self::BASE . 'cambios.store'), ['data' => now()->addDay()->toDateString(), 'taxa' => '910'])->assertSessionHasErrors('data');
        $this->post(route(self::BASE . 'cambios.store'), ['data' => '2026-10-01', 'taxa' => '0'])->assertSessionHasErrors('taxa');
        $this->post(route(self::BASE . 'cambios.store'), ['data' => '2026-10-01', 'taxa' => '910,1234567'])->assertSessionHasErrors('taxa');
        $this->post(route(self::BASE . 'cambios.store'), ['data' => 'abc', 'taxa' => '910'])->assertSessionHasErrors('data');
        $this->post(route(self::BASE . 'cambios.store'), [])->assertSessionHasErrors(['data', 'taxa']);

        $this->assertSame(0, Cambio::count());
    }

    public function test_com_usd_como_moeda_nao_se_regista_cambio(): void
    {
        ConfiguracaoMonetaria::doTenant()->update(['moeda' => 'USD']);

        $this->actingAs($this->adminEscola())->from('/x')
            ->post(route(self::BASE . 'cambios.store'), ['data' => '2026-10-01', 'taxa' => '910'])
            ->assertSessionHasErrors(['taxa' => 'Com USD como moeda da escola não é preciso registar câmbio: vale sempre 1.']);

        $this->assertSame(0, Cambio::count());
    }

    public function test_elimina_um_cambio(): void
    {
        $cambio = $this->cambio('2026-10-01', '910');

        $this->actingAs($this->adminEscola())
            ->delete(route(self::BASE . 'cambios.destroy', $cambio))
            ->assertSessionHasNoErrors();

        $this->assertNull(Cambio::find($cambio->id));
    }

    public function test_cambio_de_outro_tenant_da_404_e_nada_muda(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $doOutro = $this->noTenant($outro, fn () => $this->cambio('2026-10-01', '910'));
        $this->actingAs($this->adminEscola());

        $this->delete(route(self::BASE . 'cambios.destroy', $doOutro->id))->assertNotFound();

        $this->assertSame(910_000_000, (int) DB::table('cambios')->where('id', $doOutro->id)->value('taxa'));
    }

    public function test_professor_recebe_403_em_todas_as_rotas(): void
    {
        $cambio = $this->cambio('2026-10-01', '910');
        $this->actingAs($this->professor());

        $this->get(route(self::BASE . 'show'))->assertForbidden();
        $this->put(route(self::BASE . 'atualizar'), ['moeda' => 'AOA', 'cambio_manual' => false])->assertForbidden();
        $this->post(route(self::BASE . 'cambios.store'), ['data' => '2026-10-01', 'taxa' => '910'])->assertForbidden();
        $this->delete(route(self::BASE . 'cambios.destroy', $cambio))->assertForbidden();
    }

    public function test_tenant_id_forjado_no_payload_e_ignorado(): void
    {
        $outro = $this->criarTenant('MOSI-000003', 'Escola C', 'c.localhost');

        $this->actingAs($this->adminEscola())
            ->post(route(self::BASE . 'cambios.store'), ['data' => '2026-10-01', 'taxa' => '910', 'tenant_id' => $outro->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->tenant->id, Cambio::first()->tenant_id);
    }

    public function test_mensagens_ao_utilizador_em_portugues(): void
    {
        $this->actingAs($this->adminEscola());

        $this->put(route(self::BASE . 'atualizar'), ['moeda' => 'AOA', 'cambio_manual' => false])
            ->assertSessionHas('success', 'Moeda e câmbio atualizados com sucesso.');
        $this->post(route(self::BASE . 'cambios.store'), ['data' => '2026-10-01', 'taxa' => '910'])
            ->assertSessionHas('success', 'Câmbio registado com sucesso.');
        $this->delete(route(self::BASE . 'cambios.destroy', Cambio::first()))
            ->assertSessionHas('success', 'Câmbio eliminado com sucesso.');
    }
}
