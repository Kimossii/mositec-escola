<?php

namespace Modules\Plataforma\Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Plataforma\Database\Seeders\PlataformaDesenvolvimentoSeeder;
use Modules\Plataforma\Models\SuperAdmin;
use Tests\TestCase;

class PlataformaDesenvolvimentoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_o_super_admin_de_desenvolvimento_com_credenciais_conhecidas(): void
    {
        $this->seed(PlataformaDesenvolvimentoSeeder::class);

        $admin = SuperAdmin::sole();
        $this->assertSame(PlataformaDesenvolvimentoSeeder::EMAIL, $admin->email);
        $this->assertTrue(password_verify(PlataformaDesenvolvimentoSeeder::SENHA_DESENVOLVIMENTO, $admin->password));
        $this->assertFalse($admin->deve_alterar_senha);
        $this->assertSame(1, (int) $admin->estado);
    }

    public function test_e_idempotente_e_repoe_a_senha_conhecida(): void
    {
        $this->seed(PlataformaDesenvolvimentoSeeder::class);
        SuperAdmin::sole()->forceFill(['deve_alterar_senha' => true, 'estado' => 0])->save();

        $this->seed(PlataformaDesenvolvimentoSeeder::class);

        $admin = SuperAdmin::sole();
        $this->assertFalse($admin->deve_alterar_senha);
        $this->assertSame(1, (int) $admin->estado);
    }

    public function test_em_producao_nao_cria_nada(): void
    {
        $this->app['env'] = 'production';

        // Chamado directamente: em produção, $this->seed() pede confirmação interactiva.
        app(PlataformaDesenvolvimentoSeeder::class)->run();

        $this->assertSame(0, SuperAdmin::count());
    }

    public function test_o_database_seeder_so_o_corre_em_desenvolvimento(): void
    {
        $this->app['env'] = 'production';
        app(DatabaseSeeder::class)->run();
        $this->assertSame(0, SuperAdmin::count());

        $this->app['env'] = 'testing';
        $this->seed(DatabaseSeeder::class);
        $this->assertSame(1, SuperAdmin::count());
    }
}
