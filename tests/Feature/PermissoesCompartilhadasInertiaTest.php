<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class PermissoesCompartilhadasInertiaTest extends TestCase
{
    use RefreshDatabase;

    public function test_utilizador_autenticado_recebe_o_conjunto_de_permissoes_partilhado(): void
    {
        $this->seed(PermissaoDatabaseSeeder::class);
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => Hash::make('x')]);
        $admin->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id);

        $response = $this->actingAs($admin)->get('/');

        $response->assertInertia(fn ($page) => $page
            ->has('permissoes')
            ->where('permissoes', fn ($permissoes) => $permissoes->contains('ano-lectivo.ver')));
    }

    public function test_visitante_nao_autenticado_recebe_lista_vazia(): void
    {
        $response = $this->get('/login');

        $response->assertInertia(fn ($page) => $page->where('permissoes', []));
    }
}
