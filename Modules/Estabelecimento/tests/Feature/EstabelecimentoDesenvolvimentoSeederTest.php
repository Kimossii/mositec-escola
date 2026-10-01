<?php

namespace Modules\Estabelecimento\Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Tenancy\TenantContext;
use Modules\Estabelecimento\Database\Seeders\EstabelecimentoDesenvolvimentoSeeder;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Tenant\Models\Tenant;
use Tests\TestCase;

class EstabelecimentoDesenvolvimentoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_nao_duplica_o_estabelecimento_do_tenant(): void
    {
        $this->seed(EstabelecimentoDesenvolvimentoSeeder::class);
        $this->seed(EstabelecimentoDesenvolvimentoSeeder::class);

        $this->assertSame(1, Estabelecimento::count());
    }

    public function test_cria_o_minimo_num_tenant_sem_estabelecimento(): void
    {
        // Um tenant sem estabelecimento já não se cria pelo auxiliar, que imita o provisioning.
        $outro = Tenant::create(['codigo' => 'MOSI-000777', 'nome' => 'Sem Estabelecimento']);

        $this->noTenant($outro, function () {
            $this->seed(EstabelecimentoDesenvolvimentoSeeder::class);

            $estabelecimento = Estabelecimento::current();
            $this->assertSame('Escola de Desenvolvimento', $estabelecimento->nome);
            $this->assertNull($estabelecimento->configurado_em);
        });
    }

    public function test_database_seeder_corre_dentro_do_contexto_e_deixa_o_contexto_limpo(): void
    {
        // Sem contexto prévio: só passa se o DatabaseSeeder definir o tenant via executarComo.
        app(TenantContext::class)->limpar();

        $this->seed(DatabaseSeeder::class);

        $this->assertFalse(app(TenantContext::class)->temTenant());

        $this->noTenant($this->tenant, function () {
            $this->assertSame(1, Estabelecimento::count());
        });
    }
}
