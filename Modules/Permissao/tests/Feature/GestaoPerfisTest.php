<?php

namespace Modules\Permissao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class GestaoPerfisTest extends TestCase
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

    public function test_utilizador_sem_perfil_admin_escola_nao_acede_ao_cadastro_de_perfis(): void
    {
        $semPermissao = User::create(['name' => 'Professor', 'email' => 'professor.sem.perm@example.com', 'password' => Hash::make('x')]);
        $this->actingAs($semPermissao);

        $this->get('/permissoes/perfis')->assertForbidden();
        $this->post('/permissoes/perfis', ['descricao' => 'Diretor'])->assertForbidden();
    }

    public function test_e_sistema_distingue_perfis_seedados_de_personalizados(): void
    {
        $sistema = Role::create(['nome' => 0, 'descricao' => 'Admin escola', 'estado' => 1]);
        $personalizado = Role::create(['nome' => Role::PERFIL_PERSONALIZADO, 'descricao' => 'Diretor', 'estado' => 1]);

        $this->assertTrue($sistema->eSistema());
        $this->assertFalse($personalizado->eSistema());
    }

    public function test_cria_um_perfil_personalizado(): void
    {
        $response = $this->post('/permissoes/perfis', ['descricao' => 'Diretor', 'estado' => 1]);

        $response->assertRedirect();
        $this->assertDatabaseHas('roles', ['descricao' => 'Diretor', 'nome' => Role::PERFIL_PERSONALIZADO]);
    }

    public function test_nao_elimina_perfil_de_sistema(): void
    {
        $role = Role::create(['nome' => 0, 'descricao' => 'Admin escola', 'estado' => 1]);

        $response = $this->delete("/permissoes/perfis/{$role->id}");

        $response->assertSessionHasErrors('perfil');
        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_nao_elimina_perfil_personalizado_com_utilizadores(): void
    {
        $role = Role::create(['nome' => Role::PERFIL_PERSONALIZADO, 'descricao' => 'Diretor', 'estado' => 1]);
        $user = User::create(['name' => 'X', 'email' => 'x@example.com', 'password' => Hash::make('x')]);
        $user->roles()->attach($role->id);

        $response = $this->delete("/permissoes/perfis/{$role->id}");

        $response->assertSessionHasErrors('perfil');
        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_elimina_perfil_personalizado_sem_utilizadores(): void
    {
        $role = Role::create(['nome' => Role::PERFIL_PERSONALIZADO, 'descricao' => 'Diretor', 'estado' => 1]);

        $response = $this->delete("/permissoes/perfis/{$role->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_sincroniza_permissoes_do_perfil_via_endpoint(): void
    {
        $role = Role::create(['nome' => Role::PERFIL_PERSONALIZADO, 'descricao' => 'Diretor', 'estado' => 1]);
        $modulo = Modulo::create(['nome' => 0, 'descricao' => 'Usuario', 'estado' => 1]);
        $acao = Acao::create(['nome' => 'ver', 'numero' => 0, 'estado' => 1]);

        $response = $this->put("/permissoes/perfis/{$role->id}/permissoes", [
            'celulas' => [['modulo_id' => $modulo->id, 'acao_id' => $acao->id]],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('role_permissoes', ['role_id' => $role->id, 'modulo_id' => $modulo->id, 'acao_id' => $acao->id]);
    }
}
