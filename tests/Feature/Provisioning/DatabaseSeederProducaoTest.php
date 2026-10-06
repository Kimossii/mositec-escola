<?php

namespace Tests\Feature\Provisioning;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo;
use Modules\Tenant\Models\Tenant;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class DatabaseSeederProducaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_em_producao_so_semeia_o_catalogo_global(): void
    {
        $this->app['env'] = 'production';

        // Chamado directamente: em produção, $this->seed() pede confirmação interactiva.
        app(DatabaseSeeder::class)->run();

        $this->assertGreaterThan(0, Modulo::count());
        $this->assertGreaterThan(0, Acao::count());
        $this->assertSame(['MOSI-000001'], Tenant::pluck('codigo')->all(), 'Só o tenant da base de testes: nenhum de desenvolvimento.');
        $this->assertSame(0, User::count());
        $this->assertFalse(Tenant::where('codigo', 'MOSI-000002')->exists());
    }
}
