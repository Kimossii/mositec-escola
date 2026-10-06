<?php

namespace Tests\Feature\Jobs;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;
use Modules\Core\Tenancy\TenantContext;
use Modules\Tenant\Models\Tenant;
use Tests\Fixtures\Tenancy\JobUnicoDeTeste;
use Tests\Fixtures\Tenancy\JobUnicoPorTenantDeTeste;
use Tests\TestCase;

class UnicoPorTenantTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;

    private Tenant $b;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
        $this->a = $this->criarTenant('MOSI-000010', 'Escola A', 'a.localhost');
        $this->b = $this->criarTenant('MOSI-000011', 'Escola B', 'b.localhost');
    }

    private function despachar(Tenant $tenant, string $classe, string $chave = 'x'): void
    {
        $this->noTenant($tenant, function () use ($classe, $chave) {
            $classe === JobUnicoPorTenantDeTeste::class ? JobUnicoPorTenantDeTeste::dispatch($chave) : $classe::dispatch();
        });
    }

    public function test_o_id_unico_inclui_o_tenant_e_o_identificador_do_job(): void
    {
        $idA = $this->noTenant($this->a, fn () => (new JobUnicoPorTenantDeTeste('k'))->uniqueId());
        $idB = $this->noTenant($this->b, fn () => (new JobUnicoPorTenantDeTeste('k'))->uniqueId());

        $this->assertSame("tenant:{$this->a->id}:k", $idA);
        $this->assertSame("tenant:{$this->b->id}:k", $idB);
    }

    public function test_dois_tenants_enfileiram_o_mesmo_job_unico_e_o_repetido_do_mesmo_tenant_e_recusado(): void
    {
        $this->despachar($this->a, JobUnicoPorTenantDeTeste::class);
        $this->despachar($this->b, JobUnicoPorTenantDeTeste::class);

        $this->assertSame(2, DB::table('jobs')->count(), 'Controlo positivo: A e B não se bloqueiam.');

        $this->despachar($this->a, JobUnicoPorTenantDeTeste::class);

        $this->assertSame(2, DB::table('jobs')->count(), 'Controlo negativo: o repetido de A é recusado.');

        $this->despachar($this->a, JobUnicoPorTenantDeTeste::class, 'outra');

        $this->assertSame(3, DB::table('jobs')->count(), 'Outro identificador em A é um job diferente.');
    }

    public function test_sem_a_trait_o_lock_e_partilhado_entre_tenants(): void
    {
        // Documenta o risco que o teste de arquitectura impede nos módulos.
        $this->despachar($this->a, JobUnicoDeTeste::class);
        $this->despachar($this->b, JobUnicoDeTeste::class);

        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_sem_contexto_o_id_unico_lanca(): void
    {
        app(TenantContext::class)->limpar();

        $this->expectException(TenantNaoResolvido::class);

        (new JobUnicoPorTenantDeTeste())->uniqueId();
    }
}
