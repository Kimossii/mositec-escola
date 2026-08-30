<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Database\Seeders\RoleSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Enums\TipoLogin;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class AtualizarUsuarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function actingAsStaff(): User
    {
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        $staff->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id);
        $this->actingAs($staff);

        return $staff;
    }

    public function test_edit_devolve_dados_atuais_do_utilizador(): void
    {
        $this->actingAsStaff();

        $professor = User::create(['name' => 'Prof', 'email' => 'prof@example.com', 'password' => Hash::make('x'), 'tipo_login' => TipoLogin::EMAIL]);
        $roleProfessor = Role::where('nome', Perfil::PROFESSOR->value)->first();
        $professor->roles()->attach($roleProfessor->id);

        $modulo = Modulo::create(['nome' => 0, 'descricao' => 'Usuario', 'estado' => 1]);
        $acao = Acao::create(['nome' => 'eliminar', 'numero' => 3, 'estado' => 1]);
        $professor->permissoes()->create(['modulo_id' => $modulo->id, 'acao_id' => $acao->id, 'permitido' => false]);

        $response = $this->getJson("/usuarios/{$professor->id}/editar");

        $response->assertOk();
        $response->assertJson([
            'name' => 'Prof',
            'email' => 'prof@example.com',
            'tipo_login' => 'email',
            'perfil' => 'professor',
        ]);
        $response->assertJsonFragment(['modulo_id' => $modulo->id, 'acao_id' => $acao->id, 'permitido' => false]);
    }

    public function test_atualiza_nome_email_perfil_e_overrides(): void
    {
        $this->actingAsStaff();

        $professor = User::create(['name' => 'Prof', 'email' => 'prof2@example.com', 'password' => Hash::make('x'), 'tipo_login' => TipoLogin::EMAIL]);
        $roleProfessor = Role::where('nome', Perfil::PROFESSOR->value)->first();
        $professor->roles()->attach($roleProfessor->id);

        $modulo = Modulo::create(['nome' => 0, 'descricao' => 'Usuario', 'estado' => 1]);
        $acao = Acao::create(['nome' => 'eliminar', 'numero' => 3, 'estado' => 1]);

        $response = $this->put("/usuarios/{$professor->id}", [
            'name' => 'Prof Atualizado',
            'email' => 'prof2@example.com',
            'perfil' => 'secretario',
            'celulas' => [
                ['modulo_id' => $modulo->id, 'acao_id' => $acao->id, 'permitido' => true],
            ],
        ]);

        $response->assertRedirect();
        $professor->refresh();
        $this->assertSame('Prof Atualizado', $professor->name);

        $roleSecretario = Role::where('nome', Perfil::SECRETARIO->value)->first();
        $this->assertTrue($professor->roles->contains($roleSecretario));
        $this->assertTrue($professor->roles->contains($roleProfessor), 'não deve remover o perfil anterior');

        $this->assertDatabaseHas('user_permissoes', [
            'users_id' => $professor->id,
            'modulo_id' => $modulo->id,
            'acao_id' => $acao->id,
            'permitido' => true,
        ]);
    }
}
