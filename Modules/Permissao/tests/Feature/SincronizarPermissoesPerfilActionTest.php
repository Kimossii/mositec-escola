<?php

namespace Modules\Permissao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Actions\SincronizarPermissoesPerfilAction;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class SincronizarPermissoesPerfilActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Garante um administrador efectivo, senão o guardião anti-lockout
        // (GarantirAdministradorEfetivoAction) bloquearia estes syncs, já
        // que este teste não usa mais ninguém com autorizacao.editar.
        $this->seed(PermissaoDatabaseSeeder::class);
        $admin = User::create(['name' => 'Admin', 'email' => 'admin.fixture@example.com', 'password' => Hash::make('x')]);
        $admin->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id);
    }

    public function test_sincroniza_permissoes_do_perfil_substituindo_o_estado_anterior(): void
    {
        $role = Role::create(['nome' => Role::PERFIL_PERSONALIZADO, 'descricao' => 'Diretor', 'estado' => 1]);
        $modulo1 = Modulo::create(['nome' => 0, 'descricao' => 'Usuario', 'estado' => 1]);
        $modulo2 = Modulo::create(['nome' => 1, 'descricao' => 'Aluno', 'estado' => 1]);
        $acaoVer = Acao::create(['nome' => 'ver', 'numero' => 0, 'estado' => 1]);
        $acaoCriar = Acao::create(['nome' => 'criar', 'numero' => 1, 'estado' => 1]);

        RolePermissao::create(['role_id' => $role->id, 'modulo_id' => $modulo1->id, 'acao_id' => $acaoVer->id]);

        app(SincronizarPermissoesPerfilAction::class)->executar($role, [
            ['modulo_id' => $modulo2->id, 'acao_id' => $acaoCriar->id],
        ]);

        $this->assertDatabaseMissing('role_permissoes', ['role_id' => $role->id, 'modulo_id' => $modulo1->id, 'acao_id' => $acaoVer->id]);
        $this->assertDatabaseHas('role_permissoes', ['role_id' => $role->id, 'modulo_id' => $modulo2->id, 'acao_id' => $acaoCriar->id]);
        $this->assertSame(1, RolePermissao::where('role_id', $role->id)->count());
    }

    public function test_sincronizar_com_lista_vazia_apaga_todas_as_permissoes(): void
    {
        $role = Role::create(['nome' => Role::PERFIL_PERSONALIZADO, 'descricao' => 'Diretor', 'estado' => 1]);
        $modulo = Modulo::create(['nome' => 0, 'descricao' => 'Usuario', 'estado' => 1]);
        $acao = Acao::create(['nome' => 'ver', 'numero' => 0, 'estado' => 1]);

        RolePermissao::create(['role_id' => $role->id, 'modulo_id' => $modulo->id, 'acao_id' => $acao->id]);

        app(SincronizarPermissoesPerfilAction::class)->executar($role, []);

        $this->assertSame(0, RolePermissao::where('role_id', $role->id)->count());
    }
}
