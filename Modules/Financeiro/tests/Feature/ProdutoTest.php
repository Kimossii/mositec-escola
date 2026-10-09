<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Financeiro\Contracts\ReferenciaFinanceira;
use Modules\Financeiro\Models\Produto;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Support\ReferenciasFinanceiras;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProdutoTest extends TestCase
{
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function produto(string $nome = 'Uniforme Escolar', ?string $codigo = 'UNI-001', int $centimos = 2_500_000): Produto
    {
        return Produto::create(['nome' => $nome, 'codigo' => $codigo, 'preco' => Dinheiro::deUnidadesMenores($centimos)]);
    }

    private function produtoNoutroTenant(string $nome = 'Do Outro', ?string $codigo = 'UNI-001'): Produto
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        return $this->noTenant($outro, fn () => Produto::create(['nome' => $nome, 'codigo' => $codigo, 'preco' => Dinheiro::deUnidadesMenores(100)]));
    }

    public function test_cria_produto_com_preco_em_unidades_menores_estado_activo_e_autoria(): void
    {
        $admin = $this->adminEscola();

        $this->actingAs($admin)
            ->post(route('financeiro.configuracao.produtos.store'), [
                'nome' => 'Uniforme Escolar', 'descricao' => 'Camisa e calças', 'codigo' => 'UNI-001', 'preco' => '25000',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $produto = Produto::firstWhere('codigo', 'UNI-001');
        $this->assertNotNull($produto);
        $this->assertSame(2_500_000, $produto->preco->unidadesMenores());
        $this->assertSame(2_500_000, (int) DB::table('produtos')->where('id', $produto->id)->value('preco'));
        $this->assertSame('Camisa e calças', $produto->descricao);
        $this->assertSame('Ativo', $produto->estado_descricao);
        $this->assertSame($this->tenant->id, $produto->tenant_id);
        $this->assertSame($admin->id, $produto->criado_por);
    }

    public function test_preco_inteiro_gigante_em_json_da_erro_de_validacao_e_nao_500(): void
    {
        $this->actingAs($this->adminEscola());

        $this->postJson(route('financeiro.configuracao.produtos.store'), ['nome' => 'Gigante', 'preco' => 99999999999999999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('preco');
        $this->postJson(route('financeiro.configuracao.produtos.store'), ['nome' => 'Gigante', 'preco' => PHP_INT_MAX])
            ->assertStatus(422)
            ->assertJsonValidationErrors('preco');
    }

    public function test_codigo_e_descricao_sao_opcionais(): void
    {
        $this->actingAs($this->adminEscola());

        $this->post(route('financeiro.configuracao.produtos.store'), ['nome' => 'Caderno A', 'preco' => '500'])->assertSessionHasNoErrors();
        $this->post(route('financeiro.configuracao.produtos.store'), ['nome' => 'Caderno B', 'preco' => '600'])->assertSessionHasNoErrors();

        $this->assertSame(2, Produto::whereNull('codigo')->count());
    }

    #[DataProvider('precosAceites')]
    public function test_precos_validos_sao_gravados_em_unidades_menores_exactas(string $entrada, int $centimos): void
    {
        $this->actingAs($this->adminEscola())
            ->post(route('financeiro.configuracao.produtos.store'), ['nome' => 'Item', 'preco' => $entrada])
            ->assertSessionHasNoErrors();

        $this->assertSame($centimos, Produto::firstWhere('nome', 'Item')->preco->unidadesMenores());
    }

    public static function precosAceites(): array
    {
        return [
            'zero' => ['0', 0],
            'inteiro' => ['25000', 2_500_000],
            'uma casa' => ['25000.5', 2_500_050],
            'virgula' => ['25000,50', 2_500_050],
            'cêntimos' => ['0.05', 5],
        ];
    }

    #[DataProvider('precosRejeitados')]
    public function test_precos_invalidos_sao_rejeitados(string $entrada): void
    {
        $this->actingAs($this->adminEscola())->from('/x')
            ->post(route('financeiro.configuracao.produtos.store'), ['nome' => 'Item', 'preco' => $entrada])
            ->assertSessionHasErrors('preco');

        $this->assertSame(0, Produto::count());
    }

    public static function precosRejeitados(): array
    {
        return [
            'negativo' => ['-1'],
            'letras' => ['abc'],
            'três casas' => ['12.345'],
            'notação científica' => ['1e3'],
            'vazio' => [''],
            'milhares' => ['25.000,50'],
        ];
    }

    public function test_nome_e_preco_sao_obrigatorios(): void
    {
        $this->actingAs($this->adminEscola())->from('/x')
            ->post(route('financeiro.configuracao.produtos.store'), [])
            ->assertSessionHasErrors(['nome', 'preco']);
    }

    public function test_codigo_unico_por_tenant_mas_repetivel_noutro_tenant(): void
    {
        $this->produtoNoutroTenant('Do Outro', 'UNI-001');
        $this->actingAs($this->adminEscola());

        $this->post(route('financeiro.configuracao.produtos.store'), ['nome' => 'A', 'codigo' => 'UNI-001', 'preco' => '1'])
            ->assertSessionHasNoErrors();

        $this->from('/x')->post(route('financeiro.configuracao.produtos.store'), ['nome' => 'B', 'codigo' => 'UNI-001', 'preco' => '1'])
            ->assertSessionHasErrors('codigo');

        $this->assertSame(1, Produto::count());
    }

    public function test_actualiza_mantendo_o_proprio_codigo_e_altera_o_preco(): void
    {
        $produto = $this->produto();

        $this->actingAs($this->adminEscola())
            ->put(route('financeiro.configuracao.produtos.update', $produto), [
                'nome' => 'Uniforme Novo', 'codigo' => 'UNI-001', 'preco' => '30000',
            ])
            ->assertSessionHasNoErrors();

        $produto->refresh();
        $this->assertSame('Uniforme Novo', $produto->nome);
        $this->assertSame(3_000_000, $produto->preco->unidadesMenores());
    }

    public function test_actualizar_com_codigo_de_outro_produto_do_tenant_falha(): void
    {
        $this->produto('A', 'UNI-001');
        $outro = $this->produto('B', 'UNI-002');

        $this->actingAs($this->adminEscola())->from('/x')
            ->put(route('financeiro.configuracao.produtos.update', $outro), ['nome' => 'B', 'codigo' => 'UNI-001', 'preco' => '1'])
            ->assertSessionHasErrors('codigo');
    }

    public function test_desactivar_mantem_o_registo_e_tira_o_produto_de_activos(): void
    {
        $produto = $this->produto();
        $this->produto('Outro', 'UNI-002');

        $this->actingAs($this->adminEscola())
            ->patch(route('financeiro.configuracao.produtos.alterar-estado', $produto), ['estado' => 0])
            ->assertSessionHasNoErrors();

        $produto->refresh();
        $this->assertSame('Inativo', $produto->estado_descricao);
        $this->assertSame(2, Produto::count());
        $this->assertSame(['Outro'], Produto::activos()->pluck('nome')->all());

        $this->patch(route('financeiro.configuracao.produtos.alterar-estado', $produto), ['estado' => 1]);
        $this->assertSame(2, Produto::activos()->count());
    }

    public function test_elimina_sem_referencias_e_bloqueia_com_referencia(): void
    {
        $produto = $this->produto();
        $this->actingAs($this->adminEscola());

        $this->app->instance('ref.fake', new class implements ReferenciaFinanceira {
            public function existeReferenciaA(Model $configuracao): bool
            {
                return true;
            }
        });
        $this->app->tag(['ref.fake'], ReferenciasFinanceiras::ETIQUETA);

        $this->from('/x')->delete(route('financeiro.configuracao.produtos.destroy', $produto))->assertSessionHasErrors('eliminar');
        $this->assertNotNull(Produto::find($produto->id));
    }

    public function test_elimina_quando_nao_ha_referencias(): void
    {
        $produto = $this->produto();

        $this->actingAs($this->adminEscola())
            ->delete(route('financeiro.configuracao.produtos.destroy', $produto))
            ->assertSessionHasNoErrors();

        $this->assertNull(Produto::find($produto->id));
    }

    public function test_professor_recebe_403_em_todas_as_rotas(): void
    {
        $produto = $this->produto();
        $this->actingAs($this->professor());

        $this->post(route('financeiro.configuracao.produtos.store'), ['nome' => 'X', 'preco' => '1'])->assertForbidden();
        $this->put(route('financeiro.configuracao.produtos.update', $produto), ['nome' => 'X', 'preco' => '1'])->assertForbidden();
        $this->patch(route('financeiro.configuracao.produtos.alterar-estado', $produto), ['estado' => 0])->assertForbidden();
        $this->delete(route('financeiro.configuracao.produtos.destroy', $produto))->assertForbidden();
    }

    public function test_registo_de_outro_tenant_da_404_e_nada_muda(): void
    {
        $doOutro = $this->produtoNoutroTenant();
        $this->actingAs($this->adminEscola());

        $this->put(route('financeiro.configuracao.produtos.update', $doOutro->id), ['nome' => 'Alterado', 'preco' => '1'])->assertNotFound();
        $this->patch(route('financeiro.configuracao.produtos.alterar-estado', $doOutro->id), ['estado' => 0])->assertNotFound();
        $this->delete(route('financeiro.configuracao.produtos.destroy', $doOutro->id))->assertNotFound();

        $linha = DB::table('produtos')->where('id', $doOutro->id)->first();
        $this->assertSame('Do Outro', $linha->nome);
        $this->assertSame(1, (int) $linha->estado);
    }

    public function test_tenant_id_forjado_no_payload_e_ignorado(): void
    {
        $outro = $this->criarTenant('MOSI-000003', 'Escola C', 'c.localhost');

        $this->actingAs($this->adminEscola())
            ->post(route('financeiro.configuracao.produtos.store'), ['nome' => 'Forjado', 'preco' => '1', 'tenant_id' => $outro->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->tenant->id, Produto::firstWhere('nome', 'Forjado')->tenant_id);
    }

    public function test_preco_serializa_como_inteiro_em_unidades_menores(): void
    {
        $this->assertSame(2_500_000, $this->produto()->toArray()['preco']);
    }
}
