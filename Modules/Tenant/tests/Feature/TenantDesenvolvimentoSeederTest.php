<?php

namespace Modules\Tenant\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Permissao\Database\Seeders\AcaoSeeder;
use Modules\Permissao\Database\Seeders\ModuloSeeder;
use Modules\Tenant\Database\Seeders\TenantDesenvolvimentoSeeder;
use Modules\Tenant\Models\Domain;
use Modules\Tenant\Models\Tenant;
use Tests\TestCase;

class TenantDesenvolvimentoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // O seeder corre numa BD acabada de migrar, sem o tenant da base de testes.
        Domain::query()->delete();
        // O estabelecimento do tenant de teste prende o tenant (chave estrangeira).
        DB::table('estabelecimentos')->delete();
        Tenant::query()->delete();

        // O seeder cria os tenants pelo provisioning, que precisa do catálogo global.
        $this->seed([ModuloSeeder::class, AcaoSeeder::class]);
    }

    public function test_cria_o_tenant_1_com_os_dominios_locais_e_o_host_de_app_url(): void
    {
        config(['app.url' => 'http://Mositec-Escola.test:8000']);

        $this->seed(TenantDesenvolvimentoSeeder::class);

        $tenant = Tenant::query()->where('codigo', 'MOSI-000001')->sole();
        $this->assertEqualsCanonicalizing(
            ['localhost', '127.0.0.1', 'mositec-escola.test'],
            $tenant->dominios()->pluck('dominio')->all(),
        );
        $this->assertSame('localhost', $tenant->dominios()->where('is_principal', true)->sole()->dominio);
    }

    public function test_cria_o_tenant_2_com_o_dominio_proprio(): void
    {
        $this->seed(TenantDesenvolvimentoSeeder::class);

        $tenant = Tenant::query()->where('codigo', 'MOSI-000002')->sole();
        $this->assertSame(['escola-b.mositec-escola.test'], $tenant->dominios()->pluck('dominio')->all());
    }

    public function test_em_modo_unico_so_cria_o_primeiro_tenant(): void
    {
        config(['tenancy.modo' => 'unico']);

        $this->seed(TenantDesenvolvimentoSeeder::class);

        $this->assertSame(['MOSI-000001'], Tenant::query()->pluck('codigo')->all());
    }

    public function test_e_idempotente(): void
    {
        $this->seed(TenantDesenvolvimentoSeeder::class);
        $this->seed(TenantDesenvolvimentoSeeder::class);

        $this->assertSame(2, Tenant::query()->count());
        $this->assertSame(3, Domain::query()->count());
    }

    public function test_nao_cria_nada_fora_de_desenvolvimento_e_testes(): void
    {
        $this->app['env'] = 'production';

        // Chamado directamente: em produção, $this->seed() pede confirmação interactiva.
        app(TenantDesenvolvimentoSeeder::class)->run();

        $this->assertSame(0, Tenant::query()->count());
        $this->assertSame(0, Domain::query()->count());
    }
}
