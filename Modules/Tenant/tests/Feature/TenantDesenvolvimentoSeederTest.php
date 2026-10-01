<?php

namespace Modules\Tenant\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
    }

    public function test_cria_o_tenant_com_os_dominios_locais_e_o_host_de_app_url(): void
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

    public function test_e_idempotente(): void
    {
        $this->seed(TenantDesenvolvimentoSeeder::class);
        $this->seed(TenantDesenvolvimentoSeeder::class);

        $this->assertSame(1, Tenant::query()->count());
        $this->assertSame(2, Domain::query()->count());
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
