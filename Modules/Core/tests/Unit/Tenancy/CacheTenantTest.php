<?php

namespace Modules\Core\Tests\Unit\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Tenancy\CacheTenant;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;
use Modules\Core\Tenancy\TenantContext;
use Tests\TestCase;

class CacheTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_prefixa_a_chave_com_o_tenant(): void
    {
        $this->assertSame("tenant:{$this->tenant->id}:x", app(CacheTenant::class)->chave('x'));
    }

    public function test_o_mesmo_nome_de_chave_nao_colide_entre_tenants(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $cache = app(CacheTenant::class);

        $cache->forever('valor', 'de-A');
        $this->noTenant($outro, fn () => app(CacheTenant::class)->forever('valor', 'de-B'));

        $this->assertSame('de-A', $cache->get('valor'));
        $this->assertSame('de-B', $this->noTenant($outro, fn () => app(CacheTenant::class)->get('valor')));
    }

    public function test_esquecer_num_tenant_nao_afecta_o_outro(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $cache = app(CacheTenant::class);

        $cache->forever('valor', 'de-A');
        $this->noTenant($outro, fn () => app(CacheTenant::class)->forever('valor', 'de-B'));

        $cache->forget('valor');

        $this->assertNull($cache->get('valor'));
        $this->assertSame('de-B', $this->noTenant($outro, fn () => app(CacheTenant::class)->get('valor')));
    }

    public function test_sem_contexto_lanca_tenant_nao_resolvido(): void
    {
        app(TenantContext::class)->limpar();

        $this->expectException(TenantNaoResolvido::class);

        app(CacheTenant::class)->get('valor');
    }
}
