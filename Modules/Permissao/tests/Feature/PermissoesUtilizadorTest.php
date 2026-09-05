<?php

namespace Modules\Permissao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class PermissoesUtilizadorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissaoDatabaseSeeder::class);

        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        $staff->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id);

        $this->actingAs($staff);
    }

    public function test_atribui_e_remove_perfil_de_um_utilizador(): void
    {
        $user = User::create(['name' => 'Prof', 'email' => 'prof4@example.com', 'password' => Hash::make('x')]);
        $role = Role::create(['nome' => Role::PERFIL_PERSONALIZADO, 'descricao' => 'Diretor', 'estado' => 1]);

        $this->post("/permissoes/utilizadores/{$user->id}/perfis", ['role_id' => $role->id])
            ->assertRedirect();
        $this->assertTrue($user->fresh()->roles->contains($role));

        $this->delete("/permissoes/utilizadores/{$user->id}/perfis/{$role->id}")
            ->assertRedirect();
        $this->assertFalse($user->fresh()->roles->contains($role));
    }

    public function test_sincroniza_overrides_do_utilizador_via_endpoint(): void
    {
        $user = User::create(['name' => 'Prof', 'email' => 'prof5@example.com', 'password' => Hash::make('x')]);
        $modulo = Modulo::where('nome', 0)->first();
        $acao = Acao::where('nome', 'eliminar')->first();

        $response = $this->put("/permissoes/utilizadores/{$user->id}/permissoes", [
            'celulas' => [['modulo_id' => $modulo->id, 'acao_id' => $acao->id, 'permitido' => false]],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('user_permissoes', ['users_id' => $user->id, 'modulo_id' => $modulo->id, 'acao_id' => $acao->id, 'permitido' => false]);
    }
}
