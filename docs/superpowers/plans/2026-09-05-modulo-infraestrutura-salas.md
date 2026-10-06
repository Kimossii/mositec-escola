# Módulo Infraestrutura → Salas Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implementar o backend completo do módulo `Infraestrutura` com a entidade `Sala` (Model, migration, Enums, DTO, Actions, Form Requests, Controller, rotas Inertia, permissões e testes).

**Architecture:** Novo módulo Laravel `Modules\Infraestrutura` (scaffold nwidart/laravel-modules), seguindo o padrão já estabelecido em `Modules\AnoLectivo`. Uma única entidade nesta fase (`Sala`), autorizada por uma permissão de módulo partilhada (`infraestrutura.*`) já ligada ao mecanismo real `Gate::before`/`PermissionResolver` de `Modules\Permissao` — sem Policy classes, sem tabela de autorização paralela.

**Tech Stack:** Laravel + nwidart/laravel-modules, Eloquent, Inertia (só `render`, sem páginas Vue nesta fase), PHPUnit com `RefreshDatabase`.

**Spec:** `docs/superpowers/specs/2026-09-05-modulo-infraestrutura-salas-design.md`

## Global Constraints

- `estabelecimento_id` é sempre `foreignId(...)->nullable()->constrained('estabelecimentos')->nullOnDelete()`, preenchido pela Action a partir de `Estabelecimento::current()?->id` — nunca `NOT NULL`.
- `Sala` usa `SoftDeletes` — eliminar nunca apaga a linha fisicamente.
- Constraint `unique(['estabelecimento_id', 'codigo'])` na tabela `salas`.
- `estado` por omissão é `EstadoSala::ATIVA` (valor `0`).
- `tipo`/`estado` sincronizam `tipo_descricao`/`estado_descricao` via `booted()` manual no Model (não o trait `SincronizaEstadoDescricao`, que está fixo a `Core\Enums\Estado`).
- Autorização exclusivamente via permissão `infraestrutura.{ver|criar|editar|eliminar}` — nunca `sala.*`, nunca uma Policy class nova (nenhum módulo actual tem uma).
- `RegistaAutoria` é o trait já existente em `Modules\Core\Traits\RegistaAutoria` — reutilizado tal e qual, sem cópia nova.
- Nenhuma Action de `Sala` usa `DB::transaction`/`lockForUpdate` — não há invariante de concorrência (ao contrário do "único Ano Lectivo activo").
- `EliminarSalaAction` não verifica dependentes nesta fase — não existe hoje nenhuma tabela com `sala_id`.

---

## Task 1: Scaffold do módulo + migration `salas`

**Files:**
- Create: `Modules/Infraestrutura/module.json`
- Create: `Modules/Infraestrutura/composer.json`
- Create: `Modules/Infraestrutura/config/config.php`
- Create: `Modules/Infraestrutura/app/Providers/InfraestruturaServiceProvider.php`
- Create: `Modules/Infraestrutura/app/Providers/RouteServiceProvider.php`
- Create: `Modules/Infraestrutura/app/Providers/EventServiceProvider.php`
- Create: `Modules/Infraestrutura/routes/web.php`
- Create: `Modules/Infraestrutura/routes/api.php`
- Modify: `modules_statuses.json`
- Create: `Modules/Infraestrutura/database/migrations/2026_09_05_100000_create_salas_table.php`
- Test: `Modules/Infraestrutura/tests/Feature/SalaMigrationTest.php`

**Interfaces:**
- Produces: tabela `salas` com colunas `id, estabelecimento_id, codigo, nome, tipo, tipo_descricao, capacidade, localizacao, observacoes, estado, estado_descricao, criado_por, editado_por, created_at, updated_at, deleted_at`; namespace raiz `Modules\Infraestrutura\*` disponível para todas as tasks seguintes.

- [ ] **Step 1: Criar o scaffold do módulo**

`Modules/Infraestrutura/module.json`:

```json
{
    "name": "Infraestrutura",
    "alias": "infraestrutura",
    "description": "",
    "keywords": [],
    "priority": 0,
    "providers": [
        "Modules\\Infraestrutura\\Providers\\InfraestruturaServiceProvider"
    ],
    "files": []
}
```

`Modules/Infraestrutura/composer.json`:

```json
{
    "name": "nwidart/infraestrutura",
    "description": "",
    "authors": [
        {
            "name": "Nicolas Widart",
            "email": "n.widart@gmail.com"
        }
    ],
    "extra": {
        "laravel": {
            "providers": [],
            "aliases": {

            }
        }
    },
    "autoload": {
        "psr-4": {
            "Modules\\Infraestrutura\\": "app/",
            "Modules\\Infraestrutura\\Database\\Factories\\": "database/factories/",
            "Modules\\Infraestrutura\\Database\\Seeders\\": "database/seeders/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Modules\\Infraestrutura\\Tests\\": "tests/"
        }
    }
}
```

`Modules/Infraestrutura/config/config.php`:

```php
<?php

return [
    'name' => 'Infraestrutura',
];
```

`Modules/Infraestrutura/app/Providers/InfraestruturaServiceProvider.php`:

```php
<?php

namespace Modules\Infraestrutura\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class InfraestruturaServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Infraestrutura';

    protected string $nameLower = 'infraestrutura';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
    }
}
```

`Modules/Infraestrutura/app/Providers/RouteServiceProvider.php`:

```php
<?php

namespace Modules\Infraestrutura\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    protected string $name = 'Infraestrutura';

    public function boot(): void
    {
        parent::boot();
    }

    public function map(): void
    {
        $this->mapApiRoutes();
        $this->mapWebRoutes();
    }

    protected function mapWebRoutes(): void
    {
        Route::middleware('web')->group(module_path($this->name, '/routes/web.php'));
    }

    protected function mapApiRoutes(): void
    {
        Route::middleware('api')->prefix('api')->name('api.')->group(module_path($this->name, '/routes/api.php'));
    }
}
```

`Modules/Infraestrutura/app/Providers/EventServiceProvider.php`:

```php
<?php

namespace Modules\Infraestrutura\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [];

    protected static $shouldDiscoverEvents = true;
}
```

`Modules/Infraestrutura/routes/web.php` (rotas reais só na Task 10):

```php
<?php

use Illuminate\Support\Facades\Route;
```

`Modules/Infraestrutura/routes/api.php`:

```php
<?php

use Illuminate\Support\Facades\Route;
```

Editar `modules_statuses.json` (na raiz do projecto) — adicionar `"Infraestrutura": true` mantendo as entradas existentes:

```json
{
    "Usuario": true,
    "Autenticacao": true,
    "Permissao": true,
    "Estabelecimento": true,
    "Core": true,
    "AnoLectivo": true,
    "Infraestrutura": true
}
```

- [ ] **Step 2: Registar o autoload do módulo**

Run: `composer dump-autoload`
Expected: termina sem erros; `Modules\Infraestrutura\` passa a resolver para `Modules/Infraestrutura/app/`.

- [ ] **Step 3: Escrever o teste da migration (vai falhar)**

`Modules/Infraestrutura/tests/Feature/SalaMigrationTest.php`:

```php
<?php

namespace Modules\Infraestrutura\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SalaMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tabela_salas_tem_as_colunas_esperadas(): void
    {
        $this->assertTrue(Schema::hasTable('salas'));
        $this->assertTrue(Schema::hasColumns('salas', [
            'id',
            'estabelecimento_id',
            'codigo',
            'nome',
            'tipo',
            'tipo_descricao',
            'capacidade',
            'localizacao',
            'observacoes',
            'estado',
            'estado_descricao',
            'criado_por',
            'editado_por',
            'created_at',
            'updated_at',
            'deleted_at',
        ]));
    }
}
```

- [ ] **Step 4: Confirmar que o teste falha**

Run: `php artisan test Modules/Infraestrutura/tests/Feature/SalaMigrationTest.php`
Expected: FAIL — tabela `salas` não existe.

- [ ] **Step 5: Criar a migration**

`Modules/Infraestrutura/database/migrations/2026_09_05_100000_create_salas_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estabelecimento_id')->nullable()->constrained('estabelecimentos')->nullOnDelete();
            $table->string('codigo', 20);
            $table->string('nome');
            $table->unsignedTinyInteger('tipo'); // 0 sala_aula | 1 laboratorio | 2 biblioteca | 3 auditorio | 4 ginasio | 5 sala_professores | 6 gabinete_administrativo | 7 outro
            $table->string('tipo_descricao');
            $table->unsignedSmallInteger('capacidade')->nullable();
            $table->string('localizacao')->nullable();
            $table->text('observacoes')->nullable();
            $table->unsignedTinyInteger('estado')->default(0); // 0 ativa | 1 manutencao | 2 inativa
            $table->string('estado_descricao')->default('Ativa');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['estabelecimento_id', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salas');
    }
};
```

- [ ] **Step 6: Confirmar que o teste passa**

Run: `php artisan test Modules/Infraestrutura/tests/Feature/SalaMigrationTest.php`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add Modules/Infraestrutura/module.json Modules/Infraestrutura/composer.json Modules/Infraestrutura/config/config.php Modules/Infraestrutura/app/Providers Modules/Infraestrutura/routes Modules/Infraestrutura/database/migrations Modules/Infraestrutura/tests/Feature/SalaMigrationTest.php modules_statuses.json composer.lock
git commit -m "feat(infraestrutura): scaffold do módulo e migration da tabela salas"
```

---

## Task 2: Enums `TipoSala` e `EstadoSala`

**Files:**
- Create: `Modules/Infraestrutura/app/Enums/TipoSala.php`
- Create: `Modules/Infraestrutura/app/Enums/EstadoSala.php`
- Test: `Modules/Infraestrutura/tests/Unit/EnumsTest.php`

**Interfaces:**
- Produces: `Modules\Infraestrutura\Enums\TipoSala` (`int`-backed, casos `SALA_AULA=0, LABORATORIO=1, BIBLIOTECA=2, AUDITORIO=3, GINASIO=4, SALA_PROFESSORES=5, GABINETE_ADMINISTRATIVO=6, OUTRO=7`, método `label(): string`) e `Modules\Infraestrutura\Enums\EstadoSala` (`ATIVA=0, MANUTENCAO=1, INATIVA=2`, método `label(): string`), usados por todas as tasks seguintes.

- [ ] **Step 1: Escrever o teste dos enums (vai falhar)**

`Modules/Infraestrutura/tests/Unit/EnumsTest.php`:

```php
<?php

namespace Modules\Infraestrutura\Tests\Unit;

use Modules\Infraestrutura\Enums\EstadoSala;
use Modules\Infraestrutura\Enums\TipoSala;
use PHPUnit\Framework\TestCase;

class EnumsTest extends TestCase
{
    public function test_tipo_sala_valores_e_labels(): void
    {
        $this->assertSame(0, TipoSala::SALA_AULA->value);
        $this->assertSame(1, TipoSala::LABORATORIO->value);
        $this->assertSame(2, TipoSala::BIBLIOTECA->value);
        $this->assertSame(3, TipoSala::AUDITORIO->value);
        $this->assertSame(4, TipoSala::GINASIO->value);
        $this->assertSame(5, TipoSala::SALA_PROFESSORES->value);
        $this->assertSame(6, TipoSala::GABINETE_ADMINISTRATIVO->value);
        $this->assertSame(7, TipoSala::OUTRO->value);

        $this->assertSame('Sala de Aula', TipoSala::SALA_AULA->label());
        $this->assertSame('Laboratório', TipoSala::LABORATORIO->label());
        $this->assertSame('Gabinete Administrativo', TipoSala::GABINETE_ADMINISTRATIVO->label());
    }

    public function test_estado_sala_valores_e_labels(): void
    {
        $this->assertSame(0, EstadoSala::ATIVA->value);
        $this->assertSame(1, EstadoSala::MANUTENCAO->value);
        $this->assertSame(2, EstadoSala::INATIVA->value);

        $this->assertSame('Ativa', EstadoSala::ATIVA->label());
        $this->assertSame('Em Manutenção', EstadoSala::MANUTENCAO->label());
        $this->assertSame('Inativa', EstadoSala::INATIVA->label());
    }
}
```

- [ ] **Step 2: Confirmar que o teste falha**

Run: `php artisan test Modules/Infraestrutura/tests/Unit/EnumsTest.php`
Expected: FAIL — classe `TipoSala` não existe.

- [ ] **Step 3: Implementar os enums**

`Modules/Infraestrutura/app/Enums/TipoSala.php`:

```php
<?php

namespace Modules\Infraestrutura\Enums;

enum TipoSala: int
{
    case SALA_AULA = 0;
    case LABORATORIO = 1;
    case BIBLIOTECA = 2;
    case AUDITORIO = 3;
    case GINASIO = 4;
    case SALA_PROFESSORES = 5;
    case GABINETE_ADMINISTRATIVO = 6;
    case OUTRO = 7;

    public function label(): string
    {
        return match ($this) {
            self::SALA_AULA => 'Sala de Aula',
            self::LABORATORIO => 'Laboratório',
            self::BIBLIOTECA => 'Biblioteca',
            self::AUDITORIO => 'Auditório',
            self::GINASIO => 'Ginásio',
            self::SALA_PROFESSORES => 'Sala de Professores',
            self::GABINETE_ADMINISTRATIVO => 'Gabinete Administrativo',
            self::OUTRO => 'Outro',
        };
    }
}
```

`Modules/Infraestrutura/app/Enums/EstadoSala.php`:

```php
<?php

namespace Modules\Infraestrutura\Enums;

enum EstadoSala: int
{
    case ATIVA = 0;
    case MANUTENCAO = 1;
    case INATIVA = 2;

    public function label(): string
    {
        return match ($this) {
            self::ATIVA => 'Ativa',
            self::MANUTENCAO => 'Em Manutenção',
            self::INATIVA => 'Inativa',
        };
    }
}
```

- [ ] **Step 4: Confirmar que o teste passa**

Run: `php artisan test Modules/Infraestrutura/tests/Unit/EnumsTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add Modules/Infraestrutura/app/Enums Modules/Infraestrutura/tests/Unit/EnumsTest.php
git commit -m "feat(infraestrutura): adiciona enums TipoSala e EstadoSala"
```

---

## Task 3: Model `Sala`

**Files:**
- Create: `Modules/Infraestrutura/app/Models/Sala.php`
- Test: `Modules/Infraestrutura/tests/Feature/SalaModelTest.php`

**Interfaces:**
- Consumes: tabela `salas` (Task 1); `TipoSala`, `EstadoSala` (Task 2); `Modules\Core\Traits\RegistaAutoria` (já existente); `Modules\Estabelecimento\Models\Estabelecimento` (já existente); `Modules\Usuario\Models\User` (já existente).
- Produces: `Modules\Infraestrutura\Models\Sala` com `$fillable = ['estabelecimento_id','codigo','nome','tipo','capacidade','localizacao','observacoes','estado','criado_por','editado_por']`, casts `tipo => TipoSala::class`, `estado => EstadoSala::class`, `capacidade => 'integer'`, relações `estabelecimento(): BelongsTo`, `criadoPor(): BelongsTo`, `editadoPor(): BelongsTo` — usado por todas as tasks seguintes.

- [ ] **Step 1: Escrever o teste do Model (vai falhar)**

`Modules/Infraestrutura/tests/Feature/SalaModelTest.php`:

```php
<?php

namespace Modules\Infraestrutura\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Infraestrutura\Enums\EstadoSala;
use Modules\Infraestrutura\Enums\TipoSala;
use Modules\Infraestrutura\Models\Sala;
use Tests\TestCase;

class SalaModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_sala_com_casts_e_relacao_com_estabelecimento(): void
    {
        $estabelecimento = Estabelecimento::create([
            'nome' => 'Escola Teste',
            'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
            'is_active' => true,
        ]);

        $sala = Sala::create([
            'estabelecimento_id' => $estabelecimento->id,
            'codigo' => 'A101',
            'nome' => 'Sala 101',
            'tipo' => TipoSala::SALA_AULA->value,
            'capacidade' => 30,
            'estado' => EstadoSala::ATIVA->value,
        ]);

        $this->assertInstanceOf(TipoSala::class, $sala->tipo);
        $this->assertInstanceOf(EstadoSala::class, $sala->estado);
        $this->assertSame(30, $sala->capacidade);
        $this->assertTrue($sala->estabelecimento->is($estabelecimento));
    }

    public function test_sincroniza_tipo_descricao_e_estado_descricao_ao_gravar(): void
    {
        $sala = Sala::create([
            'codigo' => 'LAB-01',
            'nome' => 'Laboratório de Informática',
            'tipo' => TipoSala::LABORATORIO->value,
            'estado' => EstadoSala::MANUTENCAO->value,
        ]);

        $this->assertSame('Laboratório', $sala->fresh()->tipo_descricao);
        $this->assertSame('Em Manutenção', $sala->fresh()->estado_descricao);
    }

    public function test_eliminar_sala_e_soft_delete(): void
    {
        $sala = Sala::create([
            'codigo' => 'B02',
            'nome' => 'Biblioteca',
            'tipo' => TipoSala::BIBLIOTECA->value,
        ]);

        $sala->delete();

        $this->assertSoftDeleted('salas', ['id' => $sala->id]);
    }
}
```

- [ ] **Step 2: Confirmar que o teste falha**

Run: `php artisan test Modules/Infraestrutura/tests/Feature/SalaModelTest.php`
Expected: FAIL — classe `Sala` não existe.

- [ ] **Step 3: Implementar o Model**

`Modules/Infraestrutura/app/Models/Sala.php`:

```php
<?php

namespace Modules\Infraestrutura\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Infraestrutura\Enums\EstadoSala;
use Modules\Infraestrutura\Enums\TipoSala;
use Modules\Usuario\Models\User;

class Sala extends Model
{
    use HasFactory;
    use SoftDeletes;
    use RegistaAutoria;

    protected $table = 'salas';

    protected $fillable = [
        'estabelecimento_id',
        'codigo',
        'nome',
        'tipo',
        'capacidade',
        'localizacao',
        'observacoes',
        'estado',
        'criado_por',
        'editado_por',
    ];

    protected $casts = [
        'tipo' => TipoSala::class,
        'estado' => EstadoSala::class,
        'capacidade' => 'integer',
    ];

    public function estabelecimento(): BelongsTo
    {
        return $this->belongsTo(Estabelecimento::class);
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function editadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editado_por');
    }

    protected static function booted(): void
    {
        static::saving(function (Sala $sala) {
            $sala->tipo_descricao = $sala->tipo instanceof TipoSala
                ? $sala->tipo->label()
                : TipoSala::from((int) $sala->tipo)->label();

            $sala->estado_descricao = $sala->estado instanceof EstadoSala
                ? $sala->estado->label()
                : EstadoSala::from((int) $sala->estado)->label();
        });
    }
}
```

- [ ] **Step 4: Confirmar que o teste passa**

Run: `php artisan test Modules/Infraestrutura/tests/Feature/SalaModelTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add Modules/Infraestrutura/app/Models Modules/Infraestrutura/tests/Feature/SalaModelTest.php
git commit -m "feat(infraestrutura): adiciona Model Sala"
```

---

## Task 4: `SalaDTO` + `CriarSalaAction`

**Files:**
- Create: `Modules/Infraestrutura/app/DTO/SalaDTO.php`
- Create: `Modules/Infraestrutura/app/Actions/CriarSalaAction.php`
- Test: `Modules/Infraestrutura/tests/Feature/CriarSalaActionTest.php`

**Interfaces:**
- Consumes: `Sala` (Task 3), `TipoSala`, `EstadoSala` (Task 2), `Modules\Estabelecimento\Models\Estabelecimento::current()`.
- Produces: `Modules\Infraestrutura\DTO\SalaDTO` (construtor `(string $codigo, string $nome, TipoSala $tipo, ?int $capacidade = null, ?string $localizacao = null, ?string $observacoes = null, EstadoSala $estado = EstadoSala::ATIVA)`, estático `fromRequest(FormRequest $request): self`) e `Modules\Infraestrutura\Actions\CriarSalaAction::criar(SalaDTO $dto): Sala` — consumidos pela Task 9 (Form Requests) e Task 10 (Service/Controller).

- [ ] **Step 1: Escrever o teste da Action (vai falhar)**

`Modules/Infraestrutura/tests/Feature/CriarSalaActionTest.php`:

```php
<?php

namespace Modules\Infraestrutura\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Infraestrutura\Actions\CriarSalaAction;
use Modules\Infraestrutura\DTO\SalaDTO;
use Modules\Infraestrutura\Enums\EstadoSala;
use Modules\Infraestrutura\Enums\TipoSala;
use Tests\TestCase;

class CriarSalaActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_sala_associada_ao_estabelecimento_activo_com_estado_ativa_por_defeito(): void
    {
        $estabelecimento = Estabelecimento::create([
            'nome' => 'Escola Teste',
            'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
            'is_active' => true,
        ]);

        $sala = (new CriarSalaAction())->criar(new SalaDTO(
            codigo: 'A101',
            nome: 'Sala 101',
            tipo: TipoSala::SALA_AULA,
            capacidade: 30,
        ));

        $this->assertSame($estabelecimento->id, $sala->estabelecimento_id);
        $this->assertSame(EstadoSala::ATIVA, $sala->estado);
        $this->assertSame('A101', $sala->codigo);
    }

    public function test_dto_from_request_converte_enums_a_partir_de_dados_validados(): void
    {
        $request = \Mockery::mock(\Illuminate\Foundation\Http\FormRequest::class);
        $request->shouldReceive('validated')->andReturn([
            'codigo' => 'B02',
            'nome' => 'Biblioteca',
            'tipo' => (string) TipoSala::BIBLIOTECA->value,
            'capacidade' => '50',
            'estado' => (string) EstadoSala::MANUTENCAO->value,
        ]);

        $dto = SalaDTO::fromRequest($request);

        $this->assertSame('B02', $dto->codigo);
        $this->assertSame(TipoSala::BIBLIOTECA, $dto->tipo);
        $this->assertSame(50, $dto->capacidade);
        $this->assertSame(EstadoSala::MANUTENCAO, $dto->estado);
    }
}
```

- [ ] **Step 2: Confirmar que o teste falha**

Run: `php artisan test Modules/Infraestrutura/tests/Feature/CriarSalaActionTest.php`
Expected: FAIL — classes `SalaDTO`/`CriarSalaAction` não existem.

- [ ] **Step 3: Implementar o DTO e a Action**

`Modules/Infraestrutura/app/DTO/SalaDTO.php`:

```php
<?php

namespace Modules\Infraestrutura\DTO;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Infraestrutura\Enums\EstadoSala;
use Modules\Infraestrutura\Enums\TipoSala;

class SalaDTO
{
    public function __construct(
        public string $codigo,
        public string $nome,
        public TipoSala $tipo,
        public ?int $capacidade = null,
        public ?string $localizacao = null,
        public ?string $observacoes = null,
        public EstadoSala $estado = EstadoSala::ATIVA,
    ) {}

    public static function fromRequest(FormRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            codigo: $dados['codigo'],
            nome: $dados['nome'],
            tipo: TipoSala::from((int) $dados['tipo']),
            capacidade: isset($dados['capacidade']) ? (int) $dados['capacidade'] : null,
            localizacao: $dados['localizacao'] ?? null,
            observacoes: $dados['observacoes'] ?? null,
            estado: isset($dados['estado']) ? EstadoSala::from((int) $dados['estado']) : EstadoSala::ATIVA,
        );
    }
}
```

`Modules/Infraestrutura/app/Actions/CriarSalaAction.php`:

```php
<?php

namespace Modules\Infraestrutura\Actions;

use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Infraestrutura\DTO\SalaDTO;
use Modules\Infraestrutura\Models\Sala;

class CriarSalaAction
{
    public function criar(SalaDTO $dto): Sala
    {
        return Sala::create([
            'estabelecimento_id' => Estabelecimento::current()?->id,
            'codigo' => $dto->codigo,
            'nome' => $dto->nome,
            'tipo' => $dto->tipo->value,
            'capacidade' => $dto->capacidade,
            'localizacao' => $dto->localizacao,
            'observacoes' => $dto->observacoes,
            'estado' => $dto->estado->value,
        ]);
    }
}
```

- [ ] **Step 4: Confirmar que o teste passa**

Run: `php artisan test Modules/Infraestrutura/tests/Feature/CriarSalaActionTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add Modules/Infraestrutura/app/DTO Modules/Infraestrutura/app/Actions/CriarSalaAction.php Modules/Infraestrutura/tests/Feature/CriarSalaActionTest.php
git commit -m "feat(infraestrutura): adiciona SalaDTO e CriarSalaAction"
```

---

## Task 5: `AtualizarSalaAction`

**Files:**
- Create: `Modules/Infraestrutura/app/Actions/AtualizarSalaAction.php`
- Test: `Modules/Infraestrutura/tests/Feature/AtualizarSalaActionTest.php`

**Interfaces:**
- Consumes: `Sala` (Task 3), `SalaDTO` (Task 4).
- Produces: `Modules\Infraestrutura\Actions\AtualizarSalaAction::atualizar(Sala $sala, SalaDTO $dto): Sala` — consumido pela Task 10 (Service/Controller).

- [ ] **Step 1: Escrever o teste da Action (vai falhar)**

`Modules/Infraestrutura/tests/Feature/AtualizarSalaActionTest.php`:

```php
<?php

namespace Modules\Infraestrutura\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Infraestrutura\Actions\AtualizarSalaAction;
use Modules\Infraestrutura\DTO\SalaDTO;
use Modules\Infraestrutura\Enums\EstadoSala;
use Modules\Infraestrutura\Enums\TipoSala;
use Modules\Infraestrutura\Models\Sala;
use Tests\TestCase;

class AtualizarSalaActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_atualiza_dados_da_sala(): void
    {
        $sala = Sala::create([
            'codigo' => 'A101',
            'nome' => 'Sala 101',
            'tipo' => TipoSala::SALA_AULA->value,
        ]);

        $atualizada = (new AtualizarSalaAction())->atualizar($sala, new SalaDTO(
            codigo: 'A101',
            nome: 'Sala 101 - Bloco A',
            tipo: TipoSala::LABORATORIO,
            capacidade: 25,
            localizacao: 'Bloco A, 1º Andar',
            estado: EstadoSala::MANUTENCAO,
        ));

        $this->assertSame('Sala 101 - Bloco A', $atualizada->nome);
        $this->assertSame(TipoSala::LABORATORIO, $atualizada->tipo);
        $this->assertSame(25, $atualizada->capacidade);
        $this->assertSame('Bloco A, 1º Andar', $atualizada->localizacao);
        $this->assertSame(EstadoSala::MANUTENCAO, $atualizada->estado);
    }
}
```

- [ ] **Step 2: Confirmar que o teste falha**

Run: `php artisan test Modules/Infraestrutura/tests/Feature/AtualizarSalaActionTest.php`
Expected: FAIL — classe `AtualizarSalaAction` não existe.

- [ ] **Step 3: Implementar a Action**

`Modules/Infraestrutura/app/Actions/AtualizarSalaAction.php`:

```php
<?php

namespace Modules\Infraestrutura\Actions;

use Modules\Infraestrutura\DTO\SalaDTO;
use Modules\Infraestrutura\Models\Sala;

class AtualizarSalaAction
{
    public function atualizar(Sala $sala, SalaDTO $dto): Sala
    {
        $sala->update([
            'codigo' => $dto->codigo,
            'nome' => $dto->nome,
            'tipo' => $dto->tipo->value,
            'capacidade' => $dto->capacidade,
            'localizacao' => $dto->localizacao,
            'observacoes' => $dto->observacoes,
            'estado' => $dto->estado->value,
        ]);

        return $sala->fresh();
    }
}
```

- [ ] **Step 4: Confirmar que o teste passa**

Run: `php artisan test Modules/Infraestrutura/tests/Feature/AtualizarSalaActionTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add Modules/Infraestrutura/app/Actions/AtualizarSalaAction.php Modules/Infraestrutura/tests/Feature/AtualizarSalaActionTest.php
git commit -m "feat(infraestrutura): adiciona AtualizarSalaAction"
```

---

## Task 6: `AlterarEstadoSalaAction`

**Files:**
- Create: `Modules/Infraestrutura/app/Actions/AlterarEstadoSalaAction.php`
- Test: `Modules/Infraestrutura/tests/Feature/AlterarEstadoSalaActionTest.php`

**Interfaces:**
- Consumes: `Sala` (Task 3), `EstadoSala` (Task 2).
- Produces: `Modules\Infraestrutura\Actions\AlterarEstadoSalaAction::alterar(Sala $sala, EstadoSala $novoEstado): Sala` — consumido pela Task 10 (Service/Controller).

- [ ] **Step 1: Escrever o teste da Action (vai falhar)**

`Modules/Infraestrutura/tests/Feature/AlterarEstadoSalaActionTest.php`:

```php
<?php

namespace Modules\Infraestrutura\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Infraestrutura\Actions\AlterarEstadoSalaAction;
use Modules\Infraestrutura\Enums\EstadoSala;
use Modules\Infraestrutura\Enums\TipoSala;
use Modules\Infraestrutura\Models\Sala;
use Tests\TestCase;

class AlterarEstadoSalaActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_altera_estado_de_ativa_para_manutencao_e_de_volta(): void
    {
        $sala = Sala::create([
            'codigo' => 'A101',
            'nome' => 'Sala 101',
            'tipo' => TipoSala::SALA_AULA->value,
        ]);
        $this->assertSame(EstadoSala::ATIVA, $sala->estado);

        $emManutencao = (new AlterarEstadoSalaAction())->alterar($sala, EstadoSala::MANUTENCAO);
        $this->assertSame(EstadoSala::MANUTENCAO, $emManutencao->estado);

        $ativaDeNovo = (new AlterarEstadoSalaAction())->alterar($sala, EstadoSala::ATIVA);
        $this->assertSame(EstadoSala::ATIVA, $ativaDeNovo->estado);
    }

    public function test_permite_varias_salas_em_manutencao_ao_mesmo_tempo(): void
    {
        $salaA = Sala::create(['codigo' => 'A101', 'nome' => 'Sala 101', 'tipo' => TipoSala::SALA_AULA->value]);
        $salaB = Sala::create(['codigo' => 'A102', 'nome' => 'Sala 102', 'tipo' => TipoSala::SALA_AULA->value]);

        $action = new AlterarEstadoSalaAction();
        $action->alterar($salaA, EstadoSala::MANUTENCAO);
        $action->alterar($salaB, EstadoSala::MANUTENCAO);

        $this->assertSame(2, Sala::where('estado', EstadoSala::MANUTENCAO->value)->count());
    }
}
```

- [ ] **Step 2: Confirmar que o teste falha**

Run: `php artisan test Modules/Infraestrutura/tests/Feature/AlterarEstadoSalaActionTest.php`
Expected: FAIL — classe `AlterarEstadoSalaAction` não existe.

- [ ] **Step 3: Implementar a Action**

`Modules/Infraestrutura/app/Actions/AlterarEstadoSalaAction.php`:

```php
<?php

namespace Modules\Infraestrutura\Actions;

use Modules\Infraestrutura\Enums\EstadoSala;
use Modules\Infraestrutura\Models\Sala;

class AlterarEstadoSalaAction
{
    public function alterar(Sala $sala, EstadoSala $novoEstado): Sala
    {
        $sala->update(['estado' => $novoEstado->value]);

        return $sala->fresh();
    }
}
```

- [ ] **Step 4: Confirmar que o teste passa**

Run: `php artisan test Modules/Infraestrutura/tests/Feature/AlterarEstadoSalaActionTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add Modules/Infraestrutura/app/Actions/AlterarEstadoSalaAction.php Modules/Infraestrutura/tests/Feature/AlterarEstadoSalaActionTest.php
git commit -m "feat(infraestrutura): adiciona AlterarEstadoSalaAction"
```

---

## Task 7: `EliminarSalaAction`

**Files:**
- Create: `Modules/Infraestrutura/app/Actions/EliminarSalaAction.php`
- Test: `Modules/Infraestrutura/tests/Feature/EliminarSalaActionTest.php`

**Interfaces:**
- Consumes: `Sala` (Task 3).
- Produces: `Modules\Infraestrutura\Actions\EliminarSalaAction::executar(Sala $sala): void` — consumido pela Task 10 (Service/Controller).

- [ ] **Step 1: Escrever o teste da Action (vai falhar)**

`Modules/Infraestrutura/tests/Feature/EliminarSalaActionTest.php`:

```php
<?php

namespace Modules\Infraestrutura\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Infraestrutura\Actions\EliminarSalaAction;
use Modules\Infraestrutura\Enums\TipoSala;
use Modules\Infraestrutura\Models\Sala;
use Tests\TestCase;

class EliminarSalaActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_elimina_sala_com_soft_delete(): void
    {
        $sala = Sala::create([
            'codigo' => 'A101',
            'nome' => 'Sala 101',
            'tipo' => TipoSala::SALA_AULA->value,
        ]);

        (new EliminarSalaAction())->executar($sala);

        $this->assertSoftDeleted('salas', ['id' => $sala->id]);
        $this->assertSame(0, Sala::count());
        $this->assertSame(1, Sala::withTrashed()->count());
    }
}
```

- [ ] **Step 2: Confirmar que o teste falha**

Run: `php artisan test Modules/Infraestrutura/tests/Feature/EliminarSalaActionTest.php`
Expected: FAIL — classe `EliminarSalaAction` não existe.

- [ ] **Step 3: Implementar a Action**

`Modules/Infraestrutura/app/Actions/EliminarSalaAction.php`:

```php
<?php

namespace Modules\Infraestrutura\Actions;

use Modules\Infraestrutura\Models\Sala;

class EliminarSalaAction
{
    public function executar(Sala $sala): void
    {
        $sala->delete();
    }
}
```

- [ ] **Step 4: Confirmar que o teste passa**

Run: `php artisan test Modules/Infraestrutura/tests/Feature/EliminarSalaActionTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add Modules/Infraestrutura/app/Actions/EliminarSalaAction.php Modules/Infraestrutura/tests/Feature/EliminarSalaActionTest.php
git commit -m "feat(infraestrutura): adiciona EliminarSalaAction"
```

---

## Task 8: Permissão `infraestrutura.*`

**Files:**
- Modify: `Modules/Permissao/app/Enums/Modulo.php`
- Modify: `Modules/Permissao/database/seeders/ModuloSeeder.php`
- Modify: `Modules/Permissao/database/seeders/RolePermissaoSeeder.php`
- Test: `Modules/Infraestrutura/tests/Feature/SalaAutorizacaoTest.php`

**Interfaces:**
- Produces: `Modulo::INFRAESTRUTURA` (valor `12`, slug `infraestrutura`, label `Infraestrutura`) reconhecido pelo `PermissionResolver`/`Gate::before` já existentes; `ADMIN_ESCOLA` passa a ter `infraestrutura.ver`, `infraestrutura.criar`, `infraestrutura.editar`, `infraestrutura.eliminar` — usado pelas rotas/Controller da Task 10.

- [ ] **Step 1: Escrever o teste de autorização (vai falhar)**

`Modules/Infraestrutura/tests/Feature/SalaAutorizacaoTest.php`:

```php
<?php

namespace Modules\Infraestrutura\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class SalaAutorizacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissaoDatabaseSeeder::class);
    }

    public function test_admin_escola_tem_permissao_em_infraestrutura(): void
    {
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);

        foreach (['ver', 'criar', 'editar', 'eliminar'] as $acao) {
            $this->assertTrue(Gate::forUser($staff)->allows("infraestrutura.{$acao}"), "infraestrutura.{$acao}");
        }
    }

    public function test_professor_nao_tem_permissao_em_infraestrutura(): void
    {
        $professor = User::create(['name' => 'Professor', 'email' => 'professor@example.com', 'password' => Hash::make('x')]);
        $professor->roles()->syncWithoutDetaching([Role::where('nome', Perfil::PROFESSOR->value)->first()->id]);

        $this->assertFalse(Gate::forUser($professor)->allows('infraestrutura.ver'));
    }
}
```

- [ ] **Step 2: Confirmar que o teste falha**

Run: `php artisan test Modules/Infraestrutura/tests/Feature/SalaAutorizacaoTest.php`
Expected: FAIL — `infraestrutura.ver` não é reconhecido pelo `PermissionResolver` (`Modulo::fromSlug('infraestrutura')` devolve `null`).

- [ ] **Step 3: Adicionar o case `INFRAESTRUTURA` ao enum `Modulo`**

Editar `Modules/Permissao/app/Enums/Modulo.php` — adicionar o case à lista de casos, ao `match` de `slug()` e ao `match` de `label()`:

```php
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
    case INFRAESTRUTURA = 12;

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
            self::INFRAESTRUTURA => 'infraestrutura',
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
            self::USUARIO => 'Utilizadores',
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
            self::INFRAESTRUTURA => 'Infraestrutura',
        };
    }
}
```

- [ ] **Step 4: Registar o módulo no `ModuloSeeder`**

Editar `Modules/Permissao/database/seeders/ModuloSeeder.php` — adicionar a entrada `12` à lista `$modulos`:

```php
$modulos = [
    ['nome' => 0, 'descricao' => 'Utilizadores'],
    ['nome' => 1, 'descricao' => 'Autorizacao'],
    ['nome' => 2, 'descricao' => 'Ano Letivo'],
    ['nome' => 3, 'descricao' => 'Licenca'],
    ['nome' => 4, 'descricao' => 'Aluno'],
    ['nome' => 5, 'descricao' => 'Professor'],
    ['nome' => 6, 'descricao' => 'Turmas'],
    ['nome' => 7, 'descricao' => 'Matricula'],
    ['nome' => 8, 'descricao' => 'Disciplina'],
    ['nome' => 9, 'descricao' => 'Nota'],
    ['nome' => 10, 'descricao' => 'Estabelecimento'],
    ['nome' => 11, 'descricao' => 'Horario'],
    ['nome' => 12, 'descricao' => 'Infraestrutura'],
];
```

- [ ] **Step 5: Conceder a permissão a `ADMIN_ESCOLA` no `RolePermissaoSeeder`**

Editar `Modules/Permissao/database/seeders/RolePermissaoSeeder.php` — adicionar a entrada ao mapa de `ADMIN_ESCOLA`:

```php
$mapaPorRole = [
    Perfil::ADMIN_ESCOLA->value => [
        Modulo::ANO_LECTIVO->value => ['ver', 'criar', 'editar', 'eliminar'],
        Modulo::ESTABELECIMENTO->value => ['ver', 'editar'],
        Modulo::HORARIO->value => ['ver', 'criar', 'editar', 'eliminar'],
        Modulo::USUARIO->value => ['ver', 'criar', 'editar', 'eliminar'],
        Modulo::AUTORIZACAO->value => ['ver', 'criar', 'editar', 'eliminar'],
        Modulo::INFRAESTRUTURA->value => ['ver', 'criar', 'editar', 'eliminar'],
    ],
    Perfil::FUNCIONARIO->value => [
        Modulo::USUARIO->value => ['ver', 'criar', 'editar'],
    ],
];
```

- [ ] **Step 6: Confirmar que o teste passa**

Run: `php artisan test Modules/Infraestrutura/tests/Feature/SalaAutorizacaoTest.php`
Expected: PASS

- [ ] **Step 7: Rodar a suite completa de `Permissao` para confirmar ausência de regressão**

Run: `php artisan test Modules/Permissao`
Expected: PASS (nenhum teste existente quebra com o novo case do enum)

- [ ] **Step 8: Commit**

```bash
git add Modules/Permissao/app/Enums/Modulo.php Modules/Permissao/database/seeders/ModuloSeeder.php Modules/Permissao/database/seeders/RolePermissaoSeeder.php Modules/Infraestrutura/tests/Feature/SalaAutorizacaoTest.php
git commit -m "feat(permissao): adiciona modulo INFRAESTRUTURA e concede a ADMIN_ESCOLA"
```

---

## Task 9: Form Requests

**Files:**
- Create: `Modules/Infraestrutura/app/Http/Requests/CriarSalaRequest.php`
- Create: `Modules/Infraestrutura/app/Http/Requests/AtualizarSalaRequest.php`
- Create: `Modules/Infraestrutura/app/Http/Requests/AlterarEstadoSalaRequest.php`
- Test: `Modules/Infraestrutura/tests/Feature/CriarSalaRequestTest.php`

**Interfaces:**
- Consumes: `TipoSala`, `EstadoSala` (Task 2), `Modules\Estabelecimento\Models\Estabelecimento::current()`, permissão `infraestrutura.*` (Task 8).
- Produces: `CriarSalaRequest`, `AtualizarSalaRequest`, `AlterarEstadoSalaRequest` (todas estendem `App\Http\Requests\BaseRequest`) — consumidas pela Task 10 (Controller).

- [ ] **Step 1: Escrever o teste de validação (vai falhar)**

`Modules/Infraestrutura/tests/Feature/CriarSalaRequestTest.php`:

```php
<?php

namespace Modules\Infraestrutura\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Infraestrutura\Enums\TipoSala;
use Modules\Infraestrutura\Http\Requests\CriarSalaRequest;
use Modules\Infraestrutura\Models\Sala;
use Tests\TestCase;

class CriarSalaRequestTest extends TestCase
{
    use RefreshDatabase;

    private function validar(array $dados): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($dados, (new CriarSalaRequest())->rules());
    }

    public function test_dados_validos_passam(): void
    {
        $validador = $this->validar([
            'codigo' => 'A101',
            'nome' => 'Sala 101',
            'tipo' => TipoSala::SALA_AULA->value,
            'capacidade' => 30,
        ]);

        $this->assertFalse($validador->fails());
    }

    public function test_codigo_e_obrigatorio(): void
    {
        $validador = $this->validar([
            'nome' => 'Sala 101',
            'tipo' => TipoSala::SALA_AULA->value,
        ]);

        $this->assertTrue($validador->fails());
        $this->assertArrayHasKey('codigo', $validador->errors()->toArray());
    }

    public function test_codigo_duplicado_no_mesmo_estabelecimento_falha(): void
    {
        $estabelecimento = Estabelecimento::create([
            'nome' => 'Escola Teste',
            'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
            'is_active' => true,
        ]);

        Sala::create([
            'estabelecimento_id' => $estabelecimento->id,
            'codigo' => 'A101',
            'nome' => 'Sala 101',
            'tipo' => TipoSala::SALA_AULA->value,
        ]);

        $validador = $this->validar([
            'codigo' => 'A101',
            'nome' => 'Outra Sala',
            'tipo' => TipoSala::SALA_AULA->value,
        ]);

        $this->assertTrue($validador->fails());
        $this->assertArrayHasKey('codigo', $validador->errors()->toArray());
    }

    public function test_mesmo_codigo_em_estabelecimentos_diferentes_e_permitido(): void
    {
        $estabelecimentoA = Estabelecimento::create([
            'nome' => 'Escola A',
            'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
            'is_active' => false,
        ]);
        Sala::create([
            'estabelecimento_id' => $estabelecimentoA->id,
            'codigo' => 'A101',
            'nome' => 'Sala 101',
            'tipo' => TipoSala::SALA_AULA->value,
        ]);

        $estabelecimentoB = Estabelecimento::create([
            'nome' => 'Escola B',
            'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
            'is_active' => true,
        ]);

        $validador = $this->validar([
            'codigo' => 'A101',
            'nome' => 'Sala 101 (outra escola)',
            'tipo' => TipoSala::SALA_AULA->value,
        ]);

        $this->assertFalse($validador->fails());
        $this->assertNotSame($estabelecimentoA->id, $estabelecimentoB->id);
    }
}
```

- [ ] **Step 2: Confirmar que o teste falha**

Run: `php artisan test Modules/Infraestrutura/tests/Feature/CriarSalaRequestTest.php`
Expected: FAIL — classe `CriarSalaRequest` não existe.

- [ ] **Step 3: Implementar os Form Requests**

`Modules/Infraestrutura/app/Http/Requests/CriarSalaRequest.php`:

```php
<?php

namespace Modules\Infraestrutura\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Infraestrutura\Enums\EstadoSala;
use Modules\Infraestrutura\Enums\TipoSala;

class CriarSalaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('infraestrutura.criar') ?? false;
    }

    public function rules(): array
    {
        return [
            'codigo' => [
                'required',
                'string',
                'max:20',
                Rule::unique('salas', 'codigo')
                    ->where(fn ($query) => $query->where('estabelecimento_id', Estabelecimento::current()?->id)),
            ],
            'nome' => ['required', 'string', 'max:255'],
            'tipo' => ['required', new Enum(TipoSala::class)],
            'capacidade' => ['nullable', 'integer', 'min:1', 'max:500'],
            'localizacao' => ['nullable', 'string', 'max:255'],
            'observacoes' => ['nullable', 'string'],
            'estado' => ['sometimes', new Enum(EstadoSala::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'codigo.required' => 'O código da sala é obrigatório.',
            'codigo.unique' => 'Já existe uma sala com este código neste estabelecimento.',
            'nome.required' => 'O nome da sala é obrigatório.',
            'tipo.required' => 'O tipo de sala é obrigatório.',
            'capacidade.integer' => 'A capacidade deve ser um número válido.',
            'capacidade.min' => 'A capacidade deve ser de pelo menos 1.',
        ];
    }
}
```

`Modules/Infraestrutura/app/Http/Requests/AtualizarSalaRequest.php`:

```php
<?php

namespace Modules\Infraestrutura\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Infraestrutura\Enums\EstadoSala;
use Modules\Infraestrutura\Enums\TipoSala;

class AtualizarSalaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('infraestrutura.editar') ?? false;
    }

    public function rules(): array
    {
        return [
            'codigo' => [
                'required',
                'string',
                'max:20',
                Rule::unique('salas', 'codigo')
                    ->where(fn ($query) => $query->where('estabelecimento_id', Estabelecimento::current()?->id))
                    ->ignore($this->route('sala')),
            ],
            'nome' => ['required', 'string', 'max:255'],
            'tipo' => ['required', new Enum(TipoSala::class)],
            'capacidade' => ['nullable', 'integer', 'min:1', 'max:500'],
            'localizacao' => ['nullable', 'string', 'max:255'],
            'observacoes' => ['nullable', 'string'],
            'estado' => ['required', new Enum(EstadoSala::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'codigo.required' => 'O código da sala é obrigatório.',
            'codigo.unique' => 'Já existe uma sala com este código neste estabelecimento.',
            'nome.required' => 'O nome da sala é obrigatório.',
            'tipo.required' => 'O tipo de sala é obrigatório.',
            'estado.required' => 'O estado da sala é obrigatório.',
        ];
    }
}
```

`Modules/Infraestrutura/app/Http/Requests/AlterarEstadoSalaRequest.php`:

```php
<?php

namespace Modules\Infraestrutura\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rules\Enum;
use Modules\Infraestrutura\Enums\EstadoSala;

class AlterarEstadoSalaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('infraestrutura.editar') ?? false;
    }

    public function rules(): array
    {
        return [
            'estado' => ['required', new Enum(EstadoSala::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'estado.required' => 'O novo estado é obrigatório.',
        ];
    }
}
```

- [ ] **Step 4: Confirmar que o teste passa**

Run: `php artisan test Modules/Infraestrutura/tests/Feature/CriarSalaRequestTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add Modules/Infraestrutura/app/Http/Requests Modules/Infraestrutura/tests/Feature/CriarSalaRequestTest.php
git commit -m "feat(infraestrutura): adiciona Form Requests de Sala"
```

---

## Task 10: Services, Controller, rotas e testes HTTP

**Files:**
- Create: `Modules/Infraestrutura/app/Services/GestaoSalaService.php`
- Create: `Modules/Infraestrutura/app/Services/SalaConsultaService.php`
- Create: `Modules/Infraestrutura/app/Http/Controllers/SalaController.php`
- Modify: `Modules/Infraestrutura/routes/web.php`
- Test: `Modules/Infraestrutura/tests/Feature/SalaHttpTest.php`

**Interfaces:**
- Consumes: `CriarSalaAction`, `AtualizarSalaAction`, `AlterarEstadoSalaAction`, `EliminarSalaAction` (Tasks 4-7); `CriarSalaRequest`, `AtualizarSalaRequest`, `AlterarEstadoSalaRequest` (Task 9); `SalaDTO::fromRequest()` (Task 4); permissão `infraestrutura.*` (Task 8); `Sala` (Task 3).
- Produces: rotas `salas.index`, `salas.store`, `salas.show`, `salas.update`, `salas.alterar-estado`, `salas.destroy` — ponto de entrada HTTP completo do módulo nesta fase.

- [ ] **Step 1: Escrever o teste HTTP (vai falhar)**

`Modules/Infraestrutura/tests/Feature/SalaHttpTest.php`:

```php
<?php

namespace Modules\Infraestrutura\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Infraestrutura\Enums\EstadoSala;
use Modules\Infraestrutura\Enums\TipoSala;
use Modules\Infraestrutura\Models\Sala;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class SalaHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function actingAsStaff(): User
    {
        $staff = User::firstOrCreate(
            ['email' => 'staff@example.com'],
            ['name' => 'Staff', 'password' => Hash::make('segredo123')],
        );
        $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);

        $this->actingAs($staff);

        return $staff;
    }

    private function actingAsProfessor(): User
    {
        $professor = User::firstOrCreate(
            ['email' => 'professor@example.com'],
            ['name' => 'Professor', 'password' => Hash::make('segredo123')],
        );
        $professor->roles()->syncWithoutDetaching([Role::where('nome', Perfil::PROFESSOR->value)->first()->id]);

        $this->actingAs($professor);

        return $professor;
    }

    public function test_cria_sala_via_http_e_regista_autoria(): void
    {
        $staff = $this->actingAsStaff();

        $this->post(route('salas.store'), [
            'codigo' => 'A101',
            'nome' => 'Sala 101',
            'tipo' => TipoSala::SALA_AULA->value,
            'capacidade' => 30,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $sala = Sala::firstWhere('codigo', 'A101');
        $this->assertNotNull($sala);
        $this->assertSame($staff->id, $sala->criado_por);
        $this->assertSame($staff->id, $sala->editado_por);
    }

    public function test_atualiza_sala_via_http_e_actualiza_editado_por(): void
    {
        $this->actingAsStaff();
        $sala = Sala::create(['codigo' => 'A101', 'nome' => 'Sala 101', 'tipo' => TipoSala::SALA_AULA->value]);

        $outroStaff = User::create(['name' => 'Outro Staff', 'email' => 'outro@example.com', 'password' => Hash::make('x')]);
        $outroStaff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);
        $this->actingAs($outroStaff);

        $this->put(route('salas.update', $sala), [
            'codigo' => 'A101',
            'nome' => 'Sala 101 Renovada',
            'tipo' => TipoSala::SALA_AULA->value,
            'estado' => EstadoSala::ATIVA->value,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $sala->refresh();
        $this->assertSame('Sala 101 Renovada', $sala->nome);
        $this->assertSame($outroStaff->id, $sala->editado_por);
    }

    public function test_altera_estado_via_http(): void
    {
        $this->actingAsStaff();
        $sala = Sala::create(['codigo' => 'A101', 'nome' => 'Sala 101', 'tipo' => TipoSala::SALA_AULA->value]);

        $this->patch(route('salas.alterar-estado', $sala), [
            'estado' => EstadoSala::MANUTENCAO->value,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(EstadoSala::MANUTENCAO, $sala->fresh()->estado);
    }

    public function test_elimina_sala_via_http_com_soft_delete(): void
    {
        $this->actingAsStaff();
        $sala = Sala::create(['codigo' => 'A101', 'nome' => 'Sala 101', 'tipo' => TipoSala::SALA_AULA->value]);

        $this->delete(route('salas.destroy', $sala))->assertRedirect();

        $this->assertSoftDeleted('salas', ['id' => $sala->id]);
    }

    public function test_professor_recebe_403_em_todas_as_rotas_de_escrita(): void
    {
        $this->actingAsProfessor();

        $this->post(route('salas.store'), ['codigo' => 'A101', 'nome' => 'Sala 101', 'tipo' => TipoSala::SALA_AULA->value])
            ->assertForbidden();
    }

    public function test_professor_recebe_403_ao_listar(): void
    {
        $this->actingAsProfessor();

        $this->get(route('salas.index'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Confirmar que o teste falha**

Run: `php artisan test Modules/Infraestrutura/tests/Feature/SalaHttpTest.php`
Expected: FAIL — rota `salas.store` não existe.

- [ ] **Step 3: Implementar os Services**

`Modules/Infraestrutura/app/Services/GestaoSalaService.php`:

```php
<?php

namespace Modules\Infraestrutura\Services;

use Modules\Infraestrutura\Actions\AlterarEstadoSalaAction;
use Modules\Infraestrutura\Actions\AtualizarSalaAction;
use Modules\Infraestrutura\Actions\CriarSalaAction;
use Modules\Infraestrutura\Actions\EliminarSalaAction;
use Modules\Infraestrutura\DTO\SalaDTO;
use Modules\Infraestrutura\Enums\EstadoSala;
use Modules\Infraestrutura\Http\Requests\AtualizarSalaRequest;
use Modules\Infraestrutura\Http\Requests\CriarSalaRequest;
use Modules\Infraestrutura\Models\Sala;

class GestaoSalaService
{
    public function __construct(
        private CriarSalaAction $criarSala,
        private AtualizarSalaAction $atualizarSala,
        private AlterarEstadoSalaAction $alterarEstadoSala,
        private EliminarSalaAction $eliminarSala,
    ) {}

    public function criar(CriarSalaRequest $request): Sala
    {
        return $this->criarSala->criar(SalaDTO::fromRequest($request));
    }

    public function atualizar(Sala $sala, AtualizarSalaRequest $request): Sala
    {
        return $this->atualizarSala->atualizar($sala, SalaDTO::fromRequest($request));
    }

    public function alterarEstado(Sala $sala, EstadoSala $novoEstado): Sala
    {
        return $this->alterarEstadoSala->alterar($sala, $novoEstado);
    }

    public function eliminar(Sala $sala): void
    {
        $this->eliminarSala->executar($sala);
    }
}
```

`Modules/Infraestrutura/app/Services/SalaConsultaService.php`:

```php
<?php

namespace Modules\Infraestrutura\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Infraestrutura\Models\Sala;

class SalaConsultaService
{
    public function listar(): Collection
    {
        return Sala::orderBy('codigo')->get();
    }
}
```

- [ ] **Step 4: Implementar o Controller**

`Modules/Infraestrutura/app/Http/Controllers/SalaController.php`:

```php
<?php

namespace Modules\Infraestrutura\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Modules\Infraestrutura\Enums\EstadoSala;
use Modules\Infraestrutura\Http\Requests\AlterarEstadoSalaRequest;
use Modules\Infraestrutura\Http\Requests\AtualizarSalaRequest;
use Modules\Infraestrutura\Http\Requests\CriarSalaRequest;
use Modules\Infraestrutura\Models\Sala;
use Modules\Infraestrutura\Services\GestaoSalaService;
use Modules\Infraestrutura\Services\SalaConsultaService;

class SalaController extends Controller
{
    public function __construct(
        private GestaoSalaService $service,
        private SalaConsultaService $consulta,
    ) {}

    public function index()
    {
        $this->authorize('infraestrutura.ver');

        return Inertia::render('Infraestrutura/Salas/Index', [
            'salas' => $this->consulta->listar(),
        ]);
    }

    public function show(Sala $sala)
    {
        $this->authorize('infraestrutura.ver');

        return Inertia::render('Infraestrutura/Salas/Show', [
            'sala' => $sala,
        ]);
    }

    public function store(CriarSalaRequest $request)
    {
        $this->authorize('infraestrutura.criar');

        $this->service->criar($request);

        return redirect()->back()->with('success', 'Sala criada com sucesso.');
    }

    public function update(AtualizarSalaRequest $request, Sala $sala)
    {
        $this->authorize('infraestrutura.editar');

        $this->service->atualizar($sala, $request);

        return redirect()->back()->with('success', 'Sala atualizada com sucesso.');
    }

    public function alterarEstado(AlterarEstadoSalaRequest $request, Sala $sala)
    {
        $this->authorize('infraestrutura.editar');

        $this->service->alterarEstado($sala, EstadoSala::from((int) $request->validated('estado')));

        return redirect()->back()->with('success', 'Estado da sala atualizado com sucesso.');
    }

    public function destroy(Sala $sala)
    {
        $this->authorize('infraestrutura.eliminar');

        $this->service->eliminar($sala);

        return redirect()->back()->with('success', 'Sala eliminada com sucesso.');
    }
}
```

- [ ] **Step 5: Registar as rotas**

Substituir o conteúdo de `Modules/Infraestrutura/routes/web.php`:

```php
<?php

use Illuminate\Support\Facades\Route;
use Modules\Infraestrutura\Http\Controllers\SalaController;

Route::middleware(['auth'])->prefix('salas')->name('salas.')->group(function () {
    Route::get('/', [SalaController::class, 'index'])->middleware('can:infraestrutura.ver')->name('index');
    Route::post('/', [SalaController::class, 'store'])->middleware('can:infraestrutura.criar')->name('store');
    Route::get('/{sala}', [SalaController::class, 'show'])->middleware('can:infraestrutura.ver')->name('show');
    Route::put('/{sala}', [SalaController::class, 'update'])->middleware('can:infraestrutura.editar')->name('update');
    Route::patch('/{sala}/estado', [SalaController::class, 'alterarEstado'])->middleware('can:infraestrutura.editar')->name('alterar-estado');
    Route::delete('/{sala}', [SalaController::class, 'destroy'])->middleware('can:infraestrutura.eliminar')->name('destroy');
});
```

- [ ] **Step 6: Confirmar que o teste passa**

Run: `php artisan test Modules/Infraestrutura/tests/Feature/SalaHttpTest.php`
Expected: PASS

- [ ] **Step 7: Rodar a suite completa do módulo e a suite geral**

Run: `php artisan test Modules/Infraestrutura`
Expected: PASS — todos os testes das Tasks 1 a 10.

Run: `php artisan test`
Expected: PASS — nenhuma regressão nos módulos existentes (`AnoLectivo`, `Estabelecimento`, `Permissao`, `Usuario`, etc.).

- [ ] **Step 8: Commit**

```bash
git add Modules/Infraestrutura/app/Services Modules/Infraestrutura/app/Http/Controllers Modules/Infraestrutura/routes/web.php Modules/Infraestrutura/tests/Feature/SalaHttpTest.php
git commit -m "feat(infraestrutura): adiciona Services, SalaController e rotas de Sala"
```

---

## Fora de escopo (herdado da spec)

Frontend (páginas Vue/Inertia). Módulos/entidades `Equipamento`, `Inventario`, `Manutencao`. FK `turmas.sala_id` ou entidade de agendamento Sala↔Horário↔Turma. Modelagem estruturada de Bloco/Edifício/Andar. Verificação de dependentes em `EliminarSalaAction`. Relatórios/dashboards de ocupação. Seeders de dados fictícios.
