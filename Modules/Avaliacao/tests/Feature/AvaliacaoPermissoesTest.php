<?php

namespace Modules\Avaliacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Database\Seeders\AcaoSeeder;
use Modules\Permissao\Database\Seeders\ModuloSeeder;
use Modules\Permissao\Database\Seeders\RolePermissaoSeeder;
use Modules\Permissao\Database\Seeders\RoleSeeder;
use Modules\Permissao\Enums\Modulo;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Services\PermissionResolver;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class AvaliacaoPermissoesTest extends TestCase
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

    private function utilizador(Perfil $perfil): User
    {
        $user = User::create(['name' => $perfil->name, 'email' => $perfil->name.'@example.com', 'password' => Hash::make('x')]);
        $user->roles()->attach(Role::where('nome', $perfil->value)->first()->id);

        return $user;
    }

    public function test_nota_declara_as_accoes_de_avaliacao(): void
    {
        $this->assertSame(
            ['ver', 'listar', 'criar', 'editar', 'anular', 'prolongar-prazo', 'lancar-fora-prazo', 'configurar', 'exportar'],
            Modulo::NOTA->acoesAplicaveis(),
        );
    }

    public function test_admin_escola_tem_todas_as_accoes_de_nota(): void
    {
        $admin = $this->utilizador(Perfil::ADMIN_ESCOLA);
        $resolver = app(PermissionResolver::class);

        foreach (Modulo::NOTA->acoesAplicaveis() as $acao) {
            $this->assertTrue($resolver->can($admin, "nota.{$acao}"), "nota.{$acao}");
        }
    }

    public function test_professor_pode_ver_listar_criar_editar_nota_mas_nao_prolongar_prazo(): void
    {
        $professor = $this->utilizador(Perfil::PROFESSOR);
        $resolver = app(PermissionResolver::class);

        foreach (['ver', 'listar', 'criar', 'editar'] as $acao) {
            $this->assertTrue($resolver->can($professor, "nota.{$acao}"), "nota.{$acao}");
        }
        foreach (['anular', 'prolongar-prazo', 'lancar-fora-prazo', 'configurar'] as $acao) {
            $this->assertFalse($resolver->can($professor, "nota.{$acao}"), "nota.{$acao}");
        }
    }
}
