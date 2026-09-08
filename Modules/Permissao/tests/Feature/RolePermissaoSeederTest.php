<?php

namespace Modules\Permissao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Permissao\Database\Seeders\AcaoSeeder;
use Modules\Permissao\Database\Seeders\ModuloSeeder;
use Modules\Permissao\Database\Seeders\RolePermissaoSeeder;
use Modules\Permissao\Database\Seeders\RoleSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Services\PermissionResolver;
use Modules\Usuario\Models\User;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Models\Role;
use Tests\TestCase;

class RolePermissaoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ModuloSeeder::class);
        $this->seed(AcaoSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(RolePermissaoSeeder::class);
    }

    public function test_admin_escola_tem_a_paridade_dos_3_modulos_migrados(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => Hash::make('x')]);
        $admin->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id);

        $resolver = app(PermissionResolver::class);

        foreach (['ver', 'criar', 'editar', 'eliminar'] as $acao) {
            $this->assertTrue($resolver->can($admin, "ano-lectivo.{$acao}"), "ano-lectivo.{$acao}");
            $this->assertTrue($resolver->can($admin, "horario.{$acao}"), "horario.{$acao}");
        }
        $this->assertTrue($resolver->can($admin, 'estabelecimento.ver'));
        $this->assertTrue($resolver->can($admin, 'estabelecimento.editar'));
        $this->assertFalse($resolver->can($admin, 'estabelecimento.criar'), 'Estabelecimento é singleton, não tem criar');
        $this->assertFalse($resolver->can($admin, 'estabelecimento.eliminar'), 'Estabelecimento é singleton, não tem eliminar');
    }

    public function test_professor_nao_tem_nenhuma_destas_permissoes(): void
    {
        $professor = User::create(['name' => 'P', 'email' => 'p@example.com', 'password' => Hash::make('x')]);
        $professor->roles()->attach(Role::where('nome', Perfil::PROFESSOR->value)->first()->id);

        $resolver = app(PermissionResolver::class);

        $this->assertFalse($resolver->can($professor, 'ano-lectivo.ver'));
        $this->assertFalse($resolver->can($professor, 'horario.ver'));
        $this->assertFalse($resolver->can($professor, 'estabelecimento.ver'));
    }
}
