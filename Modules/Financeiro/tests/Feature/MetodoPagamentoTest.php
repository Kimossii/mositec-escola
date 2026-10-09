<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Enums\Estado;
use Modules\Financeiro\Contracts\ReferenciaFinanceira;
use Modules\Financeiro\Enums\TipoMetodoPagamento;
use Modules\Financeiro\Models\MetodoPagamento;
use Modules\Financeiro\Support\ReferenciasFinanceiras;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

class MetodoPagamentoTest extends TestCase
{
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function metodo(string $nome = 'Numerário', TipoMetodoPagamento $tipo = TipoMetodoPagamento::NUMERARIO): MetodoPagamento
    {
        return MetodoPagamento::create(['nome' => $nome, 'tipo' => $tipo]);
    }

    private function metodoNoutroTenant(string $nome = 'Numerário'): MetodoPagamento
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        return $this->noTenant($outro, fn () => MetodoPagamento::create(['nome' => $nome, 'tipo' => TipoMetodoPagamento::NUMERARIO]));
    }

    public function test_index_lista_so_os_metodos_do_tenant_e_expoe_os_tipos(): void
    {
        $this->metodo('Numerário');
        $this->metodoNoutroTenant('Metodo Do Outro');

        $this->actingAs($this->adminEscola())
            ->get(route('financeiro.configuracao.metodos-pagamento.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Financeiro/MetodosPagamento/Index')
                ->has('metodos.data', 1)
                ->where('metodos.data.0.nome', 'Numerário')
                ->where('metodos.data.0.tipo_descricao', 'Numerário')
                ->missing('metodos.data.0.tenant_id')
                ->has('tipos', 5));
    }

    public function test_index_pesquisa_por_nome_e_filtra_por_estado(): void
    {
        $this->metodo('Numerário');
        $this->metodo('Multicaixa Express', TipoMetodoPagamento::MULTICAIXA)->update(['estado' => Estado::INATIVO->value]);

        $this->actingAs($this->adminEscola());

        $this->get(route('financeiro.configuracao.metodos-pagamento.index', ['pesquisa' => 'multi']))
            ->assertInertia(fn (Assert $page) => $page->has('metodos.data', 1)->where('metodos.data.0.nome', 'Multicaixa Express'));

        $this->get(route('financeiro.configuracao.metodos-pagamento.index', ['estado' => '0']))
            ->assertInertia(fn (Assert $page) => $page->has('metodos.data', 1)->where('metodos.data.0.estado_descricao', 'Inativo'));
    }

    public function test_cria_metodo_com_tipo_descricao_estado_activo_e_autoria(): void
    {
        $admin = $this->adminEscola();

        $this->actingAs($admin)
            ->post(route('financeiro.configuracao.metodos-pagamento.store'), ['nome' => 'BAI Transferência', 'tipo' => TipoMetodoPagamento::TRANSFERENCIA_BANCARIA->value])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $metodo = MetodoPagamento::firstWhere('nome', 'BAI Transferência');
        $this->assertNotNull($metodo);
        $this->assertSame(TipoMetodoPagamento::TRANSFERENCIA_BANCARIA, $metodo->tipo);
        $this->assertSame('Transferência Bancária', $metodo->tipo_descricao);
        $this->assertSame(1, $metodo->estado);
        $this->assertSame('Ativo', $metodo->estado_descricao);
        $this->assertSame($this->tenant->id, $metodo->tenant_id);
        $this->assertSame($admin->id, $metodo->criado_por);
    }

    public function test_nome_e_tipo_sao_obrigatorios_e_tipo_tem_de_existir(): void
    {
        $this->actingAs($this->adminEscola())->from('/x')
            ->post(route('financeiro.configuracao.metodos-pagamento.store'), [])
            ->assertSessionHasErrors(['nome', 'tipo']);

        $this->post(route('financeiro.configuracao.metodos-pagamento.store'), ['nome' => 'X', 'tipo' => 99])
            ->assertSessionHasErrors('tipo');

        $this->assertSame(0, MetodoPagamento::count());
    }

    public function test_nome_unico_por_tenant_mas_repetivel_noutro_tenant(): void
    {
        $this->metodoNoutroTenant('Numerário');
        $this->actingAs($this->adminEscola());

        $this->post(route('financeiro.configuracao.metodos-pagamento.store'), ['nome' => 'Numerário', 'tipo' => 1])
            ->assertSessionHasNoErrors();

        $this->from('/x')->post(route('financeiro.configuracao.metodos-pagamento.store'), ['nome' => 'Numerário', 'tipo' => 1])
            ->assertSessionHasErrors('nome');

        $this->assertSame(1, MetodoPagamento::count());
    }

    public function test_actualiza_mantendo_o_proprio_nome_sem_falhar(): void
    {
        $metodo = $this->metodo('TPA Loja', TipoMetodoPagamento::TPA);

        $this->actingAs($this->adminEscola())
            ->put(route('financeiro.configuracao.metodos-pagamento.update', $metodo), ['nome' => 'TPA Loja', 'tipo' => TipoMetodoPagamento::OUTRO->value])
            ->assertSessionHasNoErrors();

        $metodo->refresh();
        $this->assertSame(TipoMetodoPagamento::OUTRO, $metodo->tipo);
        $this->assertSame('Outro', $metodo->tipo_descricao);
    }

    public function test_actualizar_com_nome_de_outro_metodo_do_tenant_falha(): void
    {
        $this->metodo('Numerário');
        $outro = $this->metodo('TPA', TipoMetodoPagamento::TPA);

        $this->actingAs($this->adminEscola())->from('/x')
            ->put(route('financeiro.configuracao.metodos-pagamento.update', $outro), ['nome' => 'Numerário', 'tipo' => 3])
            ->assertSessionHasErrors('nome');
    }

    public function test_desactiva_e_reactiva_mantendo_a_descricao_do_estado(): void
    {
        $metodo = $this->metodo();
        $this->actingAs($this->adminEscola());

        $this->patch(route('financeiro.configuracao.metodos-pagamento.alterar-estado', $metodo), ['estado' => 0])->assertSessionHasNoErrors();
        $this->assertSame('Inativo', $metodo->fresh()->estado_descricao);

        $this->patch(route('financeiro.configuracao.metodos-pagamento.alterar-estado', $metodo), ['estado' => 1])->assertSessionHasNoErrors();
        $this->assertSame('Ativo', $metodo->fresh()->estado_descricao);
    }

    public function test_elimina_quando_nao_ha_referencias(): void
    {
        $metodo = $this->metodo();

        $this->actingAs($this->adminEscola())
            ->delete(route('financeiro.configuracao.metodos-pagamento.destroy', $metodo))
            ->assertSessionHasNoErrors();

        $this->assertNull(MetodoPagamento::find($metodo->id));
    }

    public function test_eliminar_com_referencia_financeira_e_bloqueado(): void
    {
        $metodo = $this->metodo();
        $this->app->instance('ref.fake', new class implements ReferenciaFinanceira {
            public function existeReferenciaA(Model $configuracao): bool
            {
                return true;
            }
        });
        $this->app->tag(['ref.fake'], ReferenciasFinanceiras::ETIQUETA);

        $this->actingAs($this->adminEscola())->from('/x')
            ->delete(route('financeiro.configuracao.metodos-pagamento.destroy', $metodo))
            ->assertSessionHasErrors('eliminar');

        $this->assertNotNull(MetodoPagamento::find($metodo->id));
    }

    public function test_professor_recebe_403_em_todas_as_rotas(): void
    {
        $metodo = $this->metodo();
        $this->actingAs($this->professor());

        $this->get(route('financeiro.configuracao.metodos-pagamento.index'))->assertForbidden();
        $this->post(route('financeiro.configuracao.metodos-pagamento.store'), ['nome' => 'X', 'tipo' => 1])->assertForbidden();
        $this->put(route('financeiro.configuracao.metodos-pagamento.update', $metodo), ['nome' => 'X', 'tipo' => 1])->assertForbidden();
        $this->patch(route('financeiro.configuracao.metodos-pagamento.alterar-estado', $metodo), ['estado' => 0])->assertForbidden();
        $this->delete(route('financeiro.configuracao.metodos-pagamento.destroy', $metodo))->assertForbidden();
    }

    public function test_registo_de_outro_tenant_da_404_e_nada_muda(): void
    {
        $doOutro = $this->metodoNoutroTenant('Do Outro');
        $this->actingAs($this->adminEscola());

        $this->put(route('financeiro.configuracao.metodos-pagamento.update', $doOutro->id), ['nome' => 'Alterado', 'tipo' => 1])->assertNotFound();
        $this->patch(route('financeiro.configuracao.metodos-pagamento.alterar-estado', $doOutro->id), ['estado' => 0])->assertNotFound();
        $this->delete(route('financeiro.configuracao.metodos-pagamento.destroy', $doOutro->id))->assertNotFound();

        $linha = DB::table('metodos_pagamento')->where('id', $doOutro->id)->first();
        $this->assertSame('Do Outro', $linha->nome);
        $this->assertSame(1, (int) $linha->estado);
    }

    public function test_tenant_id_forjado_no_payload_e_ignorado(): void
    {
        $outro = $this->criarTenant('MOSI-000003', 'Escola C', 'c.localhost');

        $this->actingAs($this->adminEscola())
            ->post(route('financeiro.configuracao.metodos-pagamento.store'), ['nome' => 'Forjado', 'tipo' => 1, 'tenant_id' => $outro->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->tenant->id, MetodoPagamento::firstWhere('nome', 'Forjado')->tenant_id);
    }

    public function test_filtro_de_estado_invalido_e_ignorado(): void
    {
        $this->metodo('Numerário');
        $this->metodo('TPA', TipoMetodoPagamento::TPA);

        $this->actingAs($this->adminEscola())
            ->get(route('financeiro.configuracao.metodos-pagamento.index', ['estado' => 'abc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('metodos.data', 2));
    }

    public function test_pesquisa_em_formato_de_lista_e_ignorada(): void
    {
        $this->metodo('Numerário');
        $this->metodo('TPA', TipoMetodoPagamento::TPA);

        $this->actingAs($this->adminEscola())
            ->get(route('financeiro.configuracao.metodos-pagamento.index', ['pesquisa' => ['x']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('metodos.data', 2));
    }

    public function test_scope_activos_exclui_os_desactivados_mas_o_count_inclui(): void
    {
        $this->metodo('Numerário');
        $inactivo = $this->metodo('TPA', TipoMetodoPagamento::TPA);
        $inactivo->update(['estado' => Estado::INATIVO->value]);

        $this->assertSame(2, MetodoPagamento::count());
        $this->assertSame(['Numerário'], MetodoPagamento::activos()->pluck('nome')->all());
    }
}
