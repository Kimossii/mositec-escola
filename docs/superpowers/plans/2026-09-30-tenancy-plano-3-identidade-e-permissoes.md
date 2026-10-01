# Tenancy — Plano 3: Identidade, Permissões e Autenticação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Isolar por tenant a identidade (utilizadores, dados pessoais, documentos, tipos de documento, encarregados), as permissões (perfis e permissões de perfil e de utilizador), a autenticação web e API (login, sessão, tokens Sanctum, recuperação de palavra-passe, limitador de login) e a cache de permissões.

**Architecture:** As tabelas de identidade e permissões ganham `tenant_id` (migrations originais editadas, BD recriada) e os models passam a usar `PertenceAoTenant`. O `User` passa a ser pesquisado só dentro do tenant corrente, por isso o provider Eloquent, o Sanctum e o broker de palavras-passe ficam isolados sem alterar os controllers. Três peças novas e pequenas fecham as vias que não passam pelo Eloquent: `CacheTenant` (Core), um repositório de tokens de recuperação que filtra por tenant, e um middleware que confere o tenant da sessão. A conversão é feita por camadas, com a suite verde no fim de cada tarefa.

**Tech Stack:** PHP 8.2, Laravel 12, Laravel Fortify, Laravel Sanctum, nwidart/laravel-modules 13, PHPUnit 11, SQLite em memória nos testes, PostgreSQL em desenvolvimento.

**Spec:** `docs/superpowers/specs/2026-09-30-fundacao-tenancy-design.md` — este plano implementa a etapa 5 de §20.2 (§7.3, §10, §11, §15, §17.2 linhas `users.*`, `dados_pessoas.*`, `tipos_documentos.slug`, `password_reset_tokens.*`, §17.3 linhas Usuario, Autenticacao e Permissao, §19.2 linhas "Cache" e "Autenticação", §22 pontos 1 e 3). Depende dos Planos 1 e 2 (etapas 1 a 4), já concluídos.

**Fora deste plano (não tocar):** `matricula_sequencias` e o gerador de matrícula (etapa 7: o gerador continua global até lá; só `users.numero_matricula` passa a único por tenant), `alunos.numero_matricula` e as restantes tabelas académicas (etapa 6), caminho dos documentos de pessoa `tenants/{id}/…` (etapa 8), provisionador de utilizador administrador e de perfis (etapa 9), `ComTenant` para Jobs e Commands (etapa 11). As tabelas `modulos`, `acoes` e `licencas` continuam globais.

## Global Constraints

- **Nunca fazer commit.** O dono do repositório faz os commits. Cada tarefa termina com `git add` dos ficheiros tocados, nada mais.
- **Nunca escrever na BD de desenvolvimento por tinker ou SQL.** Só migrations, seeders e testes. `migrate:fresh --seed` só com confirmação do utilizador (Tarefa 8).
- Fail-closed: sem tenant resolvido, o código tenant-scoped lança `TenantNaoResolvido`. Nunca devolve resultados vazios nem todos os registos.
- `tenant_id` nunca entra em `$fillable` nem em DTOs. Vem sempre do contexto.
- `Modules/Core/app/Tenancy` não importa `Modules\Tenant\…`. Nenhum módulo fora de `Modules/Tenant` importa `Modules\Tenant\…` (os testes e `database/seeders/DatabaseSeeder.php` podem).
- Em `Modules/*/app`, `routes` e `database/seeders` não se usa `withoutGlobalScopes`, `DB::table(` (salvo as duas excepções dos geradores de sequência, que ficam como estão) nem a fachada `Cache`.
- Só o runtime de tenancy define ou limpa o `TenantContext` (teste de arquitectura existente): nenhum middleware ou serviço novo chama `definir()` ou `limpar()`.
- Escritas em massa (`Model::insert`, `upsert`) não passam pelos eventos do model: têm de trazer `tenant_id` do contexto explicitamente.
- Em PHP, importar as classes com `use` no topo; nunca nomes totalmente qualificados no meio do código.
- Colunas-enum inteiras têm sempre uma coluna irmã `*_descricao`, sincronizada num hook `saving` do model.
- Identificadores, mensagens e comentários em português.
- Comando de testes: `php artisan test`. A suite completa tem de passar no fim de cada tarefa (baseline: 780 passed, 1 incomplete).
- Nomes exactos: `CacheTenant`, `TokenRepositoryTenant`, `PasswordBrokerManagerTenant`, `TokenDeAcesso`, `VerificarTenantDaSessao`; chave de sessão `tenant_id`; chaves da cache `tenant:{id}:…`.

## Review Focus

Comportamentos que o spec implica e que mais facilmente ficam sem teste. Cada um tem o teste na tarefa indicada.

1. **Mesmo email, número de matrícula ou número de identificação em dois tenants** é permitido; duplicado dentro do mesmo tenant falha, na BD e na validação (`unique`) → Tarefa 3.
2. **Sessão reenviada ao domínio de outro tenant** não autentica, e uma sessão com `tenant_id` diferente do do pedido é invalidada → Tarefa 6.
3. **Token Sanctum emitido no tenant A** devolve 401 no domínio do tenant B, e a eliminação do utilizador não deixa tokens órfãos visíveis → Tarefa 4.
4. **Recuperação de palavra-passe com o mesmo email em A e B**: pedir reposição em A não apaga nem consome o token de B, e o token de A não repõe a palavra-passe de B → Tarefa 5.
5. **Limitador de login**: falhas de uma conta em A não bloqueiam a mesma conta em B; a **anti-perda de acesso** (`GarantirAdministradorEfetivoAction`) conta só administradores do tenant corrente; invalidar a cache de permissões em A não afecta B → Tarefas 1, 2 e 6.

## Mapa de ficheiros

| Ficheiro | Responsabilidade |
|---|---|
| `Modules/Core/app/Tenancy/CacheTenant.php` | Cache com prefixo `tenant:{id}:` |
| `Modules/Permissao/app/Support/PermissaoCache.php` | Passa a usar `CacheTenant` |
| `Modules/Permissao/app/Services/PermissionResolver.php` | Memória por tenant; ligação `scoped` |
| `Modules/Permissao/database/migrations/*` (4 ficheiros) | `tenant_id` em perfis e permissões |
| `Modules/Permissao/app/Models/*`, `Modules/Permissao/app/Actions/Sincronizar*` | Trait, pivots, escritas em massa |
| `Modules/Usuario/database/migrations/*` | `tenant_id`, únicos por tenant |
| `Modules/Usuario/app/Models/*` | Trait, pivots de encarregados |
| `app/Actions/Fortify/*`, `app/Models/User.php`, `database/factories/UserFactory.php` | Deixam de usar o `User` de boilerplate |
| `Modules/Autenticacao/app/Models/TokenDeAcesso.php` | Token Sanctum com trait |
| `Modules/Autenticacao/app/Passwords/TokenRepositoryTenant.php`, `PasswordBrokerManagerTenant.php` | Recuperação de palavra-passe isolada |
| `Modules/Autenticacao/app/Http/Middleware/VerificarTenantDaSessao.php` | Confere o tenant da sessão |
| `Modules/Autenticacao/app/Service/LimitadorLogin.php` | Chaves com tenant |
| `config/fortify.php`, `config/tenancy.php` | Registo público desactivado; lista de transição |
| `tests/Feature/Arquitectura/*`, `tests/Feature/Tenancy/IdentidadeIsolamentoTest.php` | Testes de arquitectura e matriz de isolamento |

---

### Task 1: CacheTenant, cache de permissões e resolver com âmbito de tenant

Sem esquema novo. Fecha o ponto de §11 e a linha "Cache" da matriz de §19.2.

**Files:**
- Create: `Modules/Core/app/Tenancy/CacheTenant.php`
- Modify: `Modules/Permissao/app/Support/PermissaoCache.php`
- Modify: `Modules/Permissao/app/Services/PermissionResolver.php`
- Modify: `Modules/Permissao/app/Providers/PermissaoServiceProvider.php`
- Modify: `tests/Feature/Arquitectura/TenancyArquitecturaTest.php`
- Test: `Modules/Core/tests/Unit/Tenancy/CacheTenantTest.php`, `Modules/Permissao/tests/Feature/PermissaoCacheTest.php`

**Interfaces:**
- Consumes: `TenantContext::id(): int` (lança `TenantNaoResolvido` sem contexto).
- Produces: `Modules\Core\Tenancy\CacheTenant` — `chave(string $chave): string`, `get(string $chave, mixed $padrao = null): mixed`, `put(string $chave, mixed $valor, DateTimeInterface|DateInterval|int|null $ttl = null): bool`, `forever(string $chave, mixed $valor): bool`, `forget(string $chave): bool`.

- [ ] **Step 1: Escrever os testes**

`Modules/Core/tests/Unit/Tenancy/CacheTenantTest.php`:

```php
<?php

namespace Modules\Core\Tests\Unit\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Tenancy\CacheTenant;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;
use Modules\Core\Tenancy\TenantContext;
use Tests\TestCase;

class CacheTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_prefixa_a_chave_com_o_tenant(): void
    {
        $this->assertSame("tenant:{$this->tenant->id}:x", app(CacheTenant::class)->chave('x'));
    }

    public function test_o_mesmo_nome_de_chave_nao_colide_entre_tenants(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $cache = app(CacheTenant::class);

        $cache->forever('valor', 'de-A');
        $this->noTenant($outro, fn () => app(CacheTenant::class)->forever('valor', 'de-B'));

        $this->assertSame('de-A', $cache->get('valor'));
        $this->assertSame('de-B', $this->noTenant($outro, fn () => app(CacheTenant::class)->get('valor')));
    }

    public function test_esquecer_num_tenant_nao_afecta_o_outro(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $cache = app(CacheTenant::class);

        $cache->forever('valor', 'de-A');
        $this->noTenant($outro, fn () => app(CacheTenant::class)->forever('valor', 'de-B'));

        $cache->forget('valor');

        $this->assertNull($cache->get('valor'));
        $this->assertSame('de-B', $this->noTenant($outro, fn () => app(CacheTenant::class)->get('valor')));
    }

    public function test_sem_contexto_lanca_tenant_nao_resolvido(): void
    {
        app(TenantContext::class)->limpar();

        $this->expectException(TenantNaoResolvido::class);

        app(CacheTenant::class)->get('valor');
    }
}
```

Acrescentar a `Modules/Permissao/tests/Feature/PermissaoCacheTest.php` (manter o que já existe; usar os `use` já presentes e acrescentar `PermissaoCache` se faltar):

```php
    public function test_invalidar_tudo_num_tenant_nao_afecta_a_versao_do_outro(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $cache = app(PermissaoCache::class);
        $chaveDeBAntes = $this->noTenant($outro, fn () => app(PermissaoCache::class)->chave(1));

        $cache->invalidarTudo();

        $this->assertSame('permissoes:v2:user:1', $cache->chave(1));
        $this->assertSame($chaveDeBAntes, $this->noTenant($outro, fn () => app(PermissaoCache::class)->chave(1)));
    }
```

- [ ] **Step 2: Correr para ver falhar**

Run: `php artisan test --filter='CacheTenantTest|PermissaoCacheTest'`
Expected: FAIL (`CacheTenant` inexistente; a chave de B muda quando A invalida).

- [ ] **Step 3: Escrever `CacheTenant`**

`Modules/Core/app/Tenancy/CacheTenant.php`:

```php
<?php

namespace Modules\Core\Tenancy;

use DateInterval;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Invólucro fino sobre a cache: todas as chaves levam o prefixo do tenant corrente.
 * Sem contexto lança TenantNaoResolvido. É o único caminho permitido para a cache nos módulos.
 */
class CacheTenant
{
    public function __construct(private TenantContext $contexto) {}

    public function chave(string $chave): string
    {
        return "tenant:{$this->contexto->id()}:{$chave}";
    }

    public function get(string $chave, mixed $padrao = null): mixed
    {
        return Cache::get($this->chave($chave), $padrao);
    }

    public function put(string $chave, mixed $valor, DateTimeInterface|DateInterval|int|null $ttl = null): bool
    {
        return Cache::put($this->chave($chave), $valor, $ttl);
    }

    public function forever(string $chave, mixed $valor): bool
    {
        return Cache::forever($this->chave($chave), $valor);
    }

    public function forget(string $chave): bool
    {
        return Cache::forget($this->chave($chave));
    }
}
```

- [ ] **Step 4: `PermissaoCache` passa a usar `CacheTenant`**

Em `Modules/Permissao/app/Support/PermissaoCache.php`: remover o `use Illuminate\Support\Facades\Cache;`, acrescentar `use Modules\Core\Tenancy\CacheTenant;`, adicionar o construtor e trocar cada `Cache::` por `$this->cache->`:

```php
    public function __construct(private readonly CacheTenant $cache) {}

    public function obter(int $userId): ?array
    {
        return $this->cache->get($this->chave($userId));
    }

    public function guardar(int $userId, array $conjunto): void
    {
        $this->cache->forever($this->chave($userId), $conjunto);
    }

    public function esquecerUtilizador(int $userId): void
    {
        $this->cache->forget($this->chave($userId));
        app(PermissionResolver::class)->esquecerUtilizador($userId);
    }

    public function invalidarTudo(): void
    {
        $this->cache->forever(self::CHAVE_EPOCH, $this->epoch() + 1);
        app(PermissionResolver::class)->esquecerTudo();
    }

    private function epoch(): int
    {
        return (int) $this->cache->get(self::CHAVE_EPOCH, 1);
    }
```

(`chave()` mantém-se igual.)

- [ ] **Step 5: `PermissionResolver` com memória por tenant e ligação `scoped`**

Em `PermissionResolver.php`: acrescentar `use Modules\Core\Tenancy\TenantContext;`, injectar o contexto e chavear a memória por tenant e utilizador:

```php
    public function __construct(
        private readonly PermissaoCache $cache,
        private readonly TenantContext $contexto,
    ) {}

    private function chaveMemoria(int $userId): string
    {
        return $this->contexto->id() . ':' . $userId;
    }
```

Substituir nos métodos `esquecerUtilizador` (`unset($this->memoria[$this->chaveMemoria($userId)])`) e `conjuntoConcedido` (`array_key_exists($this->chaveMemoria($user->id), …)`, leitura e escrita) o índice `$user->id`/`$userId` por essa chave. `esquecerTudo()` continua a esvaziar o array inteiro.

Em `PermissaoServiceProvider::register()`, trocar `singleton` por `scoped`:

```php
        $this->app->scoped(PermissionResolver::class);
```

Se algum teste existente instanciar `new PermissionResolver(...)` à mão, passar também `app(TenantContext::class)` como segundo argumento.

- [ ] **Step 6: Esvaziar a lista de excepções da cache**

Em `tests/Feature/Arquitectura/TenancyArquitecturaTest.php`, apagar a constante `EXCEPCOES_CACHE` (e o seu comentário) e ajustar o teste:

```php
        $violacoes = $this->ocorrencias('/\bCache::|\bcache\s*\(/', ['Modules/Core/app/Tenancy/']);
```

- [ ] **Step 7: Correr os testes e a suite**

Run: `php artisan test --filter='CacheTenantTest|PermissaoCache|PermissionResolver|TenancyArquitectura'` e depois `php artisan test`
Expected: PASS em tudo.

- [ ] **Step 8: Stage**

```bash
git add Modules/Core Modules/Permissao tests
```

---

### Task 2: Perfis e permissões (Permissao)

**Files:**
- Modify: `Modules/Permissao/database/migrations/2026_03_31_133053_create_roles_table.php`, `2026_03_31_133133_create_user_roles_table.php`, `2026_03_31_133643_create_user_permissoes_table.php`, `2026_04_06_142942_create_role_permissoes_table.php`
- Modify: `Modules/Permissao/app/Models/Role.php`, `RolePermissao.php`, `UserRole.php`, `UserPermissao.php`
- Modify: `Modules/Usuario/app/Models/User.php` (relação `roles()`)
- Modify: `Modules/Permissao/app/Actions/SincronizarPermissoesPerfilAction.php`, `SincronizarPermissoesUtilizadorAction.php`
- Modify: `config/tenancy.php`
- Test: `Modules/Permissao/tests/Feature/PermissaoTenancyTest.php`

**Interfaces:**
- Consumes: `PertenceAoTenant`, `TenantContext::id()`, helpers `criarTenant`/`noTenant` (Planos 1 e 2).
- Produces: `roles`, `role_permissoes`, `user_roles`, `user_permissoes` com `tenant_id NOT NULL` e FK para `tenants`; relações `User::roles()` e `Role::users()` com `withPivotValue('tenant_id', …)`.

- [ ] **Step 1: Escrever os testes**

`Modules/Permissao/tests/Feature/PermissaoTenancyTest.php`:

```php
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
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
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

        $this->assertSame(Role::count(), $this->noTenant($outro, fn () => Role::count()));
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
        $this->seed(PermissaoDatabaseSeeder::class);
        $this->assertGreaterThan(0, RolePermissao::count());

        $tenants = RolePermissao::pluck('tenant_id')->unique()->all();

        $this->assertSame([$this->tenant->id], $tenants);
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

    public function test_user_permissao_e_isolada_por_tenant(): void
    {
        $this->seed(PermissaoDatabaseSeeder::class);
        $user = $this->admin('a@example.com');

        $this->assertSame(0, UserPermissao::count());
        $this->assertSame(0, $this->noTenant($this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost'), fn () => UserPermissao::count()));
        $this->assertNotNull($user->id);
    }

    public function test_a_bd_rejeita_um_perfil_sem_tenant(): void
    {
        $this->expectException(QueryException::class);

        DB::table('roles')->insert(['nome' => 0, 'estado' => 1, 'estado_descricao' => 'Ativo']);
    }
}
```

Antes de escrever o teste da anti-perda de acesso, abrir `GarantirAdministradorEfetivoAction` e confirmar que, sem administradores no tenant corrente, o ramo que lança é o do tenant corrente (tal como o teste assume).

- [ ] **Step 2: Correr para ver falhar**

Run: `php artisan test --filter=PermissaoTenancyTest`
Expected: FAIL (colunas `tenant_id` inexistentes).

- [ ] **Step 3: Migrations**

Nas quatro migrations, acrescentar logo a seguir a `$table->id();`:

```php
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
```

Os índices únicos existentes (`(users_id, role_id)`, `(users_id, modulo_id, acao_id)`, `(role_id, modulo_id, acao_id)`) ficam como estão (spec §17.2).

- [ ] **Step 4: Models e pivots**

Em `Role`, `RolePermissao`, `UserRole` e `UserPermissao`: `use Modules\Core\Tenancy\PertenceAoTenant;` e `use PertenceAoTenant;` dentro da classe (nenhum leva `tenant_id` em `$fillable`).

Em `Role::users()` e em `User::roles()`, acrescentar o valor de pivot do tenant (importar `Modules\Core\Tenancy\TenantContext`):

```php
    public function users()
    {
        return $this->belongsToMany(User::class, 'user_roles', 'role_id', 'users_id')
            ->withPivotValue('tenant_id', app(TenantContext::class)->id());
    }
```
```php
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'users_id', 'role_id')
            ->withPivotValue('tenant_id', app(TenantContext::class)->id());
    }
```

Para o teste `->pivot->tenant_id` funcionar, acrescentar `->withPivot('tenant_id')` às duas relações.

- [ ] **Step 5: Escritas em massa**

Em `SincronizarPermissoesPerfilAction` (linha com `RolePermissao::insert($linhas)`) e em `SincronizarPermissoesUtilizadorAction` (`UserPermissao::insert($linhas)`), cada linha passa a incluir `'tenant_id' => app(TenantContext::class)->id()` (calcular o id uma vez antes do ciclo que monta `$linhas`; importar `Modules\Core\Tenancy\TenantContext`).

- [ ] **Step 6: Lista de transição**

Em `config/tenancy.php`, retirar de `tabelas_por_converter`: `role_permissoes`, `roles`, `user_permissoes`, `user_roles`.

- [ ] **Step 7: Correr os testes e a suite**

Run: `php artisan test --filter=PermissaoTenancyTest` e depois `php artisan test`
Expected: PASS em tudo. Falhas noutros testes são quase sempre um seeder ou uma criação de `Role`/`UserRole` fora de contexto: usar `noTenant(...)` ou o contexto por omissão. `GateBeforeTest` e `PermissionResolverTest` podem precisar do ajuste do construtor da Tarefa 1.

- [ ] **Step 8: Stage**

```bash
git add Modules/Permissao Modules/Usuario/app/Models/User.php config/tenancy.php tests
```

---

### Task 3: Utilizadores, dados pessoais e documentos (Usuario)

**Files:**
- Modify: `Modules/Usuario/database/migrations/2026_03_31_103946_create_dados_pessoas_table.php`, `2026_03_31_104100_create_users_table.php` (só `users`), `2026_08_25_000002_create_encarregados_alunos_table.php`, `2026_09_16_150000_create_tipos_documentos_table.php`, `2026_09_16_150100_create_documentos_pessoas_table.php`
- Modify: `Modules/Usuario/app/Models/User.php`, `DadosPessoa.php`, `DocumentoPessoa.php`, `TipoDocumento.php`, `EncarregadoAluno.php`
- Modify: `app/Actions/Fortify/CreateNewUser.php`, `ResetUserPassword.php`, `UpdateUserPassword.php`, `UpdateUserProfileInformation.php`
- Delete: `app/Models/User.php`, `database/factories/UserFactory.php`
- Modify: `config/tenancy.php`
- Test: `Modules/Usuario/tests/Feature/UsuarioTenancyTest.php`

**Interfaces:**
- Consumes: `PertenceAoTenant`, helpers de teste, `User::roles()` (Tarefa 2).
- Produces: `users`, `dados_pessoas`, `documentos_pessoas`, `tipos_documentos`, `encarregados_alunos` com `tenant_id NOT NULL`; únicos `users (tenant_id, email)`, `users (tenant_id, numero_matricula)`, `dados_pessoas (tenant_id, numero_identificacao)`, `tipos_documentos (tenant_id, slug)`; relações `User::educandos()` e `User::encarregados()` com valor de pivot do tenant.

- [ ] **Step 1: Escrever os testes**

`Modules/Usuario/tests/Feature/UsuarioTenancyTest.php`:

```php
<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Modules\Core\Tenancy\Exceptions\AlteracaoDeTenantProibida;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\TipoDocumento;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class UsuarioTenancyTest extends TestCase
{
    use RefreshDatabase;

    private function utilizador(string $email, ?string $matricula = null): User
    {
        return User::create(['name' => 'U', 'email' => $email, 'numero_matricula' => $matricula, 'password' => Hash::make('x')]);
    }

    public function test_o_mesmo_email_pode_existir_em_dois_tenants(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        $this->utilizador('igual@example.com');
        $this->noTenant($outro, fn () => $this->utilizador('igual@example.com'));

        $this->assertSame(1, User::where('email', 'igual@example.com')->count());
    }

    public function test_email_duplicado_no_mesmo_tenant_falha_na_bd(): void
    {
        $this->utilizador('igual@example.com');

        $this->expectException(QueryException::class);

        $this->utilizador('igual@example.com');
    }

    public function test_o_mesmo_numero_de_matricula_pode_existir_em_dois_tenants(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        $this->utilizador('a@example.com', '2026-0001');
        $this->noTenant($outro, fn () => $this->utilizador('b@example.com', '2026-0001'));

        $this->assertSame(1, User::where('numero_matricula', '2026-0001')->count());
    }

    public function test_o_mesmo_numero_de_identificacao_pode_existir_em_dois_tenants(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $dados = ['nome_completo' => 'Pessoa', 'numero_identificacao' => '005555555LA041'];

        DadosPessoa::create($dados);
        $this->noTenant($outro, fn () => DadosPessoa::create($dados));

        $this->expectException(QueryException::class);

        DadosPessoa::create($dados);
    }

    public function test_tipo_de_documento_com_o_mesmo_slug_em_dois_tenants(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        TipoDocumento::create(['nome' => 'BI', 'slug' => 'bi']);
        $this->noTenant($outro, fn () => TipoDocumento::create(['nome' => 'BI', 'slug' => 'bi']));

        $this->assertSame(1, TipoDocumento::where('slug', 'bi')->count());
    }

    public function test_a_regra_unique_so_olha_para_o_tenant_corrente(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, fn () => $this->utilizador('so-em-b@example.com'));
        $this->utilizador('so-em-a@example.com');

        $this->assertTrue(Validator::make(['email' => 'so-em-b@example.com'], ['email' => 'unique:users,email'])->passes());
        $this->assertTrue(Validator::make(['email' => 'so-em-a@example.com'], ['email' => 'unique:users,email'])->fails());
    }

    public function test_a_regra_exists_nao_ve_utilizadores_de_outro_tenant(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $idDeB = $this->noTenant($outro, fn () => $this->utilizador('b@example.com')->id);

        $this->assertTrue(Validator::make(['id' => $idDeB], ['id' => 'exists:users,id'])->fails());
    }

    public function test_listagem_e_find_nao_devolvem_utilizadores_de_outro_tenant(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $idDeB = $this->noTenant($outro, fn () => $this->utilizador('b@example.com')->id);
        $this->utilizador('a@example.com');

        $this->assertNull(User::find($idDeB));
        $this->assertSame(['a@example.com'], User::pluck('email')->all());
    }

    public function test_encarregado_e_educando_gravam_o_tenant_no_pivot(): void
    {
        $encarregado = $this->utilizador('enc@example.com');
        $aluno = $this->utilizador('aluno@example.com');

        $encarregado->educandos()->attach($aluno->id, ['parentesco' => 'Pai']);

        $this->assertSame(1, $encarregado->educandos()->count());
        $this->assertSame($this->tenant->id, (int) $encarregado->educandos()->first()->pivot->tenant_id);
        $this->assertSame(1, $aluno->encarregados()->count());
    }

    public function test_nao_se_muda_o_tenant_de_um_utilizador(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $user = $this->utilizador('a@example.com');

        $this->expectException(AlteracaoDeTenantProibida::class);

        $user->forceFill(['tenant_id' => $outro->id])->save();
    }
}
```

Nota: o teste de `dados_pessoas` assume que `numero_identificacao` não é validado pelo model; se existir validação de formato no model, usar um valor que a satisfaça.

- [ ] **Step 2: Correr para ver falhar**

Run: `php artisan test --filter=UsuarioTenancyTest`
Expected: FAIL (colunas `tenant_id` inexistentes).

- [ ] **Step 3: Migrations (editar as originais)**

Em cada uma, acrescentar a seguir a `$table->id();`:

```php
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
```

E os índices únicos (spec §17.2):

- `dados_pessoas`: trocar `$table->string('numero_identificacao')->unique();` por `$table->string('numero_identificacao');` e acrescentar `$table->unique(['tenant_id', 'numero_identificacao']);`.
- `users`: trocar `->nullable()->unique()` de `email` e de `numero_matricula` por `->nullable()` e acrescentar `$table->unique(['tenant_id', 'email']);` e `$table->unique(['tenant_id', 'numero_matricula']);`. `users.dados_pessoa_id` mantém o seu `unique` (migration `2026_09_10_000000_add_unique_to_users_dados_pessoa_id`). **Não** tocar ainda em `password_reset_tokens` nem em `sessions` (Tarefa 5 trata da primeira; `sessions` é infra-estrutura).
- `tipos_documentos`: trocar `$table->string('slug')->unique();` por `$table->string('slug');` e acrescentar `$table->unique(['tenant_id', 'slug']);`.
- `documentos_pessoas` e `encarregados_alunos`: só `tenant_id`; o único `(encarregado_id, aluno_id)` fica.

- [ ] **Step 4: Models e pivots**

`User`, `DadosPessoa`, `DocumentoPessoa`, `TipoDocumento`, `EncarregadoAluno`: `use Modules\Core\Tenancy\PertenceAoTenant;` + `use PertenceAoTenant;`.

Em `User::educandos()` e `User::encarregados()`, acrescentar `->withPivot('parentesco', 'tenant_id')` (em vez de só `'parentesco'`) e `->withPivotValue('tenant_id', app(TenantContext::class)->id())`, importando `Modules\Core\Tenancy\TenantContext`.

- [ ] **Step 5: Retirar o `User` de boilerplate**

`app/Models/User.php` e `database/factories/UserFactory.php` só são usados entre si e pelas quatro Actions do Fortify. Nas quatro Actions de `app/Actions/Fortify/`, trocar `use App\Models\User;` por `use Modules\Usuario\Models\User;`. Apagar `app/Models/User.php` e `database/factories/UserFactory.php`. Confirmar com `grep -rn "App\\\\Models\\\\User\|UserFactory" app Modules database tests config` que não sobra nenhuma referência.

- [ ] **Step 6: Lista de transição**

Em `config/tenancy.php`, retirar de `tabelas_por_converter`: `dados_pessoas`, `documentos_pessoas`, `encarregados_alunos`, `tipos_documentos`, `users`.

- [ ] **Step 7: Correr os testes e a suite**

Run: `php artisan test --filter=UsuarioTenancyTest` e depois `php artisan test`
Expected: PASS em tudo. Os testes do módulo Usuario e dos outros módulos criam utilizadores e pessoas dentro do contexto por omissão, por isso não deviam precisar de alterações; quem criar dados em dois tenants usa `noTenant(...)`. Os testes do teste de esquema (`test_todo_o_model_de_uma_tabela_de_tenant_usa_a_trait`) passam a exigir a trait em todos estes models.

- [ ] **Step 8: Stage**

```bash
git add Modules/Usuario app config/tenancy.php database tests
```

---

### Task 4: Tokens Sanctum por tenant

**Files:**
- Modify: `Modules/Usuario/database/migrations/2026_04_04_192609_create_personal_access_tokens_table.php`
- Create: `Modules/Autenticacao/app/Models/TokenDeAcesso.php`
- Modify: `Modules/Autenticacao/app/Providers/AutenticacaoServiceProvider.php`
- Modify: `config/tenancy.php`
- Test: `Modules/Autenticacao/tests/Feature/TokenTenancyTest.php`

**Interfaces:**
- Consumes: `PertenceAoTenant`; `Laravel\Sanctum\PersonalAccessToken`.
- Produces: `Modules\Autenticacao\Models\TokenDeAcesso extends PersonalAccessToken` com a trait; `Sanctum::usePersonalAccessTokenModel(TokenDeAcesso::class)` registado no `boot()` do `AutenticacaoServiceProvider`; `personal_access_tokens.tenant_id NOT NULL`.

- [ ] **Step 1: Escrever os testes**

`Modules/Autenticacao/tests/Feature/TokenTenancyTest.php`:

```php
<?php

namespace Modules\Autenticacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Modules\Autenticacao\Models\TokenDeAcesso;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class TokenTenancyTest extends TestCase
{
    use RefreshDatabase;

    private function utilizador(string $email): User
    {
        return User::create(['name' => 'U', 'email' => $email, 'password' => Hash::make('segredo123')]);
    }

    public function test_o_sanctum_usa_o_model_de_token_com_tenant(): void
    {
        $this->assertSame(TokenDeAcesso::class, Sanctum::$personalAccessTokenModel);
    }

    public function test_o_token_grava_o_tenant_de_quem_o_emite(): void
    {
        $token = $this->utilizador('a@example.com')->createToken('api-token');

        $this->assertSame($this->tenant->id, (int) $token->accessToken->tenant_id);
    }

    public function test_token_do_tenant_a_funciona_no_dominio_de_a(): void
    {
        $plain = $this->utilizador('a@example.com')->createToken('api-token')->plainTextToken;

        $this->withToken($plain)
            ->postJson($this->urlDoTenant($this->tenant, '/api/v1/autenticacaoApi/api/logout'))
            ->assertSuccessful();
    }

    public function test_token_do_tenant_a_e_rejeitado_no_dominio_de_b(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $plain = $this->utilizador('a@example.com')->createToken('api-token')->plainTextToken;

        $this->withToken($plain)
            ->postJson($this->urlDoTenant($outro, '/api/v1/autenticacaoApi/api/logout'))
            ->assertUnauthorized();
    }

    public function test_a_pesquisa_do_token_so_encontra_os_do_tenant_corrente(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $plainDeB = $this->noTenant($outro, fn () => $this->utilizador('b@example.com')->createToken('api-token')->plainTextToken);

        $this->assertNull(PersonalAccessToken::findToken($plainDeB));
    }
}
```

Se a rota de logout exigir um corpo ou devolver outro código de sucesso, ajustar `assertSuccessful()` ao que `GestaoAutenticacaoAPI` devolve.

- [ ] **Step 2: Correr para ver falhar**

Run: `php artisan test --filter=TokenTenancyTest`
Expected: FAIL.

- [ ] **Step 3: Migration**

Em `2026_04_04_192609_create_personal_access_tokens_table.php`, a seguir a `$table->id();`:

```php
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
```

(`personal_access_tokens.token` mantém o seu `unique` global, spec §17.2.)

- [ ] **Step 4: Model e registo**

`Modules/Autenticacao/app/Models/TokenDeAcesso.php`:

```php
<?php

namespace Modules\Autenticacao\Models;

use Laravel\Sanctum\PersonalAccessToken;
use Modules\Core\Tenancy\PertenceAoTenant;

/**
 * Token de API de um utilizador. Com a trait, um token emitido num tenant
 * não é encontrado no domínio de outro (spec §10.3).
 */
class TokenDeAcesso extends PersonalAccessToken
{
    use PertenceAoTenant;
}
```

No `boot()` de `AutenticacaoServiceProvider`, depois de `LimitadorLogin::definir();`:

```php
        Sanctum::usePersonalAccessTokenModel(TokenDeAcesso::class);
```

com os `use Laravel\Sanctum\Sanctum;` e `use Modules\Autenticacao\Models\TokenDeAcesso;`.

- [ ] **Step 5: Lista de transição**

Em `config/tenancy.php`, retirar `personal_access_tokens` de `tabelas_por_converter`.

- [ ] **Step 6: Correr os testes e a suite**

Run: `php artisan test --filter=TokenTenancyTest` e depois `php artisan test`
Expected: PASS em tudo.

- [ ] **Step 7: Stage**

```bash
git add Modules/Autenticacao Modules/Usuario/database config/tenancy.php tests
```

---

### Task 5: Recuperação de palavra-passe por tenant

**Files:**
- Modify: `Modules/Usuario/database/migrations/2026_03_31_104100_create_users_table.php` (só o bloco `password_reset_tokens`)
- Create: `Modules/Autenticacao/app/Passwords/TokenRepositoryTenant.php`
- Create: `Modules/Autenticacao/app/Passwords/PasswordBrokerManagerTenant.php`
- Modify: `Modules/Autenticacao/app/Providers/AutenticacaoServiceProvider.php`
- Modify: `config/tenancy.php`
- Test: `Modules/Autenticacao/tests/Feature/RecuperacaoPalavraPasseTenancyTest.php`

**Interfaces:**
- Consumes: `TenantContext::id()`; `Illuminate\Auth\Passwords\DatabaseTokenRepository` (métodos `create`, `deleteExisting`, `exists`, `recentlyCreatedToken`, `delete`, `deleteExpired` passam todos por `getTable()` e `getPayload()`); `Illuminate\Auth\Passwords\PasswordBrokerManager::createTokenRepository(array $config)`.
- Produces: `password_reset_tokens` com chave primária `(tenant_id, email)`; `TokenRepositoryTenant` (filtra por tenant em todas as consultas e grava `tenant_id` no payload); `PasswordBrokerManagerTenant` que devolve esse repositório; ligação `auth.password` substituída no `AutenticacaoServiceProvider`.

- [ ] **Step 1: Escrever os testes**

`Modules/Autenticacao/tests/Feature/RecuperacaoPalavraPasseTenancyTest.php`:

```php
<?php

namespace Modules\Autenticacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class RecuperacaoPalavraPasseTenancyTest extends TestCase
{
    use RefreshDatabase;

    private function utilizador(string $email): User
    {
        return User::create(['name' => 'U', 'email' => $email, 'password' => Hash::make('antiga')]);
    }

    public function test_o_token_fica_gravado_com_o_tenant(): void
    {
        $user = $this->utilizador('igual@example.com');

        Password::broker()->createToken($user);

        $this->assertDatabaseHas('password_reset_tokens', ['tenant_id' => $this->tenant->id, 'email' => 'igual@example.com']);
    }

    public function test_o_mesmo_email_em_dois_tenants_tem_um_token_para_cada(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $userA = $this->utilizador('igual@example.com');
        $userB = $this->noTenant($outro, fn () => $this->utilizador('igual@example.com'));

        Password::broker()->createToken($userA);
        $this->noTenant($outro, fn () => Password::broker()->createToken($userB));

        $this->assertDatabaseCount('password_reset_tokens', 2);
    }

    public function test_pedir_reposicao_em_a_nao_apaga_o_token_de_b(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $userA = $this->utilizador('igual@example.com');
        $userB = $this->noTenant($outro, fn () => $this->utilizador('igual@example.com'));
        $tokenDeB = $this->noTenant($outro, fn () => Password::broker()->createToken($userB));

        Password::broker()->createToken($userA);
        Password::broker()->createToken($userA); // apaga o anterior de A, nunca o de B

        $this->assertSame(
            true,
            $this->noTenant($outro, fn () => Password::broker()->tokenExists($userB, $tokenDeB)),
        );
    }

    public function test_o_token_de_a_nao_repoe_a_palavra_passe_de_b(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $userA = $this->utilizador('igual@example.com');
        $userB = $this->noTenant($outro, fn () => $this->utilizador('igual@example.com'));
        $tokenDeA = Password::broker()->createToken($userA);

        $estado = $this->noTenant($outro, fn () => Password::broker()->reset(
            ['email' => 'igual@example.com', 'password' => 'nova-senha-123', 'password_confirmation' => 'nova-senha-123', 'token' => $tokenDeA],
            function () {},
        ));

        $this->assertSame(Password::INVALID_TOKEN, $estado);
        $this->assertTrue(Hash::check('antiga', $userB->fresh()->password));
    }

    public function test_a_limpeza_de_tokens_expirados_so_toca_no_tenant_corrente(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $userA = $this->utilizador('igual@example.com');
        $userB = $this->noTenant($outro, fn () => $this->utilizador('igual@example.com'));
        Password::broker()->createToken($userA);
        $this->noTenant($outro, fn () => Password::broker()->createToken($userB));

        $this->travel(3)->hours();
        Password::broker()->getRepository()->deleteExpired();
        $this->travelBack();

        $this->assertDatabaseCount('password_reset_tokens', 1);
    }
}
```

- [ ] **Step 2: Correr para ver falhar**

Run: `php artisan test --filter=RecuperacaoPalavraPasseTenancyTest`
Expected: FAIL (coluna `tenant_id` inexistente; a chave primária só por email).

- [ ] **Step 3: Migration**

Em `2026_03_31_104100_create_users_table.php`, trocar o bloco de `password_reset_tokens` por:

```php
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('email');
            $table->string('token');
            $table->timestamp('created_at')->nullable();

            $table->primary(['tenant_id', 'email']);
        });
```

- [ ] **Step 4: Repositório e broker**

`Modules/Autenticacao/app/Passwords/TokenRepositoryTenant.php`:

```php
<?php

namespace Modules\Autenticacao\Passwords;

use Illuminate\Auth\Passwords\DatabaseTokenRepository;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Modules\Core\Tenancy\TenantContext;

/**
 * Repositório de tokens de recuperação de palavra-passe isolado por tenant (spec §10.4).
 * Toda a consulta do repositório do Laravel passa por getTable(), e toda a escrita por
 * getPayload(): filtrar e gravar o tenant nesses dois pontos cobre todos os métodos.
 */
class TokenRepositoryTenant extends DatabaseTokenRepository
{
    protected function getTable(): Builder
    {
        return parent::getTable()->where('tenant_id', app(TenantContext::class)->id());
    }

    protected function getPayload($email, #[\SensitiveParameter] $token): array
    {
        return [
            'tenant_id' => app(TenantContext::class)->id(),
            'email' => $email,
            'token' => $this->hasher->make($token),
            'created_at' => new Carbon(),
        ];
    }
}
```

Conferir contra `vendor/laravel/framework/src/Illuminate/Auth/Passwords/DatabaseTokenRepository.php` que `getTable()` e `getPayload()` continuam `protected` com estas assinaturas; se o Laravel as tiver alterado, ajustar as assinaturas (não o desenho).

`Modules/Autenticacao/app/Passwords/PasswordBrokerManagerTenant.php`:

```php
<?php

namespace Modules\Autenticacao\Passwords;

use Illuminate\Auth\Passwords\PasswordBrokerManager;

class PasswordBrokerManagerTenant extends PasswordBrokerManager
{
    protected function createTokenRepository(array $config)
    {
        $chave = $this->app['config']['app.key'];

        if (str_starts_with($chave, 'base64:')) {
            $chave = base64_decode(substr($chave, 7));
        }

        return new TokenRepositoryTenant(
            $this->app['db']->connection($config['connection'] ?? null),
            $this->app['hash'],
            $config['table'],
            $chave,
            ($config['expire'] ?? 60) * 60,
            $config['throttle'] ?? 0,
            $config['prefix'] ?? '',
        );
    }
}
```

Conferir em `PasswordBrokerManager::createTokenRepository` do vendor que a lista de argumentos do `DatabaseTokenRepository` (ligação, hasher, tabela, chave, expiração, throttle, prefixo) é a mesma e copiá-la tal como está.

No `register()` de `AutenticacaoServiceProvider` (criá-lo, chamando `parent::register()`), substituir a ligação do Laravel:

```php
        $this->app->singleton('auth.password', fn ($app) => new PasswordBrokerManagerTenant($app));
```

- [ ] **Step 5: Lista de transição**

Em `config/tenancy.php`, retirar `password_reset_tokens` de `tabelas_por_converter`.

- [ ] **Step 6: Correr os testes e a suite**

Run: `php artisan test --filter=RecuperacaoPalavraPasseTenancyTest` e depois `php artisan test`
Expected: PASS em tudo.

- [ ] **Step 7: Stage**

```bash
git add Modules/Autenticacao Modules/Usuario/database config/tenancy.php tests
```

---

### Task 6: Sessão, limitador de login e registo público

**Files:**
- Create: `Modules/Autenticacao/app/Http/Middleware/VerificarTenantDaSessao.php`
- Modify: `Modules/Autenticacao/app/Http/Controllers/AutenticacaoController.php`
- Modify: `bootstrap/app.php`
- Modify: `Modules/Autenticacao/app/Service/LimitadorLogin.php`
- Modify: `config/fortify.php`
- Test: `Modules/Autenticacao/tests/Feature/SessaoTenancyTest.php`, `Modules/Autenticacao/tests/Feature/LimiteTentativasLoginTest.php`

**Interfaces:**
- Consumes: `TenantContext::temTenant()`, `TenantContext::id()`; sessão iniciada no grupo `web`.
- Produces: `VerificarTenantDaSessao` (grupo `web`, depois de `HandleInertiaRequests`); chave de sessão `tenant_id` escrita no login; chaves do limitador `t{tenantId}:…`.

- [ ] **Step 1: Escrever os testes**

`Modules/Autenticacao/tests/Feature/SessaoTenancyTest.php`:

```php
<?php

namespace Modules\Autenticacao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class SessaoTenancyTest extends TestCase
{
    use RefreshDatabase;

    private function utilizador(string $email): User
    {
        return User::create(['name' => 'U', 'email' => $email, 'password' => Hash::make('segredo123')]);
    }

    public function test_o_login_grava_o_tenant_na_sessao(): void
    {
        $this->utilizador('a@example.com');

        $this->post($this->urlDoTenant($this->tenant, '/login'), ['login' => 'a@example.com', 'password' => 'segredo123']);

        $this->assertSame($this->tenant->id, session('tenant_id'));
    }

    public function test_credenciais_do_tenant_a_nao_entram_no_dominio_de_b(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->utilizador('a@example.com');

        $this->post($this->urlDoTenant($outro, '/login'), ['login' => 'a@example.com', 'password' => 'segredo123'])
            ->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_o_mesmo_email_entra_em_cada_tenant_com_a_sua_palavra_passe(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->utilizador('igual@example.com');
        $this->noTenant($outro, fn () => User::create(['name' => 'B', 'email' => 'igual@example.com', 'password' => Hash::make('outra-senha')]));

        $this->post($this->urlDoTenant($outro, '/login'), ['login' => 'igual@example.com', 'password' => 'outra-senha'])
            ->assertSessionHasNoErrors();

        $this->assertAuthenticated();
    }

    public function test_sessao_de_a_reenviada_ao_dominio_de_b_nao_autentica(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $user = $this->utilizador('a@example.com');

        $this->actingAs($user)->get($this->urlDoTenant($outro, '/estabelecimento'))
            ->assertRedirect();

        $this->assertGuest();
    }

    public function test_sessao_com_tenant_diferente_do_do_pedido_e_invalidada(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $user = $this->utilizador('a@example.com');

        $this->actingAs($user)
            ->withSession(['tenant_id' => $outro->id])
            ->get($this->urlDoTenant($this->tenant, '/estabelecimento'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
```

Em `Modules/Autenticacao/tests/Feature/LimiteTentativasLoginTest.php`, acrescentar (manter o que já existe):

```php
    public function test_falhas_numa_conta_em_a_nao_bloqueiam_a_mesma_conta_em_b(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        for ($i = 0; $i < 6; $i++) {
            $this->post($this->urlDoTenant($this->tenant, '/login'), ['login' => 'igual@example.com', 'password' => 'errada']);
        }

        $resposta = $this->post($this->urlDoTenant($outro, '/login'), ['login' => 'igual@example.com', 'password' => 'errada']);

        $resposta->assertSessionHasErrors('login');
        $this->assertStringNotContainsString('Muitas tentativas', session('errors')->first('login'));
    }

    public function test_o_registo_publico_esta_desactivado(): void
    {
        $this->post($this->urlDoTenant($this->tenant, '/register'), [
            'name' => 'X', 'email' => 'x@example.com', 'password' => 'segredo1234', 'password_confirmation' => 'segredo1234',
        ])->assertNotFound();
    }
```

Se o teste de limite existente já fixar o número de tentativas permitidas (5 por minuto por conta e IP), usar esse mesmo número no ciclo em vez de 6.

- [ ] **Step 2: Correr para ver falhar**

Run: `php artisan test --filter='SessaoTenancyTest|LimiteTentativasLoginTest'`
Expected: FAIL (sem `tenant_id` na sessão; a mesma conta é bloqueada nos dois tenants; `/register` existe).

- [ ] **Step 3: Middleware de verificação do tenant da sessão**

`Modules/Autenticacao/app/Http/Middleware/VerificarTenantDaSessao.php`:

```php
<?php

namespace Modules\Autenticacao\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defesa em profundidade (spec §10.2): uma sessão iniciada num tenant só vale nesse tenant.
 * A defesa principal é o provider, que só encontra o utilizador dentro do tenant corrente.
 */
class VerificarTenantDaSessao
{
    public function __construct(private TenantContext $contexto) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->contexto->temTenant() || ! $request->hasSession()) {
            return $next($request);
        }

        $daSessao = $request->session()->get('tenant_id');

        if ($daSessao !== null && (int) $daSessao !== $this->contexto->id()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        return $next($request);
    }
}
```

Registar em `bootstrap/app.php`, no grupo `web`, **antes** de `HandleInertiaRequests` (assim o Inertia nunca partilha os dados de um utilizador de outra escola):

```php
        $middleware->web(append: [
            VerificarTenantDaSessao::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            ExigirConfiguracaoInicial::class,
        ]);
```

com `use Modules\Autenticacao\Http\Middleware\VerificarTenantDaSessao;`. O middleware não chama `definir()` nem `limpar()` do contexto.

No `AutenticacaoController::store`, depois de `$request->session()->regenerate();`, gravar o tenant (injectar `TenantContext` no construtor ou resolvê-lo com `app(TenantContext::class)`, importado com `use`):

```php
        $request->session()->put('tenant_id', app(TenantContext::class)->id());
```

- [ ] **Step 4: Limitador de login ciente do tenant**

Identificar o limitador em uso: é `LimitadorLogin` (nome `autenticacao.login`), chamado por `GestaoAutenticacao` (web) e `GestaoAutenticacaoAPI`. Os limitadores `login` de `FortifyServiceProvider` e `AppServiceProvider` não são usados por nenhuma rota (a rota de login é do módulo Autenticacao). Não os apagar neste plano; registar no ledger que ficam por limpar.

Em `LimitadorLogin::definir()`, prefixar as três chaves com o tenant (importar `Modules\Core\Tenancy\TenantContext`):

```php
            $tenant = app(TenantContext::class)->id();

            return [
                Limit::perMinute(5)->by("t{$tenant}|conta-ip:{$conta}|{$ip}"),
                Limit::perMinutes(15, 20)->by("t{$tenant}|conta:{$conta}"),
                Limit::perMinute(30)->by("t{$tenant}|ip:{$ip}"),
            ];
```

- [ ] **Step 5: Desactivar o registo público**

Em `config/fortify.php`, retirar `Features::registration(),` da lista `features` (spec §10.6 e §22 ponto 1). `app/Actions/Fortify/CreateNewUser.php` fica sem uso mas não se apaga neste plano.

- [ ] **Step 6: Correr os testes e a suite**

Run: `php artisan test --filter='SessaoTenancyTest|LimiteTentativasLoginTest|LoginTest'` e depois `php artisan test`
Expected: PASS em tudo. Se `AuthUserCompartilhadoInertiaTest` ou outro teste de Inertia falhar por causa da ordem dos middlewares, a ordem acima (verificação antes do Inertia) é a que se mantém.

- [ ] **Step 7: Stage**

```bash
git add Modules/Autenticacao bootstrap/app.php config/fortify.php tests
```

---

### Task 7: Matriz de isolamento da identidade e testes de arquitectura

**Files:**
- Create: `tests/Feature/Tenancy/IdentidadeIsolamentoTest.php`
- Modify: `tests/Feature/Arquitectura/TenancyEsquemaTest.php`
- Modify: `config/tenancy.php` (só conferir)

**Interfaces:**
- Consumes: tudo o que as Tarefas 1 a 6 produzem.
- Produces: a linha "Autenticação", "Listagem", "ID directo", "Action", "Cache" e "Sem contexto" da matriz de §19.2 para a identidade, testadas de ponta a ponta por HTTP.

- [ ] **Step 1: Escrever o teste de matriz**

`tests/Feature/Tenancy/IdentidadeIsolamentoTest.php`:

```php
<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class IdentidadeIsolamentoTest extends TestCase
{
    use RefreshDatabase;

    private function administrador(string $email): User
    {
        $this->seed(PermissaoDatabaseSeeder::class);
        $user = User::create(['name' => 'Admin', 'email' => $email, 'password' => Hash::make('segredo123')]);
        $user->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->firstOrFail()->id);

        return $user;
    }

    public function test_a_listagem_de_utilizadores_mostra_so_os_do_tenant_do_dominio(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $admin = $this->administrador('admin-a@example.com');
        $this->noTenant($outro, fn () => User::create(['name' => 'So em B', 'email' => 'so-em-b@example.com', 'password' => Hash::make('x')]));

        $resposta = $this->actingAs($admin)->get($this->urlDoTenant($this->tenant, '/usuarios'));

        $resposta->assertOk();
        $this->assertStringNotContainsString('so-em-b@example.com', $resposta->getContent());
    }

    public function test_pedir_no_dominio_de_a_um_utilizador_de_b_da_404(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $admin = $this->administrador('admin-a@example.com');
        $idDeB = $this->noTenant($outro, fn () => User::create(['name' => 'B', 'email' => 'b@example.com', 'password' => Hash::make('x')])->id);

        $this->actingAs($admin)
            ->get($this->urlDoTenant($this->tenant, "/usuarios/{$idDeB}"))
            ->assertNotFound();
    }

    public function test_o_administrador_de_a_nao_acede_ao_dominio_de_b(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $admin = $this->administrador('admin-a@example.com');

        $this->actingAs($admin)->get($this->urlDoTenant($outro, '/usuarios'))->assertRedirect();

        $this->assertGuest();
    }
}
```

Antes de correr, confirmar com `php artisan route:list --path=usuarios` a rota de detalhe de um utilizador e usá-la em `test_pedir_no_dominio_de_a_um_utilizador_de_b_da_404` (se não existir uma rota `GET /usuarios/{id}`, usar a rota de edição ou de detalhe real, com route model binding). O administrador de teste precisa de `estabelecimento.ver`/`editar` e de `usuario.ver`: o perfil `ADMIN_ESCOLA` semeado já os tem; o estabelecimento por omissão dos testes já está configurado.

- [ ] **Step 2: Reforçar o teste de esquema**

Em `tests/Feature/Arquitectura/TenancyEsquemaTest.php`, acrescentar (antes de `classesDeModels`):

```php
    public function test_as_tabelas_de_identidade_ja_nao_estao_na_lista_de_transicao(): void
    {
        $convertidas = [
            'users', 'dados_pessoas', 'documentos_pessoas', 'tipos_documentos', 'encarregados_alunos',
            'roles', 'role_permissoes', 'user_roles', 'user_permissoes',
            'personal_access_tokens', 'password_reset_tokens',
        ];

        $aindaPorConverter = array_values(array_intersect($convertidas, config('tenancy.tabelas_por_converter')));

        $this->assertSame([], $aindaPorConverter, 'Tabelas de identidade ainda em tabelas_por_converter: ' . implode(', ', $aindaPorConverter));

        foreach ($convertidas as $tabela) {
            $this->assertTrue(Schema::hasColumn($tabela, 'tenant_id'), "{$tabela} devia ter tenant_id.");
        }
    }

    public function test_os_unicos_de_identidade_sao_por_tenant(): void
    {
        $esperados = [
            'users' => [['tenant_id', 'email'], ['tenant_id', 'numero_matricula']],
            'dados_pessoas' => [['tenant_id', 'numero_identificacao']],
            'tipos_documentos' => [['tenant_id', 'slug']],
        ];

        foreach ($esperados as $tabela => $indices) {
            $unicos = collect(Schema::getIndexes($tabela))->where('unique', true)->map(fn (array $i) => $i['columns'])->all();

            foreach ($indices as $colunas) {
                $this->assertContains($colunas, $unicos, "{$tabela} devia ter um índice único por " . implode(', ', $colunas) . '.');
            }

            foreach ($unicos as $colunas) {
                $this->assertNotSame(['email'], $colunas, "{$tabela} não pode ter email único global.");
                $this->assertNotSame(['numero_matricula'], $colunas);
                $this->assertNotSame(['numero_identificacao'], $colunas);
                $this->assertNotSame(['slug'], $colunas);
            }
        }
    }
```

- [ ] **Step 3: Correr os testes e a suite**

Run: `php artisan test --filter='IdentidadeIsolamentoTest|TenancyEsquemaTest|TenancyArquitectura'` e depois `php artisan test`
Expected: PASS em tudo.

- [ ] **Step 4: Verificar por mutação**

Retirar temporariamente `use PertenceAoTenant;` de `User` e confirmar que falham `IdentidadeIsolamentoTest`, `UsuarioTenancyTest` e o teste de esquema dos models; repor. Retirar `Sanctum::usePersonalAccessTokenModel(...)` e confirmar que falha `TokenTenancyTest`; repor. Mencionar as duas mutações no relatório.

- [ ] **Step 5: Stage**

```bash
git add tests config/tenancy.php
```

---

### Task 8: BD de desenvolvimento e fecho do plano

**Files:**
- Modify: `database/seeders/DatabaseSeeder.php` (só se o administrador de desenvolvimento precisar de ajuste)

- [ ] **Step 1: Suite completa**

Run: `php artisan test`
Expected: tudo a passar.

- [ ] **Step 2: Recriar a BD de desenvolvimento — pedir confirmação ao utilizador**

As migrations originais de `users`, `password_reset_tokens`, `personal_access_tokens`, `dados_pessoas`, `tipos_documentos`, `documentos_pessoas`, `encarregados_alunos` e das quatro tabelas de permissões mudaram: a BD local tem de ser recriada. **Parar e pedir ao utilizador para correr** `php artisan migrate:fresh --seed` (apaga os dados locais; o sistema de permissões do agente bloqueia este comando). Depois da confirmação dele, verificar por HTTP (sem escrever na BD por fora da app):

- `/login` responde 200 em `localhost`, `127.0.0.1` e no host de `APP_URL`, e 404 num host desconhecido;
- o login do administrador (`admin@mositec.gmail.com`) funciona e leva ao ecrã de configuração do estabelecimento;
- `POST /register` dá 404;
- o pedido de recuperação de palavra-passe (`POST /forgot-password`) responde sem erro de BD.

- [ ] **Step 3: Stage**

```bash
git add database
```

---

## Auto-revisão

**Cobertura do spec (etapa 5 de §20.2):**
- §10.1 utilizadores e únicos → Tarefa 3; §10.2 sessão → Tarefa 6; §10.3 Sanctum → Tarefa 4; §10.4 recuperação de palavra-passe → Tarefa 5; §10.5 limitador (identificado o que está em uso: `LimitadorLogin`) → Tarefa 6; §10.6 registo público desactivado → Tarefa 6.
- §11 `CacheTenant`, `PermissaoCache`, `PermissionResolver` com âmbito → Tarefa 1.
- §15 permissões (quatro tabelas tenant-scoped; `modulos` e `acoes` globais) → Tarefa 2.
- §17.2 únicos que mudam (`users.email`, `users.numero_matricula`, `dados_pessoas.numero_identificacao`, `tipos_documentos.slug`, `password_reset_tokens`) → Tarefas 3 e 5. `alunos.numero_matricula`, `matriculas.*` e as sequências ficam para as etapas 6 e 7.
- §7.3 pivots com `withPivotValue` (`user_roles`, `encarregados_alunos`) → Tarefas 2 e 3.
- §19.2 linhas "Cache", "Autenticação", "Listagem", "ID directo", "Action", "Sem contexto" para a identidade → Tarefas 1, 2, 3, 6 e 7.
- §22 pontos 1 e 3 (registo desactivado; `tipos_documentos` por tenant) → Tarefas 6 e 3.
- Fora por decisão: `matricula_sequencias`, caminho dos documentos, provisionador e `ComTenant`; ficam registados em "Fora deste plano".

**Tipos e nomes:** `CacheTenant`, `TokenDeAcesso`, `TokenRepositoryTenant`, `PasswordBrokerManagerTenant`, `VerificarTenantDaSessao` e a chave de sessão `tenant_id` usados com o mesmo nome em todas as tarefas.

**Riscos conhecidos:**
- As escritas em massa (`RolePermissao::insert`, `UserPermissao::insert`) não passam pela trait: a Tarefa 2 acrescenta o `tenant_id` do contexto às linhas e tem teste.
- O login num host central sem tenant lança `TenantNaoResolvido` (não há ecrã de login central nesta fase).
- O limitador `api` de `AppServiceProvider` e a cache do `RateLimiter` usam chaves por IP partilhadas entre tenants; só o limitador de login fica ciente do tenant (spec §10.5). Os limitadores `login` de Fortify e de `AppServiceProvider` são código morto que fica por limpar.
- `GeradorMatriculaService` continua global até à etapa 7: dois tenants avançam a mesma sequência, o que não viola a unicidade por tenant de `users.numero_matricula`.
