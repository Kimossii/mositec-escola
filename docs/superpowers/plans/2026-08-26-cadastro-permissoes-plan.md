# Cadastro de Perfis e Permissões Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construir os ecrãs de gestão (cadastro) de Perfis e Permissões: criar/editar perfis, configurar as permissões padrão de cada perfil (`role_permissoes`), e configurar overrides individuais por utilizador (`user_permissoes`), incluindo atribuir/remover perfis a utilizadores.

**Architecture:** `PermissaoController` ganha 8 endpoints novos que substituem o `Route::resource` atual. Duas Actions (`SincronizarPermissoesPerfilAction`, `SincronizarPermissoesUtilizadorAction`) fazem apagar-tudo-e-reinserir dentro de uma transação — mais simples que um diff célula-a-célula e com o mesmo resultado final. Perfis criados pelo admin recebem `nome = -1` (sentinela fora do intervalo 0-4 do enum `Perfil`) para se distinguirem dos 5 perfis de sistema, sem precisar de tornar a coluna nullable (evita depender de `doctrine/dbal`, que não está instalado). Três páginas Vue novas no módulo Permissao (ainda sem nenhuma).

**Tech Stack:** Laravel 11 (Modules: Permissao, Usuario), Eloquent, PHPUnit (`php artisan test`), Vue 3 + Inertia.js.

**Spec:** `docs/superpowers/specs/2026-08-26-cadastro-permissoes-design.md`

## Global Constraints

- Módulos e Ações ficam fixos por seeder — sem CRUD pela UI (fora do âmbito).
- Sem mecanismo de verificação em runtime (`hasPermission()`) — só os ecrãs de gestão.
- Perfis de sistema (`nome !== -1`, os 5 seedados por `RoleSeeder`) nunca podem ser eliminados.
- Perfis criados pelo admin só podem ser eliminados se não tiverem utilizadores atribuídos.
- `role_permissoes`: presença de uma linha = permissão concedida (sem coluna `permitido`).
- `user_permissoes`: tri-estado via `permitido` (sem linha = herda; `true` = concede; `false` = nega).
- Nenhum commit — todas as alterações ficam só `git add` (staged), nunca `git commit`.

---

## Estrutura de ficheiros

**Backend — novos:**
- `Modules/Permissao/app/Actions/SincronizarPermissoesPerfilAction.php`
- `Modules/Permissao/app/Actions/SincronizarPermissoesUtilizadorAction.php`
- `Modules/Permissao/app/Services/PermissaoConsultaService.php`
- `Modules/Permissao/app/Http/Requests/CriarPerfilRequest.php`
- `Modules/Permissao/app/Http/Requests/SincronizarPermissoesPerfilRequest.php`
- `Modules/Permissao/app/Http/Requests/SincronizarPermissoesUtilizadorRequest.php`

**Backend — modificados:**
- `Modules/Permissao/app/Models/UserPermissao.php` (`$fillable` falta `permitido`)
- `Modules/Permissao/app/Http/Controllers/PermissaoController.php` (reescrito)
- `Modules/Permissao/routes/web.php` (reescrito)

**Frontend — novos:**
- `Modules/Permissao/resources/js/Pages/Perfis.vue`
- `Modules/Permissao/resources/js/Components/PerfilForm.vue`
- `Modules/Permissao/resources/js/Pages/PerfilPermissoes.vue`
- `Modules/Permissao/resources/js/Pages/UtilizadorPermissoes.vue`

**Frontend — modificados:**
- `resources/js/Components/Layout/SidebarMenuWrapper.vue` (grupo "Perfis" — trocar placeholders por link real)
- `resources/js/Components/Layout/menus/AppsMenu.vue` (idem)

**Testes — novos:**
- `Modules/Permissao/tests/Feature/SincronizarPermissoesPerfilActionTest.php`
- `Modules/Permissao/tests/Feature/SincronizarPermissoesUtilizadorActionTest.php`
- `Modules/Permissao/tests/Feature/PermissaoConsultaServiceTest.php`
- `Modules/Permissao/tests/Feature/GestaoPerfisTest.php`
- `Modules/Permissao/tests/Feature/PermissoesUtilizadorTest.php`

---

### Task 1: Sentinela de perfil personalizado + correção do UserPermissao

**Files:**
- Modify: `Modules/Permissao/app/Models/Role.php`
- Modify: `Modules/Permissao/app/Models/UserPermissao.php`
- Test: `Modules/Permissao/tests/Feature/GestaoPerfisTest.php` (só o teste `eSistema`, os restantes vêm na Task 4)

**Interfaces:**
- Produces: `Role::PERFIL_PERSONALIZADO` (constante = -1), `Role::eSistema(): bool` — usados pelas Tasks 4, 5.

- [ ] **Step 1: Escrever o teste**

```php
<?php

namespace Modules\Permissao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Permissao\Models\Role;
use Tests\TestCase;

class GestaoPerfisTest extends TestCase
{
    use RefreshDatabase;

    public function test_e_sistema_distingue_perfis_seedados_de_personalizados(): void
    {
        $sistema = Role::create(['nome' => 0, 'descricao' => 'Admin escola', 'estado' => 1]);
        $personalizado = Role::create(['nome' => Role::PERFIL_PERSONALIZADO, 'descricao' => 'Diretor', 'estado' => 1]);

        $this->assertTrue($sistema->eSistema());
        $this->assertFalse($personalizado->eSistema());
    }
}
```

- [ ] **Step 2: Correr o teste e confirmar que falha**

Run: `php artisan test Modules/Permissao/tests/Feature/GestaoPerfisTest.php`
Expected: FAIL — `Undefined constant Role::PERFIL_PERSONALIZADO`.

- [ ] **Step 3: Atualizar o model Role**

```php
<?php

namespace Modules\Permissao\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Usuario\Models\User;

class Role extends Model
{
    use HasFactory;
    protected $table = 'roles';

    public const PERFIL_PERSONALIZADO = -1;

    protected $fillable = [
        'nome',
        'descricao',
        'estado',
        'criado_por',
        'editado_por',
    ];

    public function criadoPor()
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function editadoPor()
    {
        return $this->belongsTo(User::class, 'editado_por');
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'user_roles', 'role_id', 'users_id');
    }

    public function permissoes()
    {
        return $this->hasMany(RolePermissao::class);
    }

    public function eSistema(): bool
    {
        return $this->nome !== self::PERFIL_PERSONALIZADO;
    }
}
```

- [ ] **Step 4: Corrigir o `$fillable` de UserPermissao**

Em `Modules/Permissao/app/Models/UserPermissao.php`, substituir:

```php
    protected $fillable = [
        'users_id',
        'modulo_id',
        'acao_id',
    ];
```

por:

```php
    protected $fillable = [
        'users_id',
        'modulo_id',
        'acao_id',
        'permitido',
    ];
```

- [ ] **Step 5: Correr o teste e confirmar que passa**

Run: `php artisan test Modules/Permissao/tests/Feature/GestaoPerfisTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

Não aplicável — sem commits neste projeto (ver Global Constraints). Fazer apenas `git add` dos ficheiros desta task.

---

### Task 2: SincronizarPermissoesPerfilAction

**Files:**
- Create: `Modules/Permissao/app/Actions/SincronizarPermissoesPerfilAction.php`
- Test: `Modules/Permissao/tests/Feature/SincronizarPermissoesPerfilActionTest.php`

**Interfaces:**
- Consumes: `Role`, `RolePermissao`, `Modulo`, `Acao` (já existentes).
- Produces: `SincronizarPermissoesPerfilAction::executar(Role $role, array $celulas): void` — `$celulas` é um array de `['modulo_id' => int, 'acao_id' => int]`. Usado pela Task 4.

- [ ] **Step 1: Escrever o teste**

```php
<?php

namespace Modules\Permissao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Permissao\Actions\SincronizarPermissoesPerfilAction;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Tests\TestCase;

class SincronizarPermissoesPerfilActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sincroniza_permissoes_do_perfil_substituindo_o_estado_anterior(): void
    {
        $role = Role::create(['nome' => Role::PERFIL_PERSONALIZADO, 'descricao' => 'Diretor', 'estado' => 1]);
        $modulo1 = Modulo::create(['nome' => 0, 'descricao' => 'Usuario', 'estado' => 1]);
        $modulo2 = Modulo::create(['nome' => 1, 'descricao' => 'Aluno', 'estado' => 1]);
        $acaoVer = Acao::create(['nome' => 'ver', 'numero' => 0, 'estado' => 1]);
        $acaoCriar = Acao::create(['nome' => 'criar', 'numero' => 1, 'estado' => 1]);

        RolePermissao::create(['role_id' => $role->id, 'modulo_id' => $modulo1->id, 'acao_id' => $acaoVer->id]);

        (new SincronizarPermissoesPerfilAction())->executar($role, [
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

        (new SincronizarPermissoesPerfilAction())->executar($role, []);

        $this->assertSame(0, RolePermissao::where('role_id', $role->id)->count());
    }
}
```

- [ ] **Step 2: Correr o teste e confirmar que falha**

Run: `php artisan test Modules/Permissao/tests/Feature/SincronizarPermissoesPerfilActionTest.php`
Expected: FAIL — `Class "Modules\Permissao\Actions\SincronizarPermissoesPerfilAction" not found`.

- [ ] **Step 3: Implementar a Action**

```php
<?php

namespace Modules\Permissao\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;

class SincronizarPermissoesPerfilAction
{
    public function executar(Role $role, array $celulas): void
    {
        DB::transaction(function () use ($role, $celulas) {
            RolePermissao::where('role_id', $role->id)->delete();

            $linhas = collect($celulas)->map(fn (array $celula) => [
                'role_id' => $role->id,
                'modulo_id' => $celula['modulo_id'],
                'acao_id' => $celula['acao_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ])->all();

            if (! empty($linhas)) {
                RolePermissao::insert($linhas);
            }
        });
    }
}
```

- [ ] **Step 4: Correr o teste e confirmar que passa**

Run: `php artisan test Modules/Permissao/tests/Feature/SincronizarPermissoesPerfilActionTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

Sem commits (ver Global Constraints) — `git add` apenas.

---

### Task 3: SincronizarPermissoesUtilizadorAction + PermissaoConsultaService

**Files:**
- Create: `Modules/Permissao/app/Actions/SincronizarPermissoesUtilizadorAction.php`
- Create: `Modules/Permissao/app/Services/PermissaoConsultaService.php`
- Test: `Modules/Permissao/tests/Feature/SincronizarPermissoesUtilizadorActionTest.php`
- Test: `Modules/Permissao/tests/Feature/PermissaoConsultaServiceTest.php`

**Interfaces:**
- Consumes: `User::roles()`, `User::permissoes()` (já existentes), `RolePermissao`, `UserPermissao`.
- Produces: `SincronizarPermissoesUtilizadorAction::executar(User $user, array $celulas): void` — `$celulas` é array de `['modulo_id' => int, 'acao_id' => int, 'permitido' => bool]`. `PermissaoConsultaService::permissoesHerdadas(User $user): array` — array de `['modulo_id' => int, 'acao_id' => int]` únicos. Ambos usados pela Task 4.

- [ ] **Step 1: Escrever os testes**

```php
<?php

namespace Modules\Permissao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Actions\SincronizarPermissoesUtilizadorAction;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo;
use Modules\Permissao\Models\UserPermissao;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class SincronizarPermissoesUtilizadorActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sincroniza_overrides_do_utilizador_substituindo_o_estado_anterior(): void
    {
        $user = User::create(['name' => 'Prof', 'email' => 'prof@example.com', 'password' => Hash::make('x')]);
        $modulo1 = Modulo::create(['nome' => 0, 'descricao' => 'Usuario', 'estado' => 1]);
        $modulo2 = Modulo::create(['nome' => 1, 'descricao' => 'Aluno', 'estado' => 1]);
        $acao = Acao::create(['nome' => 'eliminar', 'numero' => 3, 'estado' => 1]);

        UserPermissao::create(['users_id' => $user->id, 'modulo_id' => $modulo1->id, 'acao_id' => $acao->id, 'permitido' => true]);

        (new SincronizarPermissoesUtilizadorAction())->executar($user, [
            ['modulo_id' => $modulo2->id, 'acao_id' => $acao->id, 'permitido' => false],
        ]);

        $this->assertDatabaseMissing('user_permissoes', ['users_id' => $user->id, 'modulo_id' => $modulo1->id]);
        $this->assertDatabaseHas('user_permissoes', ['users_id' => $user->id, 'modulo_id' => $modulo2->id, 'acao_id' => $acao->id, 'permitido' => false]);
    }

    public function test_lista_vazia_remove_todos_os_overrides_voltando_a_herdar(): void
    {
        $user = User::create(['name' => 'Prof', 'email' => 'prof2@example.com', 'password' => Hash::make('x')]);
        $modulo = Modulo::create(['nome' => 0, 'descricao' => 'Usuario', 'estado' => 1]);
        $acao = Acao::create(['nome' => 'ver', 'numero' => 0, 'estado' => 1]);

        UserPermissao::create(['users_id' => $user->id, 'modulo_id' => $modulo->id, 'acao_id' => $acao->id, 'permitido' => true]);

        (new SincronizarPermissoesUtilizadorAction())->executar($user, []);

        $this->assertSame(0, UserPermissao::where('users_id', $user->id)->count());
    }
}
```

```php
<?php

namespace Modules\Permissao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Modules\Permissao\Services\PermissaoConsultaService;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class PermissaoConsultaServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_permissoes_herdadas_e_a_uniao_de_todos_os_perfis_do_utilizador(): void
    {
        $user = User::create(['name' => 'Prof', 'email' => 'prof3@example.com', 'password' => Hash::make('x')]);
        $roleProfessor = Role::create(['nome' => 2, 'descricao' => 'Professor', 'estado' => 1]);
        $roleSecretario = Role::create(['nome' => 1, 'descricao' => 'Secretário', 'estado' => 1]);
        $modulo = Modulo::create(['nome' => 0, 'descricao' => 'Usuario', 'estado' => 1]);
        $acaoVer = Acao::create(['nome' => 'ver', 'numero' => 0, 'estado' => 1]);
        $acaoCriar = Acao::create(['nome' => 'criar', 'numero' => 1, 'estado' => 1]);

        RolePermissao::create(['role_id' => $roleProfessor->id, 'modulo_id' => $modulo->id, 'acao_id' => $acaoVer->id]);
        RolePermissao::create(['role_id' => $roleSecretario->id, 'modulo_id' => $modulo->id, 'acao_id' => $acaoCriar->id]);

        $user->roles()->attach([$roleProfessor->id, $roleSecretario->id]);

        $herdadas = (new PermissaoConsultaService())->permissoesHerdadas($user);

        $this->assertCount(2, $herdadas);
    }
}
```

- [ ] **Step 2: Correr os testes e confirmar que falham**

Run: `php artisan test Modules/Permissao/tests/Feature/SincronizarPermissoesUtilizadorActionTest.php Modules/Permissao/tests/Feature/PermissaoConsultaServiceTest.php`
Expected: FAIL — classes não existem.

- [ ] **Step 3: Implementar a Action**

```php
<?php

namespace Modules\Permissao\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Permissao\Models\UserPermissao;
use Modules\Usuario\Models\User;

class SincronizarPermissoesUtilizadorAction
{
    public function executar(User $user, array $celulas): void
    {
        DB::transaction(function () use ($user, $celulas) {
            UserPermissao::where('users_id', $user->id)->delete();

            $linhas = collect($celulas)->map(fn (array $celula) => [
                'users_id' => $user->id,
                'modulo_id' => $celula['modulo_id'],
                'acao_id' => $celula['acao_id'],
                'permitido' => $celula['permitido'],
                'created_at' => now(),
                'updated_at' => now(),
            ])->all();

            if (! empty($linhas)) {
                UserPermissao::insert($linhas);
            }
        });
    }
}
```

- [ ] **Step 4: Implementar o serviço de consulta**

```php
<?php

namespace Modules\Permissao\Services;

use Modules\Permissao\Models\RolePermissao;
use Modules\Usuario\Models\User;

class PermissaoConsultaService
{
    public function permissoesHerdadas(User $user): array
    {
        $roleIds = $user->roles()->pluck('roles.id');

        return RolePermissao::whereIn('role_id', $roleIds)
            ->get(['modulo_id', 'acao_id'])
            ->unique(fn ($permissao) => "{$permissao->modulo_id}-{$permissao->acao_id}")
            ->values()
            ->map(fn ($permissao) => [
                'modulo_id' => $permissao->modulo_id,
                'acao_id' => $permissao->acao_id,
            ])
            ->all();
    }
}
```

- [ ] **Step 5: Correr os testes e confirmar que passam**

Run: `php artisan test Modules/Permissao/tests/Feature/SincronizarPermissoesUtilizadorActionTest.php Modules/Permissao/tests/Feature/PermissaoConsultaServiceTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

Sem commits (ver Global Constraints) — `git add` apenas.

---

### Task 4: PermissaoController, Requests e rotas

**Files:**
- Create: `Modules/Permissao/app/Http/Requests/CriarPerfilRequest.php`
- Create: `Modules/Permissao/app/Http/Requests/SincronizarPermissoesPerfilRequest.php`
- Create: `Modules/Permissao/app/Http/Requests/SincronizarPermissoesUtilizadorRequest.php`
- Modify: `Modules/Permissao/app/Http/Controllers/PermissaoController.php`
- Modify: `Modules/Permissao/routes/web.php`
- Test: `Modules/Permissao/tests/Feature/GestaoPerfisTest.php` (adicionar os testes de CRUD)
- Test: `Modules/Permissao/tests/Feature/PermissoesUtilizadorTest.php`

**Interfaces:**
- Consumes: `Role::PERFIL_PERSONALIZADO`, `Role::eSistema()` (Task 1); `SincronizarPermissoesPerfilAction` (Task 2); `SincronizarPermissoesUtilizadorAction`, `PermissaoConsultaService` (Task 3).
- Produces: as 8 rotas nomeadas `permissao.perfis.*` e `permissao.utilizadores.*` — usadas pelas Tasks 5, 6, 7.

- [ ] **Step 1: Escrever os testes de CRUD de perfis**

Adicionar a `Modules/Permissao/tests/Feature/GestaoPerfisTest.php` (a seguir ao teste já existente da Task 1):

```php
    public function test_cria_um_perfil_personalizado(): void
    {
        $response = $this->post('/permissoes/perfis', ['descricao' => 'Diretor', 'estado' => 1]);

        $response->assertRedirect();
        $this->assertDatabaseHas('roles', ['descricao' => 'Diretor', 'nome' => Role::PERFIL_PERSONALIZADO]);
    }

    public function test_nao_elimina_perfil_de_sistema(): void
    {
        $role = Role::create(['nome' => 0, 'descricao' => 'Admin escola', 'estado' => 1]);

        $response = $this->delete("/permissoes/perfis/{$role->id}");

        $response->assertSessionHasErrors('perfil');
        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_nao_elimina_perfil_personalizado_com_utilizadores(): void
    {
        $role = Role::create(['nome' => Role::PERFIL_PERSONALIZADO, 'descricao' => 'Diretor', 'estado' => 1]);
        $user = \Modules\Usuario\Models\User::create(['name' => 'X', 'email' => 'x@example.com', 'password' => \Illuminate\Support\Facades\Hash::make('x')]);
        $user->roles()->attach($role->id);

        $response = $this->delete("/permissoes/perfis/{$role->id}");

        $response->assertSessionHasErrors('perfil');
        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_elimina_perfil_personalizado_sem_utilizadores(): void
    {
        $role = Role::create(['nome' => Role::PERFIL_PERSONALIZADO, 'descricao' => 'Diretor', 'estado' => 1]);

        $response = $this->delete("/permissoes/perfis/{$role->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_sincroniza_permissoes_do_perfil_via_endpoint(): void
    {
        $role = Role::create(['nome' => Role::PERFIL_PERSONALIZADO, 'descricao' => 'Diretor', 'estado' => 1]);
        $modulo = \Modules\Permissao\Models\Modulo::create(['nome' => 0, 'descricao' => 'Usuario', 'estado' => 1]);
        $acao = \Modules\Permissao\Models\Acao::create(['nome' => 'ver', 'numero' => 0, 'estado' => 1]);

        $response = $this->put("/permissoes/perfis/{$role->id}/permissoes", [
            'celulas' => [['modulo_id' => $modulo->id, 'acao_id' => $acao->id]],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('role_permissoes', ['role_id' => $role->id, 'modulo_id' => $modulo->id, 'acao_id' => $acao->id]);
    }
```

Adicionar os imports necessários no topo do ficheiro (`use Modules\Permissao\Models\Role;` já deve existir da Task 1; confirmar que está lá).

Criar `Modules/Permissao/tests/Feature/PermissoesUtilizadorTest.php`:

```php
<?php

namespace Modules\Permissao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class PermissoesUtilizadorTest extends TestCase
{
    use RefreshDatabase;

    public function test_atribui_e_remove_perfil_de_um_utilizador(): void
    {
        $user = User::create(['name' => 'Prof', 'email' => 'prof4@example.com', 'password' => Hash::make('x')]);
        $role = Role::create(['nome' => Role::PERFIL_PERSONALIZADO, 'descricao' => 'Diretor', 'estado' => 1]);

        $this->post("/permissoes/utilizadores/{$user->id}/perfis", ['role_id' => $role->id])
            ->assertRedirect();
        $this->assertTrue($user->fresh()->roles->contains($role));

        $this->delete("/permissoes/utilizadores/{$user->id}/perfis/{$role->id}")
            ->assertRedirect();
        $this->assertFalse($user->fresh()->roles->contains($role));
    }

    public function test_sincroniza_overrides_do_utilizador_via_endpoint(): void
    {
        $user = User::create(['name' => 'Prof', 'email' => 'prof5@example.com', 'password' => Hash::make('x')]);
        $modulo = Modulo::create(['nome' => 0, 'descricao' => 'Usuario', 'estado' => 1]);
        $acao = Acao::create(['nome' => 'eliminar', 'numero' => 3, 'estado' => 1]);

        $response = $this->put("/permissoes/utilizadores/{$user->id}/permissoes", [
            'celulas' => [['modulo_id' => $modulo->id, 'acao_id' => $acao->id, 'permitido' => false]],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('user_permissoes', ['users_id' => $user->id, 'modulo_id' => $modulo->id, 'acao_id' => $acao->id, 'permitido' => false]);
    }
}
```

- [ ] **Step 2: Correr os testes e confirmar que falham**

Run: `php artisan test Modules/Permissao`
Expected: FAIL — rotas `/permissoes/perfis` etc. ainda não existem (404).

- [ ] **Step 3: Criar os Form Requests**

```php
<?php

namespace Modules\Permissao\Http\Requests;

use App\Http\Requests\BaseRequest;

class CriarPerfilRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'descricao' => 'required|string|max:255',
            'estado' => 'nullable|in:0,1',
        ];
    }

    public function messages(): array
    {
        return [
            'descricao.required' => 'O nome do perfil é obrigatório.',
            'descricao.max' => 'O nome do perfil não pode ter mais de 255 caracteres.',
        ];
    }
}
```

```php
<?php

namespace Modules\Permissao\Http\Requests;

use App\Http\Requests\BaseRequest;

class SincronizarPermissoesPerfilRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'celulas' => 'present|array',
            'celulas.*.modulo_id' => 'required|integer|exists:modulos,id',
            'celulas.*.acao_id' => 'required|integer|exists:acoes,id',
        ];
    }
}
```

```php
<?php

namespace Modules\Permissao\Http\Requests;

use App\Http\Requests\BaseRequest;

class SincronizarPermissoesUtilizadorRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'celulas' => 'present|array',
            'celulas.*.modulo_id' => 'required|integer|exists:modulos,id',
            'celulas.*.acao_id' => 'required|integer|exists:acoes,id',
            'celulas.*.permitido' => 'required|boolean',
        ];
    }
}
```

- [ ] **Step 4: Reescrever PermissaoController**

```php
<?php

namespace Modules\Permissao\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Permissao\Actions\SincronizarPermissoesPerfilAction;
use Modules\Permissao\Actions\SincronizarPermissoesUtilizadorAction;
use Modules\Permissao\Http\Requests\CriarPerfilRequest;
use Modules\Permissao\Http\Requests\SincronizarPermissoesPerfilRequest;
use Modules\Permissao\Http\Requests\SincronizarPermissoesUtilizadorRequest;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Services\PermissaoConsultaService;
use Modules\Usuario\Models\User;

class PermissaoController extends Controller
{
    public function __construct(
        private SincronizarPermissoesPerfilAction $sincronizarPerfil,
        private SincronizarPermissoesUtilizadorAction $sincronizarUtilizador,
        private PermissaoConsultaService $consulta,
    ) {}

    public function index()
    {
        return Inertia::render('Permissao/Perfis', [
            'perfis' => Role::withCount('users')->get()->map(fn (Role $role) => [
                'id' => $role->id,
                'descricao' => $role->descricao,
                'estado' => $role->estado,
                'sistema' => $role->eSistema(),
                'utilizadores_count' => $role->users_count,
            ]),
        ]);
    }

    public function store(CriarPerfilRequest $request)
    {
        Role::create([
            'nome' => Role::PERFIL_PERSONALIZADO,
            'descricao' => $request->descricao,
            'estado' => $request->estado ?? 1,
        ]);

        return redirect()->back()->with('success', 'Perfil criado com sucesso.');
    }

    public function update(CriarPerfilRequest $request, Role $role)
    {
        $role->update([
            'descricao' => $request->descricao,
            'estado' => $request->estado ?? $role->estado,
        ]);

        return redirect()->back()->with('success', 'Perfil atualizado com sucesso.');
    }

    public function destroy(Role $role)
    {
        if ($role->eSistema()) {
            return redirect()->back()->withErrors(['perfil' => 'Perfis de sistema não podem ser eliminados.']);
        }

        if ($role->users()->exists()) {
            return redirect()->back()->withErrors(['perfil' => 'Este perfil tem utilizadores atribuídos e não pode ser eliminado.']);
        }

        $role->delete();

        return redirect()->back()->with('success', 'Perfil eliminado com sucesso.');
    }

    public function permissoesDoPerfil(Role $role)
    {
        return Inertia::render('Permissao/PerfilPermissoes', [
            'perfil' => ['id' => $role->id, 'descricao' => $role->descricao],
            'modulos' => Modulo::orderBy('nome')->get(['id', 'nome', 'descricao']),
            'acoes' => Acao::orderBy('numero')->get(['id', 'nome']),
            'marcadas' => $role->permissoes()->get(['modulo_id', 'acao_id']),
        ]);
    }

    public function sincronizarPermissoesDoPerfil(SincronizarPermissoesPerfilRequest $request, Role $role)
    {
        $this->sincronizarPerfil->executar($role, $request->celulas);

        return redirect()->back()->with('success', 'Permissões do perfil atualizadas.');
    }

    public function permissoesDoUtilizador(User $user)
    {
        return Inertia::render('Permissao/UtilizadorPermissoes', [
            'utilizador' => ['id' => $user->id, 'name' => $user->name],
            'perfis' => Role::get(['id', 'descricao']),
            'perfisAtribuidos' => $user->roles()->pluck('roles.id'),
            'modulos' => Modulo::orderBy('nome')->get(['id', 'nome', 'descricao']),
            'acoes' => Acao::orderBy('numero')->get(['id', 'nome']),
            'herdadas' => $this->consulta->permissoesHerdadas($user),
            'overrides' => $user->permissoes()->get(['modulo_id', 'acao_id', 'permitido']),
        ]);
    }

    public function sincronizarPermissoesDoUtilizador(SincronizarPermissoesUtilizadorRequest $request, User $user)
    {
        $this->sincronizarUtilizador->executar($user, $request->celulas);

        return redirect()->back()->with('success', 'Permissões do utilizador atualizadas.');
    }

    public function atribuirPerfil(Request $request, User $user)
    {
        $request->validate(['role_id' => 'required|exists:roles,id']);

        $user->roles()->syncWithoutDetaching([$request->role_id]);

        return redirect()->back()->with('success', 'Perfil atribuído.');
    }

    public function removerPerfil(User $user, Role $role)
    {
        $user->roles()->detach($role->id);

        return redirect()->back()->with('success', 'Perfil removido.');
    }
}
```

- [ ] **Step 5: Reescrever as rotas**

```php
<?php

use Illuminate\Support\Facades\Route;
use Modules\Permissao\Http\Controllers\PermissaoController;

Route::middleware('auth')->prefix('permissoes')->name('permissao.')->group(function () {
    Route::get('/perfis', [PermissaoController::class, 'index'])->name('perfis.index');
    Route::post('/perfis', [PermissaoController::class, 'store'])->name('perfis.store');
    Route::put('/perfis/{role}', [PermissaoController::class, 'update'])->name('perfis.update');
    Route::delete('/perfis/{role}', [PermissaoController::class, 'destroy'])->name('perfis.destroy');

    Route::get('/perfis/{role}/permissoes', [PermissaoController::class, 'permissoesDoPerfil'])->name('perfis.permissoes');
    Route::put('/perfis/{role}/permissoes', [PermissaoController::class, 'sincronizarPermissoesDoPerfil'])->name('perfis.permissoes.sincronizar');

    Route::get('/utilizadores/{user}/permissoes', [PermissaoController::class, 'permissoesDoUtilizador'])->name('utilizadores.permissoes');
    Route::put('/utilizadores/{user}/permissoes', [PermissaoController::class, 'sincronizarPermissoesDoUtilizador'])->name('utilizadores.permissoes.sincronizar');
    Route::post('/utilizadores/{user}/perfis', [PermissaoController::class, 'atribuirPerfil'])->name('utilizadores.perfis.atribuir');
    Route::delete('/utilizadores/{user}/perfis/{role}', [PermissaoController::class, 'removerPerfil'])->name('utilizadores.perfis.remover');
});
```

- [ ] **Step 6: Correr os testes e confirmar que passam**

Run: `php artisan test Modules/Permissao`
Expected: PASS em todos

- [ ] **Step 7: Correr a suite completa para garantir que nada quebrou**

Run: `php artisan test Modules`
Expected: PASS em todos

- [ ] **Step 8: Commit**

Sem commits (ver Global Constraints) — `git add` apenas.

---

### Task 5: Frontend — Lista de Perfis + criação

**Files:**
- Create: `Modules/Permissao/resources/js/Pages/Perfis.vue`
- Create: `Modules/Permissao/resources/js/Components/PerfilForm.vue`
- Modify: `resources/js/Components/Layout/SidebarMenuWrapper.vue`
- Modify: `resources/js/Components/Layout/menus/AppsMenu.vue`

**Interfaces:**
- Consumes: prop `perfis` (Task 4's `index()`), rotas `permissao.perfis.store/update/destroy`.

- [ ] **Step 1: Criar PerfilForm.vue**

```html
<script setup>
import { reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';

const props = defineProps({
    perfil: {
        type: Object,
        default: null,
    },
});
const emit = defineEmits(['fechar']);

const form = reactive({ descricao: '', estado: 1 });
const processing = ref(false);
const errors = ref({});

watch(() => props.perfil, (perfil) => {
    form.descricao = perfil?.descricao ?? '';
    form.estado = perfil?.estado ?? 1;
}, { immediate: true });

function submeter() {
    processing.value = true;
    errors.value = {};

    const url = props.perfil ? `/permissoes/perfis/${props.perfil.id}` : '/permissoes/perfis';
    const metodo = props.perfil ? 'put' : 'post';

    router[metodo](url, form, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(props.perfil ? 'Perfil atualizado com sucesso.' : 'Perfil criado com sucesso.');
            emit('fechar');
        },
        onError: (erros) => {
            errors.value = erros;
            toast.error(Object.values(erros)[0] ?? 'Não foi possível guardar o perfil.');
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}
</script>

<template>
    <form @submit.prevent="submeter">
        <div class="fv-row mb-7">
            <label class="required fw-semibold fs-6 mb-2">Nome do perfil</label>
            <input v-model="form.descricao" type="text" class="form-control form-control-solid" placeholder="ex: Diretor" />
            <div class="text-danger fs-7 mt-1" v-if="errors.descricao">{{ errors.descricao[0] }}</div>
        </div>

        <div class="text-end">
            <button type="button" class="btn btn-light me-2" @click="emit('fechar')">Cancelar</button>
            <button type="submit" class="btn btn-primary" :disabled="processing">Guardar</button>
        </div>
    </form>
</template>
```

- [ ] **Step 2: Criar Pages/Perfis.vue**

```html
<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import PerfilForm from '../Components/PerfilForm.vue';

defineProps({
    perfis: {
        type: Array,
        required: true,
    },
});
defineOptions({ layout: AppLayout });

const modalAberto = ref(false);
const perfilEmEdicao = ref(null);

function abrirCriacao() {
    perfilEmEdicao.value = null;
    modalAberto.value = true;
}

function abrirEdicao(perfil) {
    perfilEmEdicao.value = perfil;
    modalAberto.value = true;
}

function fecharModal() {
    modalAberto.value = false;
}

function eliminar(perfil) {
    router.delete(`/permissoes/perfis/${perfil.id}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Perfil eliminado com sucesso.'),
        onError: (erros) => toast.error(Object.values(erros)[0] ?? 'Não foi possível eliminar o perfil.'),
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="d-flex justify-content-between align-items-center mb-6">
            <h1 class="fs-2 fw-bold">Perfis</h1>
            <button class="btn btn-primary" @click="abrirCriacao">Novo perfil</button>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed fs-6 gy-5 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-200px">Nome</th>
                            <th class="min-w-100px">Tipo</th>
                            <th class="min-w-100px">Utilizadores</th>
                            <th class="text-end min-w-150px">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-for="perfil in perfis" :key="perfil.id">
                            <td>{{ perfil.descricao }}</td>
                            <td>
                                <span class="badge" :class="perfil.sistema ? 'badge-light-primary' : 'badge-light-info'">
                                    {{ perfil.sistema ? 'Sistema' : 'Personalizado' }}
                                </span>
                            </td>
                            <td>{{ perfil.utilizadores_count }}</td>
                            <td class="text-end">
                                <a :href="`/permissoes/perfis/${perfil.id}/permissoes`" class="btn btn-light btn-sm me-2">Permissões</a>
                                <button class="btn btn-light btn-sm me-2" @click="abrirEdicao(perfil)">Editar</button>
                                <button
                                    v-if="!perfil.sistema"
                                    class="btn btn-light-danger btn-sm"
                                    @click="eliminar(perfil)"
                                >
                                    Eliminar
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="modalAberto" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="fecharModal">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content p-6">
                    <h3 class="mb-5">{{ perfilEmEdicao ? 'Editar perfil' : 'Novo perfil' }}</h3>
                    <PerfilForm :perfil="perfilEmEdicao" @fechar="fecharModal" />
                </div>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 3: Ligar o menu lateral**

Em `resources/js/Components/Layout/SidebarMenuWrapper.vue`, substituir o grupo `Perfis` (linhas com `apps/user-management/roles/...`):

```js
    {
        title: 'Perfis',
        items: [
            { href: '/permissoes/perfis', title: 'Perfis e Permissões' },
        ],
    },
```

Em `resources/js/Components/Layout/menus/AppsMenu.vue`, o mesmo (usa `links`/`label` em vez de `items`/`title`):

```js
    {
        title: 'Perfis',
        links: [
            { href: '/permissoes/perfis', label: 'Perfis e Permissões' },
        ],
    },
```

- [ ] **Step 4: Compilar o frontend**

Run: `npm run build`
Expected: build sem erros, novo chunk `Perfis-*.js`.

- [ ] **Step 5: Commit**

Sem commits (ver Global Constraints) — `git add` apenas.

---

### Task 6: Frontend — Matriz de permissões do perfil

**Files:**
- Create: `Modules/Permissao/resources/js/Pages/PerfilPermissoes.vue`

**Interfaces:**
- Consumes: props `perfil`, `modulos`, `acoes`, `marcadas` (Task 4's `permissoesDoPerfil()`), rota `permissao.perfis.permissoes.sincronizar`.

- [ ] **Step 1: Criar a página**

```html
<script setup>
import { computed, reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    perfil: { type: Object, required: true },
    modulos: { type: Array, required: true },
    acoes: { type: Array, required: true },
    marcadas: { type: Array, required: true },
});
defineOptions({ layout: AppLayout });

const chave = (moduloId, acaoId) => `${moduloId}-${acaoId}`;

const estado = reactive(
    Object.fromEntries(
        props.marcadas.map((m) => [chave(m.modulo_id, m.acao_id), true]),
    ),
);

function alternar(moduloId, acaoId) {
    const k = chave(moduloId, acaoId);
    estado[k] = !estado[k];
}

const processing = computed(() => false);

function guardar() {
    const celulas = Object.entries(estado)
        .filter(([, marcado]) => marcado)
        .map(([k]) => {
            const [modulo_id, acao_id] = k.split('-').map(Number);
            return { modulo_id, acao_id };
        });

    router.put(`/permissoes/perfis/${props.perfil.id}/permissoes`, { celulas }, {
        preserveScroll: true,
        onSuccess: () => toast.success('Permissões do perfil atualizadas.'),
        onError: () => toast.error('Não foi possível guardar as permissões.'),
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <h1 class="fs-2 fw-bold mb-6">Permissões — {{ perfil.descricao }}</h1>

        <div class="card">
            <div class="card-body">
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Módulo</th>
                            <th v-for="acao in acoes" :key="acao.id" class="text-center text-capitalize">
                                {{ acao.nome }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="modulo in modulos" :key="modulo.id">
                            <td>{{ modulo.descricao }}</td>
                            <td v-for="acao in acoes" :key="acao.id" class="text-center">
                                <input
                                    type="checkbox"
                                    class="form-check-input"
                                    :checked="!!estado[`${modulo.id}-${acao.id}`]"
                                    @change="alternar(modulo.id, acao.id)"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="text-end mt-5">
                    <button class="btn btn-primary" :disabled="processing" @click="guardar">Guardar</button>
                </div>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 2: Compilar o frontend**

Run: `npm run build`
Expected: build sem erros, novo chunk `PerfilPermissoes-*.js`.

- [ ] **Step 3: Commit**

Sem commits (ver Global Constraints) — `git add` apenas.

---

### Task 7: Frontend — Permissões individuais do utilizador

**Files:**
- Create: `Modules/Permissao/resources/js/Pages/UtilizadorPermissoes.vue`

**Interfaces:**
- Consumes: props `utilizador`, `perfis`, `perfisAtribuidos`, `modulos`, `acoes`, `herdadas`, `overrides` (Task 4's `permissoesDoUtilizador()`), rotas `permissao.utilizadores.permissoes.sincronizar`, `permissao.utilizadores.perfis.atribuir`, `permissao.utilizadores.perfis.remover`.

- [ ] **Step 1: Criar a página**

```html
<script setup>
import { reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    utilizador: { type: Object, required: true },
    perfis: { type: Array, required: true },
    perfisAtribuidos: { type: Array, required: true },
    modulos: { type: Array, required: true },
    acoes: { type: Array, required: true },
    herdadas: { type: Array, required: true },
    overrides: { type: Array, required: true },
});
defineOptions({ layout: AppLayout });

const chave = (moduloId, acaoId) => `${moduloId}-${acaoId}`;

const herdadasSet = new Set(props.herdadas.map((h) => chave(h.modulo_id, h.acao_id)));

// -1 = herda, 1 = concede, 0 = nega
const overridesEstado = reactive(
    Object.fromEntries(
        props.overrides.map((o) => [chave(o.modulo_id, o.acao_id), o.permitido ? 1 : 0]),
    ),
);

function estadoCelula(moduloId, acaoId) {
    return overridesEstado[chave(moduloId, acaoId)] ?? -1;
}

function proximoEstado(moduloId, acaoId) {
    const k = chave(moduloId, acaoId);
    const atual = overridesEstado[k] ?? -1;
    const seguinte = atual === -1 ? 1 : atual === 1 ? 0 : -1;

    if (seguinte === -1) {
        delete overridesEstado[k];
    } else {
        overridesEstado[k] = seguinte;
    }
}

const novoPerfilId = ref('');

function atribuirPerfil() {
    if (!novoPerfilId.value) return;

    router.post(`/permissoes/utilizadores/${props.utilizador.id}/perfis`, { role_id: novoPerfilId.value }, {
        preserveScroll: true,
        onSuccess: () => toast.success('Perfil atribuído.'),
        onError: () => toast.error('Não foi possível atribuir o perfil.'),
    });
}

function removerPerfil(roleId) {
    router.delete(`/permissoes/utilizadores/${props.utilizador.id}/perfis/${roleId}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Perfil removido.'),
        onError: () => toast.error('Não foi possível remover o perfil.'),
    });
}

function guardarOverrides() {
    const celulas = Object.entries(overridesEstado).map(([k, valor]) => {
        const [modulo_id, acao_id] = k.split('-').map(Number);
        return { modulo_id, acao_id, permitido: valor === 1 };
    });

    router.put(`/permissoes/utilizadores/${props.utilizador.id}/permissoes`, { celulas }, {
        preserveScroll: true,
        onSuccess: () => toast.success('Permissões do utilizador atualizadas.'),
        onError: () => toast.error('Não foi possível guardar as permissões.'),
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <h1 class="fs-2 fw-bold mb-6">Permissões — {{ utilizador.name }}</h1>

        <div class="card mb-6">
            <div class="card-body">
                <h3 class="fs-5 mb-4">Perfis atribuídos</h3>
                <div class="d-flex flex-wrap gap-2 mb-4">
                    <span v-for="roleId in perfisAtribuidos" :key="roleId" class="badge badge-light-primary fs-7">
                        {{ perfis.find((p) => p.id === roleId)?.descricao }}
                        <a href="#" class="ms-2 text-danger" @click.prevent="removerPerfil(roleId)">&times;</a>
                    </span>
                </div>
                <div class="d-flex gap-2">
                    <select v-model="novoPerfilId" class="form-select form-select-solid w-auto">
                        <option value="">Selecione um perfil...</option>
                        <option v-for="perfil in perfis" :key="perfil.id" :value="perfil.id">{{ perfil.descricao }}</option>
                    </select>
                    <button class="btn btn-light-primary" @click="atribuirPerfil">Atribuir</button>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h3 class="fs-5 mb-4">Permissões individuais</h3>
                <p class="text-muted fs-7">
                    Clique numa célula para alternar entre Herda (cinza), Concede (verde) e Nega (vermelho).
                    "Herda" usa o que os perfis atribuídos já dão por padrão.
                </p>
                <table class="table align-middle">
                    <thead>
                        <tr>
                            <th>Módulo</th>
                            <th v-for="acao in acoes" :key="acao.id" class="text-center text-capitalize">
                                {{ acao.nome }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="modulo in modulos" :key="modulo.id">
                            <td>{{ modulo.descricao }}</td>
                            <td v-for="acao in acoes" :key="acao.id" class="text-center">
                                <button
                                    type="button"
                                    class="btn btn-sm"
                                    :class="{
                                        'btn-light': estadoCelula(modulo.id, acao.id) === -1,
                                        'btn-light-success': estadoCelula(modulo.id, acao.id) === 1,
                                        'btn-light-danger': estadoCelula(modulo.id, acao.id) === 0,
                                    }"
                                    @click="proximoEstado(modulo.id, acao.id)"
                                >
                                    {{ estadoCelula(modulo.id, acao.id) === -1 ? 'Herda' : estadoCelula(modulo.id, acao.id) === 1 ? 'Concede' : 'Nega' }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="text-end mt-5">
                    <button class="btn btn-primary" @click="guardarOverrides">Guardar</button>
                </div>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 2: Compilar o frontend**

Run: `npm run build`
Expected: build sem erros, novo chunk `UtilizadorPermissoes-*.js`.

- [ ] **Step 3: Correr a suite completa de backend outra vez, por garantia**

Run: `php artisan test Modules`
Expected: PASS em todos

- [ ] **Step 4: Commit**

Sem commits (ver Global Constraints) — `git add` apenas.
