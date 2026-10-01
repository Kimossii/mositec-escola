<?php

namespace Tests\Feature\Comandos;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Core\Tenancy\TenantContext;
use Modules\Tenant\Models\Tenant;
use Tests\Fixtures\Tenancy\ComandoDeTeste;
use Tests\Fixtures\Tenancy\JobDeTeste;
use Tests\TestCase;

class ConvencaoDeComandosTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $a;

    private Tenant $suspenso;

    private Tenant $encerrado;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::registerCommand(new ComandoDeTeste());
        ComandoDeTeste::$corridos = [];

        $this->a = $this->criarTenant('MOSI-000010', 'Escola A', 'a.localhost');
        $this->suspenso = $this->criarTenant('MOSI-000011', 'Escola S', 's.localhost');
        $this->encerrado = $this->criarTenant('MOSI-000012', 'Escola E', 'e.localhost');
        $this->suspenso->update(['estado' => EstadoTenant::SUSPENSO]);
        $this->encerrado->update(['estado' => EstadoTenant::ENCERRADO]);
    }

    public function test_sem_opcao_falha_e_nao_corre_em_nenhum_tenant(): void
    {
        $this->artisan('teste:tenancy')
            ->expectsOutputToContain('não há tenant por omissão')
            ->assertFailed();

        $this->assertSame([], ComandoDeTeste::$corridos);
    }

    public function test_tenant_e_todos_juntos_falham(): void
    {
        $this->artisan('teste:tenancy', ['--tenant' => 'MOSI-000010', '--todos' => true])
            ->expectsOutputToContain('não ambos')
            ->assertFailed();

        $this->assertSame([], ComandoDeTeste::$corridos);
    }

    public function test_tenant_valido_corre_so_esse(): void
    {
        $this->artisan('teste:tenancy', ['--tenant' => 'MOSI-000010'])->assertSuccessful();

        $this->assertSame(['MOSI-000010=Escola A'], ComandoDeTeste::$corridos);
    }

    public function test_tenant_desconhecido_falha(): void
    {
        $this->artisan('teste:tenancy', ['--tenant' => 'MOSI-999999'])
            ->expectsOutputToContain('não encontrado')
            ->assertFailed();

        $this->assertSame([], ComandoDeTeste::$corridos);
    }

    public function test_tenant_suspenso_ou_encerrado_e_recusado(): void
    {
        $this->artisan('teste:tenancy', ['--tenant' => 'MOSI-000011'])
            ->expectsOutputToContain('suspenso')
            ->assertFailed();
        $this->artisan('teste:tenancy', ['--tenant' => 'MOSI-000012'])
            ->expectsOutputToContain('encerrado')
            ->assertFailed();

        $this->assertSame([], ComandoDeTeste::$corridos);
    }

    public function test_todos_corre_so_os_activos_e_avisa_dos_saltados(): void
    {
        $this->artisan('teste:tenancy', ['--todos' => true])
            ->expectsOutputToContain('[MOSI-000011] saltado')
            ->expectsOutputToContain('[MOSI-000012] saltado')
            ->expectsOutputToContain('2 tenant(s) não activos saltados')
            ->assertSuccessful();

        $this->assertSame(['MOSI-000001=Escola de Teste', 'MOSI-000010=Escola A'], ComandoDeTeste::$corridos);
    }

    public function test_falha_num_tenant_nao_impede_os_seguintes_e_da_codigo_de_saida_diferente_de_zero(): void
    {
        $this->criarTenant('MOSI-000013', 'Escola C', 'c.localhost');

        $this->artisan('teste:tenancy', ['--todos' => true, '--rebentar-em' => 'MOSI-000010'])
            ->expectsOutputToContain('[MOSI-000010] falhou')
            ->doesntExpectOutputToContain('segredo-que-nao-deve-aparecer')
            ->assertFailed();

        $this->assertSame(
            ['MOSI-000001=Escola de Teste', 'MOSI-000013=Escola C'],
            ComandoDeTeste::$corridos,
            'Os tenants antes e depois do que falhou correm.',
        );
    }

    public function test_cada_tenant_corre_no_seu_contexto_e_o_anterior_e_reposto(): void
    {
        $this->artisan('teste:tenancy', ['--todos' => true])->assertSuccessful();

        // O contexto do teste (tenant por omissão) é reposto no fim.
        $this->assertSame('MOSI-000001', app(TenantContext::class)->atual()->codigo);
    }

    public function test_um_pending_dispatch_devolvido_pelo_closure_despacha_no_tenant_certo(): void
    {
        JobDeTeste::$execucoes = [];

        $this->artisan('teste:tenancy', ['--tenant' => 'MOSI-000010', '--despachar' => true])->assertSuccessful();

        $this->assertSame(['MOSI-000010'], array_column(JobDeTeste::$execucoes, 'tenant'));
    }

    public function test_todos_mostra_o_resumo_de_processados_e_saltados(): void
    {
        $this->artisan('teste:tenancy', ['--todos' => true])
            ->expectsOutputToContain('2 processados, 2 saltados.')
            ->assertSuccessful();
    }

    public function test_o_codigo_do_tenant_e_normalizado_para_maiusculas(): void
    {
        $this->artisan('teste:tenancy', ['--tenant' => ' mosi-000010 '])->assertSuccessful();

        $this->assertSame(['MOSI-000010=Escola A'], ComandoDeTeste::$corridos);
    }
}
