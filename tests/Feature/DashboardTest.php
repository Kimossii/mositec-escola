<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function utilizador(?Perfil $perfil): User
    {
        $this->seed(PermissaoDatabaseSeeder::class);
        $user = User::create(['name' => 'Func', 'email' => 'func@example.com', 'password' => Hash::make('x')]);

        if ($perfil !== null) {
            $user->roles()->attach(Role::where('nome', $perfil->value)->first()->id);
        }

        return $user;
    }

    public function test_administrador_ve_todos_os_cartoes_e_a_atencao(): void
    {
        $response = $this->actingAs($this->utilizador(Perfil::ADMIN_ESCOLA))->get('/');
        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $c) => $c
            ->has('indicadores.cartoes', 4)
            ->where('indicadores.cartoes.0.rotulo', 'Alunos activos')
            ->has('atencao')
            ->has('graficos.propinas_mes')
            ->has('graficos.alunos_curso')
            ->has('ultimosPagamentos')));
    }

    public function test_sem_permissoes_nao_ha_cartoes_nem_atencao(): void
    {
        $response = $this->actingAs($this->utilizador(null))->get('/');
        $response->assertOk();

        $response->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $c) => $c
            ->has('indicadores.cartoes', 0)
            ->has('atencao', 0)
            ->has('graficos', 0)
            ->has('ultimosPagamentos', 0)));
    }
}
