<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Tenancy\Contracts\ProvisionaTenant;
use Modules\Core\Tenancy\Provisioning\DadosProvisionamento;
use Modules\Financeiro\Provisioning\ProvisionarConfiguracaoMonetaria;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

class ConfiguracaoMonetariaProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_provisionador_esta_registado_com_a_ordem_60(): void
    {
        $provisionadores = collect(app()->tagged(ProvisionaTenant::ETIQUETA));
        $ours = $provisionadores->first(fn ($p) => $p instanceof ProvisionarConfiguracaoMonetaria);

        $this->assertNotNull($ours);
        $this->assertSame(60, $ours->ordem());
        $this->assertSame(
            $provisionadores->count(),
            $provisionadores->map(fn (ProvisionaTenant $p) => $p->ordem())->unique()->count(),
        );
    }

    public function test_provisionar_duas_vezes_nao_duplica(): void
    {
        $dados = new DadosProvisionamento('Escola', 'Admin', 'admin@example.com');
        $provisionador = app(ProvisionarConfiguracaoMonetaria::class);

        $provisionador->provisionar($this->tenant->paraTenantAtual(), $dados);
        $provisionador->provisionar($this->tenant->paraTenantAtual(), $dados);

        $this->assertSame(1, DB::table('configuracoes_monetarias')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_comando_cria_a_configuracao_em_falta_de_forma_idempotente(): void
    {
        $this->seed(PermissaoDatabaseSeeder::class);
        $this->assertSame(0, DB::table('configuracoes_monetarias')->count());

        $this->artisan('financeiro:sincronizar', ['--tenant' => $this->tenant->codigo])->assertSuccessful();
        $this->artisan('financeiro:sincronizar', ['--tenant' => $this->tenant->codigo])->assertSuccessful();

        $this->assertSame(1, DB::table('configuracoes_monetarias')->where('tenant_id', $this->tenant->id)->count());
        $this->assertSame('AOA', DB::table('configuracoes_monetarias')->value('moeda'));
    }
}
