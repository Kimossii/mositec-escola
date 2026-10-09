<?php

namespace Modules\Permissao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Permissao\Actions\SincronizarPermissoesPerfilAction;
use Modules\Permissao\Actions\SincronizarPermissoesUtilizadorAction;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Modulo as ModuloEnum;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Modules\Permissao\Models\UserPermissao;
use Modules\Permissao\Support\AcoesAplicaveis;
use Modules\Permissao\Services\PermissionResolver;
use Modules\Permissao\Support\PermissaoCache;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class AcoesAplicaveisTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = ['ver', 'criar', 'editar', 'eliminar', 'listar', 'exportar'];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissaoDatabaseSeeder::class);

        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin.aplic@example.com', 'password' => Hash::make('x')]);
        $this->admin->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id);
    }

    private function moduloId(ModuloEnum $m): int
    {
        return Modulo::where('nome', $m->value)->value('id');
    }

    private function acaoId(string $nome): int
    {
        return Acao::where('nome', $nome)->value('id');
    }

    /** Fixture de um módulo futuro: declara as acções extra (ex.: PROPINA). */
    private function declararExtras(ModuloEnum $modulo, array $extras): void
    {
        $this->app->instance(AcoesAplicaveis::class, new class($modulo, $extras) extends AcoesAplicaveis {
            public function __construct(private ModuloEnum $alvo, private array $extras)
            {
            }

            public function doModulo(ModuloEnum $modulo): array
            {
                return $modulo === $this->alvo ? [...parent::doModulo($modulo), ...$this->extras] : parent::doModulo($modulo);
            }
        });
    }

    public function test_todos_os_modulos_existentes_aplicam_exactamente_as_acoes_actuais(): void
    {
        foreach (ModuloEnum::cases() as $modulo) {
            $this->assertEqualsCanonicalizing(self::BASE, $modulo->acoesAplicaveis(), $modulo->name);
        }
    }

    public function test_catalogo_inclui_as_novas_acoes_sem_duplicar(): void
    {
        foreach (['confirmar', 'anular', 'cancelar', 'ajustar', 'negociar', 'isentar-multa'] as $nome) {
            $this->assertDatabaseHas('acoes', ['nome' => $nome]);
        }
        $this->assertSame(12, Acao::count());
    }

    public function test_grelha_do_perfil_mostra_so_acoes_aplicaveis(): void
    {
        $role = Role::create(['nome' => Role::PERFIL_PERSONALIZADO, 'descricao' => 'X', 'estado' => 1]);

        $this->actingAs($this->admin)->get("/permissoes/perfis/{$role->id}/permissoes")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('acoes', 6)
                ->where('acoes.0.nome', 'ver')
                ->has('modulos', 22)
                ->where('modulos.0.acoes', fn ($ids) => collect($ids)->sort()->values()->all()
                    === Acao::whereIn('nome', self::BASE)->pluck('id')->sort()->values()->all()));
    }

    public function test_grelha_do_utilizador_mostra_so_acoes_aplicaveis(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'u.aplic@example.com', 'password' => Hash::make('x')]);

        $this->actingAs($this->admin)->get("/permissoes/utilizadores/{$user->id}/permissoes")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('acoes', 6)
                ->has('modulos.0.acoes', 6));
    }

    public function test_modulo_futuro_com_extras_aparece_na_grelha_so_nele(): void
    {
        $this->declararExtras(ModuloEnum::PLANO_PROPINA, ['cancelar', 'anular']);
        $role = Role::create(['nome' => Role::PERFIL_PERSONALIZADO, 'descricao' => 'X', 'estado' => 1]);

        $this->actingAs($this->admin)->get("/permissoes/perfis/{$role->id}/permissoes")
            ->assertInertia(fn (Assert $page) => $page
                ->has('acoes', 8)
                ->where('modulos', function ($modulos) {
                    $porNome = collect($modulos)->keyBy('nome');

                    return count($porNome[ModuloEnum::PLANO_PROPINA->value]['acoes']) === 8
                        && count($porNome[ModuloEnum::CURSO->value]['acoes']) === 6;
                }));
    }

    public function test_perfil_rejeita_acao_inaplicavel_e_nao_altera_nada(): void
    {
        $role = Role::create(['nome' => Role::PERFIL_PERSONALIZADO, 'descricao' => 'X', 'estado' => 1]);
        RolePermissao::create(['role_id' => $role->id, 'modulo_id' => $this->moduloId(ModuloEnum::CURSO), 'acao_id' => $this->acaoId('ver')]);

        try {
            app(SincronizarPermissoesPerfilAction::class)->executar($role, [
                ['modulo_id' => $this->moduloId(ModuloEnum::CURSO), 'acao_id' => $this->acaoId('criar')],
                ['modulo_id' => $this->moduloId(ModuloEnum::CURSO), 'acao_id' => $this->acaoId('anular')],
            ]);
            $this->fail('Devia rejeitar.');
        } catch (ValidationException $e) {
            $this->assertSame(
                ['celulas' => ['A acção «anular» não é aplicável ao módulo «Curso».']],
                $e->errors(),
            );
        }

        $this->assertSame(1, RolePermissao::where('role_id', $role->id)->count());
        $this->assertDatabaseHas('role_permissoes', ['role_id' => $role->id, 'acao_id' => $this->acaoId('ver')]);
    }

    public function test_utilizador_rejeita_nova_concessao_inaplicavel(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'u2.aplic@example.com', 'password' => Hash::make('x')]);

        $this->expectException(ValidationException::class);
        try {
            app(SincronizarPermissoesUtilizadorAction::class)->executar($user, [
                ['modulo_id' => $this->moduloId(ModuloEnum::ALUNO), 'acao_id' => $this->acaoId('isentar-multa'), 'permitido' => true],
            ]);
        } finally {
            $this->assertSame(0, UserPermissao::where('users_id', $user->id)->count());
        }
    }

    public function test_endpoint_devolve_erro_pt_pt_para_acao_inaplicavel(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'u3.aplic@example.com', 'password' => Hash::make('x')]);

        $this->actingAs($this->admin)->from('/x')
            ->put("/permissoes/utilizadores/{$user->id}/permissoes", [
                'celulas' => [['modulo_id' => $this->moduloId(ModuloEnum::ALUNO), 'acao_id' => $this->acaoId('negociar'), 'permitido' => true]],
            ])
            ->assertSessionHasErrors(['celulas' => 'A acção «negociar» não é aplicável ao módulo «Aluno».']);

        $role = Role::create(['nome' => Role::PERFIL_PERSONALIZADO, 'descricao' => 'X', 'estado' => 1]);
        $this->actingAs($this->admin)->from('/x')
            ->put("/permissoes/perfis/{$role->id}/permissoes", [
                'celulas' => [['modulo_id' => $this->moduloId(ModuloEnum::ALUNO), 'acao_id' => $this->acaoId('negociar')]],
            ])
            ->assertSessionHasErrors('celulas');
    }

    public function test_modulo_futuro_aceita_as_suas_extras_e_invalida_a_cache(): void
    {
        $this->declararExtras(ModuloEnum::PLANO_PROPINA, ['anular']);
        $role = Role::create(['nome' => Role::PERFIL_PERSONALIZADO, 'descricao' => 'X', 'estado' => 1]);
        $cache = $this->spy(PermissaoCache::class);

        app(SincronizarPermissoesPerfilAction::class)->executar($role, [
            ['modulo_id' => $this->moduloId(ModuloEnum::PLANO_PROPINA), 'acao_id' => $this->acaoId('anular')],
        ]);

        $this->assertDatabaseHas('role_permissoes', ['role_id' => $role->id, 'acao_id' => $this->acaoId('anular')]);
        $cache->shouldHaveReceived('invalidarTudo');
    }

    /** Fixture: estreita o módulo às acções dadas. */
    private function estreitar(ModuloEnum $modulo, array $acoes): void
    {
        $this->app->instance(AcoesAplicaveis::class, new class($modulo, $acoes) extends AcoesAplicaveis {
            public function __construct(private ModuloEnum $alvo, private array $acoes)
            {
            }

            public function doModulo(ModuloEnum $modulo): array
            {
                return $modulo === $this->alvo ? $this->acoes : parent::doModulo($modulo);
            }
        });
    }

    public function test_perfil_com_par_antigo_guarda_e_o_par_e_removivel(): void
    {
        $role = Role::create(['nome' => Role::PERFIL_PERSONALIZADO, 'descricao' => 'X', 'estado' => 1]);
        $curso = $this->moduloId(ModuloEnum::CURSO);
        RolePermissao::create(['role_id' => $role->id, 'modulo_id' => $curso, 'acao_id' => $this->acaoId('criar')]);
        $this->estreitar(ModuloEnum::CURSO, ['ver']);

        // reenvio do par já gravado: aceite
        app(SincronizarPermissoesPerfilAction::class)->executar($role, [
            ['modulo_id' => $curso, 'acao_id' => $this->acaoId('criar')],
            ['modulo_id' => $curso, 'acao_id' => $this->acaoId('ver')],
        ]);
        $this->assertSame(2, RolePermissao::where('role_id', $role->id)->count());

        // sem o par: removido
        app(SincronizarPermissoesPerfilAction::class)->executar($role, [
            ['modulo_id' => $curso, 'acao_id' => $this->acaoId('ver')],
        ]);
        $this->assertDatabaseMissing('role_permissoes', ['role_id' => $role->id, 'acao_id' => $this->acaoId('criar')]);
    }

    public function test_utilizador_com_par_antigo_guarda_negacoes_passam_e_nova_concessao_inaplicavel_falha(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'u4.aplic@example.com', 'password' => Hash::make('x')]);
        $curso = $this->moduloId(ModuloEnum::CURSO);
        UserPermissao::create(['users_id' => $user->id, 'modulo_id' => $curso, 'acao_id' => $this->acaoId('criar'), 'permitido' => true]);
        $this->estreitar(ModuloEnum::CURSO, ['ver']);

        app(SincronizarPermissoesUtilizadorAction::class)->executar($user, [
            ['modulo_id' => $curso, 'acao_id' => $this->acaoId('criar'), 'permitido' => true],   // já gravado
            ['modulo_id' => $curso, 'acao_id' => $this->acaoId('editar'), 'permitido' => false], // negação
        ]);
        $this->assertSame(2, UserPermissao::where('users_id', $user->id)->count());

        // nova concessão inaplicável (não gravada): rejeitada
        $this->expectException(ValidationException::class);
        app(SincronizarPermissoesUtilizadorAction::class)->executar($user, [
            ['modulo_id' => $curso, 'acao_id' => $this->acaoId('eliminar'), 'permitido' => true],
        ]);
    }

    public function test_resolver_ignora_par_gravado_inaplicavel_mas_mantem_o_aplicavel(): void
    {
        $role = Role::create(['nome' => Role::PERFIL_PERSONALIZADO, 'descricao' => 'X', 'estado' => 1]);
        $curso = $this->moduloId(ModuloEnum::CURSO);
        RolePermissao::create(['role_id' => $role->id, 'modulo_id' => $curso, 'acao_id' => $this->acaoId('ver')]);
        RolePermissao::create(['role_id' => $role->id, 'modulo_id' => $curso, 'acao_id' => $this->acaoId('criar')]);
        $user = User::create(['name' => 'U', 'email' => 'u5.aplic@example.com', 'password' => Hash::make('x')]);
        $user->roles()->attach($role->id);

        $this->assertTrue(app(PermissionResolver::class)->can($user, 'curso.criar'));

        $this->estreitar(ModuloEnum::CURSO, ['ver']);
        $this->app->forgetInstance(PermissionResolver::class);
        app(PermissaoCache::class)->invalidarTudo();
        $resolver = app(PermissionResolver::class);
        $resolver->esquecerTudo();

        $this->assertTrue($resolver->can($user, 'curso.ver'));
        $this->assertFalse($resolver->can($user, 'curso.criar'));
    }
}
