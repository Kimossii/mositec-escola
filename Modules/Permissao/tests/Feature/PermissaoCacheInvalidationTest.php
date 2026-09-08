<?php

namespace Modules\Permissao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Enums\Estado;
use Modules\Permissao\Actions\AtribuirPerfilAction;
use Modules\Permissao\Actions\AtualizarPerfilAction;
use Modules\Permissao\Actions\RemoverPerfilAction;
use Modules\Permissao\Actions\SincronizarPermissoesPerfilAction;
use Modules\Permissao\Actions\SincronizarPermissoesUtilizadorAction;
use Modules\Permissao\Database\Seeders\AcaoSeeder;
use Modules\Permissao\Database\Seeders\ModuloSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo as ModuloRegistro;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Modules\Permissao\Models\UserPermissao;
use Modules\Permissao\Services\PermissionResolver;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class PermissaoCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ModuloSeeder::class);
        $this->seed(AcaoSeeder::class);

        // Garante um administrador efectivo à parte de tudo o resto que os
        // testes fazem, senão o guardião anti-lockout bloquearia os syncs
        // de perfil/utilizador exercidos aqui.
        $adminRole = Role::create(['nome' => Perfil::ADMIN_ESCOLA->value, 'descricao' => 'Admin escola']);
        $modulo = ModuloRegistro::where('nome', 1)->first();
        $acao = Acao::where('nome', 'editar')->first();
        RolePermissao::create(['role_id' => $adminRole->id, 'modulo_id' => $modulo->id, 'acao_id' => $acao->id]);
        $admin = User::create(['name' => 'Admin', 'email' => 'admin.fixture@example.com', 'password' => Hash::make('x')]);
        $admin->roles()->attach($adminRole->id);
    }

    public function test_sincronizar_permissoes_do_perfil_invalida_a_cache_de_quem_tem_essa_role(): void
    {
        $role = Role::create(['nome' => 99, 'descricao' => 'Teste']);
        $user = User::create(['name' => 'U', 'email' => 'u1@example.com', 'password' => Hash::make('x')]);
        $user->roles()->attach($role->id);

        $resolver = app(PermissionResolver::class);
        $this->assertFalse($resolver->can($user, 'turmas.criar')); // calcula e guarda em cache o "não"

        $modulo = ModuloRegistro::where('nome', 6)->first();
        $acao = Acao::where('nome', 'criar')->first();
        app(SincronizarPermissoesPerfilAction::class)->executar($role, [
            ['modulo_id' => $modulo->id, 'acao_id' => $acao->id],
        ]);

        $this->assertTrue(app(PermissionResolver::class)->can($user, 'turmas.criar'));
    }

    public function test_sincronizar_permissoes_do_utilizador_invalida_so_esse_utilizador(): void
    {
        $userA = User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => Hash::make('x')]);
        $userB = User::create(['name' => 'B', 'email' => 'b@example.com', 'password' => Hash::make('x')]);

        $resolver = app(PermissionResolver::class);
        $this->assertFalse($resolver->can($userA, 'turmas.criar'));
        $this->assertFalse($resolver->can($userB, 'turmas.criar'));

        $modulo = ModuloRegistro::where('nome', 6)->first();
        $acao = Acao::where('nome', 'criar')->first();
        app(SincronizarPermissoesUtilizadorAction::class)->executar($userA, [
            ['modulo_id' => $modulo->id, 'acao_id' => $acao->id, 'permitido' => true],
        ]);

        $this->assertTrue(app(PermissionResolver::class)->can($userA, 'turmas.criar'));

        // Prova estrutural de que a invalidação foi por utilizador (userA), não global:
        // concede directamente (sem passar por nenhuma Action, logo sem invalidar nada)
        // uma nova permissão a userB. Note que NÃO voltamos a chamar
        // PermissionResolver::can($userB, ...) entre o sync de userA e este insert directo
        // — fazê-lo forçaria, no cenário com bug (invalidarTudo), um recálculo que
        // encontraria "sem permissões" (ainda sem o insert) e voltaria a guardar esse
        // resultado em cache, mascarando a diferença que queremos detectar. Em vez disso,
        // deixamos a entrada de cache de userB intocada desde antes do sync (linha acima)
        // e só a interrogamos depois do insert directo.
        //
        // Se SincronizarPermissoesUtilizadorAction tivesse chamado invalidarTudo() em vez
        // de esquecerUtilizador($userA->id), a cache de userB também teria sido invalidada,
        // e a leitura abaixo recalcularia a partir da base de dados — já incluindo o
        // permitido=true que acabámos de inserir — fazendo este assertFalse falhar (true).
        $acaoEditar = Acao::where('nome', 'editar')->first();
        UserPermissao::create([
            'users_id' => $userB->id,
            'modulo_id' => $modulo->id,
            'acao_id' => $acaoEditar->id,
            'permitido' => true,
        ]);

        $this->assertFalse(app(PermissionResolver::class)->can($userB, 'turmas.editar'));
    }

    public function test_atribuir_e_remover_perfil_invalidam_o_utilizador(): void
    {
        $role = Role::create(['nome' => 99, 'descricao' => 'Teste']);
        $modulo = ModuloRegistro::where('nome', 6)->first();
        $acao = Acao::where('nome', 'criar')->first();
        app(SincronizarPermissoesPerfilAction::class)->executar($role, [
            ['modulo_id' => $modulo->id, 'acao_id' => $acao->id],
        ]);

        $user = User::create(['name' => 'U', 'email' => 'u2@example.com', 'password' => Hash::make('x')]);
        $resolver = app(PermissionResolver::class);
        $this->assertFalse($resolver->can($user, 'turmas.criar'));

        app(AtribuirPerfilAction::class)->executar($user, $role->id);
        $this->assertTrue(app(PermissionResolver::class)->can($user, 'turmas.criar'));

        app(RemoverPerfilAction::class)->executar($user, $role);
        $this->assertFalse(app(PermissionResolver::class)->can($user, 'turmas.criar'));
    }

    public function test_atualizar_perfil_para_inativo_invalida_a_cache_de_quem_tem_essa_role(): void
    {
        $role = Role::create(['nome' => 99, 'descricao' => 'Teste', 'estado' => Estado::ATIVO->value]);
        $modulo = ModuloRegistro::where('nome', 6)->first();
        $acao = Acao::where('nome', 'criar')->first();
        RolePermissao::create(['role_id' => $role->id, 'modulo_id' => $modulo->id, 'acao_id' => $acao->id]);

        $user = User::create(['name' => 'U', 'email' => 'u3@example.com', 'password' => Hash::make('x')]);
        $user->roles()->attach($role->id);

        $this->assertTrue(app(PermissionResolver::class)->can($user, 'turmas.criar')); // calcula e guarda em cache o "sim"

        app(AtualizarPerfilAction::class)->executar($role, [
            'descricao' => $role->descricao,
            'estado' => Estado::INATIVO->value,
        ]);

        $this->assertFalse(app(PermissionResolver::class)->can($user, 'turmas.criar'));
    }
}
