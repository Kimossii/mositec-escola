<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Financeiro\Contracts\FonteDePrecos;
use Modules\Financeiro\Contracts\ReferenciaFinanceira;
use Modules\Financeiro\Models\ConfiguracaoMonetaria;
use Modules\Financeiro\Models\Produto;
use Modules\Financeiro\Models\Servico;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Support\FontesDePrecos;
use Modules\Financeiro\Support\ReferenciasFinanceiras;
use Tests\TestCase;

class MoedaDoTenantTest extends TestCase
{
    use RefreshDatabase;

    private function servico(): MoedaDoTenant
    {
        return app(MoedaDoTenant::class);
    }

    public function test_a_moeda_por_defeito_e_o_kwanza_com_duas_casas(): void
    {
        $moeda = $this->servico()->atual();

        $this->assertSame('AOA', $moeda->codigo);
        $this->assertSame(2, $moeda->decimais);
    }

    public function test_acompanha_a_configuracao(): void
    {
        ConfiguracaoMonetaria::doTenant()->update(['moeda' => 'JPY']);

        $this->assertSame(0, $this->servico()->atual()->decimais);
    }

    public function test_pode_alterar_quando_nao_ha_nada_configurado(): void
    {
        $this->assertTrue($this->servico()->podeAlterar());
    }

    public function test_nao_pode_alterar_com_produto(): void
    {
        Produto::create(['nome' => 'P', 'preco' => Dinheiro::deUnidadesMenores(100)]);

        $this->assertFalse($this->servico()->podeAlterar());
    }

    public function test_nao_pode_alterar_com_servico(): void
    {
        Servico::create(['nome' => 'S', 'preco' => Dinheiro::deUnidadesMenores(100)]);

        $this->assertFalse($this->servico()->podeAlterar());
    }

    public function test_nao_pode_alterar_com_outra_fonte_de_precos(): void
    {
        $this->app->instance('fonte.fake', new class implements FonteDePrecos {
            public function existemPrecos(): bool
            {
                return true;
            }
        });
        $this->app->tag(['fonte.fake'], FontesDePrecos::ETIQUETA);

        $this->assertFalse($this->servico()->podeAlterar());
    }

    public function test_nao_pode_alterar_com_referencia_financeira(): void
    {
        $this->app->instance('ref.fake', new class implements ReferenciaFinanceira {
            public function existeReferenciaA(Model $configuracao): bool
            {
                return $configuracao instanceof ConfiguracaoMonetaria;
            }
        });
        $this->app->tag(['ref.fake'], ReferenciasFinanceiras::ETIQUETA);

        $this->assertFalse($this->servico()->podeAlterar());
    }

    public function test_produtos_de_outro_tenant_nao_bloqueiam(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, fn () => Produto::create(['nome' => 'P', 'preco' => Dinheiro::deUnidadesMenores(100)]));

        $this->assertTrue($this->servico()->podeAlterar());
    }
}
