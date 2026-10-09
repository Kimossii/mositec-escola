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

class PermissoesMoedaCambioTest extends TestCase
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
        $this->assertSame('moeda-cambio', Modulo::MOEDA_CAMBIO->slug());
        $this->assertSame(Modulo::MOEDA_CAMBIO, Modulo::fromSlug('moeda-cambio'));
        $this->assertSame('Moeda e Câmbio', Modulo::MOEDA_CAMBIO->label());
    }

    public function test_admin_escola_tem_as_quatro_accoes(): void
    {
        $admin = $this->utilizadorCom(Perfil::ADMIN_ESCOLA);

        foreach (['ver', 'criar', 'editar', 'eliminar'] as $acao) {
            $this->assertTrue(Gate::forUser($admin)->allows("moeda-cambio.{$acao}"), $acao);
        }
    }

    public function test_funcionario_e_professor_nao_acedem_a_nenhuma_accao(): void
    {
        foreach ([Perfil::FUNCIONARIO, Perfil::PROFESSOR] as $perfil) {
            $user = $this->utilizadorCom($perfil);

            foreach (['ver', 'criar', 'editar', 'eliminar'] as $acao) {
                $this->assertFalse(Gate::forUser($user)->allows("moeda-cambio.{$acao}"), "{$perfil->name} {$acao}");
            }
        }
    }
}
