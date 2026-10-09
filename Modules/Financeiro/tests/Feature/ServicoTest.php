<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Financeiro\Contracts\ReferenciaFinanceira;
use Modules\Financeiro\Models\Servico;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Support\ReferenciasFinanceiras;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServicoTest extends TestCase
{
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function servico(string $nome = 'Emissão de Certificado', ?string $codigo = 'SER-001', int $centimos = 2_500_000): Servico
    {
        return Servico::create(['nome' => $nome, 'codigo' => $codigo, 'preco' => Dinheiro::deCentimos($centimos)]);
    }

    private function servicoNoutroTenant(string $nome = 'Do Outro', ?string $codigo = 'SER-001'): Servico
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        return $this->noTenant($outro, fn () => Servico::create(['nome' => $nome, 'codigo' => $codigo, 'preco' => Dinheiro::deCentimos(100)]));
    }

    public function test_cria_servico_com_preco_em_centimos_estado_activo_e_autoria(): void
    {
        $admin = $this->adminEscola();

        $this->actingAs($admin)
            ->post(route('financeiro.configuracao.servicos.store'), [
                'nome' => 'Emissão de Certificado', 'descricao' => 'Certificado com selo', 'codigo' => 'SER-001', 'preco' => '25000',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $servico = Servico::firstWhere('codigo', 'SER-001');
        $this->assertNotNull($servico);
        $this->assertSame(2_500_000, $servico->preco->centimos());
        $this->assertSame(2_500_000, (int) DB::table('servicos')->where('id', $servico->id)->value('preco'));
        $this->assertSame('Certificado com selo', $servico->descricao);
        $this->assertSame('Ativo', $servico->estado_descricao);
        $this->assertSame($this->tenant->id, $servico->tenant_id);
        $this->assertSame($admin->id, $servico->criado_por);
    }

    public function test_codigo_e_descricao_sao_opcionais(): void
    {
        $this->actingAs($this->adminEscola());

        $this->post(route('financeiro.configuracao.servicos.store'), ['nome' => 'Declaração A', 'preco' => '500'])->assertSessionHasNoErrors();
        $this->post(route('financeiro.configuracao.servicos.store'), ['nome' => 'Declaração B', 'preco' => '600'])->assertSessionHasNoErrors();

        $this->assertSame(2, Servico::whereNull('codigo')->count());
    }

    #[DataProvider('precosAceites')]
    public function test_precos_validos_sao_gravados_em_centimos_exactos(string $entrada, int $centimos): void
    {
        $this->actingAs($this->adminEscola())
            ->post(route('financeiro.configuracao.servicos.store'), ['nome' => 'Item', 'preco' => $entrada])
            ->assertSessionHasNoErrors();

        $this->assertSame($centimos, Servico::firstWhere('nome', 'Item')->preco->centimos());
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
            ->post(route('financeiro.configuracao.servicos.store'), ['nome' => 'Item', 'preco' => $entrada])
            ->assertSessionHasErrors('preco');

        $this->assertSame(0, Servico::count());
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
            ->post(route('financeiro.configuracao.servicos.store'), [])
            ->assertSessionHasErrors(['nome', 'preco']);
    }

    public function test_codigo_unico_por_tenant_mas_repetivel_noutro_tenant(): void
    {
        $this->servicoNoutroTenant('Do Outro', 'SER-001');
        $this->actingAs($this->adminEscola());

        $this->post(route('financeiro.configuracao.servicos.store'), ['nome' => 'A', 'codigo' => 'SER-001', 'preco' => '1'])
            ->assertSessionHasNoErrors();

        $this->from('/x')->post(route('financeiro.configuracao.servicos.store'), ['nome' => 'B', 'codigo' => 'SER-001', 'preco' => '1'])
            ->assertSessionHasErrors('codigo');

        $this->assertSame(1, Servico::count());
    }

    public function test_actualiza_mantendo_o_proprio_codigo_e_altera_o_preco(): void
    {
        $servico = $this->servico();

        $this->actingAs($this->adminEscola())
            ->put(route('financeiro.configuracao.servicos.update', $servico), [
                'nome' => 'Certificado Novo', 'codigo' => 'SER-001', 'preco' => '30000',
            ])
            ->assertSessionHasNoErrors();

        $servico->refresh();
        $this->assertSame('Certificado Novo', $servico->nome);
        $this->assertSame(3_000_000, $servico->preco->centimos());
    }

    public function test_actualizar_com_codigo_de_outro_servico_do_tenant_falha(): void
    {
        $this->servico('A', 'SER-001');
        $outro = $this->servico('B', 'SER-002');

        $this->actingAs($this->adminEscola())->from('/x')
            ->put(route('financeiro.configuracao.servicos.update', $outro), ['nome' => 'B', 'codigo' => 'SER-001', 'preco' => '1'])
            ->assertSessionHasErrors('codigo');
    }

    public function test_desactivar_mantem_o_registo_e_tira_o_servico_de_activos(): void
    {
        $servico = $this->servico();
        $this->servico('Outro', 'SER-002');

        $this->actingAs($this->adminEscola())
            ->patch(route('financeiro.configuracao.servicos.alterar-estado', $servico), ['estado' => 0])
            ->assertSessionHasNoErrors();

        $servico->refresh();
        $this->assertSame('Inativo', $servico->estado_descricao);
        $this->assertSame(2, Servico::count());
        $this->assertSame(['Outro'], Servico::activos()->pluck('nome')->all());

        $this->patch(route('financeiro.configuracao.servicos.alterar-estado', $servico), ['estado' => 1]);
        $this->assertSame(2, Servico::activos()->count());
    }

    public function test_elimina_sem_referencias_e_bloqueia_com_referencia(): void
    {
        $servico = $this->servico();
        $this->actingAs($this->adminEscola());

        $this->app->instance('ref.fake', new class implements ReferenciaFinanceira {
            public function existeReferenciaA(Model $configuracao): bool
            {
                return true;
            }
        });
        $this->app->tag(['ref.fake'], ReferenciasFinanceiras::ETIQUETA);

        $this->from('/x')->delete(route('financeiro.configuracao.servicos.destroy', $servico))->assertSessionHasErrors('eliminar');
        $this->assertNotNull(Servico::find($servico->id));
    }

    public function test_elimina_quando_nao_ha_referencias(): void
    {
        $servico = $this->servico();

        $this->actingAs($this->adminEscola())
            ->delete(route('financeiro.configuracao.servicos.destroy', $servico))
            ->assertSessionHasNoErrors();

        $this->assertNull(Servico::find($servico->id));
    }

    public function test_mensagens_ao_utilizador_usam_o_texto_correcto_em_portugues(): void
    {
        $this->actingAs($this->adminEscola());

        $this->post(route('financeiro.configuracao.servicos.store'), ['nome' => 'Msg', 'codigo' => 'MSG-1', 'preco' => '1'])
            ->assertSessionHas('success', 'Serviço criado com sucesso.');
        $servico = Servico::firstWhere('codigo', 'MSG-1');

        $this->put(route('financeiro.configuracao.servicos.update', $servico), ['nome' => 'Msg', 'codigo' => 'MSG-1', 'preco' => '2'])
            ->assertSessionHas('success', 'Serviço atualizado com sucesso.');
        $this->patch(route('financeiro.configuracao.servicos.alterar-estado', $servico), ['estado' => 0])
            ->assertSessionHas('success', 'Estado do serviço atualizado com sucesso.');

        $this->from('/x')->post(route('financeiro.configuracao.servicos.store'), [])
            ->assertSessionHasErrors(['nome' => 'O nome do serviço é obrigatório.']);
        $this->from('/x')->post(route('financeiro.configuracao.servicos.store'), ['nome' => 'Outro', 'codigo' => 'MSG-1', 'preco' => '1'])
            ->assertSessionHasErrors(['codigo' => 'Já existe um serviço com este código.']);

        $this->delete(route('financeiro.configuracao.servicos.destroy', $servico))
            ->assertSessionHas('success', 'Serviço eliminado com sucesso.');

        $bloqueado = $this->servico('Bloq', 'MSG-2');
        $this->app->instance('ref.fake', new class implements ReferenciaFinanceira {
            public function existeReferenciaA(Model $configuracao): bool
            {
                return true;
            }
        });
        $this->app->tag(['ref.fake'], ReferenciasFinanceiras::ETIQUETA);

        $this->from('/x')->delete(route('financeiro.configuracao.servicos.destroy', $bloqueado))
            ->assertSessionHasErrors(['eliminar' => 'Não é possível eliminar este serviço: já existem registos financeiros que o utilizam. Desative-o.']);
    }

    public function test_professor_recebe_403_em_todas_as_rotas(): void
    {
        $servico = $this->servico();
        $this->actingAs($this->professor());

        $this->post(route('financeiro.configuracao.servicos.store'), ['nome' => 'X', 'preco' => '1'])->assertForbidden();
        $this->put(route('financeiro.configuracao.servicos.update', $servico), ['nome' => 'X', 'preco' => '1'])->assertForbidden();
        $this->patch(route('financeiro.configuracao.servicos.alterar-estado', $servico), ['estado' => 0])->assertForbidden();
        $this->delete(route('financeiro.configuracao.servicos.destroy', $servico))->assertForbidden();
    }

    public function test_registo_de_outro_tenant_da_404_e_nada_muda(): void
    {
        $doOutro = $this->servicoNoutroTenant();
        $this->actingAs($this->adminEscola());

        $this->put(route('financeiro.configuracao.servicos.update', $doOutro->id), ['nome' => 'Alterado', 'preco' => '1'])->assertNotFound();
        $this->patch(route('financeiro.configuracao.servicos.alterar-estado', $doOutro->id), ['estado' => 0])->assertNotFound();
        $this->delete(route('financeiro.configuracao.servicos.destroy', $doOutro->id))->assertNotFound();

        $linha = DB::table('servicos')->where('id', $doOutro->id)->first();
        $this->assertSame('Do Outro', $linha->nome);
        $this->assertSame(1, (int) $linha->estado);
    }

    public function test_tenant_id_forjado_no_payload_e_ignorado(): void
    {
        $outro = $this->criarTenant('MOSI-000003', 'Escola C', 'c.localhost');

        $this->actingAs($this->adminEscola())
            ->post(route('financeiro.configuracao.servicos.store'), ['nome' => 'Forjado', 'preco' => '1', 'tenant_id' => $outro->id])
            ->assertSessionHasNoErrors();

        $this->assertSame($this->tenant->id, Servico::firstWhere('nome', 'Forjado')->tenant_id);
    }

    public function test_preco_serializa_como_inteiro_em_centimos(): void
    {
        $this->assertSame(2_500_000, $this->servico()->toArray()['preco']);
    }
}
