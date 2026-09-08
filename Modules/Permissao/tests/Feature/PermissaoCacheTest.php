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
        $cache = new PermissaoCache();
        $cache->guardar(42, ['turmas.ver', 'turmas.criar']);

        $this->assertSame(['turmas.ver', 'turmas.criar'], $cache->obter(42));
    }

    public function test_obter_devolve_null_quando_nao_ha_nada_guardado(): void
    {
        $cache = new PermissaoCache();

        $this->assertNull($cache->obter(999));
    }

    public function test_esquecer_utilizador_remove_so_a_chave_desse_utilizador(): void
    {
        $cache = new PermissaoCache();
        $cache->guardar(1, ['a.b']);
        $cache->guardar(2, ['c.d']);

        $cache->esquecerUtilizador(1);

        $this->assertNull($cache->obter(1));
        $this->assertSame(['c.d'], $cache->obter(2));
    }

    public function test_invalidar_tudo_torna_todas_as_chaves_antigas_inacessiveis(): void
    {
        $cache = new PermissaoCache();
        $cache->guardar(1, ['a.b']);
        $cache->guardar(2, ['c.d']);

        $cache->invalidarTudo();

        $this->assertNull($cache->obter(1));
        $this->assertNull($cache->obter(2));
    }
}
