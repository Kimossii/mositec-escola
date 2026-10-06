<?php

namespace Modules\Permissao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Permissao\Support\PermissaoCache;
use Tests\TestCase;

class PermissaoCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_guardar_e_obter_devolve_o_mesmo_conjunto(): void
    {
        $cache = app(PermissaoCache::class);
        $cache->guardar(42, ['turmas.ver', 'turmas.criar']);

        $this->assertSame(['turmas.ver', 'turmas.criar'], $cache->obter(42));
    }

    public function test_obter_devolve_null_quando_nao_ha_nada_guardado(): void
    {
        $cache = app(PermissaoCache::class);

        $this->assertNull($cache->obter(999));
    }

    public function test_esquecer_utilizador_remove_so_a_chave_desse_utilizador(): void
    {
        $cache = app(PermissaoCache::class);
        $cache->guardar(1, ['a.b']);
        $cache->guardar(2, ['c.d']);

        $cache->esquecerUtilizador(1);

        $this->assertNull($cache->obter(1));
        $this->assertSame(['c.d'], $cache->obter(2));
    }

    public function test_invalidar_tudo_torna_todas_as_chaves_antigas_inacessiveis(): void
    {
        $cache = app(PermissaoCache::class);
        $cache->guardar(1, ['a.b']);
        $cache->guardar(2, ['c.d']);

        $cache->invalidarTudo();

        $this->assertNull($cache->obter(1));
        $this->assertNull($cache->obter(2));
    }

    public function test_invalidar_tudo_num_tenant_nao_afecta_a_versao_do_outro(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $cache = app(PermissaoCache::class);
        $chaveDeBAntes = $this->noTenant($outro, fn () => app(PermissaoCache::class)->chave(1));

        $cache->invalidarTudo();

        $this->assertSame('permissoes:v2:user:1', $cache->chave(1));
        $this->assertSame($chaveDeBAntes, $this->noTenant($outro, fn () => app(PermissaoCache::class)->chave(1)));
    }
}
