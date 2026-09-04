# Mecanismo Central de Autorização — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a central `PermissionResolver` that computes a user's final permission for a `modulo.acao` string (perfil + overrides individuais), wire it into Laravel's Gate via a single `Gate::before`, share it to the Vue/Inertia frontend, and migrate the three existing hardcoded-gate modules (AnoLectivo, Estabelecimento, Core/Horario) onto it.

**Architecture:** A new `Modules\Permissao\Services\PermissionResolver` computes the full set of granted `'modulo.acao'` strings for a user (querying `role_permissoes` + `user_permissoes`, resolving module identity via a new `Modules\Permissao\Enums\Modulo` enum and action names already in `acoes`), cached through `Modules\Permissao\Support\PermissaoCache` (request-memory → `Cache` facade, invalidated on write, never by TTL). A `Gate::before` registered once in `PermissaoServiceProvider` intercepts any recognized `modulo.acao` ability and returns the Resolver's decision as a final `true`/`false` (never `null` for a recognized-but-denied ability — see spec §8). The computed set is also shared to every Inertia page as `permissoes`, consumed by a small `can()` JS helper.

**Tech Stack:** Laravel 12, PHPUnit (`Modules Feature`/`Modules Unit` test suites), Vue 3 + Inertia, existing `Modules\Permissao` module (Role/Modulo/Acao/RolePermissao/UserPermissao models already exist).

**Spec:** `docs/superpowers/specs/2026-09-02-mecanismo-central-autorizacao-design.md`

## Global Constraints

- Nunca commitar automaticamente — todo o trabalho fica `git add`-ado, nunca `git commit`, salvo pedido explícito do utilizador nesse momento.
- Todas as strings de permissão seguem o formato `{slug-do-modulo}.{nome-da-acção}` (ex: `'turmas.criar'`) — nunca outro separador ou ordem.
- `Modulo::fromSlug()` e `Modulo::tryFrom()` devolvem `null` para valores desconhecidos — nunca lançam excepção (usados para sondar input não fiável vindo de strings de ability).
- Uma ability `modulo.acao` reconhecida (`PermissionResolver::reconhece()` verdadeiro) devolve sempre `true`/`false` explícito do `Gate::before` — nunca `null`. Só uma ability não reconhecida devolve `null`.
- Nega individual (`user_permissoes.permitido = false`) vence sempre, incluindo para Admin Escola.
- Cache invalidado activamente no momento da escrita — nunca por TTL.
- Frontend (`can()` em Vue) é sempre só UX — nunca a única barreira; toda a rota fica sempre protegida no backend.

---

## Pré-requisitos de leitura (para quem executar isto sem ter visto a conversa)

- `Modules/Permissao/app/Models/{Role,Modulo,Acao,RolePermissao,UserPermissao,UserRole}.php` já existem e não mudam de forma (só de uso).
- `Modules/Core/app/Enums/Estado.php` (`ATIVO=1`, `INATIVO=0`, com `label()`) já existe e é reutilizado aqui.
- `Modules\Usuario\Models\User` é o modelo de utilizador real da aplicação (não `App\Models\User`, que é um stub Laravel não usado).
- `app/Providers/AppServiceProvider.php::boot()` tem hoje 4 `Gate::define`: `gerir-permissoes`, `gerir-usuarios`, `gerir-estabelecimento`, `gerir-ano-letivo`. Só os dois últimos saem neste plano (Tarefa 12).

---

### Task 1: Enum `Modulo` + seed do módulo Horario

**Files:**
- Create: `Modules/Permissao/app/Enums/Modulo.php`
- Modify: `Modules/Permissao/database/seeders/ModuloSeeder.php`
- Test: `Modules/Permissao/tests/Unit/ModuloEnumTest.php`
- Test: `Modules/Permissao/tests/Feature/ModuloSeederTest.php`

**Interfaces:**
- Produces: `Modules\Permissao\Enums\Modulo` — enum `int`-backed, casos `USUARIO=0, AUTORIZACAO=1, ANO_LECTIVO=2, LICENCA=3, ALUNO=4, PROFESSOR=5, TURMAS=6, MATRICULA=7, DISCIPLINA=8, NOTA=9, ESTABELECIMENTO=10, HORARIO=11`; métodos `slug(): string`, `label(): string`, `public static function fromSlug(string $slug): ?self` (devolve `null`, nunca lança excepção). `tryFrom(int): ?self` já vem nativo do PHP, não precisa de ser escrito.

- [ ] **Step 1: Escrever os testes falhados do enum**

```php
<?php

namespace Modules\Permissao\Tests\Unit;

use Modules\Permissao\Enums\Modulo;
use Tests\TestCase;

class ModuloEnumTest extends TestCase
{
    public function test_slug_e_fromslug_fazem_round_trip(): void
    {
        foreach (Modulo::cases() as $modulo) {
            $this->assertSame($modulo, Modulo::fromSlug($modulo->slug()));
        }
    }

    public function test_fromslug_devolve_null_para_slug_desconhecido(): void
    {
        $this->assertNull(Modulo::fromSlug('inexistente'));
    }

    public function test_slugs_especificos(): void
    {
        $this->assertSame('horario', Modulo::HORARIO->slug());
        $this->assertSame('turmas', Modulo::TURMAS->slug());
        $this->assertSame('ano-lectivo', Modulo::ANO_LECTIVO->slug());
        $this->assertSame('estabelecimento', Modulo::ESTABELECIMENTO->slug());
    }

    public function test_tryfrom_nativo_devolve_null_para_int_desconhecido(): void
    {
        $this->assertNull(Modulo::tryFrom(999));
        $this->assertSame(Modulo::HORARIO, Modulo::tryFrom(11));
    }
}
```

- [ ] **Step 2: Correr e confirmar que falha (classe não existe)**

Run: `php artisan test --testsuite="Modules Unit" --filter=ModuloEnumTest`
Expected: FAIL — `Class "Modules\Permissao\Enums\Modulo" not found`

- [ ] **Step 3: Criar o enum**

```php
<?php

namespace Modules\Permissao\Enums;

enum Modulo: int
{
    case USUARIO = 0;
    case AUTORIZACAO = 1;
    case ANO_LECTIVO = 2;
    case LICENCA = 3;
    case ALUNO = 4;
    case PROFESSOR = 5;
    case TURMAS = 6;
    case MATRICULA = 7;
    case DISCIPLINA = 8;
    case NOTA = 9;
    case ESTABELECIMENTO = 10;
    case HORARIO = 11;

    public function slug(): string
    {
        return match ($this) {
            self::USUARIO => 'usuario',
            self::AUTORIZACAO => 'autorizacao',
            self::ANO_LECTIVO => 'ano-lectivo',
            self::LICENCA => 'licenca',
            self::ALUNO => 'aluno',
            self::PROFESSOR => 'professor',
            self::TURMAS => 'turmas',
            self::MATRICULA => 'matricula',
            self::DISCIPLINA => 'disciplina',
            self::NOTA => 'nota',
            self::ESTABELECIMENTO => 'estabelecimento',
            self::HORARIO => 'horario',
        };
    }

    public static function fromSlug(string $slug): ?self
    {
        foreach (self::cases() as $modulo) {
            if ($modulo->slug() === $slug) {
                return $modulo;
            }
        }

        return null;
    }

    public function label(): string
    {
        return match ($this) {
            self::USUARIO => 'Usuário',
            self::AUTORIZACAO => 'Autorização',
            self::ANO_LECTIVO => 'Ano Lectivo',
            self::LICENCA => 'Licença',
            self::ALUNO => 'Aluno',
            self::PROFESSOR => 'Professor',
            self::TURMAS => 'Turmas',
            self::MATRICULA => 'Matrícula',
            self::DISCIPLINA => 'Disciplina',
            self::NOTA => 'Nota',
            self::ESTABELECIMENTO => 'Estabelecimento',
            self::HORARIO => 'Horário',
        };
    }
}
```

- [ ] **Step 4: Correr e confirmar que passa**

Run: `php artisan test --testsuite="Modules Unit" --filter=ModuloEnumTest`
Expected: PASS (4 testes)

- [ ] **Step 5: Escrever o teste falhado do seeder**

```php
<?php

namespace Modules\Permissao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Permissao\Database\Seeders\ModuloSeeder;
use Tests\TestCase;

class ModuloSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_inclui_o_modulo_horario(): void
    {
        $this->seed(ModuloSeeder::class);

        $this->assertDatabaseHas('modulos', ['nome' => 11, 'descricao' => 'Horario']);
    }
}
```

- [ ] **Step 6: Correr e confirmar que falha**

Run: `php artisan test --testsuite="Modules Feature" --filter=ModuloSeederTest`
Expected: FAIL — nenhuma linha com `nome=11`

- [ ] **Step 7: Acrescentar a linha `HORARIO` ao seeder**

Em `Modules/Permissao/database/seeders/ModuloSeeder.php`, acrescentar ao array de `Modulo::insert([...])` (depois da linha `nome => 10`):

```php
            ['nome' => 11, 'descricao' => 'Horario', 'created_at' => $now, 'updated_at' => $now],
```

- [ ] **Step 8: Correr e confirmar que passa**

Run: `php artisan test --testsuite="Modules Feature" --filter=ModuloSeederTest`
Expected: PASS

- [ ] **Step 9: Stage (nunca commit)**

```bash
git add Modules/Permissao/app/Enums/Modulo.php Modules/Permissao/database/seeders/ModuloSeeder.php Modules/Permissao/tests/Unit/ModuloEnumTest.php Modules/Permissao/tests/Feature/ModuloSeederTest.php
```

---

### Task 2: `PermissaoCache` — mecânica de cache e invalidação

**Files:**
- Create: `Modules/Permissao/app/Support/PermissaoCache.php`
- Test: `Modules/Permissao/tests/Feature/PermissaoCacheTest.php`

**Interfaces:**
- Consumes: nada de tarefas anteriores (usa só a `Cache` facade do Laravel).
- Produces: `Modules\Permissao\Support\PermissaoCache` com `chave(int $userId): string`, `obter(int $userId): ?array`, `guardar(int $userId, array $conjunto): void`, `esquecerUtilizador(int $userId): void`, `invalidarTudo(): void`. Usado pela Tarefa 3 (`PermissionResolver`) e pela Tarefa 4 (invalidação nas Actions).

- [ ] **Step 1: Escrever os testes falhados**

```php
<?php

namespace Modules\Permissao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Permissao\Support\PermissaoCache;
use Tests\TestCase;

class PermissaoCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_guardar_e_obter_devolve_o_mesmo_conjunto(): void
    {
        $cache = new PermissaoCache();
        $cache->guardar(42, ['turmas.ver', 'turmas.criar']);

        $this->assertSame(['turmas.ver', 'turmas.criar'], $cache->obter(42));
    }

    public function test_obter_devolve_null_quando_nao_ha_nada_guardado(): void
    {
        $cache = new PermissaoCache();

        $this->assertNull($cache->obter(999));
    }

    public function test_esquecer_utilizador_remove_so_a_chave_desse_utilizador(): void
    {
        $cache = new PermissaoCache();
        $cache->guardar(1, ['a.b']);
        $cache->guardar(2, ['c.d']);

        $cache->esquecerUtilizador(1);

        $this->assertNull($cache->obter(1));
        $this->assertSame(['c.d'], $cache->obter(2));
    }

    public function test_invalidar_tudo_torna_todas_as_chaves_antigas_inacessiveis(): void
    {
        $cache = new PermissaoCache();
        $cache->guardar(1, ['a.b']);
        $cache->guardar(2, ['c.d']);

        $cache->invalidarTudo();

        $this->assertNull($cache->obter(1));
        $this->assertNull($cache->obter(2));
    }
}
```

- [ ] **Step 2: Correr e confirmar que falha**

Run: `php artisan test --testsuite="Modules Feature" --filter=PermissaoCacheTest`
Expected: FAIL — `Class "Modules\Permissao\Support\PermissaoCache" not found`

- [ ] **Step 3: Implementar**

```php
<?php

namespace Modules\Permissao\Support;

use Illuminate\Support\Facades\Cache;

class PermissaoCache
{
    private const CHAVE_EPOCH = 'permissoes:epoch';

    public function chave(int $userId): string
    {
        return "permissoes:v{$this->epoch()}:user:{$userId}";
    }

    public function obter(int $userId): ?array
    {
        return Cache::get($this->chave($userId));
    }

    public function guardar(int $userId, array $conjunto): void
    {
        Cache::forever($this->chave($userId), $conjunto);
    }

    public function esquecerUtilizador(int $userId): void
    {
        Cache::forget($this->chave($userId));
    }

    public function invalidarTudo(): void
    {
        Cache::forever(self::CHAVE_EPOCH, $this->epoch() + 1);
    }

    private function epoch(): int
    {
        return (int) Cache::get(self::CHAVE_EPOCH, 1);
    }
}
```

- [ ] **Step 4: Correr e confirmar que passa**

Run: `php artisan test --testsuite="Modules Feature" --filter=PermissaoCacheTest`
Expected: PASS (4 testes)

- [ ] **Step 5: Stage**

```bash
git add Modules/Permissao/app/Support/PermissaoCache.php Modules/Permissao/tests/Feature/PermissaoCacheTest.php
```

---

### Task 3: `PermissionResolver` — algoritmo de resolução

**Files:**
- Create: `Modules/Permissao/app/Services/PermissionResolver.php`
- Test: `Modules/Permissao/tests/Feature/PermissionResolverTest.php`

**Interfaces:**
- Consumes: `Modules\Permissao\Enums\Modulo` (Tarefa 1: `tryFrom(int): ?self`, `fromSlug(string): ?self`, `slug(): string`), `Modules\Permissao\Support\PermissaoCache` (Tarefa 2: `obter`, `guardar`), `Modules\Core\Enums\Estado::ATIVO` (já existe), modelos `Modules\Permissao\Models\{Role,RolePermissao,UserPermissao,Acao}` (já existem), `Modules\Usuario\Models\User` (já existe, tem `roles(): BelongsToMany`).
- Produces: `Modules\Permissao\Services\PermissionResolver` com `conjuntoConcedido(User $user): array` (array de strings `'modulo.acao'`), `can(User $user, string $permissao): bool`, `reconhece(string $permissao): bool`. Usado pela Tarefa 5 (`Gate::before`), Tarefa 11 (partilha Inertia) e por todas as migrações de módulo (Tarefas 7-9, indirectamente via `Gate::before`).

- [ ] **Step 1: Escrever os testes falhados**

```php
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
}
```

- [ ] **Step 2: Correr e confirmar que falha**

Run: `php artisan test --testsuite="Modules Feature" --filter=PermissionResolverTest`
Expected: FAIL — `Class "Modules\Permissao\Services\PermissionResolver" not found`

- [ ] **Step 3: Implementar**

```php
<?php

namespace Modules\Permissao\Services;

use Illuminate\Support\Collection;
use Modules\Core\Enums\Estado;
use Modules\Permissao\Enums\Modulo;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo as ModuloRegistro;
use Modules\Permissao\Models\RolePermissao;
use Modules\Permissao\Models\UserPermissao;
use Modules\Permissao\Support\PermissaoCache;
use Modules\Usuario\Models\User;

class PermissionResolver
{
    private array $memoria = [];

    public function __construct(private readonly PermissaoCache $cache)
    {
    }

    public function reconhece(string $permissao): bool
    {
        if (!str_contains($permissao, '.')) {
            return false;
        }

        [$moduloSlug, $acaoNome] = explode('.', $permissao, 2);

        return Modulo::fromSlug($moduloSlug) !== null
            && Acao::where('nome', $acaoNome)->exists();
    }

    public function can(User $user, string $permissao): bool
    {
        return in_array($permissao, $this->conjuntoConcedido($user), true);
    }

    public function conjuntoConcedido(User $user): array
    {
        if (array_key_exists($user->id, $this->memoria)) {
            return $this->memoria[$user->id];
        }

        $conjunto = $this->cache->obter($user->id);
        if ($conjunto === null) {
            $conjunto = $this->calcular($user);
            $this->cache->guardar($user->id, $conjunto);
        }

        return $this->memoria[$user->id] = $conjunto;
    }

    private function calcular(User $user): array
    {
        // NOTA: modulo_id em role_permissoes/user_permissoes é a FK para o
        // id (PK auto-incremento) da tabela modulos, NÃO o valor do enum
        // (esse vive na coluna modulos.nome) — por isso o mapa id->nome
        // abaixo é obrigatório antes de chamar Modulo::tryFrom().
        $moduloNomesPorId = ModuloRegistro::pluck('nome', 'id');
        $acaoNomesPorId = Acao::pluck('nome', 'id');

        $roleIds = $user->roles()
            ->where('estado', Estado::ATIVO->value)
            ->pluck('roles.id');

        $doPerfil = RolePermissao::whereIn('role_id', $roleIds)
            ->get(['modulo_id', 'acao_id'])
            ->map(fn (RolePermissao $linha) => $this->paraString($linha->modulo_id, $linha->acao_id, $moduloNomesPorId, $acaoNomesPorId))
            ->filter()
            ->toBase();

        $overrides = UserPermissao::where('users_id', $user->id)->get(['modulo_id', 'acao_id', 'permitido']);

        $concedidos = $overrides->where('permitido', true)
            ->map(fn (UserPermissao $linha) => $this->paraString($linha->modulo_id, $linha->acao_id, $moduloNomesPorId, $acaoNomesPorId))
            ->filter()
            ->toBase();

        $negados = $overrides->where('permitido', false)
            ->map(fn (UserPermissao $linha) => $this->paraString($linha->modulo_id, $linha->acao_id, $moduloNomesPorId, $acaoNomesPorId))
            ->filter()
            ->all();

        return $doPerfil->merge($concedidos)
            ->unique()
            ->reject(fn (string $permissao) => in_array($permissao, $negados, true))
            ->values()
            ->all();
    }

    private function paraString(int $moduloId, int $acaoId, Collection $moduloNomesPorId, Collection $acaoNomesPorId): ?string
    {
        $moduloNome = $moduloNomesPorId->get($moduloId);
        $modulo = $moduloNome !== null ? Modulo::tryFrom($moduloNome) : null;
        $acaoNome = $acaoNomesPorId->get($acaoId);

        if ($modulo === null || $acaoNome === null) {
            return null;
        }

        return "{$modulo->slug()}.{$acaoNome}";
    }
}
```

**Nota (correcção pós-implementação — ver ledger da Tarefa 3):** esta versão já corrige 2 bugs que existiam na primeira redacção deste plano: (1) `modulo_id` em `role_permissoes`/`user_permissoes` é a FK para o `id` auto-incremento de `modulos`, não o valor do enum (que vive em `modulos.nome`) — precisa do mapa `moduloNomesPorId` antes de `Modulo::tryFrom()`; (2) `Illuminate\Database\Eloquent\Collection::map()` não faz demote para `Collection` base quando o resultado fica vazio, e o `merge()` do Eloquent assume itens `Model` — daí o `->toBase()` antes do `merge()`.

**Nota:** `roles.estado` filtrado a `ATIVO` no cálculo é uma extensão pequena mas necessária à secção 6 da spec — sem isto, `AlternarEstadoPerfilAction` (Tarefa 4) desactivar um perfil não teria qualquer efeito prático nas permissões de quem o tem atribuído.

- [ ] **Step 4: Correr e confirmar que passa**

Run: `php artisan test --testsuite="Modules Feature" --filter=PermissionResolverTest`
Expected: PASS (7 testes)

- [ ] **Step 5: Stage**

```bash
git add Modules/Permissao/app/Services/PermissionResolver.php Modules/Permissao/tests/Feature/PermissionResolverTest.php
```

---

### Task 4: Invalidação da cache nas Actions que escrevem permissões

**Files:**
- Modify: `Modules/Permissao/app/Actions/SincronizarPermissoesPerfilAction.php`
- Modify: `Modules/Permissao/app/Actions/SincronizarPermissoesUtilizadorAction.php`
- Modify: `Modules/Permissao/app/Actions/AtribuirPerfilAction.php`
- Modify: `Modules/Permissao/app/Actions/RemoverPerfilAction.php`
- Modify: `Modules/Permissao/app/Actions/AlternarEstadoPerfilAction.php`
- Modify: `Modules/Permissao/app/Actions/EliminarPerfilAction.php`
- Test: `Modules/Permissao/tests/Feature/PermissaoCacheInvalidationTest.php`

**Interfaces:**
- Consumes: `Modules\Permissao\Support\PermissaoCache` (Tarefa 2), `Modules\Permissao\Services\PermissionResolver` (Tarefa 3, só para o teste observar o efeito).
- Produces: nada de novo — só efeitos colaterais (invalidação) nas Actions já existentes. Laravel resolve `PermissaoCache` automaticamente por injecção de construtor onde estas Actions já são instanciadas via o container (confirmado: `PermissaoController` usa `GestaoPerfilService`, que por sua vez injecta estas Actions).

- [ ] **Step 1: Escrever o teste falhado**

```php
<?php

namespace Modules\Permissao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Actions\AtribuirPerfilAction;
use Modules\Permissao\Actions\RemoverPerfilAction;
use Modules\Permissao\Actions\SincronizarPermissoesPerfilAction;
use Modules\Permissao\Actions\SincronizarPermissoesUtilizadorAction;
use Modules\Permissao\Database\Seeders\AcaoSeeder;
use Modules\Permissao\Database\Seeders\ModuloSeeder;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo as ModuloRegistro;
use Modules\Permissao\Models\Role;
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
        $this->assertFalse(app(PermissionResolver::class)->can($userB, 'turmas.criar'));
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
}
```

- [ ] **Step 2: Correr e confirmar que falha**

Run: `php artisan test --testsuite="Modules Feature" --filter=PermissaoCacheInvalidationTest`
Expected: FAIL — os `assertTrue`/segundo `assertFalse` falham porque a cache antiga (calculada no `assertFalse` inicial) nunca é invalidada.

- [ ] **Step 3: Adicionar a invalidação a cada Action**

Em `SincronizarPermissoesPerfilAction.php`, injectar `PermissaoCache` e invalidar tudo (afecta todos os utilizadores com essa role):

```php
<?php

namespace Modules\Permissao\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Modules\Permissao\Support\PermissaoCache;

class SincronizarPermissoesPerfilAction
{
    public function __construct(private readonly PermissaoCache $cache)
    {
    }

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

        $this->cache->invalidarTudo();
    }
}
```

Em `SincronizarPermissoesUtilizadorAction.php`, injectar `PermissaoCache` e invalidar só esse utilizador:

```php
<?php

namespace Modules\Permissao\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Permissao\Models\UserPermissao;
use Modules\Permissao\Support\PermissaoCache;
use Modules\Usuario\Models\User;

class SincronizarPermissoesUtilizadorAction
{
    public function __construct(private readonly PermissaoCache $cache)
    {
    }

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

        $this->cache->esquecerUtilizador($user->id);
    }
}
```

Em `AtribuirPerfilAction.php`:

```php
<?php

namespace Modules\Permissao\Actions;

use Modules\Permissao\Support\PermissaoCache;
use Modules\Usuario\Models\User;

class AtribuirPerfilAction
{
    public function __construct(private readonly PermissaoCache $cache)
    {
    }

    public function executar(User $user, int $roleId): void
    {
        $user->roles()->syncWithoutDetaching([$roleId]);
        $this->cache->esquecerUtilizador($user->id);
    }
}
```

Em `RemoverPerfilAction.php`:

```php
<?php

namespace Modules\Permissao\Actions;

use Modules\Permissao\Models\Role;
use Modules\Permissao\Support\PermissaoCache;
use Modules\Usuario\Models\User;

class RemoverPerfilAction
{
    public function __construct(private readonly PermissaoCache $cache)
    {
    }

    public function executar(User $user, Role $role): void
    {
        $user->roles()->detach($role->id);
        $this->cache->esquecerUtilizador($user->id);
    }
}
```

Em `AlternarEstadoPerfilAction.php` (desactivar/activar uma role afecta todos os que a têm — invalida tudo):

```php
<?php

namespace Modules\Permissao\Actions;

use Modules\Core\Traits\AlternaEstado;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Support\PermissaoCache;

class AlternarEstadoPerfilAction
{
    use AlternaEstado;

    public function __construct(private readonly PermissaoCache $cache)
    {
    }

    public function executar(Role $role): Role
    {
        $resultado = $this->alternarEstado($role);
        $this->cache->invalidarTudo();

        return $resultado;
    }
}
```

Em `EliminarPerfilAction.php` (rede de segurança — já bloqueada quando há utilizadores, mas barato de garantir):

```php
<?php

namespace Modules\Permissao\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Support\PermissaoCache;

class EliminarPerfilAction
{
    public function __construct(private readonly PermissaoCache $cache)
    {
    }

    public function executar(Role $role): void
    {
        if ($role->eSistema()) {
            throw ValidationException::withMessages([
                'perfil' => 'Perfis de sistema não podem ser eliminados.',
            ]);
        }

        if ($role->users()->exists()) {
            throw ValidationException::withMessages([
                'perfil' => 'Este perfil tem utilizadores atribuídos e não pode ser eliminado.',
            ]);
        }

        $role->delete();
        $this->cache->invalidarTudo();
    }
}
```

- [ ] **Step 4: Correr e confirmar que passa**

Run: `php artisan test --testsuite="Modules Feature" --filter=PermissaoCacheInvalidationTest`
Expected: PASS (3 testes)

- [ ] **Step 5: Corrigir os dois testes existentes que instanciam estas Actions manualmente com `new`**

Confirmado por grep: `Modules/Permissao/tests/Feature/SincronizarPermissoesPerfilActionTest.php` e `Modules/Permissao/tests/Feature/SincronizarPermissoesUtilizadorActionTest.php` fazem `(new SincronizarPermissoesPerfilAction())->executar(...)` / `(new SincronizarPermissoesUtilizadorAction())->executar(...)` (2 ocorrências em cada ficheiro) — isto parte assim que a Action ganha `PermissaoCache` no construtor, porque `new X()` não injecta dependências.

Em `SincronizarPermissoesPerfilActionTest.php`, trocar as duas ocorrências:

```php
        app(SincronizarPermissoesPerfilAction::class)->executar($role, [
            ['modulo_id' => $modulo2->id, 'acao_id' => $acaoCriar->id],
        ]);
```

```php
        app(SincronizarPermissoesPerfilAction::class)->executar($role, []);
```

Em `SincronizarPermissoesUtilizadorActionTest.php`, trocar as duas ocorrências:

```php
        app(SincronizarPermissoesUtilizadorAction::class)->executar($user, [
            ['modulo_id' => $modulo2->id, 'acao_id' => $acao->id, 'permitido' => false],
        ]);
```

```php
        app(SincronizarPermissoesUtilizadorAction::class)->executar($user, []);
```

- [ ] **Step 6: Correr toda a suite do módulo Permissao para confirmar que nada mais partiu**

Run: `php artisan test --testsuite="Modules Feature","Modules Unit" --filter=Permissao`
Expected: PASS em todos os testes do módulo

- [ ] **Step 7: Stage**

```bash
git add Modules/Permissao/app/Actions/SincronizarPermissoesPerfilAction.php Modules/Permissao/app/Actions/SincronizarPermissoesUtilizadorAction.php Modules/Permissao/app/Actions/AtribuirPerfilAction.php Modules/Permissao/app/Actions/RemoverPerfilAction.php Modules/Permissao/app/Actions/AlternarEstadoPerfilAction.php Modules/Permissao/app/Actions/EliminarPerfilAction.php Modules/Permissao/tests/Feature/PermissaoCacheInvalidationTest.php Modules/Permissao/tests/Feature/SincronizarPermissoesPerfilActionTest.php Modules/Permissao/tests/Feature/SincronizarPermissoesUtilizadorActionTest.php
```

---

### Task 5: `Gate::before` — ligação ao Laravel

**Files:**
- Modify: `Modules/Permissao/app/Providers/PermissaoServiceProvider.php`
- Test: `Modules/Permissao/tests/Feature/GateBeforeTest.php`

**Interfaces:**
- Consumes: `Modules\Permissao\Services\PermissionResolver` (Tarefa 3: `reconhece(string): bool`, `can(User, string): bool`).
- Produces: comportamento global do `Gate` — qualquer `Route::middleware('can:modulo.acao')` ou `$this->authorize('modulo.acao')` na aplicação passa a resolver via `PermissionResolver`. Necessário pelas Tarefas 7-9 (migração dos 3 módulos).

- [ ] **Step 1: Escrever os testes falhados**

```php
<?php

namespace Modules\Permissao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Database\Seeders\AcaoSeeder;
use Modules\Permissao\Database\Seeders\ModuloSeeder;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo as ModuloRegistro;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class GateBeforeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ModuloSeeder::class);
        $this->seed(AcaoSeeder::class);
    }

    public function test_ability_reconhecida_e_concedida_via_gate(): void
    {
        $role = Role::create(['nome' => 99, 'descricao' => 'Teste']);
        $modulo = ModuloRegistro::where('nome', 6)->first();
        $acao = Acao::where('nome', 'criar')->first();
        RolePermissao::create(['role_id' => $role->id, 'modulo_id' => $modulo->id, 'acao_id' => $acao->id]);

        $user = User::create(['name' => 'U', 'email' => 'u@example.com', 'password' => Hash::make('x')]);
        $user->roles()->attach($role->id);

        $this->assertTrue(Gate::forUser($user)->allows('turmas.criar'));
    }

    public function test_ability_nao_reconhecida_nao_e_interceptada(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'u2@example.com', 'password' => Hash::make('x')]);

        // Sem ponto: o nosso Gate::before devolve null e o Laravel segue o
        // caminho normal — sem ability nem Policy registada para 'algo-qualquer',
        // o resultado por omissão do Laravel é negar.
        $this->assertFalse(Gate::forUser($user)->allows('algo-qualquer'));
    }

    public function test_ability_reconhecida_e_negada_vence_mesmo_com_outro_gate_before_a_conceder_tudo(): void
    {
        $user = User::create(['name' => 'U', 'email' => 'u3@example.com', 'password' => Hash::make('x')]);
        // Sem role nenhuma atribuída -> o nosso resolver nega 'turmas.criar'.

        // Um segundo Gate::before, registado DEPOIS do nosso, que concederia
        // tudo -- simula um bypass futuro (ex: super-admin) mal desenhado.
        Gate::before(fn () => true);

        $this->assertFalse(Gate::forUser($user)->allows('turmas.criar'));
    }
}
```

- [ ] **Step 2: Correr e confirmar que falha**

Run: `php artisan test --testsuite="Modules Feature" --filter=GateBeforeTest`
Expected: FAIL — sem `Gate::before` registado, `Gate::forUser($user)->allows('turmas.criar')` é sempre `false` mesmo quando devia ser `true` (primeiro teste falha); o terceiro teste passaria por acaso hoje mas por motivo errado (não há mecanismo nenhum, não é uma prova de nada).

- [ ] **Step 3: Registar o `Gate::before` no `PermissaoServiceProvider`**

```php
<?php

namespace Modules\Permissao\Providers;

use Illuminate\Support\Facades\Gate;
use Modules\Permissao\Services\PermissionResolver;
use Modules\Usuario\Models\User;
use Nwidart\Modules\Support\ModuleServiceProvider;

class PermissaoServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Permissao';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'permissao';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');

        Gate::before(function (User $user, string $ability) {
            $resolver = app(PermissionResolver::class);

            if (!$resolver->reconhece($ability)) {
                return null;
            }

            return $resolver->can($user, $ability);
        });
    }
}
```

- [ ] **Step 4: Correr e confirmar que passa**

Run: `php artisan test --testsuite="Modules Feature" --filter=GateBeforeTest`
Expected: PASS (3 testes)

- [ ] **Step 5: Correr a suite completa para confirmar que registar um `Gate::before` global não parte nada já existente** (ex: `gerir-ano-letivo` continua a funcionar, por não ter ponto)

Run: `php artisan test --testsuite="Modules Feature","Modules Unit"`
Expected: PASS em toda a suite

- [ ] **Step 6: Stage**

```bash
git add Modules/Permissao/app/Providers/PermissaoServiceProvider.php Modules/Permissao/tests/Feature/GateBeforeTest.php
```

---

### Task 6: Seed de `role_permissoes` para ADMIN_ESCOLA (paridade com as gates antigas)

**Files:**
- Create: `Modules/Permissao/database/seeders/RolePermissaoSeeder.php`
- Modify: `Modules/Permissao/database/seeders/PermissaoDatabaseSeeder.php`
- Test: `Modules/Permissao/tests/Feature/RolePermissaoSeederTest.php`

**Interfaces:**
- Consumes: `Modules\Permissao\Enums\Modulo` (Tarefa 1), modelos `Role`/`Modulo`/`Acao`/`RolePermissao` já existentes, `Modules\Permissao\Enums\Perfil::ADMIN_ESCOLA` já existente.
- Produces: linhas em `role_permissoes` para ADMIN_ESCOLA cobrindo `ano-lectivo` (ver/criar/editar/eliminar), `estabelecimento` (ver/editar), `horario` (ver/criar/editar/eliminar) — pré-requisito das Tarefas 7-9 (sem isto, migrar as strings de autorização é uma regressão de acesso real).

- [ ] **Step 1: Escrever o teste falhado**

```php
<?php

namespace Modules\Permissao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Permissao\Database\Seeders\AcaoSeeder;
use Modules\Permissao\Database\Seeders\ModuloSeeder;
use Modules\Permissao\Database\Seeders\RolePermissaoSeeder;
use Modules\Permissao\Database\Seeders\RoleSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Services\PermissionResolver;
use Modules\Usuario\Models\User;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Models\Role;
use Tests\TestCase;

class RolePermissaoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ModuloSeeder::class);
        $this->seed(AcaoSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->seed(RolePermissaoSeeder::class);
    }

    public function test_admin_escola_tem_a_paridade_dos_3_modulos_migrados(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => Hash::make('x')]);
        $admin->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id);

        $resolver = app(PermissionResolver::class);

        foreach (['ver', 'criar', 'editar', 'eliminar'] as $acao) {
            $this->assertTrue($resolver->can($admin, "ano-lectivo.{$acao}"), "ano-lectivo.{$acao}");
            $this->assertTrue($resolver->can($admin, "horario.{$acao}"), "horario.{$acao}");
        }
        $this->assertTrue($resolver->can($admin, 'estabelecimento.ver'));
        $this->assertTrue($resolver->can($admin, 'estabelecimento.editar'));
        $this->assertFalse($resolver->can($admin, 'estabelecimento.criar'), 'Estabelecimento é singleton, não tem criar');
        $this->assertFalse($resolver->can($admin, 'estabelecimento.eliminar'), 'Estabelecimento é singleton, não tem eliminar');
    }

    public function test_professor_nao_tem_nenhuma_destas_permissoes(): void
    {
        $professor = User::create(['name' => 'P', 'email' => 'p@example.com', 'password' => Hash::make('x')]);
        $professor->roles()->attach(Role::where('nome', Perfil::PROFESSOR->value)->first()->id);

        $resolver = app(PermissionResolver::class);

        $this->assertFalse($resolver->can($professor, 'ano-lectivo.ver'));
        $this->assertFalse($resolver->can($professor, 'horario.ver'));
        $this->assertFalse($resolver->can($professor, 'estabelecimento.ver'));
    }
}
```

- [ ] **Step 2: Correr e confirmar que falha**

Run: `php artisan test --testsuite="Modules Feature" --filter=RolePermissaoSeederTest`
Expected: FAIL — `Class "Modules\Permissao\Database\Seeders\RolePermissaoSeeder" not found`

- [ ] **Step 3: Criar o seeder**

```php
<?php

namespace Modules\Permissao\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Permissao\Enums\Modulo;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo as ModuloRegistro;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;

class RolePermissaoSeeder extends Seeder
{
    /**
     * Concede a ADMIN_ESCOLA exactamente o que as antigas gates fixas
     * ('gerir-ano-letivo', 'gerir-estabelecimento') já davam, para migrar
     * sem regressão de acesso. Nenhuma outra role recebe nada aqui.
     */
    public function run(): void
    {
        $adminEscola = Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first();
        if (!$adminEscola) {
            return;
        }

        $mapa = [
            Modulo::ANO_LECTIVO->value => ['ver', 'criar', 'editar', 'eliminar'],
            Modulo::ESTABELECIMENTO->value => ['ver', 'editar'],
            Modulo::HORARIO->value => ['ver', 'criar', 'editar', 'eliminar'],
        ];

        foreach ($mapa as $moduloNome => $acoes) {
            $modulo = ModuloRegistro::where('nome', $moduloNome)->first();
            if (!$modulo) {
                continue;
            }

            foreach ($acoes as $acaoNome) {
                $acao = Acao::where('nome', $acaoNome)->first();
                if (!$acao) {
                    continue;
                }

                RolePermissao::firstOrCreate([
                    'role_id' => $adminEscola->id,
                    'modulo_id' => $modulo->id,
                    'acao_id' => $acao->id,
                ]);
            }
        }
    }
}
```

- [ ] **Step 4: Registar no `PermissaoDatabaseSeeder`** (depois de `RoleSeeder`/`ModuloSeeder`/`AcaoSeeder`, que já lá estão — a ordem importa, `RolePermissaoSeeder` precisa das roles/módulos/acções já existirem)

```php
<?php

namespace Modules\Permissao\Database\Seeders;

use Illuminate\Database\Seeder;

class PermissaoDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AcaoSeeder::class,
            RoleSeeder::class,
            ModuloSeeder::class,
            RolePermissaoSeeder::class,
        ]);
    }
}
```

- [ ] **Step 5: Correr e confirmar que passa**

Run: `php artisan test --testsuite="Modules Feature" --filter=RolePermissaoSeederTest`
Expected: PASS (2 testes)

- [ ] **Step 6: Correr o seeder na base de dados de desenvolvimento** (necessário para a verificação manual em browser nas Tarefas 7-9 e 13 — sem isto, ADMIN_ESCOLA fica sem acesso assim que as strings antigas saírem)

Run: `php artisan db:seed --class="Modules\Permissao\Database\Seeders\RolePermissaoSeeder"`
Expected: sem erros; confirmar com `php artisan tinker --execute="echo Modules\Permissao\Models\RolePermissao::count();"` que o número subiu (10 linhas novas: 4+2+4)

- [ ] **Step 7: Stage**

```bash
git add Modules/Permissao/database/seeders/RolePermissaoSeeder.php Modules/Permissao/database/seeders/PermissaoDatabaseSeeder.php Modules/Permissao/tests/Feature/RolePermissaoSeederTest.php
```

---

### Task 7: Migrar AnoLectivo para `ano-lectivo.*`

**Files:**
- Modify: `Modules/AnoLectivo/routes/web.php`
- Modify: `Modules/AnoLectivo/app/Http/Controllers/AnoLectivoController.php`
- Modify: `Modules/AnoLectivo/app/Http/Controllers/PeriodoController.php`
- Modify: `Modules/AnoLectivo/app/Http/Controllers/EventoCalendarioController.php`
- Modify: `Modules/AnoLectivo/app/Http/Requests/CriarAnoLectivoRequest.php`
- Modify: `Modules/AnoLectivo/app/Http/Requests/AtualizarAnoLectivoRequest.php`
- Modify: `Modules/AnoLectivo/app/Http/Requests/AlterarEstadoAnoLectivoRequest.php`
- Modify: `Modules/AnoLectivo/app/Http/Requests/CriarPeriodoRequest.php`
- Modify: `Modules/AnoLectivo/app/Http/Requests/AtualizarPeriodoRequest.php`
- Modify: `Modules/AnoLectivo/app/Http/Requests/CriarEventoCalendarioRequest.php`
- Modify: `Modules/AnoLectivo/app/Http/Requests/AtualizarEventoCalendarioRequest.php`
- Delete: `Modules/AnoLectivo/app/Policies/AnoLectivoPolicy.php`
- Delete: `Modules/AnoLectivo/app/Policies/PeriodoPolicy.php`
- Delete: `Modules/AnoLectivo/app/Policies/EventoCalendarioPolicy.php`
- Modify: `Modules/AnoLectivo/tests/Feature/AnoLectivoAutorizacaoTest.php`
- Modify: todos os `Modules/AnoLectivo/tests/Feature/*.php` que hoje fazem `$this->seed(RoleSeeder::class)` (listar exacto no Step 1)

**Interfaces:**
- Consumes: `Gate::before` (Tarefa 5) já regista o mecanismo; `RolePermissaoSeeder` (Tarefa 6) já garante que ADMIN_ESCOLA tem `ano-lectivo.{ver,criar,editar,eliminar}`.
- Produces: nada que outras tarefas consumam — módulo auto-contido.

- [ ] **Step 1: Confirmar quais testes de AnoLectivo dependem só de `RoleSeeder` e vão precisar de `PermissaoDatabaseSeeder`**

Run: `grep -rl "RoleSeeder::class" Modules/AnoLectivo/tests/`

Expected output (ficheiros a editar no Step 5): `AnoLectivoAutorizacaoTest.php`, `AnoLectivoHttpTest.php`, `PeriodoHttpTest.php`, `EventoCalendarioHttpTest.php`, `CriarAnoLectivoActionTest.php`, `AtualizarAnoLectivoActionTest.php`, `AlterarEstadoAnoLectivoActionTest.php`, `EliminarAnoLectivoActionTest.php`, `CriarPeriodoActionTest.php`, `AtualizarPeriodoActionTest.php`, `EliminarPeriodoActionTest.php`, `CriarEventoCalendarioActionTest.php`, `AtualizarEventoCalendarioActionTest.php`, `EliminarEventoCalendarioActionTest.php`, `AnoLectivoTenancyTest.php`, `AnoLectivoEstabelecimentoConsistenciaTest.php` (a lista exacta pode variar ligeiramente — usar o resultado real do `grep`, não esta lista de memória).

- [ ] **Step 2: Trocar as rotas para uma `can:` por acção**

```php
<?php

use Illuminate\Support\Facades\Route;
use Modules\AnoLectivo\Http\Controllers\AnoLectivoController;
use Modules\AnoLectivo\Http\Controllers\EventoCalendarioController;
use Modules\AnoLectivo\Http\Controllers\PeriodoController;

Route::middleware(['auth'])->prefix('ano-lectivos')->name('ano-lectivos.')->group(function () {
    Route::get('/', [AnoLectivoController::class, 'index'])->middleware('can:ano-lectivo.ver')->name('index');
    Route::post('/', [AnoLectivoController::class, 'store'])->middleware('can:ano-lectivo.criar')->name('store');
    Route::get('/{anoLectivo}', [AnoLectivoController::class, 'show'])->middleware('can:ano-lectivo.ver')->name('show');
    Route::put('/{anoLectivo}', [AnoLectivoController::class, 'update'])->middleware('can:ano-lectivo.editar')->name('update');
    Route::patch('/{anoLectivo}/estado', [AnoLectivoController::class, 'alterarEstado'])->middleware('can:ano-lectivo.editar')->name('alterar-estado');
    Route::delete('/{anoLectivo}', [AnoLectivoController::class, 'destroy'])->middleware('can:ano-lectivo.eliminar')->name('destroy');

    Route::post('/{anoLectivo}/periodos', [PeriodoController::class, 'store'])->middleware('can:ano-lectivo.criar')->name('periodos.store');
    Route::post('/{anoLectivo}/eventos-calendario', [EventoCalendarioController::class, 'store'])->middleware('can:ano-lectivo.criar')->name('eventos.store');
});

Route::middleware(['auth'])->group(function () {
    Route::put('/periodos/{periodo}', [PeriodoController::class, 'update'])->middleware('can:ano-lectivo.editar')->name('periodos.update');
    Route::delete('/periodos/{periodo}', [PeriodoController::class, 'destroy'])->middleware('can:ano-lectivo.eliminar')->name('periodos.destroy');
    Route::put('/eventos-calendario/{evento}', [EventoCalendarioController::class, 'update'])->middleware('can:ano-lectivo.editar')->name('eventos.update');
    Route::delete('/eventos-calendario/{evento}', [EventoCalendarioController::class, 'destroy'])->middleware('can:ano-lectivo.eliminar')->name('eventos.destroy');
});
```

- [ ] **Step 3: Trocar as strings nos Controllers**

Em `AnoLectivoController.php`, trocar cada `$this->authorize('X', AnoLectivo::class)` (e remover o `use Modules\AnoLectivo\Models\AnoLectivo;` se deixar de ser usado noutro sítio do ficheiro — confirmar antes de remover, `AnoLectivo` continua a ser usado como type-hint dos métodos):

```php
    public function index()
    {
        $this->authorize('ano-lectivo.ver');

        return Inertia::render('AnoLectivo/Index', [
            'anoLectivos' => $this->consulta->listar(),
        ]);
    }

    public function show(AnoLectivo $anoLectivo)
    {
        $this->authorize('ano-lectivo.ver');

        return Inertia::render('AnoLectivo/Show', [
            'anoLectivo' => $this->consulta->comRelacoes($anoLectivo),
        ]);
    }

    public function store(CriarAnoLectivoRequest $request)
    {
        $this->authorize('ano-lectivo.criar');

        $this->service->criar($request);

        return redirect()->back()->with('success', 'Ano Lectivo criado com sucesso.');
    }

    public function update(AtualizarAnoLectivoRequest $request, AnoLectivo $anoLectivo)
    {
        $this->authorize('ano-lectivo.editar');

        $this->service->atualizar($anoLectivo, $request);

        return redirect()->back()->with('success', 'Ano Lectivo atualizado com sucesso.');
    }

    public function alterarEstado(AlterarEstadoAnoLectivoRequest $request, AnoLectivo $anoLectivo)
    {
        $this->authorize('ano-lectivo.editar');

        $this->service->alterarEstado($anoLectivo, EstadoAnoLectivo::from((int) $request->validated('estado')));

        return redirect()->back()->with('success', 'Estado do Ano Lectivo atualizado com sucesso.');
    }

    public function destroy(AnoLectivo $anoLectivo)
    {
        $this->authorize('ano-lectivo.eliminar');

        $this->service->eliminar($anoLectivo);

        return redirect()->back()->with('success', 'Ano Lectivo eliminado com sucesso.');
    }
```

`PeriodoController.php` completo:

```php
<?php

namespace Modules\AnoLectivo\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AnoLectivo\Http\Requests\AtualizarPeriodoRequest;
use Modules\AnoLectivo\Http\Requests\CriarPeriodoRequest;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\AnoLectivo\Models\Periodo;
use Modules\AnoLectivo\Services\GestaoAnoLectivoService;

class PeriodoController extends Controller
{
    public function __construct(
        private GestaoAnoLectivoService $service,
    ) {}

    public function store(CriarPeriodoRequest $request, AnoLectivo $anoLectivo)
    {
        $this->authorize('ano-lectivo.criar');

        $this->service->criarPeriodo($anoLectivo, $request);

        return redirect()->back()->with('success', 'Período criado com sucesso.');
    }

    public function update(AtualizarPeriodoRequest $request, Periodo $periodo)
    {
        $this->authorize('ano-lectivo.editar');

        $this->service->atualizarPeriodo($periodo, $request);

        return redirect()->back()->with('success', 'Período atualizado com sucesso.');
    }

    public function destroy(Periodo $periodo)
    {
        $this->authorize('ano-lectivo.eliminar');

        $this->service->eliminarPeriodo($periodo);

        return redirect()->back()->with('success', 'Período eliminado com sucesso.');
    }
}
```

`EventoCalendarioController.php` completo:

```php
<?php

namespace Modules\AnoLectivo\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AnoLectivo\Http\Requests\AtualizarEventoCalendarioRequest;
use Modules\AnoLectivo\Http\Requests\CriarEventoCalendarioRequest;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\AnoLectivo\Models\EventoCalendario;
use Modules\AnoLectivo\Services\GestaoAnoLectivoService;

class EventoCalendarioController extends Controller
{
    public function __construct(
        private GestaoAnoLectivoService $service,
    ) {}

    public function store(CriarEventoCalendarioRequest $request, AnoLectivo $anoLectivo)
    {
        $this->authorize('ano-lectivo.criar');

        $this->service->criarEventoCalendario($anoLectivo, $request);

        return redirect()->back()->with('success', 'Evento de calendário criado com sucesso.');
    }

    public function update(AtualizarEventoCalendarioRequest $request, EventoCalendario $evento)
    {
        $this->authorize('ano-lectivo.editar');

        $this->service->atualizarEventoCalendario($evento, $request);

        return redirect()->back()->with('success', 'Evento de calendário atualizado com sucesso.');
    }

    public function destroy(EventoCalendario $evento)
    {
        $this->authorize('ano-lectivo.eliminar');

        $this->service->eliminarEventoCalendario($evento);

        return redirect()->back()->with('success', 'Evento de calendário eliminado com sucesso.');
    }
}
```

Em ambos, os `use` de `Periodo`/`EventoCalendario` ficam — continuam a ser usados como type-hint dos parâmetros dos métodos.

- [ ] **Step 4: Trocar as strings nas 7 FormRequests**

Em cada um dos 7 ficheiros, trocar `return $this->user()?->can('gerir-ano-letivo') ?? false;` pela acção certa:
- `CriarAnoLectivoRequest.php`, `CriarPeriodoRequest.php`, `CriarEventoCalendarioRequest.php` → `'ano-lectivo.criar'`
- `AtualizarAnoLectivoRequest.php`, `AtualizarPeriodoRequest.php`, `AtualizarEventoCalendarioRequest.php`, `AlterarEstadoAnoLectivoRequest.php` → `'ano-lectivo.editar'`

Exemplo (`CriarAnoLectivoRequest.php`):

```php
    public function authorize(): bool
    {
        return $this->user()?->can('ano-lectivo.criar') ?? false;
    }
```

- [ ] **Step 5: Eliminar as 3 Policies**

```bash
rm Modules/AnoLectivo/app/Policies/AnoLectivoPolicy.php Modules/AnoLectivo/app/Policies/PeriodoPolicy.php Modules/AnoLectivo/app/Policies/EventoCalendarioPolicy.php
```

- [ ] **Step 6: Actualizar `AnoLectivoAutorizacaoTest.php` para as novas strings**

```php
<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class AnoLectivoAutorizacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissaoDatabaseSeeder::class);
    }

    public function test_admin_escola_tem_permissao_em_ano_lectivo(): void
    {
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);

        foreach (['ver', 'criar', 'editar', 'eliminar'] as $acao) {
            $this->assertTrue(Gate::forUser($staff)->allows("ano-lectivo.{$acao}"), "ano-lectivo.{$acao}");
        }
    }

    public function test_professor_nao_tem_permissao_em_ano_lectivo(): void
    {
        $professor = User::create(['name' => 'Professor', 'email' => 'professor@example.com', 'password' => Hash::make('x')]);
        $professor->roles()->syncWithoutDetaching([Role::where('nome', Perfil::PROFESSOR->value)->first()->id]);

        $this->assertFalse(Gate::forUser($professor)->allows('ano-lectivo.ver'));
    }
}
```

- [ ] **Step 7: Actualizar todos os outros ficheiros de teste de AnoLectivo identificados no Step 1**

Em cada um, trocar `$this->seed(RoleSeeder::class);` por `$this->seed(\Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder::class);` (e remover o `use Modules\Permissao\Database\Seeders\RoleSeeder;` se deixar de ser usado no resto do ficheiro). Não mudar mais nada nesses ficheiros — `PermissaoDatabaseSeeder` já semeia tudo o que `RoleSeeder` semeava, mais `Modulo`/`Acao`/`RolePermissao`.

- [ ] **Step 8: Correr toda a suite do módulo AnoLectivo**

Run: `php artisan test --testsuite="Modules Feature","Modules Unit" --filter=AnoLectivo`
Expected: PASS em todos (os 60 já existentes + o de autorização actualizado)

- [ ] **Step 9: Verificação manual rápida em browser** (Admin Escola continua a conseguir usar `/ano-lectivos` tal como antes da migração)

Run a app localmente, login como um utilizador ADMIN_ESCOLA, visitar `/ano-lectivos`, confirmar que a lista carrega e que criar/editar/eliminar continuam a funcionar exactamente como antes desta tarefa.

- [ ] **Step 10: Stage**

```bash
git add Modules/AnoLectivo/routes/web.php Modules/AnoLectivo/app/Http/Controllers/AnoLectivoController.php Modules/AnoLectivo/app/Http/Controllers/PeriodoController.php Modules/AnoLectivo/app/Http/Controllers/EventoCalendarioController.php Modules/AnoLectivo/app/Http/Requests/CriarAnoLectivoRequest.php Modules/AnoLectivo/app/Http/Requests/AtualizarAnoLectivoRequest.php Modules/AnoLectivo/app/Http/Requests/AlterarEstadoAnoLectivoRequest.php Modules/AnoLectivo/app/Http/Requests/CriarPeriodoRequest.php Modules/AnoLectivo/app/Http/Requests/AtualizarPeriodoRequest.php Modules/AnoLectivo/app/Http/Requests/CriarEventoCalendarioRequest.php Modules/AnoLectivo/app/Http/Requests/AtualizarEventoCalendarioRequest.php Modules/AnoLectivo/tests/
git rm Modules/AnoLectivo/app/Policies/AnoLectivoPolicy.php Modules/AnoLectivo/app/Policies/PeriodoPolicy.php Modules/AnoLectivo/app/Policies/EventoCalendarioPolicy.php
```

---

### Task 8: Migrar Estabelecimento para `estabelecimento.*`

**Files:**
- Modify: `Modules/Estabelecimento/routes/web.php`
- Modify: `Modules/Estabelecimento/app/Http/Controllers/EstabelecimentoController.php`
- Modify: `Modules/Estabelecimento/app/Http/Requests/AtualizarDadosRequest.php`
- Modify: `Modules/Estabelecimento/app/Http/Requests/AtualizarLogotipoRequest.php`
- Delete: `Modules/Estabelecimento/app/Policies/EstabelecimentoPolicy.php`
- Create: `Modules/Estabelecimento/tests/Feature/EstabelecimentoAutorizacaoTest.php`
- Modify: qualquer teste existente de Estabelecimento que faça `$this->seed(RoleSeeder::class)` (confirmar com grep, igual à Tarefa 7 Step 1)

**Interfaces:**
- Consumes: `Gate::before` (Tarefa 5), `RolePermissaoSeeder` (Tarefa 6, já dá a ADMIN_ESCOLA `estabelecimento.ver`/`estabelecimento.editar`).
- Produces: nada consumido por outras tarefas.

- [ ] **Step 1: Confirmar testes existentes que dependem de `RoleSeeder`**

Run: `grep -rl "RoleSeeder::class" Modules/Estabelecimento/tests/`

- [ ] **Step 2: Trocar as rotas**

```php
<?php

use Illuminate\Support\Facades\Route;
use Modules\Estabelecimento\Http\Controllers\EstabelecimentoController;

Route::middleware(['auth'])->prefix('estabelecimento')->name('estabelecimento.')->group(function () {
    Route::get('/', [EstabelecimentoController::class, 'dados'])->middleware('can:estabelecimento.ver')->name('dados');
    Route::put('/', [EstabelecimentoController::class, 'update'])->middleware('can:estabelecimento.editar')->name('update');
    Route::get('/aparencia', [EstabelecimentoController::class, 'aparencia'])->middleware('can:estabelecimento.ver')->name('aparencia');
    Route::post('/logotipo', [EstabelecimentoController::class, 'updateLogotipo'])->middleware('can:estabelecimento.editar')->name('logotipo.update');
});
```

- [ ] **Step 3: Trocar as strings no Controller**

```php
    public function dados()
    {
        $this->authorize('estabelecimento.ver');

        return Inertia::render('Estabelecimento/DadosDaEscola', [
            'estabelecimento' => $this->service->obterAtual(),
        ]);
    }

    public function aparencia()
    {
        $this->authorize('estabelecimento.ver');

        return Inertia::render('Estabelecimento/Aparencia', [
            'estabelecimento' => $this->service->obterAtual(),
        ]);
    }

    public function update(AtualizarDadosRequest $request)
    {
        $this->authorize('estabelecimento.editar');

        $this->service->atualizarDados($request);

        return redirect()->back()->with('success', 'Dados do estabelecimento atualizados com sucesso.');
    }

    public function updateLogotipo(AtualizarLogotipoRequest $request)
    {
        $this->authorize('estabelecimento.editar');

        $this->service->atualizarLogotipo($request->file('logotipo'));

        return redirect()->back()->with('success', 'Logótipo atualizado com sucesso.');
    }
```

`Estabelecimento::class` só aparecia nos 4 `authorize()` acima — depois desta troca deixa de ser usado no ficheiro. Remover a linha `use Modules\Estabelecimento\Models\Estabelecimento;` do topo do ficheiro.

- [ ] **Step 4: Trocar as strings nas 2 FormRequests**

Ambas passam a `'estabelecimento.editar'`:

```php
    public function authorize(): bool
    {
        return $this->user()?->can('estabelecimento.editar') ?? false;
    }
```

- [ ] **Step 5: Eliminar a Policy**

```bash
rm Modules/Estabelecimento/app/Policies/EstabelecimentoPolicy.php
```

- [ ] **Step 6: Escrever `EstabelecimentoAutorizacaoTest.php`**

```php
<?php

namespace Modules\Estabelecimento\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class EstabelecimentoAutorizacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    public function test_admin_escola_ve_e_edita_estabelecimento(): void
    {
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);

        $this->assertTrue(Gate::forUser($staff)->allows('estabelecimento.ver'));
        $this->assertTrue(Gate::forUser($staff)->allows('estabelecimento.editar'));
    }

    public function test_professor_nao_ve_estabelecimento(): void
    {
        $professor = User::create(['name' => 'Professor', 'email' => 'professor@example.com', 'password' => Hash::make('x')]);
        $professor->roles()->syncWithoutDetaching([Role::where('nome', Perfil::PROFESSOR->value)->first()->id]);

        $this->assertFalse(Gate::forUser($professor)->allows('estabelecimento.ver'));
    }
}
```

- [ ] **Step 7: Actualizar testes existentes identificados no Step 1** (mesma troca `RoleSeeder::class` → `PermissaoDatabaseSeeder::class` da Tarefa 7 Step 7)

- [ ] **Step 8: Correr toda a suite do módulo**

Run: `php artisan test --testsuite="Modules Feature","Modules Unit" --filter=Estabelecimento`
Expected: PASS em todos

- [ ] **Step 9: Verificação manual em browser** (Admin Escola continua a ver/editar Dados da Escola e Logótipo/Aparência)

- [ ] **Step 10: Stage**

```bash
git add Modules/Estabelecimento/routes/web.php Modules/Estabelecimento/app/Http/Controllers/EstabelecimentoController.php Modules/Estabelecimento/app/Http/Requests/AtualizarDadosRequest.php Modules/Estabelecimento/app/Http/Requests/AtualizarLogotipoRequest.php Modules/Estabelecimento/tests/
git rm Modules/Estabelecimento/app/Policies/EstabelecimentoPolicy.php
```

---

### Task 9: Migrar Core/Horario para `horario.*`

**Files:**
- Modify: `Modules/Core/routes/web.php`
- Modify: `Modules/Core/app/Http/Controllers/Horario/HorarioController.php`
- Modify: `Modules/Core/app/Http/Requests/Horario/CriarHorarioRequest.php`
- Modify: `Modules/Core/app/Http/Requests/Horario/AtualizarHorarioRequest.php`
- Delete: `Modules/Core/app/Policies/HorarioPolicy.php`
- Modify: `Modules/Core/tests/Feature/Horario/HorarioHttpTest.php`
- Modify: `Modules/Core/tests/Feature/Horario/HorarioModelTest.php`, `Modules/Core/tests/Feature/Horario/HorarioActionsTest.php` (se algum semear `RoleSeeder` directamente — confirmar com grep)

**Interfaces:**
- Consumes: `Gate::before` (Tarefa 5), `RolePermissaoSeeder` (Tarefa 6, dá a ADMIN_ESCOLA `horario.{ver,criar,editar,eliminar}`).
- Produces: nada consumido por outras tarefas. Nota: como `horario.*` deixa de partilhar `gerir-estabelecimento` com o módulo Estabelecimento (Tarefa 8), esta tarefa só pode ser considerada correcta depois da Tarefa 6 ter mesmo semeado `horario.*` separadamente — confirmar que a Tarefa 6 já correu antes de iniciar esta.

- [ ] **Step 1: Confirmar testes existentes que dependem de `RoleSeeder`**

Run: `grep -rl "RoleSeeder::class" Modules/Core/tests/`

- [ ] **Step 2: Trocar as rotas**

```php
<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\Horario\HorarioController;

Route::middleware(['auth'])->prefix('horarios')->name('horarios.')->group(function () {
    Route::get('/', [HorarioController::class, 'index'])->middleware('can:horario.ver')->name('index');
    Route::post('/', [HorarioController::class, 'store'])->middleware('can:horario.criar')->name('store');
    Route::put('/{horario}', [HorarioController::class, 'update'])->middleware('can:horario.editar')->name('update');
    Route::delete('/{horario}', [HorarioController::class, 'destroy'])->middleware('can:horario.eliminar')->name('destroy');
});
```

- [ ] **Step 3: Trocar as strings no Controller**

```php
    public function index()
    {
        $this->authorize('horario.ver');

        return Inertia::render('Core/Horario/Index', [
            'horarios' => $this->consulta->listar(),
        ]);
    }

    public function store(CriarHorarioRequest $request)
    {
        $this->authorize('horario.criar');

        $this->service->criar($request);

        return redirect()->back()->with('success', 'Horário criado com sucesso.');
    }

    public function update(AtualizarHorarioRequest $request, Horario $horario)
    {
        $this->authorize('horario.editar');

        $this->service->atualizar($horario, $request);

        return redirect()->back()->with('success', 'Horário atualizado com sucesso.');
    }

    public function destroy(Horario $horario)
    {
        $this->authorize('horario.eliminar');

        $this->service->eliminar($horario);

        return redirect()->back()->with('success', 'Horário eliminado com sucesso.');
    }
```

- [ ] **Step 4: Trocar as strings nas 2 FormRequests**

`CriarHorarioRequest.php`:

```php
    public function authorize(): bool
    {
        return $this->user()?->can('horario.criar') ?? false;
    }
```

`AtualizarHorarioRequest.php`:

```php
    public function authorize(): bool
    {
        return $this->user()?->can('horario.editar') ?? false;
    }
```

- [ ] **Step 5: Eliminar a Policy**

```bash
rm Modules/Core/app/Policies/HorarioPolicy.php
```

- [ ] **Step 6: Actualizar `HorarioHttpTest.php` para as novas strings e seeder**

Trocar `$this->seed(RoleSeeder::class);` (ou equivalente) por `$this->seed(\Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder::class);` no `setUp()`. O teste `test_utilizador_sem_gerir_estabelecimento_recebe_403` passa a chamar-se e a comentar `horario.*` em vez de `gerir-estabelecimento` (o comportamento HTTP esperado — 403 — não muda, só o nome do teste e o comentário deixam de mencionar a gate antiga).

- [ ] **Step 7: Correr toda a suite do módulo Core**

Run: `php artisan test --testsuite="Modules Feature","Modules Unit" --filter=Core`
Expected: PASS em todos os 10 testes de Horario + qualquer outro teste de Core

- [ ] **Step 8: Verificação manual em browser** (Admin Escola continua a gerir Horários em `/horarios`)

- [ ] **Step 9: Stage**

```bash
git add Modules/Core/routes/web.php Modules/Core/app/Http/Controllers/Horario/HorarioController.php Modules/Core/app/Http/Requests/Horario/CriarHorarioRequest.php Modules/Core/app/Http/Requests/Horario/AtualizarHorarioRequest.php Modules/Core/tests/Feature/Horario/
git rm Modules/Core/app/Policies/HorarioPolicy.php
```

---

### Task 10: Teste de "fiação" — todas as rotas `can:modulo.acao` resolvem

**Files:**
- Create: `tests/Feature/RotasPermissaoReconhecidaTest.php`

**Interfaces:**
- Consumes: `Modules\Permissao\Services\PermissionResolver::reconhece()` (Tarefa 3), rotas já migradas nas Tarefas 7-9.
- Produces: nada consumido por outras tarefas — é uma rede de segurança de CI.

- [ ] **Step 1: Escrever o teste**

```php
<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Modules\Permissao\Services\PermissionResolver;
use Tests\TestCase;

class RotasPermissaoReconhecidaTest extends TestCase
{
    public function test_todas_as_rotas_can_modulo_acao_resolvem_para_um_modulo_e_uma_acao_reais(): void
    {
        $resolver = app(PermissionResolver::class);
        $naoReconhecidas = [];

        foreach (Route::getRoutes() as $rota) {
            foreach ($rota->middleware() as $middleware) {
                if (!str_starts_with($middleware, 'can:')) {
                    continue;
                }

                $ability = substr($middleware, strlen('can:'));

                if (!str_contains($ability, '.')) {
                    continue; // ability antiga sem ponto (ex: 'gerir-permissoes'), fora de âmbito deste teste
                }

                if (!$resolver->reconhece($ability)) {
                    $naoReconhecidas[] = "{$rota->uri()} => {$ability}";
                }
            }
        }

        $this->assertEmpty($naoReconhecidas, "Rotas com ability modulo.acao não reconhecida:\n" . implode("\n", $naoReconhecidas));
    }
}
```

- [ ] **Step 2: Correr e confirmar que passa**

Run: `php artisan test --filter=RotasPermissaoReconhecidaTest`
Expected: PASS — todas as rotas `can:ano-lectivo.*`, `can:estabelecimento.*`, `can:horario.*` reconhecidas (confirma as Tarefas 7-9 sem erros de digitação)

**Se falhar:** a mensagem do teste diz exactamente qual rota e qual string está errada — corrigir a rota (não o teste) e correr de novo.

- [ ] **Step 3: Stage**

```bash
git add tests/Feature/RotasPermissaoReconhecidaTest.php
```

---

### Task 11: Remover as gates fixas antigas do `AppServiceProvider`

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`

**Interfaces:**
- Consumes: confirmação das Tarefas 7-9 (nenhum código na aplicação usa `gerir-ano-letivo`/`gerir-estabelecimento` — ver Step 1).
- Produces: nada consumido por outras tarefas — é o fecho da migração.

- [ ] **Step 1: Confirmar que nada mais usa as duas gates a remover**

Run: `grep -rn "gerir-ano-letivo\|gerir-estabelecimento" --include="*.php" Modules app`
Expected: nenhum resultado (se aparecer algo, essa Tarefa 7/8/9 ficou incompleta — voltar lá antes de continuar)

- [ ] **Step 2: Remover os dois `Gate::define`**

Em `app/Providers/AppServiceProvider.php`, `boot()` passa a:

```php
    public function boot(): void
    {
        Gate::define('gerir-permissoes', function (User $user) {
            return $user->roles->contains('nome', Perfil::ADMIN_ESCOLA->value);
        });

        Gate::define('gerir-usuarios', function (User $user) {
            return $user->roles->contains('nome', Perfil::ADMIN_ESCOLA->value);
        });

        RateLimiter::for('api', function ($request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('login', function ($request) {
            return Limit::perMinute(5)->by($request->ip());
        });
    }
```

(remove os blocos `Gate::define('gerir-estabelecimento', ...)` e `Gate::define('gerir-ano-letivo', ...)`; mantém os outros dois e os rate limiters tal como estão.)

- [ ] **Step 3: Correr toda a suite da aplicação**

Run: `php artisan test --testsuite="Unit","Feature","Modules Unit","Modules Feature"`
Expected: PASS em tudo — se algo depender ainda das gates removidas, falha aqui e é preciso voltar à Tarefa 7/8/9 correspondente

- [ ] **Step 4: Stage**

```bash
git add app/Providers/AppServiceProvider.php
```

---

### Task 12: Entrega ao frontend — `permissoes` partilhado + `can()` em Vue

**Files:**
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Create: `resources/js/Composables/usePermissoes.js`
- Test: `tests/Feature/PermissoesCompartilhadasInertiaTest.php`

**Interfaces:**
- Consumes: `Modules\Permissao\Services\PermissionResolver::conjuntoConcedido()` (Tarefa 3).
- Produces: prop Inertia `permissoes: string[]` em toda a página; `can(permissao: string): boolean` importável de `@/Composables/usePermissoes.js`, para uso em qualquer módulo (Vue templates e ficheiros de menu).

- [ ] **Step 1: Escrever o teste falhado**

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class PermissoesCompartilhadasInertiaTest extends TestCase
{
    use RefreshDatabase;

    public function test_utilizador_autenticado_recebe_o_conjunto_de_permissoes_partilhado(): void
    {
        $this->seed(PermissaoDatabaseSeeder::class);
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => Hash::make('x')]);
        $admin->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id);

        $response = $this->actingAs($admin)->get('/');

        // NOTA (correcção pós-implementação): Illuminate\Testing\Fluent\Concerns\Matching::where()
        // embrulha automaticamente o array em Illuminate\Support\Collection antes de o passar
        // ao closure — in_array() não aceita Collection como 2º argumento (TypeError). Usa-se
        // ->contains() (API nativa de Collection) em vez de in_array().
        $response->assertInertia(fn ($page) => $page
            ->has('permissoes')
            ->where('permissoes', fn ($permissoes) => $permissoes->contains('ano-lectivo.ver')));
    }

    public function test_visitante_nao_autenticado_recebe_lista_vazia(): void
    {
        $response = $this->get('/login');

        $response->assertInertia(fn ($page) => $page->where('permissoes', []));
    }
}
```

- [ ] **Step 2: Correr e confirmar que falha**

Run: `php artisan test --filter=PermissoesCompartilhadasInertiaTest`
Expected: FAIL — a prop `permissoes` ainda não existe na partilha Inertia

- [ ] **Step 3: Adicionar a partilha em `HandleInertiaRequests`**

```php
<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use Modules\Permissao\Services\PermissionResolver;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'layouts.app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'permissoes' => $request->user()
                ? app(PermissionResolver::class)->conjuntoConcedido($request->user())
                : [],
        ];
    }
}
```

- [ ] **Step 4: Correr e confirmar que passa**

Run: `php artisan test --filter=PermissoesCompartilhadasInertiaTest`
Expected: PASS (2 testes)

- [ ] **Step 5: Criar o composable JS**

```js
import { usePage } from '@inertiajs/vue3';

export function can(permissao) {
    return usePage().props.permissoes.includes(permissao);
}
```

- [ ] **Step 6: Confirmar que o build do frontend continua limpo**

Run: `npm run build`
Expected: build sem erros (o composable não é ainda importado em lado nenhum, mas tem de compilar sozinho sem erros de sintaxe)

- [ ] **Step 7: Verificação manual em browser** — usar o composable num ponto real para provar que funciona de ponta a ponta antes de o dar como pronto para os outros módulos usarem

No ficheiro `resources/js/Components/Layout/menus/PedagogicoMenu.vue`, envolver a entrada "Horários" (já ligada a `/horarios` desde a sessão anterior) com uma verificação real, só para prova de conceito manual — **não deixar este ficheiro assim no fim do build final da tarefa**, é só para o teste manual do Step 8; reverter no Step 8 antes de fazer stage:

```html
<div v-for="link in section.links" :key="link.href" class="menu-item p-0 m-0">
    <a v-if="link.href !== '/horarios' || can('horario.ver')" :href="link.href" class="menu-link">
        <span class="menu-title">{{ link.label }}</span>
    </a>
</div>
```
com `import { can } from '@/Composables/usePermissoes.js';` no `<script setup>`. Fazer login como Admin Escola (vê o link) e como Professor (não vê) para confirmar visualmente, depois de correr `npm run build`.

- [ ] **Step 8: Reverter o `PedagogicoMenu.vue` ao estado anterior a este teste manual**

```bash
git checkout -- resources/js/Components/Layout/menus/PedagogicoMenu.vue
```

(A integração real do `can()` em cada menu/página fica para quando cada módulo migrar — esta tarefa só entrega o mecanismo, comprovado a funcionar; não é âmbito desta tarefa decidir onde cada botão existente passa a usar `can()`.)

- [ ] **Step 9: Stage**

```bash
git add app/Http/Middleware/HandleInertiaRequests.php resources/js/Composables/usePermissoes.js tests/Feature/PermissoesCompartilhadasInertiaTest.php
```

---

### Task 13: Verificação manual final em browser (paridade completa)

**Files:** nenhum ficheiro novo — só verificação.

**Interfaces:** consome tudo o que já está construído (Tarefas 1-12).

- [ ] **Step 1: Correr a suite completa uma última vez**

Run: `php artisan test --testsuite="Unit","Feature","Modules Unit","Modules Feature"`
Expected: PASS em tudo, zero regressões

- [ ] **Step 2: Login como Admin Escola, percorrer os 3 módulos migrados**

- `/ano-lectivos`: listar, criar, editar, alterar estado, eliminar — tudo a funcionar como antes da migração.
- `/estabelecimento` e `/estabelecimento/aparencia`: ver e editar dados/logótipo.
- `/horarios`: listar, criar, editar, eliminar.

- [ ] **Step 3: Login como um utilizador com perfil Professor (sem nenhuma das novas permissões), confirmar 403 real nos 3 módulos**

Visitar `/ano-lectivos`, `/estabelecimento`, `/horarios` directamente pelo URL — os três devem devolver 403 (backend), não uma página em branco nem um erro 500.

- [ ] **Step 4: Confirmar que a cache de permissões reflecte uma mudança feita ao vivo**

Via `php artisan tinker`, atribuir `horario.ver` ao perfil Professor através do fluxo real (`app(\Modules\Permissao\Actions\SincronizarPermissoesPerfilAction::class)->executar(...)`, ou pelo ecrã de gestão de permissões se preferires testar pela UI), sem reiniciar a app nem limpar cache manualmente — visitar `/horarios` como Professor outra vez no mesmo browser e confirmar que agora entra (prova que a invalidação da Tarefa 4 funciona em condições reais, não só nos testes).

- [ ] **Step 5: Reverter a alteração de teste do Step 4** (não deixar o Professor com acesso real a Horários depois da verificação)

Via o mesmo mecanismo, remover a permissão atribuída no Step 4.

Nenhum commit nesta tarefa — é só verificação.
