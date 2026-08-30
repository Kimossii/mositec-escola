<?php

namespace Modules\Permissao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        $adminRole = Role::create(['nome' => 0, 'descricao' => 'Admin escola', 'estado' => 1]);
        $staff->roles()->attach($adminRole->id);

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
        $modulo = Modulo::create(['nome' => 0, 'descricao' => 'Usuario', 'estado' => 1]);
        $acao = Acao::create(['nome' => 'eliminar', 'numero' => 3, 'estado' => 1]);

        $response = $this->put("/permissoes/utilizadores/{$user->id}/permissoes", [
            'celulas' => [['modulo_id' => $modulo->id, 'acao_id' => $acao->id, 'permitido' => false]],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('user_permissoes', ['users_id' => $user->id, 'modulo_id' => $modulo->id, 'acao_id' => $acao->id, 'permitido' => false]);
    }
}
