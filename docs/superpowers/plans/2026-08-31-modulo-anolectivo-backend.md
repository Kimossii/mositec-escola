# Módulo AnoLectivo (Backend) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Criar o módulo `Modules\AnoLectivo` completo no backend — Ano Lectivo, Períodos e Calendário Escolar — seguindo exactamente os padrões já usados em `Modules\Estabelecimento`/`Modules\Usuario`, sem introduzir arquitectura paralela. Frontend fica fora deste plano.

**Architecture:** Módulo nwidart/laravel-modules com Models/Enums/DTOs/Actions/Policies/Requests/Controllers/Services/Routes, mais um trait novo e genérico `Modules\Core\Traits\RegistaAutoria`. `AnoLectivo` é dono de `Periodo` e `EventoCalendario` (hasMany/belongsTo). Autorização via uma única gate `gerir-ano-letivo` (mesmo padrão de `gerir-usuarios`/`gerir-estabelecimento`). Regra "no máximo um Ano Lectivo activo" aplicada nas Actions via `DB::transaction` + `lockForUpdate()`, sempre escopada por `estabelecimento_id`.

**Tech Stack:** Laravel, nwidart/laravel-modules, Inertia (render-only nesta fase), PHPUnit, SQLite `:memory:` em testes.

**Spec:** [docs/superpowers/specs/2026-08-30-modulo-anolectivo-design.md](../specs/2026-08-30-modulo-anolectivo-design.md)

## Global Constraints

- Namespaces exactamente `Modules\AnoLectivo\{Models,Enums,DTO,Actions,Http\Controllers,Http\Requests,Policies,Providers,Services}`, mesma forma de `Modules\Estabelecimento`.
- Todos os enums são `int`-backed com `label(): string` via `match` — sem strings soltas no código.
- Regra de negócio: **no máximo um `AnoLectivo` com `estado = ATIVO` por `estabelecimento_id`** (nunca unicidade global) — verificada dentro de `DB::transaction` com `lockForUpdate()`.
- `AnoLectivo` usa `SoftDeletes`; `Periodo` e `EventoCalendario` não usam soft delete.
- `criado_por`/`editado_por` só são preenchidos automaticamente nestes 3 Models, via `Modules\Core\Traits\RegistaAutoria` — nenhum outro módulo é alterado.
- Autorização: uma única `Gate::define('gerir-ano-letivo', ...)` em `app/Providers/AppServiceProvider.php`, mesma forma (`$user->roles->contains('nome', Perfil::ADMIN_ESCOLA->value)`) das gates já existentes. Nenhuma ligação nova a `RolePermissao`/`UserPermissao`.
- `ano_lectivos.estabelecimento_id` é `nullable` (compatibilidade temporária single-tenant), populado a partir de `Estabelecimento::current()?->id`, sem `GlobalScope`.
- Regras de negócio que precisam de consultar a BD (sobreposição, intervalo, único activo, dependentes) ficam nas Actions, nunca nos Form Requests nem inline nos Controllers.
- Controllers nunca chamam Actions directamente — só através de `GestaoAnoLectivoService`/`AnoLectivoConsultaService`, mesmo padrão de `GestaoEstabelecimentoService`.
- Testes: PHPUnit (não Pest), `RefreshDatabase`, helper privado `actingAsStaff()` por ficheiro de teste (mesmo padrão de `Modules/Usuario/tests/Feature/CriarUsuarioTest.php` — sem trait de teste partilhada nova).
- Sem frontend: Controllers usam `Inertia::render(...)` desde já; a fase de frontend só criará os `.vue`.

---

## File Structure

```
Modules/AnoLectivo/
  module.json                                          (novo)
  composer.json                                        (novo)
  config/config.php                                    (novo)
  routes/web.php                                       (novo)
  routes/api.php                                       (novo, vazio — exigido pelo RouteServiceProvider)
  app/
    Providers/AnoLectivoServiceProvider.php             (novo)
    Providers/RouteServiceProvider.php                  (novo)
    Providers/EventServiceProvider.php                  (novo)
    Enums/EstadoAnoLectivo.php                          (novo)
    Enums/TipoPeriodo.php                               (novo)
    Enums/TipoEventoCalendario.php                      (novo)
    Models/AnoLectivo.php                               (novo)
    Models/Periodo.php                                  (novo)
    Models/EventoCalendario.php                         (novo)
    Policies/AnoLectivoPolicy.php                       (novo)
    Policies/PeriodoPolicy.php                           (novo)
    Policies/EventoCalendarioPolicy.php                  (novo)
    DTO/AnoLectivoDTO.php                                (novo)
    DTO/PeriodoDTO.php                                   (novo)
    DTO/EventoCalendarioDTO.php                          (novo)
    Actions/Concerns/GarantiaAnoLectivoAtivoUnico.php    (novo)
    Actions/Concerns/ValidaIntervaloPeriodo.php          (novo)
    Actions/Concerns/ValidaIntervaloEvento.php           (novo)
    Actions/CriarAnoLectivoAction.php                    (novo)
    Actions/AtualizarAnoLectivoAction.php                (novo)
    Actions/AlterarEstadoAnoLectivoAction.php            (novo)
    Actions/EliminarAnoLectivoAction.php                 (novo)
    Actions/CriarPeriodoAction.php                       (novo)
    Actions/AtualizarPeriodoAction.php                   (novo)
    Actions/EliminarPeriodoAction.php                    (novo)
    Actions/CriarEventoCalendarioAction.php              (novo)
    Actions/AtualizarEventoCalendarioAction.php          (novo)
    Actions/EliminarEventoCalendarioAction.php            (novo)
    Http/Requests/CriarAnoLectivoRequest.php             (novo)
    Http/Requests/AtualizarAnoLectivoRequest.php         (novo)
    Http/Requests/AlterarEstadoAnoLectivoRequest.php     (novo)
    Http/Requests/CriarPeriodoRequest.php                (novo)
    Http/Requests/AtualizarPeriodoRequest.php            (novo)
    Http/Requests/CriarEventoCalendarioRequest.php       (novo)
    Http/Requests/AtualizarEventoCalendarioRequest.php   (novo)
    Http/Controllers/AnoLectivoController.php            (novo)
    Http/Controllers/PeriodoController.php               (novo)
    Http/Controllers/EventoCalendarioController.php      (novo)
    Services/GestaoAnoLectivoService.php                 (novo)
    Services/AnoLectivoConsultaService.php               (novo)
  database/migrations/2026_08_31_090000_create_ano_lectivos_table.php        (novo)
  database/migrations/2026_08_31_090100_create_periodos_table.php            (novo)
  database/migrations/2026_08_31_090200_create_eventos_calendario_table.php  (novo)
  tests/Feature/*.php                                   (novo, ver tasks)

Modules/Core/app/Traits/RegistaAutoria.php              (novo)

modules_statuses.json               (modificado: + "AnoLectivo": true)
app/Providers/AppServiceProvider.php (modificado: + Gate::define('gerir-ano-letivo', ...))
```

---

### Task 1: Scaffold do módulo AnoLectivo

**Files:**
- Create: `Modules/AnoLectivo/module.json`
- Create: `Modules/AnoLectivo/composer.json`
- Create: `Modules/AnoLectivo/config/config.php`
- Create: `Modules/AnoLectivo/app/Providers/AnoLectivoServiceProvider.php`
- Create: `Modules/AnoLectivo/app/Providers/RouteServiceProvider.php`
- Create: `Modules/AnoLectivo/app/Providers/EventServiceProvider.php`
- Create: `Modules/AnoLectivo/routes/web.php`
- Create: `Modules/AnoLectivo/routes/api.php`
- Modify: `modules_statuses.json`

**Interfaces:**
- Produces: módulo `AnoLectivo` registado e activo — todas as tasks seguintes assumem `php artisan module:list` a listar `AnoLectivo: Enabled`.

- [ ] **Step 1: Criar `module.json`**

```json
{
    "name": "AnoLectivo",
    "alias": "anolectivo",
    "description": "",
    "keywords": [],
    "priority": 0,
    "providers": [
        "Modules\\AnoLectivo\\Providers\\AnoLectivoServiceProvider"
    ],
    "files": []
}
```

- [ ] **Step 2: Criar `composer.json` do módulo**

```json
{
    "name": "nwidart/anolectivo",
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
            "Modules\\AnoLectivo\\": "app/",
            "Modules\\AnoLectivo\\Database\\Factories\\": "database/factories/",
            "Modules\\AnoLectivo\\Database\\Seeders\\": "database/seeders/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Modules\\AnoLectivo\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 3: Criar `config/config.php`**

```php
<?php

return [
    'name' => 'AnoLectivo',
];
```

- [ ] **Step 4: Criar `AnoLectivoServiceProvider`**

```php
<?php

namespace Modules\AnoLectivo\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class AnoLectivoServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'AnoLectivo';

    protected string $nameLower = 'anolectivo';

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

- [ ] **Step 5: Criar `RouteServiceProvider`**

```php
<?php

namespace Modules\AnoLectivo\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    protected string $name = 'AnoLectivo';

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

- [ ] **Step 6: Criar `EventServiceProvider`**

```php
<?php

namespace Modules\AnoLectivo\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [];

    protected static $shouldDiscoverEvents = true;
}
```

- [ ] **Step 7: Criar `routes/web.php` e `routes/api.php` (vazios por agora)**

`Modules/AnoLectivo/routes/web.php`:

```php
<?php

use Illuminate\Support\Facades\Route;
```

`Modules/AnoLectivo/routes/api.php`:

```php
<?php

use Illuminate\Support\Facades\Route;
```

- [ ] **Step 8: Activar o módulo em `modules_statuses.json`**

```json
{
    "Usuario": true,
    "Autenticacao": true,
    "Permissao": true,
    "Estabelecimento": true,
    "Core": true,
    "AnoLectivo": true
}
```

- [ ] **Step 9: Confirmar que o módulo carrega**

Run: `composer dump-autoload && php artisan module:list`
Expected: tabela de módulos lista `AnoLectivo` como `Enabled`, sem erros de boot.

- [ ] **Step 10: Commit**

```bash
git add Modules/AnoLectivo/module.json Modules/AnoLectivo/composer.json Modules/AnoLectivo/config/config.php \
        Modules/AnoLectivo/app/Providers Modules/AnoLectivo/routes modules_statuses.json composer.lock
git commit -m "feat(ano-lectivo): scaffold do módulo AnoLectivo"
```

---

### Task 2: Enums

**Files:**
- Create: `Modules/AnoLectivo/app/Enums/EstadoAnoLectivo.php`
- Create: `Modules/AnoLectivo/app/Enums/TipoPeriodo.php`
- Create: `Modules/AnoLectivo/app/Enums/TipoEventoCalendario.php`
- Test: `Modules/AnoLectivo/tests/Unit/EnumsTest.php`

**Interfaces:**
- Produces: `Modules\AnoLectivo\Enums\EstadoAnoLectivo` (`PLANEADO=0`, `ATIVO=1`, `ENCERRADO=2`), `Modules\AnoLectivo\Enums\TipoPeriodo` (`TRIMESTRE=0`, `SEMESTRE=1`, `OUTRO=2`), `Modules\AnoLectivo\Enums\TipoEventoCalendario` (`AULA=0`, `AVALIACAO=1`, `REUNIAO=2`, `FERIAS=3`, `FERIADO=4`, `ACTIVIDADE=5`, `EVENTO=6`, `OUTRO=7`) — todos com `label(): string`. Usados por todas as tasks seguintes.

- [ ] **Step 1: Escrever o teste (falha primeiro)**

```php
<?php

namespace Modules\AnoLectivo\Tests\Unit;

use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoEventoCalendario;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use PHPUnit\Framework\TestCase;

class EnumsTest extends TestCase
{
    public function test_estado_ano_lectivo_valores_e_labels(): void
    {
        $this->assertSame(0, EstadoAnoLectivo::PLANEADO->value);
        $this->assertSame(1, EstadoAnoLectivo::ATIVO->value);
        $this->assertSame(2, EstadoAnoLectivo::ENCERRADO->value);
        $this->assertSame('Planeado', EstadoAnoLectivo::PLANEADO->label());
        $this->assertSame('Activo', EstadoAnoLectivo::ATIVO->label());
        $this->assertSame('Encerrado', EstadoAnoLectivo::ENCERRADO->label());
    }

    public function test_tipo_periodo_valores_e_labels(): void
    {
        $this->assertSame(0, TipoPeriodo::TRIMESTRE->value);
        $this->assertSame(1, TipoPeriodo::SEMESTRE->value);
        $this->assertSame(2, TipoPeriodo::OUTRO->value);
        $this->assertSame('Trimestre', TipoPeriodo::TRIMESTRE->label());
    }

    public function test_tipo_evento_calendario_valores_e_labels(): void
    {
        $this->assertSame(0, TipoEventoCalendario::AULA->value);
        $this->assertSame(7, TipoEventoCalendario::OUTRO->value);
        $this->assertSame('Feriado', TipoEventoCalendario::FERIADO->label());
    }
}
```

- [ ] **Step 2: Correr o teste e confirmar que falha**

Run: `php artisan test Modules/AnoLectivo/tests/Unit/EnumsTest.php`
Expected: FAIL — classes de enum não existem.

- [ ] **Step 3: Implementar os 3 enums**

```php
<?php

namespace Modules\AnoLectivo\Enums;

enum EstadoAnoLectivo: int
{
    case PLANEADO = 0;
    case ATIVO = 1;
    case ENCERRADO = 2;

    public function label(): string
    {
        return match ($this) {
            self::PLANEADO => 'Planeado',
            self::ATIVO => 'Activo',
            self::ENCERRADO => 'Encerrado',
        };
    }
}
```

```php
<?php

namespace Modules\AnoLectivo\Enums;

enum TipoPeriodo: int
{
    case TRIMESTRE = 0;
    case SEMESTRE = 1;
    case OUTRO = 2;

    public function label(): string
    {
        return match ($this) {
            self::TRIMESTRE => 'Trimestre',
            self::SEMESTRE => 'Semestre',
            self::OUTRO => 'Outro',
        };
    }
}
```

```php
<?php

namespace Modules\AnoLectivo\Enums;

enum TipoEventoCalendario: int
{
    case AULA = 0;
    case AVALIACAO = 1;
    case REUNIAO = 2;
    case FERIAS = 3;
    case FERIADO = 4;
    case ACTIVIDADE = 5;
    case EVENTO = 6;
    case OUTRO = 7;

    public function label(): string
    {
        return match ($this) {
            self::AULA => 'Aula',
            self::AVALIACAO => 'Avaliação',
            self::REUNIAO => 'Reunião',
            self::FERIAS => 'Férias',
            self::FERIADO => 'Feriado',
            self::ACTIVIDADE => 'Actividade',
            self::EVENTO => 'Evento',
            self::OUTRO => 'Outro',
        };
    }
}
```

- [ ] **Step 4: Correr o teste e confirmar que passa**

Run: `php artisan test Modules/AnoLectivo/tests/Unit/EnumsTest.php`
Expected: 3 testes, PASS.

- [ ] **Step 5: Commit**

```bash
git add Modules/AnoLectivo/app/Enums Modules/AnoLectivo/tests/Unit/EnumsTest.php
git commit -m "feat(ano-lectivo): adicionar enums EstadoAnoLectivo, TipoPeriodo e TipoEventoCalendario"
```

---

### Task 3: Trait `RegistaAutoria` + Migration/Model `AnoLectivo`

**Files:**
- Create: `Modules/Core/app/Traits/RegistaAutoria.php`
- Create: `Modules/AnoLectivo/database/migrations/2026_08_31_090000_create_ano_lectivos_table.php`
- Create: `Modules/AnoLectivo/app/Models/AnoLectivo.php`
- Test: `Modules/AnoLectivo/tests/Feature/AnoLectivoModelTest.php`

**Interfaces:**
- Consumes: `Modules\AnoLectivo\Enums\EstadoAnoLectivo` (Task 2).
- Produces: `Modules\Core\Traits\RegistaAutoria` (hooks `creating`/`updating`, preenche `criado_por`/`editado_por` com `auth()->id()`) — reutilizado pelos Models `Periodo`/`EventoCalendario` nas Tasks 4/5. `Modules\AnoLectivo\Models\AnoLectivo` com `estabelecimento()`, `periodos()`, `eventosCalendario()`, `criadoPor()`, `editadoPor()`, `static current(?int $estabelecimentoId = null): ?self` — usado por todas as Actions seguintes.

- [ ] **Step 1: Escrever o teste (falha primeiro)**

```php
<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Permissao\Database\Seeders\RoleSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class AnoLectivoModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
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

    public function test_criar_ano_lectivo_preenche_estado_descricao_e_auditoria(): void
    {
        $staff = $this->actingAsStaff();

        $anoLectivo = AnoLectivo::create([
            'nome' => '2026/2027',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2027-07-31',
            'estado' => EstadoAnoLectivo::PLANEADO->value,
        ]);

        $this->assertSame('Planeado', $anoLectivo->fresh()->estado_descricao);
        $this->assertSame($staff->id, $anoLectivo->criado_por);
        $this->assertSame($staff->id, $anoLectivo->editado_por);
    }

    public function test_atualizar_ano_lectivo_actualiza_editado_por(): void
    {
        $this->actingAsStaff();

        $anoLectivo = AnoLectivo::create([
            'nome' => '2026/2027',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2027-07-31',
            'estado' => EstadoAnoLectivo::PLANEADO->value,
        ]);

        $editor = User::create(['name' => 'Editor', 'email' => 'editor@example.com', 'password' => Hash::make('x')]);
        $editor->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);
        $this->actingAs($editor);

        $anoLectivo->update(['nome' => '2026/2027 (revisto)']);

        $this->assertSame($editor->id, $anoLectivo->fresh()->editado_por);
    }

    public function test_soft_delete_mantem_o_registo_na_base_de_dados(): void
    {
        $this->actingAsStaff();

        $anoLectivo = AnoLectivo::create([
            'nome' => '2024/2025',
            'data_inicio' => '2024-09-01',
            'data_fim' => '2025-07-31',
            'estado' => EstadoAnoLectivo::ENCERRADO->value,
        ]);

        $anoLectivo->delete();

        $this->assertSoftDeleted('ano_lectivos', ['id' => $anoLectivo->id]);
    }

    public function test_current_devolve_o_ano_lectivo_activo(): void
    {
        $this->actingAsStaff();

        AnoLectivo::create([
            'nome' => '2025/2026',
            'data_inicio' => '2025-09-01',
            'data_fim' => '2026-07-31',
            'estado' => EstadoAnoLectivo::ENCERRADO->value,
        ]);

        $ativo = AnoLectivo::create([
            'nome' => '2026/2027',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2027-07-31',
            'estado' => EstadoAnoLectivo::ATIVO->value,
        ]);

        $this->assertTrue(AnoLectivo::current()->is($ativo));
    }
}
```

- [ ] **Step 2: Correr o teste e confirmar que falha**

Run: `php artisan test Modules/AnoLectivo/tests/Feature/AnoLectivoModelTest.php`
Expected: FAIL — tabela `ano_lectivos`/classe `AnoLectivo` não existem.

- [ ] **Step 3: Criar o trait `RegistaAutoria` no Core**

```php
<?php

namespace Modules\Core\Traits;

trait RegistaAutoria
{
    protected static function bootRegistaAutoria(): void
    {
        static::creating(function ($model) {
            $model->criado_por = $model->criado_por ?? auth()->id();
            $model->editado_por = $model->editado_por ?? auth()->id();
        });

        static::updating(function ($model) {
            $model->editado_por = auth()->id();
        });
    }
}
```

- [ ] **Step 4: Criar a migration `ano_lectivos`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ano_lectivos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estabelecimento_id')->nullable()->constrained('estabelecimentos')->nullOnDelete();
            $table->string('nome');
            $table->date('data_inicio');
            $table->date('data_fim');
            $table->unsignedTinyInteger('estado')->default(0); // 0: planeado | 1: activo | 2: encerrado
            $table->string('estado_descricao')->default('Planeado');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['estabelecimento_id', 'nome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ano_lectivos');
    }
};
```

- [ ] **Step 5: Criar o Model `AnoLectivo`**

```php
<?php

namespace Modules\AnoLectivo\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Models\User;

class AnoLectivo extends Model
{
    use HasFactory;
    use SoftDeletes;
    use RegistaAutoria;

    protected $table = 'ano_lectivos';

    protected $fillable = [
        'estabelecimento_id',
        'nome',
        'data_inicio',
        'data_fim',
        'estado',
        'criado_por',
        'editado_por',
    ];

    protected $casts = [
        'data_inicio' => 'date',
        'data_fim' => 'date',
        'estado' => EstadoAnoLectivo::class,
    ];

    public function estabelecimento(): BelongsTo
    {
        return $this->belongsTo(Estabelecimento::class);
    }

    public function periodos(): HasMany
    {
        return $this->hasMany(Periodo::class);
    }

    public function eventosCalendario(): HasMany
    {
        return $this->hasMany(EventoCalendario::class);
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function editadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editado_por');
    }

    public static function current(?int $estabelecimentoId = null): ?self
    {
        return static::where('estado', EstadoAnoLectivo::ATIVO)
            ->when($estabelecimentoId, fn ($query) => $query->where('estabelecimento_id', $estabelecimentoId))
            ->first();
    }

    protected static function booted(): void
    {
        static::saving(function (AnoLectivo $anoLectivo) {
            $anoLectivo->estado_descricao = $anoLectivo->estado?->label();
        });
    }
}
```

- [ ] **Step 6: Correr o teste e confirmar que passa**

Run: `php artisan test Modules/AnoLectivo/tests/Feature/AnoLectivoModelTest.php`
Expected: 4 testes, PASS.

- [ ] **Step 7: Commit**

```bash
git add Modules/Core/app/Traits/RegistaAutoria.php Modules/AnoLectivo/database/migrations \
        Modules/AnoLectivo/app/Models/AnoLectivo.php Modules/AnoLectivo/tests/Feature/AnoLectivoModelTest.php
git commit -m "feat(ano-lectivo): trait RegistaAutoria e Model AnoLectivo"
```

---

### Task 4: Migration/Model `Periodo`

**Files:**
- Create: `Modules/AnoLectivo/database/migrations/2026_08_31_090100_create_periodos_table.php`
- Create: `Modules/AnoLectivo/app/Models/Periodo.php`
- Test: `Modules/AnoLectivo/tests/Feature/PeriodoModelTest.php`

**Interfaces:**
- Consumes: `Modules\AnoLectivo\Models\AnoLectivo` (Task 3), `Modules\AnoLectivo\Enums\TipoPeriodo` (Task 2), `Modules\Core\Enums\Estado`/`Modules\Core\Traits\SincronizaEstadoDescricao` (já existentes), `Modules\Core\Traits\RegistaAutoria` (Task 3).
- Produces: `Modules\AnoLectivo\Models\Periodo` com `anoLectivo(): BelongsTo` — usado pelas Actions de Período (Tasks 11-13).

- [ ] **Step 1: Escrever o teste (falha primeiro)**

```php
<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Permissao\Database\Seeders\RoleSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class PeriodoModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
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

    public function test_periodo_pertence_ao_ano_lectivo_e_sincroniza_descricoes(): void
    {
        $this->actingAsStaff();

        $anoLectivo = AnoLectivo::create([
            'nome' => '2026/2027',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2027-07-31',
            'estado' => EstadoAnoLectivo::ATIVO->value,
        ]);

        $periodo = $anoLectivo->periodos()->create([
            'nome' => '1.º Trimestre',
            'tipo' => TipoPeriodo::TRIMESTRE->value,
            'numero' => 1,
            'data_inicio' => '2026-09-01',
            'data_fim' => '2026-12-15',
        ]);

        $this->assertTrue($periodo->anoLectivo->is($anoLectivo));
        $this->assertSame('Trimestre', $periodo->fresh()->tipo_descricao);
        $this->assertSame('Ativo', $periodo->fresh()->estado_descricao);
    }
}
```

- [ ] **Step 2: Correr o teste e confirmar que falha**

Run: `php artisan test Modules/AnoLectivo/tests/Feature/PeriodoModelTest.php`
Expected: FAIL — tabela `periodos`/classe `Periodo` não existem.

- [ ] **Step 3: Criar a migration `periodos`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('periodos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ano_lectivo_id')->constrained('ano_lectivos')->cascadeOnDelete();
            $table->string('nome');
            $table->unsignedTinyInteger('tipo')->default(0); // 0: trimestre | 1: semestre | 2: outro
            $table->string('tipo_descricao')->nullable();
            $table->unsignedTinyInteger('numero')->nullable();
            $table->date('data_inicio');
            $table->date('data_fim');
            $table->unsignedTinyInteger('estado')->default(1); // 0: inativo, 1: ativo
            $table->string('estado_descricao')->default('Ativo');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['ano_lectivo_id', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('periodos');
    }
};
```

- [ ] **Step 4: Criar o Model `Periodo`**

```php
<?php

namespace Modules\AnoLectivo\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Core\Traits\SincronizaEstadoDescricao;

class Periodo extends Model
{
    use HasFactory;
    use SincronizaEstadoDescricao;
    use RegistaAutoria;

    protected $table = 'periodos';

    protected $fillable = [
        'ano_lectivo_id',
        'nome',
        'tipo',
        'numero',
        'data_inicio',
        'data_fim',
        'estado',
        'criado_por',
        'editado_por',
    ];

    protected $casts = [
        'data_inicio' => 'date',
        'data_fim' => 'date',
        'tipo' => TipoPeriodo::class,
    ];

    public function anoLectivo(): BelongsTo
    {
        return $this->belongsTo(AnoLectivo::class);
    }

    protected static function booted(): void
    {
        static::saving(function (Periodo $periodo) {
            $periodo->tipo_descricao = $periodo->tipo?->label();
        });
    }
}
```

- [ ] **Step 5: Correr o teste e confirmar que passa**

Run: `php artisan test Modules/AnoLectivo/tests/Feature/PeriodoModelTest.php`
Expected: 1 teste, PASS.

- [ ] **Step 6: Commit**

```bash
git add Modules/AnoLectivo/database/migrations/2026_08_31_090100_create_periodos_table.php \
        Modules/AnoLectivo/app/Models/Periodo.php Modules/AnoLectivo/tests/Feature/PeriodoModelTest.php
git commit -m "feat(ano-lectivo): Model Periodo"
```

---

### Task 5: Migration/Model `EventoCalendario`

**Files:**
- Create: `Modules/AnoLectivo/database/migrations/2026_08_31_090200_create_eventos_calendario_table.php`
- Create: `Modules/AnoLectivo/app/Models/EventoCalendario.php`
- Test: `Modules/AnoLectivo/tests/Feature/EventoCalendarioModelTest.php`

**Interfaces:**
- Consumes: `Modules\AnoLectivo\Models\AnoLectivo` (Task 3), `Modules\AnoLectivo\Enums\TipoEventoCalendario` (Task 2), `Modules\Core\Traits\SincronizaEstadoDescricao`/`RegistaAutoria`.
- Produces: `Modules\AnoLectivo\Models\EventoCalendario` com `anoLectivo(): BelongsTo` — usado pelas Actions de Evento (Tasks 14-16).

- [ ] **Step 1: Escrever o teste (falha primeiro)**

```php
<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoEventoCalendario;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Permissao\Database\Seeders\RoleSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class EventoCalendarioModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
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

    public function test_evento_pertence_ao_ano_lectivo_e_sincroniza_descricoes(): void
    {
        $this->actingAsStaff();

        $anoLectivo = AnoLectivo::create([
            'nome' => '2026/2027',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2027-07-31',
            'estado' => EstadoAnoLectivo::ATIVO->value,
        ]);

        $evento = $anoLectivo->eventosCalendario()->create([
            'titulo' => 'Início das aulas',
            'tipo' => TipoEventoCalendario::AULA->value,
            'data_inicio' => '2026-09-01',
            'data_fim' => '2026-09-01',
            'dia_inteiro' => true,
        ]);

        $this->assertTrue($evento->anoLectivo->is($anoLectivo));
        $this->assertSame('Aula', $evento->fresh()->tipo_descricao);
        $this->assertSame('Ativo', $evento->fresh()->estado_descricao);
    }
}
```

- [ ] **Step 2: Correr o teste e confirmar que falha**

Run: `php artisan test Modules/AnoLectivo/tests/Feature/EventoCalendarioModelTest.php`
Expected: FAIL — tabela `eventos_calendario`/classe `EventoCalendario` não existem.

- [ ] **Step 3: Criar a migration `eventos_calendario`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eventos_calendario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ano_lectivo_id')->constrained('ano_lectivos')->cascadeOnDelete();
            $table->string('titulo');
            $table->text('descricao')->nullable();
            $table->unsignedTinyInteger('tipo')->default(6); // 0: aula | 1: avaliação | 2: reunião | 3: férias | 4: feriado | 5: actividade | 6: evento | 7: outro
            $table->string('tipo_descricao')->nullable();
            $table->date('data_inicio');
            $table->date('data_fim');
            $table->boolean('dia_inteiro')->default(true);
            $table->unsignedTinyInteger('estado')->default(1); // 0: inativo, 1: ativo
            $table->string('estado_descricao')->default('Ativo');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventos_calendario');
    }
};
```

- [ ] **Step 4: Criar o Model `EventoCalendario`**

```php
<?php

namespace Modules\AnoLectivo\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\AnoLectivo\Enums\TipoEventoCalendario;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Core\Traits\SincronizaEstadoDescricao;

class EventoCalendario extends Model
{
    use HasFactory;
    use SincronizaEstadoDescricao;
    use RegistaAutoria;

    protected $table = 'eventos_calendario';

    protected $fillable = [
        'ano_lectivo_id',
        'titulo',
        'descricao',
        'tipo',
        'data_inicio',
        'data_fim',
        'dia_inteiro',
        'estado',
        'criado_por',
        'editado_por',
    ];

    protected $casts = [
        'data_inicio' => 'date',
        'data_fim' => 'date',
        'dia_inteiro' => 'boolean',
        'tipo' => TipoEventoCalendario::class,
    ];

    public function anoLectivo(): BelongsTo
    {
        return $this->belongsTo(AnoLectivo::class);
    }

    protected static function booted(): void
    {
        static::saving(function (EventoCalendario $evento) {
            $evento->tipo_descricao = $evento->tipo?->label();
        });
    }
}
```

- [ ] **Step 5: Correr o teste e confirmar que passa**

Run: `php artisan test Modules/AnoLectivo/tests/Feature/EventoCalendarioModelTest.php`
Expected: 1 teste, PASS.

- [ ] **Step 6: Commit**

```bash
git add Modules/AnoLectivo/database/migrations/2026_08_31_090200_create_eventos_calendario_table.php \
        Modules/AnoLectivo/app/Models/EventoCalendario.php Modules/AnoLectivo/tests/Feature/EventoCalendarioModelTest.php
git commit -m "feat(ano-lectivo): Model EventoCalendario"
```

---

### Task 6: Gate `gerir-ano-letivo` + Policies

**Files:**
- Modify: `app/Providers/AppServiceProvider.php`
- Create: `Modules/AnoLectivo/app/Policies/AnoLectivoPolicy.php`
- Create: `Modules/AnoLectivo/app/Policies/PeriodoPolicy.php`
- Create: `Modules/AnoLectivo/app/Policies/EventoCalendarioPolicy.php`
- Test: `Modules/AnoLectivo/tests/Feature/AnoLectivoAutorizacaoTest.php`

**Interfaces:**
- Consumes: Models das Tasks 3-5.
- Produces: gate `gerir-ano-letivo`; Policies auto-descobertas por convenção (`Models\X` → `Policies\XPolicy`) — usadas por todos os Controllers (Task 17).

- [ ] **Step 1: Escrever o teste (falha primeiro)**

```php
<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\AnoLectivo\Models\EventoCalendario;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Permissao\Database\Seeders\RoleSeeder;
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

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_escola_tem_permissao_gerir_ano_letivo(): void
    {
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);

        $this->assertTrue(Gate::forUser($staff)->allows('gerir-ano-letivo'));
        $this->assertTrue(Gate::forUser($staff)->allows('view', AnoLectivo::class));
        $this->assertTrue(Gate::forUser($staff)->allows('view', Periodo::class));
        $this->assertTrue(Gate::forUser($staff)->allows('view', EventoCalendario::class));
    }

    public function test_professor_nao_tem_permissao_gerir_ano_letivo(): void
    {
        $professor = User::create(['name' => 'Professor', 'email' => 'professor@example.com', 'password' => Hash::make('x')]);
        $professor->roles()->syncWithoutDetaching([Role::where('nome', Perfil::PROFESSOR->value)->first()->id]);

        $this->assertFalse(Gate::forUser($professor)->allows('gerir-ano-letivo'));
        $this->assertFalse(Gate::forUser($professor)->allows('view', AnoLectivo::class));
    }
}
```

- [ ] **Step 2: Correr o teste e confirmar que falha**

Run: `php artisan test Modules/AnoLectivo/tests/Feature/AnoLectivoAutorizacaoTest.php`
Expected: FAIL — gate `gerir-ano-letivo` não existe.

- [ ] **Step 3: Adicionar a gate em `AppServiceProvider`**

Em `app/Providers/AppServiceProvider.php`, dentro de `boot()`, junto às gates existentes:

```php
Gate::define('gerir-ano-letivo', function (User $user) {
    return $user->roles->contains('nome', Perfil::ADMIN_ESCOLA->value);
});
```

- [ ] **Step 4: Criar as 3 Policies**

```php
<?php

namespace Modules\AnoLectivo\Policies;

use Modules\Usuario\Models\User;

class AnoLectivoPolicy
{
    public function view(User $user): bool
    {
        return $user->can('gerir-ano-letivo');
    }

    public function create(User $user): bool
    {
        return $user->can('gerir-ano-letivo');
    }

    public function update(User $user): bool
    {
        return $user->can('gerir-ano-letivo');
    }

    public function delete(User $user): bool
    {
        return $user->can('gerir-ano-letivo');
    }
}
```

```php
<?php

namespace Modules\AnoLectivo\Policies;

use Modules\Usuario\Models\User;

class PeriodoPolicy
{
    public function view(User $user): bool
    {
        return $user->can('gerir-ano-letivo');
    }

    public function create(User $user): bool
    {
        return $user->can('gerir-ano-letivo');
    }

    public function update(User $user): bool
    {
        return $user->can('gerir-ano-letivo');
    }

    public function delete(User $user): bool
    {
        return $user->can('gerir-ano-letivo');
    }
}
```

```php
<?php

namespace Modules\AnoLectivo\Policies;

use Modules\Usuario\Models\User;

class EventoCalendarioPolicy
{
    public function view(User $user): bool
    {
        return $user->can('gerir-ano-letivo');
    }

    public function create(User $user): bool
    {
        return $user->can('gerir-ano-letivo');
    }

    public function update(User $user): bool
    {
        return $user->can('gerir-ano-letivo');
    }

    public function delete(User $user): bool
    {
        return $user->can('gerir-ano-letivo');
    }
}
```

- [ ] **Step 5: Correr o teste e confirmar que passa**

Run: `php artisan test Modules/AnoLectivo/tests/Feature/AnoLectivoAutorizacaoTest.php`
Expected: 2 testes, PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Providers/AppServiceProvider.php Modules/AnoLectivo/app/Policies \
        Modules/AnoLectivo/tests/Feature/AnoLectivoAutorizacaoTest.php
git commit -m "feat(ano-lectivo): gate gerir-ano-letivo e Policies"
```

---

### Task 7: `CriarAnoLectivoAction` + regra "único activo"

**Files:**
- Create: `Modules/AnoLectivo/app/DTO/AnoLectivoDTO.php`
- Create: `Modules/AnoLectivo/app/Http/Requests/CriarAnoLectivoRequest.php`
- Create: `Modules/AnoLectivo/app/Actions/Concerns/GarantiaAnoLectivoAtivoUnico.php`
- Create: `Modules/AnoLectivo/app/Actions/CriarAnoLectivoAction.php`
- Test: `Modules/AnoLectivo/tests/Feature/CriarAnoLectivoActionTest.php`

**Interfaces:**
- Consumes: `Modules\AnoLectivo\Models\AnoLectivo` (Task 3), `Modules\Estabelecimento\Models\Estabelecimento::current()` (já existente).
- Produces: `Modules\AnoLectivo\DTO\AnoLectivoDTO` (`nome`, `dataInicio`, `dataFim`, `estado`); `Modules\AnoLectivo\Actions\Concerns\GarantiaAnoLectivoAtivoUnico` com `protected function garantirUnicoAtivo(?int $estabelecimentoId, ?int $idAtual = null): void` — reutilizado pelas Tasks 8 e 9; `Modules\AnoLectivo\Actions\CriarAnoLectivoAction::criar(AnoLectivoDTO $dto): AnoLectivo`.

- [ ] **Step 1: Escrever o teste (falha primeiro)**

```php
<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Actions\CriarAnoLectivoAction;
use Modules\AnoLectivo\DTO\AnoLectivoDTO;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\RoleSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class CriarAnoLectivoActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
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

    public function test_cria_ano_lectivo_associado_ao_estabelecimento_activo(): void
    {
        $this->actingAsStaff();
        $estabelecimento = Estabelecimento::create([
            'nome' => 'Escola Teste',
            'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
            'is_active' => true,
        ]);

        $anoLectivo = (new CriarAnoLectivoAction())->criar(new AnoLectivoDTO(
            nome: '2026/2027',
            dataInicio: '2026-09-01',
            dataFim: '2027-07-31',
            estado: EstadoAnoLectivo::ATIVO,
        ));

        $this->assertSame($estabelecimento->id, $anoLectivo->estabelecimento_id);
        $this->assertSame(EstadoAnoLectivo::ATIVO, $anoLectivo->estado);
    }

    public function test_bloqueia_segundo_ano_lectivo_activo_no_mesmo_estabelecimento(): void
    {
        $this->actingAsStaff();
        Estabelecimento::create([
            'nome' => 'Escola Teste',
            'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
            'is_active' => true,
        ]);

        (new CriarAnoLectivoAction())->criar(new AnoLectivoDTO(
            nome: '2026/2027',
            dataInicio: '2026-09-01',
            dataFim: '2027-07-31',
            estado: EstadoAnoLectivo::ATIVO,
        ));

        $this->expectException(ValidationException::class);

        try {
            (new CriarAnoLectivoAction())->criar(new AnoLectivoDTO(
                nome: '2027/2028',
                dataInicio: '2027-09-01',
                dataFim: '2028-07-31',
                estado: EstadoAnoLectivo::ATIVO,
            ));
        } finally {
            $this->assertSame(1, AnoLectivo::where('estado', EstadoAnoLectivo::ATIVO->value)->count());
        }
    }

    public function test_permite_criar_ano_lectivo_planeado_com_outro_ja_activo(): void
    {
        $this->actingAsStaff();
        Estabelecimento::create([
            'nome' => 'Escola Teste',
            'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
            'is_active' => true,
        ]);

        (new CriarAnoLectivoAction())->criar(new AnoLectivoDTO(
            nome: '2026/2027',
            dataInicio: '2026-09-01',
            dataFim: '2027-07-31',
            estado: EstadoAnoLectivo::ATIVO,
        ));

        $planeado = (new CriarAnoLectivoAction())->criar(new AnoLectivoDTO(
            nome: '2027/2028',
            dataInicio: '2027-09-01',
            dataFim: '2028-07-31',
            estado: EstadoAnoLectivo::PLANEADO,
        ));

        $this->assertSame(EstadoAnoLectivo::PLANEADO, $planeado->estado);
    }
}
```

- [ ] **Step 2: Correr o teste e confirmar que falha**

Run: `php artisan test Modules/AnoLectivo/tests/Feature/CriarAnoLectivoActionTest.php`
Expected: FAIL — `AnoLectivoDTO`/`CriarAnoLectivoAction` não existem.

- [ ] **Step 3: Criar `AnoLectivoDTO`**

```php
<?php

namespace Modules\AnoLectivo\DTO;

use Illuminate\Foundation\Http\FormRequest;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;

class AnoLectivoDTO
{
    public function __construct(
        public string $nome,
        public string $dataInicio,
        public string $dataFim,
        public EstadoAnoLectivo $estado = EstadoAnoLectivo::PLANEADO,
    ) {}

    public static function fromRequest(FormRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            nome: $dados['nome'],
            dataInicio: $dados['data_inicio'],
            dataFim: $dados['data_fim'],
            estado: isset($dados['estado']) ? EstadoAnoLectivo::from((int) $dados['estado']) : EstadoAnoLectivo::PLANEADO,
        );
    }
}
```

- [ ] **Step 4: Criar `CriarAnoLectivoRequest`**

```php
<?php

namespace Modules\AnoLectivo\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rules\Enum;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;

class CriarAnoLectivoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gerir-ano-letivo') ?? false;
    }

    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'data_inicio' => ['required', 'date'],
            'data_fim' => ['required', 'date', 'after_or_equal:data_inicio'],
            'estado' => ['sometimes', new Enum(EstadoAnoLectivo::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'nome.required' => 'O nome do ano lectivo é obrigatório.',
            'data_inicio.required' => 'A data de início é obrigatória.',
            'data_inicio.date' => 'A data de início tem de ser uma data válida.',
            'data_fim.required' => 'A data de fim é obrigatória.',
            'data_fim.date' => 'A data de fim tem de ser uma data válida.',
            'data_fim.after_or_equal' => 'A data de fim não pode ser anterior à data de início.',
        ];
    }
}
```

- [ ] **Step 5: Criar a trait `GarantiaAnoLectivoAtivoUnico`**

```php
<?php

namespace Modules\AnoLectivo\Actions\Concerns;

use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;

trait GarantiaAnoLectivoAtivoUnico
{
    protected function garantirUnicoAtivo(?int $estabelecimentoId, ?int $idAtual = null): void
    {
        $existeOutroAtivo = AnoLectivo::where('estado', EstadoAnoLectivo::ATIVO->value)
            ->where('estabelecimento_id', $estabelecimentoId)
            ->when($idAtual, fn ($query) => $query->where('id', '!=', $idAtual))
            ->lockForUpdate()
            ->exists();

        if ($existeOutroAtivo) {
            throw ValidationException::withMessages([
                'estado' => 'Já existe um Ano Lectivo activo para este estabelecimento.',
            ]);
        }
    }
}
```

- [ ] **Step 6: Criar `CriarAnoLectivoAction`**

```php
<?php

namespace Modules\AnoLectivo\Actions;

use Illuminate\Support\Facades\DB;
use Modules\AnoLectivo\Actions\Concerns\GarantiaAnoLectivoAtivoUnico;
use Modules\AnoLectivo\DTO\AnoLectivoDTO;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Estabelecimento\Models\Estabelecimento;

class CriarAnoLectivoAction
{
    use GarantiaAnoLectivoAtivoUnico;

    public function criar(AnoLectivoDTO $dto): AnoLectivo
    {
        return DB::transaction(function () use ($dto) {
            $estabelecimentoId = Estabelecimento::current()?->id;

            if ($dto->estado === EstadoAnoLectivo::ATIVO) {
                $this->garantirUnicoAtivo($estabelecimentoId);
            }

            return AnoLectivo::create([
                'estabelecimento_id' => $estabelecimentoId,
                'nome' => $dto->nome,
                'data_inicio' => $dto->dataInicio,
                'data_fim' => $dto->dataFim,
                'estado' => $dto->estado->value,
            ]);
        });
    }
}
```

- [ ] **Step 7: Correr o teste e confirmar que passa**

Run: `php artisan test Modules/AnoLectivo/tests/Feature/CriarAnoLectivoActionTest.php`
Expected: 3 testes, PASS.

- [ ] **Step 8: Commit**

```bash
git add Modules/AnoLectivo/app/DTO/AnoLectivoDTO.php Modules/AnoLectivo/app/Http/Requests/CriarAnoLectivoRequest.php \
        Modules/AnoLectivo/app/Actions/Concerns/GarantiaAnoLectivoAtivoUnico.php \
        Modules/AnoLectivo/app/Actions/CriarAnoLectivoAction.php \
        Modules/AnoLectivo/tests/Feature/CriarAnoLectivoActionTest.php
git commit -m "feat(ano-lectivo): CriarAnoLectivoAction com regra de único activo por estabelecimento"
```

---

### Task 8: `AtualizarAnoLectivoAction`

**Files:**
- Create: `Modules/AnoLectivo/app/Http/Requests/AtualizarAnoLectivoRequest.php`
- Create: `Modules/AnoLectivo/app/Actions/AtualizarAnoLectivoAction.php`
- Test: `Modules/AnoLectivo/tests/Feature/AtualizarAnoLectivoActionTest.php`

**Interfaces:**
- Consumes: `Modules\AnoLectivo\DTO\AnoLectivoDTO`, `Modules\AnoLectivo\Actions\Concerns\GarantiaAnoLectivoAtivoUnico` (Task 7).
- Produces: `Modules\AnoLectivo\Actions\AtualizarAnoLectivoAction::atualizar(AnoLectivo $anoLectivo, AnoLectivoDTO $dto): AnoLectivo`.

- [ ] **Step 1: Escrever o teste (falha primeiro)**

```php
<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Actions\AtualizarAnoLectivoAction;
use Modules\AnoLectivo\Actions\CriarAnoLectivoAction;
use Modules\AnoLectivo\DTO\AnoLectivoDTO;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\RoleSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class AtualizarAnoLectivoActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);
        $this->actingAs($staff);

        Estabelecimento::create([
            'nome' => 'Escola Teste',
            'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
            'is_active' => true,
        ]);
    }

    public function test_atualiza_nome_e_datas(): void
    {
        $anoLectivo = (new CriarAnoLectivoAction())->criar(new AnoLectivoDTO(
            nome: '2026/2027',
            dataInicio: '2026-09-01',
            dataFim: '2027-07-31',
            estado: EstadoAnoLectivo::PLANEADO,
        ));

        $actualizado = (new AtualizarAnoLectivoAction())->atualizar($anoLectivo, new AnoLectivoDTO(
            nome: '2026/2027 (revisto)',
            dataInicio: '2026-09-15',
            dataFim: '2027-07-31',
            estado: EstadoAnoLectivo::PLANEADO,
        ));

        $this->assertSame('2026/2027 (revisto)', $actualizado->nome);
        $this->assertSame('2026-09-15', $actualizado->data_inicio->toDateString());
    }

    public function test_bloqueia_activar_quando_outro_ja_esta_activo(): void
    {
        $ativo = (new CriarAnoLectivoAction())->criar(new AnoLectivoDTO(
            nome: '2026/2027',
            dataInicio: '2026-09-01',
            dataFim: '2027-07-31',
            estado: EstadoAnoLectivo::ATIVO,
        ));

        $planeado = (new CriarAnoLectivoAction())->criar(new AnoLectivoDTO(
            nome: '2027/2028',
            dataInicio: '2027-09-01',
            dataFim: '2028-07-31',
            estado: EstadoAnoLectivo::PLANEADO,
        ));

        $this->expectException(ValidationException::class);

        (new AtualizarAnoLectivoAction())->atualizar($planeado, new AnoLectivoDTO(
            nome: $planeado->nome,
            dataInicio: '2027-09-01',
            dataFim: '2028-07-31',
            estado: EstadoAnoLectivo::ATIVO,
        ));
    }

    public function test_permite_reactivar_o_proprio_ano_lectivo_ja_activo(): void
    {
        $ativo = (new CriarAnoLectivoAction())->criar(new AnoLectivoDTO(
            nome: '2026/2027',
            dataInicio: '2026-09-01',
            dataFim: '2027-07-31',
            estado: EstadoAnoLectivo::ATIVO,
        ));

        $actualizado = (new AtualizarAnoLectivoAction())->atualizar($ativo, new AnoLectivoDTO(
            nome: '2026/2027',
            dataInicio: '2026-09-01',
            dataFim: '2027-07-31',
            estado: EstadoAnoLectivo::ATIVO,
        ));

        $this->assertSame(EstadoAnoLectivo::ATIVO, $actualizado->estado);
    }
}
```

- [ ] **Step 2: Correr o teste e confirmar que falha**

Run: `php artisan test Modules/AnoLectivo/tests/Feature/AtualizarAnoLectivoActionTest.php`
Expected: FAIL — `AtualizarAnoLectivoAction` não existe.

- [ ] **Step 3: Criar `AtualizarAnoLectivoRequest`**

```php
<?php

namespace Modules\AnoLectivo\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rules\Enum;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;

class AtualizarAnoLectivoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gerir-ano-letivo') ?? false;
    }

    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'data_inicio' => ['required', 'date'],
            'data_fim' => ['required', 'date', 'after_or_equal:data_inicio'],
            'estado' => ['sometimes', new Enum(EstadoAnoLectivo::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'nome.required' => 'O nome do ano lectivo é obrigatório.',
            'data_fim.after_or_equal' => 'A data de fim não pode ser anterior à data de início.',
        ];
    }
}
```

- [ ] **Step 4: Criar `AtualizarAnoLectivoAction`**

```php
<?php

namespace Modules\AnoLectivo\Actions;

use Illuminate\Support\Facades\DB;
use Modules\AnoLectivo\Actions\Concerns\GarantiaAnoLectivoAtivoUnico;
use Modules\AnoLectivo\DTO\AnoLectivoDTO;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;

class AtualizarAnoLectivoAction
{
    use GarantiaAnoLectivoAtivoUnico;

    public function atualizar(AnoLectivo $anoLectivo, AnoLectivoDTO $dto): AnoLectivo
    {
        return DB::transaction(function () use ($anoLectivo, $dto) {
            if ($dto->estado === EstadoAnoLectivo::ATIVO) {
                $this->garantirUnicoAtivo($anoLectivo->estabelecimento_id, $anoLectivo->id);
            }

            $anoLectivo->update([
                'nome' => $dto->nome,
                'data_inicio' => $dto->dataInicio,
                'data_fim' => $dto->dataFim,
                'estado' => $dto->estado->value,
            ]);

            return $anoLectivo->fresh();
        });
    }
}
```

- [ ] **Step 5: Correr o teste e confirmar que passa**

Run: `php artisan test Modules/AnoLectivo/tests/Feature/AtualizarAnoLectivoActionTest.php`
Expected: 3 testes, PASS.

- [ ] **Step 6: Commit**

```bash
git add Modules/AnoLectivo/app/Http/Requests/AtualizarAnoLectivoRequest.php \
        Modules/AnoLectivo/app/Actions/AtualizarAnoLectivoAction.php \
        Modules/AnoLectivo/tests/Feature/AtualizarAnoLectivoActionTest.php
git commit -m "feat(ano-lectivo): AtualizarAnoLectivoAction"
```

---

### Task 9: `AlterarEstadoAnoLectivoAction`

**Files:**
- Create: `Modules/AnoLectivo/app/Actions/AlterarEstadoAnoLectivoAction.php`
- Test: `Modules/AnoLectivo/tests/Feature/AlterarEstadoAnoLectivoActionTest.php`

**Interfaces:**
- Consumes: `Modules\AnoLectivo\Actions\Concerns\GarantiaAnoLectivoAtivoUnico` (Task 7).
- Produces: `Modules\AnoLectivo\Actions\AlterarEstadoAnoLectivoAction::alterar(AnoLectivo $anoLectivo, EstadoAnoLectivo $novoEstado): AnoLectivo` — usado pelo `AnoLectivoController::alterarEstado` (Task 17).

- [ ] **Step 1: Escrever o teste (falha primeiro)**

```php
<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Actions\AlterarEstadoAnoLectivoAction;
use Modules\AnoLectivo\Actions\CriarAnoLectivoAction;
use Modules\AnoLectivo\DTO\AnoLectivoDTO;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\RoleSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class AlterarEstadoAnoLectivoActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);
        $this->actingAs($staff);

        Estabelecimento::create([
            'nome' => 'Escola Teste',
            'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
            'is_active' => true,
        ]);
    }

    public function test_encerra_o_ano_lectivo_activo(): void
    {
        $anoLectivo = (new CriarAnoLectivoAction())->criar(new AnoLectivoDTO(
            nome: '2025/2026',
            dataInicio: '2025-09-01',
            dataFim: '2026-07-31',
            estado: EstadoAnoLectivo::ATIVO,
        ));

        $encerrado = (new AlterarEstadoAnoLectivoAction())->alterar($anoLectivo, EstadoAnoLectivo::ENCERRADO);

        $this->assertSame(EstadoAnoLectivo::ENCERRADO, $encerrado->estado);
    }

    public function test_bloqueia_activar_planeado_quando_outro_ja_esta_activo(): void
    {
        (new CriarAnoLectivoAction())->criar(new AnoLectivoDTO(
            nome: '2026/2027',
            dataInicio: '2026-09-01',
            dataFim: '2027-07-31',
            estado: EstadoAnoLectivo::ATIVO,
        ));

        $planeado = (new CriarAnoLectivoAction())->criar(new AnoLectivoDTO(
            nome: '2027/2028',
            dataInicio: '2027-09-01',
            dataFim: '2028-07-31',
            estado: EstadoAnoLectivo::PLANEADO,
        ));

        $this->expectException(ValidationException::class);

        (new AlterarEstadoAnoLectivoAction())->alterar($planeado, EstadoAnoLectivo::ATIVO);
    }

    public function test_activa_planeado_depois_de_encerrar_o_ativo_anterior(): void
    {
        $antigo = (new CriarAnoLectivoAction())->criar(new AnoLectivoDTO(
            nome: '2026/2027',
            dataInicio: '2026-09-01',
            dataFim: '2027-07-31',
            estado: EstadoAnoLectivo::ATIVO,
        ));

        $planeado = (new CriarAnoLectivoAction())->criar(new AnoLectivoDTO(
            nome: '2027/2028',
            dataInicio: '2027-09-01',
            dataFim: '2028-07-31',
            estado: EstadoAnoLectivo::PLANEADO,
        ));

        (new AlterarEstadoAnoLectivoAction())->alterar($antigo, EstadoAnoLectivo::ENCERRADO);
        $novoAtivo = (new AlterarEstadoAnoLectivoAction())->alterar($planeado, EstadoAnoLectivo::ATIVO);

        $this->assertSame(EstadoAnoLectivo::ATIVO, $novoAtivo->estado);
    }
}
```

- [ ] **Step 2: Correr o teste e confirmar que falha**

Run: `php artisan test Modules/AnoLectivo/tests/Feature/AlterarEstadoAnoLectivoActionTest.php`
Expected: FAIL — `AlterarEstadoAnoLectivoAction` não existe.

- [ ] **Step 3: Criar `AlterarEstadoAnoLectivoAction`**

```php
<?php

namespace Modules\AnoLectivo\Actions;

use Illuminate\Support\Facades\DB;
use Modules\AnoLectivo\Actions\Concerns\GarantiaAnoLectivoAtivoUnico;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;

class AlterarEstadoAnoLectivoAction
{
    use GarantiaAnoLectivoAtivoUnico;

    public function alterar(AnoLectivo $anoLectivo, EstadoAnoLectivo $novoEstado): AnoLectivo
    {
        return DB::transaction(function () use ($anoLectivo, $novoEstado) {
            if ($novoEstado === EstadoAnoLectivo::ATIVO) {
                $this->garantirUnicoAtivo($anoLectivo->estabelecimento_id, $anoLectivo->id);
            }

            $anoLectivo->update(['estado' => $novoEstado->value]);

            return $anoLectivo->fresh();
        });
    }
}
```

- [ ] **Step 4: Correr o teste e confirmar que passa**

Run: `php artisan test Modules/AnoLectivo/tests/Feature/AlterarEstadoAnoLectivoActionTest.php`
Expected: 3 testes, PASS.

- [ ] **Step 5: Commit**

```bash
git add Modules/AnoLectivo/app/Actions/AlterarEstadoAnoLectivoAction.php \
        Modules/AnoLectivo/tests/Feature/AlterarEstadoAnoLectivoActionTest.php
git commit -m "feat(ano-lectivo): AlterarEstadoAnoLectivoAction"
```

---

### Task 10: `EliminarAnoLectivoAction`

**Files:**
- Create: `Modules/AnoLectivo/app/Actions/EliminarAnoLectivoAction.php`
- Test: `Modules/AnoLectivo/tests/Feature/EliminarAnoLectivoActionTest.php`

**Interfaces:**
- Consumes: `Modules\AnoLectivo\Models\AnoLectivo` (`periodos()`, `eventosCalendario()`, Task 3).
- Produces: `Modules\AnoLectivo\Actions\EliminarAnoLectivoAction::executar(AnoLectivo $anoLectivo): void`.

- [ ] **Step 1: Escrever o teste (falha primeiro)**

```php
<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Actions\EliminarAnoLectivoAction;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Permissao\Database\Seeders\RoleSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class EliminarAnoLectivoActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);
        $this->actingAs($staff);
    }

    public function test_elimina_ano_lectivo_sem_dependentes_como_soft_delete(): void
    {
        $anoLectivo = AnoLectivo::create([
            'nome' => '2024/2025',
            'data_inicio' => '2024-09-01',
            'data_fim' => '2025-07-31',
            'estado' => EstadoAnoLectivo::ENCERRADO->value,
        ]);

        (new EliminarAnoLectivoAction())->executar($anoLectivo);

        $this->assertSoftDeleted('ano_lectivos', ['id' => $anoLectivo->id]);
    }

    public function test_bloqueia_eliminacao_quando_existem_periodos(): void
    {
        $anoLectivo = AnoLectivo::create([
            'nome' => '2024/2025',
            'data_inicio' => '2024-09-01',
            'data_fim' => '2025-07-31',
            'estado' => EstadoAnoLectivo::ENCERRADO->value,
        ]);

        $anoLectivo->periodos()->create([
            'nome' => '1.º Trimestre',
            'tipo' => TipoPeriodo::TRIMESTRE->value,
            'numero' => 1,
            'data_inicio' => '2024-09-01',
            'data_fim' => '2024-12-15',
        ]);

        $this->expectException(ValidationException::class);

        try {
            (new EliminarAnoLectivoAction())->executar($anoLectivo);
        } finally {
            $this->assertDatabaseHas('ano_lectivos', ['id' => $anoLectivo->id, 'deleted_at' => null]);
        }
    }
}
```

- [ ] **Step 2: Correr o teste e confirmar que falha**

Run: `php artisan test Modules/AnoLectivo/tests/Feature/EliminarAnoLectivoActionTest.php`
Expected: FAIL — `EliminarAnoLectivoAction` não existe.

- [ ] **Step 3: Criar `EliminarAnoLectivoAction`**

```php
<?php

namespace Modules\AnoLectivo\Actions;

use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Models\AnoLectivo;

class EliminarAnoLectivoAction
{
    public function executar(AnoLectivo $anoLectivo): void
    {
        if ($anoLectivo->periodos()->exists() || $anoLectivo->eventosCalendario()->exists()) {
            throw ValidationException::withMessages([
                'ano_lectivo' => 'Este Ano Lectivo tem períodos ou eventos associados e não pode ser eliminado.',
            ]);
        }

        $anoLectivo->delete();
    }
}
```

- [ ] **Step 4: Correr o teste e confirmar que passa**

Run: `php artisan test Modules/AnoLectivo/tests/Feature/EliminarAnoLectivoActionTest.php`
Expected: 2 testes, PASS.

- [ ] **Step 5: Commit**

```bash
git add Modules/AnoLectivo/app/Actions/EliminarAnoLectivoAction.php \
        Modules/AnoLectivo/tests/Feature/EliminarAnoLectivoActionTest.php
git commit -m "feat(ano-lectivo): EliminarAnoLectivoAction com soft delete e bloqueio de dependentes"
```

---

### Task 11: `CriarPeriodoAction`

**Files:**
- Create: `Modules/AnoLectivo/app/DTO/PeriodoDTO.php`
- Create: `Modules/AnoLectivo/app/Http/Requests/CriarPeriodoRequest.php`
- Create: `Modules/AnoLectivo/app/Actions/Concerns/ValidaIntervaloPeriodo.php`
- Create: `Modules/AnoLectivo/app/Actions/CriarPeriodoAction.php`
- Test: `Modules/AnoLectivo/tests/Feature/CriarPeriodoActionTest.php`

**Interfaces:**
- Consumes: `Modules\AnoLectivo\Models\AnoLectivo`/`Periodo` (Tasks 3-4).
- Produces: `Modules\AnoLectivo\DTO\PeriodoDTO`; `Modules\AnoLectivo\Actions\Concerns\ValidaIntervaloPeriodo` com `protected function garantirDentroDoIntervalo(AnoLectivo $anoLectivo, string $dataInicio, string $dataFim): void` e `protected function garantirSemSobreposicao(AnoLectivo $anoLectivo, string $dataInicio, string $dataFim, ?int $idAtual = null): void` — reutilizado pela Task 12; `Modules\AnoLectivo\Actions\CriarPeriodoAction::criar(AnoLectivo $anoLectivo, PeriodoDTO $dto): Periodo`.

- [ ] **Step 1: Escrever o teste (falha primeiro)**

```php
<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Actions\CriarPeriodoAction;
use Modules\AnoLectivo\DTO\PeriodoDTO;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Permissao\Database\Seeders\RoleSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class CriarPeriodoActionTest extends TestCase
{
    use RefreshDatabase;

    private AnoLectivo $anoLectivo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);
        $this->actingAs($staff);

        $this->anoLectivo = AnoLectivo::create([
            'nome' => '2026/2027',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2027-07-31',
            'estado' => EstadoAnoLectivo::ATIVO->value,
        ]);
    }

    public function test_cria_periodo_dentro_do_intervalo(): void
    {
        $periodo = (new CriarPeriodoAction())->criar($this->anoLectivo, new PeriodoDTO(
            nome: '1.º Trimestre',
            tipo: TipoPeriodo::TRIMESTRE,
            dataInicio: '2026-09-01',
            dataFim: '2026-12-15',
            numero: 1,
        ));

        $this->assertDatabaseHas('periodos', ['id' => $periodo->id, 'nome' => '1.º Trimestre']);
    }

    public function test_rejeita_periodo_fora_do_intervalo_do_ano_lectivo(): void
    {
        $this->expectException(ValidationException::class);

        (new CriarPeriodoAction())->criar($this->anoLectivo, new PeriodoDTO(
            nome: 'Fora do intervalo',
            tipo: TipoPeriodo::TRIMESTRE,
            dataInicio: '2026-08-01',
            dataFim: '2026-08-31',
            numero: 1,
        ));
    }

    public function test_rejeita_periodos_sobrepostos(): void
    {
        (new CriarPeriodoAction())->criar($this->anoLectivo, new PeriodoDTO(
            nome: '1.º Trimestre',
            tipo: TipoPeriodo::TRIMESTRE,
            dataInicio: '2026-09-01',
            dataFim: '2026-12-15',
            numero: 1,
        ));

        $this->expectException(ValidationException::class);

        (new CriarPeriodoAction())->criar($this->anoLectivo, new PeriodoDTO(
            nome: '2.º Trimestre',
            tipo: TipoPeriodo::TRIMESTRE,
            dataInicio: '2026-12-01',
            dataFim: '2027-03-31',
            numero: 2,
        ));
    }
}
```

- [ ] **Step 2: Correr o teste e confirmar que falha**

Run: `php artisan test Modules/AnoLectivo/tests/Feature/CriarPeriodoActionTest.php`
Expected: FAIL — `PeriodoDTO`/`CriarPeriodoAction` não existem.

- [ ] **Step 3: Criar `PeriodoDTO`**

```php
<?php

namespace Modules\AnoLectivo\DTO;

use Illuminate\Foundation\Http\FormRequest;
use Modules\AnoLectivo\Enums\TipoPeriodo;

class PeriodoDTO
{
    public function __construct(
        public string $nome,
        public TipoPeriodo $tipo,
        public string $dataInicio,
        public string $dataFim,
        public ?int $numero = null,
    ) {}

    public static function fromRequest(FormRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            nome: $dados['nome'],
            tipo: TipoPeriodo::from((int) $dados['tipo']),
            dataInicio: $dados['data_inicio'],
            dataFim: $dados['data_fim'],
            numero: isset($dados['numero']) ? (int) $dados['numero'] : null,
        );
    }
}
```

- [ ] **Step 4: Criar `CriarPeriodoRequest`**

```php
<?php

namespace Modules\AnoLectivo\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rules\Enum;
use Modules\AnoLectivo\Enums\TipoPeriodo;

class CriarPeriodoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gerir-ano-letivo') ?? false;
    }

    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'tipo' => ['required', new Enum(TipoPeriodo::class)],
            'data_inicio' => ['required', 'date'],
            'data_fim' => ['required', 'date', 'after_or_equal:data_inicio'],
            'numero' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'nome.required' => 'O nome do período é obrigatório.',
            'tipo.required' => 'O tipo de período é obrigatório.',
            'data_inicio.required' => 'A data de início é obrigatória.',
            'data_fim.required' => 'A data de fim é obrigatória.',
            'data_fim.after_or_equal' => 'A data de fim não pode ser anterior à data de início.',
        ];
    }
}
```

- [ ] **Step 5: Criar a trait `ValidaIntervaloPeriodo`**

```php
<?php

namespace Modules\AnoLectivo\Actions\Concerns;

use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Models\AnoLectivo;

trait ValidaIntervaloPeriodo
{
    protected function garantirDentroDoIntervalo(AnoLectivo $anoLectivo, string $dataInicio, string $dataFim): void
    {
        if ($dataInicio < $anoLectivo->data_inicio->toDateString() || $dataFim > $anoLectivo->data_fim->toDateString()) {
            throw ValidationException::withMessages([
                'data_inicio' => 'As datas do período têm de estar dentro do intervalo do Ano Lectivo.',
            ]);
        }
    }

    protected function garantirSemSobreposicao(AnoLectivo $anoLectivo, string $dataInicio, string $dataFim, ?int $idAtual = null): void
    {
        $sobrepoe = $anoLectivo->periodos()
            ->when($idAtual, fn ($query) => $query->where('id', '!=', $idAtual))
            ->where('data_inicio', '<=', $dataFim)
            ->where('data_fim', '>=', $dataInicio)
            ->exists();

        if ($sobrepoe) {
            throw ValidationException::withMessages([
                'data_inicio' => 'Este período sobrepõe-se a outro período já existente no mesmo Ano Lectivo.',
            ]);
        }
    }
}
```

- [ ] **Step 6: Criar `CriarPeriodoAction`**

```php
<?php

namespace Modules\AnoLectivo\Actions;

use Modules\AnoLectivo\Actions\Concerns\ValidaIntervaloPeriodo;
use Modules\AnoLectivo\DTO\PeriodoDTO;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\AnoLectivo\Models\Periodo;

class CriarPeriodoAction
{
    use ValidaIntervaloPeriodo;

    public function criar(AnoLectivo $anoLectivo, PeriodoDTO $dto): Periodo
    {
        $this->garantirDentroDoIntervalo($anoLectivo, $dto->dataInicio, $dto->dataFim);
        $this->garantirSemSobreposicao($anoLectivo, $dto->dataInicio, $dto->dataFim);

        return $anoLectivo->periodos()->create([
            'nome' => $dto->nome,
            'tipo' => $dto->tipo->value,
            'numero' => $dto->numero,
            'data_inicio' => $dto->dataInicio,
            'data_fim' => $dto->dataFim,
        ]);
    }
}
```

- [ ] **Step 7: Correr o teste e confirmar que passa**

Run: `php artisan test Modules/AnoLectivo/tests/Feature/CriarPeriodoActionTest.php`
Expected: 3 testes, PASS.

- [ ] **Step 8: Commit**

```bash
git add Modules/AnoLectivo/app/DTO/PeriodoDTO.php Modules/AnoLectivo/app/Http/Requests/CriarPeriodoRequest.php \
        Modules/AnoLectivo/app/Actions/Concerns/ValidaIntervaloPeriodo.php Modules/AnoLectivo/app/Actions/CriarPeriodoAction.php \
        Modules/AnoLectivo/tests/Feature/CriarPeriodoActionTest.php
git commit -m "feat(ano-lectivo): CriarPeriodoAction com validação de intervalo e sobreposição"
```

---

### Task 12: `AtualizarPeriodoAction`

**Files:**
- Create: `Modules/AnoLectivo/app/Http/Requests/AtualizarPeriodoRequest.php`
- Create: `Modules/AnoLectivo/app/Actions/AtualizarPeriodoAction.php`
- Test: `Modules/AnoLectivo/tests/Feature/AtualizarPeriodoActionTest.php`

**Interfaces:**
- Consumes: `Modules\AnoLectivo\Actions\Concerns\ValidaIntervaloPeriodo` (Task 11).
- Produces: `Modules\AnoLectivo\Actions\AtualizarPeriodoAction::atualizar(Periodo $periodo, PeriodoDTO $dto): Periodo`.

- [ ] **Step 1: Escrever o teste (falha primeiro)**

```php
<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Actions\AtualizarPeriodoAction;
use Modules\AnoLectivo\Actions\CriarPeriodoAction;
use Modules\AnoLectivo\DTO\PeriodoDTO;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Permissao\Database\Seeders\RoleSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class AtualizarPeriodoActionTest extends TestCase
{
    use RefreshDatabase;

    private AnoLectivo $anoLectivo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);
        $this->actingAs($staff);

        $this->anoLectivo = AnoLectivo::create([
            'nome' => '2026/2027',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2027-07-31',
            'estado' => EstadoAnoLectivo::ATIVO->value,
        ]);
    }

    public function test_atualiza_datas_do_proprio_periodo_sem_conflito(): void
    {
        $periodo = (new CriarPeriodoAction())->criar($this->anoLectivo, new PeriodoDTO(
            nome: '1.º Trimestre',
            tipo: TipoPeriodo::TRIMESTRE,
            dataInicio: '2026-09-01',
            dataFim: '2026-12-15',
            numero: 1,
        ));

        $actualizado = (new AtualizarPeriodoAction())->atualizar($periodo, new PeriodoDTO(
            nome: '1.º Trimestre (ajustado)',
            tipo: TipoPeriodo::TRIMESTRE,
            dataInicio: '2026-09-05',
            dataFim: '2026-12-20',
            numero: 1,
        ));

        $this->assertSame('1.º Trimestre (ajustado)', $actualizado->nome);
    }

    public function test_rejeita_actualizacao_que_sobrepoe_outro_periodo(): void
    {
        $primeiro = (new CriarPeriodoAction())->criar($this->anoLectivo, new PeriodoDTO(
            nome: '1.º Trimestre',
            tipo: TipoPeriodo::TRIMESTRE,
            dataInicio: '2026-09-01',
            dataFim: '2026-12-15',
            numero: 1,
        ));

        $segundo = (new CriarPeriodoAction())->criar($this->anoLectivo, new PeriodoDTO(
            nome: '2.º Trimestre',
            tipo: TipoPeriodo::TRIMESTRE,
            dataInicio: '2027-01-05',
            dataFim: '2027-03-31',
            numero: 2,
        ));

        $this->expectException(ValidationException::class);

        (new AtualizarPeriodoAction())->atualizar($segundo, new PeriodoDTO(
            nome: '2.º Trimestre',
            tipo: TipoPeriodo::TRIMESTRE,
            dataInicio: '2026-12-01',
            dataFim: '2027-03-31',
            numero: 2,
        ));
    }
}
```

- [ ] **Step 2: Correr o teste e confirmar que falha**

Run: `php artisan test Modules/AnoLectivo/tests/Feature/AtualizarPeriodoActionTest.php`
Expected: FAIL — `AtualizarPeriodoAction` não existe.

- [ ] **Step 3: Criar `AtualizarPeriodoRequest`**

```php
<?php

namespace Modules\AnoLectivo\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rules\Enum;
use Modules\AnoLectivo\Enums\TipoPeriodo;

class AtualizarPeriodoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gerir-ano-letivo') ?? false;
    }

    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'tipo' => ['required', new Enum(TipoPeriodo::class)],
            'data_inicio' => ['required', 'date'],
            'data_fim' => ['required', 'date', 'after_or_equal:data_inicio'],
            'numero' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'data_fim.after_or_equal' => 'A data de fim não pode ser anterior à data de início.',
        ];
    }
}
```

- [ ] **Step 4: Criar `AtualizarPeriodoAction`**

```php
<?php

namespace Modules\AnoLectivo\Actions;

use Modules\AnoLectivo\Actions\Concerns\ValidaIntervaloPeriodo;
use Modules\AnoLectivo\DTO\PeriodoDTO;
use Modules\AnoLectivo\Models\Periodo;

class AtualizarPeriodoAction
{
    use ValidaIntervaloPeriodo;

    public function atualizar(Periodo $periodo, PeriodoDTO $dto): Periodo
    {
        $anoLectivo = $periodo->anoLectivo;

        $this->garantirDentroDoIntervalo($anoLectivo, $dto->dataInicio, $dto->dataFim);
        $this->garantirSemSobreposicao($anoLectivo, $dto->dataInicio, $dto->dataFim, $periodo->id);

        $periodo->update([
            'nome' => $dto->nome,
            'tipo' => $dto->tipo->value,
            'numero' => $dto->numero,
            'data_inicio' => $dto->dataInicio,
            'data_fim' => $dto->dataFim,
        ]);

        return $periodo->fresh();
    }
}
```

- [ ] **Step 5: Correr o teste e confirmar que passa**

Run: `php artisan test Modules/AnoLectivo/tests/Feature/AtualizarPeriodoActionTest.php`
Expected: 2 testes, PASS.

- [ ] **Step 6: Commit**

```bash
git add Modules/AnoLectivo/app/Http/Requests/AtualizarPeriodoRequest.php Modules/AnoLectivo/app/Actions/AtualizarPeriodoAction.php \
        Modules/AnoLectivo/tests/Feature/AtualizarPeriodoActionTest.php
git commit -m "feat(ano-lectivo): AtualizarPeriodoAction"
```

---

### Task 13: `EliminarPeriodoAction`

**Files:**
- Create: `Modules/AnoLectivo/app/Actions/EliminarPeriodoAction.php`
- Test: `Modules/AnoLectivo/tests/Feature/EliminarPeriodoActionTest.php`

**Interfaces:**
- Consumes: `Modules\AnoLectivo\Models\Periodo` (Task 4).
- Produces: `Modules\AnoLectivo\Actions\EliminarPeriodoAction::executar(Periodo $periodo): void`.

- [ ] **Step 1: Escrever o teste (falha primeiro)**

```php
<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\AnoLectivo\Actions\EliminarPeriodoAction;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Permissao\Database\Seeders\RoleSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class EliminarPeriodoActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);
        $this->actingAs($staff);
    }

    public function test_elimina_periodo(): void
    {
        $anoLectivo = AnoLectivo::create([
            'nome' => '2026/2027',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2027-07-31',
            'estado' => EstadoAnoLectivo::ATIVO->value,
        ]);

        $periodo = $anoLectivo->periodos()->create([
            'nome' => '1.º Trimestre',
            'tipo' => TipoPeriodo::TRIMESTRE->value,
            'numero' => 1,
            'data_inicio' => '2026-09-01',
            'data_fim' => '2026-12-15',
        ]);

        (new EliminarPeriodoAction())->executar($periodo);

        $this->assertDatabaseMissing('periodos', ['id' => $periodo->id]);
    }
}
```

- [ ] **Step 2: Correr o teste e confirmar que falha**

Run: `php artisan test Modules/AnoLectivo/tests/Feature/EliminarPeriodoActionTest.php`
Expected: FAIL — `EliminarPeriodoAction` não existe.

- [ ] **Step 3: Criar `EliminarPeriodoAction`**

```php
<?php

namespace Modules\AnoLectivo\Actions;

use Modules\AnoLectivo\Models\Periodo;

class EliminarPeriodoAction
{
    public function executar(Periodo $periodo): void
    {
        // Sem dependentes noutros módulos nesta fase. Quando
        // `Avaliacao.periodo_id` existir, esta Action tem de ganhar a
        // mesma verificação de dependentes que `EliminarAnoLectivoAction`.
        $periodo->delete();
    }
}
```

- [ ] **Step 4: Correr o teste e confirmar que passa**

Run: `php artisan test Modules/AnoLectivo/tests/Feature/EliminarPeriodoActionTest.php`
Expected: 1 teste, PASS.

- [ ] **Step 5: Commit**

```bash
git add Modules/AnoLectivo/app/Actions/EliminarPeriodoAction.php Modules/AnoLectivo/tests/Feature/EliminarPeriodoActionTest.php
git commit -m "feat(ano-lectivo): EliminarPeriodoAction"
```

---

### Task 14: `CriarEventoCalendarioAction`

**Files:**
- Create: `Modules/AnoLectivo/app/DTO/EventoCalendarioDTO.php`
- Create: `Modules/AnoLectivo/app/Http/Requests/CriarEventoCalendarioRequest.php`
- Create: `Modules/AnoLectivo/app/Actions/Concerns/ValidaIntervaloEvento.php`
- Create: `Modules/AnoLectivo/app/Actions/CriarEventoCalendarioAction.php`
- Test: `Modules/AnoLectivo/tests/Feature/CriarEventoCalendarioActionTest.php`

**Interfaces:**
- Consumes: `Modules\AnoLectivo\Models\AnoLectivo`/`EventoCalendario` (Tasks 3, 5).
- Produces: `Modules\AnoLectivo\DTO\EventoCalendarioDTO`; `Modules\AnoLectivo\Actions\Concerns\ValidaIntervaloEvento` com `protected function garantirDentroDoIntervalo(AnoLectivo $anoLectivo, string $dataInicio, string $dataFim): void` — reutilizado pela Task 15; `Modules\AnoLectivo\Actions\CriarEventoCalendarioAction::criar(AnoLectivo $anoLectivo, EventoCalendarioDTO $dto): EventoCalendario`.

- [ ] **Step 1: Escrever o teste (falha primeiro)**

```php
<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Actions\CriarEventoCalendarioAction;
use Modules\AnoLectivo\DTO\EventoCalendarioDTO;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoEventoCalendario;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Permissao\Database\Seeders\RoleSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class CriarEventoCalendarioActionTest extends TestCase
{
    use RefreshDatabase;

    private AnoLectivo $anoLectivo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);
        $this->actingAs($staff);

        $this->anoLectivo = AnoLectivo::create([
            'nome' => '2026/2027',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2027-07-31',
            'estado' => EstadoAnoLectivo::ATIVO->value,
        ]);
    }

    public function test_cria_evento_dentro_do_intervalo(): void
    {
        $evento = (new CriarEventoCalendarioAction())->criar($this->anoLectivo, new EventoCalendarioDTO(
            titulo: 'Início das aulas',
            tipo: TipoEventoCalendario::AULA,
            dataInicio: '2026-09-01',
            dataFim: '2026-09-01',
        ));

        $this->assertDatabaseHas('eventos_calendario', ['id' => $evento->id, 'titulo' => 'Início das aulas']);
    }

    public function test_rejeita_evento_fora_do_intervalo_do_ano_lectivo(): void
    {
        $this->expectException(ValidationException::class);

        (new CriarEventoCalendarioAction())->criar($this->anoLectivo, new EventoCalendarioDTO(
            titulo: 'Fora do intervalo',
            tipo: TipoEventoCalendario::EVENTO,
            dataInicio: '2026-08-01',
            dataFim: '2026-08-15',
        ));
    }
}
```

- [ ] **Step 2: Correr o teste e confirmar que falha**

Run: `php artisan test Modules/AnoLectivo/tests/Feature/CriarEventoCalendarioActionTest.php`
Expected: FAIL — `EventoCalendarioDTO`/`CriarEventoCalendarioAction` não existem.

- [ ] **Step 3: Criar `EventoCalendarioDTO`**

```php
<?php

namespace Modules\AnoLectivo\DTO;

use Illuminate\Foundation\Http\FormRequest;
use Modules\AnoLectivo\Enums\TipoEventoCalendario;

class EventoCalendarioDTO
{
    public function __construct(
        public string $titulo,
        public TipoEventoCalendario $tipo,
        public string $dataInicio,
        public string $dataFim,
        public ?string $descricao = null,
        public bool $diaInteiro = true,
    ) {}

    public static function fromRequest(FormRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            titulo: $dados['titulo'],
            tipo: TipoEventoCalendario::from((int) $dados['tipo']),
            dataInicio: $dados['data_inicio'],
            dataFim: $dados['data_fim'],
            descricao: $dados['descricao'] ?? null,
            diaInteiro: (bool) ($dados['dia_inteiro'] ?? true),
        );
    }
}
```

- [ ] **Step 4: Criar `CriarEventoCalendarioRequest`**

```php
<?php

namespace Modules\AnoLectivo\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rules\Enum;
use Modules\AnoLectivo\Enums\TipoEventoCalendario;

class CriarEventoCalendarioRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gerir-ano-letivo') ?? false;
    }

    public function rules(): array
    {
        return [
            'titulo' => ['required', 'string', 'max:255'],
            'descricao' => ['nullable', 'string'],
            'tipo' => ['required', new Enum(TipoEventoCalendario::class)],
            'data_inicio' => ['required', 'date'],
            'data_fim' => ['required', 'date', 'after_or_equal:data_inicio'],
            'dia_inteiro' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'titulo.required' => 'O título do evento é obrigatório.',
            'tipo.required' => 'O tipo de evento é obrigatório.',
            'data_inicio.required' => 'A data de início é obrigatória.',
            'data_fim.required' => 'A data de fim é obrigatória.',
            'data_fim.after_or_equal' => 'A data de fim não pode ser anterior à data de início.',
        ];
    }
}
```

- [ ] **Step 5: Criar a trait `ValidaIntervaloEvento`**

```php
<?php

namespace Modules\AnoLectivo\Actions\Concerns;

use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Models\AnoLectivo;

trait ValidaIntervaloEvento
{
    protected function garantirDentroDoIntervalo(AnoLectivo $anoLectivo, string $dataInicio, string $dataFim): void
    {
        if ($dataInicio < $anoLectivo->data_inicio->toDateString() || $dataFim > $anoLectivo->data_fim->toDateString()) {
            throw ValidationException::withMessages([
                'data_inicio' => 'As datas do evento têm de estar dentro do intervalo do Ano Lectivo.',
            ]);
        }
    }
}
```

- [ ] **Step 6: Criar `CriarEventoCalendarioAction`**

```php
<?php

namespace Modules\AnoLectivo\Actions;

use Modules\AnoLectivo\Actions\Concerns\ValidaIntervaloEvento;
use Modules\AnoLectivo\DTO\EventoCalendarioDTO;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\AnoLectivo\Models\EventoCalendario;

class CriarEventoCalendarioAction
{
    use ValidaIntervaloEvento;

    public function criar(AnoLectivo $anoLectivo, EventoCalendarioDTO $dto): EventoCalendario
    {
        $this->garantirDentroDoIntervalo($anoLectivo, $dto->dataInicio, $dto->dataFim);

        return $anoLectivo->eventosCalendario()->create([
            'titulo' => $dto->titulo,
            'descricao' => $dto->descricao,
            'tipo' => $dto->tipo->value,
            'data_inicio' => $dto->dataInicio,
            'data_fim' => $dto->dataFim,
            'dia_inteiro' => $dto->diaInteiro,
        ]);
    }
}
```

- [ ] **Step 7: Correr o teste e confirmar que passa**

Run: `php artisan test Modules/AnoLectivo/tests/Feature/CriarEventoCalendarioActionTest.php`
Expected: 2 testes, PASS.

- [ ] **Step 8: Commit**

```bash
git add Modules/AnoLectivo/app/DTO/EventoCalendarioDTO.php Modules/AnoLectivo/app/Http/Requests/CriarEventoCalendarioRequest.php \
        Modules/AnoLectivo/app/Actions/Concerns/ValidaIntervaloEvento.php Modules/AnoLectivo/app/Actions/CriarEventoCalendarioAction.php \
        Modules/AnoLectivo/tests/Feature/CriarEventoCalendarioActionTest.php
git commit -m "feat(ano-lectivo): CriarEventoCalendarioAction com validação de intervalo"
```

---

### Task 15: `AtualizarEventoCalendarioAction`

**Files:**
- Create: `Modules/AnoLectivo/app/Http/Requests/AtualizarEventoCalendarioRequest.php`
- Create: `Modules/AnoLectivo/app/Actions/AtualizarEventoCalendarioAction.php`
- Test: `Modules/AnoLectivo/tests/Feature/AtualizarEventoCalendarioActionTest.php`

**Interfaces:**
- Consumes: `Modules\AnoLectivo\Actions\Concerns\ValidaIntervaloEvento` (Task 14).
- Produces: `Modules\AnoLectivo\Actions\AtualizarEventoCalendarioAction::atualizar(EventoCalendario $evento, EventoCalendarioDTO $dto): EventoCalendario`.

- [ ] **Step 1: Escrever o teste (falha primeiro)**

```php
<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Actions\AtualizarEventoCalendarioAction;
use Modules\AnoLectivo\Actions\CriarEventoCalendarioAction;
use Modules\AnoLectivo\DTO\EventoCalendarioDTO;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoEventoCalendario;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Permissao\Database\Seeders\RoleSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class AtualizarEventoCalendarioActionTest extends TestCase
{
    use RefreshDatabase;

    private AnoLectivo $anoLectivo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);
        $this->actingAs($staff);

        $this->anoLectivo = AnoLectivo::create([
            'nome' => '2026/2027',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2027-07-31',
            'estado' => EstadoAnoLectivo::ATIVO->value,
        ]);
    }

    public function test_atualiza_titulo_e_datas_do_evento(): void
    {
        $evento = (new CriarEventoCalendarioAction())->criar($this->anoLectivo, new EventoCalendarioDTO(
            titulo: 'Início das aulas',
            tipo: TipoEventoCalendario::AULA,
            dataInicio: '2026-09-01',
            dataFim: '2026-09-01',
        ));

        $actualizado = (new AtualizarEventoCalendarioAction())->atualizar($evento, new EventoCalendarioDTO(
            titulo: 'Início oficial das aulas',
            tipo: TipoEventoCalendario::AULA,
            dataInicio: '2026-09-02',
            dataFim: '2026-09-02',
        ));

        $this->assertSame('Início oficial das aulas', $actualizado->titulo);
    }

    public function test_rejeita_actualizacao_fora_do_intervalo_do_ano_lectivo(): void
    {
        $evento = (new CriarEventoCalendarioAction())->criar($this->anoLectivo, new EventoCalendarioDTO(
            titulo: 'Início das aulas',
            tipo: TipoEventoCalendario::AULA,
            dataInicio: '2026-09-01',
            dataFim: '2026-09-01',
        ));

        $this->expectException(ValidationException::class);

        (new AtualizarEventoCalendarioAction())->atualizar($evento, new EventoCalendarioDTO(
            titulo: 'Início das aulas',
            tipo: TipoEventoCalendario::AULA,
            dataInicio: '2027-08-01',
            dataFim: '2027-08-01',
        ));
    }
}
```

- [ ] **Step 2: Correr o teste e confirmar que falha**

Run: `php artisan test Modules/AnoLectivo/tests/Feature/AtualizarEventoCalendarioActionTest.php`
Expected: FAIL — `AtualizarEventoCalendarioAction` não existe.

- [ ] **Step 3: Criar `AtualizarEventoCalendarioRequest`**

```php
<?php

namespace Modules\AnoLectivo\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rules\Enum;
use Modules\AnoLectivo\Enums\TipoEventoCalendario;

class AtualizarEventoCalendarioRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gerir-ano-letivo') ?? false;
    }

    public function rules(): array
    {
        return [
            'titulo' => ['required', 'string', 'max:255'],
            'descricao' => ['nullable', 'string'],
            'tipo' => ['required', new Enum(TipoEventoCalendario::class)],
            'data_inicio' => ['required', 'date'],
            'data_fim' => ['required', 'date', 'after_or_equal:data_inicio'],
            'dia_inteiro' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'data_fim.after_or_equal' => 'A data de fim não pode ser anterior à data de início.',
        ];
    }
}
```

- [ ] **Step 4: Criar `AtualizarEventoCalendarioAction`**

```php
<?php

namespace Modules\AnoLectivo\Actions;

use Modules\AnoLectivo\Actions\Concerns\ValidaIntervaloEvento;
use Modules\AnoLectivo\DTO\EventoCalendarioDTO;
use Modules\AnoLectivo\Models\EventoCalendario;

class AtualizarEventoCalendarioAction
{
    use ValidaIntervaloEvento;

    public function atualizar(EventoCalendario $evento, EventoCalendarioDTO $dto): EventoCalendario
    {
        $this->garantirDentroDoIntervalo($evento->anoLectivo, $dto->dataInicio, $dto->dataFim);

        $evento->update([
            'titulo' => $dto->titulo,
            'descricao' => $dto->descricao,
            'tipo' => $dto->tipo->value,
            'data_inicio' => $dto->dataInicio,
            'data_fim' => $dto->dataFim,
            'dia_inteiro' => $dto->diaInteiro,
        ]);

        return $evento->fresh();
    }
}
```

- [ ] **Step 5: Correr o teste e confirmar que passa**

Run: `php artisan test Modules/AnoLectivo/tests/Feature/AtualizarEventoCalendarioActionTest.php`
Expected: 2 testes, PASS.

- [ ] **Step 6: Commit**

```bash
git add Modules/AnoLectivo/app/Http/Requests/AtualizarEventoCalendarioRequest.php \
        Modules/AnoLectivo/app/Actions/AtualizarEventoCalendarioAction.php \
        Modules/AnoLectivo/tests/Feature/AtualizarEventoCalendarioActionTest.php
git commit -m "feat(ano-lectivo): AtualizarEventoCalendarioAction"
```

---

### Task 16: `EliminarEventoCalendarioAction`

**Files:**
- Create: `Modules/AnoLectivo/app/Actions/EliminarEventoCalendarioAction.php`
- Test: `Modules/AnoLectivo/tests/Feature/EliminarEventoCalendarioActionTest.php`

**Interfaces:**
- Consumes: `Modules\AnoLectivo\Models\EventoCalendario` (Task 5).
- Produces: `Modules\AnoLectivo\Actions\EliminarEventoCalendarioAction::executar(EventoCalendario $evento): void`.

- [ ] **Step 1: Escrever o teste (falha primeiro)**

```php
<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\AnoLectivo\Actions\EliminarEventoCalendarioAction;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoEventoCalendario;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Permissao\Database\Seeders\RoleSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class EliminarEventoCalendarioActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);
        $this->actingAs($staff);
    }

    public function test_elimina_evento_de_calendario(): void
    {
        $anoLectivo = AnoLectivo::create([
            'nome' => '2026/2027',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2027-07-31',
            'estado' => EstadoAnoLectivo::ATIVO->value,
        ]);

        $evento = $anoLectivo->eventosCalendario()->create([
            'titulo' => 'Início das aulas',
            'tipo' => TipoEventoCalendario::AULA->value,
            'data_inicio' => '2026-09-01',
            'data_fim' => '2026-09-01',
        ]);

        (new EliminarEventoCalendarioAction())->executar($evento);

        $this->assertDatabaseMissing('eventos_calendario', ['id' => $evento->id]);
    }
}
```

- [ ] **Step 2: Correr o teste e confirmar que falha**

Run: `php artisan test Modules/AnoLectivo/tests/Feature/EliminarEventoCalendarioActionTest.php`
Expected: FAIL — `EliminarEventoCalendarioAction` não existe.

- [ ] **Step 3: Criar `EliminarEventoCalendarioAction`**

```php
<?php

namespace Modules\AnoLectivo\Actions;

use Modules\AnoLectivo\Models\EventoCalendario;

class EliminarEventoCalendarioAction
{
    public function executar(EventoCalendario $evento): void
    {
        $evento->delete();
    }
}
```

- [ ] **Step 4: Correr o teste e confirmar que passa**

Run: `php artisan test Modules/AnoLectivo/tests/Feature/EliminarEventoCalendarioActionTest.php`
Expected: 1 teste, PASS.

- [ ] **Step 5: Commit**

```bash
git add Modules/AnoLectivo/app/Actions/EliminarEventoCalendarioAction.php \
        Modules/AnoLectivo/tests/Feature/EliminarEventoCalendarioActionTest.php
git commit -m "feat(ano-lectivo): EliminarEventoCalendarioAction"
```

---

### Task 17: Services + Controllers + Routes + testes HTTP dos 9 cenários

**Files:**
- Create: `Modules/AnoLectivo/app/Services/GestaoAnoLectivoService.php`
- Create: `Modules/AnoLectivo/app/Services/AnoLectivoConsultaService.php`
- Create: `Modules/AnoLectivo/app/Http/Requests/AlterarEstadoAnoLectivoRequest.php`
- Create: `Modules/AnoLectivo/app/Http/Controllers/AnoLectivoController.php`
- Create: `Modules/AnoLectivo/app/Http/Controllers/PeriodoController.php`
- Create: `Modules/AnoLectivo/app/Http/Controllers/EventoCalendarioController.php`
- Modify: `Modules/AnoLectivo/routes/web.php`
- Test: `Modules/AnoLectivo/tests/Feature/AnoLectivoHttpTest.php`
- Test: `Modules/AnoLectivo/tests/Feature/PeriodoHttpTest.php`
- Test: `Modules/AnoLectivo/tests/Feature/EventoCalendarioHttpTest.php`
- Test: `Modules/AnoLectivo/tests/Feature/AnoLectivoTenancyTest.php`

**Interfaces:**
- Consumes: todas as Actions e DTOs das Tasks 7-16, Policies da Task 6.
- Produces: rotas HTTP nomeadas `ano-lectivos.*`, `periodos.*`, `eventos.*` — ponto de entrada que a fase de frontend vai consumir via Inertia.

- [ ] **Step 1: Escrever os testes HTTP (falham primeiro)**

`Modules/AnoLectivo/tests/Feature/AnoLectivoHttpTest.php`:

```php
<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Permissao\Database\Seeders\RoleSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class AnoLectivoHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
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

    public function test_cria_ano_lectivo_via_http(): void
    {
        $this->actingAsStaff();

        $response = $this->post('/ano-lectivos', [
            'nome' => '2026/2027',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2027-07-31',
            'estado' => EstadoAnoLectivo::ATIVO->value,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('ano_lectivos', ['nome' => '2026/2027']);
    }

    public function test_bloqueia_segundo_ano_lectivo_activo_via_http(): void
    {
        $this->actingAsStaff();

        $this->post('/ano-lectivos', [
            'nome' => '2026/2027',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2027-07-31',
            'estado' => EstadoAnoLectivo::ATIVO->value,
        ]);

        $response = $this->post('/ano-lectivos', [
            'nome' => '2027/2028',
            'data_inicio' => '2027-09-01',
            'data_fim' => '2028-07-31',
            'estado' => EstadoAnoLectivo::ATIVO->value,
        ]);

        $response->assertSessionHasErrors('estado');
        $this->assertSame(1, AnoLectivo::where('estado', EstadoAnoLectivo::ATIVO->value)->count());
    }

    public function test_encerrar_mantem_o_ano_lectivo_consultavel(): void
    {
        $this->actingAsStaff();

        $this->post('/ano-lectivos', [
            'nome' => '2025/2026',
            'data_inicio' => '2025-09-01',
            'data_fim' => '2026-07-31',
            'estado' => EstadoAnoLectivo::ATIVO->value,
        ]);
        $anoLectivo = AnoLectivo::where('nome', '2025/2026')->firstOrFail();

        $this->patch("/ano-lectivos/{$anoLectivo->id}/estado", ['estado' => EstadoAnoLectivo::ENCERRADO->value])
            ->assertRedirect();

        $this->get("/ano-lectivos/{$anoLectivo->id}")->assertOk();
        $this->assertDatabaseHas('ano_lectivos', ['id' => $anoLectivo->id, 'estado' => EstadoAnoLectivo::ENCERRADO->value]);
    }

    public function test_bloqueia_eliminar_ano_lectivo_com_periodos_via_http(): void
    {
        $this->actingAsStaff();

        $this->post('/ano-lectivos', [
            'nome' => '2026/2027',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2027-07-31',
            'estado' => EstadoAnoLectivo::ATIVO->value,
        ]);
        $anoLectivo = AnoLectivo::where('nome', '2026/2027')->firstOrFail();

        $this->post("/ano-lectivos/{$anoLectivo->id}/periodos", [
            'nome' => '1.º Trimestre',
            'tipo' => TipoPeriodo::TRIMESTRE->value,
            'numero' => 1,
            'data_inicio' => '2026-09-01',
            'data_fim' => '2026-12-15',
        ]);

        $response = $this->delete("/ano-lectivos/{$anoLectivo->id}");

        $response->assertSessionHasErrors('ano_lectivo');
        $this->assertDatabaseHas('ano_lectivos', ['id' => $anoLectivo->id, 'deleted_at' => null]);
    }

    public function test_utilizador_sem_permissao_recebe_403_em_todas_as_rotas(): void
    {
        $professor = User::create(['name' => 'Professor', 'email' => 'professor@example.com', 'password' => Hash::make('x')]);
        $professor->roles()->syncWithoutDetaching([Role::where('nome', Perfil::PROFESSOR->value)->first()->id]);
        $this->actingAs($professor);

        $this->get('/ano-lectivos')->assertForbidden();
        $this->post('/ano-lectivos', [])->assertForbidden();
    }
}
```

`Modules/AnoLectivo/tests/Feature/PeriodoHttpTest.php`:

```php
<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoPeriodo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Permissao\Database\Seeders\RoleSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class PeriodoHttpTest extends TestCase
{
    use RefreshDatabase;

    private AnoLectivo $anoLectivo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $staff = User::firstOrCreate(
            ['email' => 'staff@example.com'],
            ['name' => 'Staff', 'password' => Hash::make('segredo123')],
        );
        $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);
        $this->actingAs($staff);

        $this->anoLectivo = AnoLectivo::create([
            'nome' => '2026/2027',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2027-07-31',
            'estado' => EstadoAnoLectivo::ATIVO->value,
        ]);
    }

    public function test_cria_periodo_via_http(): void
    {
        $response = $this->post("/ano-lectivos/{$this->anoLectivo->id}/periodos", [
            'nome' => '1.º Trimestre',
            'tipo' => TipoPeriodo::TRIMESTRE->value,
            'numero' => 1,
            'data_inicio' => '2026-09-01',
            'data_fim' => '2026-12-15',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('periodos', ['nome' => '1.º Trimestre']);
    }

    public function test_rejeita_periodo_fora_do_intervalo_via_http(): void
    {
        $response = $this->post("/ano-lectivos/{$this->anoLectivo->id}/periodos", [
            'nome' => 'Fora do intervalo',
            'tipo' => TipoPeriodo::TRIMESTRE->value,
            'numero' => 1,
            'data_inicio' => '2026-08-01',
            'data_fim' => '2026-08-15',
        ]);

        $response->assertSessionHasErrors('data_inicio');
    }

    public function test_rejeita_periodos_sobrepostos_via_http(): void
    {
        $this->post("/ano-lectivos/{$this->anoLectivo->id}/periodos", [
            'nome' => '1.º Trimestre',
            'tipo' => TipoPeriodo::TRIMESTRE->value,
            'numero' => 1,
            'data_inicio' => '2026-09-01',
            'data_fim' => '2026-12-15',
        ]);

        $response = $this->post("/ano-lectivos/{$this->anoLectivo->id}/periodos", [
            'nome' => '2.º Trimestre',
            'tipo' => TipoPeriodo::TRIMESTRE->value,
            'numero' => 2,
            'data_inicio' => '2026-12-01',
            'data_fim' => '2027-03-31',
        ]);

        $response->assertSessionHasErrors('data_inicio');
    }
}
```

`Modules/AnoLectivo/tests/Feature/EventoCalendarioHttpTest.php`:

```php
<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Enums\TipoEventoCalendario;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Permissao\Database\Seeders\RoleSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class EventoCalendarioHttpTest extends TestCase
{
    use RefreshDatabase;

    private AnoLectivo $anoLectivo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $staff = User::firstOrCreate(
            ['email' => 'staff@example.com'],
            ['name' => 'Staff', 'password' => Hash::make('segredo123')],
        );
        $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);
        $this->actingAs($staff);

        $this->anoLectivo = AnoLectivo::create([
            'nome' => '2026/2027',
            'data_inicio' => '2026-09-01',
            'data_fim' => '2027-07-31',
            'estado' => EstadoAnoLectivo::ATIVO->value,
        ]);
    }

    public function test_cria_evento_de_calendario_via_http(): void
    {
        $response = $this->post("/ano-lectivos/{$this->anoLectivo->id}/eventos-calendario", [
            'titulo' => 'Início das aulas',
            'tipo' => TipoEventoCalendario::AULA->value,
            'data_inicio' => '2026-09-01',
            'data_fim' => '2026-09-01',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('eventos_calendario', ['titulo' => 'Início das aulas']);
    }

    public function test_rejeita_evento_fora_do_intervalo_via_http(): void
    {
        $response = $this->post("/ano-lectivos/{$this->anoLectivo->id}/eventos-calendario", [
            'titulo' => 'Fora do intervalo',
            'tipo' => TipoEventoCalendario::EVENTO->value,
            'data_inicio' => '2027-08-01',
            'data_fim' => '2027-08-15',
        ]);

        $response->assertSessionHasErrors('data_inicio');
    }
}
```

`Modules/AnoLectivo/tests/Feature/AnoLectivoTenancyTest.php`:

```php
<?php

namespace Modules\AnoLectivo\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnoLectivoTenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_isolamento_entre_tenants(): void
    {
        // @todo: o MosiTec é single-tenant hoje (sem tenant_id/GlobalScope
        // em nenhuma tabela do projecto — ver docs/superpowers/specs/
        // 2026-08-30-modulo-anolectivo-design.md, secção "Tenancy /
        // Estabelecimento"). Não existe hoje mais de um `Estabelecimento`
        // "activo" possível, por isso este cenário não é simulável sem
        // inventar um mecanismo de tenancy que não existe no resto do
        // sistema. Activar este teste quando a tenancy real (multi-escola)
        // for implementada.
        $this->markTestIncomplete(
            'Isolamento entre tenants ainda não é testável: tenancy real ainda não existe no MosiTec.'
        );
    }
}
```

- [ ] **Step 2: Correr os testes e confirmar que falham**

Run: `php artisan test Modules/AnoLectivo/tests/Feature/AnoLectivoHttpTest.php Modules/AnoLectivo/tests/Feature/PeriodoHttpTest.php Modules/AnoLectivo/tests/Feature/EventoCalendarioHttpTest.php`
Expected: FAIL — rotas `/ano-lectivos*` não existem (404).

- [ ] **Step 3: Criar `GestaoAnoLectivoService`**

```php
<?php

namespace Modules\AnoLectivo\Services;

use Modules\AnoLectivo\Actions\AlterarEstadoAnoLectivoAction;
use Modules\AnoLectivo\Actions\AtualizarAnoLectivoAction;
use Modules\AnoLectivo\Actions\AtualizarEventoCalendarioAction;
use Modules\AnoLectivo\Actions\AtualizarPeriodoAction;
use Modules\AnoLectivo\Actions\CriarAnoLectivoAction;
use Modules\AnoLectivo\Actions\CriarEventoCalendarioAction;
use Modules\AnoLectivo\Actions\CriarPeriodoAction;
use Modules\AnoLectivo\Actions\EliminarAnoLectivoAction;
use Modules\AnoLectivo\Actions\EliminarEventoCalendarioAction;
use Modules\AnoLectivo\Actions\EliminarPeriodoAction;
use Modules\AnoLectivo\DTO\AnoLectivoDTO;
use Modules\AnoLectivo\DTO\EventoCalendarioDTO;
use Modules\AnoLectivo\DTO\PeriodoDTO;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Http\Requests\AtualizarAnoLectivoRequest;
use Modules\AnoLectivo\Http\Requests\AtualizarEventoCalendarioRequest;
use Modules\AnoLectivo\Http\Requests\AtualizarPeriodoRequest;
use Modules\AnoLectivo\Http\Requests\CriarAnoLectivoRequest;
use Modules\AnoLectivo\Http\Requests\CriarEventoCalendarioRequest;
use Modules\AnoLectivo\Http\Requests\CriarPeriodoRequest;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\AnoLectivo\Models\EventoCalendario;
use Modules\AnoLectivo\Models\Periodo;

class GestaoAnoLectivoService
{
    public function __construct(
        private CriarAnoLectivoAction $criarAnoLectivo,
        private AtualizarAnoLectivoAction $atualizarAnoLectivo,
        private AlterarEstadoAnoLectivoAction $alterarEstadoAnoLectivo,
        private EliminarAnoLectivoAction $eliminarAnoLectivo,
        private CriarPeriodoAction $criarPeriodo,
        private AtualizarPeriodoAction $atualizarPeriodo,
        private EliminarPeriodoAction $eliminarPeriodo,
        private CriarEventoCalendarioAction $criarEvento,
        private AtualizarEventoCalendarioAction $atualizarEvento,
        private EliminarEventoCalendarioAction $eliminarEvento,
    ) {}

    public function criar(CriarAnoLectivoRequest $request): AnoLectivo
    {
        return $this->criarAnoLectivo->criar(AnoLectivoDTO::fromRequest($request));
    }

    public function atualizar(AnoLectivo $anoLectivo, AtualizarAnoLectivoRequest $request): AnoLectivo
    {
        return $this->atualizarAnoLectivo->atualizar($anoLectivo, AnoLectivoDTO::fromRequest($request));
    }

    public function alterarEstado(AnoLectivo $anoLectivo, EstadoAnoLectivo $novoEstado): AnoLectivo
    {
        return $this->alterarEstadoAnoLectivo->alterar($anoLectivo, $novoEstado);
    }

    public function eliminar(AnoLectivo $anoLectivo): void
    {
        $this->eliminarAnoLectivo->executar($anoLectivo);
    }

    public function criarPeriodo(AnoLectivo $anoLectivo, CriarPeriodoRequest $request): Periodo
    {
        return $this->criarPeriodo->criar($anoLectivo, PeriodoDTO::fromRequest($request));
    }

    public function atualizarPeriodo(Periodo $periodo, AtualizarPeriodoRequest $request): Periodo
    {
        return $this->atualizarPeriodo->atualizar($periodo, PeriodoDTO::fromRequest($request));
    }

    public function eliminarPeriodo(Periodo $periodo): void
    {
        $this->eliminarPeriodo->executar($periodo);
    }

    public function criarEventoCalendario(AnoLectivo $anoLectivo, CriarEventoCalendarioRequest $request): EventoCalendario
    {
        return $this->criarEvento->criar($anoLectivo, EventoCalendarioDTO::fromRequest($request));
    }

    public function atualizarEventoCalendario(EventoCalendario $evento, AtualizarEventoCalendarioRequest $request): EventoCalendario
    {
        return $this->atualizarEvento->atualizar($evento, EventoCalendarioDTO::fromRequest($request));
    }

    public function eliminarEventoCalendario(EventoCalendario $evento): void
    {
        $this->eliminarEvento->executar($evento);
    }
}
```

- [ ] **Step 4: Criar `AnoLectivoConsultaService`**

```php
<?php

namespace Modules\AnoLectivo\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\AnoLectivo\Models\AnoLectivo;

class AnoLectivoConsultaService
{
    public function listar(): Collection
    {
        return AnoLectivo::orderByDesc('data_inicio')->get();
    }

    public function comRelacoes(AnoLectivo $anoLectivo): AnoLectivo
    {
        return $anoLectivo->load(['periodos', 'eventosCalendario']);
    }
}
```

- [ ] **Step 5: Criar `AlterarEstadoAnoLectivoRequest`**

```php
<?php

namespace Modules\AnoLectivo\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rules\Enum;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;

class AlterarEstadoAnoLectivoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gerir-ano-letivo') ?? false;
    }

    public function rules(): array
    {
        return [
            'estado' => ['required', new Enum(EstadoAnoLectivo::class)],
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

- [ ] **Step 6: Criar `AnoLectivoController`**

```php
<?php

namespace Modules\AnoLectivo\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Http\Requests\AlterarEstadoAnoLectivoRequest;
use Modules\AnoLectivo\Http\Requests\AtualizarAnoLectivoRequest;
use Modules\AnoLectivo\Http\Requests\CriarAnoLectivoRequest;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\AnoLectivo\Services\AnoLectivoConsultaService;
use Modules\AnoLectivo\Services\GestaoAnoLectivoService;

class AnoLectivoController extends Controller
{
    public function __construct(
        private GestaoAnoLectivoService $service,
        private AnoLectivoConsultaService $consulta,
    ) {}

    public function index()
    {
        $this->authorize('view', AnoLectivo::class);

        return Inertia::render('AnoLectivo/Index', [
            'anoLectivos' => $this->consulta->listar(),
        ]);
    }

    public function show(AnoLectivo $anoLectivo)
    {
        $this->authorize('view', AnoLectivo::class);

        return Inertia::render('AnoLectivo/Show', [
            'anoLectivo' => $this->consulta->comRelacoes($anoLectivo),
        ]);
    }

    public function store(CriarAnoLectivoRequest $request)
    {
        $this->authorize('create', AnoLectivo::class);

        $this->service->criar($request);

        return redirect()->back()->with('success', 'Ano Lectivo criado com sucesso.');
    }

    public function update(AtualizarAnoLectivoRequest $request, AnoLectivo $anoLectivo)
    {
        $this->authorize('update', AnoLectivo::class);

        $this->service->atualizar($anoLectivo, $request);

        return redirect()->back()->with('success', 'Ano Lectivo atualizado com sucesso.');
    }

    public function alterarEstado(AlterarEstadoAnoLectivoRequest $request, AnoLectivo $anoLectivo)
    {
        $this->authorize('update', AnoLectivo::class);

        $this->service->alterarEstado($anoLectivo, EstadoAnoLectivo::from((int) $request->validated('estado')));

        return redirect()->back()->with('success', 'Estado do Ano Lectivo atualizado com sucesso.');
    }

    public function destroy(AnoLectivo $anoLectivo)
    {
        $this->authorize('delete', AnoLectivo::class);

        $this->service->eliminar($anoLectivo);

        return redirect()->back()->with('success', 'Ano Lectivo eliminado com sucesso.');
    }
}
```

- [ ] **Step 7: Criar `PeriodoController` e `EventoCalendarioController`**

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
        $this->authorize('create', Periodo::class);

        $this->service->criarPeriodo($anoLectivo, $request);

        return redirect()->back()->with('success', 'Período criado com sucesso.');
    }

    public function update(AtualizarPeriodoRequest $request, Periodo $periodo)
    {
        $this->authorize('update', Periodo::class);

        $this->service->atualizarPeriodo($periodo, $request);

        return redirect()->back()->with('success', 'Período atualizado com sucesso.');
    }

    public function destroy(Periodo $periodo)
    {
        $this->authorize('delete', Periodo::class);

        $this->service->eliminarPeriodo($periodo);

        return redirect()->back()->with('success', 'Período eliminado com sucesso.');
    }
}
```

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
        $this->authorize('create', EventoCalendario::class);

        $this->service->criarEventoCalendario($anoLectivo, $request);

        return redirect()->back()->with('success', 'Evento de calendário criado com sucesso.');
    }

    public function update(AtualizarEventoCalendarioRequest $request, EventoCalendario $evento)
    {
        $this->authorize('update', EventoCalendario::class);

        $this->service->atualizarEventoCalendario($evento, $request);

        return redirect()->back()->with('success', 'Evento de calendário atualizado com sucesso.');
    }

    public function destroy(EventoCalendario $evento)
    {
        $this->authorize('delete', EventoCalendario::class);

        $this->service->eliminarEventoCalendario($evento);

        return redirect()->back()->with('success', 'Evento de calendário eliminado com sucesso.');
    }
}
```

- [ ] **Step 8: Preencher `routes/web.php`**

```php
<?php

use Illuminate\Support\Facades\Route;
use Modules\AnoLectivo\Http\Controllers\AnoLectivoController;
use Modules\AnoLectivo\Http\Controllers\EventoCalendarioController;
use Modules\AnoLectivo\Http\Controllers\PeriodoController;

Route::middleware(['auth', 'can:gerir-ano-letivo'])->prefix('ano-lectivos')->name('ano-lectivos.')->group(function () {
    Route::get('/', [AnoLectivoController::class, 'index'])->name('index');
    Route::post('/', [AnoLectivoController::class, 'store'])->name('store');
    Route::get('/{anoLectivo}', [AnoLectivoController::class, 'show'])->name('show');
    Route::put('/{anoLectivo}', [AnoLectivoController::class, 'update'])->name('update');
    Route::patch('/{anoLectivo}/estado', [AnoLectivoController::class, 'alterarEstado'])->name('alterar-estado');
    Route::delete('/{anoLectivo}', [AnoLectivoController::class, 'destroy'])->name('destroy');

    Route::post('/{anoLectivo}/periodos', [PeriodoController::class, 'store'])->name('periodos.store');
    Route::post('/{anoLectivo}/eventos-calendario', [EventoCalendarioController::class, 'store'])->name('eventos.store');
});

Route::middleware(['auth', 'can:gerir-ano-letivo'])->group(function () {
    Route::put('/periodos/{periodo}', [PeriodoController::class, 'update'])->name('periodos.update');
    Route::delete('/periodos/{periodo}', [PeriodoController::class, 'destroy'])->name('periodos.destroy');
    Route::put('/eventos-calendario/{evento}', [EventoCalendarioController::class, 'update'])->name('eventos.update');
    Route::delete('/eventos-calendario/{evento}', [EventoCalendarioController::class, 'destroy'])->name('eventos.destroy');
});
```

- [ ] **Step 9: Correr toda a suite do módulo e confirmar que passa**

Run: `php artisan test Modules/AnoLectivo`
Expected: todos os testes do módulo PASS, excepto `AnoLectivoTenancyTest` que aparece como **Incomplete** (não Failed, não Passed) — confirmando que o cenário 10 está documentado, não escondido.

- [ ] **Step 10: Correr a suite completa do projecto**

Run: `php artisan test`
Expected: nenhuma regressão nos módulos existentes (`Usuario`, `Permissao`, `Estabelecimento`, `Core`).

- [ ] **Step 11: Commit**

```bash
git add Modules/AnoLectivo/app/Services Modules/AnoLectivo/app/Http/Requests/AlterarEstadoAnoLectivoRequest.php \
        Modules/AnoLectivo/app/Http/Controllers Modules/AnoLectivo/routes/web.php \
        Modules/AnoLectivo/tests/Feature/AnoLectivoHttpTest.php Modules/AnoLectivo/tests/Feature/PeriodoHttpTest.php \
        Modules/AnoLectivo/tests/Feature/EventoCalendarioHttpTest.php Modules/AnoLectivo/tests/Feature/AnoLectivoTenancyTest.php
git commit -m "feat(ano-lectivo): Services, Controllers e rotas HTTP; cobre os 9 cenários de teste do requisito"
```

---

## Validation Checklist (pós-execução)

- [ ] `php artisan module:list` mostra `AnoLectivo: Enabled`.
- [ ] `php artisan test Modules/AnoLectivo` — tudo PASS, `AnoLectivoTenancyTest` marcado Incomplete (não Failed).
- [ ] `php artisan test` (suite completa) sem regressões noutros módulos.
- [ ] `AnoLectivo::current()` devolve o único activo por `estabelecimento_id`; nunca há dois `ATIVO` no mesmo estabelecimento (Tasks 7-9).
- [ ] Eliminar `AnoLectivo` com `Periodo`/`EventoCalendario` associados é bloqueado; sem dependentes, fica soft-deleted (Task 10).
- [ ] `Periodo`/`EventoCalendario` nunca ficam fora do intervalo do `AnoLectivo`; períodos nunca se sobrepõem (Tasks 11-16).
- [ ] Utilizador sem `gerir-ano-letivo` recebe 403 em todas as rotas do módulo (Task 6, 17).
- [ ] `criado_por`/`editado_por` preenchidos nos 3 Models via `RegistaAutoria` (Task 3).
- [ ] Nenhum outro módulo (`Usuario`, `Permissao`, `Estabelecimento`) foi alterado além de `app/Providers/AppServiceProvider.php` e `modules_statuses.json`.
