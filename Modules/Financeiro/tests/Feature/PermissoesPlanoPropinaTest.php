<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Modulo;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class PermissoesPlanoPropinaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function utilizadorCom(Perfil $perfil): User
    {
        $user = User::create(['name' => 'Teste', 'email' => $perfil->value . '@example.com', 'password' => Hash::make('x')]);
        $user->roles()->syncWithoutDetaching([Role::where('nome', $perfil->value)->first()->id]);

        return $user;
    }

    public function test_modulo_tem_slug_e_label(): void
    {
        $this->assertSame('plano-propina', Modulo::PLANO_PROPINA->slug());
        $this->assertSame(Modulo::PLANO_PROPINA, Modulo::fromSlug('plano-propina'));
        $this->assertSame('Plano de Propina', Modulo::PLANO_PROPINA->label());
    }

    public function test_admin_escola_tem_as_quatro_accoes(): void
    {
        $admin = $this->utilizadorCom(Perfil::ADMIN_ESCOLA);

        foreach (['ver', 'criar', 'editar', 'eliminar'] as $acao) {
            $this->assertTrue(Gate::forUser($admin)->allows("plano-propina.{$acao}"), $acao);
        }
    }

    public function test_funcionario_e_professor_nao_acedem(): void
    {
        foreach ([Perfil::FUNCIONARIO, Perfil::PROFESSOR] as $perfil) {
            $this->assertFalse(Gate::forUser($this->utilizadorCom($perfil))->allows('plano-propina.ver'), $perfil->name);
        }
    }
}
