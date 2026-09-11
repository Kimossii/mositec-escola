# Módulo PlanoCurricular — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Criar o módulo `Modules/PlanoCurricular`, que define a estrutura curricular (disciplinas por nível académico, com carga horária/créditos/componente/tipo/obrigatoriedade/ordem) de um `Curso`, reutilizável para Ensino Geral, Técnico e Universitário, e a sua confirmação/reconfirmação ao longo dos anos lectivos — mais a adição de `tipo_ensino` ao `Estabelecimento`.

**Architecture:** Segue byte-a-byte o padrão já estabelecido em `Modules/Curso`/`Modules/Disciplina`/`Modules/Turma`/`Modules/AnoLectivo`: Migration → Model (traits `RegistaAutoria`+`SincronizaEstadoDescricao` do `Modules/Core`) → DTO → Action (`executar()`) → Service (`Gestao*Service` agrega Actions; `*ConsultaService` só leitura) → FormRequest (`extends App\Http\Requests\BaseRequest`, autoriza via `$this->user()?->can(...)`) → Controller fino (Inertia, `$this->authorize()`) → `routes/web.php` (middleware `can:modulo.acao` por rota) → Vue Pages/Components → Tests Feature. Sem Repository, sem Policy — tudo passa por `Gate::before` + `PermissionResolver` (`Modules/Permissao`). Sem tenancy real: isolamento por estabelecimento é sempre manual via `Estabelecimento::current()?->id`, nunca um global scope.

**Tech Stack:** Laravel + `nwidart/laravel-modules`, Inertia.js + Vue 3, MySQL/SQLite (testes), Pest/PHPUnit (`RefreshDatabase`).

**Spec:** Especificação completa fornecida pelo utilizador na conversa de 2026-09-10 (ver mensagem inicial desta sessão — não há ficheiro de spec em disco; este plano incorpora-a integralmente).

## Global Constraints

- PT-PT em toda a interface, mensagens de validação e labels.
- Não criar tabelas/módulos diferentes por tipo de ensino (geral/técnico/universitário) — uma só estrutura.
- Não duplicar `Disciplina`, `NivelAcademico` (`Modules\Turma\Models\NivelAcademico`) nem `AnoLectivo`/`Periodo` (`Modules\AnoLectivo`).
- **Não** adicionar `semestre` a `plano_curricular_disciplinas`.
- **Não** adicionar `ano_lectivo_id`, `modalidade` ou `tipo_ensino` a `planos_curriculares`.
- Não implementar Matrícula, Frequência, Avaliações, Notas, Professores, Encarregados, nem Pré-requisitos nesta fase.
- Não alterar a lógica de `Turma` além de nada (nenhuma alteração prevista neste plano).
- Sem Policy própria — usar `can:<modulo>.<acao>` nas rotas e `$this->authorize('<modulo>.<acao>')` no Controller.
- Não criar commits automaticamente (deixar tudo staged/working tree).
- Permissões do módulo: apenas `plano-curricular.ver`, `plano-curricular.criar`, `plano-curricular.editar` (sem `.eliminar` — spec não pede).

---

## Decisões arquitecturais tomadas nesta análise (a validar com o utilizador antes de codar)

Estas são as questões que a spec pediu explicitamente para "apresentar antes de implementar". Resolvidas por analogia estrita com o código actual — sinalizadas para confirmação porque envolvem escolha, não são 100% mecânicas.

1. **`tipo_ensino` deve ser um enum `int`-backed (1/2/3), não string.** Motivo técnico, não só estilístico: `Modules/Estabelecimento/resources/js/Components/CampoFicha.vue:56` faz `@change="$emit('update:modelValue', Number($event.target.value))"` no `<select>` — um enum string quebraria esse componente partilhado (usado também por `tipo`). Replicar exactamente `TipoEstabelecimentoEnum` (`Modules/Estabelecimento/app/Enums/TipoEstabelecimentoEnum.php`): `GERAL=1`, `TECNICO=2`, `UNIVERSITARIO=3`.
2. **Constraint de unicidade em `plano_curricular_disciplinas`: `unique(plano_curricular_id, disciplina_id, nivel_academico_id)` é suficiente.** Não há, dentro do escopo actual do módulo (sem turnos/faseamento/período), nenhum cenário legítimo em que a mesma `Disciplina` apareça duas vezes no mesmo `NivelAcademico` do mesmo plano — "Estágio I"/"Estágio II" seriam duas `Disciplina`s distintas na tabela `disciplinas`, não a mesma disciplina repetida. Não adicionar coluna extra só para "distinguir" duplicados.
3. **`planos_curriculares` só tem `unique(estabelecimento_id, codigo)`, sem unicidade de `nome`.** A spec pede explicitamente "código único dentro do estabelecimento" e nada sobre nome único — diferente de `Curso`/`Disciplina`/`NivelAcademico`, que têm ambos. Não inventar a constraint de nome (o mesmo nome de plano pode legitimamente repetir-se entre cursos diferentes, ou como rascunho "Plano A (cópia)").
4. **`plano_curricular_disciplinas` e `plano_curricular_anos_lectivos` não têm `estabelecimento_id` próprio** — herdam-no via `plano_curricular_id` (mesmo padrão de `periodos` que não duplica `estabelecimento_id`, herdando de `ano_lectivo_id`). O isolamento cross-entidade (impedir associar `disciplina_id`/`nivel_academico_id`/`ano_lectivo_id`/`curso_id` de outro estabelecimento) é feito explicitamente nas FormRequests via `Rule::exists(...)->where(...)`, replicando o padrão de `Rule::unique(...)->where(fn ($q) => $q->where('estabelecimento_id', Estabelecimento::current()?->id))` já usado em `Curso`/`Disciplina`.
5. **Imutabilidade histórica de um plano confirmado não é bloqueada tecnicamente nesta fase.** A spec pede "não deve ser alterado retroactivamente" mas também pede explicitamente para não introduzir versionamento complexo. Interpretação: é uma convenção de processo (documentada na UI/manual), não uma trava de escrita na BD — não vou impedir a edição de um `PlanoCurricular` já confirmado para um ano lectivo. **Sinalizado para confirmação** porque é a decisão mais discutível do plano.
6. **Clonagem de plano ("Plano A → Duplicar → Plano B") não é implementada nesta primeira versão.** Descrita na Task 18 como extensão opcional, com desenho mínimo (uma Action `DuplicarPlanoCurricularAction` que copia `planos_curriculares` + todas as `plano_curricular_disciplinas`, sem copiar `plano_curricular_anos_lectivos`), mas fora do escopo de implementação a não ser que confirmes que a queres já.
7. **Permissão do módulo é única para o módulo inteiro** (`plano-curricular.*`), cobrindo tanto o CRUD do plano como a gestão das suas disciplinas e a confirmação por ano lectivo — replica o padrão de `Infraestrutura` (uma permissão por módulo, não por sub-recurso).
8. **FK deletes:** `plano_curricular_disciplinas.plano_curricular_id` usa `cascadeOnDelete()` (é claramente filha exclusiva do plano, como `periodos.ano_lectivo_id`); `disciplina_id`/`nivel_academico_id` usam `restrictOnDelete()` (entidades partilhadas, não apagáveis com uso activo, como em `turmas`). `plano_curricular_anos_lectivos` usa `restrictOnDelete()` em ambas as FKs (registo de confirmação histórica — não deve desaparecer por cascata).

---

## Task 1 — `tipo_ensino` no Estabelecimento

**Files:**
- Create: `Modules/Estabelecimento/database/migrations/2026_09_10_090000_add_tipo_ensino_to_estabelecimentos_table.php`
- Create: `Modules/Estabelecimento/app/Enums/TipoEnsinoEnum.php`
- Modify: `Modules/Estabelecimento/app/Models/Estabelecimento.php`
- Modify: `Modules/Estabelecimento/app/DTO/EstabelecimentoDTO.php`
- Modify: `Modules/Estabelecimento/app/Http/Requests/AtualizarDadosRequest.php`
- Modify: `Modules/Estabelecimento/app/Actions/AtualizarDadosEstabelecimentoAction.php`
- Modify: `Modules/Estabelecimento/resources/js/Pages/DadosDaEscola.vue`
- Modify: `Modules/Estabelecimento/tests/Feature/GestaoEstabelecimentoTest.php`
- Create: `Modules/Estabelecimento/tests/Unit/TipoEnsinoEnumTest.php`

**Interfaces:**
- Produces: `Modules\Estabelecimento\Enums\TipoEnsinoEnum` (`GERAL=1`, `TECNICO=2`, `UNIVERSITARIO=3`, método `label(): string`) — consumido futuramente por `PlanoCurricular` para adaptar comportamento via `Estabelecimento::current()?->tipo_ensino`.

- [ ] **Step 1: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('estabelecimentos', function (Blueprint $table) {
            $table->unsignedTinyInteger('tipo_ensino')->default(1)->after('tipo');
            $table->string('tipo_ensino_descricao')->default('Ensino Geral')->after('tipo_ensino');
        });
    }

    public function down(): void
    {
        Schema::table('estabelecimentos', function (Blueprint $table) {
            $table->dropColumn(['tipo_ensino', 'tipo_ensino_descricao']);
        });
    }
};
```

- [ ] **Step 2: Enum**

```php
<?php

namespace Modules\Estabelecimento\Enums;

enum TipoEnsinoEnum: int
{
    case GERAL = 1;
    case TECNICO = 2;
    case UNIVERSITARIO = 3;

    public function label(): string
    {
        return match ($this) {
            self::GERAL => 'Ensino Geral',
            self::TECNICO => 'Ensino Técnico',
            self::UNIVERSITARIO => 'Ensino Universitário',
        };
    }
}
```

- [ ] **Step 3: Unit test do enum (escrever antes do passo seguinte falhar/passar não se aplica a enum puro — escrever e correr)**

`Modules/Estabelecimento/tests/Unit/TipoEnsinoEnumTest.php`:
```php
<?php

namespace Modules\Estabelecimento\Tests\Unit;

use Modules\Estabelecimento\Enums\TipoEnsinoEnum;
use Tests\TestCase;

class TipoEnsinoEnumTest extends TestCase
{
    public function test_labels(): void
    {
        $this->assertSame('Ensino Geral', TipoEnsinoEnum::GERAL->label());
        $this->assertSame('Ensino Técnico', TipoEnsinoEnum::TECNICO->label());
        $this->assertSame('Ensino Universitário', TipoEnsinoEnum::UNIVERSITARIO->label());
    }

    public function test_from_valores_validos(): void
    {
        $this->assertSame(TipoEnsinoEnum::GERAL, TipoEnsinoEnum::from(1));
        $this->assertSame(TipoEnsinoEnum::TECNICO, TipoEnsinoEnum::from(2));
        $this->assertSame(TipoEnsinoEnum::UNIVERSITARIO, TipoEnsinoEnum::from(3));
    }

    public function test_valor_invalido_lanca_erro(): void
    {
        $this->expectException(\ValueError::class);
        TipoEnsinoEnum::from(99);
    }
}
```
Run: `php artisan test --filter=TipoEnsinoEnumTest` — Expected: PASS.

- [ ] **Step 4: Model — `$fillable`, `$casts`, hook `saving`**

Em `Modules/Estabelecimento/app/Models/Estabelecimento.php`, adicionar `'tipo_ensino'` a `$fillable` (logo após `'tipo'`), adicionar ao `$casts`:
```php
'tipo_ensino' => TipoEnsinoEnum::class,
```
e importar `use Modules\Estabelecimento\Enums\TipoEnsinoEnum;`. Estender o `booted()`:
```php
protected static function booted(): void
{
    static::saving(function (Estabelecimento $estabelecimento) {
        $estabelecimento->tipo_descricao = $estabelecimento->tipo?->label();
        $estabelecimento->tipo_ensino_descricao = $estabelecimento->tipo_ensino?->label();
    });
}
```

- [ ] **Step 5: DTO**

Em `EstabelecimentoDTO`, adicionar propriedade `public TipoEnsinoEnum $tipo_ensino` (logo após `tipo`, sem default — obrigatório tal como `tipo`), e em `fromRequest()`:
```php
tipo_ensino: TipoEnsinoEnum::from((int) $dados['tipo_ensino']),
```
(inserir a linha imediatamente a seguir a `tipo: TipoEstabelecimentoEnum::from(...)`).

- [ ] **Step 6: Request**

Em `AtualizarDadosRequest::rules()`, adicionar logo após `'tipo'`:
```php
'tipo_ensino' => 'required|integer|in:1,2,3',
```
Em `messages()`:
```php
'tipo_ensino.required' => 'O tipo de ensino é obrigatório.',
'tipo_ensino.in' => 'O tipo de ensino indicado é inválido.',
```

- [ ] **Step 7: Action**

Em `AtualizarDadosEstabelecimentoAction::executar()`, adicionar ao array de `fill()`:
```php
'tipo_ensino' => $dto->tipo_ensino,
```
(logo após `'tipo' => $dto->tipo`).

- [ ] **Step 8: Teste de feature — escrever falhando primeiro**

Adicionar a `Modules/Estabelecimento/tests/Feature/GestaoEstabelecimentoTest.php`:
```php
public function test_atualiza_tipo_ensino_com_valores_validos(): void
{
    $staff = User::factory()->create();
    $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);

    foreach ([1 => 'Ensino Geral', 2 => 'Ensino Técnico', 3 => 'Ensino Universitário'] as $valor => $descricao) {
        $this->actingAs($staff)->put('/estabelecimento', [
            'nome' => 'Escola Teste',
            'tipo' => 2,
            'tipo_ensino' => $valor,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('estabelecimentos', [
            'tipo_ensino' => $valor,
            'tipo_ensino_descricao' => $descricao,
        ]);
    }
}

public function test_rejeita_tipo_ensino_invalido(): void
{
    $staff = User::factory()->create();
    $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);

    $this->actingAs($staff)->put('/estabelecimento', [
        'nome' => 'Escola Teste',
        'tipo' => 2,
        'tipo_ensino' => 99,
    ])->assertSessionHasErrors('tipo_ensino');
}
```
(Ajustar imports/factories/seed de `PermissaoDatabaseSeeder` para replicar exactamente o `setUp()` já usado no resto do ficheiro.)

Run: `php artisan test --filter=GestaoEstabelecimentoTest` — Expected: FAIL antes dos steps 1-7, PASS depois.

- [ ] **Step 9: Vue — `DadosDaEscola.vue`**

Adicionar array (logo após `const tipos = [...]`):
```js
const tiposEnsino = [
    { value: 1, label: 'Ensino Geral' },
    { value: 2, label: 'Ensino Técnico' },
    { value: 3, label: 'Ensino Universitário' },
];
```
Em `snapshot()`, adicionar `tipo_ensino: props.estabelecimento?.tipo_ensino ?? 1,`.
No template, dentro do card "Identificação", junto ao campo `Tipo` (col-md-4), adicionar novo `col-md-4`:
```html
<div class="col-md-4">
    <CampoFicha
        v-model="form.tipo_ensino" label="Tipo de Ensino" type="select" :options="tiposEnsino" required
        :editing="editando" :error="errors.tipo_ensino?.[0]" icon="ki-book-open" :icon-paths="2"
    />
</div>
```

- [ ] **Step 10: Rodar suite completa do módulo e confirmar sem regressão**

Run: `php artisan test Modules/Estabelecimento` — Expected: PASS (incluindo `EstabelecimentoAutorizacaoTest`).

- [ ] **Step 11: Commit** (não executar — deixar em staged, por instrução explícita do utilizador de nunca commitar automaticamente).

**Tests:** `TipoEnsinoEnumTest` (labels, from válido/inválido), `GestaoEstabelecimentoTest::test_atualiza_tipo_ensino_com_valores_validos`, `::test_rejeita_tipo_ensino_invalido`.

**Critérios de aceitação:**
- `estabelecimentos` tem `tipo_ensino`/`tipo_ensino_descricao` sincronizados automaticamente.
- `Estabelecimento::current()?->tipo_ensino` devolve uma instância de `TipoEnsinoEnum`.
- Formulário de Estabelecimento mostra e grava o novo campo sem quebrar `CampoFicha.vue`.
- Nenhum teste existente do módulo Estabelecimento regride.

---

## Task 2 — Registar módulo `PlanoCurricular` no sistema de permissões

**Files:**
- Modify: `Modules/Permissao/app/Enums/Modulo.php`
- Modify: `Modules/Permissao/database/seeders/ModuloSeeder.php`
- Modify: `Modules/Permissao/database/seeders/RolePermissaoSeeder.php`
- Modify: `Modules/Permissao/tests/Unit/ModuloEnumTest.php` (ou equivalente já existente)
- Modify: `Modules/Permissao/tests/Feature/ModuloSeederTest.php`
- Modify: `Modules/Permissao/tests/Feature/RolePermissaoSeederTest.php`

**Interfaces:**
- Produces: ability strings `plano-curricular.ver`, `plano-curricular.criar`, `plano-curricular.editar`, reconhecidas por `PermissionResolver::reconhece()`.

- [ ] **Step 1: Enum `Modulo` — novo case**

Em `Modules/Permissao/app/Enums/Modulo.php`, adicionar:
```php
case CURSO = 13;
case PLANO_CURRICULAR = 14;
```
No `slug()`:
```php
self::CURSO => 'curso',
self::PLANO_CURRICULAR => 'plano-curricular',
```
No `label()`:
```php
self::CURSO => 'Curso',
self::PLANO_CURRICULAR => 'Plano Curricular',
```

- [ ] **Step 2: Teste do enum — escrever/estender antes de rodar**

Adicionar a `ModuloEnumTest` (localizar o ficheiro exacto com `smart_outline`/`grep` por `class ModuloEnumTest` antes de editar; se não existir teste unitário dedicado, criar `Modules/Permissao/tests/Unit/ModuloEnumTest.php` seguindo o padrão dos outros testes de enum do projecto):
```php
public function test_plano_curricular_slug_e_label(): void
{
    $this->assertSame('plano-curricular', Modulo::PLANO_CURRICULAR->slug());
    $this->assertSame('Plano Curricular', Modulo::PLANO_CURRICULAR->label());
    $this->assertSame(Modulo::PLANO_CURRICULAR, Modulo::fromSlug('plano-curricular'));
}
```
Run: `php artisan test --filter=ModuloEnumTest` — Expected: FAIL antes do Step 1, PASS depois.

- [ ] **Step 3: `ModuloSeeder`**

Adicionar ao array `$modulos`:
```php
['nome' => 14, 'descricao' => 'Plano Curricular'],
```

- [ ] **Step 4: `RolePermissaoSeeder`**

No `$mapaPorRole[Perfil::ADMIN_ESCOLA->value]`, adicionar (junto a `Modulo::CURSO`/`Modulo::DISCIPLINA`):
```php
Modulo::PLANO_CURRICULAR->value => ['ver', 'criar', 'editar'],
```

- [ ] **Step 5: Testes de seeder — rodar**

Run: `php artisan test --filter="ModuloSeederTest|RolePermissaoSeederTest"` — Expected: PASS (estender `RolePermissaoSeederTest` com asserção de que `ADMIN_ESCOLA` tem `plano-curricular.ver/criar/editar` via `PermissionResolver::can()`, seguindo o padrão exacto já usado para `curso`/`disciplina` nesse ficheiro).

**Tests:** `ModuloEnumTest::test_plano_curricular_slug_e_label`, `ModuloSeederTest` (regista `nome=14`), `RolePermissaoSeederTest` (ADMIN_ESCOLA tem as 3 permissões; um perfil sem mapeamento, ex. `PROFESSOR`, não tem nenhuma).

**Critérios de aceitação:**
- `Modulo::fromSlug('plano-curricular')` resolve.
- `php artisan db:seed --class=Modules\\Permissao\\Database\\Seeders\\PermissaoDatabaseSeeder` (ou equivalente já usado nos testes) concede `plano-curricular.ver/criar/editar` a `ADMIN_ESCOLA`.
- Nenhum módulo existente perde/ganha permissões por engano.

---

## Task 3 — Scaffold do módulo `PlanoCurricular` (nwidart/laravel-modules)

**Files:**
- Create: `Modules/PlanoCurricular/module.json`
- Create: `Modules/PlanoCurricular/composer.json`
- Create: `Modules/PlanoCurricular/app/Providers/PlanoCurricularServiceProvider.php`
- Create: `Modules/PlanoCurricular/app/Providers/RouteServiceProvider.php`
- Create: `Modules/PlanoCurricular/app/Providers/EventServiceProvider.php`
- Create: `Modules/PlanoCurricular/routes/web.php` (vazio, com grupo a preencher na Task 13)
- Create: `Modules/PlanoCurricular/routes/api.php` (comentário "módulo não expõe API")
- Create: `Modules/PlanoCurricular/database/migrations/.gitkeep`
- Create: `Modules/PlanoCurricular/database/seeders/PlanoCurricularDatabaseSeeder.php` (stub vazio, mesmo padrão de `CursoDatabaseSeeder`)
- Create: `Modules/PlanoCurricular/tests/.gitkeep`
- Create: `Modules/PlanoCurricular/vite.config.js` (stub, cópia do de `Curso`)
- Modify: `modules_statuses.json`

**Interfaces:** Nenhuma — apenas scaffolding, sem lógica.

- [ ] **Step 1: `module.json`** (copiar `Modules/Curso/module.json`, substituir nome)

```json
{
    "name": "PlanoCurricular",
    "alias": "planocurricular",
    "description": "",
    "keywords": [],
    "priority": 0,
    "providers": [
        "Modules\\PlanoCurricular\\Providers\\PlanoCurricularServiceProvider"
    ],
    "files": []
}
```

- [ ] **Step 2: `composer.json`** (copiar `Modules/Curso/composer.json`, substituir namespace)

```json
{
    "name": "nwidart/planocurricular",
    "description": "",
    "authors": [
        { "name": "Nicolas Widart", "email": "n.widart@gmail.com" }
    ],
    "extra": { "laravel": { "providers": [], "aliases": {} } },
    "autoload": {
        "psr-4": {
            "Modules\\PlanoCurricular\\": "app/",
            "Modules\\PlanoCurricular\\Database\\Factories\\": "database/factories/",
            "Modules\\PlanoCurricular\\Database\\Seeders\\": "database/seeders/"
        }
    },
    "autoload-dev": {
        "psr-4": { "Modules\\PlanoCurricular\\Tests\\": "tests/" }
    }
}
```

- [ ] **Step 3: `PlanoCurricularServiceProvider.php`, `RouteServiceProvider.php`, `EventServiceProvider.php`**

Ler `Modules/Curso/app/Providers/{CursoServiceProvider,RouteServiceProvider,EventServiceProvider}.php` e replicar substituindo `Curso`→`PlanoCurricular`, `curso`→`planocurricular` (nameLower).

- [ ] **Step 4: `modules_statuses.json`**

Adicionar `"PlanoCurricular": true` ao objecto raiz.

- [ ] **Step 5: `routes/web.php` (vazio, preenchido na Task 13) e `routes/api.php`**

```php
<?php
// Este módulo não expõe rotas de API; é apenas Inertia-web.
```

- [ ] **Step 6: `composer dump-autoload` e verificar módulo activo**

Run: `composer dump-autoload && php artisan module:list` — Expected: `PlanoCurricular` listado como `Enabled`.

- [ ] **Step 7: Confirmar que o módulo não quebra o boot da app**

Run: `php artisan route:list --name=planocurricular` (deve devolver vazio sem erro) e `php artisan test --filter=NadaAindaExiste` só para confirmar que `php artisan test` continua a correr sem erro fatal de autoload.

**Tests:** Nenhum teste de negócio nesta task — validação é `php artisan module:list` + app arranca sem erros.

**Critérios de aceitação:**
- Módulo aparece activo em `module:list`.
- `composer dump-autoload` sem erros de namespace duplicado/colisão.
- Nenhuma rota registada ainda (routes/web.php vazio) — sem impacto no resto da app.

---

## Task 4 — `planos_curriculares`: migration + Model

**Files:**
- Create: `Modules/PlanoCurricular/database/migrations/2026_09_10_090100_create_planos_curriculares_table.php`
- Create: `Modules/PlanoCurricular/app/Models/PlanoCurricular.php`
- Create: `Modules/PlanoCurricular/tests/Feature/PlanoCurricularModelTest.php`

**Interfaces:**
- Produces: `Modules\PlanoCurricular\Models\PlanoCurricular` — `$fillable = [estabelecimento_id, curso_id, codigo, nome, descricao, estado, estado_descricao, criado_por, editado_por]`; relações `estabelecimento(): BelongsTo`, `curso(): BelongsTo`, `disciplinas(): HasMany` (para `PlanoCurricularDisciplina`, definida na Task 5), `anosLectivos(): HasMany` (para `PlanoCurricularAnoLectivo`, Task 6), `criadoPor()/editadoPor(): BelongsTo`.

- [ ] **Step 1: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planos_curriculares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estabelecimento_id')->constrained('estabelecimentos')->restrictOnDelete();
            $table->foreignId('curso_id')->constrained('cursos')->restrictOnDelete();
            $table->string('codigo');
            $table->string('nome');
            $table->text('descricao')->nullable();
            $table->unsignedTinyInteger('estado')->default(1);
            $table->string('estado_descricao')->default('Ativo');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['estabelecimento_id', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planos_curriculares');
    }
};
```

- [ ] **Step 2: Escrever teste de Model falhando**

```php
<?php

namespace Modules\PlanoCurricular\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class PlanoCurricularModelTest extends TestCase
{
    use RefreshDatabase;

    private function estabelecimento(): Estabelecimento
    {
        return Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
    }

    public function test_cria_plano_pertencente_a_curso_e_estabelecimento(): void
    {
        $estabelecimento = $this->estabelecimento();
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'C1', 'nome' => 'Curso A']);

        $plano = PlanoCurricular::create([
            'estabelecimento_id' => $estabelecimento->id,
            'curso_id' => $curso->id,
            'codigo' => 'PC-2026',
            'nome' => 'Plano 2026',
        ]);

        $this->assertSame($estabelecimento->id, $plano->estabelecimento->id);
        $this->assertSame($curso->id, $plano->curso->id);
        $this->assertSame('Ativo', $plano->fresh()->estado_descricao);
    }

    public function test_autoria_preenchida_automaticamente(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $estabelecimento = $this->estabelecimento();
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'C1', 'nome' => 'Curso A']);

        $plano = PlanoCurricular::create([
            'estabelecimento_id' => $estabelecimento->id,
            'curso_id' => $curso->id,
            'codigo' => 'PC-2026',
            'nome' => 'Plano 2026',
        ]);

        $this->assertSame($user->id, $plano->criado_por);
        $this->assertSame($user->id, $plano->editado_por);
    }

    public function test_codigo_unico_por_estabelecimento(): void
    {
        $estabelecimento = $this->estabelecimento();
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'C1', 'nome' => 'Curso A']);
        PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'curso_id' => $curso->id, 'codigo' => 'PC-2026', 'nome' => 'Plano 2026']);

        $this->expectException(QueryException::class);
        PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'curso_id' => $curso->id, 'codigo' => 'PC-2026', 'nome' => 'Plano Duplicado']);
    }

    public function test_mesmo_codigo_em_estabelecimentos_diferentes_e_permitido(): void
    {
        $estabelecimentoA = $this->estabelecimento();
        Estabelecimento::where('id', $estabelecimentoA->id)->update(['is_active' => false]);
        $estabelecimentoB = Estabelecimento::create(['nome' => 'Escola B', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $cursoA = Curso::create(['estabelecimento_id' => $estabelecimentoA->id, 'codigo' => 'C1', 'nome' => 'Curso A']);
        $cursoB = Curso::create(['estabelecimento_id' => $estabelecimentoB->id, 'codigo' => 'C1', 'nome' => 'Curso B']);

        PlanoCurricular::create(['estabelecimento_id' => $estabelecimentoA->id, 'curso_id' => $cursoA->id, 'codigo' => 'PC-2026', 'nome' => 'Plano A']);
        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimentoB->id, 'curso_id' => $cursoB->id, 'codigo' => 'PC-2026', 'nome' => 'Plano B']);

        $this->assertNotNull($plano->id);
    }

    public function test_nao_possui_colunas_ano_lectivo_id_nem_modalidade(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('planos_curriculares', 'ano_lectivo_id'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('planos_curriculares', 'modalidade'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('planos_curriculares', 'tipo_ensino'));
    }
}
```
Run: `php artisan test --filter=PlanoCurricularModelTest` — Expected: FAIL (classe `PlanoCurricular` não existe).

- [ ] **Step 3: Model**

```php
<?php

namespace Modules\PlanoCurricular\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Core\Traits\SincronizaEstadoDescricao;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Models\User;

class PlanoCurricular extends Model
{
    use RegistaAutoria;
    use SincronizaEstadoDescricao;

    protected $table = 'planos_curriculares';

    protected $fillable = [
        'estabelecimento_id',
        'curso_id',
        'codigo',
        'nome',
        'descricao',
        'estado',
        'estado_descricao',
        'criado_por',
        'editado_por',
    ];

    protected $attributes = [
        'estado' => 1,
    ];

    protected $casts = [
        'estado' => 'integer',
    ];

    public function estabelecimento(): BelongsTo
    {
        return $this->belongsTo(Estabelecimento::class);
    }

    public function curso(): BelongsTo
    {
        return $this->belongsTo(Curso::class);
    }

    public function disciplinas(): HasMany
    {
        return $this->hasMany(PlanoCurricularDisciplina::class)->orderBy('ordem');
    }

    public function anosLectivos(): HasMany
    {
        return $this->hasMany(PlanoCurricularAnoLectivo::class);
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
(`PlanoCurricularDisciplina`/`PlanoCurricularAnoLectivo` só existirão após as Tasks 5/6 — este ficheiro fica com erro de classe-não-encontrada até lá; normal em desenvolvimento sequencial, mas se quiseres correr os testes desta task isoladamente, comentar temporariamente as duas relações ou avançar directamente para a Task 5/6 antes de rodar o suite completo.)

- [ ] **Step 4: Rodar migration e testes**

Run: `php artisan migrate --path=Modules/PlanoCurricular/database/migrations && php artisan test --filter=PlanoCurricularModelTest` — Expected: PASS.

**Tests:** `PlanoCurricularModelTest` (criação/relações, autoria, `estado_descricao`, unicidade de `codigo` por estabelecimento, mesmo código entre estabelecimentos diferentes permitido, ausência de `ano_lectivo_id`/`modalidade`/`tipo_ensino`).

**Critérios de aceitação:**
- Migration cria a tabela exactamente com as colunas da spec, nada a mais.
- `unique(estabelecimento_id, codigo)` reforçada a nível de BD.
- Nenhuma referência a `ano_lectivo_id`/`modalidade`/`tipo_ensino` na tabela.

---

## Task 5 — `plano_curricular_disciplinas`: migration + Model + enums `Componente`/`Tipo`

**Files:**
- Create: `Modules/PlanoCurricular/database/migrations/2026_09_10_090200_create_plano_curricular_disciplinas_table.php`
- Create: `Modules/PlanoCurricular/app/Enums/ComponentePlanoCurricular.php`
- Create: `Modules/PlanoCurricular/app/Enums/TipoDisciplinaPlano.php`
- Create: `Modules/PlanoCurricular/app/Models/PlanoCurricularDisciplina.php`
- Create: `Modules/PlanoCurricular/tests/Feature/PlanoCurricularDisciplinaModelTest.php`
- Create: `Modules/PlanoCurricular/tests/Unit/EnumsTest.php`

**Interfaces:**
- Produces: `Modules\PlanoCurricular\Enums\ComponentePlanoCurricular` (`GERAL=1`,`TECNICA=2`,`PRATICA=3`), `Modules\PlanoCurricular\Enums\TipoDisciplinaPlano` (`NORMAL=0`,`ESTAGIO=1`,`OPTATIVA=2`,`PROJETO=3`), `Modules\PlanoCurricular\Models\PlanoCurricularDisciplina` — `$fillable = [plano_curricular_id, disciplina_id, nivel_academico_id, carga_horaria, creditos, componente, componente_descricao, tipo, tipo_descricao, obrigatoria, ordem, estado, estado_descricao, criado_por, editado_por]`; relações `planoCurricular()`, `disciplina()`, `nivelAcademico()`.

- [ ] **Step 1: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plano_curricular_disciplinas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plano_curricular_id')->constrained('planos_curriculares')->cascadeOnDelete();
            $table->foreignId('disciplina_id')->constrained('disciplinas')->restrictOnDelete();
            $table->foreignId('nivel_academico_id')->constrained('niveis_academicos')->restrictOnDelete();
            $table->unsignedSmallInteger('carga_horaria')->nullable();
            $table->unsignedSmallInteger('creditos')->nullable();
            $table->unsignedTinyInteger('componente')->nullable();
            $table->string('componente_descricao')->nullable();
            $table->unsignedTinyInteger('tipo')->default(0);
            $table->string('tipo_descricao')->default('Normal');
            $table->boolean('obrigatoria')->default(true);
            $table->unsignedInteger('ordem')->default(0);
            $table->unsignedTinyInteger('estado')->default(1);
            $table->string('estado_descricao')->default('Ativo');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['plano_curricular_id', 'disciplina_id', 'nivel_academico_id'], 'plano_disciplina_nivel_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plano_curricular_disciplinas');
    }
};
```

- [ ] **Step 2: Enums**

```php
<?php

namespace Modules\PlanoCurricular\Enums;

enum ComponentePlanoCurricular: int
{
    case GERAL = 1;
    case TECNICA = 2;
    case PRATICA = 3;

    public function label(): string
    {
        return match ($this) {
            self::GERAL => 'Geral',
            self::TECNICA => 'Técnica',
            self::PRATICA => 'Prática',
        };
    }
}
```
```php
<?php

namespace Modules\PlanoCurricular\Enums;

enum TipoDisciplinaPlano: int
{
    case NORMAL = 0;
    case ESTAGIO = 1;
    case OPTATIVA = 2;
    case PROJETO = 3;

    public function label(): string
    {
        return match ($this) {
            self::NORMAL => 'Normal',
            self::ESTAGIO => 'Estágio',
            self::OPTATIVA => 'Optativa',
            self::PROJETO => 'Projecto',
        };
    }
}
```

- [ ] **Step 3: Teste unitário dos enums**

```php
<?php

namespace Modules\PlanoCurricular\Tests\Unit;

use Modules\PlanoCurricular\Enums\ComponentePlanoCurricular;
use Modules\PlanoCurricular\Enums\TipoDisciplinaPlano;
use Tests\TestCase;

class EnumsTest extends TestCase
{
    public function test_componente_labels(): void
    {
        $this->assertSame('Geral', ComponentePlanoCurricular::GERAL->label());
        $this->assertSame('Técnica', ComponentePlanoCurricular::TECNICA->label());
        $this->assertSame('Prática', ComponentePlanoCurricular::PRATICA->label());
    }

    public function test_tipo_labels(): void
    {
        $this->assertSame('Normal', TipoDisciplinaPlano::NORMAL->label());
        $this->assertSame('Estágio', TipoDisciplinaPlano::ESTAGIO->label());
        $this->assertSame('Optativa', TipoDisciplinaPlano::OPTATIVA->label());
        $this->assertSame('Projecto', TipoDisciplinaPlano::PROJETO->label());
    }
}
```
Run: `php artisan test --filter="Modules\\\\PlanoCurricular\\\\Tests\\\\Unit\\\\EnumsTest"` — Expected: PASS.

- [ ] **Step 4: Model**

```php
<?php

namespace Modules\PlanoCurricular\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Core\Traits\SincronizaEstadoDescricao;
use Modules\Disciplina\Models\Disciplina;
use Modules\PlanoCurricular\Enums\ComponentePlanoCurricular;
use Modules\PlanoCurricular\Enums\TipoDisciplinaPlano;
use Modules\Turma\Models\NivelAcademico;
use Modules\Usuario\Models\User;

class PlanoCurricularDisciplina extends Model
{
    use RegistaAutoria;
    use SincronizaEstadoDescricao;

    protected $table = 'plano_curricular_disciplinas';

    protected $fillable = [
        'plano_curricular_id',
        'disciplina_id',
        'nivel_academico_id',
        'carga_horaria',
        'creditos',
        'componente',
        'componente_descricao',
        'tipo',
        'tipo_descricao',
        'obrigatoria',
        'ordem',
        'estado',
        'estado_descricao',
        'criado_por',
        'editado_por',
    ];

    protected $attributes = [
        'estado' => 1,
        'tipo' => 0,
        'obrigatoria' => true,
        'ordem' => 0,
    ];

    protected $casts = [
        'estado' => 'integer',
        'componente' => ComponentePlanoCurricular::class,
        'tipo' => TipoDisciplinaPlano::class,
        'obrigatoria' => 'boolean',
        'ordem' => 'integer',
        'carga_horaria' => 'integer',
        'creditos' => 'integer',
    ];

    public function planoCurricular(): BelongsTo
    {
        return $this->belongsTo(PlanoCurricular::class);
    }

    public function disciplina(): BelongsTo
    {
        return $this->belongsTo(Disciplina::class);
    }

    public function nivelAcademico(): BelongsTo
    {
        return $this->belongsTo(NivelAcademico::class);
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
        static::saving(function (PlanoCurricularDisciplina $item) {
            $item->componente_descricao = $item->componente?->label();
            $item->tipo_descricao = $item->tipo?->label();
        });
    }
}
```

- [ ] **Step 5: Teste de feature falhando → implementação já feita nos steps anteriores → correr**

```php
<?php

namespace Modules\PlanoCurricular\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Curso\Models\Curso;
use Modules\Disciplina\Models\Disciplina;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\PlanoCurricular\Enums\ComponentePlanoCurricular;
use Modules\PlanoCurricular\Enums\TipoDisciplinaPlano;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\Turma\Models\NivelAcademico;
use Tests\TestCase;

class PlanoCurricularDisciplinaModelTest extends TestCase
{
    use RefreshDatabase;

    private function contexto(): array
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'tipo_ensino' => 2, 'is_active' => true]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'C1', 'nome' => 'Curso Técnico']);
        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'curso_id' => $curso->id, 'codigo' => 'PC1', 'nome' => 'Plano 1']);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'N10', 'nome' => '10ª Classe', 'ordem' => 1]);

        return compact('estabelecimento', 'curso', 'plano', 'nivel');
    }

    public function test_associa_disciplina_ao_plano_e_nivel(): void
    {
        ['estabelecimento' => $estabelecimento, 'plano' => $plano, 'nivel' => $nivel] = $this->contexto();
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'D1', 'nome' => 'Electrotecnia']);

        $item = PlanoCurricularDisciplina::create([
            'plano_curricular_id' => $plano->id,
            'disciplina_id' => $disciplina->id,
            'nivel_academico_id' => $nivel->id,
            'carga_horaria' => 90,
            'componente' => ComponentePlanoCurricular::TECNICA->value,
            'tipo' => TipoDisciplinaPlano::NORMAL->value,
            'obrigatoria' => true,
            'ordem' => 1,
        ]);

        $this->assertSame('Técnica', $item->fresh()->componente_descricao);
        $this->assertSame('Normal', $item->fresh()->tipo_descricao);
        $this->assertTrue($item->obrigatoria);
        $this->assertSame($disciplina->id, $item->disciplina->id);
        $this->assertSame($nivel->id, $item->nivelAcademico->id);
    }

    public function test_impede_duplicar_mesma_disciplina_no_mesmo_nivel_do_plano(): void
    {
        ['estabelecimento' => $estabelecimento, 'plano' => $plano, 'nivel' => $nivel] = $this->contexto();
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'D1', 'nome' => 'Matemática']);

        PlanoCurricularDisciplina::create([
            'plano_curricular_id' => $plano->id, 'disciplina_id' => $disciplina->id, 'nivel_academico_id' => $nivel->id, 'tipo' => 0, 'ordem' => 1,
        ]);

        $this->expectException(QueryException::class);
        PlanoCurricularDisciplina::create([
            'plano_curricular_id' => $plano->id, 'disciplina_id' => $disciplina->id, 'nivel_academico_id' => $nivel->id, 'tipo' => 0, 'ordem' => 2,
        ]);
    }

    public function test_permite_disciplina_optativa_nao_obrigatoria(): void
    {
        ['estabelecimento' => $estabelecimento, 'plano' => $plano, 'nivel' => $nivel] = $this->contexto();
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'D2', 'nome' => 'Optativa X']);

        $item = PlanoCurricularDisciplina::create([
            'plano_curricular_id' => $plano->id, 'disciplina_id' => $disciplina->id, 'nivel_academico_id' => $nivel->id,
            'tipo' => TipoDisciplinaPlano::OPTATIVA->value, 'obrigatoria' => false, 'ordem' => 1,
        ]);

        $this->assertFalse($item->obrigatoria);
        $this->assertSame(TipoDisciplinaPlano::OPTATIVA, $item->tipo);
    }

    public function test_creditos_e_componente_sao_nullable(): void
    {
        ['estabelecimento' => $estabelecimento, 'plano' => $plano, 'nivel' => $nivel] = $this->contexto();
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'D3', 'nome' => 'Língua Portuguesa']);

        $item = PlanoCurricularDisciplina::create([
            'plano_curricular_id' => $plano->id, 'disciplina_id' => $disciplina->id, 'nivel_academico_id' => $nivel->id, 'ordem' => 1,
        ]);

        $this->assertNull($item->creditos);
        $this->assertNull($item->componente);
        $this->assertNull($item->fresh()->componente_descricao);
    }

    public function test_nao_possui_coluna_semestre(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('plano_curricular_disciplinas', 'semestre'));
    }
}
```
Run: `php artisan test --filter=PlanoCurricularDisciplinaModelTest` — Expected: PASS.

**Tests:** `EnumsTest` (labels), `PlanoCurricularDisciplinaModelTest` (associação, unicidade plano+disciplina+nível, optativa/obrigatória, créditos/componente nullable, ausência de `semestre`).

**Critérios de aceitação:**
- Constraint `plano_disciplina_nivel_unique` impede duplicar a mesma disciplina no mesmo nível do mesmo plano.
- `componente`/`creditos`/`carga_horaria` aceitam `null`.
- `tipo`/`obrigatoria`/`ordem` obrigatórios com defaults sensatos.
- Sem coluna `semestre`.

---

## Task 6 — `plano_curricular_anos_lectivos`: migration + Model

**Files:**
- Create: `Modules/PlanoCurricular/database/migrations/2026_09_10_090300_create_plano_curricular_anos_lectivos_table.php`
- Create: `Modules/PlanoCurricular/app/Models/PlanoCurricularAnoLectivo.php`
- Create: `Modules/PlanoCurricular/tests/Feature/PlanoCurricularAnoLectivoModelTest.php`

**Interfaces:**
- Produces: `Modules\PlanoCurricular\Models\PlanoCurricularAnoLectivo` — `$fillable = [plano_curricular_id, ano_lectivo_id, estado, estado_descricao, confirmado_em, confirmado_por, observacoes]`; relações `planoCurricular()`, `anoLectivo()`, `confirmadoPor()`.

- [ ] **Step 1: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plano_curricular_anos_lectivos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plano_curricular_id')->constrained('planos_curriculares')->restrictOnDelete();
            $table->foreignId('ano_lectivo_id')->constrained('ano_lectivos')->restrictOnDelete();
            $table->unsignedTinyInteger('estado')->default(1);
            $table->string('estado_descricao')->default('Ativo');
            $table->timestamp('confirmado_em')->nullable();
            $table->foreignId('confirmado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('observacoes')->nullable();
            $table->timestamps();

            $table->unique(['plano_curricular_id', 'ano_lectivo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plano_curricular_anos_lectivos');
    }
};
```

- [ ] **Step 2: Model**

```php
<?php

namespace Modules\PlanoCurricular\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Traits\SincronizaEstadoDescricao;
use Modules\Usuario\Models\User;

class PlanoCurricularAnoLectivo extends Model
{
    use SincronizaEstadoDescricao;

    protected $table = 'plano_curricular_anos_lectivos';

    protected $fillable = [
        'plano_curricular_id',
        'ano_lectivo_id',
        'estado',
        'estado_descricao',
        'confirmado_em',
        'confirmado_por',
        'observacoes',
    ];

    protected $attributes = [
        'estado' => 1,
    ];

    protected $casts = [
        'estado' => 'integer',
        'confirmado_em' => 'datetime',
    ];

    public function planoCurricular(): BelongsTo
    {
        return $this->belongsTo(PlanoCurricular::class);
    }

    public function anoLectivo(): BelongsTo
    {
        return $this->belongsTo(AnoLectivo::class);
    }

    public function confirmadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmado_por');
    }
}
```
(Sem `RegistaAutoria`: a tabela não tem `criado_por`/`editado_por`, só `confirmado_por`/`confirmado_em`, preenchidos explicitamente pela Action na Task 10.)

- [ ] **Step 3: Teste de feature — regra histórica fundamental**

```php
<?php

namespace Modules\PlanoCurricular\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularAnoLectivo;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class PlanoCurricularAnoLectivoModelTest extends TestCase
{
    use RefreshDatabase;

    private function contexto(): array
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'C1', 'nome' => 'Curso A']);
        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'curso_id' => $curso->id, 'codigo' => 'PC1', 'nome' => 'Plano A']);

        return compact('estabelecimento', 'curso', 'plano');
    }

    public function test_confirma_plano_para_ano_lectivo(): void
    {
        ['estabelecimento' => $estabelecimento, 'plano' => $plano] = $this->contexto();
        $user = User::factory()->create();
        $ano = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026/2027', 'data_inicio' => '2026-02-01', 'data_fim' => '2026-11-30']);

        $confirmacao = PlanoCurricularAnoLectivo::create([
            'plano_curricular_id' => $plano->id,
            'ano_lectivo_id' => $ano->id,
            'confirmado_em' => now(),
            'confirmado_por' => $user->id,
        ]);

        $this->assertSame($plano->id, $confirmacao->planoCurricular->id);
        $this->assertSame($ano->id, $confirmacao->anoLectivo->id);
        $this->assertSame($user->id, $confirmacao->confirmadoPor->id);
    }

    public function test_impede_duas_associacoes_iguais_no_mesmo_ano(): void
    {
        ['estabelecimento' => $estabelecimento, 'plano' => $plano] = $this->contexto();
        $ano = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026/2027', 'data_inicio' => '2026-02-01', 'data_fim' => '2026-11-30']);
        PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $plano->id, 'ano_lectivo_id' => $ano->id]);

        $this->expectException(QueryException::class);
        PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $plano->id, 'ano_lectivo_id' => $ano->id]);
    }

    public function test_mesmo_plano_reconfirmado_em_anos_diferentes(): void
    {
        ['estabelecimento' => $estabelecimento, 'plano' => $planoA] = $this->contexto();
        $ano2026 = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026/2027', 'data_inicio' => '2026-02-01', 'data_fim' => '2026-11-30']);
        $ano2027 = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2027/2028', 'data_inicio' => '2027-02-01', 'data_fim' => '2027-11-30']);

        PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $planoA->id, 'ano_lectivo_id' => $ano2026->id]);
        PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $planoA->id, 'ano_lectivo_id' => $ano2027->id]);

        $this->assertCount(2, $planoA->anosLectivos);
    }

    public function test_historico_suporta_plano_diferente_em_ano_seguinte_sem_alterar_plano_anterior(): void
    {
        ['estabelecimento' => $estabelecimento, 'curso' => $curso, 'plano' => $planoA] = $this->contexto();
        $planoB = PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'curso_id' => $curso->id, 'codigo' => 'PC2', 'nome' => 'Plano B']);

        $ano2026 = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026/2027', 'data_inicio' => '2026-02-01', 'data_fim' => '2026-11-30']);
        $ano2027 = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2027/2028', 'data_inicio' => '2027-02-01', 'data_fim' => '2027-11-30']);
        $ano2028 = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2028/2029', 'data_inicio' => '2028-02-01', 'data_fim' => '2028-11-30']);

        PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $planoA->id, 'ano_lectivo_id' => $ano2026->id]);
        PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $planoA->id, 'ano_lectivo_id' => $ano2027->id]);
        PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $planoB->id, 'ano_lectivo_id' => $ano2028->id]);

        $this->assertSame(['2026/2027', '2027/2028'], $planoA->fresh()->anosLectivos->pluck('anoLectivo.nome')->all());
        $this->assertSame(['2028/2029'], $planoB->fresh()->anosLectivos->pluck('anoLectivo.nome')->all());
        // Plano A não foi tocado ao criar o Plano B — mesma instância, mesmos dados.
        $this->assertSame('Plano A', $planoA->fresh()->nome);
    }
}
```
Run: `php artisan test --filter=PlanoCurricularAnoLectivoModelTest` — Expected: PASS.

**Tests:** associação plano↔ano lectivo, impedir duplicação no mesmo ano, reconfirmação do mesmo plano em anos diferentes, cenário histórico completo (Plano A em 2026/27+2027/28, Plano B em 2028/29, sem alterar Plano A).

**Critérios de aceitação:**
- `unique(plano_curricular_id, ano_lectivo_id)` reforçada a nível de BD.
- Um plano pode ter múltiplas confirmações (anos diferentes).
- Criar Plano B não muta nenhum dado do Plano A.

---

## Task 7 — DTOs

**Files:**
- Create: `Modules/PlanoCurricular/app/DTO/PlanoCurricularDTO.php`
- Create: `Modules/PlanoCurricular/app/DTO/PlanoCurricularDisciplinaDTO.php`
- Create: `Modules/PlanoCurricular/app/DTO/ConfirmarAnoLectivoDTO.php`

**Interfaces:**
- Consumes: `CriarPlanoCurricularRequest`, `AtualizarPlanoCurricularRequest`, `AdicionarDisciplinaRequest`, `AtualizarDisciplinaRequest`, `ConfirmarAnoLectivoRequest` (definidas na Task 10).
- Produces: `PlanoCurricularDTO{codigo, nome, ?descricao, curso_id}`, `PlanoCurricularDisciplinaDTO{disciplina_id, nivel_academico_id, ?carga_horaria, ?creditos, ?componente, tipo, obrigatoria, ordem}`, `ConfirmarAnoLectivoDTO{ano_lectivo_id, ?observacoes}` — consumidos pelas Actions (Tasks 8-10).

- [ ] **Step 1: `PlanoCurricularDTO`**

```php
<?php

namespace Modules\PlanoCurricular\DTO;

use Modules\PlanoCurricular\Http\Requests\AtualizarPlanoCurricularRequest;
use Modules\PlanoCurricular\Http\Requests\CriarPlanoCurricularRequest;

class PlanoCurricularDTO
{
    public function __construct(
        public int $curso_id,
        public string $codigo,
        public string $nome,
        public ?string $descricao = null,
    ) {
    }

    public static function fromCriarRequest(CriarPlanoCurricularRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            curso_id: (int) $dados['curso_id'],
            codigo: $dados['codigo'],
            nome: $dados['nome'],
            descricao: $dados['descricao'] ?? null,
        );
    }

    public static function fromAtualizarRequest(AtualizarPlanoCurricularRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            curso_id: (int) $dados['curso_id'],
            codigo: $dados['codigo'],
            nome: $dados['nome'],
            descricao: $dados['descricao'] ?? null,
        );
    }
}
```

- [ ] **Step 2: `PlanoCurricularDisciplinaDTO`**

```php
<?php

namespace Modules\PlanoCurricular\DTO;

use Modules\PlanoCurricular\Enums\ComponentePlanoCurricular;
use Modules\PlanoCurricular\Enums\TipoDisciplinaPlano;
use Modules\PlanoCurricular\Http\Requests\AdicionarDisciplinaRequest;
use Modules\PlanoCurricular\Http\Requests\AtualizarDisciplinaRequest;

class PlanoCurricularDisciplinaDTO
{
    public function __construct(
        public int $disciplina_id,
        public int $nivel_academico_id,
        public TipoDisciplinaPlano $tipo,
        public bool $obrigatoria,
        public int $ordem,
        public ?int $carga_horaria = null,
        public ?int $creditos = null,
        public ?ComponentePlanoCurricular $componente = null,
    ) {
    }

    public static function fromAdicionarRequest(AdicionarDisciplinaRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            disciplina_id: (int) $dados['disciplina_id'],
            nivel_academico_id: (int) $dados['nivel_academico_id'],
            tipo: TipoDisciplinaPlano::from((int) $dados['tipo']),
            obrigatoria: (bool) $dados['obrigatoria'],
            ordem: (int) $dados['ordem'],
            carga_horaria: isset($dados['carga_horaria']) ? (int) $dados['carga_horaria'] : null,
            creditos: isset($dados['creditos']) ? (int) $dados['creditos'] : null,
            componente: isset($dados['componente']) ? ComponentePlanoCurricular::from((int) $dados['componente']) : null,
        );
    }

    public static function fromAtualizarRequest(AtualizarDisciplinaRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            disciplina_id: (int) $dados['disciplina_id'],
            nivel_academico_id: (int) $dados['nivel_academico_id'],
            tipo: TipoDisciplinaPlano::from((int) $dados['tipo']),
            obrigatoria: (bool) $dados['obrigatoria'],
            ordem: (int) $dados['ordem'],
            carga_horaria: isset($dados['carga_horaria']) ? (int) $dados['carga_horaria'] : null,
            creditos: isset($dados['creditos']) ? (int) $dados['creditos'] : null,
            componente: isset($dados['componente']) ? ComponentePlanoCurricular::from((int) $dados['componente']) : null,
        );
    }
}
```

- [ ] **Step 3: `ConfirmarAnoLectivoDTO`**

```php
<?php

namespace Modules\PlanoCurricular\DTO;

use Modules\PlanoCurricular\Http\Requests\ConfirmarAnoLectivoRequest;

class ConfirmarAnoLectivoDTO
{
    public function __construct(
        public int $ano_lectivo_id,
        public ?string $observacoes = null,
    ) {
    }

    public static function fromRequest(ConfirmarAnoLectivoRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            ano_lectivo_id: (int) $dados['ano_lectivo_id'],
            observacoes: $dados['observacoes'] ?? null,
        );
    }
}
```

- [ ] **Step 4: Confirmar que compila (classes de Request ainda não existem — normal; validar sintaxe com lint)**

Run: `php -l Modules/PlanoCurricular/app/DTO/PlanoCurricularDTO.php Modules/PlanoCurricular/app/DTO/PlanoCurricularDisciplinaDTO.php Modules/PlanoCurricular/app/DTO/ConfirmarAnoLectivoDTO.php` — Expected: "No syntax errors detected" nos três.

**Tests:** Sem testes dedicados (DTOs são testados indirectamente via testes de Action/Request nas Tasks 8-12) — consistente com o padrão de `CursoDTO`, que não tem teste próprio.

**Critérios de aceitação:**
- DTOs não contêm `estabelecimento_id` nem `estado` (preenchidos pelas Actions, nunca pelo cliente).
- Conversão de enums feita no DTO, não na Action nem no Model.

---

## Task 8 — Actions do Plano (criar/atualizar/alterar-estado)

**Files:**
- Create: `Modules/PlanoCurricular/app/Actions/CriarPlanoCurricularAction.php`
- Create: `Modules/PlanoCurricular/app/Actions/AtualizarPlanoCurricularAction.php`
- Create: `Modules/PlanoCurricular/app/Actions/AlterarEstadoPlanoCurricularAction.php`
- Create: `Modules/PlanoCurricular/tests/Feature/CriarPlanoCurricularActionTest.php`
- Create: `Modules/PlanoCurricular/tests/Feature/AtualizarPlanoCurricularActionTest.php`
- Create: `Modules/PlanoCurricular/tests/Feature/AlterarEstadoPlanoCurricularActionTest.php`

**Interfaces:**
- Consumes: `PlanoCurricularDTO` (Task 7).
- Produces: `CriarPlanoCurricularAction::executar(PlanoCurricularDTO $dto): PlanoCurricular`, `AtualizarPlanoCurricularAction::executar(PlanoCurricular $plano, PlanoCurricularDTO $dto): PlanoCurricular`, `AlterarEstadoPlanoCurricularAction::executar(PlanoCurricular $plano, Estado $novoEstado): PlanoCurricular` — consumidos por `GestaoPlanoCurricularService` (Task 11).

- [ ] **Step 1: Escrever teste de `CriarPlanoCurricularAction` falhando**

```php
<?php

namespace Modules\PlanoCurricular\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\PlanoCurricular\Actions\CriarPlanoCurricularAction;
use Modules\PlanoCurricular\DTO\PlanoCurricularDTO;
use Tests\TestCase;

class CriarPlanoCurricularActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_plano_com_estabelecimento_actual(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'C1', 'nome' => 'Curso A']);

        $plano = (new CriarPlanoCurricularAction())->executar(new PlanoCurricularDTO(
            curso_id: $curso->id, codigo: 'PC1', nome: 'Plano 1', descricao: 'Descrição',
        ));

        $this->assertSame($estabelecimento->id, $plano->estabelecimento_id);
        $this->assertSame($curso->id, $plano->curso_id);
        $this->assertSame('PC1', $plano->codigo);
    }
}
```
Run: `php artisan test --filter=CriarPlanoCurricularActionTest` — Expected: FAIL (classe não existe).

- [ ] **Step 2: `CriarPlanoCurricularAction`**

```php
<?php

namespace Modules\PlanoCurricular\Actions;

use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\PlanoCurricular\DTO\PlanoCurricularDTO;
use Modules\PlanoCurricular\Models\PlanoCurricular;

class CriarPlanoCurricularAction
{
    public function executar(PlanoCurricularDTO $dto): PlanoCurricular
    {
        return PlanoCurricular::create([
            'estabelecimento_id' => Estabelecimento::current()?->id,
            'curso_id' => $dto->curso_id,
            'codigo' => $dto->codigo,
            'nome' => $dto->nome,
            'descricao' => $dto->descricao,
        ]);
    }
}
```
Run: `php artisan test --filter=CriarPlanoCurricularActionTest` — Expected: PASS.

- [ ] **Step 3: `AtualizarPlanoCurricularAction` + teste (mesmo padrão de `AtualizarCursoAction`)**

```php
<?php

namespace Modules\PlanoCurricular\Actions;

use Modules\PlanoCurricular\DTO\PlanoCurricularDTO;
use Modules\PlanoCurricular\Models\PlanoCurricular;

class AtualizarPlanoCurricularAction
{
    public function executar(PlanoCurricular $plano, PlanoCurricularDTO $dto): PlanoCurricular
    {
        $plano->fill([
            'curso_id' => $dto->curso_id,
            'codigo' => $dto->codigo,
            'nome' => $dto->nome,
            'descricao' => $dto->descricao,
        ]);
        $plano->save();

        return $plano->fresh();
    }
}
```
Teste `AtualizarPlanoCurricularActionTest` — replicar `AtualizarCursoActionTest` (criar plano, chamar Action, assert campos actualizados, assert `estabelecimento_id` inalterado).
Run: `php artisan test --filter=AtualizarPlanoCurricularActionTest` — Expected: PASS.

- [ ] **Step 4: `AlterarEstadoPlanoCurricularAction` + teste (mesmo padrão de `AlterarEstadoCursoAction`)**

```php
<?php

namespace Modules\PlanoCurricular\Actions;

use Modules\Core\Enums\Estado;
use Modules\PlanoCurricular\Models\PlanoCurricular;

class AlterarEstadoPlanoCurricularAction
{
    public function executar(PlanoCurricular $plano, Estado $novoEstado): PlanoCurricular
    {
        $plano->estado = $novoEstado->value;
        $plano->save();

        return $plano->fresh();
    }
}
```
Teste `AlterarEstadoPlanoCurricularActionTest` — replicar `AlterarEstadoCursoActionTest`.
Run: `php artisan test --filter=AlterarEstadoPlanoCurricularActionTest` — Expected: PASS.

**Tests:** `CriarPlanoCurricularActionTest`, `AtualizarPlanoCurricularActionTest`, `AlterarEstadoPlanoCurricularActionTest`.

**Critérios de aceitação:**
- `estabelecimento_id` nunca vem do DTO/cliente — sempre `Estabelecimento::current()?->id`.
- Actualizar plano não altera `estabelecimento_id` nem `estado`.
- Alterar estado não altera mais nenhum campo.

---

## Task 9 — Actions de Disciplinas do Plano (adicionar/atualizar/remover)

**Files:**
- Create: `Modules/PlanoCurricular/app/Actions/AdicionarDisciplinaAoPlanoAction.php`
- Create: `Modules/PlanoCurricular/app/Actions/AtualizarDisciplinaDoPlanoAction.php`
- Create: `Modules/PlanoCurricular/app/Actions/RemoverDisciplinaDoPlanoAction.php`
- Create: `Modules/PlanoCurricular/tests/Feature/AdicionarDisciplinaAoPlanoActionTest.php`
- Create: `Modules/PlanoCurricular/tests/Feature/AtualizarDisciplinaDoPlanoActionTest.php`
- Create: `Modules/PlanoCurricular/tests/Feature/RemoverDisciplinaDoPlanoActionTest.php`

**Interfaces:**
- Consumes: `PlanoCurricularDisciplinaDTO` (Task 7).
- Produces: `AdicionarDisciplinaAoPlanoAction::executar(PlanoCurricular $plano, PlanoCurricularDisciplinaDTO $dto): PlanoCurricularDisciplina`, `AtualizarDisciplinaDoPlanoAction::executar(PlanoCurricularDisciplina $item, PlanoCurricularDisciplinaDTO $dto): PlanoCurricularDisciplina`, `RemoverDisciplinaDoPlanoAction::executar(PlanoCurricularDisciplina $item): void`.

- [ ] **Step 1: Teste + implementação de `AdicionarDisciplinaAoPlanoAction`**

Teste (`AdicionarDisciplinaAoPlanoActionTest`): cria plano+disciplina+nível, chama a Action, assert `plano_curricular_id` correcto e valores gravados. Run para confirmar FAIL antes de implementar.

```php
<?php

namespace Modules\PlanoCurricular\Actions;

use Modules\PlanoCurricular\DTO\PlanoCurricularDisciplinaDTO;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;

class AdicionarDisciplinaAoPlanoAction
{
    public function executar(PlanoCurricular $plano, PlanoCurricularDisciplinaDTO $dto): PlanoCurricularDisciplina
    {
        return PlanoCurricularDisciplina::create([
            'plano_curricular_id' => $plano->id,
            'disciplina_id' => $dto->disciplina_id,
            'nivel_academico_id' => $dto->nivel_academico_id,
            'carga_horaria' => $dto->carga_horaria,
            'creditos' => $dto->creditos,
            'componente' => $dto->componente?->value,
            'tipo' => $dto->tipo->value,
            'obrigatoria' => $dto->obrigatoria,
            'ordem' => $dto->ordem,
        ]);
    }
}
```
Run: `php artisan test --filter=AdicionarDisciplinaAoPlanoActionTest` — Expected: PASS.

- [ ] **Step 2: Teste + implementação de `AtualizarDisciplinaDoPlanoAction`**

```php
<?php

namespace Modules\PlanoCurricular\Actions;

use Modules\PlanoCurricular\DTO\PlanoCurricularDisciplinaDTO;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;

class AtualizarDisciplinaDoPlanoAction
{
    public function executar(PlanoCurricularDisciplina $item, PlanoCurricularDisciplinaDTO $dto): PlanoCurricularDisciplina
    {
        $item->fill([
            'disciplina_id' => $dto->disciplina_id,
            'nivel_academico_id' => $dto->nivel_academico_id,
            'carga_horaria' => $dto->carga_horaria,
            'creditos' => $dto->creditos,
            'componente' => $dto->componente?->value,
            'tipo' => $dto->tipo->value,
            'obrigatoria' => $dto->obrigatoria,
            'ordem' => $dto->ordem,
        ]);
        $item->save();

        return $item->fresh();
    }
}
```
Teste segue o mesmo molde de `AtualizarCursoActionTest`. Run e confirmar PASS.

- [ ] **Step 3: Teste + implementação de `RemoverDisciplinaDoPlanoAction`**

```php
<?php

namespace Modules\PlanoCurricular\Actions;

use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;

class RemoverDisciplinaDoPlanoAction
{
    public function executar(PlanoCurricularDisciplina $item): void
    {
        $item->delete();
    }
}
```
Teste: cria item, chama a Action, `assertDatabaseMissing('plano_curricular_disciplinas', ['id' => $item->id])`. Run e confirmar PASS.

**Tests:** `AdicionarDisciplinaAoPlanoActionTest`, `AtualizarDisciplinaDoPlanoActionTest`, `RemoverDisciplinaDoPlanoActionTest`.

**Critérios de aceitação:**
- Adicionar respeita a constraint de unicidade (testado indirectamente via camada de Request na Task 12, aqui só o caminho feliz).
- Remover é hard-delete (a tabela não tem soft delete — decisão consistente com a spec não pedir histórico de disciplinas removidas individualmente, apenas histórico ao nível do plano completo via `plano_curricular_anos_lectivos`).

---

## Task 10 — Action de Confirmação de Ano Lectivo

**Files:**
- Create: `Modules/PlanoCurricular/app/Actions/ConfirmarPlanoParaAnoLectivoAction.php`
- Create: `Modules/PlanoCurricular/tests/Feature/ConfirmarPlanoParaAnoLectivoActionTest.php`

**Interfaces:**
- Consumes: `ConfirmarAnoLectivoDTO` (Task 7).
- Produces: `ConfirmarPlanoParaAnoLectivoAction::executar(PlanoCurricular $plano, ConfirmarAnoLectivoDTO $dto, int $confirmadoPorId): PlanoCurricularAnoLectivo`.

- [ ] **Step 1: Teste falhando**

```php
<?php

namespace Modules\PlanoCurricular\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\PlanoCurricular\Actions\ConfirmarPlanoParaAnoLectivoAction;
use Modules\PlanoCurricular\DTO\ConfirmarAnoLectivoDTO;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class ConfirmarPlanoParaAnoLectivoActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirma_plano_regista_quem_e_quando(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'C1', 'nome' => 'Curso A']);
        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'curso_id' => $curso->id, 'codigo' => 'PC1', 'nome' => 'Plano A']);
        $ano = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026/2027', 'data_inicio' => '2026-02-01', 'data_fim' => '2026-11-30']);
        $user = User::factory()->create();

        $confirmacao = (new ConfirmarPlanoParaAnoLectivoAction())->executar(
            $plano,
            new ConfirmarAnoLectivoDTO(ano_lectivo_id: $ano->id, observacoes: 'Sem alterações face ao ano anterior.'),
            $user->id,
        );

        $this->assertSame($ano->id, $confirmacao->ano_lectivo_id);
        $this->assertSame($user->id, $confirmacao->confirmado_por);
        $this->assertNotNull($confirmacao->confirmado_em);
        $this->assertSame('Sem alterações face ao ano anterior.', $confirmacao->observacoes);
    }
}
```
Run: `php artisan test --filter=ConfirmarPlanoParaAnoLectivoActionTest` — Expected: FAIL.

- [ ] **Step 2: Implementação**

```php
<?php

namespace Modules\PlanoCurricular\Actions;

use Modules\PlanoCurricular\DTO\ConfirmarAnoLectivoDTO;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularAnoLectivo;

class ConfirmarPlanoParaAnoLectivoAction
{
    public function executar(PlanoCurricular $plano, ConfirmarAnoLectivoDTO $dto, int $confirmadoPorId): PlanoCurricularAnoLectivo
    {
        return PlanoCurricularAnoLectivo::create([
            'plano_curricular_id' => $plano->id,
            'ano_lectivo_id' => $dto->ano_lectivo_id,
            'confirmado_em' => now(),
            'confirmado_por' => $confirmadoPorId,
            'observacoes' => $dto->observacoes,
        ]);
    }
}
```
Run: `php artisan test --filter=ConfirmarPlanoParaAnoLectivoActionTest` — Expected: PASS.

**Tests:** `ConfirmarPlanoParaAnoLectivoActionTest` (confirmação básica). A Task 12 (Requests) acrescenta o teste de "impedir duplicar a mesma associação no mesmo ano" à camada de validação; a Task 6 já cobre a constraint a nível de BD.

**Critérios de aceitação:**
- `confirmado_por` vem sempre do utilizador autenticado (`auth()->id()` resolvido no Controller, nunca do payload do cliente).
- `confirmado_em` é sempre `now()` no momento da confirmação.

---

## Task 11 — Services (`GestaoPlanoCurricularService` + `PlanoCurricularConsultaService`)

**Files:**
- Create: `Modules/PlanoCurricular/app/Services/GestaoPlanoCurricularService.php`
- Create: `Modules/PlanoCurricular/app/Services/PlanoCurricularConsultaService.php`
- Create: `Modules/PlanoCurricular/tests/Feature/PlanoCurricularConsultaServiceTest.php`

**Interfaces:**
- Consumes: todas as Actions das Tasks 8-10, todos os DTOs da Task 7.
- Produces: `GestaoPlanoCurricularService::{criar,atualizar,alterarEstado,adicionarDisciplina,atualizarDisciplina,removerDisciplina,confirmarAnoLectivo}`; `PlanoCurricularConsultaService::{listar(): Collection, opcoesFormulario(): array}` — consumidos pelo `PlanoCurricularController` (Task 13).

- [ ] **Step 1: `GestaoPlanoCurricularService`**

```php
<?php

namespace Modules\PlanoCurricular\Services;

use Modules\Core\Enums\Estado;
use Modules\PlanoCurricular\Actions\AdicionarDisciplinaAoPlanoAction;
use Modules\PlanoCurricular\Actions\AlterarEstadoPlanoCurricularAction;
use Modules\PlanoCurricular\Actions\AtualizarDisciplinaDoPlanoAction;
use Modules\PlanoCurricular\Actions\AtualizarPlanoCurricularAction;
use Modules\PlanoCurricular\Actions\ConfirmarPlanoParaAnoLectivoAction;
use Modules\PlanoCurricular\Actions\CriarPlanoCurricularAction;
use Modules\PlanoCurricular\Actions\RemoverDisciplinaDoPlanoAction;
use Modules\PlanoCurricular\DTO\ConfirmarAnoLectivoDTO;
use Modules\PlanoCurricular\DTO\PlanoCurricularDisciplinaDTO;
use Modules\PlanoCurricular\DTO\PlanoCurricularDTO;
use Modules\PlanoCurricular\Http\Requests\AdicionarDisciplinaRequest;
use Modules\PlanoCurricular\Http\Requests\AtualizarDisciplinaRequest;
use Modules\PlanoCurricular\Http\Requests\AtualizarPlanoCurricularRequest;
use Modules\PlanoCurricular\Http\Requests\ConfirmarAnoLectivoRequest;
use Modules\PlanoCurricular\Http\Requests\CriarPlanoCurricularRequest;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularAnoLectivo;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;

class GestaoPlanoCurricularService
{
    public function __construct(
        private CriarPlanoCurricularAction $criarPlano,
        private AtualizarPlanoCurricularAction $atualizarPlano,
        private AlterarEstadoPlanoCurricularAction $alterarEstadoPlano,
        private AdicionarDisciplinaAoPlanoAction $adicionarDisciplina,
        private AtualizarDisciplinaDoPlanoAction $atualizarDisciplina,
        private RemoverDisciplinaDoPlanoAction $removerDisciplina,
        private ConfirmarPlanoParaAnoLectivoAction $confirmarAnoLectivo,
    ) {
    }

    public function criar(CriarPlanoCurricularRequest $request): PlanoCurricular
    {
        return $this->criarPlano->executar(PlanoCurricularDTO::fromCriarRequest($request));
    }

    public function atualizar(PlanoCurricular $plano, AtualizarPlanoCurricularRequest $request): PlanoCurricular
    {
        return $this->atualizarPlano->executar($plano, PlanoCurricularDTO::fromAtualizarRequest($request));
    }

    public function alterarEstado(PlanoCurricular $plano, Estado $novoEstado): PlanoCurricular
    {
        return $this->alterarEstadoPlano->executar($plano, $novoEstado);
    }

    public function adicionarDisciplina(PlanoCurricular $plano, AdicionarDisciplinaRequest $request): PlanoCurricularDisciplina
    {
        return $this->adicionarDisciplina->executar($plano, PlanoCurricularDisciplinaDTO::fromAdicionarRequest($request));
    }

    public function atualizarDisciplina(PlanoCurricularDisciplina $item, AtualizarDisciplinaRequest $request): PlanoCurricularDisciplina
    {
        return $this->atualizarDisciplina->executar($item, PlanoCurricularDisciplinaDTO::fromAtualizarRequest($request));
    }

    public function removerDisciplina(PlanoCurricularDisciplina $item): void
    {
        $this->removerDisciplina->executar($item);
    }

    public function confirmarAnoLectivo(PlanoCurricular $plano, ConfirmarAnoLectivoRequest $request, int $confirmadoPorId): PlanoCurricularAnoLectivo
    {
        return $this->confirmarAnoLectivo->executar($plano, ConfirmarAnoLectivoDTO::fromRequest($request), $confirmadoPorId);
    }
}
```

- [ ] **Step 2: Teste + implementação de `PlanoCurricularConsultaService`**

Teste (`PlanoCurricularConsultaServiceTest`): cria 2 estabelecimentos com planos, confirma que `listar()` só devolve os do `Estabelecimento::current()`; confirma que `opcoesFormulario()` devolve `cursos`, `disciplinas`, `niveisAcademicos`, `anosLectivos` filtrados pelo estabelecimento actual. Run para confirmar FAIL antes de implementar.

```php
<?php

namespace Modules\PlanoCurricular\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Enums\Estado;
use Modules\Curso\Models\Curso;
use Modules\Disciplina\Models\Disciplina;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\Turma\Models\NivelAcademico;

class PlanoCurricularConsultaService
{
    public function listar(): Collection
    {
        return PlanoCurricular::where('estabelecimento_id', Estabelecimento::current()?->id)
            ->with('curso')
            ->orderBy('nome')
            ->get();
    }

    public function opcoesFormulario(): array
    {
        $estabelecimentoId = Estabelecimento::current()?->id;

        return [
            'cursos' => Curso::where('estabelecimento_id', $estabelecimentoId)->where('estado', Estado::ATIVO->value)->orderBy('nome')->get(['id', 'nome']),
            'disciplinas' => Disciplina::where('estabelecimento_id', $estabelecimentoId)->where('estado', Estado::ATIVO->value)->orderBy('nome')->get(['id', 'nome']),
            'niveisAcademicos' => NivelAcademico::where('estabelecimento_id', $estabelecimentoId)->where('estado', Estado::ATIVO->value)->orderBy('ordem')->get(['id', 'nome', 'ordem']),
            'anosLectivos' => AnoLectivo::where('estabelecimento_id', $estabelecimentoId)->orderByDesc('data_inicio')->get(['id', 'nome', 'estado']),
        ];
    }
}
```
Run: `php artisan test --filter=PlanoCurricularConsultaServiceTest` — Expected: PASS.

**Tests:** `PlanoCurricularConsultaServiceTest` (isolamento por estabelecimento em `listar()` e `opcoesFormulario()`).

**Critérios de aceitação:**
- `listar()` nunca devolve planos de outro estabelecimento.
- `opcoesFormulario()` só oferece cursos/disciplinas/níveis/anos do estabelecimento actual (impede seleccionar entidades de outro estabelecimento já na origem da lista, complementando a validação da Task 12).

---

## Task 12 — FormRequests (com isolamento cross-entidade)

**Files:**
- Create: `Modules/PlanoCurricular/app/Http/Requests/CriarPlanoCurricularRequest.php`
- Create: `Modules/PlanoCurricular/app/Http/Requests/AtualizarPlanoCurricularRequest.php`
- Create: `Modules/PlanoCurricular/app/Http/Requests/AlterarEstadoPlanoCurricularRequest.php`
- Create: `Modules/PlanoCurricular/app/Http/Requests/AdicionarDisciplinaRequest.php`
- Create: `Modules/PlanoCurricular/app/Http/Requests/AtualizarDisciplinaRequest.php`
- Create: `Modules/PlanoCurricular/app/Http/Requests/ConfirmarAnoLectivoRequest.php`
- Create: `Modules/PlanoCurricular/tests/Feature/CriarPlanoCurricularRequestTest.php`
- Create: `Modules/PlanoCurricular/tests/Feature/AdicionarDisciplinaRequestTest.php`
- Create: `Modules/PlanoCurricular/tests/Feature/ConfirmarAnoLectivoRequestTest.php`

**Interfaces:** Produces regras de validação consumidas pelos DTOs (Task 7) e pelo Controller (Task 13).

- [ ] **Step 1: `CriarPlanoCurricularRequest`**

```php
<?php

namespace Modules\PlanoCurricular\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Estabelecimento\Models\Estabelecimento;

class CriarPlanoCurricularRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('plano-curricular.criar') ?? false;
    }

    public function rules(): array
    {
        $estabelecimentoId = Estabelecimento::current()?->id;

        return [
            'curso_id' => [
                'required',
                'integer',
                Rule::exists('cursos', 'id')->where(fn ($q) => $q->where('estabelecimento_id', $estabelecimentoId)),
            ],
            'codigo' => [
                'required',
                'string',
                'max:50',
                Rule::unique('planos_curriculares', 'codigo')->where(fn ($q) => $q->where('estabelecimento_id', $estabelecimentoId)),
            ],
            'nome' => ['required', 'string', 'max:255'],
            'descricao' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'curso_id.required' => 'O curso é obrigatório.',
            'curso_id.exists' => 'O curso indicado não pertence a este estabelecimento.',
            'codigo.required' => 'O código do plano é obrigatório.',
            'codigo.unique' => 'Já existe um plano curricular com este código neste estabelecimento.',
            'nome.required' => 'O nome do plano é obrigatório.',
        ];
    }
}
```

- [ ] **Step 2: Teste de `CriarPlanoCurricularRequest` — isolamento e unicidade**

```php
<?php

namespace Modules\PlanoCurricular\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\PlanoCurricular\Http\Requests\CriarPlanoCurricularRequest;
use Tests\TestCase;

class CriarPlanoCurricularRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejeita_curso_de_outro_estabelecimento(): void
    {
        $estabelecimentoA = Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $estabelecimentoB = Estabelecimento::create(['nome' => 'Escola B', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => false]);
        $cursoDeOutroEstabelecimento = Curso::create(['estabelecimento_id' => $estabelecimentoB->id, 'codigo' => 'C1', 'nome' => 'Curso B']);

        $validator = Validator::make(
            ['curso_id' => $cursoDeOutroEstabelecimento->id, 'codigo' => 'PC1', 'nome' => 'Plano 1'],
            (new CriarPlanoCurricularRequest())->rules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('curso_id', $validator->errors()->toArray());
    }

    public function test_mesmo_codigo_em_estabelecimentos_diferentes_e_permitido(): void
    {
        Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $curso = Curso::create(['estabelecimento_id' => Estabelecimento::current()->id, 'codigo' => 'C1', 'nome' => 'Curso A']);

        $validator = Validator::make(
            ['curso_id' => $curso->id, 'codigo' => 'PC1', 'nome' => 'Plano 1'],
            (new CriarPlanoCurricularRequest())->rules(),
        );

        $this->assertFalse($validator->fails());
    }
}
```
Run: `php artisan test --filter=CriarPlanoCurricularRequestTest` — Expected: PASS.

- [ ] **Step 3: `AtualizarPlanoCurricularRequest`** (igual à de criar + `->ignore($this->route('plano_curricular'))` na regra `codigo`, seguindo `AtualizarCursoRequest`).

- [ ] **Step 4: `AlterarEstadoPlanoCurricularRequest`**

```php
<?php

namespace Modules\PlanoCurricular\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rules\Enum;
use Modules\Core\Enums\Estado;

class AlterarEstadoPlanoCurricularRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('plano-curricular.editar') ?? false;
    }

    public function rules(): array
    {
        return ['estado' => ['required', new Enum(Estado::class)]];
    }
}
```

- [ ] **Step 5: `AdicionarDisciplinaRequest` (isolamento de `disciplina_id`/`nivel_academico_id` + unicidade plano+disciplina+nível)**

```php
<?php

namespace Modules\PlanoCurricular\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\PlanoCurricular\Enums\ComponentePlanoCurricular;
use Modules\PlanoCurricular\Enums\TipoDisciplinaPlano;

class AdicionarDisciplinaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('plano-curricular.editar') ?? false;
    }

    public function rules(): array
    {
        $estabelecimentoId = Estabelecimento::current()?->id;
        $planoId = $this->route('plano_curricular')->id;

        return [
            'disciplina_id' => [
                'required',
                'integer',
                Rule::exists('disciplinas', 'id')->where(fn ($q) => $q->where('estabelecimento_id', $estabelecimentoId)),
                Rule::unique('plano_curricular_disciplinas', 'disciplina_id')
                    ->where(fn ($q) => $q->where('plano_curricular_id', $planoId)->where('nivel_academico_id', $this->input('nivel_academico_id'))),
            ],
            'nivel_academico_id' => [
                'required',
                'integer',
                Rule::exists('niveis_academicos', 'id')->where(fn ($q) => $q->where('estabelecimento_id', $estabelecimentoId)),
            ],
            'carga_horaria' => ['nullable', 'integer', 'min:1'],
            'creditos' => ['nullable', 'integer', 'min:1'],
            'componente' => ['nullable', new Enum(ComponentePlanoCurricular::class)],
            'tipo' => ['required', new Enum(TipoDisciplinaPlano::class)],
            'obrigatoria' => ['required', 'boolean'],
            'ordem' => ['required', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'disciplina_id.exists' => 'A disciplina indicada não pertence a este estabelecimento.',
            'disciplina_id.unique' => 'Esta disciplina já está associada a este nível académico neste plano.',
            'nivel_academico_id.exists' => 'O nível académico indicado não pertence a este estabelecimento.',
        ];
    }
}
```

- [ ] **Step 6: Teste de `AdicionarDisciplinaRequest` — isolamento e duplicação**

```php
<?php

namespace Modules\PlanoCurricular\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Modules\Curso\Models\Curso;
use Modules\Disciplina\Models\Disciplina;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\PlanoCurricular\Http\Requests\AdicionarDisciplinaRequest;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\Turma\Models\NivelAcademico;
use Tests\TestCase;

class AdicionarDisciplinaRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejeita_disciplina_de_outro_estabelecimento(): void
    {
        $estabelecimentoA = Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $estabelecimentoB = Estabelecimento::create(['nome' => 'Escola B', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => false]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimentoA->id, 'codigo' => 'C1', 'nome' => 'Curso A']);
        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimentoA->id, 'curso_id' => $curso->id, 'codigo' => 'PC1', 'nome' => 'Plano A']);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimentoA->id, 'codigo' => 'N10', 'nome' => '10ª Classe', 'ordem' => 1]);
        $disciplinaDeOutroEstabelecimento = Disciplina::create(['estabelecimento_id' => $estabelecimentoB->id, 'codigo' => 'D1', 'nome' => 'Matemática']);

        $request = new AdicionarDisciplinaRequest();
        $request->setRouteResolver(fn () => tap(new \Illuminate\Routing\Route('POST', '/x', []), fn ($r) => $r->bind(new \Illuminate\Http\Request())->setParameter('plano_curricular', $plano)));

        $validator = Validator::make(
            ['disciplina_id' => $disciplinaDeOutroEstabelecimento->id, 'nivel_academico_id' => $nivel->id, 'tipo' => 0, 'obrigatoria' => true, 'ordem' => 1],
            $request->rules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('disciplina_id', $validator->errors()->toArray());
    }
}
```
(Se o binding manual de `route()` no teste unitário de Request se revelar frágil, mover este cenário específico para um teste HTTP de ponta-a-ponta na Task 14 em vez de testar a `Request` isolada — decisão de implementação, documentar no PR se mudar.)
Run: `php artisan test --filter=AdicionarDisciplinaRequestTest` — Expected: PASS.

- [ ] **Step 7: `AtualizarDisciplinaRequest`** (igual à de adicionar + `->ignore($item->id)` na regra de unicidade, recebendo `$this->route('disciplina')` ou nome de parâmetro definido nas rotas da Task 13).

- [ ] **Step 8: `ConfirmarAnoLectivoRequest` (isolamento de `ano_lectivo_id` + unicidade plano+ano)**

```php
<?php

namespace Modules\PlanoCurricular\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Estabelecimento\Models\Estabelecimento;

class ConfirmarAnoLectivoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('plano-curricular.editar') ?? false;
    }

    public function rules(): array
    {
        $estabelecimentoId = Estabelecimento::current()?->id;
        $planoId = $this->route('plano_curricular')->id;

        return [
            'ano_lectivo_id' => [
                'required',
                'integer',
                Rule::exists('ano_lectivos', 'id')->where(fn ($q) => $q->where('estabelecimento_id', $estabelecimentoId)),
                Rule::unique('plano_curricular_anos_lectivos', 'ano_lectivo_id')
                    ->where(fn ($q) => $q->where('plano_curricular_id', $planoId)),
            ],
            'observacoes' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'ano_lectivo_id.exists' => 'O ano lectivo indicado não pertence a este estabelecimento.',
            'ano_lectivo_id.unique' => 'Este plano já está confirmado para este ano lectivo.',
        ];
    }
}
```
Teste `ConfirmarAnoLectivoRequestTest` — replicar o molde do Step 6, cobrindo isolamento por estabelecimento e duplicação no mesmo ano.
Run: `php artisan test --filter=ConfirmarAnoLectivoRequestTest` — Expected: PASS.

**Tests:** `CriarPlanoCurricularRequestTest`, `AdicionarDisciplinaRequestTest`, `ConfirmarAnoLectivoRequestTest` (isolamento cross-entidade e unicidade a nível de validação, complementando as constraints de BD das Tasks 4-6).

**Critérios de aceitação:**
- Nenhuma Request aceita `curso_id`/`disciplina_id`/`nivel_academico_id`/`ano_lectivo_id` de outro estabelecimento — erro de validação, não erro 500 de FK.
- Mensagens de erro em PT-PT claras sobre a causa (não genéricas).

---

## Task 13 — Controller + Routes

**Files:**
- Create: `Modules/PlanoCurricular/app/Http/Controllers/PlanoCurricularController.php`
- Modify: `Modules/PlanoCurricular/routes/web.php`
- Create: `Modules/PlanoCurricular/tests/Feature/PlanoCurricularHttpTest.php`
- Create: `Modules/PlanoCurricular/tests/Feature/PlanoCurricularAutorizacaoTest.php`

**Interfaces:**
- Consumes: `GestaoPlanoCurricularService`, `PlanoCurricularConsultaService` (Task 11).
- Produces: rotas nomeadas `planos-curriculares.{index,store,show,update,alterar-estado,disciplinas.store,disciplinas.update,disciplinas.destroy,anos-lectivos.store}`.

- [ ] **Step 1: Controller**

```php
<?php

namespace Modules\PlanoCurricular\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Modules\Core\Enums\Estado;
use Modules\PlanoCurricular\Http\Requests\AdicionarDisciplinaRequest;
use Modules\PlanoCurricular\Http\Requests\AlterarEstadoPlanoCurricularRequest;
use Modules\PlanoCurricular\Http\Requests\AtualizarDisciplinaRequest;
use Modules\PlanoCurricular\Http\Requests\AtualizarPlanoCurricularRequest;
use Modules\PlanoCurricular\Http\Requests\ConfirmarAnoLectivoRequest;
use Modules\PlanoCurricular\Http\Requests\CriarPlanoCurricularRequest;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\PlanoCurricular\Services\GestaoPlanoCurricularService;
use Modules\PlanoCurricular\Services\PlanoCurricularConsultaService;

class PlanoCurricularController extends Controller
{
    public function __construct(
        private GestaoPlanoCurricularService $service,
        private PlanoCurricularConsultaService $consulta,
    ) {
    }

    public function index()
    {
        $this->authorize('plano-curricular.ver');

        return Inertia::render('PlanoCurricular/Index', [
            'planosCurriculares' => $this->consulta->listar(),
            'opcoes' => $this->consulta->opcoesFormulario(),
        ]);
    }

    public function show(PlanoCurricular $planoCurricular)
    {
        $this->authorize('plano-curricular.ver');

        $planoCurricular->load(['curso', 'disciplinas.disciplina', 'disciplinas.nivelAcademico', 'anosLectivos.anoLectivo', 'anosLectivos.confirmadoPor']);

        return Inertia::render('PlanoCurricular/Show', [
            'planoCurricular' => $planoCurricular,
            'opcoes' => $this->consulta->opcoesFormulario(),
        ]);
    }

    public function store(CriarPlanoCurricularRequest $request)
    {
        $this->authorize('plano-curricular.criar');

        $this->service->criar($request);

        return redirect()->back()->with('success', 'Plano curricular criado com sucesso.');
    }

    public function update(AtualizarPlanoCurricularRequest $request, PlanoCurricular $planoCurricular)
    {
        $this->authorize('plano-curricular.editar');

        $this->service->atualizar($planoCurricular, $request);

        return redirect()->back()->with('success', 'Plano curricular atualizado com sucesso.');
    }

    public function alterarEstado(AlterarEstadoPlanoCurricularRequest $request, PlanoCurricular $planoCurricular)
    {
        $this->authorize('plano-curricular.editar');

        $this->service->alterarEstado($planoCurricular, Estado::from((int) $request->validated('estado')));

        return redirect()->back()->with('success', 'Estado do plano curricular atualizado com sucesso.');
    }

    public function adicionarDisciplina(AdicionarDisciplinaRequest $request, PlanoCurricular $planoCurricular)
    {
        $this->authorize('plano-curricular.editar');

        $this->service->adicionarDisciplina($planoCurricular, $request);

        return redirect()->back()->with('success', 'Disciplina associada ao plano com sucesso.');
    }

    public function atualizarDisciplina(AtualizarDisciplinaRequest $request, PlanoCurricular $planoCurricular, PlanoCurricularDisciplina $disciplina)
    {
        $this->authorize('plano-curricular.editar');

        $this->service->atualizarDisciplina($disciplina, $request);

        return redirect()->back()->with('success', 'Disciplina do plano atualizada com sucesso.');
    }

    public function removerDisciplina(PlanoCurricular $planoCurricular, PlanoCurricularDisciplina $disciplina)
    {
        $this->authorize('plano-curricular.editar');

        $this->service->removerDisciplina($disciplina);

        return redirect()->back()->with('success', 'Disciplina removida do plano com sucesso.');
    }

    public function confirmarAnoLectivo(ConfirmarAnoLectivoRequest $request, PlanoCurricular $planoCurricular)
    {
        $this->authorize('plano-curricular.editar');

        $this->service->confirmarAnoLectivo($planoCurricular, $request, auth()->id());

        return redirect()->back()->with('success', 'Plano confirmado para o ano lectivo com sucesso.');
    }
}
```

- [ ] **Step 2: Routes**

```php
<?php

use Illuminate\Support\Facades\Route;
use Modules\PlanoCurricular\Http\Controllers\PlanoCurricularController;

Route::middleware(['auth'])->prefix('planos-curriculares')->name('planos-curriculares.')->group(function () {
    Route::get('/', [PlanoCurricularController::class, 'index'])->middleware('can:plano-curricular.ver')->name('index');
    Route::post('/', [PlanoCurricularController::class, 'store'])->middleware('can:plano-curricular.criar')->name('store');
    Route::get('/{planoCurricular}', [PlanoCurricularController::class, 'show'])->middleware('can:plano-curricular.ver')->name('show');
    Route::put('/{planoCurricular}', [PlanoCurricularController::class, 'update'])->middleware('can:plano-curricular.editar')->name('update');
    Route::patch('/{planoCurricular}/estado', [PlanoCurricularController::class, 'alterarEstado'])->middleware('can:plano-curricular.editar')->name('alterar-estado');

    Route::post('/{planoCurricular}/disciplinas', [PlanoCurricularController::class, 'adicionarDisciplina'])->middleware('can:plano-curricular.editar')->name('disciplinas.store');
    Route::put('/{planoCurricular}/disciplinas/{disciplina}', [PlanoCurricularController::class, 'atualizarDisciplina'])->middleware('can:plano-curricular.editar')->name('disciplinas.update');
    Route::delete('/{planoCurricular}/disciplinas/{disciplina}', [PlanoCurricularController::class, 'removerDisciplina'])->middleware('can:plano-curricular.editar')->name('disciplinas.destroy');

    Route::post('/{planoCurricular}/anos-lectivos', [PlanoCurricularController::class, 'confirmarAnoLectivo'])->middleware('can:plano-curricular.editar')->name('anos-lectivos.store');
});
```

- [ ] **Step 3: Teste HTTP de ponta-a-ponta (escrever cobrindo fluxo completo, rodar, deve passar dado tudo o que já foi implementado)**

`PlanoCurricularHttpTest` — replicar `CursoHttpTest`/`TurmaHttpTest`: `setUp()` com `$this->seed(PermissaoDatabaseSeeder::class)`, `actingAs` um `ADMIN_ESCOLA`, testar `index`/`store`/`show`/`update`/`alterar-estado`/`disciplinas.store`/`disciplinas.update`/`disciplinas.destroy`/`anos-lectivos.store`, incluindo:
- `test_index_expoe_apenas_planos_do_estabelecimento_actual` (cria 2 estabelecimentos, confirma isolamento, mesmo padrão de `CursoHttpTest`).
- `test_store_cria_plano_associado_ao_curso`.
- `test_disciplinas_store_associa_disciplina_ao_plano`.
- `test_disciplinas_store_rejeita_duplicar_mesma_disciplina_no_mesmo_nivel` (assert 422 / `assertSessionHasErrors`).
- `test_anos_lectivos_store_confirma_plano_para_ano_lectivo`.
- `test_anos_lectivos_store_permite_reconfirmar_plano_em_ano_diferente`.

- [ ] **Step 4: Teste de autorização**

`PlanoCurricularAutorizacaoTest` — replicar `Modules/Infraestrutura/tests/Feature/SalaAutorizacaoTest.php`: `ADMIN_ESCOLA` tem `plano-curricular.ver/criar/editar`; `PROFESSOR` não tem nenhuma (`Gate::forUser($professor)->allows('plano-curricular.ver')` é `false`).

Run: `php artisan test --filter="PlanoCurricularHttpTest|PlanoCurricularAutorizacaoTest"` — Expected: PASS.

- [ ] **Step 5: Rodar toda a suite do módulo**

Run: `php artisan test Modules/PlanoCurricular` — Expected: todos PASS, sem interferência entre testes.

**Tests:** `PlanoCurricularHttpTest` (CRUD completo + disciplinas + confirmação de ano lectivo + isolamento), `PlanoCurricularAutorizacaoTest` (perfil autorizado vs não autorizado nas 3 abilities).

**Critérios de aceitação:**
- Todas as rotas exigem `auth` + `can:plano-curricular.*` correspondente.
- `index`/`show` nunca vazam dados de outro estabelecimento.
- Fluxo de confirmação de ano lectivo funciona de ponta-a-ponta via HTTP.

---

## Task 14 — Testes de isolamento e histórico (cross-cutting)

**Files:**
- Create: `Modules/PlanoCurricular/tests/Feature/PlanoCurricularIsolamentoTest.php`
- Create: `Modules/PlanoCurricular/tests/Feature/PlanoCurricularHistoricoTest.php`

**Interfaces:** Nenhuma nova — testes de integração sobre tudo o que já existe (Tasks 4-13).

- [ ] **Step 1: `PlanoCurricularIsolamentoTest`**

Cobrir, via HTTP (`actingAs` um `ADMIN_ESCOLA` do estabelecimento A):
- Tentar `show`/`update`/`disciplinas.store`/`anos-lectivos.store` sobre um `PlanoCurricular` pertencente ao estabelecimento B → 404 (route-model-binding não encontra fora do escopo — **atenção**: como não há global scope, o binding do Laravel encontra o registo por `id` cru; replicar aqui o padrão real usado por `TurmaSala` em `TurmaHttpTest` — verificar explicitamente `estabelecimento_id` dentro do Controller/Request e devolver 404/403 manualmente se necessário, já que o Model não filtra sozinho). Este teste pode revelar a necessidade de um `abort_unless($planoCurricular->estabelecimento_id === Estabelecimento::current()?->id, 404)` no início de cada método do Controller — **se o teste falhar por vazamento cross-estabelecimento, adicionar essa guarda ao Controller da Task 13 e voltar a correr.**
- `store` com `curso_id`/`disciplina_id`/`nivel_academico_id`/`ano_lectivo_id` de outro estabelecimento → erro de validação (já coberto pelas Requests da Task 12, aqui é o teste de regressão de integração).

Run: `php artisan test --filter=PlanoCurricularIsolamentoTest` — Expected: PASS (se FAIL na guarda de `show`/`update` entre estabelecimentos, aplicar o fix acima antes de prosseguir).

- [ ] **Step 2: `PlanoCurricularHistoricoTest`**

Cenário completo da "regra histórica fundamental" via HTTP:
```
Plano A confirmado em 2026/2027 e 2027/2028 → Plano B criado e confirmado em 2028/2029 → Plano A permanece inalterado (nome, disciplinas, confirmações antigas intactas).
```
Assert explícito de que editar o Plano B (nome/disciplinas) não toca em nenhuma linha do Plano A nem das suas confirmações antigas.

Run: `php artisan test --filter=PlanoCurricularHistoricoTest` — Expected: PASS.

**Tests:** os dois ficheiros desta task.

**Critérios de aceitação:**
- Nenhum acesso cross-estabelecimento é possível em nenhuma rota do módulo (404, não vazamento de dados nem erro 500).
- Cenário 2026/27→2027/28 (Plano A) + 2028/29 (Plano B) passa sem qualquer mutação retroactiva do Plano A.

---

## Task 15 — Menu (Sidebar + dropdown Header)

**Files:**
- Modify: `resources/js/Components/Layout/SidebarMenuWrapper.vue`
- Modify: `resources/js/Composables/useAcademicoMenu.js`

**Interfaces:** Nenhuma nova — apenas dados de menu.

- [ ] **Step 1: `SidebarMenuWrapper.vue`**

No objecto `academico.items` (`resources/js/Components/Layout/SidebarMenuWrapper.vue:463-477`), adicionar, após o item `Cursos`:
```js
{ href: '/planos-curriculares', title: 'Planos Curriculares', permissao: 'plano-curricular.ver' },
```

- [ ] **Step 2: `useAcademicoMenu.js`**

Em `seccoesAcademico[0].links` (`resources/js/Composables/useAcademicoMenu.js:8-21`), adicionar, após `Cursos`:
```js
{ href: '/planos-curriculares', label: 'Planos Curriculares', permissao: 'plano-curricular.ver' },
```

- [ ] **Step 3: Verificação visual manual**

Com a app a correr (`npm run dev` + `php artisan serve`), autenticar como `ADMIN_ESCOLA` (credenciais em memória: `admin@mositec.gmail.com`), confirmar que "Planos Curriculares" aparece no grupo "Académico" da sidebar e no dropdown do header, e que navega para `/planos-curriculares`.

**Tests:** Sem teste automatizado dedicado (menu é dado estático filtrado por `podeVer`, já coberto pelos testes de `usePermissoes`/`useAcademicoMenu` existentes — nenhuma lógica nova a testar).

**Critérios de aceitação:**
- Item só visível para utilizadores com `plano-curricular.ver`.
- Sidebar e dropdown do Header mostram o mesmo item, consistentes entre si.

---

## Task 16 — Frontend: `Index.vue`, `Show.vue`, `PlanoCurricularFormModal.vue`

**Files:**
- Create: `Modules/PlanoCurricular/resources/js/Pages/Index.vue`
- Create: `Modules/PlanoCurricular/resources/js/Pages/Show.vue`
- Create: `Modules/PlanoCurricular/resources/js/Components/PlanoCurricularFormModal.vue`
- Create: `Modules/PlanoCurricular/resources/js/Components/Shared/EstadoBadge.vue` (cópia de `Modules/Curso/resources/js/Components/Shared/EstadoBadge.vue`)
- Create: `Modules/PlanoCurricular/resources/js/Models/Estado.js` (cópia de `Modules/Curso/resources/js/Models/Estado.js`)

**Interfaces:**
- Consumes: props Inertia `planosCurriculares`/`opcoes` (de `index`) e `planoCurricular`/`opcoes` (de `show`), tal como devolvidas pelo `PlanoCurricularController` (Task 13).

- [ ] **Step 1: `Estado.js` e `EstadoBadge.vue`**

Copiar exactamente `Modules/Curso/resources/js/Models/Estado.js` e `Modules/Curso/resources/js/Components/Shared/EstadoBadge.vue` para os novos paths, sem alterações (são genéricos ao par Ativo/Inativo).

- [ ] **Step 2: `PlanoCurricularFormModal.vue`**

Mirror campo-a-campo de `Modules/Curso/resources/js/Components/CursoFormModal.vue`, com os campos: `curso_id` (select, opções de `props.opcoes.cursos`), `codigo`, `nome`, `descricao` (textarea), e — só em edição — `estado` via `SelectSolid` (mesmo padrão condicional do `CursoFormModal`). **Não incluir `tipo_ensino`** (vem do estabelecimento actual, não é campo do formulário) nem `semestre`. Emite `submit` com o payload; `props: { show: Boolean, planoCurricular: Object|null, opcoes: Object }`.

- [ ] **Step 3: `Pages/Index.vue`**

Mirror de `Modules/Curso/resources/js/Pages/Index.vue`: tabela com colunas Código/Nome/Curso/Estado/Acções, botão "Novo Plano" condicionado a `can('plano-curricular.criar')`, abre `PlanoCurricularFormModal` para criar, `ConfirmModal` para alternar estado via `router.patch('/planos-curriculares/{id}/estado', {estado})`, link de cada linha para `Show.vue`.

- [ ] **Step 4: `Pages/Show.vue`**

Estrutura:
- Cabeçalho com dados do plano (`codigo`, `nome`, `curso.nome`, `EstadoBadge`), botão "Editar" (reabre `PlanoCurricularFormModal` em modo edição) e "Activar/Desactivar" condicionados a `can('plano-curricular.editar')`.
- Secção "Disciplinas": tabela agrupada por `nivelAcademico.nome` (ordenada por `ordem`), colunas Disciplina/Carga Horária/Créditos/Componente/Tipo/Obrigatória, botão "Adicionar Disciplina" (abre modal — Task 17) e acções Editar/Remover por linha, todas condicionadas a `can('plano-curricular.editar')`.
- Secção "Anos Lectivos": lista de `anosLectivos` (ano, estado, `confirmado_em`, `confirmado_por.name`, `observacoes`), botão "Confirmar para Ano Lectivo" (abre modal — Task 17).
- Usa `BotaoVoltar` (`@/Components/Shared/BotaoVoltar.vue`), mesmo padrão do `Curso/Show.vue`.

- [ ] **Step 5: Verificação manual — build + navegação**

Run: `npm run build` — Expected: sem erros. Depois, com o dev server a correr, criar um plano, abrir o `Show`, confirmar layout.

**Tests:** Nenhum teste automatizado de frontend nesta task (o projecto não tem suite JS — consistente com o resto do repositório); validação é `npm run build` sem erros + verificação manual no browser.

**Critérios de aceitação:**
- Nenhum campo `semestre` ou `tipo_ensino` manual em nenhum formulário.
- Alternar estado passa pelo endpoint dedicado `PATCH .../estado`, não pelo formulário principal.
- Layout visualmente consistente com `Curso`/`Disciplina` (mesmas classes Bootstrap/Metronic, mesmos componentes partilhados).

---

## Task 17 — Frontend: gestão de Disciplinas do Plano e confirmação de Ano Lectivo

**Files:**
- Create: `Modules/PlanoCurricular/resources/js/Components/DisciplinaPlanoFormModal.vue`
- Create: `Modules/PlanoCurricular/resources/js/Components/ConfirmarAnoLectivoModal.vue`

**Interfaces:**
- Consumes: `props.opcoes.disciplinas`, `props.opcoes.niveisAcademicos`, `props.opcoes.anosLectivos` (da Task 11/13).

- [ ] **Step 1: `DisciplinaPlanoFormModal.vue`**

Campos: `disciplina_id` (select), `nivel_academico_id` (select), `carga_horaria` (number, nullable), `creditos` (number, nullable), `componente` (select com opções `[{value:1,label:'Geral'},{value:2,label:'Técnica'},{value:3,label:'Prática'}]`, nullable — incluir opção vazia "Sem componente"), `tipo` (select `[{value:0,label:'Normal'},{value:1,label:'Estágio'},{value:2,label:'Optativa'},{value:3,label:'Projecto'}]`), `obrigatoria` (checkbox/switch), `ordem` (number). Submete via `router.post('/planos-curriculares/{plano}/disciplinas', form)` (criar) ou `router.put('/planos-curriculares/{plano}/disciplinas/{id}', form)` (editar), mesmo padrão `onSuccess/onError/onFinish` + `vue-sonner` do `CursoFormModal`.

- [ ] **Step 2: `ConfirmarAnoLectivoModal.vue`**

Campos: `ano_lectivo_id` (select, opções de `props.opcoes.anosLectivos` **excluindo** anos já presentes em `planoCurricular.anosLectivos`, calculado no `computed` do componente), `observacoes` (textarea, opcional). Submete via `router.post('/planos-curriculares/{plano}/anos-lectivos', form)`.

- [ ] **Step 3: Integrar os dois modais em `Show.vue`** (Task 16, Step 4) — abrir/fechar via `ref` local, mesmo padrão dos outros modais do projecto.

- [ ] **Step 4: Verificação manual completa do fluxo**

Com a app a correr: criar plano → adicionar 3-4 disciplinas com níveis/componentes/tipos diferentes → confirmar para um ano lectivo → tentar confirmar o mesmo ano de novo (deve mostrar erro "já confirmado") → confirmar para um segundo ano lectivo (deve funcionar).

Run: `npm run build` — Expected: sem erros.

**Tests:** Verificação manual (sem suite JS no projecto).

**Critérios de aceitação:**
- Select de Ano Lectivo na confirmação não lista anos já confirmados para aquele plano (UX preventiva, complementando a validação 422 do backend).
- Remover disciplina pede confirmação antes de executar (mesmo padrão `ConfirmModal` do resto do projecto).

---

## Task 18 (extensão opcional — NÃO implementar sem confirmação) — Clonagem de Plano

**Não faz parte da implementação inicial.** Documentado aqui apenas porque a spec pediu para "apresentar como extensão opcional".

**Desenho mínimo, se vier a ser pedido:**
- `Modules/PlanoCurricular/app/Actions/DuplicarPlanoCurricularAction.php::executar(PlanoCurricular $planoOrigem, string $novoCodigo, string $novoNome): PlanoCurricular` — cria um novo `PlanoCurricular` (mesmo `curso_id`/`estabelecimento_id`) e copia todas as `plano_curricular_disciplinas` do plano de origem (sem copiar `plano_curricular_anos_lectivos` — o novo plano começa sem nenhuma confirmação).
- Rota `POST /planos-curriculares/{planoCurricular}/duplicar`, permissão `plano-curricular.criar`.
- Botão "Duplicar" em `Show.vue`, pede `codigo`/`nome` do novo plano num modal simples.

Não abrir estes ficheiros nem tocar em rotas/menu para isto agora — só avançar se pedires explicitamente depois de reveres o resto do plano.

---

## Self-Review

**Cobertura da spec:** todas as secções da spec do utilizador mapeiam para uma task — `tipo_ensino` (Task 1), enum `Modulo`/permissões (Task 2), scaffold (Task 3), as 3 tabelas (Tasks 4-6), DTO/Action/Service/Request/Controller/Routes (Tasks 7-13), isolamento e histórico (Task 14), menu (Task 15), frontend (Tasks 16-17), seeder (coberto pela Task 3 — stub vazio, mesmo padrão não-chamado de `CursoDatabaseSeeder`, e pela Task 2 — `RolePermissaoSeeder` real, que é o que efectivamente corre em `php artisan db:seed`), clonagem (Task 18, opcional).

**Placeholders:** nenhum "TODO"/"implementar depois" nos passos obrigatórios (Tasks 1-17) — todo o código é completo e correcto para o padrão observado. A Task 18 é deliberadamente não-implementada por instrução explícita da spec ("apresentar como extensão opcional"), não um placeholder esquecido.

**Consistência de tipos:** `PlanoCurricularDTO`, `PlanoCurricularDisciplinaDTO`, `ConfirmarAnoLectivoDTO` (Task 7) usam exactamente os mesmos nomes de propriedade consumidos pelas Actions (Tasks 8-10) e produzidos pelas Requests (Task 12); `GestaoPlanoCurricularService` (Task 11) usa os mesmos nomes de método chamados pelo Controller (Task 13).
