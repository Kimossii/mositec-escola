# Tenancy — Plano 1: Runtime e Módulo Tenant (base)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Pôr a aplicação a correr sempre dentro de um Tenant resolvido, com o mecanismo de isolamento (contexto, trait, scope, verificador de validação) construído e testado, ainda sem converter nenhuma tabela existente.

**Architecture:** O runtime vive em `Modules/Core/app/Tenancy` e não conhece as tabelas `tenants`/`domains`. A gestão vive no novo `Modules/Tenant`, que fornece a implementação do contrato `ResolvedorTenant`. Um middleware nos grupos `web` e `api` resolve o tenant antes da sessão. As tabelas existentes ficam numa lista de transição (`tabelas_por_converter`) até os planos seguintes as converterem.

**Tech Stack:** PHP 8.2, Laravel 12, nwidart/laravel-modules 13, PHPUnit 11, SQLite em memória nos testes, PostgreSQL em desenvolvimento.

**Spec:** `docs/superpowers/specs/2026-09-30-fundacao-tenancy-design.md` — este plano implementa as etapas 1, 2 e 3 de §20.2.

## Global Constraints

- **Nunca fazer commit.** O dono do repositório faz os commits. Cada tarefa termina com `git add` dos ficheiros tocados, nada mais.
- **Nunca escrever na BD de desenvolvimento por tinker ou SQL.** Só migrations, seeders e testes.
- Fail-closed: sem tenant resolvido, código tenant-scoped lança `TenantNaoResolvido`. Nunca devolve resultados vazios nem todos os registos.
- Não existe `semTenant()`. O único caminho para ter contexto fora de um pedido HTTP é `TenantContext::executarComo()`.
- `Modules/Core/app/Tenancy` não importa nada de `Modules\Tenant\…`. Nenhum módulo fora de `Modules/Tenant` importa `Modules\Tenant\…`. (Os testes em `tests/` e `Modules/*/tests/` podem.)
- `tenant_id` nunca entra em `$fillable` nem em DTOs.
- Em PHP, importar as classes com `use` no topo; nunca nomes totalmente qualificados no meio do código.
- Colunas-enum inteiras têm sempre uma coluna irmã `*_descricao`, sincronizada num hook `saving` do model.
- Identificadores, mensagens e comentários em português.
- Comando de testes: `php artisan test`. A suite completa tem de passar no fim de cada tarefa.
- Nomes exactos: códigos de tenant no formato `MOSI-` + seis dígitos; estados `Activo`, `Suspenso`, `Encerrado`; modos de resolução `dominio` e `unico`.

## Review Focus

Comportamentos que o spec implica e que mais facilmente ficam sem teste. Cada um tem o teste na tarefa indicada.

1. **Host com maiúsculas, porta ou ponto final** (`Escola-A.Localhost:8000.`) tem de resolver o mesmo tenant que `escola-a.localhost` → Tarefa 4.
2. **Excepção dentro de `executarComo`** tem de repor o contexto anterior, não deixar o tenant temporário activo → Tarefa 1.
3. **Depois de um pedido HTTP, o contexto volta ao que estava antes** (senão um teste que faz um pedido e depois consulta models fica sem tenant, e um worker reutilizado fica com o tenant do pedido anterior) → Tarefa 5.
4. **`exists` com uma lista de IDs que mistura dois tenants** tem de falhar, não passar porque alguns existem → Tarefa 6.
5. **`TENANCY_MODO` com valor desconhecido** tem de dar erro explícito ao resolver, não cair em silêncio num dos modos → Tarefa 4.

## Mapa de ficheiros

| Ficheiro | Responsabilidade |
|---|---|
| `Modules/Core/app/Tenancy/Enums/EstadoTenant.php` | Estados do tenant |
| `Modules/Core/app/Tenancy/TenantAtual.php` | Representação imutável do tenant corrente |
| `Modules/Core/app/Tenancy/TenantContext.php` | Contexto do pedido |
| `Modules/Core/app/Tenancy/Exceptions/TenantNaoResolvido.php` | Falha explícita sem tenant |
| `Modules/Core/app/Tenancy/Exceptions/AlteracaoDeTenantProibida.php` | Tentativa de mudar o tenant de um registo |
| `Modules/Core/app/Tenancy/TenantScope.php` | Global scope |
| `Modules/Core/app/Tenancy/PertenceAoTenant.php` | Trait dos models |
| `Modules/Core/app/Tenancy/Support/NormalizadorHost.php` | Normalização de hosts |
| `Modules/Core/app/Tenancy/Contracts/ResolvedorTenant.php` | Contrato de resolução |
| `Modules/Core/app/Tenancy/Http/Middleware/ResolverTenant.php` | Middleware |
| `Modules/Core/app/Tenancy/Validation/VerificadorPresencaTenant.php` | `exists`/`unique` cientes do tenant |
| `config/tenancy.php` | Modo, hosts centrais, listas de tabelas |
| `Modules/Tenant/…` | Módulo novo: tabelas, models, resolvedores, provider, seeder de desenvolvimento |
| `tests/Concerns/ComTenantDeTeste.php` | Tenant por omissão e auxiliares para os testes |
| `tests/Feature/Arquitectura/*` | Testes de arquitectura e de esquema |

---

### Task 1: TenantAtual e TenantContext

**Files:**
- Create: `Modules/Core/app/Tenancy/Enums/EstadoTenant.php`
- Create: `Modules/Core/app/Tenancy/TenantAtual.php`
- Create: `Modules/Core/app/Tenancy/Exceptions/TenantNaoResolvido.php`
- Create: `Modules/Core/app/Tenancy/TenantContext.php`
- Modify: `Modules/Core/app/Providers/CoreServiceProvider.php`
- Test: `Modules/Core/tests/Unit/Tenancy/TenantContextTest.php`

**Interfaces:**
- Consumes: nada.
- Produces:
  - `Modules\Core\Tenancy\Enums\EstadoTenant` — `ACTIVO = 1`, `SUSPENSO = 2`, `ENCERRADO = 3`; `label(): string`.
  - `Modules\Core\Tenancy\TenantAtual` — `new TenantAtual(int $id, string $codigo, string $nome, EstadoTenant $estado)`; propriedades públicas só de leitura.
  - `Modules\Core\Tenancy\TenantContext` — `atual(): TenantAtual`, `id(): int`, `temTenant(): bool`, `definir(TenantAtual $tenant): void`, `limpar(): void`, `executarComo(TenantAtual $tenant, Closure $fn): mixed`, `lembrar(string $chave, Closure $fn): mixed`. Ligado no container com `scoped`.
  - `Modules\Core\Tenancy\Exceptions\TenantNaoResolvido` (estende `RuntimeException`).

- [ ] **Step 1: Escrever o teste**

`Modules/Core/tests/Unit/Tenancy/TenantContextTest.php`:

```php
<?php

namespace Modules\Core\Tests\Unit\Tenancy;

use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;
use Modules\Core\Tenancy\TenantAtual;
use Modules\Core\Tenancy\TenantContext;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TenantContextTest extends TestCase
{
    private function tenant(int $id): TenantAtual
    {
        return new TenantAtual($id, sprintf('MOSI-%06d', $id), "Escola {$id}", EstadoTenant::ACTIVO);
    }

    public function test_sem_tenant_atual_lanca_excepcao(): void
    {
        $contexto = new TenantContext();

        $this->assertFalse($contexto->temTenant());
        $this->expectException(TenantNaoResolvido::class);
        $contexto->atual();
    }

    public function test_id_sem_tenant_lanca_excepcao(): void
    {
        $this->expectException(TenantNaoResolvido::class);
        (new TenantContext())->id();
    }

    public function test_definir_e_limpar(): void
    {
        $contexto = new TenantContext();
        $contexto->definir($this->tenant(7));

        $this->assertTrue($contexto->temTenant());
        $this->assertSame(7, $contexto->id());
        $this->assertSame('MOSI-000007', $contexto->atual()->codigo);

        $contexto->limpar();
        $this->assertFalse($contexto->temTenant());
    }

    public function test_executar_como_devolve_o_resultado_e_repoe_o_contexto_anterior(): void
    {
        $contexto = new TenantContext();
        $contexto->definir($this->tenant(1));

        $resultado = $contexto->executarComo($this->tenant(2), fn () => $contexto->id());

        $this->assertSame(2, $resultado);
        $this->assertSame(1, $contexto->id());
    }

    public function test_executar_como_sem_contexto_anterior_volta_a_ficar_sem_tenant(): void
    {
        $contexto = new TenantContext();

        $contexto->executarComo($this->tenant(2), fn () => null);

        $this->assertFalse($contexto->temTenant());
    }

    public function test_executar_como_repoe_o_contexto_mesmo_com_excepcao(): void
    {
        $contexto = new TenantContext();
        $contexto->definir($this->tenant(1));

        try {
            $contexto->executarComo($this->tenant(2), fn () => throw new RuntimeException('falhou'));
            $this->fail('Devia ter lançado a excepção.');
        } catch (RuntimeException $e) {
            $this->assertSame('falhou', $e->getMessage());
        }

        $this->assertSame(1, $contexto->id());
    }

    public function test_lembrar_calcula_uma_vez_por_tenant(): void
    {
        $contexto = new TenantContext();
        $contexto->definir($this->tenant(1));
        $chamadas = 0;
        $calcular = function () use (&$chamadas) {
            $chamadas++;

            return null;
        };

        $contexto->lembrar('chave', $calcular);
        $contexto->lembrar('chave', $calcular);

        $this->assertSame(1, $chamadas, 'Um resultado nulo também fica guardado.');
    }

    public function test_lembrar_nao_vaza_entre_tenants(): void
    {
        $contexto = new TenantContext();
        $contexto->definir($this->tenant(1));
        $contexto->lembrar('nome', fn () => 'do tenant 1');

        $dentro = $contexto->executarComo($this->tenant(2), fn () => $contexto->lembrar('nome', fn () => 'do tenant 2'));

        $this->assertSame('do tenant 2', $dentro);
        $this->assertSame('do tenant 1', $contexto->lembrar('nome', fn () => 'recalculado'));
    }

    public function test_lembrar_sem_tenant_lanca_excepcao(): void
    {
        $this->expectException(TenantNaoResolvido::class);
        (new TenantContext())->lembrar('chave', fn () => 1);
    }

    public function test_definir_outro_tenant_limpa_a_memoria(): void
    {
        $contexto = new TenantContext();
        $contexto->definir($this->tenant(1));
        $contexto->lembrar('nome', fn () => 'do tenant 1');

        $contexto->definir($this->tenant(2));

        $this->assertSame('do tenant 2', $contexto->lembrar('nome', fn () => 'do tenant 2'));
    }
}
```

- [ ] **Step 2: Correr o teste e confirmar que falha**

Run: `php artisan test --filter=TenantContextTest`
Expected: FAIL — `Class "Modules\Core\Tenancy\TenantContext" not found`.

- [ ] **Step 3: Implementar**

`Modules/Core/app/Tenancy/Enums/EstadoTenant.php`:

```php
<?php

namespace Modules\Core\Tenancy\Enums;

enum EstadoTenant: int
{
    case ACTIVO = 1;
    case SUSPENSO = 2;
    case ENCERRADO = 3;

    public function label(): string
    {
        return match ($this) {
            self::ACTIVO => 'Activo',
            self::SUSPENSO => 'Suspenso',
            self::ENCERRADO => 'Encerrado',
        };
    }
}
```

`Modules/Core/app/Tenancy/TenantAtual.php`:

```php
<?php

namespace Modules\Core\Tenancy;

use Modules\Core\Tenancy\Enums\EstadoTenant;

/**
 * Tudo o que os módulos da escola conhecem do tenant corrente.
 * Não é um model: não depende das tabelas de gestão de tenants.
 */
final readonly class TenantAtual
{
    public function __construct(
        public int $id,
        public string $codigo,
        public string $nome,
        public EstadoTenant $estado,
    ) {}
}
```

`Modules/Core/app/Tenancy/Exceptions/TenantNaoResolvido.php`:

```php
<?php

namespace Modules\Core\Tenancy\Exceptions;

use RuntimeException;

class TenantNaoResolvido extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Operação sobre dados de tenant sem tenant resolvido. Fora de um pedido HTTP, use TenantContext::executarComo().');
    }
}
```

`Modules/Core/app/Tenancy/TenantContext.php`:

```php
<?php

namespace Modules\Core\Tenancy;

use Closure;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;

class TenantContext
{
    private ?TenantAtual $tenant = null;

    /** @var array<string, mixed> */
    private array $memoria = [];

    public function atual(): TenantAtual
    {
        return $this->tenant ?? throw new TenantNaoResolvido();
    }

    public function id(): int
    {
        return $this->atual()->id;
    }

    public function temTenant(): bool
    {
        return $this->tenant !== null;
    }

    public function definir(TenantAtual $tenant): void
    {
        $this->tenant = $tenant;
        $this->memoria = [];
    }

    public function limpar(): void
    {
        $this->tenant = null;
        $this->memoria = [];
    }

    /**
     * Único caminho para ter contexto fora de um pedido HTTP
     * (provisioning, seeders, testes, comandos, jobs).
     */
    public function executarComo(TenantAtual $tenant, Closure $fn): mixed
    {
        $tenantAnterior = $this->tenant;
        $memoriaAnterior = $this->memoria;

        $this->definir($tenant);

        try {
            return $fn($tenant);
        } finally {
            $this->tenant = $tenantAnterior;
            $this->memoria = $memoriaAnterior;
        }
    }

    /**
     * Memória por tenant e por pedido. Esvaziada quando o tenant muda.
     */
    public function lembrar(string $chave, Closure $fn): mixed
    {
        $this->atual();

        if (! array_key_exists($chave, $this->memoria)) {
            $this->memoria[$chave] = $fn();
        }

        return $this->memoria[$chave];
    }
}
```

Em `Modules/Core/app/Providers/CoreServiceProvider.php`, acrescentar o import e o método `register`:

```php
use Modules\Core\Tenancy\TenantContext;
```

```php
    public function register(): void
    {
        parent::register();

        $this->app->scoped(TenantContext::class);
    }
```

- [ ] **Step 4: Correr o teste e confirmar que passa**

Run: `php artisan test --filter=TenantContextTest`
Expected: PASS, 10 testes.

- [ ] **Step 5: Correr a suite completa**

Run: `php artisan test`
Expected: PASS, sem regressões.

- [ ] **Step 6: Stage (sem commit)**

```bash
git add Modules/Core/app/Tenancy Modules/Core/app/Providers/CoreServiceProvider.php Modules/Core/tests/Unit/Tenancy
```

---

### Task 2: TenantScope e trait PertenceAoTenant

**Files:**
- Create: `Modules/Core/app/Tenancy/Exceptions/AlteracaoDeTenantProibida.php`
- Create: `Modules/Core/app/Tenancy/TenantScope.php`
- Create: `Modules/Core/app/Tenancy/PertenceAoTenant.php`
- Test: `Modules/Core/tests/Feature/Tenancy/PertenceAoTenantTest.php`

**Interfaces:**
- Consumes: `TenantContext` (`id()`, `definir()`, `limpar()`, `executarComo()`), `TenantAtual`, `EstadoTenant`, `TenantNaoResolvido` — Tarefa 1.
- Produces:
  - `Modules\Core\Tenancy\PertenceAoTenant` — trait para models cuja tabela tem a coluna `tenant_id`.
  - `Modules\Core\Tenancy\TenantScope` — implementa `Illuminate\Database\Eloquent\Scope`.
  - `Modules\Core\Tenancy\Exceptions\AlteracaoDeTenantProibida` (estende `LogicException`).

- [ ] **Step 1: Escrever o teste**

O teste usa uma tabela e um model só seus, para não depender de nenhum módulo.

`Modules/Core/tests/Feature/Tenancy/PertenceAoTenantTest.php`:

```php
<?php

namespace Modules\Core\Tests\Feature\Tenancy;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Core\Tenancy\Exceptions\AlteracaoDeTenantProibida;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Tenancy\TenantAtual;
use Modules\Core\Tenancy\TenantContext;
use Tests\TestCase;

class ItemDeTeste extends Model
{
    use PertenceAoTenant;

    protected $table = 'tenancy_teste_itens';

    protected $fillable = ['nome'];

    public $timestamps = false;
}

class PertenceAoTenantTest extends TestCase
{
    use RefreshDatabase;

    private TenantContext $contexto;

    private TenantAtual $tenantA;

    private TenantAtual $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('tenancy_teste_itens');
        Schema::create('tenancy_teste_itens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('nome');
        });

        $this->tenantA = new TenantAtual(101, 'MOSI-000101', 'Escola A', EstadoTenant::ACTIVO);
        $this->tenantB = new TenantAtual(102, 'MOSI-000102', 'Escola B', EstadoTenant::ACTIVO);

        $this->contexto = app(TenantContext::class);
        $this->contexto->limpar();
    }

    public function test_criacao_preenche_o_tenant_id_a_partir_do_contexto(): void
    {
        $item = $this->contexto->executarComo($this->tenantA, fn () => ItemDeTeste::create(['nome' => 'a1']));

        $this->assertSame(101, (int) DB::table('tenancy_teste_itens')->where('id', $item->id)->value('tenant_id'));
    }

    public function test_leitura_so_devolve_registos_do_tenant_corrente(): void
    {
        $this->contexto->executarComo($this->tenantA, fn () => ItemDeTeste::create(['nome' => 'a1']));
        $this->contexto->executarComo($this->tenantB, fn () => ItemDeTeste::create(['nome' => 'b1']));

        $nomes = $this->contexto->executarComo($this->tenantA, fn () => ItemDeTeste::query()->pluck('nome')->all());

        $this->assertSame(['a1'], $nomes);
    }

    public function test_find_de_um_id_de_outro_tenant_devolve_null(): void
    {
        $deB = $this->contexto->executarComo($this->tenantB, fn () => ItemDeTeste::create(['nome' => 'b1']));

        $encontrado = $this->contexto->executarComo($this->tenantA, fn () => ItemDeTeste::find($deB->id));

        $this->assertNull($encontrado);
    }

    public function test_leitura_sem_tenant_lanca_excepcao(): void
    {
        $this->expectException(TenantNaoResolvido::class);

        ItemDeTeste::query()->get();
    }

    public function test_criacao_sem_tenant_lanca_excepcao(): void
    {
        $this->expectException(TenantNaoResolvido::class);

        ItemDeTeste::create(['nome' => 'orfao']);
    }

    public function test_criacao_com_tenant_id_de_outro_tenant_lanca_excepcao(): void
    {
        $this->expectException(AlteracaoDeTenantProibida::class);

        $this->contexto->executarComo($this->tenantA, function () {
            $item = new ItemDeTeste(['nome' => 'forjado']);
            $item->tenant_id = 102;
            $item->save();
        });
    }

    public function test_alterar_o_tenant_id_lanca_excepcao(): void
    {
        $this->expectException(AlteracaoDeTenantProibida::class);

        $this->contexto->executarComo($this->tenantA, function () {
            $item = ItemDeTeste::create(['nome' => 'a1']);
            $item->tenant_id = 102;
            $item->save();
        });
    }
}
```

- [ ] **Step 2: Correr o teste e confirmar que falha**

Run: `php artisan test --filter=PertenceAoTenantTest`
Expected: FAIL — `Trait "Modules\Core\Tenancy\PertenceAoTenant" not found`.

- [ ] **Step 3: Implementar**

`Modules/Core/app/Tenancy/Exceptions/AlteracaoDeTenantProibida.php`:

```php
<?php

namespace Modules\Core\Tenancy\Exceptions;

use LogicException;

class AlteracaoDeTenantProibida extends LogicException
{
    public function __construct(string $model)
    {
        parent::__construct("O tenant de um registo de {$model} não pode ser definido à mão nem alterado.");
    }
}
```

`Modules/Core/app/Tenancy/TenantScope.php`:

```php
<?php

namespace Modules\Core\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    /**
     * Sem tenant resolvido, TenantContext::id() lança TenantNaoResolvido:
     * a consulta falha em vez de devolver tudo ou nada.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->qualifyColumn('tenant_id'), app(TenantContext::class)->id());
    }
}
```

`Modules/Core/app/Tenancy/PertenceAoTenant.php`:

```php
<?php

namespace Modules\Core\Tenancy;

use Modules\Core\Tenancy\Exceptions\AlteracaoDeTenantProibida;

trait PertenceAoTenant
{
    protected static function bootPertenceAoTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function ($model) {
            $tenantId = app(TenantContext::class)->id();

            if ($model->tenant_id !== null && (int) $model->tenant_id !== $tenantId) {
                throw new AlteracaoDeTenantProibida($model::class);
            }

            $model->tenant_id = $tenantId;
        });

        static::updating(function ($model) {
            if ($model->isDirty('tenant_id')) {
                throw new AlteracaoDeTenantProibida($model::class);
            }
        });
    }
}
```

- [ ] **Step 4: Correr o teste e confirmar que passa**

Run: `php artisan test --filter=PertenceAoTenantTest`
Expected: PASS, 7 testes.

- [ ] **Step 5: Correr a suite completa**

Run: `php artisan test`
Expected: PASS.

- [ ] **Step 6: Stage (sem commit)**

```bash
git add Modules/Core/app/Tenancy Modules/Core/tests/Feature/Tenancy
```

---

### Task 3: Módulo Tenant — tabelas e models

**Files:**
- Create: `Modules/Tenant/module.json`
- Create: `Modules/Tenant/composer.json`
- Create: `Modules/Tenant/app/Providers/TenantServiceProvider.php`
- Create: `Modules/Tenant/app/Enums/TipoDominio.php`
- Create: `Modules/Tenant/app/Models/Tenant.php`
- Create: `Modules/Tenant/app/Models/Domain.php`
- Create: `Modules/Tenant/database/migrations/2026_01_01_000000_create_tenants_table.php`
- Create: `Modules/Tenant/database/migrations/2026_01_01_000001_create_domains_table.php`
- Create: `Modules/Core/app/Tenancy/Support/NormalizadorHost.php`
- Modify: `modules_statuses.json`
- Test: `Modules/Core/tests/Unit/Tenancy/NormalizadorHostTest.php`
- Test: `Modules/Tenant/tests/Feature/TenantModelTest.php`

**Interfaces:**
- Consumes: `EstadoTenant`, `TenantAtual` — Tarefa 1.
- Produces:
  - `Modules\Core\Tenancy\Support\NormalizadorHost::normalizar(string $host): string`.
  - `Modules\Tenant\Models\Tenant` — `$fillable`: `codigo`, `nome`, `estado`, `suspenso_em`, `motivo_suspensao`, `encerrado_em`; `dominios(): HasMany`; `paraTenantAtual(): TenantAtual`. `estado` é `EstadoTenant`, por omissão `ACTIVO`.
  - `Modules\Tenant\Models\Domain` — tabela `domains`; `$fillable`: `dominio`, `tipo`, `is_principal`; `tenant(): BelongsTo`. `tipo` é `TipoDominio`, por omissão `SUBDOMINIO`.
  - `Modules\Tenant\Enums\TipoDominio` — `SUBDOMINIO = 0`, `PERSONALIZADO = 1`; `label(): string`.
  - As migrations têm data `2026_01_01` para correrem antes de todas as outras tabelas de módulos (a mais antiga é `2026_03_31`).

- [ ] **Step 1: Escrever os testes**

`Modules/Core/tests/Unit/Tenancy/NormalizadorHostTest.php`:

```php
<?php

namespace Modules\Core\Tests\Unit\Tenancy;

use Modules\Core\Tenancy\Support\NormalizadorHost;
use PHPUnit\Framework\TestCase;

class NormalizadorHostTest extends TestCase
{
    public function test_passa_a_minusculas(): void
    {
        $this->assertSame('escola-a.mositec.ao', NormalizadorHost::normalizar('Escola-A.MosiTec.AO'));
    }

    public function test_remove_a_porta(): void
    {
        $this->assertSame('escola-a.localhost', NormalizadorHost::normalizar('escola-a.localhost:8000'));
    }

    public function test_remove_o_ponto_final(): void
    {
        $this->assertSame('escola-a.mositec.ao', NormalizadorHost::normalizar('escola-a.mositec.ao.'));
    }

    public function test_remove_espacos_e_combina_tudo(): void
    {
        $this->assertSame('escola-a.localhost', NormalizadorHost::normalizar('  Escola-A.Localhost.:8000 '));
    }

    public function test_mantem_um_endereco_ip(): void
    {
        $this->assertSame('192.168.1.10', NormalizadorHost::normalizar('192.168.1.10:8080'));
    }
}
```

`Modules/Tenant/tests/Feature/TenantModelTest.php`:

```php
<?php

namespace Modules\Tenant\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Tenant\Enums\TipoDominio;
use Modules\Tenant\Models\Tenant;
use Tests\TestCase;

class TenantModelTest extends TestCase
{
    use RefreshDatabase;

    private function novoTenant(string $codigo = 'MOSI-000900'): Tenant
    {
        return Tenant::create(['codigo' => $codigo, 'nome' => 'Colégio São José']);
    }

    public function test_tabelas_tem_as_colunas_esperadas(): void
    {
        $this->assertTrue(Schema::hasColumns('tenants', [
            'id', 'codigo', 'nome', 'estado', 'estado_descricao',
            'suspenso_em', 'motivo_suspensao', 'encerrado_em', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('domains', [
            'id', 'tenant_id', 'dominio', 'tipo', 'tipo_descricao', 'is_principal', 'created_at', 'updated_at',
        ]));
    }

    public function test_tenant_nasce_activo_com_descricao_sincronizada(): void
    {
        $tenant = $this->novoTenant()->fresh();

        $this->assertSame(EstadoTenant::ACTIVO, $tenant->estado);
        $this->assertSame('Activo', $tenant->estado_descricao);
    }

    public function test_mudar_o_estado_actualiza_a_descricao(): void
    {
        $tenant = $this->novoTenant();
        $tenant->update(['estado' => EstadoTenant::SUSPENSO]);

        $this->assertSame('Suspenso', $tenant->fresh()->estado_descricao);
    }

    public function test_codigo_e_unico(): void
    {
        $this->novoTenant('MOSI-000900');

        $this->expectException(QueryException::class);
        $this->novoTenant('MOSI-000900');
    }

    public function test_codigo_e_imutavel(): void
    {
        $tenant = $this->novoTenant();

        $this->expectException(LogicException::class);
        $tenant->update(['codigo' => 'MOSI-000901']);
    }

    public function test_para_tenant_atual(): void
    {
        $tenant = $this->novoTenant();
        $atual = $tenant->paraTenantAtual();

        $this->assertSame($tenant->id, $atual->id);
        $this->assertSame('MOSI-000900', $atual->codigo);
        $this->assertSame('Colégio São José', $atual->nome);
        $this->assertSame(EstadoTenant::ACTIVO, $atual->estado);
    }

    public function test_dominio_e_guardado_normalizado_com_tipo_por_omissao(): void
    {
        $dominio = $this->novoTenant()->dominios()->create(['dominio' => 'Colegio-SJ.MosiTec.AO:443'])->fresh();

        $this->assertSame('colegio-sj.mositec.ao', $dominio->dominio);
        $this->assertSame(TipoDominio::SUBDOMINIO, $dominio->tipo);
        $this->assertSame('Subdomínio', $dominio->tipo_descricao);
        $this->assertFalse($dominio->is_principal);
    }

    public function test_dominio_e_unico_entre_tenants(): void
    {
        $this->novoTenant('MOSI-000900')->dominios()->create(['dominio' => 'igual.mositec.ao']);

        $this->expectException(QueryException::class);
        $this->novoTenant('MOSI-000901')->dominios()->create(['dominio' => 'IGUAL.mositec.ao']);
    }

    public function test_so_pode_haver_um_dominio_principal_por_tenant(): void
    {
        $tenant = $this->novoTenant();
        $tenant->dominios()->create(['dominio' => 'um.mositec.ao', 'is_principal' => true]);
        $tenant->dominios()->create(['dominio' => 'dois.mositec.ao', 'is_principal' => false]);

        $this->expectException(QueryException::class);
        $tenant->dominios()->create(['dominio' => 'tres.mositec.ao', 'is_principal' => true]);
    }

    public function test_tenants_diferentes_podem_ter_cada_um_o_seu_principal(): void
    {
        $this->novoTenant('MOSI-000900')->dominios()->create(['dominio' => 'a.mositec.ao', 'is_principal' => true]);
        $this->novoTenant('MOSI-000901')->dominios()->create(['dominio' => 'b.mositec.ao', 'is_principal' => true]);

        $this->assertDatabaseCount('domains', 2);
    }
}
```

- [ ] **Step 2: Correr os testes e confirmar que falham**

Run: `php artisan test --filter='NormalizadorHostTest|TenantModelTest'`
Expected: FAIL — classes não encontradas.

- [ ] **Step 3: Criar o esqueleto do módulo**

`Modules/Tenant/module.json`:

```json
{
    "name": "Tenant",
    "alias": "tenant",
    "description": "Gestão de Tenants: identidade, domínios, ciclo de vida e provisioning.",
    "keywords": [],
    "priority": 0,
    "providers": [
        "Modules\\Tenant\\Providers\\TenantServiceProvider"
    ],
    "files": []
}
```

`Modules/Tenant/composer.json`:

```json
{
    "name": "nwidart/tenant",
    "description": "",
    "extra": {
        "laravel": {
            "providers": [],
            "aliases": {}
        }
    },
    "autoload": {
        "psr-4": {
            "Modules\\Tenant\\": "app/",
            "Modules\\Tenant\\Database\\Factories\\": "database/factories/",
            "Modules\\Tenant\\Database\\Seeders\\": "database/seeders/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Modules\\Tenant\\Tests\\": "tests/"
        }
    }
}
```

`Modules/Tenant/app/Providers/TenantServiceProvider.php`:

```php
<?php

namespace Modules\Tenant\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class TenantServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Tenant';

    protected string $nameLower = 'tenant';
}
```

Em `modules_statuses.json`, acrescentar a entrada (um módulo que falte aqui não carrega e não dá erro):

```json
    "Matricula": true,
    "Tenant": true
```

- [ ] **Step 4: Criar o normalizador, o enum, as migrations e os models**

`Modules/Core/app/Tenancy/Support/NormalizadorHost.php`:

```php
<?php

namespace Modules\Core\Tenancy\Support;

class NormalizadorHost
{
    /**
     * Minúsculas, sem espaços, sem porta e sem ponto final.
     */
    public static function normalizar(string $host): string
    {
        $host = mb_strtolower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host);

        return rtrim($host, '.');
    }
}
```

`Modules/Tenant/app/Enums/TipoDominio.php`:

```php
<?php

namespace Modules\Tenant\Enums;

enum TipoDominio: int
{
    case SUBDOMINIO = 0;
    case PERSONALIZADO = 1;

    public function label(): string
    {
        return match ($this) {
            self::SUBDOMINIO => 'Subdomínio',
            self::PERSONALIZADO => 'Domínio personalizado',
        };
    }
}
```

`Modules/Tenant/database/migrations/2026_01_01_000000_create_tenants_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->unique();
            $table->string('nome');
            $table->integer('estado')->default(1); // 1: Activo, 2: Suspenso, 3: Encerrado
            $table->string('estado_descricao')->default('Activo');
            $table->timestamp('suspenso_em')->nullable();
            $table->string('motivo_suspensao')->nullable();
            $table->timestamp('encerrado_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
```

`Modules/Tenant/database/migrations/2026_01_01_000001_create_domains_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('dominio')->unique();
            $table->integer('tipo')->default(0); // 0: Subdomínio, 1: Domínio personalizado
            $table->string('tipo_descricao')->default('Subdomínio');
            $table->boolean('is_principal')->default(false);
            $table->timestamps();
        });

        // Índice único parcial: no máximo um domínio principal por tenant.
        // O Blueprint não tem API para índices parciais; a sintaxe é comum a PostgreSQL e SQLite.
        DB::statement('CREATE UNIQUE INDEX domains_tenant_principal_unique ON domains (tenant_id) WHERE is_principal = true');
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
```

`Modules/Tenant/app/Models/Tenant.php`:

```php
<?php

namespace Modules\Tenant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Core\Tenancy\TenantAtual;

/**
 * Registo de gestão do tenant. Não usa PertenceAoTenant: é o próprio tenant.
 * Os módulos da escola nunca usam este model; conhecem apenas TenantAtual.
 */
class Tenant extends Model
{
    protected $table = 'tenants';

    protected $fillable = [
        'codigo',
        'nome',
        'estado',
        'suspenso_em',
        'motivo_suspensao',
        'encerrado_em',
    ];

    protected $attributes = [
        'estado' => 1,
    ];

    protected $casts = [
        'estado' => EstadoTenant::class,
        'suspenso_em' => 'datetime',
        'encerrado_em' => 'datetime',
    ];

    public function dominios(): HasMany
    {
        return $this->hasMany(Domain::class, 'tenant_id');
    }

    public function paraTenantAtual(): TenantAtual
    {
        return new TenantAtual($this->id, $this->codigo, $this->nome, $this->estado);
    }

    protected static function booted(): void
    {
        static::saving(function (Tenant $tenant) {
            $tenant->estado_descricao = $tenant->estado->label();
        });

        static::updating(function (Tenant $tenant) {
            if ($tenant->isDirty('codigo')) {
                throw new LogicException('O código do tenant é imutável.');
            }
        });
    }
}
```

`Modules/Tenant/app/Models/Domain.php`:

```php
<?php

namespace Modules\Tenant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\Support\NormalizadorHost;
use Modules\Tenant\Enums\TipoDominio;

/**
 * Endereço pelo qual se chega a um tenant. Identifica o tenant, mas não é a identidade dele.
 * Não usa PertenceAoTenant: é lido antes de haver tenant resolvido.
 */
class Domain extends Model
{
    protected $table = 'domains';

    protected $fillable = [
        'dominio',
        'tipo',
        'is_principal',
    ];

    protected $attributes = [
        'tipo' => 0,
        'is_principal' => false,
    ];

    protected $casts = [
        'tipo' => TipoDominio::class,
        'is_principal' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    protected static function booted(): void
    {
        static::saving(function (Domain $dominio) {
            $dominio->dominio = NormalizadorHost::normalizar($dominio->dominio);
            $dominio->tipo_descricao = $dominio->tipo->label();
        });
    }
}
```

- [ ] **Step 5: Regenerar o autoload**

Run: `composer dump-autoload`
Expected: termina sem erros. (O `composer.json` do módulo é lido pelo merge-plugin; sem este passo, `Modules\Tenant\Models\Tenant` não é encontrado.)

- [ ] **Step 6: Correr os testes e confirmar que passam**

Run: `php artisan test --filter='NormalizadorHostTest|TenantModelTest'`
Expected: PASS, 15 testes.

- [ ] **Step 7: Correr a suite completa**

Run: `php artisan test`
Expected: PASS.

- [ ] **Step 8: Stage (sem commit)**

```bash
git add Modules/Tenant modules_statuses.json Modules/Core/app/Tenancy/Support Modules/Core/tests/Unit/Tenancy/NormalizadorHostTest.php
```

---

### Task 4: Configuração e resolvedores

**Files:**
- Create: `config/tenancy.php`
- Create: `Modules/Core/app/Tenancy/Contracts/ResolvedorTenant.php`
- Create: `Modules/Tenant/app/Exceptions/InstalacaoUnicaInvalida.php`
- Create: `Modules/Tenant/app/Services/ResolvedorTenantPorDominio.php`
- Create: `Modules/Tenant/app/Services/ResolvedorTenantUnico.php`
- Modify: `Modules/Tenant/app/Providers/TenantServiceProvider.php`
- Modify: `.env.example`
- Modify: `phpunit.xml`
- Test: `Modules/Tenant/tests/Feature/ResolvedorTenantTest.php`

**Interfaces:**
- Consumes: `Tenant`, `Domain`, `NormalizadorHost` — Tarefa 3; `TenantAtual` — Tarefa 1.
- Produces:
  - `Modules\Core\Tenancy\Contracts\ResolvedorTenant` — `resolver(Request $request): ?TenantAtual`. Devolve `null` quando o pedido não corresponde a nenhum tenant. Não avalia o estado do tenant.
  - Ligação no container: `ResolvedorTenant::class` → implementação conforme `config('tenancy.modo')`, avaliada a cada resolução.
  - `config('tenancy.modo')` (`'dominio'` | `'unico'`), `config('tenancy.hosts_centrais')` (array), `config('tenancy.subdominios_reservados')` (array), `config('tenancy.tabelas_globais')`, `config('tenancy.tabelas_infraestrutura')`, `config('tenancy.tabelas_por_converter')` (arrays de nomes de tabelas).

- [ ] **Step 1: Escrever o teste**

`Modules/Tenant/tests/Feature/ResolvedorTenantTest.php`:

```php
<?php

namespace Modules\Tenant\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Modules\Core\Tenancy\Contracts\ResolvedorTenant;
use Modules\Tenant\Exceptions\InstalacaoUnicaInvalida;
use Modules\Tenant\Models\Domain;
use Modules\Tenant\Models\Tenant;
use Modules\Tenant\Services\ResolvedorTenantPorDominio;
use Modules\Tenant\Services\ResolvedorTenantUnico;
use Tests\TestCase;

class ResolvedorTenantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Estes testes controlam exactamente que tenants existem.
        Domain::query()->delete();
        Tenant::query()->delete();
    }

    private function tenantComDominio(string $codigo, string $dominio): Tenant
    {
        $tenant = Tenant::create(['codigo' => $codigo, 'nome' => "Escola {$codigo}"]);
        $tenant->dominios()->create(['dominio' => $dominio, 'is_principal' => true]);

        return $tenant;
    }

    private function pedido(string $url): Request
    {
        return Request::create($url);
    }

    public function test_por_dominio_resolve_o_tenant_do_host(): void
    {
        $this->tenantComDominio('MOSI-000801', 'escola-a.localhost');
        $b = $this->tenantComDominio('MOSI-000802', 'escola-b.localhost');

        $atual = app(ResolvedorTenantPorDominio::class)->resolver($this->pedido('http://escola-b.localhost/painel'));

        $this->assertSame($b->id, $atual->id);
        $this->assertSame('MOSI-000802', $atual->codigo);
    }

    public function test_por_dominio_ignora_maiusculas_porta_e_ponto_final(): void
    {
        $a = $this->tenantComDominio('MOSI-000801', 'escola-a.localhost');
        $pedido = $this->pedido('http://localhost/painel');
        $pedido->headers->set('HOST', 'Escola-A.Localhost.:8000');

        $atual = app(ResolvedorTenantPorDominio::class)->resolver($pedido);

        $this->assertSame($a->id, $atual->id);
    }

    public function test_por_dominio_resolve_por_um_dominio_secundario(): void
    {
        $a = $this->tenantComDominio('MOSI-000801', 'escola-a.localhost');
        $a->dominios()->create(['dominio' => 'gestao.escola-a.ao']);

        $atual = app(ResolvedorTenantPorDominio::class)->resolver($this->pedido('http://gestao.escola-a.ao/'));

        $this->assertSame($a->id, $atual->id);
    }

    public function test_por_dominio_devolve_null_para_host_desconhecido(): void
    {
        $this->tenantComDominio('MOSI-000801', 'escola-a.localhost');

        $this->assertNull(app(ResolvedorTenantPorDominio::class)->resolver($this->pedido('http://outra.localhost/')));
    }

    public function test_unico_devolve_o_unico_tenant_ignorando_o_host(): void
    {
        $a = $this->tenantComDominio('MOSI-000801', 'escola-a.localhost');

        $atual = app(ResolvedorTenantUnico::class)->resolver($this->pedido('http://192.168.1.10:8080/'));

        $this->assertSame($a->id, $atual->id);
    }

    public function test_unico_sem_tenants_lanca_excepcao(): void
    {
        $this->expectException(InstalacaoUnicaInvalida::class);
        $this->expectExceptionMessage('0');

        app(ResolvedorTenantUnico::class)->resolver($this->pedido('http://localhost/'));
    }

    public function test_unico_com_dois_tenants_lanca_excepcao(): void
    {
        $this->tenantComDominio('MOSI-000801', 'escola-a.localhost');
        $this->tenantComDominio('MOSI-000802', 'escola-b.localhost');

        $this->expectException(InstalacaoUnicaInvalida::class);
        $this->expectExceptionMessage('2');

        app(ResolvedorTenantUnico::class)->resolver($this->pedido('http://localhost/'));
    }

    public function test_o_container_escolhe_o_resolvedor_pelo_modo_configurado(): void
    {
        config(['tenancy.modo' => 'dominio']);
        $this->assertInstanceOf(ResolvedorTenantPorDominio::class, app(ResolvedorTenant::class));

        config(['tenancy.modo' => 'unico']);
        $this->assertInstanceOf(ResolvedorTenantUnico::class, app(ResolvedorTenant::class));
    }

    public function test_modo_desconhecido_lanca_excepcao_explicita(): void
    {
        config(['tenancy.modo' => 'subdominio']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('subdominio');

        app(ResolvedorTenant::class);
    }
}
```

- [ ] **Step 2: Correr o teste e confirmar que falha**

Run: `php artisan test --filter=ResolvedorTenantTest`
Expected: FAIL — `Interface "Modules\Core\Tenancy\Contracts\ResolvedorTenant" not found`.

- [ ] **Step 3: Criar a configuração**

`config/tenancy.php`:

```php
<?php

return [

    /*
    | Como o tenant é resolvido em cada pedido:
    |   dominio  → pelo host do pedido, na tabela domains (instalação partilhada, cloud, desenvolvimento)
    |   unico    → o único tenant da instalação, ignorando o host (instalação local ou dedicada)
    */
    'modo' => env('TENANCY_MODO', 'dominio'),

    /*
    | Hosts que não pertencem a nenhum tenant. Os pedidos seguem sem tenant resolvido.
    | Lista separada por vírgulas.
    */
    'hosts_centrais' => array_values(array_filter(array_map('trim', explode(',', (string) env('TENANCY_HOSTS_CENTRAIS', ''))))),

    /*
    | Subdomínios que nenhum tenant pode registar.
    */
    'subdominios_reservados' => ['www', 'api', 'admin', 'plataforma', 'mail', 'app'],

    /*
    | Tabelas sem tenant_id por desenho: catálogo do produto e gestão de tenants.
    | As regras exists/unique sobre estas tabelas não são filtradas por tenant.
    | Qualquer tabela que NÃO esteja numa das três listas abaixo é tratada como tenant-scoped.
    */
    'tabelas_globais' => [
        'tenants',
        'domains',
        'modulos',
        'acoes',
        'licencas', // legado; ver spec §18
    ],

    /*
    | Tabelas do framework.
    */
    'tabelas_infraestrutura' => [
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'sessions',
        'migrations',
    ],

    /*
    | LISTA DE TRANSIÇÃO. Tabelas de tenant que ainda não receberam tenant_id.
    | Cada plano seguinte retira daqui as tabelas que converte.
    | O último plano exige que esteja vazia e remove esta chave.
    */
    'tabelas_por_converter' => [
        'aluno_enquadramentos_academicos',
        'alunos',
        'ano_lectivos',
        'cursos',
        'dados_pessoas',
        'disciplinas',
        'documentos_pessoas',
        'encarregados_alunos',
        'estabelecimento_etapas_ensino',
        'estabelecimentos',
        'eventos_calendario',
        'horarios',
        'inscricoes_disciplinas',
        'matricula_historicos',
        'matricula_registo_sequencias',
        'matricula_sequencias',
        'matriculas',
        'niveis_academicos',
        'password_reset_tokens',
        'periodos',
        'personal_access_tokens',
        'plano_curricular_anos_lectivos',
        'plano_curricular_disciplina_periodos',
        'plano_curricular_disciplinas',
        'planos_curriculares',
        'role_permissoes',
        'roles',
        'salas',
        'tipos_documentos',
        'turma_salas',
        'turmas',
        'turno_horarios',
        'turnos',
        'user_permissoes',
        'user_roles',
        'users',
    ],
];
```

Em `.env.example`, acrescentar a seguir à linha `APP_URL=http://localhost`:

```dotenv

# dominio: tenant resolvido pelo host | unico: instalação com um só tenant
TENANCY_MODO=dominio
# Hosts sem tenant, separados por vírgulas (ex.: plataforma.mositec.ao)
TENANCY_HOSTS_CENTRAIS=
```

Em `phpunit.xml`, dentro de `<php>`, a seguir a `<env name="SESSION_DRIVER" value="array"/>`:

```xml
        <env name="TENANCY_MODO" value="dominio"/>
        <env name="TENANCY_HOSTS_CENTRAIS" value=""/>
```

- [ ] **Step 4: Implementar o contrato, os resolvedores e a ligação**

`Modules/Core/app/Tenancy/Contracts/ResolvedorTenant.php`:

```php
<?php

namespace Modules\Core\Tenancy\Contracts;

use Illuminate\Http\Request;
use Modules\Core\Tenancy\TenantAtual;

interface ResolvedorTenant
{
    /**
     * Devolve o tenant a que o pedido se destina, ou null se não corresponder a nenhum.
     * Não avalia o estado do tenant: isso é responsabilidade do middleware.
     */
    public function resolver(Request $request): ?TenantAtual;
}
```

`Modules/Tenant/app/Exceptions/InstalacaoUnicaInvalida.php`:

```php
<?php

namespace Modules\Tenant\Exceptions;

use RuntimeException;

class InstalacaoUnicaInvalida extends RuntimeException
{
    public function __construct(int $quantidade)
    {
        parent::__construct("TENANCY_MODO=unico exige exactamente 1 tenant nesta instalação; existem {$quantidade}.");
    }
}
```

`Modules/Tenant/app/Services/ResolvedorTenantPorDominio.php`:

```php
<?php

namespace Modules\Tenant\Services;

use Illuminate\Http\Request;
use Modules\Core\Tenancy\Contracts\ResolvedorTenant;
use Modules\Core\Tenancy\Support\NormalizadorHost;
use Modules\Core\Tenancy\TenantAtual;
use Modules\Tenant\Models\Domain;

class ResolvedorTenantPorDominio implements ResolvedorTenant
{
    public function resolver(Request $request): ?TenantAtual
    {
        $host = NormalizadorHost::normalizar($request->getHost());

        $dominio = Domain::query()->with('tenant')->where('dominio', $host)->first();

        return $dominio?->tenant->paraTenantAtual();
    }
}
```

`Modules/Tenant/app/Services/ResolvedorTenantUnico.php`:

```php
<?php

namespace Modules\Tenant\Services;

use Illuminate\Http\Request;
use Modules\Core\Tenancy\Contracts\ResolvedorTenant;
use Modules\Core\Tenancy\TenantAtual;
use Modules\Tenant\Exceptions\InstalacaoUnicaInvalida;
use Modules\Tenant\Models\Tenant;

/**
 * Instalação local ou dedicada: acedida por IP ou nome de rede, por isso o host é ignorado.
 */
class ResolvedorTenantUnico implements ResolvedorTenant
{
    public function resolver(Request $request): ?TenantAtual
    {
        $tenants = Tenant::query()->limit(2)->get();

        if ($tenants->count() !== 1) {
            throw new InstalacaoUnicaInvalida(Tenant::query()->count());
        }

        return $tenants->first()->paraTenantAtual();
    }
}
```

Substituir `Modules/Tenant/app/Providers/TenantServiceProvider.php` por:

```php
<?php

namespace Modules\Tenant\Providers;

use InvalidArgumentException;
use Modules\Core\Tenancy\Contracts\ResolvedorTenant;
use Modules\Tenant\Services\ResolvedorTenantPorDominio;
use Modules\Tenant\Services\ResolvedorTenantUnico;
use Nwidart\Modules\Support\ModuleServiceProvider;

class TenantServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Tenant';

    protected string $nameLower = 'tenant';

    public function register(): void
    {
        parent::register();

        // bind (e não singleton): o modo é lido a cada resolução.
        $this->app->bind(ResolvedorTenant::class, function ($app) {
            $modo = config('tenancy.modo');

            return match ($modo) {
                'dominio' => $app->make(ResolvedorTenantPorDominio::class),
                'unico' => $app->make(ResolvedorTenantUnico::class),
                default => throw new InvalidArgumentException("Modo de tenancy desconhecido: '{$modo}'. Use 'dominio' ou 'unico'."),
            };
        });
    }
}
```

- [ ] **Step 5: Correr o teste e confirmar que passa**

Run: `php artisan test --filter=ResolvedorTenantTest`
Expected: PASS, 9 testes.

- [ ] **Step 6: Correr a suite completa**

Run: `php artisan test`
Expected: PASS.

- [ ] **Step 7: Stage (sem commit)**

```bash
git add config/tenancy.php .env.example phpunit.xml Modules/Core/app/Tenancy/Contracts Modules/Tenant
```

---

### Task 5: Middleware ResolverTenant, base de testes e tenant de desenvolvimento

A partir desta tarefa, todos os pedidos `web` e `api` exigem um tenant. Por isso, a mesma tarefa entrega o middleware, o tenant por omissão dos testes e o tenant de desenvolvimento: separados, deixariam a suite ou a aplicação local partidas.

**Files:**
- Create: `Modules/Core/app/Tenancy/Http/Middleware/ResolverTenant.php`
- Modify: `bootstrap/app.php`
- Create: `tests/Concerns/ComTenantDeTeste.php`
- Modify: `tests/TestCase.php`
- Create: `Modules/Tenant/database/seeders/TenantDesenvolvimentoSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Test: `Modules/Core/tests/Feature/Tenancy/ResolverTenantMiddlewareTest.php`

**Interfaces:**
- Consumes: `ResolvedorTenant` — Tarefa 4; `TenantContext`, `TenantAtual`, `EstadoTenant` — Tarefa 1; `NormalizadorHost`, `Tenant` — Tarefa 3.
- Produces:
  - `Modules\Core\Tenancy\Http\Middleware\ResolverTenant`, registado à cabeça dos grupos `web` e `api` e antes de `StartSession` na lista de prioridades.
  - Respostas: host desconhecido → 404; tenant `ENCERRADO` → 404; tenant `SUSPENSO` → 403 com a mensagem `Conta suspensa.`; host central → segue sem tenant.
  - No fim do pedido, o contexto volta ao estado em que estava antes do pedido.
  - Em todos os testes que usam base de dados: `$this->tenant` (`Modules\Tenant\Models\Tenant`, código `MOSI-000001`, domínio `localhost`) e o `TenantContext` já definido para ele.
  - Auxiliares de teste: `criarTenant(string $codigo, string $nome, string $dominio): Tenant`, `noTenant(Tenant $tenant, Closure $fn): mixed`, `urlDoTenant(Tenant $tenant, string $caminho = '/'): string`.

- [ ] **Step 1: Escrever o teste**

`Modules/Core/tests/Feature/Tenancy/ResolverTenantMiddlewareTest.php`:

```php
<?php

namespace Modules\Core\Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Core\Tenancy\TenantContext;
use Tests\TestCase;

class ResolverTenantMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $responder = fn () => app(TenantContext::class)->temTenant()
            ? app(TenantContext::class)->atual()->codigo
            : 'sem-tenant';

        Route::middleware('web')->get('/_tenancy/web', $responder);
        Route::middleware('api')->get('/_tenancy/api', $responder);
    }

    public function test_a_base_de_testes_cria_um_tenant_por_omissao_e_define_o_contexto(): void
    {
        $this->assertSame('MOSI-000001', $this->tenant->codigo);
        $this->assertSame($this->tenant->id, app(TenantContext::class)->id());
    }

    public function test_pedido_web_no_dominio_do_tenant_corre_dentro_desse_tenant(): void
    {
        $this->get('/_tenancy/web')->assertOk()->assertSee('MOSI-000001');
    }

    public function test_pedido_api_tambem_e_resolvido(): void
    {
        $this->getJson('/_tenancy/api')->assertOk()->assertSee('MOSI-000001');
    }

    public function test_cada_dominio_resolve_o_seu_tenant(): void
    {
        $b = $this->criarTenant('MOSI-000002', 'Escola B', 'escola-b.localhost');

        $this->get($this->urlDoTenant($b, '/_tenancy/web'))->assertOk()->assertSee('MOSI-000002');
        $this->get('/_tenancy/web')->assertOk()->assertSee('MOSI-000001');
    }

    public function test_host_desconhecido_da_404(): void
    {
        $this->get('http://nao-existe.localhost/_tenancy/web')->assertNotFound();
        $this->getJson('http://nao-existe.localhost/_tenancy/api')->assertNotFound();
    }

    public function test_tenant_suspenso_da_403(): void
    {
        $this->tenant->update(['estado' => EstadoTenant::SUSPENSO]);

        $this->get('/_tenancy/web')->assertForbidden();
        $this->getJson('/_tenancy/api')->assertForbidden()->assertJsonFragment(['message' => 'Conta suspensa.']);
    }

    public function test_tenant_encerrado_da_404(): void
    {
        $this->tenant->update(['estado' => EstadoTenant::ENCERRADO]);

        $this->get('/_tenancy/web')->assertNotFound();
    }

    public function test_host_central_segue_sem_tenant(): void
    {
        config(['tenancy.hosts_centrais' => ['plataforma.localhost']]);

        $this->get('http://plataforma.localhost/_tenancy/web')->assertOk()->assertSee('sem-tenant');
    }

    public function test_depois_do_pedido_o_contexto_volta_ao_que_estava(): void
    {
        $b = $this->criarTenant('MOSI-000002', 'Escola B', 'escola-b.localhost');

        $this->get($this->urlDoTenant($b, '/_tenancy/web'))->assertSee('MOSI-000002');
        $this->assertSame($this->tenant->id, app(TenantContext::class)->id());

        $this->get('http://nao-existe.localhost/_tenancy/web')->assertNotFound();
        $this->assertSame($this->tenant->id, app(TenantContext::class)->id());
    }

    public function test_sem_contexto_anterior_o_pedido_nao_deixa_tenant_definido(): void
    {
        app(TenantContext::class)->limpar();

        $this->get('/_tenancy/web')->assertSee('MOSI-000001');

        $this->assertFalse(app(TenantContext::class)->temTenant());
    }

    public function test_modo_unico_ignora_o_host(): void
    {
        config(['tenancy.modo' => 'unico']);

        $this->get('http://192.168.1.10/_tenancy/web')->assertOk()->assertSee('MOSI-000001');
    }

    public function test_no_tenant_executa_no_contexto_indicado(): void
    {
        $b = $this->criarTenant('MOSI-000002', 'Escola B', 'escola-b.localhost');

        $codigo = $this->noTenant($b, fn () => app(TenantContext::class)->atual()->codigo);

        $this->assertSame('MOSI-000002', $codigo);
        $this->assertSame($this->tenant->id, app(TenantContext::class)->id());
    }
}
```

- [ ] **Step 2: Correr o teste e confirmar que falha**

Run: `php artisan test --filter=ResolverTenantMiddlewareTest`
Expected: FAIL — `Undefined property: ...::$tenant` (a base de testes ainda não cria o tenant).

- [ ] **Step 3: Implementar o middleware**

`Modules/Core/app/Tenancy/Http/Middleware/ResolverTenant.php`:

```php
<?php

namespace Modules\Core\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Tenancy\Contracts\ResolvedorTenant;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Core\Tenancy\Support\NormalizadorHost;
use Modules\Core\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\Response;

class ResolverTenant
{
    private const CONTEXTO_ANTERIOR = 'tenancy.contexto_anterior';

    public function __construct(
        private TenantContext $contexto,
        private ResolvedorTenant $resolvedor,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // O Laravel cria outra instância deste middleware para o terminate(),
        // por isso o contexto anterior viaja no próprio pedido.
        $request->attributes->set(
            self::CONTEXTO_ANTERIOR,
            $this->contexto->temTenant() ? $this->contexto->atual() : null,
        );

        $this->contexto->limpar();

        if ($this->hostCentral($request)) {
            return $next($request);
        }

        $tenant = $this->resolvedor->resolver($request);

        // Host desconhecido e tenant encerrado respondem da mesma forma,
        // para não revelar que domínios existem.
        if ($tenant === null || $tenant->estado === EstadoTenant::ENCERRADO) {
            abort(404);
        }

        if ($tenant->estado === EstadoTenant::SUSPENSO) {
            abort(403, 'Conta suspensa.');
        }

        $this->contexto->definir($tenant);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! $request->attributes->has(self::CONTEXTO_ANTERIOR)) {
            return;
        }

        $anterior = $request->attributes->get(self::CONTEXTO_ANTERIOR);

        $anterior === null ? $this->contexto->limpar() : $this->contexto->definir($anterior);
    }

    private function hostCentral(Request $request): bool
    {
        return in_array(
            NormalizadorHost::normalizar($request->getHost()),
            config('tenancy.hosts_centrais', []),
            true,
        );
    }
}
```

- [ ] **Step 4: Registar o middleware**

Em `bootstrap/app.php`, acrescentar os imports no topo:

```php
use Illuminate\Session\Middleware\StartSession;
use Modules\Core\Tenancy\Http\Middleware\ResolverTenant;
```

e substituir o corpo de `withMiddleware` por:

```php
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(
            append: [
                \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
                'throttle:api',
                \Illuminate\Routing\Middleware\SubstituteBindings::class,
            ],
            prepend: [
                ResolverTenant::class,
            ],
        );
        $middleware->web(
            append: [
                \App\Http\Middleware\HandleInertiaRequests::class,
            ],
            prepend: [
                ResolverTenant::class,
            ],
        );

        // O tenant tem de estar resolvido antes da sessão e da autenticação.
        $middleware->prependToPriorityList(
            before: StartSession::class,
            prepend: ResolverTenant::class,
        );
    })
```

(As três classes que já estavam escritas com nome totalmente qualificado ficam como estavam: não fazem parte desta alteração.)

- [ ] **Step 5: Criar a base de testes**

`tests/Concerns/ComTenantDeTeste.php`:

```php
<?php

namespace Tests\Concerns;

use Closure;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Tenancy\TenantContext;
use Modules\Tenant\Models\Tenant;

trait ComTenantDeTeste
{
    /** Tenant por omissão de cada teste com base de dados. Domínio: localhost. */
    protected ?Tenant $tenant = null;

    protected function prepararTenantDeTeste(): void
    {
        // Testes sem base de dados (sem RefreshDatabase) não têm tabelas.
        if (! Schema::hasTable('tenants')) {
            return;
        }

        $this->tenant = $this->criarTenant('MOSI-000001', 'Escola de Teste', 'localhost');

        app(TenantContext::class)->definir($this->tenant->paraTenantAtual());
    }

    protected function criarTenant(string $codigo, string $nome, string $dominio): Tenant
    {
        $tenant = Tenant::create(['codigo' => $codigo, 'nome' => $nome]);
        $tenant->dominios()->create(['dominio' => $dominio, 'is_principal' => true]);

        return $tenant;
    }

    protected function noTenant(Tenant $tenant, Closure $fn): mixed
    {
        return app(TenantContext::class)->executarComo($tenant->paraTenantAtual(), $fn);
    }

    protected function urlDoTenant(Tenant $tenant, string $caminho = '/'): string
    {
        $dominio = $tenant->dominios()->where('is_principal', true)->value('dominio');

        return 'http://' . $dominio . '/' . ltrim($caminho, '/');
    }
}
```

Substituir `tests/TestCase.php` por:

```php
<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\ComTenantDeTeste;

abstract class TestCase extends BaseTestCase
{
    use ComTenantDeTeste;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepararTenantDeTeste();
    }
}
```

- [ ] **Step 6: Correr o teste do middleware e confirmar que passa**

Run: `php artisan test --filter=ResolverTenantMiddlewareTest`
Expected: PASS, 12 testes.

- [ ] **Step 7: Correr a suite completa**

Run: `php artisan test`
Expected: PASS. Os pedidos HTTP dos testes existentes usam o host `localhost`, que é o domínio do tenant por omissão.

Se algum teste existente falhar com `no such table: domains`, é um teste que faz pedidos HTTP sem `RefreshDatabase`: acrescentar-lhe `use RefreshDatabase;`. Se falhar com 404 num pedido, o teste usa um host diferente de `localhost`: passar a construir o URL com `$this->urlDoTenant($this->tenant, '/caminho')`.

- [ ] **Step 8: Criar o tenant de desenvolvimento**

Sem isto, a aplicação local responde 404 a tudo depois de `migrate:fresh --seed`.

`Modules/Tenant/database/seeders/TenantDesenvolvimentoSeeder.php`:

```php
<?php

namespace Modules\Tenant\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Tenant\Models\Tenant;

/**
 * Tenant para desenvolvimento local, acessível em http://localhost:8000 e http://127.0.0.1:8000.
 * Provisório: é substituído pelo comando mosi:tenant:create quando o provisioning existir.
 */
class TenantDesenvolvimentoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::firstOrCreate(
            ['codigo' => 'MOSI-000001'],
            ['nome' => 'Escola de Desenvolvimento'],
        );

        $tenant->dominios()->firstOrCreate(['dominio' => 'localhost'], ['is_principal' => true]);
        $tenant->dominios()->firstOrCreate(['dominio' => '127.0.0.1']);
    }
}
```

Em `database/seeders/DatabaseSeeder.php`, acrescentar o import:

```php
use Modules\Tenant\Database\Seeders\TenantDesenvolvimentoSeeder;
```

e pôr o seeder em primeiro lugar na chamada existente:

```php
        $this->call([
            TenantDesenvolvimentoSeeder::class,
            PermissaoDatabaseSeeder::class,
            AdminUserSeeder::class,
```

- [ ] **Step 9: Verificar o seeder e a aplicação local**

**Parar e pedir confirmação ao dono do repositório antes deste passo:** `migrate:fresh` apaga toda a BD de desenvolvimento. Foi dito que não há dados a preservar, mas a ordem de apagar é dele.

Run: `composer dump-autoload && php artisan migrate:fresh --seed`
Expected: termina sem erros; a saída inclui `Modules\Tenant\Database\Seeders\TenantDesenvolvimentoSeeder`.

Run: `php artisan serve` e abrir `http://localhost:8000/login`
Expected: a página de login aparece. Em `http://nao-existe.localhost:8000/login` a resposta é 404.

- [ ] **Step 10: Stage (sem commit)**

```bash
git add Modules/Core/app/Tenancy/Http Modules/Core/tests/Feature/Tenancy/ResolverTenantMiddlewareTest.php bootstrap/app.php tests/TestCase.php tests/Concerns Modules/Tenant/database/seeders database/seeders/DatabaseSeeder.php
```

---

### Task 6: Verificador de presença ciente do tenant

**Files:**
- Create: `Modules/Core/app/Tenancy/Validation/VerificadorPresencaTenant.php`
- Modify: `Modules/Core/app/Providers/CoreServiceProvider.php`
- Test: `Modules/Core/tests/Feature/Tenancy/VerificadorPresencaTenantTest.php`

**Interfaces:**
- Consumes: `TenantContext` — Tarefa 1; `config('tenancy.tabelas_globais')`, `config('tenancy.tabelas_infraestrutura')`, `config('tenancy.tabelas_por_converter')` — Tarefa 4; `$this->tenant`, `criarTenant()`, `noTenant()` — Tarefa 5.
- Produces:
  - A chave `validation.presence` do container passa a devolver `Modules\Core\Tenancy\Validation\VerificadorPresencaTenant`.
  - Efeito: as regras `exists` e `unique` sobre uma tabela que não esteja em nenhuma das três listas são filtradas por `tenant_id = <tenant corrente>` e lançam `TenantNaoResolvido` sem contexto. As tabelas das três listas não são filtradas.

- [ ] **Step 1: Escrever o teste**

`Modules/Core/tests/Feature/Tenancy/VerificadorPresencaTenantTest.php`:

```php
<?php

namespace Modules\Core\Tests\Feature\Tenancy;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;
use Modules\Core\Tenancy\TenantContext;
use Modules\Core\Tenancy\Validation\VerificadorPresencaTenant;
use Modules\Tenant\Models\Tenant;
use Tests\TestCase;

class VerificadorPresencaTenantTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantB;

    private int $idDeA;

    private int $idDeB;

    protected function setUp(): void
    {
        parent::setUp();

        // Tabela de tenant: não consta em nenhuma lista de config/tenancy.php.
        Schema::dropIfExists('tenancy_teste_itens');
        Schema::create('tenancy_teste_itens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('codigo');
            $table->string('grupo')->nullable();
        });

        // Tabela global: sem tenant_id, declarada em tabelas_globais.
        Schema::dropIfExists('tenancy_teste_globais');
        Schema::create('tenancy_teste_globais', function (Blueprint $table) {
            $table->id();
            $table->string('codigo');
        });
        config(['tenancy.tabelas_globais' => [...config('tenancy.tabelas_globais'), 'tenancy_teste_globais']]);

        $this->tenantB = $this->criarTenant('MOSI-000002', 'Escola B', 'escola-b.localhost');

        $this->idDeA = DB::table('tenancy_teste_itens')->insertGetId(['tenant_id' => $this->tenant->id, 'codigo' => 'A-1', 'grupo' => 'x']);
        $this->idDeB = DB::table('tenancy_teste_itens')->insertGetId(['tenant_id' => $this->tenantB->id, 'codigo' => 'B-1', 'grupo' => 'x']);
        DB::table('tenancy_teste_globais')->insert(['codigo' => 'G-1']);
    }

    private function passa(array $dados, array $regras): bool
    {
        return Validator::make($dados, $regras)->passes();
    }

    public function test_o_container_usa_o_verificador_de_tenancy(): void
    {
        $this->assertInstanceOf(VerificadorPresencaTenant::class, app('validation.presence'));
    }

    public function test_exists_aceita_um_id_do_tenant_corrente(): void
    {
        $this->assertTrue($this->passa(['id' => $this->idDeA], ['id' => 'exists:tenancy_teste_itens,id']));
    }

    public function test_exists_rejeita_um_id_de_outro_tenant(): void
    {
        $this->assertFalse($this->passa(['id' => $this->idDeB], ['id' => 'exists:tenancy_teste_itens,id']));
    }

    public function test_exists_segue_o_contexto_e_nao_um_tenant_fixo(): void
    {
        $passaEmB = $this->noTenant($this->tenantB, fn () => $this->passa(['id' => $this->idDeB], ['id' => 'exists:tenancy_teste_itens,id']));

        $this->assertTrue($passaEmB);
    }

    public function test_exists_com_lista_que_mistura_tenants_falha(): void
    {
        $regras = ['ids' => 'array', 'ids.*' => 'exists:tenancy_teste_itens,id'];

        $this->assertFalse($this->passa(['ids' => [$this->idDeA, $this->idDeB]], $regras));
        $this->assertTrue($this->passa(['ids' => [$this->idDeA]], $regras));
    }

    public function test_exists_com_array_de_valores_num_so_campo_conta_so_os_do_tenant(): void
    {
        // Um campo cujo valor é um array faz o Laravel usar getMultiCount().
        $regras = ['ids' => 'exists:tenancy_teste_itens,id'];

        $this->assertFalse($this->passa(['ids' => [$this->idDeA, $this->idDeB]], $regras));
        $this->assertTrue($this->passa(['ids' => [$this->idDeA]], $regras));
    }

    public function test_unique_permite_o_mesmo_valor_noutro_tenant(): void
    {
        $this->assertTrue($this->passa(['codigo' => 'B-1'], ['codigo' => 'unique:tenancy_teste_itens,codigo']));
    }

    public function test_unique_rejeita_duplicado_no_mesmo_tenant(): void
    {
        $this->assertFalse($this->passa(['codigo' => 'A-1'], ['codigo' => 'unique:tenancy_teste_itens,codigo']));
    }

    public function test_unique_com_ignore_continua_a_funcionar(): void
    {
        $regra = Rule::unique('tenancy_teste_itens', 'codigo')->ignore($this->idDeA);

        $this->assertTrue($this->passa(['codigo' => 'A-1'], ['codigo' => $regra]));
    }

    public function test_condicoes_adicionais_continuam_a_funcionar(): void
    {
        $noGrupoX = Rule::unique('tenancy_teste_itens', 'codigo')->where('grupo', 'x');
        $noGrupoY = Rule::unique('tenancy_teste_itens', 'codigo')->where('grupo', 'y');

        $this->assertFalse($this->passa(['codigo' => 'A-1'], ['codigo' => $noGrupoX]));
        $this->assertTrue($this->passa(['codigo' => 'A-1'], ['codigo' => $noGrupoY]));
    }

    public function test_tabela_global_nao_e_filtrada(): void
    {
        $this->assertTrue($this->passa(['codigo' => 'G-1'], ['codigo' => 'exists:tenancy_teste_globais,codigo']));
    }

    public function test_tabela_global_funciona_sem_tenant(): void
    {
        app(TenantContext::class)->limpar();

        $this->assertTrue($this->passa(['codigo' => 'G-1'], ['codigo' => 'exists:tenancy_teste_globais,codigo']));
    }

    public function test_tabela_de_tenant_sem_contexto_lanca_excepcao(): void
    {
        app(TenantContext::class)->limpar();

        $this->expectException(TenantNaoResolvido::class);

        $this->passa(['id' => $this->idDeA], ['id' => 'exists:tenancy_teste_itens,id']);
    }

    public function test_tabela_por_converter_nao_e_filtrada(): void
    {
        // users ainda não tem tenant_id: filtrar daria erro de coluna inexistente.
        $this->assertTrue($this->passa(['email' => 'livre@exemplo.ao'], ['email' => 'unique:users,email']));
    }
}
```

- [ ] **Step 2: Correr o teste e confirmar que falha**

Run: `php artisan test --filter=VerificadorPresencaTenantTest`
Expected: FAIL — `Class "Modules\Core\Tenancy\Validation\VerificadorPresencaTenant" not found`.

- [ ] **Step 3: Implementar**

`Modules/Core/app/Tenancy/Validation/VerificadorPresencaTenant.php`:

```php
<?php

namespace Modules\Core\Tenancy\Validation;

use Illuminate\Validation\DatabasePresenceVerifier;
use Modules\Core\Tenancy\TenantContext;

/**
 * As regras exists e unique consultam a tabela pelo query builder, sem passar
 * pelo Eloquent, por isso ignoram o TenantScope. Este verificador aplica a
 * mesma regra nesse caminho: ler uma tabela de tenant filtra sempre pelo tenant.
 *
 * Uma tabela que não esteja em nenhuma lista de config/tenancy.php é tratada
 * como tabela de tenant: o comportamento por omissão é o seguro.
 */
class VerificadorPresencaTenant extends DatabasePresenceVerifier
{
    protected function table($table)
    {
        $query = parent::table($table);

        if ($this->semFiltro($table)) {
            return $query;
        }

        // TenantContext::id() lança TenantNaoResolvido se não houver tenant.
        return $query->where($table . '.tenant_id', app(TenantContext::class)->id());
    }

    private function semFiltro(string $tabela): bool
    {
        $nome = str_contains($tabela, '.') ? substr($tabela, strrpos($tabela, '.') + 1) : $tabela;

        return in_array($nome, [
            ...config('tenancy.tabelas_globais', []),
            ...config('tenancy.tabelas_infraestrutura', []),
            ...config('tenancy.tabelas_por_converter', []),
        ], true);
    }
}
```

Em `Modules/Core/app/Providers/CoreServiceProvider.php`, acrescentar o import:

```php
use Modules\Core\Tenancy\Validation\VerificadorPresencaTenant;
```

e completar o método `register`:

```php
    public function register(): void
    {
        parent::register();

        $this->app->scoped(TenantContext::class);

        // O TenantContext é resolvido dentro do verificador a cada consulta,
        // porque o verificador é singleton e o contexto tem âmbito de pedido.
        $this->app->extend('validation.presence', fn ($verificador, $app) => new VerificadorPresencaTenant($app['db']));
    }
```

- [ ] **Step 4: Correr o teste e confirmar que passa**

Run: `php artisan test --filter=VerificadorPresencaTenantTest`
Expected: PASS, 14 testes.

- [ ] **Step 5: Correr a suite completa**

Run: `php artisan test`
Expected: PASS. Todas as tabelas actuais estão em `tabelas_por_converter` ou `tabelas_globais`, por isso as cerca de 70 regras existentes comportam-se como antes.

- [ ] **Step 6: Stage (sem commit)**

```bash
git add Modules/Core/app/Tenancy/Validation Modules/Core/app/Providers/CoreServiceProvider.php Modules/Core/tests/Feature/Tenancy/VerificadorPresencaTenantTest.php
```

---

### Task 7: Testes de arquitectura e de esquema

Estes testes passam logo à primeira: o código das tarefas anteriores já cumpre as regras. O valor deles é falharem no futuro. Por isso, cada um é verificado introduzindo de propósito uma violação.

**Files:**
- Create: `tests/Feature/Arquitectura/TenancyArquitecturaTest.php`
- Create: `tests/Feature/Arquitectura/TenancyEsquemaTest.php`

**Interfaces:**
- Consumes: `config('tenancy.*')` — Tarefa 4; `PertenceAoTenant` — Tarefa 2.
- Produces: regras que os planos seguintes têm de respeitar:
  - Cada tabela ou está em exactamente uma das três listas de `config/tenancy.php`, ou tem `tenant_id`.
  - Uma tabela em `tabelas_por_converter` não tem `tenant_id` (quando o ganha, sai da lista).
  - Todo o model em `Modules/*/app/Models` cuja tabela é de tenant usa `PertenceAoTenant`.
  - Listas de excepções temporárias, a esvaziar pelos planos seguintes: `EXCEPCOES_DB_TABLE` e `EXCEPCOES_CACHE` em `TenancyArquitecturaTest`.

- [ ] **Step 1: Escrever o teste de arquitectura**

`tests/Feature/Arquitectura/TenancyArquitecturaTest.php`:

```php
<?php

namespace Tests\Feature\Arquitectura;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Regras de dependência e de isolamento verificadas por leitura dos ficheiros.
 * Só analisa código de aplicação (app/ e Modules/<Modulo>/app); os testes ficam de fora.
 */
class TenancyArquitecturaTest extends TestCase
{
    /**
     * Temporário. O plano das sequências substitui estes dois geradores por um
     * gerador único no Core, que passa a ser a única entrada desta lista.
     */
    private const EXCEPCOES_DB_TABLE = [
        'Modules/Usuario/app/Services/GeradorMatriculaService.php',
        'Modules/Matricula/app/Services/GeradorNumeroRegistoMatriculaService.php',
    ];

    /**
     * Temporário. O plano de identidade e permissões passa o PermissaoCache
     * para CacheTenant e esvazia esta lista.
     */
    private const EXCEPCOES_CACHE = [
        'Modules/Permissao/app/Support/PermissaoCache.php',
    ];

    private function raiz(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string, string> caminho relativo => conteúdo */
    private function codigoDeAplicacao(): array
    {
        $pastas = [$this->raiz() . '/app', ...glob($this->raiz() . '/Modules/*/app')];
        $ficheiros = [];

        foreach ($pastas as $pasta) {
            $iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pasta, FilesystemIterator::SKIP_DOTS));

            foreach ($iterador as $ficheiro) {
                if ($ficheiro->isFile() && $ficheiro->getExtension() === 'php') {
                    $relativo = str_replace($this->raiz() . '/', '', $ficheiro->getPathname());
                    $ficheiros[$relativo] = file_get_contents($ficheiro->getPathname());
                }
            }
        }

        return $ficheiros;
    }

    /**
     * @param  string[]  $prefixosPermitidos
     * @return string[] caminhos onde o padrão aparece fora dos prefixos permitidos
     */
    private function ocorrencias(string $padrao, array $prefixosPermitidos): array
    {
        $encontrados = [];

        foreach ($this->codigoDeAplicacao() as $caminho => $conteudo) {
            foreach ($prefixosPermitidos as $prefixo) {
                if (str_starts_with($caminho, $prefixo)) {
                    continue 2;
                }
            }

            if (preg_match($padrao, $conteudo) === 1) {
                $encontrados[] = $caminho;
            }
        }

        return $encontrados;
    }

    public function test_ha_codigo_de_aplicacao_para_analisar(): void
    {
        $caminhos = array_keys($this->codigoDeAplicacao());

        $this->assertContains('Modules/Core/app/Tenancy/TenantContext.php', $caminhos);
        $this->assertContains('Modules/Tenant/app/Models/Tenant.php', $caminhos);
    }

    public function test_so_o_modulo_tenant_conhece_o_modulo_tenant(): void
    {
        $violacoes = $this->ocorrencias('/Modules\\\\Tenant\\\\/', ['Modules/Tenant/']);

        $this->assertSame([], $violacoes, "Estes ficheiros importam Modules\\Tenant. Os módulos da escola e o Core só podem conhecer TenantAtual e TenantContext:\n" . implode("\n", $violacoes));
    }

    public function test_ninguem_remove_os_global_scopes(): void
    {
        $violacoes = $this->ocorrencias('/withoutGlobalScopes?\s*\(/', ['Modules/Core/app/Tenancy/']);

        $this->assertSame([], $violacoes, "withoutGlobalScope(s) remove o isolamento por tenant:\n" . implode("\n", $violacoes));
    }

    public function test_o_tenant_scope_so_e_referido_no_runtime_de_tenancy(): void
    {
        $violacoes = $this->ocorrencias('/\bTenantScope\b/', ['Modules/Core/app/Tenancy/']);

        $this->assertSame([], $violacoes, "TenantScope só é usado pela trait PertenceAoTenant:\n" . implode("\n", $violacoes));
    }

    public function test_db_table_so_e_usado_nas_excepcoes_declaradas(): void
    {
        $violacoes = $this->ocorrencias('/\bDB::table\s*\(/', self::EXCEPCOES_DB_TABLE);

        $this->assertSame([], $violacoes, "DB::table() ignora o isolamento por tenant. Use o model (com PertenceAoTenant):\n" . implode("\n", $violacoes));
    }

    public function test_a_cache_so_e_usada_nas_excepcoes_declaradas(): void
    {
        $violacoes = $this->ocorrencias('/\bCache::|\bcache\s*\(/', ['Modules/Core/app/Tenancy/', ...self::EXCEPCOES_CACHE]);

        $this->assertSame([], $violacoes, "A cache partilhada não é isolada por tenant:\n" . implode("\n", $violacoes));
    }

    public function test_as_excepcoes_declaradas_ainda_existem(): void
    {
        foreach ([...self::EXCEPCOES_DB_TABLE, ...self::EXCEPCOES_CACHE] as $caminho) {
            $this->assertFileExists($this->raiz() . '/' . $caminho, "Excepção obsoleta em TenancyArquitecturaTest: {$caminho}");
        }
    }
}
```

- [ ] **Step 2: Escrever o teste de esquema**

`tests/Feature/Arquitectura/TenancyEsquemaTest.php`:

```php
<?php

namespace Tests\Feature\Arquitectura;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Tenancy\PertenceAoTenant;
use ReflectionClass;
use Tests\TestCase;

class TenancyEsquemaTest extends TestCase
{
    use RefreshDatabase;

    /** @return string[] */
    private function tabelas(): array
    {
        return array_column(Schema::getTables(), 'name');
    }

    public function test_cada_tabela_esta_classificada_exactamente_uma_vez(): void
    {
        $globais = config('tenancy.tabelas_globais');
        $infra = config('tenancy.tabelas_infraestrutura');
        $porConverter = config('tenancy.tabelas_por_converter');
        $erros = [];

        $this->assertNotEmpty($this->tabelas());

        foreach ($this->tabelas() as $tabela) {
            $listas = (int) in_array($tabela, $globais, true)
                + (int) in_array($tabela, $infra, true)
                + (int) in_array($tabela, $porConverter, true);
            $temTenantId = Schema::hasColumn($tabela, 'tenant_id');

            if ($listas > 1) {
                $erros[] = "{$tabela}: consta em mais de uma lista de config/tenancy.php.";
            } elseif ($listas === 0 && ! $temTenantId) {
                $erros[] = "{$tabela}: não tem tenant_id nem consta em nenhuma lista de config/tenancy.php.";
            } elseif (in_array($tabela, $porConverter, true) && $temTenantId) {
                $erros[] = "{$tabela}: já tem tenant_id; retire-a de tabelas_por_converter.";
            }
        }

        $this->assertSame([], $erros, implode("\n", $erros));
    }

    public function test_as_listas_nao_tem_tabelas_que_ja_nao_existem(): void
    {
        $declaradas = [
            ...config('tenancy.tabelas_globais'),
            ...config('tenancy.tabelas_infraestrutura'),
            ...config('tenancy.tabelas_por_converter'),
        ];

        $inexistentes = array_values(array_diff($declaradas, $this->tabelas()));

        $this->assertSame([], $inexistentes, 'Tabelas declaradas em config/tenancy.php que não existem: ' . implode(', ', $inexistentes));
    }

    public function test_todo_o_model_de_uma_tabela_de_tenant_usa_a_trait(): void
    {
        $semFiltro = [
            ...config('tenancy.tabelas_globais'),
            ...config('tenancy.tabelas_infraestrutura'),
            ...config('tenancy.tabelas_por_converter'),
        ];
        $erros = [];
        $analisados = 0;

        foreach (glob(base_path('Modules/*/app/Models/*.php')) as $ficheiro) {
            $modulo = basename(dirname($ficheiro, 3));
            $classe = "Modules\\{$modulo}\\Models\\" . basename($ficheiro, '.php');

            if (! class_exists($classe) || ! is_subclass_of($classe, Model::class) || (new ReflectionClass($classe))->isAbstract()) {
                continue;
            }

            $analisados++;
            $tabela = (new $classe())->getTable();
            $usaTrait = in_array(PertenceAoTenant::class, class_uses_recursive($classe), true);
            $eDeTenant = ! in_array($tabela, $semFiltro, true);

            if ($eDeTenant && ! $usaTrait) {
                $erros[] = "{$classe} (tabela {$tabela}) é de tenant e não usa PertenceAoTenant.";
            }

            if (! $eDeTenant && $usaTrait) {
                $erros[] = "{$classe} (tabela {$tabela}) usa PertenceAoTenant, mas a tabela não é de tenant.";
            }
        }

        $this->assertGreaterThan(30, $analisados, 'Não foram encontrados os models dos módulos.');
        $this->assertSame([], $erros, implode("\n", $erros));
    }
}
```

- [ ] **Step 3: Correr os testes**

Run: `php artisan test --filter='TenancyArquitecturaTest|TenancyEsquemaTest'`
Expected: PASS, 10 testes.

Se `test_cada_tabela_esta_classificada_exactamente_uma_vez` falhar, a mensagem indica a tabela: acrescentá-la à lista certa em `config/tenancy.php` (`tabelas_por_converter` se for dados de escola, `tabelas_infraestrutura` se for do framework).

- [ ] **Step 4: Provar que os testes detectam violações**

Cada verificação é feita, confirmada e desfeita antes da seguinte.

1. Em `Modules/Curso/app/Actions/CriarCursoAction.php`, acrescentar no topo `use Modules\Tenant\Models\Tenant;`.
   Run: `php artisan test --filter=test_so_o_modulo_tenant_conhece_o_modulo_tenant`
   Expected: FAIL, a mensagem lista `Modules/Curso/app/Actions/CriarCursoAction.php`.
   Desfazer: `git checkout Modules/Curso/app/Actions/CriarCursoAction.php`

2. Em `config/tenancy.php`, retirar `'salas',` de `tabelas_por_converter`.
   Run: `php artisan test --filter=TenancyEsquemaTest`
   Expected: FAIL com `salas: não tem tenant_id nem consta em nenhuma lista` e com `Modules\Infraestrutura\Models\Sala (tabela salas) é de tenant e não usa PertenceAoTenant`.
   Desfazer: repor a linha `'salas',`.

- [ ] **Step 5: Correr a suite completa**

Run: `php artisan test`
Expected: PASS.

Run: `git status --short`
Expected: só aparecem os dois ficheiros de teste novos desta tarefa (mais o que já estava em stage); nenhuma alteração em `CriarCursoAction.php` nem em `config/tenancy.php` em relação ao stage.

- [ ] **Step 6: Stage (sem commit)**

```bash
git add tests/Feature/Arquitectura
```

---

## Estado no fim deste plano

- A aplicação corre sempre dentro de um tenant resolvido por domínio ou em modo único.
- O mecanismo de isolamento (contexto, trait, scope, verificador) existe e está testado com tabelas de teste.
- **Nenhuma tabela real está ainda isolada.** As 36 tabelas de dados de escola estão em `tabelas_por_converter`.
- Os testes de arquitectura e de esquema obrigam os planos seguintes a converter cada tabela de forma completa: coluna, trait e saída da lista.

## Planos seguintes

Cada um parte do estado deixado pelo anterior e é escrito depois de o anterior estar concluído, com os ficheiros reais à frente.

| Plano | Etapas do spec (§20.2) | Conteúdo |
|---|---|---|
| 2 | 4 e 5 | Estabelecimento (`tenant_id` único, `current()` pelo contexto, `configurado_em`); Usuario, Permissao e Autenticacao (tabelas, únicos, pivots, login, sessão, Sanctum, recuperação de palavra-passe, limitador, `CacheTenant`) |
| 3 | 6 | Módulos académicos, por ordem de dependência, com chaves estrangeiras compostas |
| 4 | 7 e 8 | Gerador único de sequências; ficheiros com prefixo de tenant; fotos de alunos em disco privado |
| 5 | 9 a 12 | Provisioning modular, `CriarTenantAction`, `mosi:tenant:create`, ciclo de vida, Jobs e Commands, bateria final de isolamento, remoção de `tabelas_por_converter` |

## Desvios na execução (2026-09-30)

O código acima foi executado com estas diferenças:

1. **`phpunit.xml` fixa `APP_URL=http://localhost`.** Os pedidos de teste usam o host de `APP_URL`; o `.env` local aponta para `mositec-escola.test`, e o tenant por omissão dos testes (domínio `localhost`) não resolvia.
2. **`test_cada_dominio_resolve_o_seu_tenant` usa `urlDoTenant()` nos dois pedidos.** Depois de um pedido com URL absoluto, os caminhos relativos do mesmo teste herdam o host desse pedido. O trait `ComTenantDeTeste` documenta isto.
3. **`TenantModelTest::test_tenants_diferentes_podem_ter_cada_um_o_seu_principal`** conta só os dois domínios que cria, porque a base de testes já tem o domínio do tenant por omissão.
4. **`TenantDesenvolvimentoSeeder` regista também o host de `APP_URL`**, e ganhou o teste `TenantDesenvolvimentoSeederTest`.
5. **Tarefa 5, passo 9 (`migrate:fresh --seed`)** foi executado depois de confirmado pelo dono do repositório.

## Correcções após a revisão independente (2026-09-30)

1. **`ResolverTenant` passou a middleware global** (`$middleware->append`), com `tenancy.caminhos_sem_tenant => ['up']`. Nos grupos, o Sanctum punha `EnsureFrontendRequestsAreStateful` à frente dele nas rotas `api`. Passa também a cobrir `storage/{path}`.
2. **A trait verifica contexto e dono em `updating`, `deleting` e `restoring`.** `save()` e `delete()` de uma instância não passam pelo global scope.
3. **`TenantDesenvolvimentoSeeder` só corre em `local` e `testing`.**
4. **Teste de esquema:** uma tabela com `tenant_id` numa lista sem filtro é erro (excepção explícita: `domains`); os models são procurados também em `app/Models` e em subpastas; `tenant_id` preenchível em massa é erro.
5. **Testes de arquitectura:** apanham também `withoutGlobalScopesExcept`, `newQueryWithoutScope(s)`, `newModelQuery`, as restantes consultas pela fachada `DB`, e `->definir(`/`->limpar(` do `TenantContext` fora do runtime; analisam também rotas e seeders. `database/seeders/DatabaseSeeder.php` é a única excepção à regra de não referir `Modules\Tenant`.
