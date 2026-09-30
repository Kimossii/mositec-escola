# Documentos de Pessoa Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `TipoDocumento` (configurable document types) and `DocumentoPessoa` (documents/attachments) linked to `DadosPessoa`, with private-disk upload/download, estado-based history, and permission-gated HTTP endpoints — plus the `DadosPessoal` → `DadosPessoa` rename the spec requires first.

**Architecture:** Everything lives in `Modules/Usuario` (same module as `DadosPessoa`), following the project's established `Request → Service → DTO → Action → Model` layering (no Repository pattern). `TipoDocumento` is a plain lookup table (no PHP enum mirror). `DocumentoPessoa` reuses Core traits (`RegistaAutoria`, `SincronizaEstadoDescricao`, `SoftDeletes`) and the existing `Modulo`/`Acao` permission system (`Gate::before` dynamic resolution — no Policies).

**Tech Stack:** Laravel (nwidart/laravel-modules), PHPUnit (`php artisan test`), SQLite in-memory for tests, Inertia (existing app shell — no new Vue in this plan).

**Spec:** `docs/superpowers/specs/2026-09-16-documentos-pessoa-design.md`

## Global Constraints

- **Never run `git commit`.** Every task ends with `git add` (stage only) — never commit, per this project's standing rule (confirmed 4 times). If a sub-skill's own mechanics want to commit per task, stage instead and move on.
- Rename `DadosPessoal` → `DadosPessoa` is Task 1, mechanical only — no behavior change, no new methods added in that task.
- `estado = INATIVO` means "superseded by a newer document of the same type, still preserved." `deleted_at` means "the record itself was deleted." The two are independent — never conflate them in any Action.
- Index `(dados_pessoa_id, tipo_documento_id)` on `documentos_pessoas` is **not unique** — history of same-type documents is required.
- Files are stored on a **private** disk (`documentos`), never `public`. No route ever exposes a public URL — only the authenticated/authorized `download` endpoint.
- `php.ini` on this machine caps `upload_max_filesize` at `2M` — the `ficheiro` validation rule uses `max:2048` (KB) to match. Do not raise this validation limit without also raising `php.ini` (out of scope for this plan).
- No Vue/Inertia page is built in this plan. The controller's `index` returns JSON (there is no page yet to render it) and write endpoints redirect back, matching the rest of the codebase's Inertia-flash convention. Frontend integration into the Aluno form is explicitly deferred (see spec).
- Every module namespace/path follows the existing `Modules\Usuario\...` convention (PSR-4 root at `Modules/Usuario/app/`).

---

## Task 1: Rename `DadosPessoal` → `DadosPessoa`

**Files:**
- Rename: `Modules/Usuario/app/Models/DadosPessoal.php` → `Modules/Usuario/app/Models/DadosPessoa.php`
- Modify (whole-word `DadosPessoal` → `DadosPessoa`, confirmed by `grep -rlE "\bDadosPessoal\b"`, 25 other files):
  - `Modules/AnoLectivo/tests/Feature/AnoLectivoHttpTest.php`
  - `Modules/AnoLectivo/tests/Feature/EliminarAnoLectivoActionTest.php`
  - `Modules/AnoLectivo/tests/Feature/AlterarEstadoAnoLectivoActionTest.php`
  - `Modules/Aluno/tests/Feature/AtualizarAlunoActionTest.php`
  - `Modules/Aluno/tests/Feature/AlunoHttpTest.php`
  - `Modules/Aluno/tests/Feature/CriarAlunoActionTest.php`
  - `Modules/Aluno/tests/Feature/AlunoConsultaServiceTest.php`
  - `Modules/Aluno/tests/Feature/AlterarEstadoAlunoActionTest.php`
  - `Modules/Aluno/app/Models/Aluno.php`
  - `Modules/Aluno/app/Actions/CriarAlunoAction.php`
  - `Modules/Aluno/tests/Feature/AlunoUsuarioIntegracaoTest.php`
  - `Modules/Aluno/tests/Feature/CriarAlunoRequestTest.php`
  - `Modules/Aluno/tests/Feature/AlunoModelTest.php`
  - `Modules/Usuario/app/Models/User.php`
  - `Modules/Usuario/tests/Feature/DadosPessoaIdUnicoTest.php`
  - `Modules/Matricula/tests/Feature/EliminarInscricaoDisciplinaActionTest.php`
  - `Modules/Matricula/tests/Feature/InscreverDisciplinasAutomaticamenteActionTest.php`
  - `Modules/Matricula/tests/Feature/InscricaoDisciplinaModelTest.php`
  - `Modules/Matricula/tests/Feature/MatriculaHttpTest.php`
  - `Modules/Matricula/tests/Feature/GestaoInscricaoDisciplinaServiceTest.php`
  - `Modules/Matricula/tests/Feature/CriarInscricaoDisciplinaActionTest.php`
  - `Modules/Matricula/tests/Feature/InscricaoDisciplinaHttpTest.php`
  - `Modules/Matricula/tests/Feature/MatriculaConsultaServiceInscricoesTest.php`
  - `Modules/Matricula/tests/Feature/MatriculaActionTest.php`
  - `Modules/Matricula/tests/Feature/AlterarEstadoInscricaoDisciplinaActionTest.php`
  - `Modules/Turma/tests/Feature/TurmaHttpTest.php`
- **Do NOT touch** `Modules/Usuario/app/Http/Requests/CriarDadosPessoalRequest.php` — it only matched a plain-substring grep because its class name *contains* the string "DadosPessoal" (`CriarDadosPessoalRequest`); it has no reference to the model class itself. Confirmed via word-boundary grep, which correctly excludes it.
- **Do NOT touch** anything under `.claude/worktrees/infraestrutura-salas/` — belongs to unrelated in-progress work.

**Interfaces:**
- Produces: `Modules\Usuario\Models\DadosPessoa` (same table `dados_pessoas`, same fillable/casts/constants as before, just renamed). All later tasks reference this class.

- [ ] **Step 1: Confirm the exact file list and rename the model file**

```bash
cd /home/eluckimossi/code/mositec-escola
grep -rlE "\bDadosPessoal\b" --include="*.php" Modules/ app/ database/
git mv Modules/Usuario/app/Models/DadosPessoal.php Modules/Usuario/app/Models/DadosPessoa.php
```

Expected: the grep output matches the 26-file list above (25 files + the model itself).

- [ ] **Step 2: Rename the class declaration inside the renamed file**

In `Modules/Usuario/app/Models/DadosPessoa.php`, change:
```php
class DadosPessoal extends Model
```
to:
```php
class DadosPessoa extends Model
```
Leave everything else in the file untouched (fillable, casts, `SEXO_*`/`TIPO_*` constants, the commented-out factory `use` line).

- [ ] **Step 3: Replace all remaining whole-word references**

```bash
cd /home/eluckimossi/code/mositec-escola
grep -rlE "\bDadosPessoal\b" --include="*.php" Modules/ app/ database/ | \
  xargs sed -i -E 's/\bDadosPessoal\b/DadosPessoa/g'
```

- [ ] **Step 4: Verify no reference to the old name remains outside the excluded files**

```bash
grep -rlE "\bDadosPessoal\b" --include="*.php" Modules/ app/ database/
```

Expected: no output (the `Criar` prefix in `CriarDadosPessoalRequest` is a substring match only with a plain `grep -l DadosPessoal`, not with the word-boundary pattern — confirm this command specifically returns empty).

- [ ] **Step 5: Run the full test suite**

```bash
php artisan test
```

Expected: PASS, same count of tests as before the rename (pure rename, no behavior change).

- [ ] **Step 6: Stage the changes**

```bash
git add Modules/Usuario/app/Models/DadosPessoa.php Modules/AnoLectivo Modules/Aluno Modules/Usuario Modules/Matricula Modules/Turma
```

Do not commit.

---

## Task 2: Add `Modulo::DOCUMENTO_PESSOA` permission module

**Files:**
- Modify: `Modules/Permissao/app/Enums/Modulo.php`
- Modify: `Modules/Permissao/database/seeders/ModuloSeeder.php`
- Modify: `Modules/Permissao/database/seeders/RolePermissaoSeeder.php`
- Modify: `Modules/Permissao/tests/Unit/ModuloEnumTest.php`

**Interfaces:**
- Produces: `Modulo::DOCUMENTO_PESSOA` (int `15`, slug `documento-pessoa`, label `Documento Pessoa`), granted to `Perfil::ADMIN_ESCOLA` with actions `ver, criar, editar, eliminar`. Later tasks' routes use ability strings `documento-pessoa.ver` / `.criar` / `.editar` / `.eliminar`.

- [ ] **Step 1: Write the failing test**

Add to `Modules/Permissao/tests/Unit/ModuloEnumTest.php` (inside the existing class, alongside `test_plano_curricular_slug_e_label`):

```php
public function test_documento_pessoa_slug_e_label(): void
{
    $this->assertSame('documento-pessoa', Modulo::DOCUMENTO_PESSOA->slug());
    $this->assertSame('Documento Pessoa', Modulo::DOCUMENTO_PESSOA->label());
    $this->assertSame(Modulo::DOCUMENTO_PESSOA, Modulo::fromSlug('documento-pessoa'));
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --filter=test_documento_pessoa_slug_e_label
```

Expected: FAIL (fatal error — `DOCUMENTO_PESSOA` is not a case of `Modulo`).

- [ ] **Step 3: Add the enum case and RolePermissao grant**

In `Modules/Permissao/app/Enums/Modulo.php`, add to each part:
```php
// in the case list, after PLANO_CURRICULAR:
case DOCUMENTO_PESSOA = 15;

// in slug(), after self::PLANO_CURRICULAR => 'plano-curricular',
self::DOCUMENTO_PESSOA => 'documento-pessoa',

// in label(), after self::PLANO_CURRICULAR => 'Plano Curricular',
self::DOCUMENTO_PESSOA => 'Documento Pessoa',
```

In `Modules/Permissao/database/seeders/ModuloSeeder.php`, add to the `$modulos` array:
```php
['nome' => 15, 'descricao' => 'Documento Pessoa'],
```

In `Modules/Permissao/database/seeders/RolePermissaoSeeder.php`, add to the `Perfil::ADMIN_ESCOLA->value` array in `$mapaPorRole`:
```php
Modulo::DOCUMENTO_PESSOA->value => ['ver', 'criar', 'editar', 'eliminar'],
```

- [ ] **Step 4: Run test to verify it passes**

```bash
php artisan test --filter=ModuloEnumTest
```

Expected: PASS (all `ModuloEnumTest` tests, including the round-trip test over all cases).

- [ ] **Step 5: Stage the changes**

```bash
git add Modules/Permissao/app/Enums/Modulo.php Modules/Permissao/database/seeders/ModuloSeeder.php Modules/Permissao/database/seeders/RolePermissaoSeeder.php Modules/Permissao/tests/Unit/ModuloEnumTest.php
```

---

## Task 3: `tipos_documentos` table and `TipoDocumento` model

**Files:**
- Create: `Modules/Usuario/database/migrations/2026_09_16_150000_create_tipos_documentos_table.php`
- Create: `Modules/Usuario/app/Models/TipoDocumento.php`
- Test: `Modules/Usuario/tests/Feature/TipoDocumentoModelTest.php`

**Interfaces:**
- Produces: `Modules\Usuario\Models\TipoDocumento` (table `tipos_documentos`: `id, nome, slug (unique), estado, estado_descricao, timestamps`), used by Task 4 (seeder) and Task 5 (`DocumentoPessoa::tipoDocumento()`).

- [ ] **Step 1: Write the failing test**

Create `Modules/Usuario/tests/Feature/TipoDocumentoModelTest.php`:

```php
<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Modules\Usuario\Models\TipoDocumento;
use Tests\TestCase;

class TipoDocumentoModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_estado_descricao_e_sincronizada_ao_criar(): void
    {
        $tipo = TipoDocumento::create([
            'nome' => 'Bilhete de Identidade',
            'slug' => 'bi',
        ]);

        $this->assertSame(1, $tipo->estado);
        $this->assertSame('Ativo', $tipo->estado_descricao);
    }

    public function test_estado_descricao_e_sincronizada_ao_desactivar(): void
    {
        $tipo = TipoDocumento::create(['nome' => 'Outro', 'slug' => 'outro']);

        $tipo->update(['estado' => 0]);

        $this->assertSame('Inativo', $tipo->fresh()->estado_descricao);
    }

    public function test_slug_e_unico(): void
    {
        TipoDocumento::create(['nome' => 'Bilhete de Identidade', 'slug' => 'bi']);

        $this->expectException(QueryException::class);

        TipoDocumento::create(['nome' => 'Outro BI', 'slug' => 'bi']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --filter=TipoDocumentoModelTest
```

Expected: FAIL (class `TipoDocumento` not found / table does not exist).

- [ ] **Step 3: Create the migration**

Create `Modules/Usuario/database/migrations/2026_09_16_150000_create_tipos_documentos_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_documentos', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('slug')->unique();
            $table->unsignedTinyInteger('estado')->default(1);
            $table->string('estado_descricao')->default('Ativo');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipos_documentos');
    }
};
```

- [ ] **Step 4: Create the model**

Create `Modules/Usuario/app/Models/TipoDocumento.php`:

```php
<?php

namespace Modules\Usuario\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Traits\SincronizaEstadoDescricao;

class TipoDocumento extends Model
{
    use HasFactory, SincronizaEstadoDescricao;

    protected $table = 'tipos_documentos';

    protected $fillable = [
        'nome',
        'slug',
        'estado',
        'estado_descricao',
    ];

    protected $attributes = [
        'estado' => 1,
    ];

    protected $casts = [
        'estado' => 'integer',
    ];

    public function documentos(): HasMany
    {
        return $this->hasMany(DocumentoPessoa::class, 'tipo_documento_id');
    }
}
```

(`DocumentoPessoa` does not exist yet — that's fine, PHP resolves the return-type-hinted relation call lazily; this method is not invoked until Task 5 exists.)

- [ ] **Step 5: Run test to verify it passes**

```bash
php artisan test --filter=TipoDocumentoModelTest
```

Expected: PASS.

- [ ] **Step 6: Stage the changes**

```bash
git add Modules/Usuario/database/migrations/2026_09_16_150000_create_tipos_documentos_table.php Modules/Usuario/app/Models/TipoDocumento.php Modules/Usuario/tests/Feature/TipoDocumentoModelTest.php
```

---

## Task 4: `TipoDocumentoSeeder`

**Files:**
- Create: `Modules/Usuario/database/seeders/TipoDocumentoSeeder.php`
- Test: `Modules/Usuario/tests/Feature/TipoDocumentoSeederTest.php`

**Interfaces:**
- Produces: `Modules\Usuario\Database\Seeders\TipoDocumentoSeeder` — called directly via `$this->seed(TipoDocumentoSeeder::class)` in every later test that needs seeded types (no root `DatabaseSeeder` wiring, matching how `PermissaoDatabaseSeeder` is called directly in Feature tests).

- [ ] **Step 1: Write the failing test**

Create `Modules/Usuario/tests/Feature/TipoDocumentoSeederTest.php`:

```php
<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Usuario\Database\Seeders\TipoDocumentoSeeder;
use Modules\Usuario\Models\TipoDocumento;
use Tests\TestCase;

class TipoDocumentoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_semeia_os_seis_tipos_iniciais(): void
    {
        $this->seed(TipoDocumentoSeeder::class);

        $this->assertSame(6, TipoDocumento::count());
        foreach (['bi', 'passaporte', 'certidao_nascimento', 'certificado', 'declaracao', 'outro'] as $slug) {
            $this->assertTrue(TipoDocumento::where('slug', $slug)->exists(), "slug '{$slug}' não foi semeado");
        }
    }

    public function test_e_idempotente(): void
    {
        $this->seed(TipoDocumentoSeeder::class);
        $this->seed(TipoDocumentoSeeder::class);

        $this->assertSame(6, TipoDocumento::count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --filter=TipoDocumentoSeederTest
```

Expected: FAIL (class `TipoDocumentoSeeder` not found).

- [ ] **Step 3: Create the seeder**

Create `Modules/Usuario/database/seeders/TipoDocumentoSeeder.php`:

```php
<?php

namespace Modules\Usuario\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Usuario\Models\TipoDocumento;

class TipoDocumentoSeeder extends Seeder
{
    public function run(): void
    {
        $tipos = [
            ['nome' => 'Bilhete de Identidade', 'slug' => 'bi'],
            ['nome' => 'Passaporte', 'slug' => 'passaporte'],
            ['nome' => 'Certidão de Nascimento', 'slug' => 'certidao_nascimento'],
            ['nome' => 'Certificado', 'slug' => 'certificado'],
            ['nome' => 'Declaração', 'slug' => 'declaracao'],
            ['nome' => 'Outro', 'slug' => 'outro'],
        ];

        foreach ($tipos as $tipo) {
            TipoDocumento::updateOrCreate(
                ['slug' => $tipo['slug']],
                ['nome' => $tipo['nome']],
            );
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
php artisan test --filter=TipoDocumentoSeederTest
```

Expected: PASS.

- [ ] **Step 5: Stage the changes**

```bash
git add Modules/Usuario/database/seeders/TipoDocumentoSeeder.php Modules/Usuario/tests/Feature/TipoDocumentoSeederTest.php
```

---

## Task 5: `documentos_pessoas` table, `DocumentoPessoa` model, and the `DadosPessoa`/`TipoDocumento` relations

**Files:**
- Create: `Modules/Usuario/database/migrations/2026_09_16_150100_create_documentos_pessoas_table.php`
- Create: `Modules/Usuario/app/Models/DocumentoPessoa.php`
- Modify: `Modules/Usuario/app/Models/DadosPessoa.php` (add `documentos(): HasMany`)
- Test: `Modules/Usuario/tests/Feature/DocumentoPessoaModelTest.php`

**Interfaces:**
- Consumes: `Modules\Usuario\Models\DadosPessoa` (Task 1), `Modules\Usuario\Models\TipoDocumento` (Task 3), `Modules\Core\Traits\RegistaAutoria`, `Modules\Core\Traits\SincronizaEstadoDescricao` (both `Modules\Core\Traits`).
- Produces: `Modules\Usuario\Models\DocumentoPessoa` with relations `dadosPessoa(): BelongsTo`, `tipoDocumento(): BelongsTo`, `criadoPor(): BelongsTo`, `editadoPor(): BelongsTo`; `DadosPessoa::documentos(): HasMany`; `TipoDocumento::documentos(): HasMany` (already added in Task 3). Later tasks (6-11) all depend on this model.

- [ ] **Step 1: Write the failing test**

Create `Modules/Usuario/tests/Feature/DocumentoPessoaModelTest.php`:

```php
<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Usuario\Database\Seeders\TipoDocumentoSeeder;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\DocumentoPessoa;
use Modules\Usuario\Models\TipoDocumento;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class DocumentoPessoaModelTest extends TestCase
{
    use RefreshDatabase;

    private function criarPessoa(): DadosPessoa
    {
        return DadosPessoa::create([
            'nome_completo' => 'Ana Silva',
            'numero_identificacao' => 'BI0001',
            'tipo_pessoa' => DadosPessoa::TIPO_ALUNO,
        ]);
    }

    public function test_pertence_a_dados_pessoa_e_tipo_documento(): void
    {
        $this->seed(TipoDocumentoSeeder::class);
        $pessoa = $this->criarPessoa();
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();

        $documento = DocumentoPessoa::create([
            'dados_pessoa_id' => $pessoa->id,
            'tipo_documento_id' => $tipo->id,
            'nome_original' => 'bi-frente.pdf',
            'caminho' => 'documentos-pessoas/1/bi-frente.pdf',
            'mime_type' => 'application/pdf',
            'tamanho' => 1024,
        ]);

        $this->assertTrue($pessoa->documentos->contains($documento));
        $this->assertTrue($tipo->documentos->contains($documento));
        $this->assertSame($pessoa->id, $documento->dadosPessoa->id);
        $this->assertSame($tipo->id, $documento->tipoDocumento->id);
    }

    public function test_estado_descricao_e_sincronizada(): void
    {
        $this->seed(TipoDocumentoSeeder::class);
        $pessoa = $this->criarPessoa();
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();

        $documento = DocumentoPessoa::create([
            'dados_pessoa_id' => $pessoa->id,
            'tipo_documento_id' => $tipo->id,
            'nome_original' => 'bi.pdf',
            'caminho' => 'x',
            'mime_type' => 'application/pdf',
            'tamanho' => 10,
        ]);

        $this->assertSame('Ativo', $documento->estado_descricao);
    }

    public function test_regista_autoria_do_utilizador_autenticado(): void
    {
        $this->seed(TipoDocumentoSeeder::class);
        $pessoa = $this->criarPessoa();
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();
        $user = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('segredo123')]);
        $this->actingAs($user);

        $documento = DocumentoPessoa::create([
            'dados_pessoa_id' => $pessoa->id,
            'tipo_documento_id' => $tipo->id,
            'nome_original' => 'bi.pdf',
            'caminho' => 'x',
            'mime_type' => 'application/pdf',
            'tamanho' => 10,
        ]);

        $this->assertSame($user->id, $documento->criado_por);
        $this->assertSame($user->id, $documento->editado_por);
    }

    public function test_soft_delete_preserva_o_registo_e_nao_altera_estado(): void
    {
        $this->seed(TipoDocumentoSeeder::class);
        $pessoa = $this->criarPessoa();
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();

        $documento = DocumentoPessoa::create([
            'dados_pessoa_id' => $pessoa->id,
            'tipo_documento_id' => $tipo->id,
            'nome_original' => 'bi.pdf',
            'caminho' => 'x',
            'mime_type' => 'application/pdf',
            'tamanho' => 10,
        ]);

        $documento->delete();

        $this->assertSoftDeleted('documentos_pessoas', ['id' => $documento->id]);
        $comTrashed = DocumentoPessoa::withTrashed()->find($documento->id);
        $this->assertSame(1, $comTrashed->estado);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --filter=DocumentoPessoaModelTest
```

Expected: FAIL (class `DocumentoPessoa` not found).

- [ ] **Step 3: Create the migration**

Create `Modules/Usuario/database/migrations/2026_09_16_150100_create_documentos_pessoas_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documentos_pessoas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dados_pessoa_id')->constrained('dados_pessoas')->cascadeOnDelete();
            $table->foreignId('tipo_documento_id')->constrained('tipos_documentos')->restrictOnDelete();
            $table->string('numero_documento')->nullable();
            $table->date('data_emissao')->nullable();
            $table->date('data_validade')->nullable();
            $table->string('nome_original');
            $table->string('caminho');
            $table->string('mime_type');
            $table->unsignedInteger('tamanho');
            $table->text('observacoes')->nullable();
            $table->unsignedTinyInteger('estado')->default(1);
            $table->string('estado_descricao')->default('Ativo');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['dados_pessoa_id', 'tipo_documento_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documentos_pessoas');
    }
};
```

- [ ] **Step 4: Create the model**

Create `Modules/Usuario/app/Models/DocumentoPessoa.php`:

```php
<?php

namespace Modules\Usuario\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Core\Traits\SincronizaEstadoDescricao;

class DocumentoPessoa extends Model
{
    use HasFactory, RegistaAutoria, SincronizaEstadoDescricao, SoftDeletes;

    protected $table = 'documentos_pessoas';

    protected $fillable = [
        'dados_pessoa_id',
        'tipo_documento_id',
        'numero_documento',
        'data_emissao',
        'data_validade',
        'nome_original',
        'caminho',
        'mime_type',
        'tamanho',
        'observacoes',
        'estado',
        'estado_descricao',
        'criado_por',
        'editado_por',
    ];

    protected $attributes = [
        'estado' => 1,
    ];

    protected $casts = [
        'data_emissao' => 'date',
        'data_validade' => 'date',
        'estado' => 'integer',
        'tamanho' => 'integer',
    ];

    public function dadosPessoa(): BelongsTo
    {
        return $this->belongsTo(DadosPessoa::class, 'dados_pessoa_id');
    }

    public function tipoDocumento(): BelongsTo
    {
        return $this->belongsTo(TipoDocumento::class, 'tipo_documento_id');
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

- [ ] **Step 5: Add the inverse relation to `DadosPessoa`**

In `Modules/Usuario/app/Models/DadosPessoa.php`, add the import `use Illuminate\Database\Eloquent\Relations\HasMany;` and, inside the class:

```php
public function documentos(): HasMany
{
    return $this->hasMany(DocumentoPessoa::class, 'dados_pessoa_id');
}
```

- [ ] **Step 6: Run test to verify it passes**

```bash
php artisan test --filter=DocumentoPessoaModelTest
```

Expected: PASS.

- [ ] **Step 7: Stage the changes**

```bash
git add Modules/Usuario/database/migrations/2026_09_16_150100_create_documentos_pessoas_table.php Modules/Usuario/app/Models/DocumentoPessoa.php Modules/Usuario/app/Models/DadosPessoa.php Modules/Usuario/tests/Feature/DocumentoPessoaModelTest.php
```

---

## Task 6: `GuardarDocumentoPessoaRequest`

**Files:**
- Create: `Modules/Usuario/app/Http/Requests/GuardarDocumentoPessoaRequest.php`
- Test: `Modules/Usuario/tests/Feature/GuardarDocumentoPessoaRequestTest.php`

**Interfaces:**
- Consumes: `Modules\Usuario\Models\TipoDocumento` (Task 3, for the `exists` rule), `App\Http\Requests\BaseRequest`.
- Produces: `Modules\Usuario\Http\Requests\GuardarDocumentoPessoaRequest` with `rules(): array` (fields: `tipo_documento_id, numero_documento, data_emissao, data_validade, observacoes, ficheiro`) and `authorize(): bool` checking `documento-pessoa.criar`. Consumed by Task 7's DTO and Task 11's controller.

- [ ] **Step 1: Write the failing test**

Create `Modules/Usuario/tests/Feature/GuardarDocumentoPessoaRequestTest.php`:

```php
<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Modules\Usuario\Database\Seeders\TipoDocumentoSeeder;
use Modules\Usuario\Http\Requests\GuardarDocumentoPessoaRequest;
use Modules\Usuario\Models\TipoDocumento;
use Tests\TestCase;

class GuardarDocumentoPessoaRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_falha_sem_tipo_documento_e_sem_ficheiro(): void
    {
        $validator = Validator::make([], (new GuardarDocumentoPessoaRequest())->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('tipo_documento_id', $validator->errors()->toArray());
        $this->assertArrayHasKey('ficheiro', $validator->errors()->toArray());
    }

    public function test_falha_com_tipo_documento_inexistente(): void
    {
        $validator = Validator::make([
            'tipo_documento_id' => 999,
            'ficheiro' => UploadedFile::fake()->create('bi.pdf', 100, 'application/pdf'),
        ], (new GuardarDocumentoPessoaRequest())->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('tipo_documento_id', $validator->errors()->toArray());
    }

    public function test_falha_quando_data_validade_e_anterior_a_data_emissao(): void
    {
        $this->seed(TipoDocumentoSeeder::class);
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();

        $validator = Validator::make([
            'tipo_documento_id' => $tipo->id,
            'data_emissao' => '2025-01-10',
            'data_validade' => '2024-01-10',
            'ficheiro' => UploadedFile::fake()->create('bi.pdf', 100, 'application/pdf'),
        ], (new GuardarDocumentoPessoaRequest())->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('data_validade', $validator->errors()->toArray());
    }

    public function test_passa_com_dados_validos(): void
    {
        $this->seed(TipoDocumentoSeeder::class);
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();

        $validator = Validator::make([
            'tipo_documento_id' => $tipo->id,
            'numero_documento' => '0012345LA042',
            'ficheiro' => UploadedFile::fake()->create('bi.pdf', 100, 'application/pdf'),
        ], (new GuardarDocumentoPessoaRequest())->rules());

        $this->assertFalse($validator->fails());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --filter=GuardarDocumentoPessoaRequestTest
```

Expected: FAIL (class `GuardarDocumentoPessoaRequest` not found).

- [ ] **Step 3: Create the Request**

Create `Modules/Usuario/app/Http/Requests/GuardarDocumentoPessoaRequest.php`:

```php
<?php

namespace Modules\Usuario\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;

class GuardarDocumentoPessoaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('documento-pessoa.criar') ?? false;
    }

    public function rules(): array
    {
        return [
            'tipo_documento_id' => ['required', 'integer', Rule::exists('tipos_documentos', 'id')],
            'numero_documento' => ['nullable', 'string', 'max:100'],
            'data_emissao' => ['nullable', 'date'],
            'data_validade' => ['nullable', 'date', 'after_or_equal:data_emissao'],
            'observacoes' => ['nullable', 'string', 'max:1000'],
            'ficheiro' => ['required', 'file', 'max:2048', 'mimes:pdf,jpg,jpeg,png'],
        ];
    }

    public function messages(): array
    {
        return [
            'tipo_documento_id.required' => 'Selecione o tipo de documento.',
            'tipo_documento_id.exists' => 'Tipo de documento inválido.',
            'data_validade.after_or_equal' => 'A data de validade não pode ser anterior à data de emissão.',
            'ficheiro.required' => 'Selecione um ficheiro.',
            'ficheiro.file' => 'O ficheiro enviado é inválido.',
            'ficheiro.max' => 'O ficheiro não pode exceder 2MB.',
            'ficheiro.mimes' => 'O ficheiro deve ser PDF, JPG, JPEG ou PNG.',
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
php artisan test --filter=GuardarDocumentoPessoaRequestTest
```

Expected: PASS.

- [ ] **Step 5: Stage the changes**

```bash
git add Modules/Usuario/app/Http/Requests/GuardarDocumentoPessoaRequest.php Modules/Usuario/tests/Feature/GuardarDocumentoPessoaRequestTest.php
```

---

## Task 7: Private disk config, `DocumentoPessoaDTO`, and `CriarDocumentoPessoaAction`

**Files:**
- Modify: `config/filesystems.php` (add `documentos` disk)
- Create: `Modules/Usuario/app/DTO/DocumentoPessoaDTO.php`
- Create: `Modules/Usuario/app/Actions/CriarDocumentoPessoaAction.php`
- Test: `Modules/Usuario/tests/Feature/CriarDocumentoPessoaActionTest.php`

**Interfaces:**
- Consumes: `Modules\Usuario\Http\Requests\GuardarDocumentoPessoaRequest` (Task 6, for `DTO::fromRequest`), `Modules\Usuario\Models\DadosPessoa`, `Modules\Usuario\Models\DocumentoPessoa`, `Modules\Core\Enums\Estado`.
- Produces: `Modules\Usuario\DTO\DocumentoPessoaDTO` (constructor: `int $tipo_documento_id, ?string $numero_documento, ?string $data_emissao, ?string $data_validade, ?string $observacoes`; static `fromRequest(GuardarDocumentoPessoaRequest $request): self`). `Modules\Usuario\Actions\CriarDocumentoPessoaAction::executar(DadosPessoa $pessoa, DocumentoPessoaDTO $dto, UploadedFile $ficheiro): DocumentoPessoa`. Consumed by Task 10 (Service) and Task 11 (Controller, via the Service).

- [ ] **Step 1: Add the private disk to `config/filesystems.php`**

In the `'disks' => [...]` array, after the `'public'` entry, add:

```php
'documentos' => [
    'driver' => 'local',
    'root' => storage_path('app/documentos'),
    'serve' => true,
    'throw' => false,
    'report' => false,
],
```

No `url`/`visibility` key — this disk is private by omission (same as the existing `local` disk), served only through the controlled `download` endpoint built in Task 11.

- [ ] **Step 2: Write the failing test**

Create `Modules/Usuario/tests/Feature/CriarDocumentoPessoaActionTest.php`:

```php
<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Enums\Estado;
use Modules\Usuario\Actions\CriarDocumentoPessoaAction;
use Modules\Usuario\Database\Seeders\TipoDocumentoSeeder;
use Modules\Usuario\DTO\DocumentoPessoaDTO;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\DocumentoPessoa;
use Modules\Usuario\Models\TipoDocumento;
use Tests\TestCase;

class CriarDocumentoPessoaActionTest extends TestCase
{
    use RefreshDatabase;

    private function criarPessoa(string $numeroIdentificacao = 'BI0001'): DadosPessoa
    {
        return DadosPessoa::create([
            'nome_completo' => 'Ana Silva',
            'numero_identificacao' => $numeroIdentificacao,
            'tipo_pessoa' => DadosPessoa::TIPO_ALUNO,
        ]);
    }

    public function test_cria_documento_e_guarda_ficheiro_no_disco_privado(): void
    {
        Storage::fake('documentos');
        $this->seed(TipoDocumentoSeeder::class);
        $pessoa = $this->criarPessoa();
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();

        $dto = new DocumentoPessoaDTO(
            tipo_documento_id: $tipo->id,
            numero_documento: '0012345LA042',
            data_emissao: '2020-01-10',
            data_validade: '2030-01-10',
            observacoes: null,
        );
        $ficheiro = UploadedFile::fake()->create('bi-frente.pdf', 200, 'application/pdf');

        $documento = app(CriarDocumentoPessoaAction::class)->executar($pessoa, $dto, $ficheiro);

        $this->assertInstanceOf(DocumentoPessoa::class, $documento);
        $this->assertSame($pessoa->id, $documento->dados_pessoa_id);
        $this->assertSame($tipo->id, $documento->tipo_documento_id);
        $this->assertSame('0012345LA042', $documento->numero_documento);
        $this->assertSame('bi-frente.pdf', $documento->nome_original);
        $this->assertSame('application/pdf', $documento->mime_type);
        $this->assertSame(Estado::ATIVO->value, $documento->estado);
        Storage::disk('documentos')->assertExists($documento->caminho);
    }

    public function test_novo_documento_do_mesmo_tipo_desactiva_o_anterior_sem_apagar(): void
    {
        Storage::fake('documentos');
        $this->seed(TipoDocumentoSeeder::class);
        $pessoa = $this->criarPessoa();
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();
        $action = app(CriarDocumentoPessoaAction::class);

        $primeiro = $action->executar(
            $pessoa,
            new DocumentoPessoaDTO($tipo->id, 'BI-ANTIGO', null, null, null),
            UploadedFile::fake()->create('antigo.pdf', 100, 'application/pdf'),
        );

        $segundo = $action->executar(
            $pessoa,
            new DocumentoPessoaDTO($tipo->id, 'BI-NOVO', null, null, null),
            UploadedFile::fake()->create('novo.pdf', 100, 'application/pdf'),
        );

        $this->assertSame(Estado::INATIVO->value, $primeiro->fresh()->estado);
        $this->assertSame(Estado::ATIVO->value, $segundo->fresh()->estado);
        $this->assertNull($primeiro->fresh()->deleted_at);
        Storage::disk('documentos')->assertExists($primeiro->caminho);
        Storage::disk('documentos')->assertExists($segundo->caminho);
    }

    public function test_documento_de_outra_pessoa_do_mesmo_tipo_nao_e_afectado(): void
    {
        Storage::fake('documentos');
        $this->seed(TipoDocumentoSeeder::class);
        $pessoa1 = $this->criarPessoa('BI0001');
        $pessoa2 = $this->criarPessoa('BI0002');
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();
        $action = app(CriarDocumentoPessoaAction::class);

        $documentoPessoa1 = $action->executar(
            $pessoa1,
            new DocumentoPessoaDTO($tipo->id, 'BI0001', null, null, null),
            UploadedFile::fake()->create('a.pdf', 100, 'application/pdf'),
        );
        $action->executar(
            $pessoa2,
            new DocumentoPessoaDTO($tipo->id, 'BI0002', null, null, null),
            UploadedFile::fake()->create('b.pdf', 100, 'application/pdf'),
        );

        $this->assertSame(Estado::ATIVO->value, $documentoPessoa1->fresh()->estado);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

```bash
php artisan test --filter=CriarDocumentoPessoaActionTest
```

Expected: FAIL (classes `DocumentoPessoaDTO`/`CriarDocumentoPessoaAction` not found).

- [ ] **Step 4: Create the DTO**

Create `Modules/Usuario/app/DTO/DocumentoPessoaDTO.php`:

```php
<?php

namespace Modules\Usuario\DTO;

use Modules\Usuario\Http\Requests\GuardarDocumentoPessoaRequest;

class DocumentoPessoaDTO
{
    public function __construct(
        public int $tipo_documento_id,
        public ?string $numero_documento,
        public ?string $data_emissao,
        public ?string $data_validade,
        public ?string $observacoes,
    ) {}

    public static function fromRequest(GuardarDocumentoPessoaRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            tipo_documento_id: (int) $dados['tipo_documento_id'],
            numero_documento: $dados['numero_documento'] ?? null,
            data_emissao: $dados['data_emissao'] ?? null,
            data_validade: $dados['data_validade'] ?? null,
            observacoes: $dados['observacoes'] ?? null,
        );
    }
}
```

- [ ] **Step 5: Create the Action**

Create `Modules/Usuario/app/Actions/CriarDocumentoPessoaAction.php`:

```php
<?php

namespace Modules\Usuario\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Core\Enums\Estado;
use Modules\Usuario\DTO\DocumentoPessoaDTO;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\DocumentoPessoa;

class CriarDocumentoPessoaAction
{
    public function executar(DadosPessoa $pessoa, DocumentoPessoaDTO $dto, UploadedFile $ficheiro): DocumentoPessoa
    {
        return DB::transaction(function () use ($pessoa, $dto, $ficheiro) {
            DocumentoPessoa::where('dados_pessoa_id', $pessoa->id)
                ->where('tipo_documento_id', $dto->tipo_documento_id)
                ->where('estado', Estado::ATIVO->value)
                ->lockForUpdate()
                ->get()
                ->each(fn (DocumentoPessoa $anterior) => $anterior->update(['estado' => Estado::INATIVO->value]));

            $caminho = $ficheiro->store('documentos-pessoas/' . $pessoa->id, 'documentos');

            return DocumentoPessoa::create([
                'dados_pessoa_id' => $pessoa->id,
                'tipo_documento_id' => $dto->tipo_documento_id,
                'numero_documento' => $dto->numero_documento,
                'data_emissao' => $dto->data_emissao,
                'data_validade' => $dto->data_validade,
                'observacoes' => $dto->observacoes,
                'nome_original' => $ficheiro->getClientOriginalName(),
                'caminho' => $caminho,
                'mime_type' => $ficheiro->getClientMimeType(),
                'tamanho' => $ficheiro->getSize(),
                'estado' => Estado::ATIVO->value,
            ]);
        });
    }
}
```

(`->get()->each(...)->update()` is deliberate instead of a mass `update()` query — it loads each previous document as an Eloquent model so `SincronizaEstadoDescricao`'s `saving` hook fires and keeps `estado_descricao` in sync.)

- [ ] **Step 6: Run test to verify it passes**

```bash
php artisan test --filter=CriarDocumentoPessoaActionTest
```

Expected: PASS.

- [ ] **Step 7: Stage the changes**

```bash
git add config/filesystems.php Modules/Usuario/app/DTO/DocumentoPessoaDTO.php Modules/Usuario/app/Actions/CriarDocumentoPessoaAction.php Modules/Usuario/tests/Feature/CriarDocumentoPessoaActionTest.php
```

---

## Task 8: `RemoverDocumentoPessoaAction`

**Files:**
- Create: `Modules/Usuario/app/Actions/RemoverDocumentoPessoaAction.php`
- Test: `Modules/Usuario/tests/Feature/RemoverDocumentoPessoaActionTest.php`

**Interfaces:**
- Consumes: `Modules\Usuario\Models\DocumentoPessoa` (Task 5).
- Produces: `Modules\Usuario\Actions\RemoverDocumentoPessoaAction::executar(DocumentoPessoa $documento): void`. Consumed by Task 10 (Service).

- [ ] **Step 1: Write the failing test**

Create `Modules/Usuario/tests/Feature/RemoverDocumentoPessoaActionTest.php`:

```php
<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Enums\Estado;
use Modules\Usuario\Actions\RemoverDocumentoPessoaAction;
use Modules\Usuario\Database\Seeders\TipoDocumentoSeeder;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\DocumentoPessoa;
use Modules\Usuario\Models\TipoDocumento;
use Tests\TestCase;

class RemoverDocumentoPessoaActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_remove_por_soft_delete_sem_alterar_estado(): void
    {
        $this->seed(TipoDocumentoSeeder::class);
        $pessoa = DadosPessoa::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();
        $documento = DocumentoPessoa::create([
            'dados_pessoa_id' => $pessoa->id,
            'tipo_documento_id' => $tipo->id,
            'nome_original' => 'bi.pdf',
            'caminho' => 'x',
            'mime_type' => 'application/pdf',
            'tamanho' => 10,
        ]);

        app(RemoverDocumentoPessoaAction::class)->executar($documento);

        $this->assertSoftDeleted('documentos_pessoas', ['id' => $documento->id]);
        $comTrashed = DocumentoPessoa::withTrashed()->find($documento->id);
        $this->assertSame(Estado::ATIVO->value, $comTrashed->estado);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --filter=RemoverDocumentoPessoaActionTest
```

Expected: FAIL (class `RemoverDocumentoPessoaAction` not found).

- [ ] **Step 3: Create the Action**

Create `Modules/Usuario/app/Actions/RemoverDocumentoPessoaAction.php`:

```php
<?php

namespace Modules\Usuario\Actions;

use Modules\Usuario\Models\DocumentoPessoa;

class RemoverDocumentoPessoaAction
{
    public function executar(DocumentoPessoa $documento): void
    {
        $documento->delete();
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
php artisan test --filter=RemoverDocumentoPessoaActionTest
```

Expected: PASS.

- [ ] **Step 5: Stage the changes**

```bash
git add Modules/Usuario/app/Actions/RemoverDocumentoPessoaAction.php Modules/Usuario/tests/Feature/RemoverDocumentoPessoaActionTest.php
```

---

## Task 9: `AlternarEstadoDocumentoPessoaAction`

**Files:**
- Create: `Modules/Usuario/app/Actions/AlternarEstadoDocumentoPessoaAction.php`
- Test: `Modules/Usuario/tests/Feature/AlternarEstadoDocumentoPessoaActionTest.php`

**Interfaces:**
- Consumes: `Modules\Core\Traits\AlternaEstado`, `Modules\Usuario\Models\DocumentoPessoa`.
- Produces: `Modules\Usuario\Actions\AlternarEstadoDocumentoPessoaAction::executar(DocumentoPessoa $documento): DocumentoPessoa`. Consumed by Task 10 (Service).

- [ ] **Step 1: Write the failing test**

Create `Modules/Usuario/tests/Feature/AlternarEstadoDocumentoPessoaActionTest.php`:

```php
<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Enums\Estado;
use Modules\Usuario\Actions\AlternarEstadoDocumentoPessoaAction;
use Modules\Usuario\Database\Seeders\TipoDocumentoSeeder;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\DocumentoPessoa;
use Modules\Usuario\Models\TipoDocumento;
use Tests\TestCase;

class AlternarEstadoDocumentoPessoaActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_alterna_de_ativo_para_inativo_e_vice_versa(): void
    {
        $this->seed(TipoDocumentoSeeder::class);
        $pessoa = DadosPessoa::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();
        $documento = DocumentoPessoa::create([
            'dados_pessoa_id' => $pessoa->id,
            'tipo_documento_id' => $tipo->id,
            'nome_original' => 'bi.pdf',
            'caminho' => 'x',
            'mime_type' => 'application/pdf',
            'tamanho' => 10,
        ]);
        $action = app(AlternarEstadoDocumentoPessoaAction::class);

        $inativo = $action->executar($documento);
        $this->assertSame(Estado::INATIVO->value, $inativo->estado);

        $ativo = $action->executar($inativo);
        $this->assertSame(Estado::ATIVO->value, $ativo->estado);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --filter=AlternarEstadoDocumentoPessoaActionTest
```

Expected: FAIL (class `AlternarEstadoDocumentoPessoaAction` not found).

- [ ] **Step 3: Create the Action**

Create `Modules/Usuario/app/Actions/AlternarEstadoDocumentoPessoaAction.php`:

```php
<?php

namespace Modules\Usuario\Actions;

use Modules\Core\Traits\AlternaEstado;
use Modules\Usuario\Models\DocumentoPessoa;

class AlternarEstadoDocumentoPessoaAction
{
    use AlternaEstado;

    public function executar(DocumentoPessoa $documento): DocumentoPessoa
    {
        return $this->alternarEstado($documento);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
php artisan test --filter=AlternarEstadoDocumentoPessoaActionTest
```

Expected: PASS.

- [ ] **Step 5: Stage the changes**

```bash
git add Modules/Usuario/app/Actions/AlternarEstadoDocumentoPessoaAction.php Modules/Usuario/tests/Feature/AlternarEstadoDocumentoPessoaActionTest.php
```

---

## Task 10: `GestaoDocumentoPessoaService`

**Files:**
- Create: `Modules/Usuario/app/Services/GestaoDocumentoPessoaService.php`
- Test: `Modules/Usuario/tests/Feature/GestaoDocumentoPessoaServiceTest.php`

**Interfaces:**
- Consumes: `CriarDocumentoPessoaAction`, `RemoverDocumentoPessoaAction`, `AlternarEstadoDocumentoPessoaAction` (Tasks 7-9), `Modules\Usuario\Models\DadosPessoa`, `Modules\Usuario\Models\DocumentoPessoa`, `Modules\Usuario\DTO\DocumentoPessoaDTO`.
- Produces: `Modules\Usuario\Services\GestaoDocumentoPessoaService` with `listar(DadosPessoa $pessoa): Collection`, `adicionar(DadosPessoa $pessoa, DocumentoPessoaDTO $dto, UploadedFile $ficheiro): DocumentoPessoa`, `remover(DocumentoPessoa $documento): void`, `alternarEstado(DocumentoPessoa $documento): DocumentoPessoa`, `download(DocumentoPessoa $documento): StreamedResponse`. Consumed by Task 11 (Controller).

- [ ] **Step 1: Write the failing test**

Create `Modules/Usuario/tests/Feature/GestaoDocumentoPessoaServiceTest.php`:

```php
<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Usuario\Database\Seeders\TipoDocumentoSeeder;
use Modules\Usuario\DTO\DocumentoPessoaDTO;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\DocumentoPessoa;
use Modules\Usuario\Models\TipoDocumento;
use Modules\Usuario\Services\GestaoDocumentoPessoaService;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class GestaoDocumentoPessoaServiceTest extends TestCase
{
    use RefreshDatabase;

    private function criarPessoa(): DadosPessoa
    {
        return DadosPessoa::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
    }

    public function test_listar_devolve_os_documentos_da_pessoa_com_tipo_carregado(): void
    {
        $this->seed(TipoDocumentoSeeder::class);
        $pessoa = $this->criarPessoa();
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();
        DocumentoPessoa::create([
            'dados_pessoa_id' => $pessoa->id,
            'tipo_documento_id' => $tipo->id,
            'nome_original' => 'bi.pdf',
            'caminho' => 'x',
            'mime_type' => 'application/pdf',
            'tamanho' => 10,
        ]);

        $documentos = app(GestaoDocumentoPessoaService::class)->listar($pessoa);

        $this->assertCount(1, $documentos);
        $this->assertTrue($documentos->first()->relationLoaded('tipoDocumento'));
    }

    public function test_adicionar_cria_documento_via_action(): void
    {
        Storage::fake('documentos');
        $this->seed(TipoDocumentoSeeder::class);
        $pessoa = $this->criarPessoa();
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();
        $dto = new DocumentoPessoaDTO($tipo->id, 'BI0001', null, null, null);

        $documento = app(GestaoDocumentoPessoaService::class)->adicionar($pessoa, $dto, UploadedFile::fake()->create('bi.pdf', 100, 'application/pdf'));

        $this->assertInstanceOf(DocumentoPessoa::class, $documento);
        $this->assertSame(1, DocumentoPessoa::count());
    }

    public function test_remover_faz_soft_delete_via_action(): void
    {
        $this->seed(TipoDocumentoSeeder::class);
        $pessoa = $this->criarPessoa();
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();
        $documento = DocumentoPessoa::create([
            'dados_pessoa_id' => $pessoa->id,
            'tipo_documento_id' => $tipo->id,
            'nome_original' => 'bi.pdf',
            'caminho' => 'x',
            'mime_type' => 'application/pdf',
            'tamanho' => 10,
        ]);

        app(GestaoDocumentoPessoaService::class)->remover($documento);

        $this->assertSoftDeleted('documentos_pessoas', ['id' => $documento->id]);
    }

    public function test_download_devolve_streamed_response_do_disco_privado(): void
    {
        Storage::fake('documentos');
        $this->seed(TipoDocumentoSeeder::class);
        $pessoa = $this->criarPessoa();
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();
        $caminho = UploadedFile::fake()->create('bi.pdf', 100, 'application/pdf')->store('documentos-pessoas/' . $pessoa->id, 'documentos');
        $documento = DocumentoPessoa::create([
            'dados_pessoa_id' => $pessoa->id,
            'tipo_documento_id' => $tipo->id,
            'nome_original' => 'bi.pdf',
            'caminho' => $caminho,
            'mime_type' => 'application/pdf',
            'tamanho' => 10,
        ]);

        $resposta = app(GestaoDocumentoPessoaService::class)->download($documento);

        $this->assertInstanceOf(StreamedResponse::class, $resposta);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --filter=GestaoDocumentoPessoaServiceTest
```

Expected: FAIL (class `GestaoDocumentoPessoaService` not found).

- [ ] **Step 3: Create the Service**

Create `Modules/Usuario/app/Services/GestaoDocumentoPessoaService.php`:

```php
<?php

namespace Modules\Usuario\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Usuario\Actions\AlternarEstadoDocumentoPessoaAction;
use Modules\Usuario\Actions\CriarDocumentoPessoaAction;
use Modules\Usuario\Actions\RemoverDocumentoPessoaAction;
use Modules\Usuario\DTO\DocumentoPessoaDTO;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\DocumentoPessoa;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GestaoDocumentoPessoaService
{
    public function __construct(
        private CriarDocumentoPessoaAction $criarAction,
        private RemoverDocumentoPessoaAction $removerAction,
        private AlternarEstadoDocumentoPessoaAction $alternarEstadoAction,
    ) {
    }

    public function listar(DadosPessoa $pessoa): Collection
    {
        return $pessoa->documentos()->with('tipoDocumento')->latest()->get();
    }

    public function adicionar(DadosPessoa $pessoa, DocumentoPessoaDTO $dto, UploadedFile $ficheiro): DocumentoPessoa
    {
        return $this->criarAction->executar($pessoa, $dto, $ficheiro);
    }

    public function remover(DocumentoPessoa $documento): void
    {
        $this->removerAction->executar($documento);
    }

    public function alternarEstado(DocumentoPessoa $documento): DocumentoPessoa
    {
        return $this->alternarEstadoAction->executar($documento);
    }

    public function download(DocumentoPessoa $documento): StreamedResponse
    {
        return Storage::disk('documentos')->download($documento->caminho, $documento->nome_original);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
php artisan test --filter=GestaoDocumentoPessoaServiceTest
```

Expected: PASS.

- [ ] **Step 5: Stage the changes**

```bash
git add Modules/Usuario/app/Services/GestaoDocumentoPessoaService.php Modules/Usuario/tests/Feature/GestaoDocumentoPessoaServiceTest.php
```

---

## Task 11: `DocumentoPessoaController` and routes

**Files:**
- Create: `Modules/Usuario/app/Http/Controllers/DocumentoPessoaController.php`
- Modify: `Modules/Usuario/routes/web.php`
- Test: `Modules/Usuario/tests/Feature/DocumentoPessoaHttpTest.php`

**Interfaces:**
- Consumes: `GestaoDocumentoPessoaService` (Task 10), `GuardarDocumentoPessoaRequest` (Task 6), `DocumentoPessoaDTO::fromRequest` (Task 7), `Modules\Usuario\Models\DadosPessoa`, `Modules\Usuario\Models\DocumentoPessoa`.
- Produces: HTTP routes `documentos-pessoa.index` (GET, JSON), `.store` (POST, redirect), `.destroy` (DELETE, redirect), `.alternarEstado` (PATCH, redirect), `.download` (GET, file stream) — this is the plan's final deliverable, ready for the (deferred, out-of-scope) Aluno-form frontend to call.

- [ ] **Step 1: Write the failing test**

Create `Modules/Usuario/tests/Feature/DocumentoPessoaHttpTest.php`:

```php
<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Database\Seeders\TipoDocumentoSeeder;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\DocumentoPessoa;
use Modules\Usuario\Models\TipoDocumento;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class DocumentoPessoaHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
        $this->seed(TipoDocumentoSeeder::class);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => Hash::make('segredo123')]);
        $admin->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id);
        $this->actingAs($admin);

        return $admin;
    }

    private function criarPessoa(): DadosPessoa
    {
        return DadosPessoa::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoa::TIPO_ALUNO]);
    }

    public function test_admin_faz_upload_de_documento(): void
    {
        Storage::fake('documentos');
        $this->actingAsAdmin();
        $pessoa = $this->criarPessoa();
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();

        $response = $this->post("/dados-pessoais/{$pessoa->id}/documentos", [
            'tipo_documento_id' => $tipo->id,
            'numero_documento' => '0012345LA042',
            'ficheiro' => UploadedFile::fake()->create('bi.pdf', 100, 'application/pdf'),
        ]);

        $response->assertRedirect();
        $this->assertSame(1, DocumentoPessoa::count());
    }

    public function test_utilizador_sem_permissao_nao_faz_upload(): void
    {
        Storage::fake('documentos');
        $semPermissao = User::create(['name' => 'Sem Permissao', 'email' => 'sem.permissao@example.com', 'password' => Hash::make('segredo123')]);
        $this->actingAs($semPermissao);
        $pessoa = $this->criarPessoa();
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();

        $response = $this->post("/dados-pessoais/{$pessoa->id}/documentos", [
            'tipo_documento_id' => $tipo->id,
            'ficheiro' => UploadedFile::fake()->create('bi.pdf', 100, 'application/pdf'),
        ]);

        $response->assertForbidden();
        $this->assertSame(0, DocumentoPessoa::count());
    }

    public function test_lista_documentos_da_pessoa(): void
    {
        $this->actingAsAdmin();
        $pessoa = $this->criarPessoa();
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();
        DocumentoPessoa::create([
            'dados_pessoa_id' => $pessoa->id,
            'tipo_documento_id' => $tipo->id,
            'nome_original' => 'bi.pdf',
            'caminho' => 'x',
            'mime_type' => 'application/pdf',
            'tamanho' => 10,
        ]);

        $response = $this->get("/dados-pessoais/{$pessoa->id}/documentos");

        $response->assertOk();
        $response->assertJsonCount(1, 'documentos');
    }

    public function test_download_devolve_o_ficheiro_para_quem_tem_permissao(): void
    {
        Storage::fake('documentos');
        $this->actingAsAdmin();
        $pessoa = $this->criarPessoa();
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();
        $caminho = UploadedFile::fake()->create('bi.pdf', 100, 'application/pdf')->store('documentos-pessoas/' . $pessoa->id, 'documentos');
        $documento = DocumentoPessoa::create([
            'dados_pessoa_id' => $pessoa->id,
            'tipo_documento_id' => $tipo->id,
            'nome_original' => 'bi.pdf',
            'caminho' => $caminho,
            'mime_type' => 'application/pdf',
            'tamanho' => 10,
        ]);

        $response = $this->get("/documentos-pessoa/{$documento->id}/download");

        $response->assertOk();
    }

    public function test_download_e_recusado_sem_permissao(): void
    {
        Storage::fake('documentos');
        $semPermissao = User::create(['name' => 'Sem Permissao', 'email' => 'sem.permissao@example.com', 'password' => Hash::make('segredo123')]);
        $pessoa = $this->criarPessoa();
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();
        $caminho = UploadedFile::fake()->create('bi.pdf', 100, 'application/pdf')->store('documentos-pessoas/' . $pessoa->id, 'documentos');
        $documento = DocumentoPessoa::create([
            'dados_pessoa_id' => $pessoa->id,
            'tipo_documento_id' => $tipo->id,
            'nome_original' => 'bi.pdf',
            'caminho' => $caminho,
            'mime_type' => 'application/pdf',
            'tamanho' => 10,
        ]);
        $this->actingAs($semPermissao);

        $response = $this->get("/documentos-pessoa/{$documento->id}/download");

        $response->assertForbidden();
    }

    public function test_elimina_documento_por_soft_delete(): void
    {
        $this->actingAsAdmin();
        $pessoa = $this->criarPessoa();
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();
        $documento = DocumentoPessoa::create([
            'dados_pessoa_id' => $pessoa->id,
            'tipo_documento_id' => $tipo->id,
            'nome_original' => 'bi.pdf',
            'caminho' => 'x',
            'mime_type' => 'application/pdf',
            'tamanho' => 10,
        ]);

        $response = $this->delete("/documentos-pessoa/{$documento->id}");

        $response->assertRedirect();
        $this->assertSoftDeleted('documentos_pessoas', ['id' => $documento->id]);
    }

    public function test_alterna_estado_do_documento(): void
    {
        $this->actingAsAdmin();
        $pessoa = $this->criarPessoa();
        $tipo = TipoDocumento::where('slug', 'bi')->firstOrFail();
        $documento = DocumentoPessoa::create([
            'dados_pessoa_id' => $pessoa->id,
            'tipo_documento_id' => $tipo->id,
            'nome_original' => 'bi.pdf',
            'caminho' => 'x',
            'mime_type' => 'application/pdf',
            'tamanho' => 10,
        ]);

        $response = $this->patch("/documentos-pessoa/{$documento->id}/estado");

        $response->assertRedirect();
        $this->assertSame(0, $documento->fresh()->estado);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --filter=DocumentoPessoaHttpTest
```

Expected: FAIL (404s — routes/controller don't exist yet).

- [ ] **Step 3: Create the Controller**

Create `Modules/Usuario/app/Http/Controllers/DocumentoPessoaController.php`:

```php
<?php

namespace Modules\Usuario\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Usuario\DTO\DocumentoPessoaDTO;
use Modules\Usuario\Http\Requests\GuardarDocumentoPessoaRequest;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\DocumentoPessoa;
use Modules\Usuario\Services\GestaoDocumentoPessoaService;

class DocumentoPessoaController extends Controller
{
    public function __construct(
        private GestaoDocumentoPessoaService $service,
    ) {
    }

    public function index(DadosPessoa $dadosPessoa)
    {
        $this->authorize('documento-pessoa.ver');

        return response()->json([
            'documentos' => $this->service->listar($dadosPessoa),
        ]);
    }

    public function store(GuardarDocumentoPessoaRequest $request, DadosPessoa $dadosPessoa)
    {
        $this->authorize('documento-pessoa.criar');

        $dto = DocumentoPessoaDTO::fromRequest($request);
        $this->service->adicionar($dadosPessoa, $dto, $request->file('ficheiro'));

        return redirect()->back()->with('success', 'Documento adicionado com sucesso.');
    }

    public function destroy(DocumentoPessoa $documento)
    {
        $this->authorize('documento-pessoa.eliminar');

        $this->service->remover($documento);

        return redirect()->back()->with('success', 'Documento removido com sucesso.');
    }

    public function alternarEstado(DocumentoPessoa $documento)
    {
        $this->authorize('documento-pessoa.editar');

        $this->service->alternarEstado($documento);

        return redirect()->back()->with('success', 'Estado do documento atualizado com sucesso.');
    }

    public function download(DocumentoPessoa $documento)
    {
        $this->authorize('documento-pessoa.ver');

        return $this->service->download($documento);
    }
}
```

- [ ] **Step 4: Add the routes**

In `Modules/Usuario/routes/web.php`, add the import `use Modules\Usuario\Http\Controllers\DocumentoPessoaController;` at the top, and append this group (outside the existing `usuarios` prefix group, since these routes are keyed by `DadosPessoa`/`DocumentoPessoa`, not by `User`):

```php
Route::middleware(['auth'])->group(function () {
    Route::get('/dados-pessoais/{dadosPessoa}/documentos', [DocumentoPessoaController::class, 'index'])
        ->middleware('can:documento-pessoa.ver')->name('documentos-pessoa.index');
    Route::post('/dados-pessoais/{dadosPessoa}/documentos', [DocumentoPessoaController::class, 'store'])
        ->middleware('can:documento-pessoa.criar')->name('documentos-pessoa.store');
    Route::delete('/documentos-pessoa/{documento}', [DocumentoPessoaController::class, 'destroy'])
        ->middleware('can:documento-pessoa.eliminar')->name('documentos-pessoa.destroy');
    Route::patch('/documentos-pessoa/{documento}/estado', [DocumentoPessoaController::class, 'alternarEstado'])
        ->middleware('can:documento-pessoa.editar')->name('documentos-pessoa.alternarEstado');
    Route::get('/documentos-pessoa/{documento}/download', [DocumentoPessoaController::class, 'download'])
        ->middleware('can:documento-pessoa.ver')->name('documentos-pessoa.download');
});
```

- [ ] **Step 5: Run test to verify it passes**

```bash
php artisan test --filter=DocumentoPessoaHttpTest
```

Expected: PASS.

- [ ] **Step 6: Run the full suite one last time**

```bash
php artisan test
```

Expected: PASS, no regressions across any module (rename from Task 1 + all new Documento Pessoa tests).

- [ ] **Step 7: Stage the changes**

```bash
git add Modules/Usuario/app/Http/Controllers/DocumentoPessoaController.php Modules/Usuario/routes/web.php Modules/Usuario/tests/Feature/DocumentoPessoaHttpTest.php
```

Do not commit — wait for the user to explicitly ask.
