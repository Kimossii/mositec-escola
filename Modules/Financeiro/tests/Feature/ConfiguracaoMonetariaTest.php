<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Financeiro\Models\ConfiguracaoMonetaria;
use Tests\TestCase;

class ConfiguracaoMonetariaTest extends TestCase
{
    use RefreshDatabase;

    public function test_do_tenant_cria_com_os_defaults(): void
    {
        $configuracao = ConfiguracaoMonetaria::doTenant();

        $this->assertSame($this->tenant->id, $configuracao->tenant_id);
        $this->assertSame('AOA', $configuracao->moeda);
        $this->assertFalse($configuracao->cambio_manual);
    }

    public function test_do_tenant_e_idempotente(): void
    {
        $primeira = ConfiguracaoMonetaria::doTenant();
        $segunda = ConfiguracaoMonetaria::doTenant();

        $this->assertSame($primeira->id, $segunda->id);
        $this->assertSame(1, ConfiguracaoMonetaria::count());
    }

    public function test_a_bd_impede_uma_segunda_configuracao_no_mesmo_tenant(): void
    {
        ConfiguracaoMonetaria::doTenant();

        $this->expectException(QueryException::class);

        ConfiguracaoMonetaria::create(ConfiguracaoMonetaria::DEFAULTS);
    }

    public function test_cada_tenant_tem_a_sua_moeda(): void
    {
        ConfiguracaoMonetaria::doTenant()->update(['moeda' => 'MZN']);
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        $doOutro = $this->noTenant($outro, fn () => ConfiguracaoMonetaria::doTenant());

        $this->assertSame('AOA', $doOutro->moeda);
        $this->assertSame($outro->id, $doOutro->tenant_id);
        $this->assertSame('MZN', ConfiguracaoMonetaria::doTenant()->moeda);
    }

    public function test_tenant_id_nao_e_exposto(): void
    {
        $this->assertArrayNotHasKey('tenant_id', ConfiguracaoMonetaria::doTenant()->toArray());
    }
}
