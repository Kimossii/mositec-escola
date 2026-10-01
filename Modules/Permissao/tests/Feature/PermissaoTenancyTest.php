<?php

namespace Modules\Permissao\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Core\Tenancy\Exceptions\AlteracaoDeTenantProibida;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;
use Modules\Core\Tenancy\TenantContext;
use Modules\Permissao\Actions\GarantirAdministradorEfetivoAction;
use Modules\Permissao\Actions\SincronizarPermissoesPerfilAction;
use Modules\Permissao\Actions\SincronizarPermissoesUtilizadorAction;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Modules\Permissao\Models\UserPermissao;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class PermissaoTenancyTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $email): User
    {
        $user = User::create(['name' => 'Admin', 'email' => $email, 'password' => Hash::make('x')]);
        $user->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->firstOrFail()->id);

        return $user;
    }

    public function test_cada_tenant_tem_os_seus_perfis_e_permissoes(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->seed(PermissaoDatabaseSeeder::class);
        $this->noTenant($outro, fn () => $this->seed(PermissaoDatabaseSeeder::class));

        $this->assertSame([], Role::pluck('id')->intersect($this->noTenant($outro, fn () => Role::pluck('id')))->all());
        $this->assertGreaterThan(0, RolePermissao::count());
    }

    public function test_perfil_de_outro_tenant_nao_e_encontrado_por_id(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $idDeB = $this->noTenant($outro, fn () => Role::create(['nome' => Role::PERFIL_PERSONALIZADO, 'descricao' => 'B'])->id);

        $this->assertNull(Role::find($idDeB));
    }

    public function test_atribuir_perfil_grava_o_tenant_na_tabela_pivot(): void
    {
        $this->seed(PermissaoDatabaseSeeder::class);
        $user = $this->admin('a@example.com');

        $this->assertSame(
            $this->tenant->id,
            (int) $user->roles()->first()->pivot->tenant_id,
        );
    }

    public function test_sincronizar_permissoes_grava_tenant_id_nas_escritas_em_massa(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->seed(PermissaoDatabaseSeeder::class);
        $this->noTenant($outro, fn () => $this->seed(PermissaoDatabaseSeeder::class));

        $modulo = Modulo::firstOrFail()->id;
        $acao = Acao::firstOrFail()->id;
        $user = User::create(['name' => 'U', 'email' => 'u@example.com', 'password' => Hash::make('x')]);
        $this->admin('admin-a@example.com');

        $this->noTenant($outro, function () use ($modulo, $acao) {
            $this->admin('admin-b@example.com');
            $perfil = Role::create(['nome' => Role::PERFIL_PERSONALIZADO, 'descricao' => 'B']);
            $utilizador = User::create(['name' => 'UB', 'email' => 'ub@example.com', 'password' => Hash::make('x')]);
            app(SincronizarPermissoesPerfilAction::class)->executar($perfil, [['modulo_id' => $modulo, 'acao_id' => $acao]]);
            app(SincronizarPermissoesUtilizadorAction::class)->executar($utilizador, [['modulo_id' => $modulo, 'acao_id' => $acao, 'permitido' => true]]);
        });

        $perfilA = Role::create(['nome' => Role::PERFIL_PERSONALIZADO, 'descricao' => 'A']);
        RolePermissao::where('role_id', $perfilA->id)->delete();
        app(SincronizarPermissoesPerfilAction::class)->executar($perfilA, [['modulo_id' => $modulo, 'acao_id' => $acao]]);
        app(SincronizarPermissoesUtilizadorAction::class)->executar($user, [['modulo_id' => $modulo, 'acao_id' => $acao, 'permitido' => true]]);

        $this->assertSame([$this->tenant->id], RolePermissao::where('role_id', $perfilA->id)->pluck('tenant_id')->unique()->all());
        $this->assertSame([$this->tenant->id], UserPermissao::pluck('tenant_id')->unique()->all());
        $this->assertSame(1, UserPermissao::count());

        $this->noTenant($outro, function () use ($outro) {
            $this->assertSame([$outro->id], UserPermissao::pluck('tenant_id')->unique()->all());
            $this->assertSame(1, UserPermissao::count());
            $this->assertSame([$outro->id], RolePermissao::pluck('tenant_id')->unique()->all());
        });
    }

    public function test_alterar_o_tenant_de_um_perfil_e_proibido(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $role = Role::create(['nome' => Role::PERFIL_PERSONALIZADO, 'descricao' => 'A']);

        $this->expectException(AlteracaoDeTenantProibida::class);

        $role->forceFill(['tenant_id' => $outro->id])->save();
    }

    public function test_sem_contexto_os_models_lancam(): void
    {
        app(TenantContext::class)->limpar();

        $this->expectException(TenantNaoResolvido::class);

        Role::count();
    }

    public function test_a_anti_perda_de_acesso_conta_so_administradores_do_tenant_corrente(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->seed(PermissaoDatabaseSeeder::class);
        $this->noTenant($outro, function () {
            $this->seed(PermissaoDatabaseSeeder::class);
            $this->admin('b@example.com');
        });

        // No tenant A não há nenhum administrador: o de B não pode satisfazer a verificação.
        $this->expectException(ValidationException::class);

        app(GarantirAdministradorEfetivoAction::class)->verificar();
    }

    public function test_a_bd_rejeita_um_perfil_sem_tenant(): void
    {
        $this->expectException(QueryException::class);

        DB::table('roles')->insert(['nome' => 0, 'estado' => 1, 'estado_descricao' => 'Ativo']);
    }
}
