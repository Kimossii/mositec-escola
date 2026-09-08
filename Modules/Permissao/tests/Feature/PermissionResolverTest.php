<?php

namespace Modules\Permissao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Enums\Estado;
use Modules\Permissao\Database\Seeders\AcaoSeeder;
use Modules\Permissao\Database\Seeders\ModuloSeeder;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo as ModuloRegistro;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Modules\Permissao\Models\UserPermissao;
use Modules\Permissao\Services\PermissionResolver;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class PermissionResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ModuloSeeder::class);
        $this->seed(AcaoSeeder::class);
    }

    private function criarUtilizadorComRole(?int $roleId): User
    {
        $user = User::create(['name' => 'U', 'email' => uniqid() . '@example.com', 'password' => Hash::make('x')]);
        if ($roleId !== null) {
            $user->roles()->attach($roleId);
        }

        return $user;
    }

    private function turmasCriar(): array
    {
        return [ModuloRegistro::where('nome', 6)->first(), Acao::where('nome', 'criar')->first()];
    }

    public function test_sem_role_e_sem_override_nega(): void
    {
        $user = $this->criarUtilizadorComRole(null);

        $this->assertFalse(app(PermissionResolver::class)->can($user, 'turmas.criar'));
    }

    public function test_role_concede_e_sem_override_concede(): void
    {
        [$modulo, $acao] = $this->turmasCriar();
        $role = Role::create(['nome' => 99, 'descricao' => 'Teste']);
        RolePermissao::create(['role_id' => $role->id, 'modulo_id' => $modulo->id, 'acao_id' => $acao->id]);
        $user = $this->criarUtilizadorComRole($role->id);

        $this->assertTrue(app(PermissionResolver::class)->can($user, 'turmas.criar'));
    }

    public function test_role_concede_mas_override_nega_vence(): void
    {
        [$modulo, $acao] = $this->turmasCriar();
        $role = Role::create(['nome' => 99, 'descricao' => 'Teste']);
        RolePermissao::create(['role_id' => $role->id, 'modulo_id' => $modulo->id, 'acao_id' => $acao->id]);
        $user = $this->criarUtilizadorComRole($role->id);
        UserPermissao::create(['users_id' => $user->id, 'modulo_id' => $modulo->id, 'acao_id' => $acao->id, 'permitido' => false]);

        $this->assertFalse(app(PermissionResolver::class)->can($user, 'turmas.criar'));
    }

    public function test_role_nao_concede_mas_override_concede_vence(): void
    {
        [$modulo, $acao] = $this->turmasCriar();
        $role = Role::create(['nome' => 99, 'descricao' => 'Teste']);
        $user = $this->criarUtilizadorComRole($role->id);
        UserPermissao::create(['users_id' => $user->id, 'modulo_id' => $modulo->id, 'acao_id' => $acao->id, 'permitido' => true]);

        $this->assertTrue(app(PermissionResolver::class)->can($user, 'turmas.criar'));
    }

    public function test_role_inactiva_nao_concede(): void
    {
        [$modulo, $acao] = $this->turmasCriar();
        $role = Role::create(['nome' => 99, 'descricao' => 'Teste', 'estado' => Estado::INATIVO->value]);
        RolePermissao::create(['role_id' => $role->id, 'modulo_id' => $modulo->id, 'acao_id' => $acao->id]);
        $user = $this->criarUtilizadorComRole($role->id);

        $this->assertFalse(app(PermissionResolver::class)->can($user, 'turmas.criar'));
    }

    public function test_conjunto_concedido_e_can_sao_consistentes(): void
    {
        [$modulo, $acao] = $this->turmasCriar();
        $role = Role::create(['nome' => 99, 'descricao' => 'Teste']);
        RolePermissao::create(['role_id' => $role->id, 'modulo_id' => $modulo->id, 'acao_id' => $acao->id]);
        $user = $this->criarUtilizadorComRole($role->id);

        $resolver = app(PermissionResolver::class);

        $this->assertContains('turmas.criar', $resolver->conjuntoConcedido($user));
        $this->assertTrue($resolver->can($user, 'turmas.criar'));
    }

    public function test_reconhece_confirma_modulo_e_acao_validos(): void
    {
        $resolver = app(PermissionResolver::class);

        $this->assertTrue($resolver->reconhece('turmas.criar'));
        $this->assertFalse($resolver->reconhece('turmas-inexistente.criar'));
        $this->assertFalse($resolver->reconhece('turmas.acao-inexistente'));
        $this->assertFalse($resolver->reconhece('semponto'));
    }

    public function test_permission_resolver_e_resolvido_como_singleton(): void
    {
        $this->assertSame(app(PermissionResolver::class), app(PermissionResolver::class));
    }
}
