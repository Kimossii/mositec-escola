<?php

namespace Modules\Permissao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Permissao\Actions\AlternarEstadoPerfilAction;
use Modules\Permissao\Actions\EliminarPerfilAction;
use Modules\Permissao\Actions\RemoverPerfilAction;
use Modules\Permissao\Actions\SincronizarPermissoesPerfilAction;
use Modules\Permissao\Actions\SincronizarPermissoesUtilizadorAction;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\UserPermissao;
use Modules\Usuario\Actions\EliminarUsuarioAction;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class GarantirAdministradorEfetivoTest extends TestCase
{
    use RefreshDatabase;

    private Role $adminRole;
    private Modulo $moduloAutorizacao;
    private Acao $acaoEditar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissaoDatabaseSeeder::class);
        $this->adminRole = Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first();
        $this->moduloAutorizacao = Modulo::where('nome', 1)->first();
        $this->acaoEditar = Acao::where('nome', 'editar')->first();
    }

    private function criarUnicoAdministrador(): User
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => Hash::make('x')]);
        $admin->roles()->attach($this->adminRole->id);

        return $admin;
    }

    public function test_nao_desactiva_perfil_se_for_a_unica_fonte_de_autorizacao_editar(): void
    {
        $this->criarUnicoAdministrador();

        $this->expectException(ValidationException::class);

        app(AlternarEstadoPerfilAction::class)->executar($this->adminRole);
    }

    public function test_desactiva_perfil_se_existir_outro_administrador_efetivo(): void
    {
        $this->criarUnicoAdministrador();

        // Segundo administrador, independente do perfil Admin Escola —
        // um override individual directo.
        $outro = User::create(['name' => 'Outro', 'email' => 'outro@example.com', 'password' => Hash::make('x')]);
        UserPermissao::create([
            'users_id' => $outro->id,
            'modulo_id' => $this->moduloAutorizacao->id,
            'acao_id' => $this->acaoEditar->id,
            'permitido' => true,
        ]);

        app(AlternarEstadoPerfilAction::class)->executar($this->adminRole);

        $this->assertSame(0, $this->adminRole->fresh()->estado);
    }

    public function test_nao_sincroniza_permissoes_do_perfil_removendo_autorizacao_editar_do_ultimo_administrador(): void
    {
        $this->criarUnicoAdministrador();

        $this->expectException(ValidationException::class);

        // Nova lista de permissões do perfil Admin Escola sem autorizacao.editar.
        app(SincronizarPermissoesPerfilAction::class)->executar($this->adminRole, []);
    }

    public function test_sincroniza_permissoes_do_perfil_mantendo_autorizacao_editar(): void
    {
        $this->criarUnicoAdministrador();

        app(SincronizarPermissoesPerfilAction::class)->executar($this->adminRole, [
            ['modulo_id' => $this->moduloAutorizacao->id, 'acao_id' => $this->acaoEditar->id],
        ]);

        $this->assertDatabaseHas('role_permissoes', [
            'role_id' => $this->adminRole->id,
            'modulo_id' => $this->moduloAutorizacao->id,
            'acao_id' => $this->acaoEditar->id,
        ]);
    }

    public function test_nao_remove_perfil_do_ultimo_administrador(): void
    {
        $admin = $this->criarUnicoAdministrador();

        $this->expectException(ValidationException::class);

        app(RemoverPerfilAction::class)->executar($admin, $this->adminRole);
    }

    public function test_remove_perfil_de_um_administrador_se_existir_outro_efetivo(): void
    {
        $admin = $this->criarUnicoAdministrador();
        $outro = User::create(['name' => 'Outro', 'email' => 'outro@example.com', 'password' => Hash::make('x')]);
        $outro->roles()->attach($this->adminRole->id);

        app(RemoverPerfilAction::class)->executar($admin, $this->adminRole);

        $this->assertFalse($admin->fresh()->roles->contains($this->adminRole));
    }

    public function test_nao_nega_autorizacao_editar_ao_ultimo_administrador_via_override(): void
    {
        $admin = $this->criarUnicoAdministrador();

        try {
            app(SincronizarPermissoesUtilizadorAction::class)->executar($admin, [
                ['modulo_id' => $this->moduloAutorizacao->id, 'acao_id' => $this->acaoEditar->id, 'permitido' => false],
            ]);
            $this->fail('Devia ter lançado ValidationException.');
        } catch (ValidationException $e) {
            // esperado
        }

        // A transacção deve ter sido revertida — nenhum override negado persistido.
        $this->assertDatabaseMissing('user_permissoes', [
            'users_id' => $admin->id,
            'modulo_id' => $this->moduloAutorizacao->id,
            'acao_id' => $this->acaoEditar->id,
            'permitido' => false,
        ]);
    }

    public function test_nao_elimina_o_ultimo_administrador(): void
    {
        $admin = $this->criarUnicoAdministrador();

        $this->expectException(ValidationException::class);

        app(EliminarUsuarioAction::class)->executar($admin);

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_elimina_um_administrador_se_existir_outro_efetivo(): void
    {
        $admin = $this->criarUnicoAdministrador();
        $outro = User::create(['name' => 'Outro', 'email' => 'outro@example.com', 'password' => Hash::make('x')]);
        $outro->roles()->attach($this->adminRole->id);

        app(EliminarUsuarioAction::class)->executar($admin);

        $this->assertDatabaseMissing('users', ['id' => $admin->id]);
    }

    public function test_mensagem_de_erro_chega_a_sessao_no_formato_que_o_inertia_entrega_ao_frontend(): void
    {
        // Cobre a camada que os testes acima (ao nível da Action) não tocam:
        // HTTP -> ValidationException -> sessão -> o que o Inertia expõe em
        // page.props.errors. O frontend já não tem texto fixo de fallback —
        // depende inteiramente desta mensagem chegar como string não-vazia
        // na chave certa, tal como Inertia\Middleware::resolveValidationErrors
        // a devolve ($errors->first($campo), nunca um array).
        $admin = $this->criarUnicoAdministrador();
        $this->actingAs($admin);

        $resposta = $this->put("/permissoes/perfis/{$this->adminRole->id}/permissoes", [
            'celulas' => [],
        ]);

        $resposta->assertSessionHasErrors('autorizacao');

        $mensagem = session('errors')->get('autorizacao')[0];
        $this->assertIsString($mensagem);
        $this->assertNotEmpty($mensagem);
        $this->assertStringContainsString('autorizacao.editar', $mensagem);

        // Nada foi persistido — a transacção reverteu.
        $this->assertDatabaseHas('role_permissoes', [
            'role_id' => $this->adminRole->id,
            'modulo_id' => $this->moduloAutorizacao->id,
            'acao_id' => $this->acaoEditar->id,
        ]);
    }

    public function test_elimina_perfil_personalizado_sem_utilizadores_mesmo_com_o_guardiao_activo(): void
    {
        // Um perfil personalizado sem ninguém atribuído nunca pode ser a
        // fonte de um lockout (a regra "tem utilizadores atribuídos" já
        // impede eliminar um perfil em uso) — confirma que o guardião
        // anti-lockout, agora também presente em EliminarPerfilAction, não
        // interfere no caso normal.
        $this->criarUnicoAdministrador();
        $personalizado = Role::create(['nome' => Role::PERFIL_PERSONALIZADO, 'descricao' => 'Director-Geral', 'estado' => 1]);

        app(EliminarPerfilAction::class)->executar($personalizado);

        $this->assertDatabaseMissing('roles', ['id' => $personalizado->id]);
    }
}
