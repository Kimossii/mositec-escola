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

/** A conta de encarregado só usa as permissões do perfil: sem overrides e sem perfis extra. */
class PerfilDeEncarregadoFixoTest extends TestCase
{
    use RefreshDatabase;

    private User $encarregado;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissaoDatabaseSeeder::class);

        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => Hash::make('x')]);
        $admin->roles()->attach($this->roleId(Perfil::ADMIN_ESCOLA));

        $this->encarregado = User::create(['name' => 'Encarregado', 'email' => 'enc@example.com', 'password' => Hash::make('x')]);
        $this->encarregado->roles()->attach($this->roleId(Perfil::ENCARREGADO));

        $this->actingAs($admin);
    }

    private function roleId(Perfil $perfil): int
    {
        return Role::where('nome', $perfil->value)->value('id');
    }

    public function test_ecra_de_permissoes_do_encarregado_e_recusado(): void
    {
        $this->get("/permissoes/utilizadores/{$this->encarregado->id}/permissoes")->assertForbidden();
    }

    public function test_guardar_overrides_de_encarregado_e_recusado(): void
    {
        $this->put("/permissoes/utilizadores/{$this->encarregado->id}/permissoes", [
            'celulas' => [['modulo_id' => Modulo::first()->id, 'acao_id' => Acao::first()->id, 'permitido' => true]],
        ])->assertSessionHasErrors('celulas');

        $this->assertDatabaseMissing('user_permissoes', ['users_id' => $this->encarregado->id]);
    }

    public function test_encarregado_nao_recebe_perfil_extra(): void
    {
        $this->post("/permissoes/utilizadores/{$this->encarregado->id}/perfis", ['role_id' => $this->roleId(Perfil::FUNCIONARIO)])
            ->assertSessionHasErrors('role_id');

        $this->assertSame(1, $this->encarregado->roles()->count());
    }

    public function test_perfil_encarregado_nao_e_atribuido_a_outros_utilizadores(): void
    {
        $prof = User::create(['name' => 'Prof', 'email' => 'prof@example.com', 'password' => Hash::make('x')]);
        $prof->roles()->attach($this->roleId(Perfil::PROFESSOR));

        $this->post("/permissoes/utilizadores/{$prof->id}/perfis", ['role_id' => $this->roleId(Perfil::ENCARREGADO)])
            ->assertSessionHasErrors('role_id');

        $this->assertFalse($prof->roles()->where('nome', Perfil::ENCARREGADO->value)->exists());
    }

    public function test_overrides_antigos_de_encarregado_nao_tem_efeito(): void
    {
        $modulo = Modulo::where('nome', 0)->first();
        $acao = Acao::where('nome', 'eliminar')->first();
        $this->encarregado->permissoes()->create(['modulo_id' => $modulo->id, 'acao_id' => $acao->id, 'permitido' => true]);

        $this->assertNotContains('usuario.eliminar', app(PermissionResolver::class)->conjuntoConcedido($this->encarregado));
    }
}
