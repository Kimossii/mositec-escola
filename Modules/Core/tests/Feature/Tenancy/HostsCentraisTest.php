<?php

namespace Modules\Core\Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Modules\Core\Tenancy\Support\HostsCentrais;
use Modules\Core\Tenancy\TenantContext;
use Modules\Tenant\Exceptions\DadosDeTenantInvalidos;
use Modules\Tenant\Services\ValidadorDominio;
use Tests\TestCase;

/**
 * Um só componente decide o que é um host central; o ResolverTenant, o ApenasHostCentral
 * (Plataforma) e o ValidadorDominio (Tenant) usam-no e têm de concordar.
 */
class HostsCentraisTest extends TestCase
{
    use RefreshDatabase;

    public function test_normaliza_os_hosts_da_config_ao_ler(): void
    {
        config(['tenancy.hosts_centrais' => ['Painel.Exemplo.test:8000', '  OUTRO.exemplo.test. ', '']]);

        $this->assertSame(['painel.exemplo.test', 'outro.exemplo.test'], HostsCentrais::lista());
        $this->assertTrue(HostsCentrais::contem('painel.exemplo.test'));
        $this->assertTrue(HostsCentrais::contem('PAINEL.Exemplo.test:8000'));
        $this->assertTrue(HostsCentrais::contem('outro.exemplo.test'));
        $this->assertFalse(HostsCentrais::contem('escola.exemplo.test'));
        $this->assertFalse(HostsCentrais::contem('exemplo.test'));
    }

    public function test_vazio_significa_nenhum_e_o_host_vazio_nunca_e_central(): void
    {
        config(['tenancy.hosts_centrais' => []]);
        $this->assertFalse(HostsCentrais::contem('painel.exemplo.test'));
        $this->assertFalse(HostsCentrais::contem(''));

        config(['tenancy.hosts_centrais' => ['', ' ']]);
        $this->assertSame([], HostsCentrais::lista());
        $this->assertFalse(HostsCentrais::contem(''));
    }

    public function test_os_tres_consumidores_concordam_com_maiusculas_e_porta(): void
    {
        config(['tenancy.hosts_centrais' => ['Painel.Exemplo.test:8000']]);
        Route::get('/_hc/sem-grupo', fn () => app(TenantContext::class)->temTenant() ? 'com-tenant' : 'sem-tenant');
        Route::middleware('plataforma')->get('/plataforma/_hc/painel', fn () => 'painel');

        // ResolverTenant: host central, sem tenant.
        $this->get('http://painel.exemplo.test:8000/_hc/sem-grupo')->assertOk()->assertSee('sem-tenant');
        // ApenasHostCentral: o painel responde.
        $this->get('http://painel.exemplo.test/plataforma/_hc/painel')->assertOk()->assertSee('painel');
        // ValidadorDominio: o host central não pode ser de um tenant.
        $this->expectException(DadosDeTenantInvalidos::class);
        app(ValidadorDominio::class)->validar('painel.exemplo.test');
    }

    public function test_sem_hosts_centrais_nenhum_consumidor_o_trata_como_central(): void
    {
        config(['tenancy.hosts_centrais' => []]);
        Route::middleware('plataforma')->get('/plataforma/_hc/painel', fn () => 'painel');

        $this->get('http://painel.exemplo.test/plataforma/_hc/painel')->assertNotFound();
        $this->get('http://painel.exemplo.test/_hc/sem-grupo')->assertNotFound();
        $this->assertSame('painel.exemplo.test', app(ValidadorDominio::class)->validar('painel.exemplo.test'));
    }
}
