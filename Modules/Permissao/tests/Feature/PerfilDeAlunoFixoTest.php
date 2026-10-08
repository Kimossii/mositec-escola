<?php

namespace Modules\Permissao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Services\PermissionResolver;
use Modules\Usuario\Models\User;
use Tests\TestCase;

/** A conta de aluno só usa as permissões do perfil: sem overrides e sem perfis extra. */
class PerfilDeAlunoFixoTest extends TestCase
{
    use RefreshDatabase;

    private User $aluno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissaoDatabaseSeeder::class);

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => Hash::make('x')]);
        $admin->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->value('id'));

        $this->aluno = User::create(['name' => 'Aluno', 'numero_matricula' => '2026-9001', 'password' => Hash::make('x')]);
        $this->aluno->roles()->attach($this->roleId(Perfil::ALUNO));

        $this->actingAs($admin);
    }

    private function roleId(Perfil $perfil): int
    {
        return Role::where('nome', $perfil->value)->value('id');
    }

    public function test_ecra_de_permissoes_do_aluno_e_recusado(): void
    {
        $this->get("/permissoes/utilizadores/{$this->aluno->id}/permissoes")->assertForbidden();
    }

    public function test_guardar_overrides_de_aluno_e_recusado(): void
    {
        $modulo = Modulo::first();
        $acao = Acao::first();

        $this->put("/permissoes/utilizadores/{$this->aluno->id}/permissoes", [
            'celulas' => [['modulo_id' => $modulo->id, 'acao_id' => $acao->id, 'permitido' => true]],
        ])->assertSessionHasErrors('celulas');

        $this->assertDatabaseMissing('user_permissoes', ['users_id' => $this->aluno->id]);
    }

    public function test_aluno_nao_recebe_perfil_extra(): void
    {
        $this->post("/permissoes/utilizadores/{$this->aluno->id}/perfis", ['role_id' => $this->roleId(Perfil::FUNCIONARIO)])
            ->assertSessionHasErrors('role_id');

        $this->assertSame(1, $this->aluno->roles()->count());
    }

    public function test_perfil_aluno_nao_e_atribuido_a_outros_utilizadores(): void
    {
        $prof = User::create(['name' => 'Prof', 'email' => 'prof@example.com', 'password' => Hash::make('x')]);
        $prof->roles()->attach($this->roleId(Perfil::PROFESSOR));

        $this->post("/permissoes/utilizadores/{$prof->id}/perfis", ['role_id' => $this->roleId(Perfil::ALUNO)])
            ->assertSessionHasErrors('role_id');

        $this->assertFalse($prof->roles()->where('nome', Perfil::ALUNO->value)->exists());
    }

    public function test_editar_nao_muda_o_perfil_de_um_aluno(): void
    {
        $this->put("/usuarios/{$this->aluno->id}", ['perfil' => 'funcionario'])
            ->assertSessionHasErrors('perfil');
    }

    public function test_overrides_antigos_de_aluno_nao_tem_efeito(): void
    {
        $modulo = Modulo::where('nome', 0)->first();
        $acao = Acao::where('nome', 'eliminar')->first();
        $this->aluno->permissoes()->create(['modulo_id' => $modulo->id, 'acao_id' => $acao->id, 'permitido' => true]);

        $this->assertNotContains('usuario.eliminar', app(PermissionResolver::class)->conjuntoConcedido($this->aluno));
    }
}
