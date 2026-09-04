<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
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

        $this->seed(PermissaoDatabaseSeeder::class);
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

        $modulo = Modulo::where('nome', 0)->first();
        $acao = Acao::where('nome', 'eliminar')->first();
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

        $modulo = Modulo::where('nome', 0)->first();
        $acao = Acao::where('nome', 'eliminar')->first();

        $response = $this->put("/usuarios/{$professor->id}", [
            'name' => 'Prof Atualizado',
            'email' => 'prof2@example.com',
            'perfil' => 'funcionario',
            'celulas' => [
                ['modulo_id' => $modulo->id, 'acao_id' => $acao->id, 'permitido' => true],
            ],
        ]);

        $response->assertRedirect();
        $professor->refresh();
        $this->assertSame('Prof Atualizado', $professor->name);

        $roleFuncionario = Role::where('nome', Perfil::FUNCIONARIO->value)->first();
        $this->assertTrue($professor->roles->contains($roleFuncionario));
        $this->assertTrue($professor->roles->contains($roleProfessor), 'não deve remover o perfil anterior');

        $this->assertDatabaseHas('user_permissoes', [
            'users_id' => $professor->id,
            'modulo_id' => $modulo->id,
            'acao_id' => $acao->id,
            'permitido' => true,
        ]);
    }

    public function test_funcionario_nao_consegue_esconder_uma_concessao_de_autorizacao_ao_editar(): void
    {
        $funcionario = User::create(['name' => 'Funcionário', 'email' => 'funcionario@example.com', 'password' => Hash::make('x')]);
        $funcionario->roles()->attach(Role::where('nome', Perfil::FUNCIONARIO->value)->first()->id);
        $this->actingAs($funcionario);

        $professor = User::create(['name' => 'Prof', 'email' => 'prof3@example.com', 'password' => Hash::make('x'), 'tipo_login' => TipoLogin::EMAIL]);
        $professor->roles()->attach(Role::where('nome', Perfil::PROFESSOR->value)->first()->id);

        $moduloAutorizacao = Modulo::where('nome', 1)->first();
        $acaoEditar = Acao::where('nome', 'editar')->first();

        // O Funcionário tem usuario.editar (pode editar um Professor), mas
        // nunca devia conseguir, pelo mesmo pedido, conceder-lhe
        // autorizacao.editar via celulas.
        $response = $this->put("/usuarios/{$professor->id}", [
            'name' => 'Prof',
            'email' => 'prof3@example.com',
            'perfil' => 'professor',
            'celulas' => [
                ['modulo_id' => $moduloAutorizacao->id, 'acao_id' => $acaoEditar->id, 'permitido' => true],
            ],
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('user_permissoes', [
            'users_id' => $professor->id,
            'modulo_id' => $moduloAutorizacao->id,
            'acao_id' => $acaoEditar->id,
        ]);
    }
}
