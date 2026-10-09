<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Financeiro\Models\Produto;
use Modules\Financeiro\Models\Servico;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

class CatalogoFinanceiroTest extends TestCase
{
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function produto(string $nome, int $centimos = 100, ?string $codigo = null): Produto
    {
        return Produto::create(['nome' => $nome, 'codigo' => $codigo, 'preco' => Dinheiro::deCentimos($centimos)]);
    }

    private function servico(string $nome, int $centimos = 100, ?string $codigo = null): Servico
    {
        return Servico::create(['nome' => $nome, 'codigo' => $codigo, 'preco' => Dinheiro::deCentimos($centimos)]);
    }

    private function url(array $filtros = []): string
    {
        return route('financeiro.configuracao.produtos-servicos.index', $filtros);
    }

    public function test_junta_produtos_e_servicos_ordenados_por_nome_com_o_tipo(): void
    {
        $this->produto('Uniforme Escolar', 2_500_000, 'UNI-001');
        $this->servico('Emissão de Certificado', 500_000, 'SER-001');
        $this->produto('Caderno', 50_000);

        $this->actingAs($this->adminEscola())->get($this->url())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Financeiro/ProdutosServicos/Index')
                ->has('itens.data', 3)
                ->where('itens.data.0.nome', 'Caderno')
                ->where('itens.data.0.tipo', 'produto')
                ->where('itens.data.1.nome', 'Emissão de Certificado')
                ->where('itens.data.1.tipo', 'servico')
                ->where('itens.data.1.preco', 500_000)
                ->where('itens.data.2.nome', 'Uniforme Escolar')
                ->missing('itens.data.0.tenant_id'));
    }

    public function test_filtra_por_tipo(): void
    {
        $this->produto('P1');
        $this->servico('S1');
        $this->actingAs($this->adminEscola());

        $this->get($this->url(['tipo' => 'produto']))
            ->assertInertia(fn (Assert $page) => $page->has('itens.data', 1)->where('itens.data.0.tipo', 'produto'));
        $this->get($this->url(['tipo' => 'servico']))
            ->assertInertia(fn (Assert $page) => $page->has('itens.data', 1)->where('itens.data.0.tipo', 'servico'));
    }

    public function test_pesquisa_por_nome_ou_codigo_e_filtra_por_estado_sem_esconder_inactivos(): void
    {
        $this->produto('Uniforme', 100, 'UNI-001');
        $this->servico('Certificado', 100, 'SER-001')->update(['estado' => 0]);
        $this->actingAs($this->adminEscola());

        $this->get($this->url(['pesquisa' => 'uni-0']))
            ->assertInertia(fn (Assert $page) => $page->has('itens.data', 1)->where('itens.data.0.nome', 'Uniforme'));

        $this->get($this->url())
            ->assertInertia(fn (Assert $page) => $page->has('itens.data', 2));

        $this->get($this->url(['estado' => '0']))
            ->assertInertia(fn (Assert $page) => $page->has('itens.data', 1)->where('itens.data.0.estado_descricao', 'Inativo'));
    }

    public function test_isola_por_tenant(): void
    {
        $this->produto('Meu');
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, function () {
            $this->produto('Do Outro');
            $this->servico('Serviço Do Outro');
        });

        $this->actingAs($this->adminEscola())->get($this->url())
            ->assertInertia(fn (Assert $page) => $page->has('itens.data', 1)->where('itens.data.0.nome', 'Meu'));
    }

    public function test_pagina_os_resultados_do_catalogo_unificado(): void
    {
        foreach (range(1, 6) as $n) {
            $this->produto(sprintf('Produto %02d', $n));
        }
        foreach (range(1, 5) as $n) {
            $this->servico(sprintf('Servico %02d', $n));
        }
        $this->actingAs($this->adminEscola());

        $this->get($this->url())
            ->assertInertia(fn (Assert $page) => $page->has('itens.data', 10)->where('itens.total', 11));

        $this->get($this->url(['page' => 2]))
            ->assertInertia(fn (Assert $page) => $page->has('itens.data', 1)->where('itens.current_page', 2));
    }

    public function test_professor_recebe_403(): void
    {
        $this->actingAs($this->professor())->get($this->url())->assertForbidden();
    }

    public function test_ordena_sem_considerar_acentos(): void
    {
        $this->produto('Zebra');
        $this->servico('Água');
        $this->produto('Caderno');
        $this->servico('Época');

        $this->actingAs($this->adminEscola())->get($this->url())
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('itens.data.0.nome', 'Água')
                ->where('itens.data.1.nome', 'Caderno')
                ->where('itens.data.2.nome', 'Época')
                ->where('itens.data.3.nome', 'Zebra'));
    }

    public function test_filtro_de_estado_invalido_e_ignorado(): void
    {
        $this->produto('P1');
        $this->servico('S1');

        $this->actingAs($this->adminEscola())->get($this->url(['estado' => 'abc']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('itens.data', 2));
    }

    public function test_pesquisa_em_formato_de_lista_e_ignorada(): void
    {
        $this->produto('P1');
        $this->servico('S1');

        $this->actingAs($this->adminEscola())->get($this->url(['pesquisa' => ['x']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('itens.data', 2));
    }
}
