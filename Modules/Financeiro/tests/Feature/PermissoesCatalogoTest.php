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

class PermissoesCatalogoTest extends TestCase
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

    public function test_modulos_tem_slug_e_label(): void
    {
        $this->assertSame('metodo-pagamento', Modulo::METODO_PAGAMENTO->slug());
        $this->assertSame('catalogo-financeiro', Modulo::CATALOGO_FINANCEIRO->slug());
        $this->assertSame(Modulo::METODO_PAGAMENTO, Modulo::fromSlug('metodo-pagamento'));
        $this->assertSame(Modulo::CATALOGO_FINANCEIRO, Modulo::fromSlug('catalogo-financeiro'));
        $this->assertSame('Método de Pagamento', Modulo::METODO_PAGAMENTO->label());
        $this->assertSame('Catálogo Financeiro', Modulo::CATALOGO_FINANCEIRO->label());
    }

    public function test_admin_escola_tem_as_quatro_accoes_nos_dois_modulos(): void
    {
        $admin = $this->utilizadorCom(Perfil::ADMIN_ESCOLA);

        foreach (['metodo-pagamento', 'catalogo-financeiro'] as $modulo) {
            foreach (['ver', 'criar', 'editar', 'eliminar'] as $acao) {
                $this->assertTrue(Gate::forUser($admin)->allows("{$modulo}.{$acao}"), "{$modulo}.{$acao}");
            }
        }
    }

    public function test_funcionario_e_professor_nao_acedem(): void
    {
        foreach ([Perfil::FUNCIONARIO, Perfil::PROFESSOR] as $perfil) {
            $user = $this->utilizadorCom($perfil);

            $this->assertFalse(Gate::forUser($user)->allows('metodo-pagamento.ver'), $perfil->name);
            $this->assertFalse(Gate::forUser($user)->allows('catalogo-financeiro.ver'), $perfil->name);
        }
    }
}
