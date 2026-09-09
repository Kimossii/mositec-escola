# Módulo Curso — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Criar o módulo `Curso` (catálogo de cursos/formações de um Estabelecimento) e ligar a Turma a um Curso de forma obrigatória.

**Architecture:** Módulo Laravel-Modules novo (`Modules/Curso`), réplica exacta do padrão já estabelecido em `Modules/Turma/app/Models/NivelAcademico.php` (Controller fino → Service (Gestão + Consulta) → Action → DTO/Model, traits `RegistaAutoria` + `SincronizaEstadoDescricao`, estado binário via `Modules\Core\Enums\Estado`). Depois, uma alteração cirúrgica em `Modules/Turma` adiciona `curso_id` obrigatório à tabela `turmas`.

**Tech Stack:** Laravel 11 + nwidart/laravel-modules, Inertia + Vue 3, PHPUnit, Pest-style `Tests\TestCase` (RefreshDatabase).

**Spec:** `docs/superpowers/specs/2026-09-09-modulo-curso-design.md`

## Global Constraints

- Curso pertence obrigatoriamente a um Estabelecimento (`estabelecimento_id` NOT NULL, `restrictOnDelete`).
- `codigo` e `nome` únicos por `estabelecimento_id` (constraint na BD + validação no Form Request).
- Estado binário Ativo/Inativo via `Modules\Core\Enums\Estado` (0=Inativo, 1=Ativo) — reutilizar, nunca inventar enum próprio para isto.
- Traits obrigatórios no Model: `Modules\Core\Traits\RegistaAutoria` + `Modules\Core\Traits\SincronizaEstadoDescricao` — nunca escrever hook `booted()` próprio para autoria/estado.
- Sem operação de eliminar em Curso: nada de `EliminarCursoAction`, rota `destroy`, ou permissão `curso.eliminar`.
- Alterar estado é sempre "estado alvo explícito" (`PATCH .../estado` com `estado` no payload), nunca um toggle cego.
- Controllers finos: toda a lógica de leitura vive em `*ConsultaService`, toda a escrita em `Gestao*Service` → `*Action`. Nada de query ou regra de negócio inline no Controller.
- `use` sempre no topo de cada ficheiro PHP; nunca FQN inline (`\Inertia\Inertia::render(...)`).
- Módulo Curso é de entidade única: páginas em `resources/js/Pages/Index.vue` / `Show.vue` directamente, sem subpasta `Pages/Cursos/...` (essa convenção só se aplica a módulos multi-entidade como Infraestrutura/Turma).
- `turmas.curso_id`: obrigatório (NOT NULL), FK para `cursos`, `restrictOnDelete` (nunca `nullOnDelete` — incompatível com coluna obrigatória).
- Unicidade de `turmas.codigo` mantém-se `unique(ano_lectivo_id, codigo)` — `curso_id` não entra nesta constraint.
- Toda alteração de ficheiro de teste existente que passe a falhar por causa de `curso_id` deve ser corrigida no mesmo commit que introduz a coluna (não deixar testes vermelhos entre tasks).

---

## Estrutura de Ficheiros

**Módulo Curso (novo):**
```
Modules/Curso/
├── module.json, composer.json, config/config.php
├── app/Providers/{CursoServiceProvider,EventServiceProvider,RouteServiceProvider}.php
├── app/Models/Curso.php
├── app/DTO/CursoDTO.php
├── app/Actions/{CriarCursoAction,AtualizarCursoAction,AlterarEstadoCursoAction}.php
├── app/Http/Requests/{CriarCursoRequest,AtualizarCursoRequest,AlterarEstadoCursoRequest}.php
├── app/Http/Controllers/CursoController.php
├── app/Services/{GestaoCursoService,CursoConsultaService}.php
├── database/migrations/2026_09_09_090000_create_cursos_table.php
├── routes/web.php
├── resources/js/Models/Estado.js
├── resources/js/Components/Shared/EstadoBadge.vue
├── resources/js/Components/CursoFormModal.vue
├── resources/js/Pages/{Index,Show}.vue
└── tests/Feature/{CursoModelTest,CriarCursoActionTest,AtualizarCursoActionTest,AlterarEstadoCursoActionTest,CriarCursoRequestTest,AtualizarCursoRequestTest,CursoHttpTest,CursoAutorizacaoTest}.php
```

**Módulos existentes tocados:**
```
Modules/Permissao/app/Enums/Modulo.php                          — novo case CURSO
Modules/Permissao/database/seeders/{ModuloSeeder,RolePermissaoSeeder}.php
Modules/Permissao/tests/Feature/ModuloSeederTest.php             — contagem 13 → 14
Modules/Permissao/tests/Unit/ModuloEnumTest.php                  — slug curso
modules_statuses.json                                            — "Curso": true
resources/js/Composables/useAcademicoMenu.js                     — link Cursos

Modules/Turma/database/migrations/2026_09_09_090100_add_curso_id_to_turmas_table.php  — novo
Modules/Turma/app/Models/Turma.php                                — fillable + relação curso()
Modules/Turma/app/DTO/TurmaDTO.php                                — campo curso_id
Modules/Turma/app/Http/Requests/{CriarTurmaRequest,AtualizarTurmaRequest}.php — regra curso_id
Modules/Turma/app/Actions/{CriarTurmaAction,AtualizarTurmaAction}.php — persistir curso_id
Modules/Turma/app/Services/TurmaConsultaService.php               — opcoesFormulario() com cursos
Modules/Turma/resources/js/Components/Turma/TurmaFormModal.vue    — select Curso obrigatório
Modules/Turma/resources/js/Pages/Turmas/{Index,Show}.vue          — mostrar curso
Modules/Turma/tests/Feature/TurmaHttpTest.php                     — todas as fixtures ganham curso_id
```

---

## Task 1: Scaffold do módulo Curso + permissões

**Files:**
- Create: `Modules/Curso/module.json`, `Modules/Curso/composer.json`, `Modules/Curso/config/config.php`
- Create: `Modules/Curso/app/Providers/{CursoServiceProvider,EventServiceProvider,RouteServiceProvider}.php`
- Create: `Modules/Curso/routes/web.php` (vazio nesta task, populado na Task 5)
- Modify: `modules_statuses.json`
- Modify: `Modules/Permissao/app/Enums/Modulo.php`
- Modify: `Modules/Permissao/database/seeders/ModuloSeeder.php`
- Modify: `Modules/Permissao/database/seeders/RolePermissaoSeeder.php`
- Modify: `Modules/Permissao/tests/Feature/ModuloSeederTest.php`
- Modify: `Modules/Permissao/tests/Unit/ModuloEnumTest.php`

**Interfaces:**
- Produces: `Modules\Permissao\Enums\Modulo::CURSO` (int `13`, slug `curso`, label `Curso`) — usado por todas as tasks seguintes para permissões `curso.*`.
- Produces: módulo `Curso` activo em `modules_statuses.json`, autoloadable via `Modules\Curso\`.

- [ ] **Step 1: Gerar o esqueleto do módulo**

```bash
php artisan module:make Curso
```

Isto cria `Modules/Curso/` com a estrutura padrão do `nwidart/laravel-modules` (composer.json, module.json, config, providers, routes, resources).

- [ ] **Step 2: Ajustar `module.json` gerado**

```json
{
    "name": "Curso",
    "alias": "curso",
    "description": "",
    "keywords": [],
    "priority": 0,
    "providers": [
        "Modules\\Curso\\Providers\\CursoServiceProvider"
    ],
    "files": []
}
```

- [ ] **Step 3: Confirmar `composer.json` gerado tem o autoload correcto**

`Modules/Curso/composer.json` deve conter:

```json
{
    "name": "nwidart/curso",
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
            "aliases": {}
        }
    },
    "autoload": {
        "psr-4": {
            "Modules\\Curso\\": "app/",
            "Modules\\Curso\\Database\\Factories\\": "database/factories/",
            "Modules\\Curso\\Database\\Seeders\\": "database/seeders/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Modules\\Curso\\Tests\\": "tests/"
        }
    }
}
```

(Ajustar manualmente se o gerador produzir algo diferente — este é o padrão usado por `Modules/Turma/composer.json`.)

- [ ] **Step 4: Ajustar `CursoServiceProvider`**

`Modules/Curso/app/Providers/CursoServiceProvider.php`:

```php
<?php

namespace Modules\Curso\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class CursoServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Curso';

    protected string $nameLower = 'curso';

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

- [ ] **Step 5: Activar o módulo**

Editar `modules_statuses.json` (raiz do projecto) e acrescentar:

```json
"Curso": true
```

Confirmar:

```bash
php artisan module:list
```

Esperado: `[Enabled] Curso ... Modules/Curso [0]`.

- [ ] **Step 6: Escrever o teste que falha (contagem de módulos)**

Editar `Modules/Permissao/tests/Feature/ModuloSeederTest.php`, linha do `assertSame(13, ...)`:

```php
    public function test_seeder_e_idempotente(): void
    {
        $this->seed(ModuloSeeder::class);
        $contagemInicial = Modulo::count();

        $this->seed(ModuloSeeder::class);

        $this->assertSame($contagemInicial, Modulo::count());
        $this->assertSame(14, Modulo::count());
    }
```

- [ ] **Step 7: Correr o teste e confirmar que falha**

```bash
php artisan test --filter=ModuloSeederTest
```

Esperado: FAIL — `Failed asserting that 13 matches expected 14.`

- [ ] **Step 8: Adicionar o case `CURSO` ao enum `Modulo`**

Editar `Modules/Permissao/app/Enums/Modulo.php`:

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
    case INFRAESTRUTURA = 12;
    case CURSO = 13;

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
            self::CURSO => 'curso',
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
            self::CURSO => 'Curso',
        };
    }
}
```

- [ ] **Step 9: Adicionar o módulo ao `ModuloSeeder`**

Editar `Modules/Permissao/database/seeders/ModuloSeeder.php`, acrescentar à lista `$modulos`:

```php
            ['nome' => 13, 'descricao' => 'Curso'],
```

(logo a seguir à entrada `12 => 'Infraestrutura'`).

- [ ] **Step 10: Conceder permissões a ADMIN_ESCOLA no `RolePermissaoSeeder`**

Editar `Modules/Permissao/database/seeders/RolePermissaoSeeder.php`, dentro do array de `Perfil::ADMIN_ESCOLA->value`, acrescentar:

```php
                Modulo::CURSO->value => ['ver', 'criar', 'editar'],
```

- [ ] **Step 11: Correr o teste e confirmar que passa**

```bash
php artisan test --filter=ModuloSeederTest
```

Esperado: PASS (3/3).

- [ ] **Step 12: Reforçar `ModuloEnumTest` com o novo slug**

Editar `Modules/Permissao/tests/Unit/ModuloEnumTest.php`, no método `test_slugs_especificos`:

```php
    public function test_slugs_especificos(): void
    {
        $this->assertSame('horario', Modulo::HORARIO->slug());
        $this->assertSame('turmas', Modulo::TURMAS->slug());
        $this->assertSame('ano-lectivo', Modulo::ANO_LECTIVO->slug());
        $this->assertSame('estabelecimento', Modulo::ESTABELECIMENTO->slug());
        $this->assertSame('curso', Modulo::CURSO->slug());
    }
```

- [ ] **Step 13: Correr toda a suite do Permissao e confirmar verde**

```bash
php artisan test --filter=Permissao
```

Esperado: PASS em todos os testes de `Modules/Permissao`.

- [ ] **Step 14: Commit**

```bash
git add Modules/Curso Modules/Permissao modules_statuses.json
git commit -m "feat(curso): scaffold do módulo Curso e permissões associadas"
```

---

## Task 2: Migração + Model `Curso`

**Files:**
- Create: `Modules/Curso/database/migrations/2026_09_09_090000_create_cursos_table.php`
- Create: `Modules/Curso/app/Models/Curso.php`
- Test: `Modules/Curso/tests/Feature/CursoModelTest.php`

**Interfaces:**
- Consumes: `Modules\Core\Traits\RegistaAutoria`, `Modules\Core\Traits\SincronizaEstadoDescricao`, `Modules\Core\Enums\Estado`, `Modules\Estabelecimento\Models\Estabelecimento`, `Modules\Usuario\Models\User`.
- Produces: `Modules\Curso\Models\Curso` com `$fillable = ['estabelecimento_id','codigo','nome','descricao','estado','estado_descricao','criado_por','editado_por']`, relações `estabelecimento()`, `criadoPor()`, `editadoPor()` — usado por todas as tasks seguintes.

- [ ] **Step 1: Escrever o teste (falha por a tabela/model não existirem)**

Criar `Modules/Curso/tests/Feature/CursoModelTest.php`:

```php
<?php

namespace Modules\Curso\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Enums\Estado;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class CursoModelTest extends TestCase
{
    use RefreshDatabase;

    private function criarEstabelecimento(): Estabelecimento
    {
        return Estabelecimento::create([
            'nome' => 'Escola Teste',
            'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
            'is_active' => true,
        ]);
    }

    public function test_criar_curso_regista_autoria_e_sincroniza_estado_descricao(): void
    {
        $user = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        $this->actingAs($user);
        $estabelecimento = $this->criarEstabelecimento();

        $curso = Curso::create([
            'estabelecimento_id' => $estabelecimento->id,
            'codigo' => 'INF',
            'nome' => 'Informática',
        ]);

        $this->assertSame($user->id, $curso->criado_por);
        $this->assertSame($user->id, $curso->editado_por);
        $this->assertSame(Estado::ATIVO->value, $curso->estado);
        $this->assertSame('Ativo', $curso->estado_descricao);
    }

    public function test_atualizar_estado_sincroniza_descricao_e_editado_por(): void
    {
        $criador = User::create(['name' => 'Criador', 'email' => 'criador@example.com', 'password' => Hash::make('x')]);
        $this->actingAs($criador);
        $estabelecimento = $this->criarEstabelecimento();
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $editor = User::create(['name' => 'Editor', 'email' => 'editor@example.com', 'password' => Hash::make('x')]);
        $this->actingAs($editor);
        $curso->update(['estado' => Estado::INATIVO->value]);

        $curso->refresh();
        $this->assertSame(Estado::INATIVO->value, $curso->estado);
        $this->assertSame('Inativo', $curso->estado_descricao);
        $this->assertSame($editor->id, $curso->editado_por);
        $this->assertSame($criador->id, $curso->criado_por);
    }

    public function test_codigo_duplicado_no_mesmo_estabelecimento_viola_constraint(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Outro Nome']);
    }

    public function test_nome_duplicado_no_mesmo_estabelecimento_viola_constraint(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'OUTRO', 'nome' => 'Informática']);
    }

    public function test_mesmo_codigo_e_nome_permitido_em_estabelecimentos_diferentes(): void
    {
        $estabelecimentoA = $this->criarEstabelecimento();
        $estabelecimentoB = Estabelecimento::create(['nome' => 'Escola B', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => false]);

        $cursoA = Curso::create(['estabelecimento_id' => $estabelecimentoA->id, 'codigo' => 'INF', 'nome' => 'Informática']);
        $cursoB = Curso::create(['estabelecimento_id' => $estabelecimentoB->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $this->assertNotSame($cursoA->id, $cursoB->id);
    }
}
```

- [ ] **Step 2: Correr o teste e confirmar que falha**

```bash
php artisan test --filter=CursoModelTest
```

Esperado: FAIL — tabela `cursos` não existe / classe `Curso` não existe.

- [ ] **Step 3: Criar a migração**

`Modules/Curso/database/migrations/2026_09_09_090000_create_cursos_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cursos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estabelecimento_id')->constrained('estabelecimentos')->restrictOnDelete();
            $table->string('codigo');
            $table->string('nome');
            $table->text('descricao')->nullable();
            $table->unsignedTinyInteger('estado')->default(1);
            $table->string('estado_descricao')->default('Ativo');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['estabelecimento_id', 'codigo']);
            $table->unique(['estabelecimento_id', 'nome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cursos');
    }
};
```

- [ ] **Step 4: Criar o Model**

`Modules/Curso/app/Models/Curso.php`:

```php
<?php

namespace Modules\Curso\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Core\Traits\SincronizaEstadoDescricao;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Models\User;

class Curso extends Model
{
    use RegistaAutoria;
    use SincronizaEstadoDescricao;

    protected $table = 'cursos';

    protected $fillable = [
        'estabelecimento_id',
        'codigo',
        'nome',
        'descricao',
        'estado',
        'estado_descricao',
        'criado_por',
        'editado_por',
    ];

    protected $casts = [
        'estado' => 'integer',
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
}
```

- [ ] **Step 5: Correr o teste e confirmar que passa**

```bash
php artisan test --filter=CursoModelTest
```

Esperado: PASS (5/5).

- [ ] **Step 6: Commit**

```bash
git add Modules/Curso/database/migrations Modules/Curso/app/Models Modules/Curso/tests
git commit -m "feat(curso): migração e model Curso com autoria e estado sincronizado"
```

---

## Task 3: DTO + Actions

**Files:**
- Create: `Modules/Curso/app/DTO/CursoDTO.php`
- Create: `Modules/Curso/app/Actions/{CriarCursoAction,AtualizarCursoAction,AlterarEstadoCursoAction}.php`
- Test: `Modules/Curso/tests/Feature/{CriarCursoActionTest,AtualizarCursoActionTest,AlterarEstadoCursoActionTest}.php`

**Interfaces:**
- Consumes: `Modules\Curso\Models\Curso` (Task 2), `Modules\Estabelecimento\Models\Estabelecimento::current()`.
- Produces: `CursoDTO(string $codigo, string $nome, ?string $descricao = null)` com `fromCriarRequest`/`fromAtualizarRequest` (usados na Task 5); `CriarCursoAction::executar(CursoDTO): Curso`; `AtualizarCursoAction::executar(Curso, CursoDTO): Curso`; `AlterarEstadoCursoAction::executar(Curso, Estado): Curso`.

- [ ] **Step 1: Escrever os testes (falham por as classes não existirem)**

`Modules/Curso/tests/Feature/CriarCursoActionTest.php`:

```php
<?php

namespace Modules\Curso\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Enums\Estado;
use Modules\Curso\Actions\CriarCursoAction;
use Modules\Curso\DTO\CursoDTO;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Tests\TestCase;

class CriarCursoActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_curso_associado_ao_estabelecimento_activo_com_estado_ativo_por_defeito(): void
    {
        $estabelecimento = Estabelecimento::create([
            'nome' => 'Escola Teste',
            'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
            'is_active' => true,
        ]);

        $curso = (new CriarCursoAction())->executar(new CursoDTO(
            codigo: 'INF',
            nome: 'Informática',
            descricao: 'Curso técnico de Informática',
        ));

        $this->assertSame($estabelecimento->id, $curso->estabelecimento_id);
        $this->assertSame(Estado::ATIVO->value, $curso->estado);
        $this->assertSame('INF', $curso->codigo);
        $this->assertSame('Curso técnico de Informática', $curso->descricao);
    }
}
```

`Modules/Curso/tests/Feature/AtualizarCursoActionTest.php`:

```php
<?php

namespace Modules\Curso\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Curso\Actions\AtualizarCursoAction;
use Modules\Curso\DTO\CursoDTO;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Tests\TestCase;

class AtualizarCursoActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_atualiza_codigo_nome_e_descricao(): void
    {
        $estabelecimento = Estabelecimento::create([
            'nome' => 'Escola Teste',
            'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
            'is_active' => true,
        ]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $atualizado = (new AtualizarCursoAction())->executar($curso, new CursoDTO(
            codigo: 'INF2',
            nome: 'Informática e Sistemas',
            descricao: 'Descrição nova',
        ));

        $this->assertSame('INF2', $atualizado->codigo);
        $this->assertSame('Informática e Sistemas', $atualizado->nome);
        $this->assertSame('Descrição nova', $atualizado->descricao);
    }
}
```

`Modules/Curso/tests/Feature/AlterarEstadoCursoActionTest.php`:

```php
<?php

namespace Modules\Curso\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Enums\Estado;
use Modules\Curso\Actions\AlterarEstadoCursoAction;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Tests\TestCase;

class AlterarEstadoCursoActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_desactiva_curso_e_sincroniza_descricao(): void
    {
        $estabelecimento = Estabelecimento::create([
            'nome' => 'Escola Teste',
            'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
            'is_active' => true,
        ]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $atualizado = (new AlterarEstadoCursoAction())->executar($curso, Estado::INATIVO);

        $this->assertSame(Estado::INATIVO->value, $atualizado->estado);
        $this->assertSame('Inativo', $atualizado->estado_descricao);
    }
}
```

- [ ] **Step 2: Correr os testes e confirmar que falham**

```bash
php artisan test --filter="CriarCursoActionTest|AtualizarCursoActionTest|AlterarEstadoCursoActionTest"
```

Esperado: FAIL — classes `CursoDTO`, `CriarCursoAction`, `AtualizarCursoAction`, `AlterarEstadoCursoAction` não existem.

- [ ] **Step 3: Criar o DTO**

`Modules/Curso/app/DTO/CursoDTO.php`:

```php
<?php

namespace Modules\Curso\DTO;

use Modules\Curso\Http\Requests\AtualizarCursoRequest;
use Modules\Curso\Http\Requests\CriarCursoRequest;

class CursoDTO
{
    public function __construct(
        public string $codigo,
        public string $nome,
        public ?string $descricao = null,
    ) {
    }

    public static function fromCriarRequest(CriarCursoRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            codigo: $dados['codigo'],
            nome: $dados['nome'],
            descricao: $dados['descricao'] ?? null,
        );
    }

    public static function fromAtualizarRequest(AtualizarCursoRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            codigo: $dados['codigo'],
            nome: $dados['nome'],
            descricao: $dados['descricao'] ?? null,
        );
    }
}
```

Nota: esta classe referencia `Http\Requests\CriarCursoRequest`/`AtualizarCursoRequest`, que só existem a partir da Task 4 — isto é esperado (mesma ordem de dependência usada em `NivelAcademicoDTO`); o autoloader PHP não resolve a classe no `use` até ser chamada, por isso os testes de Action desta task (que não tocam nos métodos `from*Request`) continuam a passar.

- [ ] **Step 4: Criar as Actions**

`Modules/Curso/app/Actions/CriarCursoAction.php`:

```php
<?php

namespace Modules\Curso\Actions;

use Modules\Curso\DTO\CursoDTO;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Models\Estabelecimento;

class CriarCursoAction
{
    public function executar(CursoDTO $dto): Curso
    {
        return Curso::create([
            'estabelecimento_id' => Estabelecimento::current()?->id,
            'codigo' => $dto->codigo,
            'nome' => $dto->nome,
            'descricao' => $dto->descricao,
        ]);
    }
}
```

`Modules/Curso/app/Actions/AtualizarCursoAction.php`:

```php
<?php

namespace Modules\Curso\Actions;

use Modules\Curso\DTO\CursoDTO;
use Modules\Curso\Models\Curso;

class AtualizarCursoAction
{
    public function executar(Curso $curso, CursoDTO $dto): Curso
    {
        $curso->fill([
            'codigo' => $dto->codigo,
            'nome' => $dto->nome,
            'descricao' => $dto->descricao,
        ]);

        $curso->save();

        return $curso->fresh();
    }
}
```

`Modules/Curso/app/Actions/AlterarEstadoCursoAction.php`:

```php
<?php

namespace Modules\Curso\Actions;

use Modules\Core\Enums\Estado;
use Modules\Curso\Models\Curso;

class AlterarEstadoCursoAction
{
    public function executar(Curso $curso, Estado $novoEstado): Curso
    {
        $curso->estado = $novoEstado->value;
        $curso->save();

        return $curso->fresh();
    }
}
```

- [ ] **Step 5: Correr os testes e confirmar que passam**

```bash
php artisan test --filter="CriarCursoActionTest|AtualizarCursoActionTest|AlterarEstadoCursoActionTest"
```

Esperado: PASS (3/3).

- [ ] **Step 6: Commit**

```bash
git add Modules/Curso/app/DTO Modules/Curso/app/Actions Modules/Curso/tests
git commit -m "feat(curso): DTO e Actions de criação, atualização e mudança de estado"
```

---

## Task 4: Form Requests

**Files:**
- Create: `Modules/Curso/app/Http/Requests/{CriarCursoRequest,AtualizarCursoRequest,AlterarEstadoCursoRequest}.php`
- Test: `Modules/Curso/tests/Feature/{CriarCursoRequestTest,AtualizarCursoRequestTest}.php`

**Interfaces:**
- Consumes: `App\Http\Requests\BaseRequest`, `Modules\Core\Enums\Estado`, `Modules\Estabelecimento\Models\Estabelecimento::current()`.
- Produces: `CriarCursoRequest`/`AtualizarCursoRequest` com regras `codigo` (required|string|max:50|unique por estabelecimento), `nome` (required|string|max:255|unique por estabelecimento), `descricao` (nullable|string); `AlterarEstadoCursoRequest` com `estado` (required, Enum). Autorização via `curso.criar`/`curso.editar` (definidos na Task 1).

- [ ] **Step 1: Escrever os testes (falham por as classes não existirem)**

`Modules/Curso/tests/Feature/CriarCursoRequestTest.php`:

```php
<?php

namespace Modules\Curso\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Modules\Curso\Http\Requests\CriarCursoRequest;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Tests\TestCase;

class CriarCursoRequestTest extends TestCase
{
    use RefreshDatabase;

    private function validar(array $dados): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($dados, (new CriarCursoRequest())->rules());
    }

    public function test_dados_validos_passam(): void
    {
        $validador = $this->validar(['codigo' => 'INF', 'nome' => 'Informática']);

        $this->assertFalse($validador->fails());
    }

    public function test_codigo_e_obrigatorio(): void
    {
        $validador = $this->validar(['nome' => 'Informática']);

        $this->assertTrue($validador->fails());
        $this->assertArrayHasKey('codigo', $validador->errors()->toArray());
    }

    public function test_nome_e_obrigatorio(): void
    {
        $validador = $this->validar(['codigo' => 'INF']);

        $this->assertTrue($validador->fails());
        $this->assertArrayHasKey('nome', $validador->errors()->toArray());
    }

    public function test_codigo_duplicado_no_mesmo_estabelecimento_falha(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
        Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $validador = $this->validar(['codigo' => 'INF', 'nome' => 'Outro Nome']);

        $this->assertTrue($validador->fails());
        $this->assertArrayHasKey('codigo', $validador->errors()->toArray());
    }

    public function test_nome_duplicado_no_mesmo_estabelecimento_falha(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
        Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $validador = $this->validar(['codigo' => 'OUTRO', 'nome' => 'Informática']);

        $this->assertTrue($validador->fails());
        $this->assertArrayHasKey('nome', $validador->errors()->toArray());
    }

    public function test_mesmo_codigo_em_estabelecimentos_diferentes_e_permitido(): void
    {
        $estabelecimentoA = Estabelecimento::create(['nome' => 'Escola A', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => false]);
        Curso::create(['estabelecimento_id' => $estabelecimentoA->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        Estabelecimento::create(['nome' => 'Escola B', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);

        $validador = $this->validar(['codigo' => 'INF', 'nome' => 'Informática (outra escola)']);

        $this->assertFalse($validador->fails());
    }
}
```

`Modules/Curso/tests/Feature/AtualizarCursoRequestTest.php` (sem depender de binding de rota — a verificação do `ignore()` em uso real é feita via HTTP na Task 5, `CursoHttpTest`):

```php
<?php

namespace Modules\Curso\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Modules\Curso\Http\Requests\AtualizarCursoRequest;
use Tests\TestCase;

class AtualizarCursoRequestTest extends TestCase
{
    use RefreshDatabase;

    private function validar(array $dados): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($dados, (new AtualizarCursoRequest())->rules());
    }

    public function test_dados_validos_passam(): void
    {
        $validador = $this->validar(['codigo' => 'INF', 'nome' => 'Informática']);

        $this->assertFalse($validador->fails());
    }

    public function test_codigo_e_obrigatorio(): void
    {
        $validador = $this->validar(['nome' => 'Informática']);

        $this->assertTrue($validador->fails());
        $this->assertArrayHasKey('codigo', $validador->errors()->toArray());
    }

    public function test_nome_e_obrigatorio(): void
    {
        $validador = $this->validar(['codigo' => 'INF']);

        $this->assertTrue($validador->fails());
        $this->assertArrayHasKey('nome', $validador->errors()->toArray());
    }
}
```

- [ ] **Step 2: Correr os testes e confirmar que falham**

```bash
php artisan test --filter="CriarCursoRequestTest|AtualizarCursoRequestTest"
```

Esperado: FAIL — classes `CriarCursoRequest`/`AtualizarCursoRequest` não existem.

- [ ] **Step 3: Criar as Form Requests**

`Modules/Curso/app/Http/Requests/CriarCursoRequest.php`:

```php
<?php

namespace Modules\Curso\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Estabelecimento\Models\Estabelecimento;

class CriarCursoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('curso.criar') ?? false;
    }

    public function rules(): array
    {
        return [
            'codigo' => [
                'required',
                'string',
                'max:50',
                Rule::unique('cursos', 'codigo')
                    ->where(fn ($query) => $query->where('estabelecimento_id', Estabelecimento::current()?->id)),
            ],
            'nome' => [
                'required',
                'string',
                'max:255',
                Rule::unique('cursos', 'nome')
                    ->where(fn ($query) => $query->where('estabelecimento_id', Estabelecimento::current()?->id)),
            ],
            'descricao' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'codigo.required' => 'O código do curso é obrigatório.',
            'codigo.unique' => 'Já existe um curso com este código neste estabelecimento.',
            'codigo.max' => 'O código do curso não pode ultrapassar 50 caracteres.',
            'nome.required' => 'O nome do curso é obrigatório.',
            'nome.unique' => 'Já existe um curso com este nome neste estabelecimento.',
            'nome.max' => 'O nome do curso não pode ultrapassar 255 caracteres.',
        ];
    }
}
```

`Modules/Curso/app/Http/Requests/AtualizarCursoRequest.php`:

```php
<?php

namespace Modules\Curso\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Estabelecimento\Models\Estabelecimento;

class AtualizarCursoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('curso.editar') ?? false;
    }

    public function rules(): array
    {
        return [
            'codigo' => [
                'required',
                'string',
                'max:50',
                Rule::unique('cursos', 'codigo')
                    ->where(fn ($query) => $query->where('estabelecimento_id', Estabelecimento::current()?->id))
                    ->ignore($this->route('curso')),
            ],
            'nome' => [
                'required',
                'string',
                'max:255',
                Rule::unique('cursos', 'nome')
                    ->where(fn ($query) => $query->where('estabelecimento_id', Estabelecimento::current()?->id))
                    ->ignore($this->route('curso')),
            ],
            'descricao' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'codigo.required' => 'O código do curso é obrigatório.',
            'codigo.unique' => 'Já existe um curso com este código neste estabelecimento.',
            'nome.required' => 'O nome do curso é obrigatório.',
            'nome.unique' => 'Já existe um curso com este nome neste estabelecimento.',
        ];
    }
}
```

`Modules/Curso/app/Http/Requests/AlterarEstadoCursoRequest.php`:

```php
<?php

namespace Modules\Curso\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rules\Enum;
use Modules\Core\Enums\Estado;

class AlterarEstadoCursoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('curso.editar') ?? false;
    }

    public function rules(): array
    {
        return [
            'estado' => ['required', new Enum(Estado::class)],
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

- [ ] **Step 4: Correr os testes e confirmar que passam**

```bash
php artisan test --filter="CriarCursoRequestTest|AtualizarCursoRequestTest"
```

Esperado: PASS (8/8).

- [ ] **Step 5: Commit**

```bash
git add Modules/Curso/app/Http/Requests Modules/Curso/tests
git commit -m "feat(curso): form requests com validação de unicidade por estabelecimento"
```

---

## Task 5: Services + Controller + Rotas

**Files:**
- Create: `Modules/Curso/app/Services/{GestaoCursoService,CursoConsultaService}.php`
- Create: `Modules/Curso/app/Http/Controllers/CursoController.php`
- Modify: `Modules/Curso/routes/web.php`
- Test: `Modules/Curso/tests/Feature/{CursoHttpTest,CursoAutorizacaoTest}.php`

**Interfaces:**
- Consumes: tudo das Tasks 2-4.
- Produces: rotas nomeadas `cursos.index|store|show|update|alterar-estado` — consumidas pelo frontend na Task 6.

- [ ] **Step 1: Escrever os testes (falham — rotas não existem)**

`Modules/Curso/tests/Feature/CursoHttpTest.php`:

```php
<?php

namespace Modules\Curso\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Enums\Estado;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class CursoHttpTest extends TestCase
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

    private function criarEstabelecimento(): Estabelecimento
    {
        return Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
    }

    public function test_cria_curso_via_http_infere_estabelecimento_actual_e_regista_autoria(): void
    {
        $staff = $this->actingAsStaff();
        $this->criarEstabelecimento();

        $this->post(route('cursos.store'), [
            'codigo' => 'INF',
            'nome' => 'Informática',
            'descricao' => 'Curso técnico de Informática',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $curso = Curso::firstWhere('codigo', 'INF');
        $this->assertNotNull($curso);
        $this->assertSame($staff->id, $curso->criado_por);
        $this->assertSame(Estabelecimento::current()->id, $curso->estabelecimento_id);
        $this->assertSame(Estado::ATIVO->value, $curso->estado);
        $this->assertSame('Ativo', $curso->estado_descricao);
    }

    public function test_atualiza_curso_via_http_e_actualiza_editado_por(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $outroStaff = User::create(['name' => 'Outro Staff', 'email' => 'outro@example.com', 'password' => Hash::make('x')]);
        $outroStaff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);
        $this->actingAs($outroStaff);

        $this->put(route('cursos.update', $curso), [
            'codigo' => 'INF',
            'nome' => 'Informática e Sistemas',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $curso->refresh();
        $this->assertSame('Informática e Sistemas', $curso->nome);
        $this->assertSame($outroStaff->id, $curso->editado_por);
    }

    public function test_altera_estado_via_http(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $this->patch(route('cursos.alterar-estado', $curso), [
            'estado' => Estado::INATIVO->value,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(Estado::INATIVO->value, $curso->fresh()->estado);
    }

    public function test_atualizar_curso_mantendo_o_proprio_codigo_e_nome_nao_falha(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $this->put(route('cursos.update', $curso), [
            'codigo' => 'INF',
            'nome' => 'Informática',
            'descricao' => 'Descrição actualizada',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('Descrição actualizada', $curso->fresh()->descricao);
    }

    public function test_atualizar_curso_com_codigo_de_outro_curso_do_mesmo_estabelecimento_falha(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);
        $cursoEmEdicao = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'CONT', 'nome' => 'Contabilidade']);

        $this->put(route('cursos.update', $cursoEmEdicao), [
            'codigo' => 'INF',
            'nome' => 'Contabilidade',
        ])->assertSessionHasErrors('codigo');
    }

    public function test_index_expoe_apenas_cursos_do_estabelecimento_actual(): void
    {
        $this->actingAsStaff();
        $estabelecimentoActual = $this->criarEstabelecimento();
        Curso::create(['estabelecimento_id' => $estabelecimentoActual->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $outroEstabelecimento = Estabelecimento::create(['nome' => 'Outra Escola', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => false]);
        Curso::create(['estabelecimento_id' => $outroEstabelecimento->id, 'codigo' => 'CONT', 'nome' => 'Contabilidade']);

        $this->get(route('cursos.index'))->assertInertia(fn (Assert $page) => $page
            ->component('Curso/Index')
            ->has('cursos', 1)
            ->where('cursos.0.codigo', 'INF')
        );
    }

    public function test_show_expoe_o_curso(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);

        $this->get(route('cursos.show', $curso))->assertInertia(fn (Assert $page) => $page
            ->component('Curso/Show')
            ->where('curso.codigo', 'INF')
        );
    }

    public function test_professor_recebe_403_em_todas_as_rotas_de_escrita(): void
    {
        $this->actingAsProfessor();

        $this->post(route('cursos.store'), ['codigo' => 'INF', 'nome' => 'Informática'])->assertForbidden();
    }

    public function test_professor_recebe_403_ao_listar(): void
    {
        $this->actingAsProfessor();

        $this->get(route('cursos.index'))->assertForbidden();
    }
}
```

`Modules/Curso/tests/Feature/CursoAutorizacaoTest.php`:

```php
<?php

namespace Modules\Curso\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class CursoAutorizacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissaoDatabaseSeeder::class);
    }

    public function test_admin_escola_tem_permissao_em_curso(): void
    {
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);

        foreach (['ver', 'criar', 'editar'] as $acao) {
            $this->assertTrue(Gate::forUser($staff)->allows("curso.{$acao}"), "curso.{$acao}");
        }
    }

    public function test_professor_nao_tem_permissao_em_curso(): void
    {
        $professor = User::create(['name' => 'Professor', 'email' => 'professor@example.com', 'password' => Hash::make('x')]);
        $professor->roles()->syncWithoutDetaching([Role::where('nome', Perfil::PROFESSOR->value)->first()->id]);

        $this->assertFalse(Gate::forUser($professor)->allows('curso.ver'));
    }
}
```

- [ ] **Step 2: Correr os testes e confirmar que falham**

```bash
php artisan test --filter="CursoHttpTest|CursoAutorizacaoTest"
```

Esperado: FAIL — rota `cursos.store` (e restantes) não definida.

- [ ] **Step 3: Criar os Services**

`Modules/Curso/app/Services/GestaoCursoService.php`:

```php
<?php

namespace Modules\Curso\Services;

use Modules\Core\Enums\Estado;
use Modules\Curso\Actions\AlterarEstadoCursoAction;
use Modules\Curso\Actions\AtualizarCursoAction;
use Modules\Curso\Actions\CriarCursoAction;
use Modules\Curso\DTO\CursoDTO;
use Modules\Curso\Http\Requests\AtualizarCursoRequest;
use Modules\Curso\Http\Requests\CriarCursoRequest;
use Modules\Curso\Models\Curso;

class GestaoCursoService
{
    public function __construct(
        private CriarCursoAction $criarCurso,
        private AtualizarCursoAction $atualizarCurso,
        private AlterarEstadoCursoAction $alterarEstadoCurso,
    ) {
    }

    public function criar(CriarCursoRequest $request): Curso
    {
        return $this->criarCurso->executar(CursoDTO::fromCriarRequest($request));
    }

    public function atualizar(Curso $curso, AtualizarCursoRequest $request): Curso
    {
        return $this->atualizarCurso->executar($curso, CursoDTO::fromAtualizarRequest($request));
    }

    public function alterarEstado(Curso $curso, Estado $novoEstado): Curso
    {
        return $this->alterarEstadoCurso->executar($curso, $novoEstado);
    }
}
```

`Modules/Curso/app/Services/CursoConsultaService.php`:

```php
<?php

namespace Modules\Curso\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Models\Estabelecimento;

class CursoConsultaService
{
    public function listar(): Collection
    {
        return Curso::where('estabelecimento_id', Estabelecimento::current()?->id)
            ->orderBy('nome')
            ->get();
    }
}
```

- [ ] **Step 4: Criar o Controller**

`Modules/Curso/app/Http/Controllers/CursoController.php`:

```php
<?php

namespace Modules\Curso\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Modules\Core\Enums\Estado;
use Modules\Curso\Http\Requests\AlterarEstadoCursoRequest;
use Modules\Curso\Http\Requests\AtualizarCursoRequest;
use Modules\Curso\Http\Requests\CriarCursoRequest;
use Modules\Curso\Models\Curso;
use Modules\Curso\Services\CursoConsultaService;
use Modules\Curso\Services\GestaoCursoService;

class CursoController extends Controller
{
    public function __construct(
        private GestaoCursoService $service,
        private CursoConsultaService $consulta,
    ) {
    }

    public function index()
    {
        $this->authorize('curso.ver');

        return Inertia::render('Curso/Index', [
            'cursos' => $this->consulta->listar(),
        ]);
    }

    public function show(Curso $curso)
    {
        $this->authorize('curso.ver');

        return Inertia::render('Curso/Show', [
            'curso' => $curso,
        ]);
    }

    public function store(CriarCursoRequest $request)
    {
        $this->authorize('curso.criar');

        $this->service->criar($request);

        return redirect()->back()->with('success', 'Curso criado com sucesso.');
    }

    public function update(AtualizarCursoRequest $request, Curso $curso)
    {
        $this->authorize('curso.editar');

        $this->service->atualizar($curso, $request);

        return redirect()->back()->with('success', 'Curso atualizado com sucesso.');
    }

    public function alterarEstado(AlterarEstadoCursoRequest $request, Curso $curso)
    {
        $this->authorize('curso.editar');

        $this->service->alterarEstado($curso, Estado::from((int) $request->validated('estado')));

        return redirect()->back()->with('success', 'Estado do curso atualizado com sucesso.');
    }
}
```

- [ ] **Step 5: Definir as rotas**

`Modules/Curso/routes/web.php`:

```php
<?php

use Illuminate\Support\Facades\Route;
use Modules\Curso\Http\Controllers\CursoController;

Route::middleware(['auth'])->prefix('cursos')->name('cursos.')->group(function () {
    Route::get('/', [CursoController::class, 'index'])->middleware('can:curso.ver')->name('index');
    Route::post('/', [CursoController::class, 'store'])->middleware('can:curso.criar')->name('store');
    Route::get('/{curso}', [CursoController::class, 'show'])->middleware('can:curso.ver')->name('show');
    Route::put('/{curso}', [CursoController::class, 'update'])->middleware('can:curso.editar')->name('update');
    Route::patch('/{curso}/estado', [CursoController::class, 'alterarEstado'])->middleware('can:curso.editar')->name('alterar-estado');
});
```

- [ ] **Step 6: Correr os testes e confirmar que passam**

```bash
php artisan test --filter="CursoHttpTest|CursoAutorizacaoTest"
```

Esperado: PASS (11/11).

- [ ] **Step 7: Correr toda a suite do módulo Curso**

```bash
php artisan test --filter=Curso
```

Esperado: PASS em todos os testes criados nas Tasks 2-5.

- [ ] **Step 8: Commit**

```bash
git add Modules/Curso/app/Services Modules/Curso/app/Http/Controllers Modules/Curso/routes Modules/Curso/tests
git commit -m "feat(curso): services, controller e rotas HTTP do módulo Curso"
```

---

## Task 6: Frontend do módulo Curso + menu

**Files:**
- Create: `Modules/Curso/resources/js/Models/Estado.js`
- Create: `Modules/Curso/resources/js/Components/Shared/EstadoBadge.vue`
- Create: `Modules/Curso/resources/js/Components/CursoFormModal.vue`
- Create: `Modules/Curso/resources/js/Pages/{Index,Show}.vue`
- Modify: `resources/js/Composables/useAcademicoMenu.js`

**Interfaces:**
- Consumes: rotas `cursos.*` (Task 5), componentes partilhados `@/Components/Shared/{AcaoIcone,ConfirmModal,SelectSolid}.vue`, `@/Composables/usePermissoes`.

- [ ] **Step 1: Criar o modelo de apresentação do Estado**

`Modules/Curso/resources/js/Models/Estado.js`:

```js
/**
 * Espelha Modules/Core/app/Enums/Estado.php — usado por Curso, que
 * reutiliza o mesmo Estado binário Ativo/Inativo do backend.
 * Só para apresentação (labels, cor de badge); a autoridade é o backend.
 */

export const ESTADO = Object.freeze({ INATIVO: 0, ATIVO: 1 });

export const estadoBadgeClass = (estado) => {
    switch (estado) {
        case ESTADO.ATIVO: return 'badge-light-success';
        case ESTADO.INATIVO: return 'badge-light-secondary';
        default: return 'badge-light-secondary';
    }
};
```

- [ ] **Step 2: Criar o badge de estado**

`Modules/Curso/resources/js/Components/Shared/EstadoBadge.vue`:

```vue
<script setup>
import { estadoBadgeClass } from '../../Models/Estado';

defineProps({
    estado: { type: Number, required: true },
    estadoDescricao: { type: String, required: true },
});
</script>

<template>
    <div class="badge fw-bold" :class="estadoBadgeClass(estado)">
        {{ estadoDescricao }}
    </div>
</template>
```

- [ ] **Step 3: Criar o modal de criação/edição**

`Modules/Curso/resources/js/Components/CursoFormModal.vue`:

```vue
<script setup>
import { reactive, watch } from 'vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import { ESTADO } from '../Models/Estado';

const props = defineProps({
    show: { type: Boolean, default: false },
    curso: { type: Object, default: null },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const ESTADO_OPCOES = [
    { value: ESTADO.ATIVO, label: 'Ativo' },
    { value: ESTADO.INATIVO, label: 'Inativo' },
];

const form = reactive({
    codigo: '',
    nome: '',
    descricao: '',
    estado: ESTADO.ATIVO,
});

watch(() => props.show, (show) => {
    if (!show) return;
    form.codigo = props.curso?.codigo ?? '';
    form.nome = props.curso?.nome ?? '';
    form.descricao = props.curso?.descricao ?? '';
    form.estado = props.curso?.estado ?? ESTADO.ATIVO;
});

function submeter() {
    const payload = { ...form };
    if (!props.curso) delete payload.estado;
    emit('submit', payload);
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-5">{{ curso ? 'Editar Curso' : 'Novo Curso' }}</h3>
                <form @submit.prevent="submeter">
                    <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Código</label>
                            <input v-model="form.codigo" type="text" class="form-control form-control-solid" placeholder="ex: INF" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.codigo">{{ errors.codigo }}</div>
                        </div>
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Nome</label>
                            <input v-model="form.nome" type="text" class="form-control form-control-solid" placeholder="ex: Informática" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.nome">{{ errors.nome }}</div>
                        </div>
                    </div>

                    <div class="fv-row mb-7">
                        <label class="fw-semibold fs-6 mb-2">Descrição</label>
                        <textarea v-model="form.descricao" class="form-control form-control-solid" rows="3"></textarea>
                        <div class="text-danger fs-7 mt-1" v-if="errors.descricao">{{ errors.descricao }}</div>
                    </div>

                    <div v-if="curso" class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Estado</label>
                        <SelectSolid v-model="form.estado" :options="ESTADO_OPCOES" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.estado">{{ errors.estado }}</div>
                    </div>

                    <div class="text-end">
                        <button type="button" class="btn btn-light-danger me-2" :disabled="processing" @click="emit('cancelar')">
                            Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary" :disabled="processing">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 4: Criar a página de listagem**

`Modules/Curso/resources/js/Pages/Index.vue`:

```vue
<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import EstadoBadge from '../Components/Shared/EstadoBadge.vue';
import CursoFormModal from '../Components/CursoFormModal.vue';
import { ESTADO } from '../Models/Estado';

defineProps({
    cursos: { type: Array, required: true },
});
defineOptions({ layout: AppLayout });

const modalAberto = ref(false);
const cursoEmEdicao = ref(null);
const processing = ref(false);
const errors = ref({});

function abrirCriacao() {
    cursoEmEdicao.value = null;
    errors.value = {};
    modalAberto.value = true;
}

function abrirEdicao(curso) {
    cursoEmEdicao.value = curso;
    errors.value = {};
    modalAberto.value = true;
}

function fecharModal() {
    modalAberto.value = false;
}

function guardar(payload) {
    processing.value = true;
    errors.value = {};

    const url = cursoEmEdicao.value ? `/cursos/${cursoEmEdicao.value.id}` : '/cursos';
    const metodo = cursoEmEdicao.value ? 'put' : 'post';

    router[metodo](url, payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(cursoEmEdicao.value ? 'Curso atualizado com sucesso.' : 'Curso criado com sucesso.');
            fecharModal();
        },
        onError: (erros) => {
            errors.value = erros;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}

const cursoParaAlterarEstado = ref(null);
const novoEstado = ref(null);
const alterandoEstado = ref(false);

function pedirAlteracaoEstado(curso, estado) {
    cursoParaAlterarEstado.value = curso;
    novoEstado.value = estado;
}

function cancelarAlteracaoEstado() {
    cursoParaAlterarEstado.value = null;
    novoEstado.value = null;
}

function confirmarAlteracaoEstado() {
    alterandoEstado.value = true;
    router.patch(`/cursos/${cursoParaAlterarEstado.value.id}/estado`, { estado: novoEstado.value }, {
        preserveScroll: true,
        onSuccess: () => toast.success('Estado do curso atualizado com sucesso.'),
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            alterandoEstado.value = false;
            cursoParaAlterarEstado.value = null;
            novoEstado.value = null;
        },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="d-flex justify-content-between align-items-center mb-6">
            <h1 class="fs-2 fw-bold">Cursos</h1>
            <button v-if="can('curso.criar')" class="btn btn-primary" @click="abrirCriacao">Novo Curso</button>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-100px">Código</th>
                            <th class="min-w-200px">Nome</th>
                            <th class="min-w-125px">Estado</th>
                            <th class="text-end min-w-125px">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="cursos.length === 0">
                            <td colspan="4" class="text-center text-muted py-6">Nenhum curso criado.</td>
                        </tr>
                        <tr v-for="curso in cursos" :key="curso.id">
                            <td>
                                <a :href="`/cursos/${curso.id}`" class="text-gray-800 text-hover-primary">{{ curso.codigo }}</a>
                            </td>
                            <td>{{ curso.nome }}</td>
                            <td>
                                <EstadoBadge :estado="curso.estado" :estado-descricao="curso.estado_descricao" />
                            </td>
                            <td class="text-end">
                                <a href="#" class="btn btn-light btn-active-light-primary btn-flex btn-center btn-sm" data-kt-menu-trigger="click" data-kt-menu-placement="bottom-end">
                                    Ações
                                    <i class="ki-duotone ki-down fs-5 ms-1"></i>
                                </a>
                                <div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-600 menu-state-bg-light-primary fw-semibold fs-7 w-200px py-4" data-kt-menu="true">
                                    <div v-if="can('curso.editar')" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="abrirEdicao(curso)">
                                            <AcaoIcone acao="editar" class="me-2" />
                                            Editar
                                        </a>
                                    </div>
                                    <div v-if="can('curso.editar') && curso.estado !== ESTADO.ATIVO" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="pedirAlteracaoEstado(curso, ESTADO.ATIVO)">
                                            <AcaoIcone acao="ativar" class="me-2" />
                                            Ativar
                                        </a>
                                    </div>
                                    <div v-if="can('curso.editar') && curso.estado === ESTADO.ATIVO" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="pedirAlteracaoEstado(curso, ESTADO.INATIVO)">
                                            <AcaoIcone acao="desativar" class="me-2" />
                                            Desativar
                                        </a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <CursoFormModal
            :show="modalAberto"
            :curso="cursoEmEdicao"
            :processing="processing"
            :errors="errors"
            @submit="guardar"
            @cancelar="fecharModal"
        />

        <ConfirmModal
            :show="!!cursoParaAlterarEstado"
            titulo="Alterar estado"
            :mensagem="`Alterar o estado do curso ${cursoParaAlterarEstado?.nome} para '${novoEstado === ESTADO.ATIVO ? 'Ativo' : 'Inativo'}'?`"
            texto-confirmar="Confirmar"
            :processando="alterandoEstado"
            @confirmar="confirmarAlteracaoEstado"
            @cancelar="cancelarAlteracaoEstado"
        />
    </div>
</template>
```

- [ ] **Step 5: Criar a página de consulta**

`Modules/Curso/resources/js/Pages/Show.vue`:

```vue
<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import BotaoVoltar from '@/Components/Shared/BotaoVoltar.vue';
import EstadoBadge from '../Components/Shared/EstadoBadge.vue';
import CursoFormModal from '../Components/CursoFormModal.vue';

const props = defineProps({
    curso: { type: Object, required: true },
});
defineOptions({ layout: AppLayout });

const modalAberto = ref(false);
const processing = ref(false);
const errors = ref({});

function abrirEdicao() {
    errors.value = {};
    modalAberto.value = true;
}

function fecharModal() {
    modalAberto.value = false;
}

function guardar(payload) {
    processing.value = true;
    errors.value = {};
    router.put(`/cursos/${props.curso.id}`, payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Curso atualizado com sucesso.');
            fecharModal();
        },
        onError: (erros) => {
            errors.value = erros;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <BotaoVoltar href="/cursos" class="mb-4" />

        <div class="d-flex justify-content-between align-items-center mb-6">
            <div>
                <h1 class="fs-2 fw-bold mb-1">{{ curso.nome }}</h1>
                <span class="text-muted">Código: {{ curso.codigo }}</span>
            </div>
            <button v-if="can('curso.editar')" class="btn btn-primary" @click="abrirEdicao">Editar</button>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="row mb-4">
                    <div class="col-md-3 fw-bold text-muted">Estado</div>
                    <div class="col-md-9">
                        <EstadoBadge :estado="curso.estado" :estado-descricao="curso.estado_descricao" />
                    </div>
                </div>
                <div class="row mb-4">
                    <div class="col-md-3 fw-bold text-muted">Descrição</div>
                    <div class="col-md-9">{{ curso.descricao ?? '—' }}</div>
                </div>
            </div>
        </div>

        <CursoFormModal
            :show="modalAberto"
            :curso="curso"
            :processing="processing"
            :errors="errors"
            @submit="guardar"
            @cancelar="fecharModal"
        />
    </div>
</template>
```

- [ ] **Step 6: Adicionar Curso ao menu Académico**

Editar `resources/js/Composables/useAcademicoMenu.js`:

```js
const seccoesAcademico = [
    {
        title: 'Académico',
        links: [
            { href: '#', label: 'Alunos' },
            { href: '#', label: 'Encarregados de Educação' },
            { href: '/cursos', label: 'Cursos', permissao: 'curso.ver' },
            { href: '/turmas', label: 'Turmas', permissao: 'turmas.ver' },
            { href: '#', label: 'Matrículas' },
            { href: '#', label: 'Transferências' },
            { href: '#', label: 'Histórico Escolar' },
            { href: '#', label: 'Ficha do Aluno' },
        ],
    },
];
```

- [ ] **Step 7: Build do frontend e verificação manual**

```bash
npm run build
```

Confirmar que compila sem erros. Depois, com o servidor a correr (`php artisan serve` + servidor de assets conforme o projecto usa), login como ADMIN_ESCOLA e verificar manualmente:
1. "Cursos" aparece no menu Académico.
2. Criar um curso — aparece na lista, código/nome únicos são validados.
3. Editar um curso.
4. Desactivar/reactivar um curso.
5. Consultar (Show) um curso.

- [ ] **Step 8: Commit**

```bash
git add Modules/Curso/resources resources/js/Composables/useAcademicoMenu.js
git commit -m "feat(curso): frontend (listagem, consulta, formulário) e entrada no menu Académico"
```

---

## Task 7: Migração `curso_id` em Turma + Model

**Files:**
- Create: `Modules/Turma/database/migrations/2026_09_09_090100_add_curso_id_to_turmas_table.php`
- Modify: `Modules/Turma/app/Models/Turma.php`
- Test: `Modules/Turma/tests/Feature/TurmaHttpTest.php` (ajuste de fixtures — feito nesta task para não deixar a suite vermelha)

**Interfaces:**
- Consumes: `Modules\Curso\Models\Curso` (Task 2).
- Produces: `turmas.curso_id` NOT NULL; `Turma::curso(): BelongsTo`.

- [ ] **Step 1: Escrever/ajustar o teste que expõe a exigência**

Adicionar a `Modules/Turma/tests/Feature/TurmaHttpTest.php` um helper privado e usá-lo em todas as fixtures de Turma. Primeiro, acrescentar o helper (logo a seguir a `criarAnoLectivo`):

```php
    private function criarCurso(Estabelecimento $estabelecimento): \Modules\Curso\Models\Curso
    {
        return \Modules\Curso\Models\Curso::create([
            'estabelecimento_id' => $estabelecimento->id,
            'codigo' => 'INF',
            'nome' => 'Informática',
        ]);
    }
```

Depois, actualizar **todas** as chamadas a `Turma::create([...])` e ao `route('turmas.store'/'turmas.update', ...)` no ficheiro para incluírem `curso_id`. Substituir cada ocorrência (há 7 no ficheiro actual):

1. `test_nao_elimina_turno_associado_a_turma`:
```php
        $curso = $this->criarCurso($estabelecimento);
        Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'turno_id' => $turno->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);
```

2. `test_nao_elimina_nivel_academico_associado_a_turma`:
```php
        $curso = $this->criarCurso($estabelecimento);
        Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);
```

3. `test_cria_turma_via_http_e_regista_autoria`:
```php
        $curso = $this->criarCurso($estabelecimento);

        $this->post(route('turmas.store'), [
            'ano_lectivo_id' => $anoLectivo->id,
            'nivel_academico_id' => $nivel->id,
            'curso_id' => $curso->id,
            'codigo' => 'T1',
            'nome' => 'Turma 1',
        ])->assertSessionHasNoErrors()->assertRedirect();
```

4. `test_altera_estado_da_turma_via_http_e_sincroniza_descricao`:
```php
        $curso = $this->criarCurso($estabelecimento);
        $turma = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);
```

5. `test_elimina_turma_via_http_com_soft_delete`:
```php
        $curso = $this->criarCurso($estabelecimento);
        $turma = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);
```

6. `test_show_da_turma_carrega_relacoes_e_salas_associadas`:
```php
        $curso = $this->criarCurso($estabelecimento);
        $turma = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);
```

7. `test_encerrar_sala_de_outra_turma_devolve_404`:
```php
        $curso = $this->criarCurso($estabelecimento);
        $turmaA = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'codigo' => 'TA', 'nome' => 'Turma A']);
        $turmaB = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'codigo' => 'TB', 'nome' => 'Turma B']);
```

(`test_adiciona_horario_ao_turno_via_http` não cria Turma — não precisa de alteração. `test_professor_recebe_403_em_todas_as_rotas_de_escrita` testa 403 antes de qualquer validação — não precisa de `curso_id`.)

Adicionar também o `use` no topo do ficheiro:

```php
use Modules\Curso\Models\Curso;
```

(o helper acima usa FQN inline só para ilustrar o diff; no ficheiro final, usar a classe importada, i.e. `Curso::create([...])` em vez de `\Modules\Curso\Models\Curso::create([...])`).

- [ ] **Step 2: Correr a suite do Turma e confirmar que falha**

```bash
php artisan test --filter=TurmaHttpTest
```

Esperado: FAIL — coluna `curso_id` não existe ainda / classe `Curso` referenciada mas tabela `cursos` sem a FK ligada (o teste vai falhar por `curso_id` não ser uma coluna reconhecida em `turmas`).

- [ ] **Step 3: Criar a migração**

`Modules/Turma/database/migrations/2026_09_09_090100_add_curso_id_to_turmas_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('turmas', function (Blueprint $table) {
            $table->foreignId('curso_id')->after('nivel_academico_id')
                ->constrained('cursos')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('turmas', function (Blueprint $table) {
            $table->dropForeign(['curso_id']);
            $table->dropColumn('curso_id');
        });
    }
};
```

- [ ] **Step 4: Actualizar o Model `Turma`**

Editar `Modules/Turma/app/Models/Turma.php`:

```php
<?php

namespace Modules\Turma\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Core\Traits\SincronizaEstadoDescricao;
use Modules\Curso\Models\Curso;
use Modules\Usuario\Models\User;

class Turma extends Model
{
    use SoftDeletes;
    use RegistaAutoria;
    use SincronizaEstadoDescricao;

    protected $table = 'turmas';

    protected $fillable = [
        'ano_lectivo_id',
        'nivel_academico_id',
        'curso_id',
        'codigo',
        'nome',
        'turno_id',
        'estado',
        'estado_descricao',
        'criado_por',
        'editado_por',
    ];

    protected $casts = [
        'estado' => 'integer',
    ];

    public function anoLectivo(): BelongsTo
    {
        return $this->belongsTo(AnoLectivo::class);
    }
    public function nivelAcademico(): BelongsTo
    {
        return $this->belongsTo(NivelAcademico::class);
    }

    public function curso(): BelongsTo
    {
        return $this->belongsTo(Curso::class);
    }

    public function turno(): BelongsTo
    {
        return $this->belongsTo(Turno::class);
    }

    public function turmaSalas(): HasMany
    {
        return $this->hasMany(TurmaSala::class);
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function editadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'editado_por');
    }
}
```

- [ ] **Step 5: Correr a suite do Turma e confirmar que ainda falha (esperado — DTO/Request/Action seguintes)**

```bash
php artisan test --filter=TurmaHttpTest
```

Esperado neste ponto: os testes de criação/actualização via HTTP (`store`/`update`) ainda falham, porque `CriarTurmaRequest`/`AtualizarTurmaRequest` não validam nem persistem `curso_id` — isso é resolvido na Task 8. Os testes de `Turma::create([...])` directo (que já passam `curso_id` manualmente à BD) devem passar já nesta task, porque a coluna e a relação já existem.

- [ ] **Step 6: Commit**

```bash
git add Modules/Turma/database/migrations Modules/Turma/app/Models/Turma.php Modules/Turma/tests
git commit -m "feat(turma): adiciona curso_id obrigatório e relação curso()"
```

---

## Task 8: Turma — DTO, Requests, Actions, Service e Curso nas opções de formulário

**Files:**
- Modify: `Modules/Turma/app/DTO/TurmaDTO.php`
- Modify: `Modules/Turma/app/Http/Requests/{CriarTurmaRequest,AtualizarTurmaRequest}.php`
- Modify: `Modules/Turma/app/Actions/{CriarTurmaAction,AtualizarTurmaAction}.php`
- Modify: `Modules/Turma/app/Services/TurmaConsultaService.php`
- Test: `Modules/Turma/tests/Feature/TurmaHttpTest.php` (novos casos)

**Interfaces:**
- Produces: `TurmaConsultaService::opcoesFormulario()` inclui `'cursos' => Collection<{id,nome}>`.

- [ ] **Step 1: Acrescentar os novos casos de teste**

Adicionar a `Modules/Turma/tests/Feature/TurmaHttpTest.php`:

```php
    public function test_criar_turma_sem_curso_id_falha_com_erro_de_validacao(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 1]);

        $this->post(route('turmas.store'), [
            'ano_lectivo_id' => $anoLectivo->id,
            'nivel_academico_id' => $nivel->id,
            'codigo' => 'T1',
            'nome' => 'Turma 1',
        ])->assertSessionHasErrors('curso_id');
    }

    public function test_editar_turma_sem_curso_id_falha_com_erro_de_validacao(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 1]);
        $curso = $this->criarCurso($estabelecimento);
        $turma = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);

        $this->put(route('turmas.update', $turma), [
            'nivel_academico_id' => $nivel->id,
            'codigo' => 'T1',
            'nome' => 'Turma 1',
        ])->assertSessionHasErrors('curso_id');
    }

    public function test_index_da_turma_expoe_cursos_nas_opcoes_de_formulario(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $this->criarAnoLectivo($estabelecimento);
        NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 1]);
        Turno::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => 'Manhã']);
        $this->criarCurso($estabelecimento);

        $this->get(route('turmas.index'))->assertInertia(fn (Assert $page) => $page
            ->component('Turma/Turmas/Index')
            ->has('cursos', 1)
        );
    }
```

- [ ] **Step 2: Correr a suite e confirmar que falha**

```bash
php artisan test --filter=TurmaHttpTest
```

Esperado: FAIL — `curso_id` ainda não é validado nem persistido; `cursos` ainda não é devolvido pelo `index`.

- [ ] **Step 3: Actualizar `TurmaDTO`**

`Modules/Turma/app/DTO/TurmaDTO.php`:

```php
<?php

namespace Modules\Turma\DTO;

use Modules\Turma\Http\Requests\AtualizarTurmaRequest;
use Modules\Turma\Http\Requests\CriarTurmaRequest;

class TurmaDTO
{
    public function __construct(
        public string $codigo,
        public string $nome,
        public ?int $ano_lectivo_id = null,
        public ?int $nivel_academico_id = null,
        public ?int $curso_id = null,
        public ?int $turno_id = null,
    ) {
    }

    public static function fromCriarRequest(
        CriarTurmaRequest $request
    ): self {
        $dados = $request->validated();

        return new self(
            codigo: $dados['codigo'],
            nome: $dados['nome'],
            ano_lectivo_id: (int) $dados['ano_lectivo_id'],
            nivel_academico_id: (int) $dados['nivel_academico_id'],
            curso_id: (int) $dados['curso_id'],
            turno_id: isset($dados['turno_id'])
            ? (int) $dados['turno_id']
            : null,
        );
    }

    public static function fromAtualizarRequest(
        AtualizarTurmaRequest $request
    ): self {
        $dados = $request->validated();

        return new self(
            codigo: $dados['codigo'],
            nome: $dados['nome'],
            nivel_academico_id: (int) $dados['nivel_academico_id'],
            curso_id: (int) $dados['curso_id'],
            turno_id: isset($dados['turno_id'])
            ? (int) $dados['turno_id']
            : null,
        );
    }
}
```

- [ ] **Step 4: Actualizar `CriarTurmaRequest` e `AtualizarTurmaRequest`**

`Modules/Turma/app/Http/Requests/CriarTurmaRequest.php`, no método `rules()`, acrescentar:

```php
            'curso_id' => 'required|integer|exists:cursos,id',
```

(logo a seguir a `nivel_academico_id`) e em `messages()`:

```php
            'curso_id.required' => 'O curso é obrigatório.',
            'curso_id.exists' => 'O curso indicado não existe.',
```

`Modules/Turma/app/Http/Requests/AtualizarTurmaRequest.php`, mesmas adições em `rules()` e `messages()`.

- [ ] **Step 5: Actualizar `CriarTurmaAction` e `AtualizarTurmaAction`**

`Modules/Turma/app/Actions/CriarTurmaAction.php`:

```php
<?php

namespace Modules\Turma\Actions;

use Modules\Turma\DTO\TurmaDTO;
use Modules\Turma\Models\Turma;

class CriarTurmaAction
{
    public function executar(TurmaDTO $dto): Turma
    {
        return Turma::create([
            'ano_lectivo_id' => $dto->ano_lectivo_id,
            'nivel_academico_id' => $dto->nivel_academico_id,
            'curso_id' => $dto->curso_id,
            'codigo' => $dto->codigo,
            'nome' => $dto->nome,
            'turno_id' => $dto->turno_id,
        ]);
    }
}
```

`Modules/Turma/app/Actions/AtualizarTurmaAction.php`:

```php
<?php

namespace Modules\Turma\Actions;

use Modules\Turma\DTO\TurmaDTO;
use Modules\Turma\Models\Turma;

class AtualizarTurmaAction
{
    public function executar(
        Turma $turma,
        TurmaDTO $dto
    ): Turma {
        $turma->fill([
            'nivel_academico_id' => $dto->nivel_academico_id,
            'curso_id' => $dto->curso_id,
            'codigo' => $dto->codigo,
            'nome' => $dto->nome,
            'turno_id' => $dto->turno_id,
        ]);

        $turma->save();

        return $turma->fresh();
    }
}
```

- [ ] **Step 6: Actualizar `TurmaConsultaService::opcoesFormulario()`**

`Modules/Turma/app/Services/TurmaConsultaService.php`:

```php
<?php

namespace Modules\Turma\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Infraestrutura\Models\Sala;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Turma\Models\Turno;

class TurmaConsultaService
{
    public function listar(): Collection
    {
        return Turma::orderBy('codigo')->get();
    }

    public function opcoesFormulario(): array
    {
        $estabelecimentoId = Estabelecimento::current()?->id;

        return [
            'anoLectivos' => AnoLectivo::where('estabelecimento_id', $estabelecimentoId)->orderByDesc('nome')->get(['id', 'nome']),
            'niveisAcademicos' => NivelAcademico::where('estabelecimento_id', $estabelecimentoId)->orderBy('ordem')->get(['id', 'nome']),
            'cursos' => Curso::where('estabelecimento_id', $estabelecimentoId)->orderBy('nome')->get(['id', 'nome']),
            'turnos' => Turno::where('estabelecimento_id', $estabelecimentoId)->orderBy('nome')->get(['id', 'nome']),
        ];
    }

    public function salasDisponiveis(): Collection
    {
        return Sala::orderBy('codigo')->get(['id', 'codigo', 'nome']);
    }
}
```

- [ ] **Step 7: Correr toda a suite do Turma e confirmar que passa**

```bash
php artisan test --filter=TurmaHttpTest
```

Esperado: PASS em todos os testes (incluindo os 3 novos e os 7 ajustados na Task 7).

- [ ] **Step 8: Correr as suites de Curso e Turma juntas para garantir que nada regrediu**

```bash
php artisan test --filter="Curso|Turma"
```

Esperado: PASS.

- [ ] **Step 9: Commit**

```bash
git add Modules/Turma/app/DTO Modules/Turma/app/Http/Requests Modules/Turma/app/Actions Modules/Turma/app/Services Modules/Turma/tests
git commit -m "feat(turma): curso_id obrigatório em criar/editar turma e nas opções de formulário"
```

---

## Task 9: Frontend da Turma — seleccionar Curso

**Files:**
- Modify: `Modules/Turma/resources/js/Components/Turma/TurmaFormModal.vue`
- Modify: `Modules/Turma/resources/js/Pages/Turmas/Index.vue`
- Modify: `Modules/Turma/resources/js/Pages/Turmas/Show.vue`

**Interfaces:**
- Consumes: prop `cursos` (Task 8, via `opcoesFormulario()`), relação `turma.curso` carregada no `show()` do `TurmaController` (já inclui `load(['anoLectivo', 'nivelAcademico', 'turno', 'turmaSalas.sala'])` — acrescentar `curso`).

- [ ] **Step 1: Carregar a relação `curso` no `show()` do Controller**

Editar `Modules/Turma/app/Http/Controllers/TurmaController.php`, método `show`:

```php
    public function show(Turma $turma)
    {
        $this->authorize('turmas.ver');

        $turma->load(['anoLectivo', 'nivelAcademico', 'curso', 'turno', 'turmaSalas.sala']);

        return Inertia::render('Turma/Turmas/Show', array_merge([
            'turma' => $turma,
            'salas' => $this->consulta->salasDisponiveis(),
        ], $this->consulta->opcoesFormulario()));
    }
```

- [ ] **Step 2: Adicionar o select de Curso ao `TurmaFormModal.vue`**

Editar `Modules/Turma/resources/js/Components/Turma/TurmaFormModal.vue`:

```vue
<script setup>
import { reactive, watch } from 'vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import { ESTADO } from '../../Models/Estado';

const props = defineProps({
    show: { type: Boolean, default: false },
    turma: { type: Object, default: null },
    anoLectivos: { type: Array, required: true },
    niveisAcademicos: { type: Array, required: true },
    cursos: { type: Array, required: true },
    turnos: { type: Array, required: true },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const ESTADO_OPCOES = [
    { value: ESTADO.ATIVO, label: 'Ativo' },
    { value: ESTADO.INATIVO, label: 'Inativo' },
];

const opcoesAnoLectivo = () => props.anoLectivos.map((a) => ({ value: a.id, label: a.nome }));
const opcoesNivelAcademico = () => props.niveisAcademicos.map((n) => ({ value: n.id, label: n.nome }));
const opcoesCurso = () => props.cursos.map((c) => ({ value: c.id, label: c.nome }));
const opcoesTurno = () => [{ value: '', label: 'Sem turno' }, ...props.turnos.map((t) => ({ value: t.id, label: t.nome }))];

const form = reactive({
    ano_lectivo_id: '',
    nivel_academico_id: '',
    curso_id: '',
    codigo: '',
    nome: '',
    turno_id: '',
    estado: ESTADO.ATIVO,
});

watch(() => props.show, (show) => {
    if (!show) return;
    form.ano_lectivo_id = props.turma?.ano_lectivo_id ?? '';
    form.nivel_academico_id = props.turma?.nivel_academico_id ?? '';
    form.curso_id = props.turma?.curso_id ?? '';
    form.codigo = props.turma?.codigo ?? '';
    form.nome = props.turma?.nome ?? '';
    form.turno_id = props.turma?.turno_id ?? '';
    form.estado = props.turma?.estado ?? ESTADO.ATIVO;
});

function submeter() {
    const payload = { ...form, turno_id: form.turno_id === '' ? null : form.turno_id };
    if (props.turma) delete payload.ano_lectivo_id;
    else delete payload.estado;
    emit('submit', payload);
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-5">{{ turma ? 'Editar Turma' : 'Nova Turma' }}</h3>
                <form @submit.prevent="submeter">
                    <div v-if="!turma" class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Ano Lectivo</label>
                        <SelectSolid v-model="form.ano_lectivo_id" :options="opcoesAnoLectivo()" placeholder="Selecione o ano lectivo" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.ano_lectivo_id">{{ errors.ano_lectivo_id }}</div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Nível Académico</label>
                            <SelectSolid v-model="form.nivel_academico_id" :options="opcoesNivelAcademico()" placeholder="Selecione o nível académico" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.nivel_academico_id">{{ errors.nivel_academico_id }}</div>
                        </div>
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Curso</label>
                            <SelectSolid v-model="form.curso_id" :options="opcoesCurso()" placeholder="Selecione o curso" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.curso_id">{{ errors.curso_id }}</div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Código</label>
                            <input v-model="form.codigo" type="text" class="form-control form-control-solid" placeholder="ex: T1" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.codigo">{{ errors.codigo }}</div>
                        </div>
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Nome</label>
                            <input v-model="form.nome" type="text" class="form-control form-control-solid" placeholder="ex: Turma 1" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.nome">{{ errors.nome }}</div>
                        </div>
                    </div>

                    <div class="fv-row mb-7">
                        <label class="fw-semibold fs-6 mb-2">Turno</label>
                        <SelectSolid v-model="form.turno_id" :options="opcoesTurno()" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.turno_id">{{ errors.turno_id }}</div>
                    </div>

                    <div v-if="turma" class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Estado</label>
                        <SelectSolid v-model="form.estado" :options="ESTADO_OPCOES" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.estado">{{ errors.estado }}</div>
                    </div>

                    <div class="text-end">
                        <button type="button" class="btn btn-light-danger me-2" :disabled="processing" @click="emit('cancelar')">
                            Cancelar
                        </button>
                        <button type="submit" class="btn btn-primary" :disabled="processing">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 3: Mostrar o Curso na tabela de `Pages/Turmas/Index.vue`**

Localizar a `<thead>` da tabela em `Modules/Turma/resources/js/Pages/Turmas/Index.vue` e acrescentar uma coluna "Curso" a seguir a "Nível Académico" (ou coluna equivalente já existente), e na `<tbody>` a célula correspondente `{{ turma.curso?.nome }}` (o objecto `curso` chega via relação eager-loaded se o `listar()` dos dados também carregar `curso`; caso a listagem actual não faça eager load, usar `turma.curso_id` só como fallback textual não é aceitável — em vez disso, adicionar `with(['curso'])` a `TurmaConsultaService::listar()`:

```php
    public function listar(): Collection
    {
        return Turma::with(['nivelAcademico', 'curso', 'turno'])->orderBy('codigo')->get();
    }
```

Depois, na tabela, mostrar `{{ turma.curso.nome }}`.

- [ ] **Step 4: Mostrar o Curso em `Pages/Turmas/Show.vue`**

Adicionar uma linha "Curso" na secção de detalhes da turma (mesmo padrão visual usado para Nível Académico/Turno), usando `turma.curso.nome` (já carregado via `load(['curso', ...])` no Step 1).

- [ ] **Step 5: Build e verificação manual**

```bash
npm run build
```

Com o servidor a correr, login como ADMIN_ESCOLA:
1. Criar uma Turma sem escolher Curso — deve bloquear com erro de validação.
2. Criar uma Turma escolhendo um Curso — deve gravar e mostrar o curso na listagem e na consulta.
3. Editar uma Turma existente e trocar o Curso.

- [ ] **Step 6: Commit**

```bash
git add Modules/Turma/app/Http/Controllers/TurmaController.php Modules/Turma/app/Services/TurmaConsultaService.php Modules/Turma/resources/js
git commit -m "feat(turma): frontend passa a exigir e mostrar o Curso da turma"
```

---

## Task 10: Verificação final

**Files:** nenhum (apenas comandos de verificação).

- [ ] **Step 1: Migrar tudo do zero**

```bash
php artisan module:migrate-fresh
```

Esperado: todas as migrações (incluindo `create_cursos_table` e `add_curso_id_to_turmas_table`) correm sem erro, na ordem correcta (Curso antes da alteração em Turma, por causa da FK).

- [ ] **Step 2: Seed completo**

```bash
php artisan module:seed Permissao
```

Esperado: sem erros; `Modulo::count()` fica em 14; ADMIN_ESCOLA tem `curso.ver`/`curso.criar`/`curso.editar`.

- [ ] **Step 3: Suite completa**

```bash
php artisan test
```

Esperado: 100% verde — nenhuma regressão em Permissao, Turma, Infraestrutura, AnoLectivo, etc.

- [ ] **Step 4: Checklist manual final (com servidor a correr)**

1. Login ADMIN_ESCOLA → menu Académico → "Cursos" visível.
2. CRUD completo de Curso (criar, listar, consultar, editar, activar/desactivar) — sem opção de eliminar em lado nenhum da UI.
3. Tentar criar Curso com código/nome repetidos no mesmo estabelecimento → erro amigável.
4. Login PROFESSOR → "Cursos" não aparece no menu; acesso directo a `/cursos` devolve 403.
5. Criar uma Turma → Curso é obrigatório no formulário, sem opção "Sem curso".
6. Turma criada mostra o Curso na listagem e na consulta.

- [ ] **Step 5: Reportar resultado**

Não há commit nesta task — é só verificação. Se algo falhar, voltar à task correspondente, corrigir, e só então prosseguir.
