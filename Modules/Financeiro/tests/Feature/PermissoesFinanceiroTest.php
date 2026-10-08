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

class PermissoesFinanceiroTest extends TestCase
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
        $this->assertSame('regra-cobranca', Modulo::REGRA_COBRANCA->slug());
        $this->assertSame(Modulo::REGRA_COBRANCA, Modulo::fromSlug('regra-cobranca'));
        $this->assertSame('Regra de Cobrança', Modulo::REGRA_COBRANCA->label());
    }

    public function test_admin_escola_ve_e_edita_regras_de_cobranca(): void
    {
        $admin = $this->utilizadorCom(Perfil::ADMIN_ESCOLA);

        $this->assertTrue(Gate::forUser($admin)->allows('regra-cobranca.ver'));
        $this->assertTrue(Gate::forUser($admin)->allows('regra-cobranca.editar'));
        $this->assertFalse(Gate::forUser($admin)->allows('regra-cobranca.eliminar'));
    }

    public function test_funcionario_e_professor_nao_acedem(): void
    {
        foreach ([Perfil::FUNCIONARIO, Perfil::PROFESSOR] as $perfil) {
            $user = $this->utilizadorCom($perfil);

            $this->assertFalse(Gate::forUser($user)->allows('regra-cobranca.ver'), $perfil->name);
        }
    }
}
