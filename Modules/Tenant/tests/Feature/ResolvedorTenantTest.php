<?php

namespace Modules\Tenant\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Core\Tenancy\Contracts\ResolvedorTenant;
use Modules\Tenant\Exceptions\InstalacaoUnicaInvalida;
use Modules\Tenant\Models\Domain;
use Modules\Tenant\Models\Tenant;
use Modules\Tenant\Services\ResolvedorTenantPorDominio;
use Modules\Tenant\Services\ResolvedorTenantUnico;
use Tests\TestCase;

class ResolvedorTenantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Estes testes controlam exactamente que tenants existem.
        Domain::query()->delete();
        Tenant::query()->delete();
    }

    private function tenantComDominio(string $codigo, string $dominio): Tenant
    {
        $tenant = Tenant::create(['codigo' => $codigo, 'nome' => "Escola {$codigo}"]);
        $tenant->dominios()->create(['dominio' => $dominio, 'is_principal' => true]);

        return $tenant;
    }

    private function pedido(string $url): Request
    {
        return Request::create($url);
    }

    public function test_por_dominio_resolve_o_tenant_do_host(): void
    {
        $this->tenantComDominio('MOSI-000801', 'escola-a.localhost');
        $b = $this->tenantComDominio('MOSI-000802', 'escola-b.localhost');

        $atual = app(ResolvedorTenantPorDominio::class)->resolver($this->pedido('http://escola-b.localhost/painel'));

        $this->assertSame($b->id, $atual->id);
        $this->assertSame('MOSI-000802', $atual->codigo);
    }

    public function test_por_dominio_ignora_maiusculas_porta_e_ponto_final(): void
    {
        $a = $this->tenantComDominio('MOSI-000801', 'escola-a.localhost');
        $pedido = $this->pedido('http://localhost/painel');
        $pedido->headers->set('HOST', 'Escola-A.Localhost.:8000');

        $atual = app(ResolvedorTenantPorDominio::class)->resolver($pedido);

        $this->assertSame($a->id, $atual->id);
    }

    public function test_por_dominio_resolve_por_um_dominio_secundario(): void
    {
        $a = $this->tenantComDominio('MOSI-000801', 'escola-a.localhost');
        $a->dominios()->create(['dominio' => 'gestao.escola-a.ao']);

        $atual = app(ResolvedorTenantPorDominio::class)->resolver($this->pedido('http://gestao.escola-a.ao/'));

        $this->assertSame($a->id, $atual->id);
    }

    public function test_por_dominio_devolve_null_para_host_desconhecido(): void
    {
        $this->tenantComDominio('MOSI-000801', 'escola-a.localhost');

        $this->assertNull(app(ResolvedorTenantPorDominio::class)->resolver($this->pedido('http://outra.localhost/')));
    }

    public function test_unico_devolve_o_unico_tenant_ignorando_o_host(): void
    {
        $a = $this->tenantComDominio('MOSI-000801', 'escola-a.localhost');

        $atual = app(ResolvedorTenantUnico::class)->resolver($this->pedido('http://192.168.1.10:8080/'));

        $this->assertSame($a->id, $atual->id);
    }

    public function test_unico_sem_tenants_lanca_excepcao(): void
    {
        $this->expectException(InstalacaoUnicaInvalida::class);
        $this->expectExceptionMessage('0');

        app(ResolvedorTenantUnico::class)->resolver($this->pedido('http://localhost/'));
    }

    public function test_unico_com_dois_tenants_lanca_excepcao(): void
    {
        $this->tenantComDominio('MOSI-000801', 'escola-a.localhost');
        $this->tenantComDominio('MOSI-000802', 'escola-b.localhost');

        $this->expectException(InstalacaoUnicaInvalida::class);
        $this->expectExceptionMessage('2');

        app(ResolvedorTenantUnico::class)->resolver($this->pedido('http://localhost/'));
    }

    public function test_o_container_escolhe_o_resolvedor_pelo_modo_configurado(): void
    {
        config(['tenancy.modo' => 'dominio']);
        $this->assertInstanceOf(ResolvedorTenantPorDominio::class, app(ResolvedorTenant::class));

        config(['tenancy.modo' => 'unico']);
        $this->assertInstanceOf(ResolvedorTenantUnico::class, app(ResolvedorTenant::class));
    }

    public function test_modo_desconhecido_lanca_excepcao_explicita(): void
    {
        config(['tenancy.modo' => 'subdominio']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('subdominio');

        app(ResolvedorTenant::class);
    }
}
