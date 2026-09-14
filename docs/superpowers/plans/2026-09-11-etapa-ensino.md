# Etapa de Ensino — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Introduzir `EtapaEnsinoEnum` (Creche, Pré-Escolar, Primário, Secundário, Superior), ligar `NivelAcademico` a essa etapa, e tornar `Turma.curso_id` condicionalmente obrigatório (só quando a etapa do nível académico exige curso), para que estabelecimentos sem cursos (creches, pré-escolares, ensino primário) consigam criar Turmas.

**Architecture:** Enum PHP novo em `Modules\Estabelecimento\Enums` (mesma família de `TipoEnsinoEnum`). Coluna `etapa_ensino` (+ `etapa_ensino_descricao`) em `niveis_academicos`, seguindo o padrão de coluna-irmã já usado em `estabelecimentos.tipo_ensino_descricao` (sincronizada num hook `saving()` manual no `booted()` do model, sem trait genérica — a trait `SincronizaEstadoDescricao` existente é específica do enum binário `Estado`). `Turma.curso_id` passa de `NOT NULL` para `nullable`; a obrigatoriedade passa a ser decidida em validação (`Rule::requiredIf`) a partir de `EtapaEnsinoEnum::exigeCurso()` do nível académico seleccionado — sem nenhuma coluna nova em `NivelAcademico` para isto (regra fixa no enum, não configurável por instituição).

**Tech Stack:** Laravel 12 + nwidart/laravel-modules, Inertia + Vue 3, PHPUnit (`Tests\TestCase`, `RefreshDatabase`, sqlite `:memory:` em testes).

**Spec:** `docs/superpowers/specs/2026-09-11-etapa-ensino-design.md`

## Global Constraints

- `EtapaEnsinoEnum` vive em `Modules\Estabelecimento\Enums` (mesma família de `TipoEnsinoEnum`/`TipoEstabelecimentoEnum`) — nunca em `Modules\Turma`.
- `TipoEnsinoEnum` e `estabelecimentos.tipo_ensino` não são tocados nesta feature.
- `NivelAcademico.etapa_ensino` é `NOT NULL`, sem `default` a nível de BD — mesma lógica já usada em `turmas.curso_id` original: assume-se fase de desenvolvimento, tabela `niveis_academicos` ainda sem dados reais via UI.
- **Sem coluna `exige_curso` em `NivelAcademico`** — decisão confirmada pelo utilizador. A obrigatoriedade de `curso_id` deriva sempre de `EtapaEnsinoEnum::exigeCurso()` (`true` só para `SECUNDARIO`/`SUPERIOR`), nunca de um campo persistido.
- `turmas.curso_id` passa a `nullable`; a validação usa `Rule::requiredIf(...)`, nunca `required` fixo.
- Nenhuma alteração a permissões — `turmas.ver`/`turmas.criar`/`turmas.editar` continuam a cobrir tudo (NivelAcademico e Turma já vivem sob estas permissões).
- Toda alteração de ficheiro de teste existente que passe a falhar por causa de uma task deve ser corrigida **ainda dentro dessa mesma task**, excepto a fronteira explícita Task 2 → Task 3 (ver nota na Task 2), que replica o padrão já usado no plano do módulo Curso (migração+model numa task, wiring de Request/Action na seguinte).
- `use` sempre no topo de cada ficheiro PHP; nunca FQN inline.

---

## Estrutura de Ficheiros

**Novo:**
```
Modules/Estabelecimento/app/Enums/EtapaEnsinoEnum.php
Modules/Estabelecimento/tests/Unit/EtapaEnsinoEnumTest.php
Modules/Turma/database/migrations/2026_09_11_100000_add_etapa_ensino_to_niveis_academicos_table.php
Modules/Turma/database/migrations/2026_09_11_100100_make_curso_id_nullable_on_turmas_table.php
Modules/Turma/tests/Feature/NivelAcademicoConsultaServiceTest.php
```

**Modificado:**
```
Modules/Turma/app/Models/NivelAcademico.php                          — fillable, casts, booted()
Modules/Turma/app/DTO/NivelAcademicoDTO.php                          — campo etapa_ensino
Modules/Turma/app/Http/Requests/CriarNivelAcademicoRequest.php       — validação etapa_ensino
Modules/Turma/app/Http/Requests/AtualizarNivelAcademicoRequest.php   — validação etapa_ensino
Modules/Turma/app/Actions/CriarNivelAcademicoAction.php              — persiste etapa_ensino
Modules/Turma/app/Actions/AtualizarNivelAcademicoAction.php          — persiste etapa_ensino
Modules/Turma/app/Http/Requests/CriarTurmaRequest.php                — curso_id required_if
Modules/Turma/app/Http/Requests/AtualizarTurmaRequest.php            — curso_id required_if
Modules/Turma/app/DTO/TurmaDTO.php                                   — curso_id nullable
Modules/Turma/app/Services/TurmaConsultaService.php                  — niveisAcademicos com etapa_ensino
Modules/Turma/app/Services/NivelAcademicoConsultaService.php         — novo método etapasEnsino()
Modules/Turma/tests/Feature/TurmaHttpTest.php                        — helper criarNivelAcademico(), novos testes
Modules/Turma/resources/js/Components/NivelAcademico/NivelAcademicoFormModal.vue — select Etapa de Ensino
Modules/Turma/resources/js/Pages/NiveisAcademicos/Index.vue          — coluna Etapa
Modules/Turma/resources/js/Components/Turma/TurmaFormModal.vue       — select Curso condicional
Modules/PlanoCurricular/tests/Feature/*.php (12 ficheiros)           — fixtures NivelAcademico ganham etapa_ensino
```

---

## Task 1: `EtapaEnsinoEnum`

**Files:**
- Create: `Modules/Estabelecimento/app/Enums/EtapaEnsinoEnum.php`
- Test: `Modules/Estabelecimento/tests/Unit/EtapaEnsinoEnumTest.php`

**Interfaces:**
- Produces: `Modules\Estabelecimento\Enums\EtapaEnsinoEnum` (cases `CRECHE=1, PRE_ESCOLAR=2, PRIMARIO=3, SECUNDARIO=4, SUPERIOR=5`), método `label(): string`, método `exigeCurso(): bool`. Usado a partir da Task 2 em diante.

- [ ] **Step 1: Escrever o teste (falha — classe não existe)**

`Modules/Estabelecimento/tests/Unit/EtapaEnsinoEnumTest.php`:

```php
<?php

namespace Modules\Estabelecimento\Tests\Unit;

use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Tests\TestCase;

class EtapaEnsinoEnumTest extends TestCase
{
    public function test_labels(): void
    {
        $this->assertSame('Creche', EtapaEnsinoEnum::CRECHE->label());
        $this->assertSame('Pré-Escolar', EtapaEnsinoEnum::PRE_ESCOLAR->label());
        $this->assertSame('Ensino Primário', EtapaEnsinoEnum::PRIMARIO->label());
        $this->assertSame('Ensino Secundário', EtapaEnsinoEnum::SECUNDARIO->label());
        $this->assertSame('Ensino Superior', EtapaEnsinoEnum::SUPERIOR->label());
    }

    public function test_from_valores_validos(): void
    {
        $this->assertSame(EtapaEnsinoEnum::CRECHE, EtapaEnsinoEnum::from(1));
        $this->assertSame(EtapaEnsinoEnum::PRE_ESCOLAR, EtapaEnsinoEnum::from(2));
        $this->assertSame(EtapaEnsinoEnum::PRIMARIO, EtapaEnsinoEnum::from(3));
        $this->assertSame(EtapaEnsinoEnum::SECUNDARIO, EtapaEnsinoEnum::from(4));
        $this->assertSame(EtapaEnsinoEnum::SUPERIOR, EtapaEnsinoEnum::from(5));
    }

    public function test_valor_invalido_lanca_erro(): void
    {
        $this->expectException(\ValueError::class);
        EtapaEnsinoEnum::from(99);
    }

    public function test_exige_curso(): void
    {
        $this->assertFalse(EtapaEnsinoEnum::CRECHE->exigeCurso());
        $this->assertFalse(EtapaEnsinoEnum::PRE_ESCOLAR->exigeCurso());
        $this->assertFalse(EtapaEnsinoEnum::PRIMARIO->exigeCurso());
        $this->assertTrue(EtapaEnsinoEnum::SECUNDARIO->exigeCurso());
        $this->assertTrue(EtapaEnsinoEnum::SUPERIOR->exigeCurso());
    }
}
```

- [ ] **Step 2: Correr o teste e confirmar que falha**

```bash
php artisan test --filter=EtapaEnsinoEnumTest
```

Esperado: FAIL — `Class "Modules\Estabelecimento\Enums\EtapaEnsinoEnum" not found`.

- [ ] **Step 3: Implementar o enum**

`Modules/Estabelecimento/app/Enums/EtapaEnsinoEnum.php`:

```php
<?php

namespace Modules\Estabelecimento\Enums;

enum EtapaEnsinoEnum: int
{
    case CRECHE = 1;
    case PRE_ESCOLAR = 2;
    case PRIMARIO = 3;
    case SECUNDARIO = 4;
    case SUPERIOR = 5;

    public function label(): string
    {
        return match ($this) {
            self::CRECHE => 'Creche',
            self::PRE_ESCOLAR => 'Pré-Escolar',
            self::PRIMARIO => 'Ensino Primário',
            self::SECUNDARIO => 'Ensino Secundário',
            self::SUPERIOR => 'Ensino Superior',
        };
    }

    public function exigeCurso(): bool
    {
        return match ($this) {
            self::SECUNDARIO, self::SUPERIOR => true,
            default => false,
        };
    }
}
```

- [ ] **Step 4: Correr o teste e confirmar que passa**

```bash
php artisan test --filter=EtapaEnsinoEnumTest
```

Esperado: PASS (4 testes).

- [ ] **Step 5: Commit**

```bash
git add Modules/Estabelecimento/app/Enums/EtapaEnsinoEnum.php Modules/Estabelecimento/tests/Unit/EtapaEnsinoEnumTest.php
git commit -m "feat(estabelecimento): adiciona EtapaEnsinoEnum"
```

---

## Task 2: Migração + Model — `niveis_academicos.etapa_ensino`

**Files:**
- Create: `Modules/Turma/database/migrations/2026_09_11_100000_add_etapa_ensino_to_niveis_academicos_table.php`
- Modify: `Modules/Turma/app/Models/NivelAcademico.php`
- Modify: `Modules/Turma/tests/Feature/TurmaHttpTest.php` (helper `criarNivelAcademico()` + 13 chamadas substituídas)
- Modify: 12 ficheiros de teste em `Modules/PlanoCurricular/tests/Feature/` (fixtures mecânicas — ver Step 2)

**Interfaces:**
- Consumes: `Modules\Estabelecimento\Enums\EtapaEnsinoEnum` (Task 1).
- Produces: `niveis_academicos.etapa_ensino` (NOT NULL) + `etapa_ensino_descricao`; `NivelAcademico::$casts['etapa_ensino']` → `EtapaEnsinoEnum`.

**Nota sobre esta task ficar parcialmente "vermelha":** depois desta task, criar/editar `NivelAcademico` **via Eloquent directo** (todos os testes deste ficheiro e de PlanoCurricular) já funciona. Criar/editar `NivelAcademico` **via HTTP** (`niveis-academicos.store`/`update`) continua a falhar até à Task 3, porque `CriarNivelAcademicoRequest`/`CriarNivelAcademicoAction` ainda não conhecem `etapa_ensino`. É o mesmo padrão já usado no plano do módulo Curso (Task 7 → Task 8).

- [ ] **Step 1: Editar `TurmaHttpTest.php` — introduzir o helper e substituir as 13 chamadas directas**

Adicionar o import no topo do ficheiro, a seguir aos `use` existentes:

```php
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
```

Adicionar o helper logo a seguir a `criarCurso()`:

```php
    private function criarNivelAcademico(Estabelecimento $estabelecimento, EtapaEnsinoEnum $etapa = EtapaEnsinoEnum::SECUNDARIO): NivelAcademico
    {
        return NivelAcademico::create([
            'estabelecimento_id' => $estabelecimento->id,
            'codigo' => '1C',
            'nome' => '1ª Classe',
            'ordem' => 1,
            'etapa_ensino' => $etapa,
        ]);
    }
```

Substituir **todas** as 13 ocorrências de
`NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 1]);`
(com ou sem `$nivel =` à frente) por `$this->criarNivelAcademico($estabelecimento);` (mantendo o `$nivel =`/`return` conforme o contexto de cada teste). Confirmar a contagem antes e depois:

```bash
grep -c "NivelAcademico::create(\['estabelecimento_id' => \$estabelecimento->id, 'codigo' => '1C'" Modules/Turma/tests/Feature/TurmaHttpTest.php
```

Esperado depois da edição: `0`.

- [ ] **Step 2: Corrigir as fixtures de `NivelAcademico` em PlanoCurricular (16 ocorrências mecânicas em 12 ficheiros)**

Estas fixtures não têm nenhuma relação com a obrigatoriedade de curso — qualquer etapa válida serve. Aplicar via `sed` (mais seguro que editar 16 linhas manualmente, já que o padrão é idêntico em todas):

```bash
for f in \
  Modules/PlanoCurricular/tests/Feature/AdicionarDisciplinaRequestTest.php \
  Modules/PlanoCurricular/tests/Feature/AtualizarDisciplinaDoPlanoActionTest.php \
  Modules/PlanoCurricular/tests/Feature/PlanoCurricularConsultaServiceTest.php \
  Modules/PlanoCurricular/tests/Feature/PlanoCurricularDisciplinaModelTest.php \
  Modules/PlanoCurricular/tests/Feature/PlanoCurricularDisciplinaPeriodoModelTest.php \
  Modules/PlanoCurricular/tests/Feature/AdicionarDisciplinaAoPlanoActionTest.php \
  Modules/PlanoCurricular/tests/Feature/PlanoCurricularHistoricoTest.php \
  Modules/PlanoCurricular/tests/Feature/PlanoCurricularIsolamentoTest.php \
  Modules/PlanoCurricular/tests/Feature/DefinirPeriodosDaDisciplinaActionTest.php \
  Modules/PlanoCurricular/tests/Feature/DefinirPeriodosDisciplinaRequestTest.php \
  Modules/PlanoCurricular/tests/Feature/PlanoCurricularHttpTest.php \
  Modules/PlanoCurricular/tests/Feature/RemoverDisciplinaDoPlanoActionTest.php \
; do
  sed -i -E "s/(NivelAcademico::create\(\[.*'ordem' => [0-9]+)\]\)/\1, 'etapa_ensino' => 4]);/;s/\]\);;/]);/" "$f"
done
```

Confirmar visualmente que todas as 16 linhas ganharam `'etapa_ensino' => 4` (valor de `EtapaEnsinoEnum::SECUNDARIO`, irrelevante para estes testes):

```bash
grep -rn "NivelAcademico::create(" Modules/PlanoCurricular/tests/Feature/*.php
```

Cada linha deve terminar em `..., 'etapa_ensino' => 4]);`. Corrigir manualmente qualquer linha em que o `sed` não tenha aplicado correctamente (o `;;` residual do padrão de segurança acima remove um `;` duplicado, caso ocorra).

- [ ] **Step 3: Correr as suites afectadas e confirmar que falham (coluna ainda não existe)**

```bash
php artisan test --filter=TurmaHttpTest
php artisan test tests/Feature Modules/PlanoCurricular
```

Esperado: FAIL em ambas — `SQLSTATE... no such column: etapa_ensino` (sqlite) ao criar `NivelAcademico`.

- [ ] **Step 4: Criar a migração**

`Modules/Turma/database/migrations/2026_09_11_100000_add_etapa_ensino_to_niveis_academicos_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('niveis_academicos', function (Blueprint $table) {
            $table->unsignedTinyInteger('etapa_ensino')->after('nome');
            $table->string('etapa_ensino_descricao')->after('etapa_ensino');
        });
    }

    public function down(): void
    {
        Schema::table('niveis_academicos', function (Blueprint $table) {
            $table->dropColumn(['etapa_ensino', 'etapa_ensino_descricao']);
        });
    }
};
```

- [ ] **Step 5: Actualizar o Model `NivelAcademico`**

Editar `Modules/Turma/app/Models/NivelAcademico.php`:

```php
<?php

namespace Modules\Turma\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Core\Traits\SincronizaEstadoDescricao;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Models\User;

class NivelAcademico extends Model
{
    use RegistaAutoria;
    use SincronizaEstadoDescricao;

    protected $table = 'niveis_academicos';

    protected $fillable = [
        'estabelecimento_id',
        'codigo',
        'nome',
        'etapa_ensino',
        'ordem',
        'estado',
        'estado_descricao',
        'criado_por',
        'editado_por',
    ];

    protected $casts = [
        'estado' => 'integer',
        'ordem' => 'integer',
        'etapa_ensino' => EtapaEnsinoEnum::class,
    ];

    public function estabelecimento(): BelongsTo
    {
        return $this->belongsTo(Estabelecimento::class, 'estabelecimento_id');
    }

    public function turmas(): HasMany
    {
        return $this->hasMany(Turma::class);
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
        static::saving(function (NivelAcademico $nivelAcademico) {
            if ($nivelAcademico->etapa_ensino !== null) {
                $nivelAcademico->etapa_ensino_descricao = $nivelAcademico->etapa_ensino->label();
            }
        });
    }
}
```

(A guarda `!== null` replica exactamente a razão documentada em `Estabelecimento::booted()`: evita sobrepor com `null` em qualquer save que não passe o campo explicitamente. Aqui não há default de BD, mas a guarda mantém o código simétrico e seguro caso a coluna alguma vez ganhe um default.)

- [ ] **Step 6: Correr as suites e confirmar o estado esperado**

```bash
php artisan test --filter=TurmaHttpTest
php artisan test tests/Feature Modules/PlanoCurricular
```

Esperado:
- Suite de PlanoCurricular: PASS (não depende de Requests de NivelAcademico).
- `TurmaHttpTest`: os testes que criam `NivelAcademico` directamente (a maioria) passam; `test_cria_nivel_academico_via_http_infere_estabelecimento_actual_e_regista_autoria` continua a falhar (POST não envia `etapa_ensino`, Action não persiste — resolvido na Task 3).

- [ ] **Step 7: Commit**

```bash
git add Modules/Turma/database/migrations/2026_09_11_100000_add_etapa_ensino_to_niveis_academicos_table.php \
        Modules/Turma/app/Models/NivelAcademico.php \
        Modules/Turma/tests/Feature/TurmaHttpTest.php \
        Modules/PlanoCurricular/tests/Feature
git commit -m "feat(turma): adiciona etapa_ensino a NivelAcademico"
```

---

## Task 3: `NivelAcademico` — DTO, Requests, Actions

**Files:**
- Modify: `Modules/Turma/app/DTO/NivelAcademicoDTO.php`
- Modify: `Modules/Turma/app/Http/Requests/CriarNivelAcademicoRequest.php`
- Modify: `Modules/Turma/app/Http/Requests/AtualizarNivelAcademicoRequest.php`
- Modify: `Modules/Turma/app/Actions/CriarNivelAcademicoAction.php`
- Modify: `Modules/Turma/app/Actions/AtualizarNivelAcademicoAction.php`
- Modify: `Modules/Turma/tests/Feature/TurmaHttpTest.php`

**Interfaces:**
- Consumes: `Modules\Estabelecimento\Enums\EtapaEnsinoEnum` (Task 1), coluna `etapa_ensino` (Task 2).
- Produces: `NivelAcademicoDTO::$etapa_ensino` (`EtapaEnsinoEnum`) — consumido pelas Actions desta task.

- [ ] **Step 1: Escrever/ajustar os testes (falham nesta fase)**

Em `Modules/Turma/tests/Feature/TurmaHttpTest.php`, editar `test_cria_nivel_academico_via_http_infere_estabelecimento_actual_e_regista_autoria`:

```php
    public function test_cria_nivel_academico_via_http_infere_estabelecimento_actual_e_regista_autoria(): void
    {
        $staff = $this->actingAsStaff();
        $this->criarEstabelecimento();

        $this->post(route('niveis-academicos.store'), [
            'codigo' => '1C',
            'nome' => '1ª Classe',
            'ordem' => 1,
            'etapa_ensino' => EtapaEnsinoEnum::PRIMARIO->value,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $nivel = NivelAcademico::firstWhere('codigo', '1C');
        $this->assertNotNull($nivel);
        $this->assertSame($staff->id, $nivel->criado_por);
        $this->assertSame(Estabelecimento::current()->id, $nivel->estabelecimento_id);
        $this->assertSame(1, $nivel->estado);
        $this->assertSame('Ativo', $nivel->estado_descricao);
        $this->assertSame(EtapaEnsinoEnum::PRIMARIO, $nivel->etapa_ensino);
        $this->assertSame('Ensino Primário', $nivel->etapa_ensino_descricao);
    }
```

Adicionar dois testes novos, logo a seguir:

```php
    public function test_criar_nivel_academico_sem_etapa_ensino_falha_com_erro_de_validacao(): void
    {
        $this->actingAsStaff();
        $this->criarEstabelecimento();

        $this->post(route('niveis-academicos.store'), [
            'codigo' => '1C',
            'nome' => '1ª Classe',
            'ordem' => 1,
        ])->assertSessionHasErrors('etapa_ensino');
    }

    public function test_criar_nivel_academico_com_etapa_ensino_invalida_falha_com_erro_de_validacao(): void
    {
        $this->actingAsStaff();
        $this->criarEstabelecimento();

        $this->post(route('niveis-academicos.store'), [
            'codigo' => '1C',
            'nome' => '1ª Classe',
            'ordem' => 1,
            'etapa_ensino' => 99,
        ])->assertSessionHasErrors('etapa_ensino');
    }
```

- [ ] **Step 2: Correr e confirmar que falham**

```bash
php artisan test --filter=TurmaHttpTest
```

Esperado: `test_cria_nivel_academico_via_http_...` falha (assert de `etapa_ensino`/`etapa_ensino_descricao` — a Action ainda não persiste o campo). `test_criar_nivel_academico_sem_etapa_ensino_falha_com_erro_de_validacao` e a variante inválida também falham, porque a Request ainda não valida `etapa_ensino` (o POST passa sem erros, `assertSessionHasErrors` falha).

- [ ] **Step 3: Actualizar o DTO**

`Modules/Turma/app/DTO/NivelAcademicoDTO.php`:

```php
<?php

namespace Modules\Turma\DTO;

use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Turma\Http\Requests\AtualizarNivelAcademicoRequest;
use Modules\Turma\Http\Requests\CriarNivelAcademicoRequest;

class NivelAcademicoDTO
{
    public function __construct(
        public string $codigo,
        public string $nome,
        public int $ordem,
        public EtapaEnsinoEnum $etapa_ensino,
    ) {
    }

    public static function fromCriarRequest(
        CriarNivelAcademicoRequest $request
    ): self {
        $dados = $request->validated();

        return new self(
            codigo: $dados['codigo'],
            nome: $dados['nome'],
            ordem: (int) $dados['ordem'],
            etapa_ensino: EtapaEnsinoEnum::from((int) $dados['etapa_ensino']),
        );
    }

    public static function fromAtualizarRequest(
        AtualizarNivelAcademicoRequest $request
    ): self {
        $dados = $request->validated();

        return new self(
            codigo: $dados['codigo'],
            nome: $dados['nome'],
            ordem: (int) $dados['ordem'],
            etapa_ensino: EtapaEnsinoEnum::from((int) $dados['etapa_ensino']),
        );
    }
}
```

- [ ] **Step 4: Actualizar os Form Requests**

`Modules/Turma/app/Http/Requests/CriarNivelAcademicoRequest.php` — adicionar à `rules()`:

```php
            'etapa_ensino' => 'required|integer|in:1,2,3,4,5',
```

e à `messages()`:

```php
            'etapa_ensino.required' => 'A etapa de ensino é obrigatória.',
            'etapa_ensino.in' => 'A etapa de ensino indicada é inválida.',
```

`Modules/Turma/app/Http/Requests/AtualizarNivelAcademicoRequest.php` — mesmas duas adições (`rules()` e `messages()`), idênticas.

- [ ] **Step 5: Actualizar as Actions**

`Modules/Turma/app/Actions/CriarNivelAcademicoAction.php`:

```php
<?php

namespace Modules\Turma\Actions;

use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Turma\DTO\NivelAcademicoDTO;
use Modules\Turma\Models\NivelAcademico;

class CriarNivelAcademicoAction
{
    public function executar(NivelAcademicoDTO $dto): NivelAcademico
    {
        return NivelAcademico::create([
            'estabelecimento_id' => Estabelecimento::current()?->id,
            'codigo' => $dto->codigo,
            'nome' => $dto->nome,
            'ordem' => $dto->ordem,
            'etapa_ensino' => $dto->etapa_ensino,
        ]);
    }
}
```

`Modules/Turma/app/Actions/AtualizarNivelAcademicoAction.php`:

```php
<?php

namespace Modules\Turma\Actions;

use Modules\Turma\DTO\NivelAcademicoDTO;
use Modules\Turma\Models\NivelAcademico;

class AtualizarNivelAcademicoAction
{
    public function executar(
        NivelAcademico $nivelAcademico,
        NivelAcademicoDTO $dto
    ): NivelAcademico {
        $nivelAcademico->fill([
            'codigo' => $dto->codigo,
            'nome' => $dto->nome,
            'ordem' => $dto->ordem,
            'etapa_ensino' => $dto->etapa_ensino,
        ]);

        $nivelAcademico->save();

        return $nivelAcademico->fresh();
    }
}
```

- [ ] **Step 6: Correr toda a suite de Turma e confirmar que passa**

```bash
php artisan test --filter=TurmaHttpTest
```

Esperado: PASS (todos os testes, incluindo os 3 novos/ajustados).

- [ ] **Step 7: Commit**

```bash
git add Modules/Turma/app/DTO/NivelAcademicoDTO.php \
        Modules/Turma/app/Http/Requests/CriarNivelAcademicoRequest.php \
        Modules/Turma/app/Http/Requests/AtualizarNivelAcademicoRequest.php \
        Modules/Turma/app/Actions/CriarNivelAcademicoAction.php \
        Modules/Turma/app/Actions/AtualizarNivelAcademicoAction.php \
        Modules/Turma/tests/Feature/TurmaHttpTest.php
git commit -m "feat(turma): valida e persiste etapa_ensino em NivelAcademico"
```

---

## Task 4: `Turma.curso_id` deixa de ser sempre obrigatório

**Files:**
- Create: `Modules/Turma/database/migrations/2026_09_11_100100_make_curso_id_nullable_on_turmas_table.php`
- Modify: `Modules/Turma/app/Http/Requests/CriarTurmaRequest.php`
- Modify: `Modules/Turma/app/Http/Requests/AtualizarTurmaRequest.php`
- Modify: `Modules/Turma/app/DTO/TurmaDTO.php`
- Modify: `Modules/Turma/app/Services/TurmaConsultaService.php`
- Modify: `Modules/Turma/tests/Feature/TurmaHttpTest.php`

**Interfaces:**
- Consumes: `NivelAcademico::$etapa_ensino` (Task 2/3), `EtapaEnsinoEnum::exigeCurso()` (Task 1).
- Produces: `turmas.curso_id` nullable; `TurmaConsultaService::opcoesFormulario()['niveisAcademicos']` passa a incluir `etapa_ensino` por item — consumido pelo frontend na Task 6.

- [ ] **Step 1: Escrever os testes novos (falham — curso_id ainda obrigatório sempre)**

Adicionar a `Modules/Turma/tests/Feature/TurmaHttpTest.php`, a seguir a `test_editar_turma_sem_curso_id_falha_com_erro_de_validacao`:

```php
    public function test_criar_turma_sem_curso_id_e_permitido_quando_nivel_nao_exige_curso(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento, EtapaEnsinoEnum::PRIMARIO);

        $this->post(route('turmas.store'), [
            'ano_lectivo_id' => $anoLectivo->id,
            'nivel_academico_id' => $nivel->id,
            'codigo' => 'T1',
            'nome' => 'Turma 1',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $turma = Turma::firstWhere('codigo', 'T1');
        $this->assertNotNull($turma);
        $this->assertNull($turma->curso_id);
    }

    public function test_editar_turma_sem_curso_id_e_permitido_quando_nivel_nao_exige_curso(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $anoLectivo = $this->criarAnoLectivo($estabelecimento);
        $nivel = $this->criarNivelAcademico($estabelecimento, EtapaEnsinoEnum::PRIMARIO);
        $curso = $this->criarCurso($estabelecimento);
        $turma = Turma::create(['ano_lectivo_id' => $anoLectivo->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'codigo' => 'T1', 'nome' => 'Turma 1']);

        $this->put(route('turmas.update', $turma), [
            'nivel_academico_id' => $nivel->id,
            'codigo' => 'T1',
            'nome' => 'Turma 1',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertNull($turma->fresh()->curso_id);
    }
```

(Os dois testes já existentes, `test_criar_turma_sem_curso_id_falha_com_erro_de_validacao` e `test_editar_turma_sem_curso_id_falha_com_erro_de_validacao`, usam `$this->criarNivelAcademico($estabelecimento)` desde a Task 2 — que por omissão cria um nível de etapa `SECUNDARIO`. Não precisam de nenhuma alteração: continuam a validar que, para uma etapa que exige curso, `curso_id` continua obrigatório.)

- [ ] **Step 2: Correr e confirmar que os dois testes novos falham**

```bash
php artisan test --filter=TurmaHttpTest
```

Esperado: FAIL nos dois testes novos — `curso_id` continua `required` incondicional (Request actual) e `NOT NULL` (BD).

- [ ] **Step 3: Criar a migração**

`Modules/Turma/database/migrations/2026_09_11_100100_make_curso_id_nullable_on_turmas_table.php`:

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
            $table->foreignId('curso_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('turmas', function (Blueprint $table) {
            $table->foreignId('curso_id')->nullable(false)->change();
        });
    }
};
```

- [ ] **Step 4: Actualizar `CriarTurmaRequest` e `AtualizarTurmaRequest`**

`Modules/Turma/app/Http/Requests/CriarTurmaRequest.php`:

```php
<?php

namespace Modules\Turma\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Turma\Models\NivelAcademico;

class CriarTurmaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('turmas.criar') ?? false;
    }

    public function rules(): array
    {
        return [
            'ano_lectivo_id' => 'required|integer|exists:ano_lectivos,id',
            'nivel_academico_id' => 'required|integer|exists:niveis_academicos,id',
            'curso_id' => [
                Rule::requiredIf(function () {
                    $nivel = NivelAcademico::find($this->input('nivel_academico_id'));

                    return $nivel && $nivel->etapa_ensino->exigeCurso();
                }),
                'nullable',
                'integer',
                'exists:cursos,id',
            ],
            'codigo' => 'required|string|max:50',
            'nome' => 'required|string|max:255',
            'turno_id' => 'nullable|integer|exists:turnos,id',
        ];
    }

    public function messages(): array
    {
        return [
            'ano_lectivo_id.required' => 'O ano lectivo é obrigatório.',
            'ano_lectivo_id.exists' => 'O ano lectivo indicado não existe.',

            'nivel_academico_id.required' => 'O nível académico é obrigatório.',
            'nivel_academico_id.exists' => 'O nível académico indicado não existe.',

            'curso_id.required' => 'O curso é obrigatório.',
            'curso_id.exists' => 'O curso indicado não existe.',

            'codigo.required' => 'O código da turma é obrigatório.',
            'codigo.max' => 'O código da turma não pode ultrapassar 50 caracteres.',

            'nome.required' => 'O nome da turma é obrigatório.',
            'nome.max' => 'O nome da turma não pode ultrapassar 255 caracteres.',

            'turno_id.exists' => 'O turno indicado não existe.',
        ];
    }
}
```

`Modules/Turma/app/Http/Requests/AtualizarTurmaRequest.php`:

```php
<?php

namespace Modules\Turma\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Turma\Models\NivelAcademico;

class AtualizarTurmaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('turmas.editar') ?? false;
    }

    public function rules(): array
    {
        return [
            'nivel_academico_id' => 'required|integer|exists:niveis_academicos,id',
            'curso_id' => [
                Rule::requiredIf(function () {
                    $nivel = NivelAcademico::find($this->input('nivel_academico_id'));

                    return $nivel && $nivel->etapa_ensino->exigeCurso();
                }),
                'nullable',
                'integer',
                'exists:cursos,id',
            ],
            'codigo' => 'required|string|max:50',
            'nome' => 'required|string|max:255',
            'turno_id' => 'nullable|integer|exists:turnos,id',
        ];
    }

    public function messages(): array
    {
        return [
            'nivel_academico_id.required' => 'O nível académico é obrigatório.',
            'nivel_academico_id.exists' => 'O nível académico indicado não existe.',

            'curso_id.required' => 'O curso é obrigatório.',
            'curso_id.exists' => 'O curso indicado não existe.',

            'codigo.required' => 'O código da turma é obrigatório.',
            'codigo.max' => 'O código da turma não pode ultrapassar 50 caracteres.',

            'nome.required' => 'O nome da turma é obrigatório.',
            'nome.max' => 'O nome da turma não pode ultrapassar 255 caracteres.',

            'turno_id.exists' => 'O turno indicado não existe.',
        ];
    }
}
```

- [ ] **Step 5: Actualizar `TurmaDTO`**

Em `Modules/Turma/app/DTO/TurmaDTO.php`, alterar `fromCriarRequest` e `fromAtualizarRequest` — trocar:

```php
            curso_id: (int) $dados['curso_id'],
```

por, em ambos os métodos:

```php
            curso_id: isset($dados['curso_id']) ? (int) $dados['curso_id'] : null,
```

(A assinatura do construtor já é `public ?int $curso_id = null` — não muda.)

- [ ] **Step 6: Actualizar `TurmaConsultaService::opcoesFormulario()`**

Em `Modules/Turma/app/Services/TurmaConsultaService.php`, trocar:

```php
            'niveisAcademicos' => NivelAcademico::where('estabelecimento_id', $estabelecimentoId)->where('estado',1)->orderBy('ordem')->get(['id', 'nome']),
```

por:

```php
            'niveisAcademicos' => NivelAcademico::where('estabelecimento_id', $estabelecimentoId)->where('estado',1)->orderBy('ordem')->get(['id', 'nome', 'etapa_ensino']),
```

- [ ] **Step 7: Correr toda a suite de Turma e confirmar que passa**

```bash
php artisan test --filter=TurmaHttpTest
```

Esperado: PASS — incluindo os dois testes novos e os dois testes de "sem curso_id falha" (que continuam a falhar-como-esperado por usarem um nível `SECUNDARIO`).

- [ ] **Step 8: Commit**

```bash
git add Modules/Turma/database/migrations/2026_09_11_100100_make_curso_id_nullable_on_turmas_table.php \
        Modules/Turma/app/Http/Requests/CriarTurmaRequest.php \
        Modules/Turma/app/Http/Requests/AtualizarTurmaRequest.php \
        Modules/Turma/app/DTO/TurmaDTO.php \
        Modules/Turma/app/Services/TurmaConsultaService.php \
        Modules/Turma/tests/Feature/TurmaHttpTest.php
git commit -m "feat(turma): curso_id deixa de ser obrigatório quando a etapa de ensino não exige curso"
```

---

## Task 5: Frontend — `NivelAcademico` ganha Etapa de Ensino

**Files:**
- Modify: `Modules/Turma/resources/js/Components/NivelAcademico/NivelAcademicoFormModal.vue`
- Modify: `Modules/Turma/resources/js/Pages/NiveisAcademicos/Index.vue`

**Interfaces:**
- Consumes: rota `niveis-academicos.store`/`update` já validam/persistem `etapa_ensino` (Task 3).

- [ ] **Step 1: Adicionar o select de Etapa de Ensino ao modal**

Editar `Modules/Turma/resources/js/Components/NivelAcademico/NivelAcademicoFormModal.vue`. Acrescentar a constante de opções, a seguir a `ESTADO_OPCOES`:

```js
const ETAPA_ENSINO_OPCOES = [
    { value: 1, label: 'Creche' },
    { value: 2, label: 'Pré-Escolar' },
    { value: 3, label: 'Ensino Primário' },
    { value: 4, label: 'Ensino Secundário' },
    { value: 5, label: 'Ensino Superior' },
];
```

Acrescentar `etapa_ensino: 1` ao `reactive(form)` inicial:

```js
const form = reactive({
    codigo: '',
    nome: '',
    ordem: 1,
    etapa_ensino: 1,
    estado: ESTADO.ATIVO,
});
```

No `watch(() => props.show, ...)`, acrescentar:

```js
    form.etapa_ensino = props.nivelAcademico?.etapa_ensino ?? 1;
```

No template, acrescentar o select logo a seguir ao campo "Nome" (antes do bloco `v-if="nivelAcademico"` do Estado):

```html
                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Etapa de Ensino</label>
                        <SelectSolid v-model="form.etapa_ensino" :options="ETAPA_ENSINO_OPCOES" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.etapa_ensino">{{ errors.etapa_ensino }}</div>
                    </div>
```

- [ ] **Step 2: Mostrar a etapa na listagem**

Editar `Modules/Turma/resources/js/Pages/NiveisAcademicos/Index.vue`. No `<thead>`, acrescentar uma coluna entre "Nome" e "Estado":

```html
                            <th class="min-w-150px">Etapa</th>
```

No `<tbody>`, acrescentar a célula correspondente entre a de "Nome" e a de "Estado":

```html
                            <td>{{ nivel.etapa_ensino_descricao }}</td>
```

Ajustar o `colspan` da linha "Nenhum nível académico criado." de `5` para `6`.

- [ ] **Step 3: Build e verificação manual**

```bash
npm run build
```

Confirmar que compila sem erros. Com a app a correr, login como ADMIN_ESCOLA e verificar:
1. Criar um Nível Académico exige seleccionar uma Etapa de Ensino (submeter sem seleccionar mostra erro).
2. A listagem mostra a coluna "Etapa" com o texto correcto (ex.: "Ensino Primário").
3. Editar um nível académico existente pré-selecciona a etapa actual.

- [ ] **Step 4: Commit**

```bash
git add Modules/Turma/resources/js/Components/NivelAcademico/NivelAcademicoFormModal.vue \
        Modules/Turma/resources/js/Pages/NiveisAcademicos/Index.vue
git commit -m "feat(turma): frontend de Etapa de Ensino em Nível Académico"
```

---

## Task 6: Frontend — Turma esconde Curso quando a etapa não exige

**Files:**
- Modify: `Modules/Turma/resources/js/Components/Turma/TurmaFormModal.vue`

**Interfaces:**
- Consumes: `niveisAcademicos` (prop já existente) agora com `etapa_ensino` por item (Task 4).

- [ ] **Step 1: Adicionar a lógica condicional**

Editar `Modules/Turma/resources/js/Components/Turma/TurmaFormModal.vue`. Importar `computed` a par de `reactive, watch`:

```js
import { reactive, watch, computed } from 'vue';
```

Acrescentar, a seguir às constantes `opcoes*`:

```js
// Espelha EtapaEnsinoEnum::exigeCurso() (Modules/Estabelecimento/app/Enums/EtapaEnsinoEnum.php):
// só Secundário (4) e Superior (5) exigem curso.
const ETAPAS_QUE_EXIGEM_CURSO = [4, 5];

const nivelExigeCurso = computed(() => {
    const nivel = props.niveisAcademicos.find((n) => n.id === form.nivel_academico_id);
    return nivel ? ETAPAS_QUE_EXIGEM_CURSO.includes(nivel.etapa_ensino) : false;
});
```

Acrescentar um `watch` sobre `nivelExigeCurso` que limpa `curso_id` quando deixa de se aplicar (logo a seguir ao `watch(() => props.show, ...)` existente):

```js
watch(nivelExigeCurso, (exige) => {
    if (!exige) form.curso_id = '';
});
```

- [ ] **Step 2: Esconder o select de Curso condicionalmente**

No template, envolver o bloco do select "Curso" (`<div class="col-md-6 fv-row mb-7">` que contém o label "Curso") com `v-if="nivelExigeCurso"`:

```html
                        <div v-if="nivelExigeCurso" class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Curso</label>
                            <SelectSolid v-model="form.curso_id" :options="opcoesCurso()" placeholder="Selecione o curso" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.curso_id">{{ errors.curso_id }}</div>
                        </div>
```

- [ ] **Step 3: Garantir que `submeter()` não envia um `curso_id` obsoleto**

Confirmar que `submeter()` continua a enviar `form.curso_id` tal como está (já fica `''` pelo `watch` acima quando a etapa não exige) — nenhuma alteração adicional necessária em `submeter()`.

- [ ] **Step 4: Build e verificação manual**

```bash
npm run build
```

Com a app a correr:
1. Criar Turma com um Nível Académico de etapa Primário/Creche/Pré-Escolar — o select "Curso" não aparece, e a submissão sem curso é aceite.
2. Trocar o Nível Académico seleccionado de um que exige curso para um que não exige — o select "Curso" desaparece e `curso_id` é limpo.
3. Criar Turma com um Nível Académico de etapa Secundário/Superior — o select "Curso" aparece e continua obrigatório.

- [ ] **Step 5: Commit**

```bash
git add Modules/Turma/resources/js/Components/Turma/TurmaFormModal.vue
git commit -m "feat(turma): esconde o select de Curso quando a etapa de ensino não o exige"
```

---

## Task 7: `NivelAcademicoConsultaService::etapasEnsino()`

Resolve o ponto em aberto da spec: consulta agregada das etapas em uso num estabelecimento, sem pivot nem coluna nova — fica no módulo Turma (não em `Estabelecimento`, para não introduzir a dependência invertida Estabelecimento→Turma).

**Files:**
- Modify: `Modules/Turma/app/Services/NivelAcademicoConsultaService.php`
- Create: `Modules/Turma/tests/Feature/NivelAcademicoConsultaServiceTest.php`

**Interfaces:**
- Produces: `NivelAcademicoConsultaService::etapasEnsino(?int $estabelecimentoId = null): \Illuminate\Support\Collection<int, EtapaEnsinoEnum>`.

- [ ] **Step 1: Escrever o teste (falha — método não existe)**

`Modules/Turma/tests/Feature/NivelAcademicoConsultaServiceTest.php`:

```php
<?php

namespace Modules\Turma\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Services\NivelAcademicoConsultaService;
use Tests\TestCase;

class NivelAcademicoConsultaServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_etapas_ensino_devolve_etapas_distintas_dos_niveis_do_estabelecimento(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Mista', 'tipo' => 1, 'is_active' => true]);

        NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'CR', 'nome' => 'Creche', 'ordem' => 1, 'etapa_ensino' => EtapaEnsinoEnum::CRECHE]);
        NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 2, 'etapa_ensino' => EtapaEnsinoEnum::PRIMARIO]);
        NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => '2C', 'nome' => '2ª Classe', 'ordem' => 3, 'etapa_ensino' => EtapaEnsinoEnum::PRIMARIO]);

        $etapas = (new NivelAcademicoConsultaService())->etapasEnsino($estabelecimento->id);

        $this->assertCount(2, $etapas);
        $this->assertTrue($etapas->contains(EtapaEnsinoEnum::CRECHE));
        $this->assertTrue($etapas->contains(EtapaEnsinoEnum::PRIMARIO));
    }

    public function test_etapas_ensino_nao_mistura_estabelecimentos_diferentes(): void
    {
        $estabelecimentoA = Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'is_active' => true]);
        $estabelecimentoB = Estabelecimento::create(['nome' => 'Escola B', 'tipo' => 1, 'is_active' => false]);

        NivelAcademico::create(['estabelecimento_id' => $estabelecimentoA->id, 'codigo' => '1C', 'nome' => '1ª Classe', 'ordem' => 1, 'etapa_ensino' => EtapaEnsinoEnum::PRIMARIO]);
        NivelAcademico::create(['estabelecimento_id' => $estabelecimentoB->id, 'codigo' => 'SU', 'nome' => 'Licenciatura', 'ordem' => 1, 'etapa_ensino' => EtapaEnsinoEnum::SUPERIOR]);

        $etapas = (new NivelAcademicoConsultaService())->etapasEnsino($estabelecimentoA->id);

        $this->assertCount(1, $etapas);
        $this->assertTrue($etapas->contains(EtapaEnsinoEnum::PRIMARIO));
    }
}
```

- [ ] **Step 2: Correr e confirmar que falha**

```bash
php artisan test --filter=NivelAcademicoConsultaServiceTest
```

Esperado: FAIL — `Call to undefined method ...::etapasEnsino()`.

- [ ] **Step 3: Implementar o método**

Editar `Modules/Turma/app/Services/NivelAcademicoConsultaService.php`:

```php
<?php

namespace Modules\Turma\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Turma\Models\NivelAcademico;

class NivelAcademicoConsultaService
{
    public function listar(): Collection
    {
        return NivelAcademico::where('estabelecimento_id', Estabelecimento::current()?->id)
            ->orderBy('ordem')
            ->get();
    }

    public function etapasEnsino(?int $estabelecimentoId = null): SupportCollection
    {
        return NivelAcademico::where('estabelecimento_id', $estabelecimentoId ?? Estabelecimento::current()?->id)
            ->get()
            ->pluck('etapa_ensino')
            ->unique()
            ->values();
    }
}
```

(`pluck('etapa_ensino')` sobre a `Collection` de models — não sobre uma query `select` crua — para que o cast `EtapaEnsinoEnum` já esteja aplicado a cada valor antes do `unique()`; `unique()` num array de enums funciona correctamente porque `unique()` do Support Collection compara por `==`/valor, e `BackedEnum` compara igual quando é a mesma instância de case.)

- [ ] **Step 4: Correr e confirmar que passa**

```bash
php artisan test --filter=NivelAcademicoConsultaServiceTest
```

Esperado: PASS (2 testes).

- [ ] **Step 5: Commit**

```bash
git add Modules/Turma/app/Services/NivelAcademicoConsultaService.php Modules/Turma/tests/Feature/NivelAcademicoConsultaServiceTest.php
git commit -m "feat(turma): NivelAcademicoConsultaService::etapasEnsino()"
```

---

## Task 8: Verificação final

**Files:** nenhum (apenas execução).

- [ ] **Step 1: Correr toda a suite de testes do projecto**

```bash
php artisan test
```

Esperado: PASS em toda a suite — nenhuma regressão em `Estabelecimento`, `Turma`, `Curso`, `PlanoCurricular`, `AnoLectivo`, `Permissao`, `Usuario`, etc.

- [ ] **Step 2: Build final do frontend**

```bash
npm run build
```

Esperado: compila sem erros nem avisos novos.

- [ ] **Step 3: Verificação manual do cenário "instituição mista" ponta-a-ponta**

Com a app a correr, login como ADMIN_ESCOLA:
1. Criar um Nível Académico de etapa Creche (ex.: código `CR`, nome "Creche", ordem 1).
2. Criar um Ano Lectivo (se ainda não existir).
3. Criar uma Turma para esse nível — confirmar que o select "Curso" não aparece e a Turma é criada sem curso.
4. Criar um segundo Nível Académico de etapa Secundário (ex.: código `10C`, nome "10ª Classe", ordem 10).
5. Criar uma Turma para esse nível — confirmar que o select "Curso" aparece e é obrigatório.
6. Consultar a listagem de Turmas — confirmar que a Turma de Creche mostra "—" (ou vazio) na coluna de Curso, e a de 10ª Classe mostra o curso escolhido.

Não há passo de "commit" nesta task — é só verificação. Se algum passo falhar, voltar à task correspondente, corrigir, e repetir a Task 8 do início.

## Auto-review

- **Cobertura da spec:** Parte A (enum) → Task 1. Parte B (NivelAcademico + FK etapa) → Tasks 2-3. Parte C (curso_id condicional) → Task 4. Parte D (frontend) → Tasks 5-6. Ponto em aberto (`etapasEnsino()`) → Task 7, resolvido a favor do `NivelAcademicoConsultaService` (evita a dependência invertida Estabelecimento→Turma identificada na spec). Dados/migrações existentes (`tipo_ensino` intocado) → nenhuma task o toca, conforme pretendido.
- **Placeholders:** nenhum "TBD" — todos os valores (mapeamento etapa→exige curso, nomes de colunas, mensagens) são concretos. A lista de 12 ficheiros PlanoCurricular na Task 2 é exaustiva (confirmada por `grep` antes de escrever o plano).
- **Consistência de tipos:** `NivelAcademicoDTO::$etapa_ensino` (Task 3) é sempre `EtapaEnsinoEnum`, nunca `int`, em todas as camadas (Model cast, Action, DTO). `TurmaDTO::$curso_id` mantém-se `?int` (já era nullable na assinatura; só o preenchimento muda). `NivelAcademicoConsultaService::etapasEnsino()` devolve `Illuminate\Support\Collection<EtapaEnsinoEnum>`, consistente com os testes da Task 7.
- **Escopo:** 8 tasks, todas dentro da spec aprovada. Nenhuma introduz Matrícula, Disciplina, pivot Estabelecimento↔Etapa, ou coluna `exige_curso` (explicitamente excluída pelo utilizador).
