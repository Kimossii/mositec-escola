# Plano Curricular sem Curso — Nível Académico como Âncora — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `PlanoCurricular` passa a ter `nivel_academico_id` obrigatório directamente no plano e `curso_id` opcional (espelhando `Turma`); `nivel_academico_id` sai de `PlanoCurricularDisciplina` (torna-se redundante); `NivelAcademico` ganha uma página "Show" com o mesmo card "Planos Curriculares" que `Curso` já tem, como ponto de entrada para etapas sem Curso (Creche, Pré-Escolar, Primário).

**Architecture:** Migração relacional (coluna nova + coluna relaxada numa tabela, coluna removida noutra) seguida da propagação em cascata pelas camadas já estabelecidas neste projecto (Model → DTO → Request → Action → Frontend), mais uma página Vue nova em Turma que replica a estrutura já existente em Curso.

**Tech Stack:** Laravel 12 + nwidart/laravel-modules, Inertia + Vue 3, PHPUnit (`Tests\TestCase`, `RefreshDatabase`, sqlite `:memory:` em testes).

**Spec:** `docs/superpowers/specs/2026-09-11-plano-curricular-sem-curso-design.md`

## Global Constraints

- **Uma única entidade `PlanoCurricular`** — nunca duas tabelas/módulos paralelos para "com curso" vs "sem curso".
- `PlanoCurricular.nivel_academico_id`: `NOT NULL`, `restrictOnDelete`. `PlanoCurricular.curso_id`: `nullable`, `restrictOnDelete` mantido quando preenchido.
- `PlanoCurricularDisciplina.nivel_academico_id` **é removido** — nunca reintroduzido como opcional "só por garantia".
- Criar um plano a partir do **Nível Académico não pergunta Curso** — fica sempre `null` nesse caminho (decisão confirmada; ligar um Curso depois só é possível por edição).
- Sem backfill nas migrações — mesma convenção já usada nesta sessão (`add_curso_id_to_turmas_table`, `add_etapa_ensino_to_niveis_academicos_table`): ambiente de desenvolvimento, sem planos reais ainda criados.
- Mecanismo de períodos (`PlanoCurricularDisciplinaPeriodo`) não é tocado — não referencia `curso_id` nem `nivel_academico_id` directamente.
- `use` sempre no topo de cada ficheiro PHP; nunca FQN inline.
- Controllers finos: leitura em `*ConsultaService`, escrita em `Gestao*Service` → `*Action`.

---

## Estrutura de Ficheiros

**Novo:**
```
Modules/PlanoCurricular/database/migrations/2026_09_11_140000_move_nivel_academico_from_disciplina_to_plano.php
Modules/Turma/resources/js/Pages/NiveisAcademicos/Show.vue
Modules/Turma/resources/js/Components/NivelAcademico/PlanosCurriculares/NovoPlanoCurricularModal.vue
```

**Modificado:**
```
Modules/PlanoCurricular/app/Models/PlanoCurricular.php              — + nivelAcademico()
Modules/PlanoCurricular/app/Models/PlanoCurricularDisciplina.php     — - nivelAcademico(), - fillable
Modules/Turma/app/Models/NivelAcademico.php                          — + planosCurriculares()
Modules/PlanoCurricular/app/DTO/PlanoCurricularDTO.php               — nivel_academico_id obrigatório, curso_id opcional
Modules/PlanoCurricular/app/Http/Requests/CriarPlanoCurricularRequest.php
Modules/PlanoCurricular/app/Http/Requests/AtualizarPlanoCurricularRequest.php
Modules/PlanoCurricular/app/Actions/CriarPlanoCurricularAction.php
Modules/PlanoCurricular/app/Actions/AtualizarPlanoCurricularAction.php
Modules/PlanoCurricular/app/DTO/PlanoCurricularDisciplinaDTO.php     — - nivel_academico_id
Modules/PlanoCurricular/app/Http/Requests/AdicionarDisciplinaRequest.php
Modules/PlanoCurricular/app/Http/Requests/AtualizarDisciplinaRequest.php
Modules/PlanoCurricular/app/Actions/AdicionarDisciplinaAoPlanoAction.php
Modules/PlanoCurricular/app/Actions/AtualizarDisciplinaDoPlanoAction.php
Modules/Curso/app/Http/Controllers/CursoController.php              — + niveisAcademicos na prop do Show
Modules/Curso/app/Services/CursoConsultaService.php                 — + niveisAcademicos()
Modules/Curso/resources/js/Components/PlanosCurriculares/NovoPlanoCurricularModal.vue — + select Nível
Modules/Curso/resources/js/Pages/Show.vue                           — passa niveisAcademicos ao modal
Modules/PlanoCurricular/resources/js/Components/PlanoCurricularFormModal.vue — + select Nível, Curso "Sem curso"
Modules/PlanoCurricular/resources/js/Components/DisciplinaPlanoFormModal.vue — - select Nível
Modules/PlanoCurricular/resources/js/Pages/Show.vue                 — mostra Nível, Curso "—" quando null
Modules/Turma/app/Http/Controllers/NivelAcademicoController.php     — + show()
Modules/Turma/app/Services/NivelAcademicoConsultaService.php        — + comRelacoes()
Modules/Turma/resources/js/Pages/NiveisAcademicos/Index.vue         — link para o Show
Modules/Turma/routes/web.php                                        — + rota show

Modules/PlanoCurricular/tests/Feature/PlanoCurricularModelTest.php
Modules/PlanoCurricular/tests/Feature/PlanoCurricularDisciplinaModelTest.php
Modules/PlanoCurricular/tests/Feature/CriarPlanoCurricularActionTest.php
Modules/PlanoCurricular/tests/Feature/CriarPlanoCurricularRequestTest.php
Modules/PlanoCurricular/tests/Feature/AtualizarPlanoCurricularActionTest.php
Modules/PlanoCurricular/tests/Feature/AlterarEstadoPlanoCurricularActionTest.php
Modules/PlanoCurricular/tests/Feature/AdicionarDisciplinaAoPlanoActionTest.php
Modules/PlanoCurricular/tests/Feature/AdicionarDisciplinaRequestTest.php
Modules/PlanoCurricular/tests/Feature/AtualizarDisciplinaDoPlanoActionTest.php
Modules/PlanoCurricular/tests/Feature/RemoverDisciplinaDoPlanoActionTest.php
Modules/PlanoCurricular/tests/Feature/PlanoCurricularHttpTest.php
Modules/PlanoCurricular/tests/Feature/PlanoCurricularIsolamentoTest.php
Modules/PlanoCurricular/tests/Feature/PlanoCurricularHistoricoTest.php
Modules/PlanoCurricular/tests/Feature/DefinirPeriodosDaDisciplinaActionTest.php
Modules/PlanoCurricular/tests/Feature/DefinirPeriodosDisciplinaRequestTest.php
Modules/PlanoCurricular/tests/Feature/PlanoCurricularDisciplinaPeriodoModelTest.php
Modules/PlanoCurricular/tests/Feature/PlanoCurricularAnoLectivoModelTest.php
Modules/PlanoCurricular/tests/Feature/ConfirmarPlanoParaAnoLectivoActionTest.php
Modules/PlanoCurricular/tests/Feature/ConfirmarAnoLectivoRequestTest.php
```

Ficheiros confirmados **sem alteração** (verificado ao escrever este plano):
`PlanoCurricularConsultaServiceTest.php` (não cria `PlanoCurricular`), `PlanoCurricularAutorizacaoTest.php` (só testa `Gate`), `Unit/EnumsTest.php` (enums não tocados), mecanismo de períodos em si (`PlanoCurricularDisciplinaPeriodo` model/Action/Request).

---

## Task 1: Migração + Models

**Files:**
- Create: `Modules/PlanoCurricular/database/migrations/2026_09_11_140000_move_nivel_academico_from_disciplina_to_plano.php`
- Modify: `Modules/PlanoCurricular/app/Models/PlanoCurricular.php`
- Modify: `Modules/PlanoCurricular/app/Models/PlanoCurricularDisciplina.php`
- Modify: `Modules/Turma/app/Models/NivelAcademico.php`
- Test: `Modules/PlanoCurricular/tests/Feature/PlanoCurricularModelTest.php` (só o teste novo)
- Test: `Modules/PlanoCurricular/tests/Feature/PlanoCurricularDisciplinaModelTest.php` (só o teste novo)

**Interfaces:**
- Produces: `PlanoCurricular::nivelAcademico(): BelongsTo`; `NivelAcademico::planosCurriculares(): HasMany`. Consumido pelas Tasks 2, 5, 6.

**Nota sobre esta task ficar "vermelha":** depois desta task, **toda a suite de `PlanoCurricular` fica vermelha** excepto os dois testes novos que esta task acrescenta — qualquer `PlanoCurricular::create([...])` sem `nivel_academico_id` falha com violação `NOT NULL`, e qualquer `PlanoCurricularDisciplina::create([...'nivel_academico_id' => ...])` falha com "coluna desconhecida". Isto é intencional: as Tasks 2-4 corrigem os ficheiros de teste existentes por grupos.

- [ ] **Step 1: Escrever os dois testes novos**

Em `Modules/PlanoCurricular/tests/Feature/PlanoCurricularModelTest.php`, adicionar o import:

```php
use Modules\Turma\Models\NivelAcademico;
```

E o teste, a seguir a `test_nao_possui_colunas_ano_lectivo_id_nem_modalidade`:

```php
    public function test_pertence_a_um_nivel_academico_obrigatorio_e_curso_e_opcional(): void
    {
        $estabelecimento = $this->estabelecimento();
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'CR', 'nome' => 'Creche I', 'ordem' => 1, 'etapa_ensino' => 1]);

        $plano = PlanoCurricular::create([
            'estabelecimento_id' => $estabelecimento->id,
            'nivel_academico_id' => $nivel->id,
            'codigo' => 'PC-CRECHE',
            'nome' => 'Plano Creche',
        ]);

        $this->assertNull($plano->curso_id);
        $this->assertSame($nivel->id, $plano->nivelAcademico->id);
    }
```

Em `Modules/PlanoCurricular/tests/Feature/PlanoCurricularDisciplinaModelTest.php`, adicionar o teste, a seguir a `test_nao_possui_coluna_semestre`:

```php
    public function test_nao_possui_coluna_nivel_academico_id(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('plano_curricular_disciplinas', 'nivel_academico_id'));
    }
```

- [ ] **Step 2: Correr os dois testes novos e confirmar que falham**

```bash
php artisan test --filter=test_pertence_a_um_nivel_academico_obrigatorio_e_curso_e_opcional
php artisan test --filter=test_nao_possui_coluna_nivel_academico_id
```

Esperado: FAIL em ambos (coluna `nivel_academico_id` não existe em `planos_curriculares`; coluna `nivel_academico_id` ainda existe em `plano_curricular_disciplinas`).

- [ ] **Step 3: Criar a migração**

`Modules/PlanoCurricular/database/migrations/2026_09_11_140000_move_nivel_academico_from_disciplina_to_plano.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planos_curriculares', function (Blueprint $table) {
            $table->foreignId('curso_id')->nullable()->change();
            $table->foreignId('nivel_academico_id')->after('curso_id')
                ->constrained('niveis_academicos')->restrictOnDelete();
        });

        Schema::table('plano_curricular_disciplinas', function (Blueprint $table) {
            $table->dropUnique('plano_disciplina_nivel_unique');
            $table->dropForeign(['nivel_academico_id']);
            $table->dropColumn('nivel_academico_id');
            $table->unique(['plano_curricular_id', 'disciplina_id']);
        });
    }

    public function down(): void
    {
        Schema::table('plano_curricular_disciplinas', function (Blueprint $table) {
            $table->dropUnique(['plano_curricular_id', 'disciplina_id']);
            $table->foreignId('nivel_academico_id')->nullable()
                ->constrained('niveis_academicos')->restrictOnDelete();
            $table->unique(['plano_curricular_id', 'disciplina_id', 'nivel_academico_id'], 'plano_disciplina_nivel_unique');
        });

        Schema::table('planos_curriculares', function (Blueprint $table) {
            $table->dropForeign(['nivel_academico_id']);
            $table->dropColumn('nivel_academico_id');
            $table->foreignId('curso_id')->nullable(false)->change();
        });
    }
};
```

- [ ] **Step 4: Actualizar os Models**

`Modules/PlanoCurricular/app/Models/PlanoCurricular.php` — adicionar o import e o método, a seguir a `curso()`:

```php
use Modules\Turma\Models\NivelAcademico;
```

```php
    public function nivelAcademico(): BelongsTo
    {
        return $this->belongsTo(NivelAcademico::class);
    }
```

`Modules/PlanoCurricular/app/Models/PlanoCurricularDisciplina.php` — remover o método `nivelAcademico()`, remover o import `Modules\Turma\Models\NivelAcademico` (deixa de ser usado neste ficheiro), remover `'nivel_academico_id'` de `$fillable`.

`Modules/Turma/app/Models/NivelAcademico.php` — adicionar o import e o método, a seguir a `turmas()`:

```php
use Modules\PlanoCurricular\Models\PlanoCurricular;
```

```php
    public function planosCurriculares(): HasMany
    {
        return $this->hasMany(PlanoCurricular::class);
    }
```

(`HasMany` já está importado neste ficheiro.)

- [ ] **Step 5: Correr os dois testes novos e confirmar que passam**

```bash
php artisan test --filter=test_pertence_a_um_nivel_academico_obrigatorio_e_curso_e_opcional
php artisan test --filter=test_nao_possui_coluna_nivel_academico_id
```

Esperado: PASS em ambos.

- [ ] **Step 6: Commit**

```bash
git add Modules/PlanoCurricular/database/migrations/2026_09_11_140000_move_nivel_academico_from_disciplina_to_plano.php \
        Modules/PlanoCurricular/app/Models/PlanoCurricular.php \
        Modules/PlanoCurricular/app/Models/PlanoCurricularDisciplina.php \
        Modules/Turma/app/Models/NivelAcademico.php \
        Modules/PlanoCurricular/tests/Feature/PlanoCurricularModelTest.php \
        Modules/PlanoCurricular/tests/Feature/PlanoCurricularDisciplinaModelTest.php
git commit -m "feat(plano-curricular): nivel_academico_id passa do plano_curricular_disciplinas para planos_curriculares"
```

---

## Task 2: `PlanoCurricular` — DTO, Requests, Actions (nível obrigatório, curso opcional)

**Files:**
- Modify: `Modules/PlanoCurricular/app/DTO/PlanoCurricularDTO.php`
- Modify: `Modules/PlanoCurricular/app/Http/Requests/CriarPlanoCurricularRequest.php`
- Modify: `Modules/PlanoCurricular/app/Http/Requests/AtualizarPlanoCurricularRequest.php`
- Modify: `Modules/PlanoCurricular/app/Actions/CriarPlanoCurricularAction.php`
- Modify: `Modules/PlanoCurricular/app/Actions/AtualizarPlanoCurricularAction.php`
- Modify: `Modules/PlanoCurricular/tests/Feature/CriarPlanoCurricularActionTest.php`
- Modify: `Modules/PlanoCurricular/tests/Feature/CriarPlanoCurricularRequestTest.php`
- Modify: `Modules/PlanoCurricular/tests/Feature/AtualizarPlanoCurricularActionTest.php`
- Modify: `Modules/PlanoCurricular/tests/Feature/AlterarEstadoPlanoCurricularActionTest.php`
- Modify: `Modules/PlanoCurricular/tests/Feature/PlanoCurricularModelTest.php` (os 4 testes antigos que faltam corrigir)

**Interfaces:**
- Consumes: `Modules\Turma\Models\NivelAcademico` (Task 1).
- Produces: `PlanoCurricularDTO(nivel_academico_id: int, codigo: string, nome: string, curso_id: ?int = null, descricao: ?string = null)` — assinatura nova, consumida pelas Actions desta task.

- [ ] **Step 1: Corrigir os testes existentes e escrever o teste novo**

Em cada um dos ficheiros abaixo, toda a criação de `PlanoCurricular` (via `PlanoCurricular::create()` ou `new PlanoCurricularDTO(...)`) precisa de um `NivelAcademico` fixture e de `nivel_academico_id` no payload/DTO.

**`CriarPlanoCurricularActionTest.php`** — adicionar `use Modules\Turma\Models\NivelAcademico;`, e trocar o teste:

```php
    public function test_cria_plano_com_estabelecimento_actual(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'C1', 'nome' => 'Curso A']);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'N10', 'nome' => '10ª Classe', 'ordem' => 1, 'etapa_ensino' => 4]);

        $plano = (new CriarPlanoCurricularAction())->executar(new PlanoCurricularDTO(
            nivel_academico_id: $nivel->id, codigo: 'PC1', nome: 'Plano 1', curso_id: $curso->id, descricao: 'Descrição',
        ));

        $this->assertSame($estabelecimento->id, $plano->estabelecimento_id);
        $this->assertSame($curso->id, $plano->curso_id);
        $this->assertSame($nivel->id, $plano->nivel_academico_id);
        $this->assertSame('PC1', $plano->codigo);
    }

    public function test_cria_plano_sem_curso(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'CR', 'nome' => 'Creche I', 'ordem' => 1, 'etapa_ensino' => 1]);

        $plano = (new CriarPlanoCurricularAction())->executar(new PlanoCurricularDTO(
            nivel_academico_id: $nivel->id, codigo: 'PC-CR', nome: 'Plano Creche',
        ));

        $this->assertNull($plano->curso_id);
        $this->assertSame($nivel->id, $plano->nivel_academico_id);
    }
```

**`CriarPlanoCurricularRequestTest.php`** — adicionar `use Modules\Turma\Models\NivelAcademico;`, e trocar os dois testes:

```php
    public function test_rejeita_curso_de_outro_estabelecimento(): void
    {
        $estabelecimentoA = Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $estabelecimentoB = Estabelecimento::create(['nome' => 'Escola B', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => false]);
        $cursoDeOutroEstabelecimento = Curso::create(['estabelecimento_id' => $estabelecimentoB->id, 'codigo' => 'C1', 'nome' => 'Curso B']);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimentoA->id, 'codigo' => 'N10', 'nome' => '10ª Classe', 'ordem' => 1, 'etapa_ensino' => 4]);

        $validator = Validator::make(
            ['curso_id' => $cursoDeOutroEstabelecimento->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'PC1', 'nome' => 'Plano 1'],
            (new CriarPlanoCurricularRequest)->rules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('curso_id', $validator->errors()->toArray());
    }

    public function test_mesmo_codigo_em_estabelecimentos_diferentes_e_permitido(): void
    {
        Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $curso = Curso::create(['estabelecimento_id' => Estabelecimento::current()->id, 'codigo' => 'C1', 'nome' => 'Curso A']);
        $nivel = NivelAcademico::create(['estabelecimento_id' => Estabelecimento::current()->id, 'codigo' => 'N10', 'nome' => '10ª Classe', 'ordem' => 1, 'etapa_ensino' => 4]);

        $validator = Validator::make(
            ['curso_id' => $curso->id, 'nivel_academico_id' => $nivel->id, 'codigo' => 'PC1', 'nome' => 'Plano 1'],
            (new CriarPlanoCurricularRequest)->rules(),
        );

        $this->assertFalse($validator->fails());
    }

    public function test_aceita_sem_curso_id(): void
    {
        Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $nivel = NivelAcademico::create(['estabelecimento_id' => Estabelecimento::current()->id, 'codigo' => 'CR', 'nome' => 'Creche I', 'ordem' => 1, 'etapa_ensino' => 1]);

        $validator = Validator::make(
            ['nivel_academico_id' => $nivel->id, 'codigo' => 'PC1', 'nome' => 'Plano 1'],
            (new CriarPlanoCurricularRequest)->rules(),
        );

        $this->assertFalse($validator->fails());
    }
```

**`AtualizarPlanoCurricularActionTest.php`** — adicionar `use Modules\Turma\Models\NivelAcademico;`, e nos dois testes existentes, acrescentar a criação de `$nivel` e `nivel_academico_id: $nivel->id` no `PlanoCurricularDTO`:

```php
    public function test_atualiza_curso_codigo_nome_e_descricao(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);
        $outroCurso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'GES', 'nome' => 'Gestão']);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'N10', 'nome' => '10ª Classe', 'ordem' => 1, 'etapa_ensino' => 4]);
        $plano = PlanoCurricular::create([
            'estabelecimento_id' => $estabelecimento->id,
            'nivel_academico_id' => $nivel->id,
            'curso_id' => $curso->id,
            'codigo' => 'PC1',
            'nome' => 'Plano 1',
        ]);

        $atualizado = (new AtualizarPlanoCurricularAction())->executar($plano, new PlanoCurricularDTO(
            nivel_academico_id: $nivel->id,
            curso_id: $outroCurso->id,
            codigo: 'PC2',
            nome: 'Plano 2',
            descricao: 'Descrição nova',
        ));

        $this->assertSame($outroCurso->id, $atualizado->curso_id);
        $this->assertSame('PC2', $atualizado->codigo);
        $this->assertSame('Plano 2', $atualizado->nome);
        $this->assertSame('Descrição nova', $atualizado->descricao);
        $this->assertSame($estabelecimento->id, $atualizado->estabelecimento_id);
    }

    public function test_nao_altera_estabelecimento_id_nem_estado(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'N10', 'nome' => '10ª Classe', 'ordem' => 1, 'etapa_ensino' => 4]);
        $plano = PlanoCurricular::create([
            'estabelecimento_id' => $estabelecimento->id,
            'nivel_academico_id' => $nivel->id,
            'curso_id' => $curso->id,
            'codigo' => 'PC1',
            'nome' => 'Plano 1',
        ]);

        $atualizado = (new AtualizarPlanoCurricularAction())->executar($plano, new PlanoCurricularDTO(
            nivel_academico_id: $nivel->id,
            curso_id: $curso->id,
            codigo: 'PC1',
            nome: 'Plano 1 renomeado',
        ));

        $this->assertSame($estabelecimento->id, $atualizado->estabelecimento_id);
        $this->assertSame($plano->estado, $atualizado->estado);
    }
```

**`AlterarEstadoPlanoCurricularActionTest.php`** — adicionar `use Modules\Turma\Models\NivelAcademico;`, e em ambos os testes acrescentar a criação de `$nivel` e `'nivel_academico_id' => $nivel->id` ao `PlanoCurricular::create([...])`:

```php
    public function test_desactiva_plano_e_sincroniza_descricao(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'N10', 'nome' => '10ª Classe', 'ordem' => 1, 'etapa_ensino' => 4]);
        $plano = PlanoCurricular::create([
            'estabelecimento_id' => $estabelecimento->id,
            'nivel_academico_id' => $nivel->id,
            'curso_id' => $curso->id,
            'codigo' => 'PC1',
            'nome' => 'Plano 1',
        ]);

        $atualizado = (new AlterarEstadoPlanoCurricularAction())->executar($plano, Estado::INATIVO);

        $this->assertSame(Estado::INATIVO->value, $atualizado->estado);
        $this->assertSame('Inativo', $atualizado->estado_descricao);
    }

    public function test_nao_altera_outros_campos(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'N10', 'nome' => '10ª Classe', 'ordem' => 1, 'etapa_ensino' => 4]);
        $plano = PlanoCurricular::create([
            'estabelecimento_id' => $estabelecimento->id,
            'nivel_academico_id' => $nivel->id,
            'curso_id' => $curso->id,
            'codigo' => 'PC1',
            'nome' => 'Plano 1',
            'descricao' => 'Descrição original',
        ]);

        $atualizado = (new AlterarEstadoPlanoCurricularAction())->executar($plano, Estado::INATIVO);

        $this->assertSame('PC1', $atualizado->codigo);
        $this->assertSame('Plano 1', $atualizado->nome);
        $this->assertSame('Descrição original', $atualizado->descricao);
        $this->assertSame($curso->id, $atualizado->curso_id);
        $this->assertSame($estabelecimento->id, $atualizado->estabelecimento_id);
    }
```

**`PlanoCurricularModelTest.php`** — os 4 testes que ainda faltam (o `use NivelAcademico` já foi adicionado na Task 1), acrescentar `$nivel` e `'nivel_academico_id' => $nivel->id`/`$cursoA->id`/etc. a cada `PlanoCurricular::create([...])`:

```php
    public function test_cria_plano_pertencente_a_curso_e_estabelecimento(): void
    {
        $estabelecimento = $this->estabelecimento();
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'C1', 'nome' => 'Curso A']);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'N10', 'nome' => '10ª Classe', 'ordem' => 1, 'etapa_ensino' => 4]);

        $plano = PlanoCurricular::create([
            'estabelecimento_id' => $estabelecimento->id,
            'nivel_academico_id' => $nivel->id,
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
        $user = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        $this->actingAs($user);
        $estabelecimento = $this->estabelecimento();
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'C1', 'nome' => 'Curso A']);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'N10', 'nome' => '10ª Classe', 'ordem' => 1, 'etapa_ensino' => 4]);

        $plano = PlanoCurricular::create([
            'estabelecimento_id' => $estabelecimento->id,
            'nivel_academico_id' => $nivel->id,
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
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'N10', 'nome' => '10ª Classe', 'ordem' => 1, 'etapa_ensino' => 4]);
        PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'codigo' => 'PC-2026', 'nome' => 'Plano 2026']);

        $this->expectException(QueryException::class);
        PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'codigo' => 'PC-2026', 'nome' => 'Plano Duplicado']);
    }

    public function test_mesmo_codigo_em_estabelecimentos_diferentes_e_permitido(): void
    {
        $estabelecimentoA = $this->estabelecimento();
        Estabelecimento::where('id', $estabelecimentoA->id)->update(['is_active' => false]);
        $estabelecimentoB = Estabelecimento::create(['nome' => 'Escola B', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $cursoA = Curso::create(['estabelecimento_id' => $estabelecimentoA->id, 'codigo' => 'C1', 'nome' => 'Curso A']);
        $cursoB = Curso::create(['estabelecimento_id' => $estabelecimentoB->id, 'codigo' => 'C1', 'nome' => 'Curso B']);
        $nivelA = NivelAcademico::create(['estabelecimento_id' => $estabelecimentoA->id, 'codigo' => 'N10', 'nome' => '10ª Classe', 'ordem' => 1, 'etapa_ensino' => 4]);
        $nivelB = NivelAcademico::create(['estabelecimento_id' => $estabelecimentoB->id, 'codigo' => 'N10', 'nome' => '10ª Classe', 'ordem' => 1, 'etapa_ensino' => 4]);

        PlanoCurricular::create(['estabelecimento_id' => $estabelecimentoA->id, 'nivel_academico_id' => $nivelA->id, 'curso_id' => $cursoA->id, 'codigo' => 'PC-2026', 'nome' => 'Plano A']);
        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimentoB->id, 'nivel_academico_id' => $nivelB->id, 'curso_id' => $cursoB->id, 'codigo' => 'PC-2026', 'nome' => 'Plano B']);

        $this->assertNotNull($plano->id);
    }
```

- [ ] **Step 2: Correr os ficheiros afectados e confirmar o padrão de falhas**

```bash
php artisan test --filter="CriarPlanoCurricularActionTest|CriarPlanoCurricularRequestTest|AtualizarPlanoCurricularActionTest|AlterarEstadoPlanoCurricularActionTest|PlanoCurricularModelTest"
```

Esperado: FAIL — `nivel_academico_id` ainda não é aceite pelo DTO/Request/Action (o construtor `PlanoCurricularDTO` ainda não tem este parâmetro).

- [ ] **Step 3: Actualizar `PlanoCurricularDTO`**

`Modules/PlanoCurricular/app/DTO/PlanoCurricularDTO.php`:

```php
<?php

namespace Modules\PlanoCurricular\DTO;

use Modules\PlanoCurricular\Http\Requests\AtualizarPlanoCurricularRequest;
use Modules\PlanoCurricular\Http\Requests\CriarPlanoCurricularRequest;

class PlanoCurricularDTO
{
    public function __construct(
        public int $nivel_academico_id,
        public string $codigo,
        public string $nome,
        public ?int $curso_id = null,
        public ?string $descricao = null,
    ) {
    }

    public static function fromCriarRequest(CriarPlanoCurricularRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            nivel_academico_id: (int) $dados['nivel_academico_id'],
            codigo: $dados['codigo'],
            nome: $dados['nome'],
            curso_id: isset($dados['curso_id']) ? (int) $dados['curso_id'] : null,
            descricao: $dados['descricao'] ?? null,
        );
    }

    public static function fromAtualizarRequest(AtualizarPlanoCurricularRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            nivel_academico_id: (int) $dados['nivel_academico_id'],
            codigo: $dados['codigo'],
            nome: $dados['nome'],
            curso_id: isset($dados['curso_id']) ? (int) $dados['curso_id'] : null,
            descricao: $dados['descricao'] ?? null,
        );
    }
}
```

- [ ] **Step 4: Actualizar as Requests**

`Modules/PlanoCurricular/app/Http/Requests/CriarPlanoCurricularRequest.php`:

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
            'nivel_academico_id' => [
                'required',
                'integer',
                Rule::exists('niveis_academicos', 'id')->where(fn ($q) => $q->where('estabelecimento_id', $estabelecimentoId)),
            ],
            'curso_id' => [
                'nullable',
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
            'nivel_academico_id.required' => 'O nível académico é obrigatório.',
            'nivel_academico_id.exists' => 'O nível académico indicado não pertence a este estabelecimento.',
            'curso_id.exists' => 'O curso indicado não pertence a este estabelecimento.',
            'codigo.required' => 'O código do plano é obrigatório.',
            'codigo.unique' => 'Já existe um plano curricular com este código neste estabelecimento.',
            'nome.required' => 'O nome do plano é obrigatório.',
        ];
    }
}
```

`Modules/PlanoCurricular/app/Http/Requests/AtualizarPlanoCurricularRequest.php` — mesma estrutura, com o `->ignore($this->route('planoCurricular'))` já existente na regra `codigo`:

```php
<?php

namespace Modules\PlanoCurricular\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Estabelecimento\Models\Estabelecimento;

class AtualizarPlanoCurricularRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('plano-curricular.editar') ?? false;
    }

    public function rules(): array
    {
        $estabelecimentoId = Estabelecimento::current()?->id;

        return [
            'nivel_academico_id' => [
                'required',
                'integer',
                Rule::exists('niveis_academicos', 'id')->where(fn ($q) => $q->where('estabelecimento_id', $estabelecimentoId)),
            ],
            'curso_id' => [
                'nullable',
                'integer',
                Rule::exists('cursos', 'id')->where(fn ($q) => $q->where('estabelecimento_id', $estabelecimentoId)),
            ],
            'codigo' => [
                'required',
                'string',
                'max:50',
                Rule::unique('planos_curriculares', 'codigo')
                    ->where(fn ($q) => $q->where('estabelecimento_id', $estabelecimentoId))
                    ->ignore($this->route('planoCurricular')),
            ],
            'nome' => ['required', 'string', 'max:255'],
            'descricao' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'nivel_academico_id.required' => 'O nível académico é obrigatório.',
            'nivel_academico_id.exists' => 'O nível académico indicado não pertence a este estabelecimento.',
            'curso_id.exists' => 'O curso indicado não pertence a este estabelecimento.',
            'codigo.required' => 'O código do plano é obrigatório.',
            'codigo.unique' => 'Já existe um plano curricular com este código neste estabelecimento.',
            'nome.required' => 'O nome do plano é obrigatório.',
        ];
    }
}
```

- [ ] **Step 5: Actualizar as Actions**

`Modules/PlanoCurricular/app/Actions/CriarPlanoCurricularAction.php`:

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
            'nivel_academico_id' => $dto->nivel_academico_id,
            'curso_id' => $dto->curso_id,
            'codigo' => $dto->codigo,
            'nome' => $dto->nome,
            'descricao' => $dto->descricao,
        ]);
    }
}
```

`Modules/PlanoCurricular/app/Actions/AtualizarPlanoCurricularAction.php`:

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
            'nivel_academico_id' => $dto->nivel_academico_id,
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

- [ ] **Step 6: Correr os ficheiros afectados e confirmar que passam**

```bash
php artisan test --filter="CriarPlanoCurricularActionTest|CriarPlanoCurricularRequestTest|AtualizarPlanoCurricularActionTest|AlterarEstadoPlanoCurricularActionTest|PlanoCurricularModelTest"
```

Esperado: PASS em todos, incluindo os 2 testes novos.

- [ ] **Step 7: Commit**

```bash
git add Modules/PlanoCurricular/app/DTO/PlanoCurricularDTO.php \
        Modules/PlanoCurricular/app/Http/Requests/CriarPlanoCurricularRequest.php \
        Modules/PlanoCurricular/app/Http/Requests/AtualizarPlanoCurricularRequest.php \
        Modules/PlanoCurricular/app/Actions/CriarPlanoCurricularAction.php \
        Modules/PlanoCurricular/app/Actions/AtualizarPlanoCurricularAction.php \
        Modules/PlanoCurricular/tests/Feature/CriarPlanoCurricularActionTest.php \
        Modules/PlanoCurricular/tests/Feature/CriarPlanoCurricularRequestTest.php \
        Modules/PlanoCurricular/tests/Feature/AtualizarPlanoCurricularActionTest.php \
        Modules/PlanoCurricular/tests/Feature/AlterarEstadoPlanoCurricularActionTest.php \
        Modules/PlanoCurricular/tests/Feature/PlanoCurricularModelTest.php
git commit -m "feat(plano-curricular): DTO/Requests/Actions do plano com nivel_academico_id obrigatorio e curso_id opcional"
```

---

## Task 3: `PlanoCurricularDisciplina` — DTO, Requests, Actions (nível sai daqui)

**Files:**
- Modify: `Modules/PlanoCurricular/app/DTO/PlanoCurricularDisciplinaDTO.php`
- Modify: `Modules/PlanoCurricular/app/Http/Requests/AdicionarDisciplinaRequest.php`
- Modify: `Modules/PlanoCurricular/app/Http/Requests/AtualizarDisciplinaRequest.php`
- Modify: `Modules/PlanoCurricular/app/Actions/AdicionarDisciplinaAoPlanoAction.php`
- Modify: `Modules/PlanoCurricular/app/Actions/AtualizarDisciplinaDoPlanoAction.php`
- Modify: `Modules/PlanoCurricular/tests/Feature/AdicionarDisciplinaAoPlanoActionTest.php`
- Modify: `Modules/PlanoCurricular/tests/Feature/AdicionarDisciplinaRequestTest.php`
- Modify: `Modules/PlanoCurricular/tests/Feature/AtualizarDisciplinaDoPlanoActionTest.php`
- Modify: `Modules/PlanoCurricular/tests/Feature/RemoverDisciplinaDoPlanoActionTest.php`
- Modify: `Modules/PlanoCurricular/tests/Feature/PlanoCurricularDisciplinaModelTest.php` (os 4 testes antigos)

**Interfaces:**
- Consumes: `PlanoCurricular::nivel_academico_id` (Task 2) — a disciplina já não precisa de saber o nível, o plano-pai já sabe.
- Produces: `PlanoCurricularDisciplinaDTO(disciplina_id: int, tipo: TipoDisciplinaPlano, obrigatoria: bool, ordem: int, carga_horaria: ?int = null, creditos: ?int = null, componente: ?ComponentePlanoCurricular = null)` — sem `nivel_academico_id`.

- [ ] **Step 1: Corrigir os testes e escrever o padrão esperado**

**`AdicionarDisciplinaAoPlanoActionTest.php`**:

```php
    public function test_adiciona_disciplina_ao_plano(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);
        $nivelAcademico = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'N10', 'nome' => '10ª Classe', 'ordem' => 1, 'etapa_ensino' => 4]);
        $plano = PlanoCurricular::create([
            'estabelecimento_id' => $estabelecimento->id,
            'nivel_academico_id' => $nivelAcademico->id,
            'curso_id' => $curso->id,
            'codigo' => 'PC1',
            'nome' => 'Plano 1',
        ]);
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'MAT', 'nome' => 'Matemática']);

        $item = (new AdicionarDisciplinaAoPlanoAction())->executar($plano, new PlanoCurricularDisciplinaDTO(
            disciplina_id: $disciplina->id,
            tipo: TipoDisciplinaPlano::NORMAL,
            obrigatoria: true,
            ordem: 1,
            carga_horaria: 90,
            creditos: 4,
            componente: ComponentePlanoCurricular::GERAL,
        ));

        $this->assertSame($plano->id, $item->plano_curricular_id);
        $this->assertSame($disciplina->id, $item->disciplina_id);
        $this->assertSame(90, $item->carga_horaria);
        $this->assertSame(4, $item->creditos);
        $this->assertSame(ComponentePlanoCurricular::GERAL, $item->componente);
        $this->assertSame(TipoDisciplinaPlano::NORMAL, $item->tipo);
        $this->assertTrue($item->obrigatoria);
        $this->assertSame(1, $item->ordem);
        $this->assertSame('Geral', $item->componente_descricao);
        $this->assertSame('Normal', $item->tipo_descricao);
    }
```

**`AdicionarDisciplinaRequestTest.php`**:

```php
    public function test_rejeita_disciplina_de_outro_estabelecimento(): void
    {
        $estabelecimentoA = Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $estabelecimentoB = Estabelecimento::create(['nome' => 'Escola B', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => false]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimentoA->id, 'codigo' => 'C1', 'nome' => 'Curso A']);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimentoA->id, 'codigo' => 'N10', 'nome' => '10ª Classe', 'ordem' => 1, 'etapa_ensino' => 4]);
        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimentoA->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'codigo' => 'PC1', 'nome' => 'Plano A']);
        $disciplinaDeOutroEstabelecimento = Disciplina::create(['estabelecimento_id' => $estabelecimentoB->id, 'codigo' => 'D1', 'nome' => 'Matemática']);

        $request = new AdicionarDisciplinaRequest;
        $request->setRouteResolver(fn () => tap(new Route('POST', '/x', []), fn ($r) => $r->bind(new Request)->setParameter('planoCurricular', $plano)));

        $validator = Validator::make(
            ['disciplina_id' => $disciplinaDeOutroEstabelecimento->id, 'tipo' => 0, 'obrigatoria' => true, 'ordem' => 1],
            $request->rules(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('disciplina_id', $validator->errors()->toArray());
    }
```

(`NivelAcademico` já está importado neste ficheiro.)

**`AtualizarDisciplinaDoPlanoActionTest.php`**:

```php
    public function test_atualiza_campos_da_disciplina_do_plano(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);
        $nivelAcademico = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'N10', 'nome' => '10ª Classe', 'ordem' => 1, 'etapa_ensino' => 4]);
        $plano = PlanoCurricular::create([
            'estabelecimento_id' => $estabelecimento->id,
            'nivel_academico_id' => $nivelAcademico->id,
            'curso_id' => $curso->id,
            'codigo' => 'PC1',
            'nome' => 'Plano 1',
        ]);
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'MAT', 'nome' => 'Matemática']);
        $outraDisciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'FIS', 'nome' => 'Física']);
        $item = PlanoCurricularDisciplina::create([
            'plano_curricular_id' => $plano->id,
            'disciplina_id' => $disciplina->id,
            'carga_horaria' => 90,
            'creditos' => 4,
            'componente' => ComponentePlanoCurricular::GERAL->value,
            'tipo' => TipoDisciplinaPlano::NORMAL->value,
            'obrigatoria' => true,
            'ordem' => 1,
        ]);

        $atualizado = (new AtualizarDisciplinaDoPlanoAction())->executar($item, new PlanoCurricularDisciplinaDTO(
            disciplina_id: $outraDisciplina->id,
            tipo: TipoDisciplinaPlano::OPTATIVA,
            obrigatoria: false,
            ordem: 2,
            carga_horaria: 60,
            creditos: 3,
            componente: ComponentePlanoCurricular::TECNICA,
        ));

        $this->assertSame($outraDisciplina->id, $atualizado->disciplina_id);
        $this->assertSame(60, $atualizado->carga_horaria);
        $this->assertSame(3, $atualizado->creditos);
        $this->assertSame(ComponentePlanoCurricular::TECNICA, $atualizado->componente);
        $this->assertSame(TipoDisciplinaPlano::OPTATIVA, $atualizado->tipo);
        $this->assertFalse($atualizado->obrigatoria);
        $this->assertSame(2, $atualizado->ordem);
        $this->assertSame('Técnica', $atualizado->componente_descricao);
        $this->assertSame('Optativa', $atualizado->tipo_descricao);
        $this->assertSame($plano->id, $atualizado->plano_curricular_id);
    }
```

(Removida a variável `$outroNivelAcademico`, que só servia para o campo eliminado.)

**`RemoverDisciplinaDoPlanoActionTest.php`** — em ambos os testes, remover `'nivel_academico_id' => $nivelAcademico->id,` do `PlanoCurricularDisciplina::create([...])` e acrescentar `'nivel_academico_id' => $nivelAcademico->id` ao `PlanoCurricular::create([...])`:

```php
    public function test_remove_disciplina_do_plano(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);
        $nivelAcademico = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'N10', 'nome' => '10ª Classe', 'ordem' => 1, 'etapa_ensino' => 4]);
        $plano = PlanoCurricular::create([
            'estabelecimento_id' => $estabelecimento->id,
            'nivel_academico_id' => $nivelAcademico->id,
            'curso_id' => $curso->id,
            'codigo' => 'PC1',
            'nome' => 'Plano 1',
        ]);
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'MAT', 'nome' => 'Matemática']);
        $item = PlanoCurricularDisciplina::create([
            'plano_curricular_id' => $plano->id,
            'disciplina_id' => $disciplina->id,
            'tipo' => TipoDisciplinaPlano::NORMAL->value,
            'obrigatoria' => true,
            'ordem' => 1,
        ]);

        (new RemoverDisciplinaDoPlanoAction())->executar($item);

        $this->assertDatabaseMissing('plano_curricular_disciplinas', ['id' => $item->id]);
    }

    public function test_bloqueia_remocao_de_disciplina_com_periodos_associados(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'INF', 'nome' => 'Informática']);
        $nivelAcademico = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'N10', 'nome' => '10ª Classe', 'ordem' => 1, 'etapa_ensino' => 4]);
        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'nivel_academico_id' => $nivelAcademico->id, 'curso_id' => $curso->id, 'codigo' => 'PC1', 'nome' => 'Plano 1']);
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'MAT', 'nome' => 'Matemática']);
        $item = PlanoCurricularDisciplina::create([
            'plano_curricular_id' => $plano->id,
            'disciplina_id' => $disciplina->id,
            'tipo' => TipoDisciplinaPlano::NORMAL->value,
            'obrigatoria' => true,
            'ordem' => 1,
        ]);
        $anoLectivo = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026/2027', 'data_inicio' => '2026-02-01', 'data_fim' => '2026-11-30', 'estado' => EstadoAnoLectivo::ATIVO]);
        $periodo = Periodo::create(['ano_lectivo_id' => $anoLectivo->id, 'nome' => '1º Trimestre', 'tipo' => TipoPeriodo::TRIMESTRE, 'numero' => 1, 'data_inicio' => '2026-02-01', 'data_fim' => '2026-05-01']);
        $aplicacao = PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $plano->id, 'ano_lectivo_id' => $anoLectivo->id]);
        $item->periodosPorAplicacao()->create(['plano_curricular_ano_lectivo_id' => $aplicacao->id, 'periodo_id' => $periodo->id]);

        $this->expectException(ValidationException::class);

        try {
            (new RemoverDisciplinaDoPlanoAction())->executar($item);
        } finally {
            $this->assertDatabaseHas('plano_curricular_disciplinas', ['id' => $item->id]);
        }
    }
```

**`PlanoCurricularDisciplinaModelTest.php`** — o helper `contexto()` ganha `nivel_academico_id` no `PlanoCurricular::create()`, e os 4 testes que usam `PlanoCurricularDisciplina::create()` deixam de passar `'nivel_academico_id' => $nivel->id,`:

```php
    private function contexto(): array
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'tipo_ensino' => 2, 'is_active' => true]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'C1', 'nome' => 'Curso Técnico']);
        $nivel = NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'N10', 'nome' => '10ª Classe', 'ordem' => 1, 'etapa_ensino' => 4]);
        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'codigo' => 'PC1', 'nome' => 'Plano 1']);

        return compact('estabelecimento', 'curso', 'plano', 'nivel');
    }

    public function test_associa_disciplina_ao_plano(): void
    {
        ['estabelecimento' => $estabelecimento, 'plano' => $plano] = $this->contexto();
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'D1', 'nome' => 'Electrotecnia']);

        $item = PlanoCurricularDisciplina::create([
            'plano_curricular_id' => $plano->id,
            'disciplina_id' => $disciplina->id,
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
    }

    public function test_impede_duplicar_mesma_disciplina_no_plano(): void
    {
        ['estabelecimento' => $estabelecimento, 'plano' => $plano] = $this->contexto();
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'D1', 'nome' => 'Matemática']);

        PlanoCurricularDisciplina::create([
            'plano_curricular_id' => $plano->id, 'disciplina_id' => $disciplina->id, 'tipo' => 0, 'ordem' => 1,
        ]);

        $this->expectException(QueryException::class);
        PlanoCurricularDisciplina::create([
            'plano_curricular_id' => $plano->id, 'disciplina_id' => $disciplina->id, 'tipo' => 0, 'ordem' => 2,
        ]);
    }

    public function test_permite_disciplina_optativa_nao_obrigatoria(): void
    {
        ['estabelecimento' => $estabelecimento, 'plano' => $plano] = $this->contexto();
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'D2', 'nome' => 'Optativa X']);

        $item = PlanoCurricularDisciplina::create([
            'plano_curricular_id' => $plano->id, 'disciplina_id' => $disciplina->id,
            'tipo' => TipoDisciplinaPlano::OPTATIVA->value, 'obrigatoria' => false, 'ordem' => 1,
        ]);

        $this->assertFalse($item->obrigatoria);
        $this->assertSame(TipoDisciplinaPlano::OPTATIVA, $item->tipo);
    }

    public function test_creditos_e_componente_sao_nullable(): void
    {
        ['estabelecimento' => $estabelecimento, 'plano' => $plano] = $this->contexto();
        $disciplina = Disciplina::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'D3', 'nome' => 'Língua Portuguesa']);

        $item = PlanoCurricularDisciplina::create([
            'plano_curricular_id' => $plano->id, 'disciplina_id' => $disciplina->id, 'ordem' => 1,
        ]);

        $this->assertNull($item->creditos);
        $this->assertNull($item->componente);
        $this->assertNull($item->fresh()->componente_descricao);
    }
```

(Renomeado `test_associa_disciplina_ao_plano_e_nivel` → `test_associa_disciplina_ao_plano` e `test_impede_duplicar_mesma_disciplina_no_mesmo_nivel_do_plano` → `test_impede_duplicar_mesma_disciplina_no_plano`, para reflectir que a restrição de unicidade já não depende do nível.)

- [ ] **Step 2: Correr os ficheiros afectados e confirmar o padrão de falhas**

```bash
php artisan test --filter="AdicionarDisciplinaAoPlanoActionTest|AdicionarDisciplinaRequestTest|AtualizarDisciplinaDoPlanoActionTest|RemoverDisciplinaDoPlanoActionTest|PlanoCurricularDisciplinaModelTest"
```

Esperado: FAIL — `PlanoCurricularDisciplinaDTO` ainda exige `nivel_academico_id` no construtor.

- [ ] **Step 3: Actualizar o DTO**

`Modules/PlanoCurricular/app/DTO/PlanoCurricularDisciplinaDTO.php`:

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

- [ ] **Step 4: Actualizar as Requests**

`Modules/PlanoCurricular/app/Http/Requests/AdicionarDisciplinaRequest.php` — remover a regra `nivel_academico_id` e a respectiva mensagem; a unicidade de `disciplina_id` deixa de filtrar por nível:

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
        $planoId = $this->route('planoCurricular')->id;

        return [
            'disciplina_id' => [
                'required',
                'integer',
                Rule::exists('disciplinas', 'id')->where(fn ($q) => $q->where('estabelecimento_id', $estabelecimentoId)),
                Rule::unique('plano_curricular_disciplinas', 'disciplina_id')
                    ->where(fn ($q) => $q->where('plano_curricular_id', $planoId)),
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
            'disciplina_id.unique' => 'Esta disciplina já está associada a este plano.',
        ];
    }
}
```

`Modules/PlanoCurricular/app/Http/Requests/AtualizarDisciplinaRequest.php` — mesma alteração, mantendo o `->ignore($this->route('disciplina'))`:

```php
<?php

namespace Modules\PlanoCurricular\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\PlanoCurricular\Enums\ComponentePlanoCurricular;
use Modules\PlanoCurricular\Enums\TipoDisciplinaPlano;

class AtualizarDisciplinaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('plano-curricular.editar') ?? false;
    }

    public function rules(): array
    {
        $estabelecimentoId = Estabelecimento::current()?->id;
        $planoId = $this->route('planoCurricular')->id;

        return [
            'disciplina_id' => [
                'required',
                'integer',
                Rule::exists('disciplinas', 'id')->where(fn ($q) => $q->where('estabelecimento_id', $estabelecimentoId)),
                Rule::unique('plano_curricular_disciplinas', 'disciplina_id')
                    ->where(fn ($q) => $q->where('plano_curricular_id', $planoId))
                    ->ignore($this->route('disciplina')),
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
            'disciplina_id.unique' => 'Esta disciplina já está associada a este plano.',
        ];
    }
}
```

- [ ] **Step 5: Actualizar as Actions**

`Modules/PlanoCurricular/app/Actions/AdicionarDisciplinaAoPlanoAction.php`:

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

`Modules/PlanoCurricular/app/Actions/AtualizarDisciplinaDoPlanoAction.php`:

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

- [ ] **Step 6: Correr os ficheiros afectados e confirmar que passam**

```bash
php artisan test --filter="AdicionarDisciplinaAoPlanoActionTest|AdicionarDisciplinaRequestTest|AtualizarDisciplinaDoPlanoActionTest|RemoverDisciplinaDoPlanoActionTest|PlanoCurricularDisciplinaModelTest"
```

Esperado: PASS em todos.

- [ ] **Step 7: Commit**

```bash
git add Modules/PlanoCurricular/app/DTO/PlanoCurricularDisciplinaDTO.php \
        Modules/PlanoCurricular/app/Http/Requests/AdicionarDisciplinaRequest.php \
        Modules/PlanoCurricular/app/Http/Requests/AtualizarDisciplinaRequest.php \
        Modules/PlanoCurricular/app/Actions/AdicionarDisciplinaAoPlanoAction.php \
        Modules/PlanoCurricular/app/Actions/AtualizarDisciplinaDoPlanoAction.php \
        Modules/PlanoCurricular/tests/Feature/AdicionarDisciplinaAoPlanoActionTest.php \
        Modules/PlanoCurricular/tests/Feature/AdicionarDisciplinaRequestTest.php \
        Modules/PlanoCurricular/tests/Feature/AtualizarDisciplinaDoPlanoActionTest.php \
        Modules/PlanoCurricular/tests/Feature/RemoverDisciplinaDoPlanoActionTest.php \
        Modules/PlanoCurricular/tests/Feature/PlanoCurricularDisciplinaModelTest.php
git commit -m "feat(plano-curricular): nivel_academico_id sai da API de PlanoCurricularDisciplina"
```

---

## Task 4: Reparação mecânica dos restantes ficheiros de teste

**Files:**
- Modify: `Modules/PlanoCurricular/tests/Feature/PlanoCurricularHttpTest.php`
- Modify: `Modules/PlanoCurricular/tests/Feature/PlanoCurricularIsolamentoTest.php`
- Modify: `Modules/PlanoCurricular/tests/Feature/PlanoCurricularHistoricoTest.php`
- Modify: `Modules/PlanoCurricular/tests/Feature/DefinirPeriodosDaDisciplinaActionTest.php`
- Modify: `Modules/PlanoCurricular/tests/Feature/DefinirPeriodosDisciplinaRequestTest.php`
- Modify: `Modules/PlanoCurricular/tests/Feature/PlanoCurricularDisciplinaPeriodoModelTest.php`
- Modify: `Modules/PlanoCurricular/tests/Feature/PlanoCurricularAnoLectivoModelTest.php`
- Modify: `Modules/PlanoCurricular/tests/Feature/ConfirmarPlanoParaAnoLectivoActionTest.php`
- Modify: `Modules/PlanoCurricular/tests/Feature/ConfirmarAnoLectivoRequestTest.php`

**Interfaces:** nenhuma nova — pura reparação de fixtures contra o schema/DTO já corrigidos nas Tasks 1-3. Nenhuma destas suites testa lógica de negócio afectada por esta feature (períodos, histórico, confirmação de ano lectivo, isolamento entre estabelecimentos) — só precisam de deixar de passar `nivel_academico_id` onde já não existe, e passar a incluir onde passou a ser obrigatório.

- [ ] **Step 1: `PlanoCurricularHttpTest.php`**

No helper privado `criarPlano()`, acrescentar a criação de um `NivelAcademico` e o campo no `create()`:

```php
    private function criarPlano(Estabelecimento $estabelecimento, Curso $curso, string $codigo = 'PLI'): PlanoCurricular
    {
        $nivel = NivelAcademico::firstOrCreate(
            ['estabelecimento_id' => $estabelecimento->id, 'codigo' => '1C'],
            ['nome' => '1ª Classe', 'ordem' => 1, 'etapa_ensino' => 4],
        );

        return PlanoCurricular::create([
            'estabelecimento_id' => $estabelecimento->id,
            'nivel_academico_id' => $nivel->id,
            'curso_id' => $curso->id,
            'codigo' => $codigo,
            'nome' => 'Plano Informática',
        ]);
    }
```

(`firstOrCreate` em vez de `create` porque este helper é chamado várias vezes com o mesmo `$estabelecimento` dentro do mesmo teste em alguns casos — ex.: `test_definir_periodos_disciplina_rejeita_disciplina_que_nao_pertence_ao_plano_da_aplicacao` cria dois planos para o mesmo estabelecimento — e a unicidade `(estabelecimento_id, codigo)` de `niveis_academicos` rejeitaria uma segunda criação com o mesmo código.)

Em cada chamada a `$this->post(route('planos-curriculares.store'), [...])`, adicionar `'nivel_academico_id' => $nivel->id,` ao payload — isto exige ter `$nivel` disponível nesses testes. Como `criarEstabelecimento()`/`criarCurso()` já existem como helpers separados, adicionar também:

```php
    private function criarNivelAcademicoParaPlano(Estabelecimento $estabelecimento): NivelAcademico
    {
        return NivelAcademico::firstOrCreate(
            ['estabelecimento_id' => $estabelecimento->id, 'codigo' => '1C'],
            ['nome' => '1ª Classe', 'ordem' => 1, 'etapa_ensino' => 4],
        );
    }
```

(Nota: este helper tem o mesmo `codigo` que o já usado por `criarPlano()` acima — é intencional, `firstOrCreate` devolve o mesmo registo em ambos, evitando duplicar `NivelAcademico`s desnecessariamente no mesmo teste.)

Nos 3 testes que chamam `route('planos-curriculares.store')` directamente (`test_store_cria_plano_associado_ao_curso`, `test_professor_recebe_403_em_todas_as_rotas_de_escrita`, e os dois em `PlanoCurricularHistoricoTest` — este último é outro ficheiro, tratado no Step 3), adicionar a chamada ao helper e o campo no payload. Exemplo para `test_store_cria_plano_associado_ao_curso`:

```php
    public function test_store_cria_plano_associado_ao_curso(): void
    {
        $staff = $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $curso = $this->criarCurso($estabelecimento);
        $nivel = $this->criarNivelAcademicoParaPlano($estabelecimento);

        $this->post(route('planos-curriculares.store'), [
            'nivel_academico_id' => $nivel->id,
            'curso_id' => $curso->id,
            'codigo' => 'PLI',
            'nome' => 'Plano Informática',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $plano = PlanoCurricular::firstWhere('codigo', 'PLI');
        $this->assertNotNull($plano);
        $this->assertSame($curso->id, $plano->curso_id);
        $this->assertSame($nivel->id, $plano->nivel_academico_id);
        $this->assertSame($estabelecimento->id, $plano->estabelecimento_id);
        $this->assertSame($staff->id, $plano->criado_por);
        $this->assertSame('Ativo', $plano->estado_descricao);
    }
```

`test_atualiza_plano_via_http` e `test_professor_recebe_403_em_todas_as_rotas_de_escrita` usam `criarPlano()` (já corrigido acima) e depois enviam `curso_id`/`codigo`/`nome` a `route('planos-curriculares.update', ...)` — acrescentar `'nivel_academico_id' => $nivel->id,` a esses payloads também, chamando `criarNivelAcademicoParaPlano($estabelecimento)` antes.

Em todas as chamadas a `$plano->disciplinas()->create([...])` e a `$this->post/put(route('planos-curriculares.disciplinas.*', ...))`, remover a chave `'nivel_academico_id' => $nivel->id,` do array e a linha `$nivel = $this->criarNivelAcademico($estabelecimento);` fica só para quem ainda precisa dela por outra razão — neste ficheiro, `criarNivelAcademico()` deixa de ser chamado para disciplinas (só para o plano, via `criarNivelAcademicoParaPlano()`). Isto afecta: `test_disciplinas_store_associa_disciplina_ao_plano`, `test_disciplinas_store_rejeita_duplicar_mesma_disciplina_no_mesmo_nivel` (renomear para `test_disciplinas_store_rejeita_duplicar_mesma_disciplina`), `test_disciplinas_update_via_http`, `test_disciplinas_destroy_via_http`, `test_definir_periodos_disciplina_associa_varios_periodos_via_http`, `test_definir_periodos_disciplina_rejeita_periodo_de_outro_ano_lectivo_via_http`, `test_definir_periodos_disciplina_rejeita_disciplina_que_nao_pertence_ao_plano_da_aplicacao`.

- [ ] **Step 2: `PlanoCurricularIsolamentoTest.php`**

Mesmo tratamento: o helper `criarPlano()` ganha `NivelAcademico` + `nivel_academico_id`; todas as chamadas a `disciplinas.store`/`.update` e a `$planoB->disciplinas()->create([...])` perdem `nivel_academico_id`; `test_store_rejeita_curso_de_outro_estabelecimento` (que só envia `curso_id`/`codigo`/`nome`, sem nível) precisa de continuar a falhar por `curso_id` — acrescentar um `nivel_academico_id` válido ao payload para isolar exactamente essa validação (mesma razão de design já documentada no comentário do `test_disciplinas_update_em_plano_de_outro_estabelecimento_devolve_404` original: "payload válido... para isolar exactamente a guarda").

`criarPlano()`:

```php
    private function criarPlano(Estabelecimento $estabelecimento, Curso $curso, string $codigo): PlanoCurricular
    {
        $nivel = $this->criarNivelAcademico($estabelecimento);

        return PlanoCurricular::create([
            'estabelecimento_id' => $estabelecimento->id,
            'nivel_academico_id' => $nivel->id,
            'curso_id' => $curso->id,
            'codigo' => $codigo,
            'nome' => 'Plano do Estabelecimento B',
        ]);
    }
```

`test_store_rejeita_curso_de_outro_estabelecimento`:

```php
    public function test_store_rejeita_curso_de_outro_estabelecimento(): void
    {
        [$estabelecimentoA, $estabelecimentoB] = $this->prepararCenarioCrossEstabelecimento();
        $cursoB = $this->criarCurso($estabelecimentoB, 'CONT2');
        $nivelA = $this->criarNivelAcademico($estabelecimentoA);

        $this->post(route('planos-curriculares.store'), [
            'nivel_academico_id' => $nivelA->id,
            'curso_id' => $cursoB->id,
            'codigo' => 'PLX',
            'nome' => 'Plano inválido',
        ])->assertSessionHasErrors('curso_id');

        $this->assertDatabaseMissing('planos_curriculares', ['codigo' => 'PLX']);
    }
```

`test_update_de_plano_de_outro_estabelecimento_devolve_404_e_nao_muta_dados` — acrescentar `'nivel_academico_id' => $this->criarNivelAcademico($estabelecimentoA)->id,` ao payload do `put`.

Remover `'nivel_academico_id' => $nivelA->id,`/`$nivelB->id,` de todas as chamadas relacionadas com `disciplinas.*` (store/update/destroy) e de `$planoB->disciplinas()->create([...])` — mas manter as próprias variáveis `$nivelA`/`$nivelB` e as suas criações onde ainda forem necessárias só para o `nivel_academico_id` do plano (não há mais nenhum uso delas para disciplina).

- [ ] **Step 3: `PlanoCurricularHistoricoTest.php`**

Acrescentar `'nivel_academico_id' => $nivel->id,` aos dois `$this->post(route('planos-curriculares.store'), [...])` (Plano A e Plano B) e ao `$this->put(route('planos-curriculares.update', $planoB), [...])`; remover `'nivel_academico_id' => $nivel->id,` das chamadas a `disciplinas.store`/`.update` (a variável `$nivel` já existe em ambos os testes, criada uma vez por teste — continua a servir para o campo do plano).

- [ ] **Step 4: `DefinirPeriodosDaDisciplinaActionTest.php`, `DefinirPeriodosDisciplinaRequestTest.php`, `PlanoCurricularDisciplinaPeriodoModelTest.php`**

Nos três, dentro do método `contexto()`: acrescentar `'nivel_academico_id' => $nivel->id,` ao `PlanoCurricular::create([...])`; remover `'nivel_academico_id' => $nivel->id,` de `$plano->disciplinas()->create([...])`. A variável `$nivel` continua a existir, só muda para onde aponta.

- [ ] **Step 5: `PlanoCurricularAnoLectivoModelTest.php`, `ConfirmarPlanoParaAnoLectivoActionTest.php`, `ConfirmarAnoLectivoRequestTest.php`**

Nenhum destes cria disciplinas — só criam um `NivelAcademico` novo e acrescentam `'nivel_academico_id' => $nivel->id,` a cada `PlanoCurricular::create([...])`. Exemplo para `ConfirmarPlanoParaAnoLectivoActionTest.php`:

```php
    public function test_confirma_plano_regista_quem_e_quando(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true]);
        $curso = Curso::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'C1', 'nome' => 'Curso A']);
        $nivel = \Modules\Turma\Models\NivelAcademico::create(['estabelecimento_id' => $estabelecimento->id, 'codigo' => 'N10', 'nome' => '10ª Classe', 'ordem' => 1, 'etapa_ensino' => 4]);
        $plano = PlanoCurricular::create(['estabelecimento_id' => $estabelecimento->id, 'nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'codigo' => 'PC1', 'nome' => 'Plano A']);
        $ano = AnoLectivo::create(['estabelecimento_id' => $estabelecimento->id, 'nome' => '2026/2027', 'data_inicio' => '2026-02-01', 'data_fim' => '2026-11-30', 'estado' => EstadoAnoLectivo::ATIVO]);
        $user = User::create(['name' => 'Test User', 'email' => 'test@example.com', 'password' => Hash::make('password')]);

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
```

(Adicionar `use Modules\Turma\Models\NivelAcademico;` no topo em vez de FQN inline, nos três ficheiros — o exemplo acima usa FQN só para caber na descrição do diff; no ficheiro final, importar normalmente.)

Aplicar o mesmo padrão (criar `$nivel`, acrescentar `nivel_academico_id` ao `PlanoCurricular::create`) a todos os `PlanoCurricular::create()` em `PlanoCurricularAnoLectivoModelTest.php` (método `contexto()` e o `$planoB` criado directamente em `test_historico_suporta_plano_diferente_em_ano_seguinte_sem_alterar_plano_anterior`) e em `ConfirmarAnoLectivoRequestTest.php` (2 testes).

- [ ] **Step 6: Correr toda a suite de PlanoCurricular e confirmar que passa**

```bash
php artisan test --filter=PlanoCurricular
```

Esperado: PASS em toda a suite do módulo.

- [ ] **Step 7: Correr a suite completa do projecto**

```bash
php artisan test
```

Esperado: PASS (o mesmo 1 teste incompleto pré-existente sobre isolamento de tenants, sem relação).

- [ ] **Step 8: Commit**

```bash
git add Modules/PlanoCurricular/tests/Feature/PlanoCurricularHttpTest.php \
        Modules/PlanoCurricular/tests/Feature/PlanoCurricularIsolamentoTest.php \
        Modules/PlanoCurricular/tests/Feature/PlanoCurricularHistoricoTest.php \
        Modules/PlanoCurricular/tests/Feature/DefinirPeriodosDaDisciplinaActionTest.php \
        Modules/PlanoCurricular/tests/Feature/DefinirPeriodosDisciplinaRequestTest.php \
        Modules/PlanoCurricular/tests/Feature/PlanoCurricularDisciplinaPeriodoModelTest.php \
        Modules/PlanoCurricular/tests/Feature/PlanoCurricularAnoLectivoModelTest.php \
        Modules/PlanoCurricular/tests/Feature/ConfirmarPlanoParaAnoLectivoActionTest.php \
        Modules/PlanoCurricular/tests/Feature/ConfirmarAnoLectivoRequestTest.php
git commit -m "test(plano-curricular): repara fixtures dos restantes testes contra o novo schema"
```

---

## Task 5: Frontend — Curso continua a gerir Planos Curriculares (agora com Nível)

**Files:**
- Modify: `Modules/Curso/app/Services/CursoConsultaService.php`
- Modify: `Modules/Curso/app/Http/Controllers/CursoController.php`
- Modify: `Modules/Curso/resources/js/Pages/Show.vue`
- Modify: `Modules/Curso/resources/js/Components/PlanosCurriculares/NovoPlanoCurricularModal.vue`
- Modify: `Modules/PlanoCurricular/resources/js/Components/PlanoCurricularFormModal.vue`
- Modify: `Modules/PlanoCurricular/resources/js/Components/DisciplinaPlanoFormModal.vue`
- Modify: `Modules/PlanoCurricular/resources/js/Pages/Show.vue`

**Interfaces:**
- Consumes: `PlanoCurricular.nivel_academico_id` (Task 2), `PlanoCurricularConsultaService::opcoesFormulario()['niveisAcademicos']` (já existente, inalterado).
- Produces: nova prop `niveisAcademicos` em `Curso/Pages/Show.vue`, consumida pelo `NovoPlanoCurricularModal.vue` (Curso).

**Nota de fronteira de módulo:** esta task faz `Modules\Curso\Services\CursoConsultaService` importar `Modules\Turma\Models\NivelAcademico`. `Modules\Turma\Models\Turma` já importa `Modules\Curso\Models\Curso` (a relação `Turma::curso()`) — esta task torna essa dependência **bidireccional** entre os dois módulos. Tecnicamente inofensivo (PHP/Laravel resolvem isto em tempo de execução via autoload, sem erro de dependência circular; este projecto já tem várias referências cruzadas entre módulos — `PlanoCurricular` já depende de `Turma` e de `Curso` em simultâneo, `Estabelecimento` já depende de `Turma`). Registado aqui por transparência, não é um bloqueio — a alternativa (consultar `niveis_academicos` via `DB::table()` em vez do model Eloquent, só para evitar o import) seria complexidade extra sem benefício real neste monólito modular.

- [ ] **Step 1: `CursoConsultaService` expõe os níveis académicos**

`Modules/Curso/app/Services/CursoConsultaService.php`:

```php
<?php

namespace Modules\Curso\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Turma\Models\NivelAcademico;

class CursoConsultaService
{
    public function listar(): Collection
    {
        return Curso::where('estabelecimento_id', Estabelecimento::current()?->id)
            ->orderBy('nome')
            ->get();
    }

    public function comRelacoes(Curso $curso): Curso
    {
        return $curso->load(['planosCurriculares' => fn ($query) => $query->orderBy('nome')]);
    }

    public function niveisAcademicos(): Collection
    {
        return NivelAcademico::where('estabelecimento_id', Estabelecimento::current()?->id)
            ->where('estado', 1)
            ->orderBy('ordem')
            ->get(['id', 'nome', 'ordem']);
    }
}
```

- [ ] **Step 2: `CursoController::show()` passa a prop**

Editar `Modules/Curso/app/Http/Controllers/CursoController.php`:

```php
    public function show(Curso $curso)
    {
        $this->authorize('curso.ver');

        return Inertia::render('Curso/Show', [
            'curso' => $this->consulta->comRelacoes($curso),
            'niveisAcademicos' => $this->consulta->niveisAcademicos(),
        ]);
    }
```

- [ ] **Step 3: `Curso/Pages/Show.vue` recebe e reenvia a prop**

Editar `Modules/Curso/resources/js/Pages/Show.vue`:

```js
const props = defineProps({
    curso: { type: Object, required: true },
    niveisAcademicos: { type: Array, required: true },
});
```

No template, passar a prop ao modal:

```html
        <NovoPlanoCurricularModal
            :show="planoModalAberto"
            :niveis-academicos="niveisAcademicos"
            :processing="planoProcessing"
            :errors="planoErrors"
            @submit="guardarPlano"
            @cancelar="fecharPlanoModal"
        />
```

- [ ] **Step 4: `NovoPlanoCurricularModal.vue` (Curso) ganha o select de Nível**

Editar `Modules/Curso/resources/js/Components/PlanosCurriculares/NovoPlanoCurricularModal.vue`:

```vue
<script setup>
import { computed, reactive, watch } from 'vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    niveisAcademicos: { type: Array, required: true },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const opcoesNiveisAcademicos = computed(() =>
    [...props.niveisAcademicos].sort((a, b) => a.ordem - b.ordem).map((n) => ({ value: n.id, label: n.nome })),
);

const form = reactive({
    nivel_academico_id: '',
    codigo: '',
    nome: '',
    descricao: '',
});

watch(() => props.show, (show) => {
    if (!show) return;
    form.nivel_academico_id = '';
    form.codigo = '';
    form.nome = '';
    form.descricao = '';
});

function submeter() {
    emit('submit', { ...form });
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-5">Novo Plano Curricular</h3>
                <form @submit.prevent="submeter">
                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Nível Académico</label>
                        <SelectSolid v-model="form.nivel_academico_id" :options="opcoesNiveisAcademicos" placeholder="Selecione o nível" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.nivel_academico_id">{{ errors.nivel_academico_id }}</div>
                    </div>
                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Código</label>
                        <input v-model="form.codigo" type="text" class="form-control form-control-solid" placeholder="ex: INF-2026" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.codigo">{{ errors.codigo }}</div>
                    </div>
                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Nome</label>
                        <input v-model="form.nome" type="text" class="form-control form-control-solid" placeholder="ex: Plano Curricular de Informática" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.nome">{{ errors.nome }}</div>
                    </div>
                    <div class="fv-row mb-7">
                        <label class="fw-semibold fs-6 mb-2">Descrição</label>
                        <textarea v-model="form.descricao" class="form-control form-control-solid" rows="3"></textarea>
                        <div class="text-danger fs-7 mt-1" v-if="errors.descricao">{{ errors.descricao }}</div>
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

`Curso/Pages/Show.vue`'s `guardarPlano()` já injecta `curso_id: props.curso.id` no payload — não precisa de alteração, o `nivel_academico_id` vem agora do próprio formulário.

- [ ] **Step 5: `PlanoCurricularFormModal.vue` (edição) ganha Nível e Curso passa a opcional**

Editar `Modules/PlanoCurricular/resources/js/Components/PlanoCurricularFormModal.vue`:

```vue
<script setup>
import { computed, reactive, watch } from 'vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    planoCurricular: { type: Object, default: null },
    opcoes: { type: Object, required: true },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const opcoesCurso = () => [{ value: '', label: 'Sem curso' }, ...props.opcoes.cursos.map((c) => ({ value: c.id, label: c.nome }))];
const opcoesNiveisAcademicos = computed(() =>
    [...props.opcoes.niveisAcademicos].sort((a, b) => a.ordem - b.ordem).map((n) => ({ value: n.id, label: n.nome })),
);

const form = reactive({
    nivel_academico_id: '',
    curso_id: '',
    codigo: '',
    nome: '',
    descricao: '',
});

watch(() => props.show, (show) => {
    if (!show) return;
    form.nivel_academico_id = props.planoCurricular?.nivel_academico_id ?? '';
    form.curso_id = props.planoCurricular?.curso_id ?? '';
    form.codigo = props.planoCurricular?.codigo ?? '';
    form.nome = props.planoCurricular?.nome ?? '';
    form.descricao = props.planoCurricular?.descricao ?? '';
});

function submeter() {
    const payload = { ...form, curso_id: form.curso_id === '' ? null : form.curso_id };
    emit('submit', payload);
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-5">{{ planoCurricular ? 'Editar Plano Curricular' : 'Novo Plano Curricular' }}</h3>
                <form @submit.prevent="submeter">
                    <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Nível Académico</label>
                            <SelectSolid v-model="form.nivel_academico_id" :options="opcoesNiveisAcademicos" placeholder="Selecione o nível" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.nivel_academico_id">{{ errors.nivel_academico_id }}</div>
                        </div>
                        <div class="col-md-6 fv-row mb-7">
                            <label class="fw-semibold fs-6 mb-2">Curso</label>
                            <SelectSolid v-model="form.curso_id" :options="opcoesCurso()" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.curso_id">{{ errors.curso_id }}</div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Código</label>
                            <input v-model="form.codigo" type="text" class="form-control form-control-solid" placeholder="ex: INF-2026" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.codigo">{{ errors.codigo }}</div>
                        </div>
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Nome</label>
                            <input v-model="form.nome" type="text" class="form-control form-control-solid" placeholder="ex: Plano Curricular de Informática" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.nome">{{ errors.nome }}</div>
                        </div>
                    </div>

                    <div class="fv-row mb-7">
                        <label class="fw-semibold fs-6 mb-2">Descrição</label>
                        <textarea v-model="form.descricao" class="form-control form-control-solid" rows="3"></textarea>
                        <div class="text-danger fs-7 mt-1" v-if="errors.descricao">{{ errors.descricao }}</div>
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

- [ ] **Step 6: `DisciplinaPlanoFormModal.vue` perde o select de Nível**

Editar `Modules/PlanoCurricular/resources/js/Components/DisciplinaPlanoFormModal.vue`: remover o `computed opcoesNiveisAcademicos`, remover `nivel_academico_id` do `form` reactive e do `watch`, remover o bloco `<div class="col-md-6 ...">` do select "Nível Académico" no template — o bloco "Disciplina" passa a ocupar a linha inteira (`col-md-12` em vez de `col-md-6`, ou manter `col-md-6` a par de outro campo, conforme o layout ficar mais equilibrado ao implementar).

- [ ] **Step 7: `PlanoCurricular/Show.vue` mostra o Nível**

Editar `Modules/PlanoCurricular/resources/js/Pages/Show.vue`: no cabeçalho onde já mostra dados do plano, adicionar uma linha "Nível Académico" (`planoCurricular.nivel_academico.nome`) e ajustar a linha "Curso" para `planoCurricular.curso?.nome ?? '—'`.

- [ ] **Step 8: Build e verificação estática**

```bash
npm run build
```

Confirmar que compila sem erros.

- [ ] **Step 9: Commit**

```bash
git add Modules/Curso/app/Services/CursoConsultaService.php \
        Modules/Curso/app/Http/Controllers/CursoController.php \
        Modules/Curso/resources/js/Pages/Show.vue \
        Modules/Curso/resources/js/Components/PlanosCurriculares/NovoPlanoCurricularModal.vue \
        Modules/PlanoCurricular/resources/js/Components/PlanoCurricularFormModal.vue \
        Modules/PlanoCurricular/resources/js/Components/DisciplinaPlanoFormModal.vue \
        Modules/PlanoCurricular/resources/js/Pages/Show.vue
git commit -m "feat(plano-curricular): frontend do Curso e da edição passam a exigir Nível Académico"
```

---

## Task 6: Nível Académico ganha página Show (ponto de entrada sem Curso)

**Files:**
- Modify: `Modules/Turma/routes/web.php`
- Modify: `Modules/Turma/app/Http/Controllers/NivelAcademicoController.php`
- Modify: `Modules/Turma/app/Services/NivelAcademicoConsultaService.php`
- Modify: `Modules/Turma/resources/js/Pages/NiveisAcademicos/Index.vue`
- Create: `Modules/Turma/resources/js/Pages/NiveisAcademicos/Show.vue`
- Create: `Modules/Turma/resources/js/Components/NivelAcademico/PlanosCurriculares/NovoPlanoCurricularModal.vue`

**Interfaces:**
- Consumes: `NivelAcademico::planosCurriculares()` (Task 1).
- Produces: rota `niveis-academicos.show`, consumida pelo link em `NiveisAcademicos/Index.vue`.

- [ ] **Step 1: Rota nova**

Editar `Modules/Turma/routes/web.php`, dentro do grupo `niveis-academicos`:

```php
    Route::prefix('niveis-academicos')->name('niveis-academicos.')->group(function () {
        Route::get('/', [NivelAcademicoController::class, 'index'])->middleware('can:turmas.ver')->name('index');
        Route::get('/{nivelAcademico}', [NivelAcademicoController::class, 'show'])->middleware('can:turmas.ver')->name('show');
        Route::post('/', [NivelAcademicoController::class, 'store'])->middleware('can:turmas.criar')->name('store');
        Route::put('/{nivelAcademico}', [NivelAcademicoController::class, 'update'])->middleware('can:turmas.editar')->name('update');
        Route::patch('/{nivelAcademico}/estado', [NivelAcademicoController::class, 'alterarEstado'])->middleware('can:turmas.editar')->name('alterar-estado');
        Route::delete('/{nivelAcademico}', [NivelAcademicoController::class, 'destroy'])->middleware('can:turmas.eliminar')->name('destroy');
    });
```

(`GET /{nivelAcademico}` colocado antes do `POST /` para não colidir com nenhuma rota estática — não há conflito real aqui porque os métodos HTTP são diferentes, mas mantém a ordem já usada em `Curso`/`Turma` de "show logo a seguir a index".)

- [ ] **Step 2: `NivelAcademicoConsultaService::comRelacoes()`**

Editar `Modules/Turma/app/Services/NivelAcademicoConsultaService.php`:

```php
<?php

namespace Modules\Turma\Services;

use Illuminate\Database\Eloquent\Collection;
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

    public function comRelacoes(NivelAcademico $nivelAcademico): NivelAcademico
    {
        return $nivelAcademico->load(['planosCurriculares' => fn ($query) => $query->orderBy('nome')]);
    }
}
```

- [ ] **Step 3: `NivelAcademicoController::show()`**

Editar `Modules/Turma/app/Http/Controllers/NivelAcademicoController.php`, adicionar o método (a seguir a `index()`):

```php
    public function show(NivelAcademico $nivelAcademico)
    {
        $this->authorize('turmas.ver');

        return Inertia::render('Turma/NiveisAcademicos/Show', [
            'nivelAcademico' => $this->consulta->comRelacoes($nivelAcademico),
        ]);
    }
```

(`use Inertia\Inertia;` já está importado neste ficheiro.)

- [ ] **Step 4: Página `NiveisAcademicos/Show.vue`**

`Modules/Turma/resources/js/Pages/NiveisAcademicos/Show.vue`:

```vue
<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import BotaoVoltar from '@/Components/Shared/BotaoVoltar.vue';
import EstadoBadge from '../../Components/Shared/EstadoBadge.vue';
import NovoPlanoCurricularModal from '../../Components/NivelAcademico/PlanosCurriculares/NovoPlanoCurricularModal.vue';

const props = defineProps({
    nivelAcademico: { type: Object, required: true },
});
defineOptions({ layout: AppLayout });

const planoModalAberto = ref(false);
const planoProcessing = ref(false);
const planoErrors = ref({});

function abrirCriacaoPlano() {
    planoErrors.value = {};
    planoModalAberto.value = true;
}

function fecharPlanoModal() {
    planoModalAberto.value = false;
}

function guardarPlano(payload) {
    planoProcessing.value = true;
    planoErrors.value = {};
    router.post('/planos-curriculares', { ...payload, nivel_academico_id: props.nivelAcademico.id }, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Plano curricular criado com sucesso.');
            fecharPlanoModal();
        },
        onError: (erros) => {
            planoErrors.value = erros;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => {
            planoProcessing.value = false;
        },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <BotaoVoltar href="/niveis-academicos" class="mb-4" />

        <div class="d-flex justify-content-between align-items-center mb-6">
            <div>
                <h1 class="fs-2 fw-bold mb-1">{{ nivelAcademico.nome }}</h1>
                <span class="text-muted">Código: {{ nivelAcademico.codigo }} · Etapa: {{ nivelAcademico.etapa_ensino_descricao }}</span>
            </div>
        </div>

        <div class="card mb-6">
            <div class="card-body">
                <div class="row mb-4">
                    <div class="col-md-3 fw-bold text-muted">Estado</div>
                    <div class="col-md-9">
                        <EstadoBadge :estado="nivelAcademico.estado" :estado-descricao="nivelAcademico.estado_descricao" />
                    </div>
                </div>
            </div>
        </div>

        <div v-if="can('plano-curricular.ver')" class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h3 class="card-title fw-bold">Planos Curriculares</h3>
                <button v-if="can('plano-curricular.criar')" class="btn btn-primary btn-sm" @click="abrirCriacaoPlano">Novo Plano</button>
            </div>
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-100px">Código</th>
                            <th class="min-w-200px">Nome</th>
                            <th class="min-w-125px">Estado</th>
                            <th class="text-end min-w-100px">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="nivelAcademico.planos_curriculares.length === 0">
                            <td colspan="4" class="text-center text-muted py-6">Nenhum plano curricular criado para este nível.</td>
                        </tr>
                        <tr v-for="plano in nivelAcademico.planos_curriculares" :key="plano.id">
                            <td>
                                <a :href="`/planos-curriculares/${plano.id}`" class="text-gray-800 text-hover-primary">{{ plano.codigo }}</a>
                            </td>
                            <td>{{ plano.nome }}</td>
                            <td>
                                <EstadoBadge :estado="plano.estado" :estado-descricao="plano.estado_descricao" />
                            </td>
                            <td class="text-end">
                                <a :href="`/planos-curriculares/${plano.id}`" class="btn btn-light btn-sm">Ver</a>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <NovoPlanoCurricularModal
            :show="planoModalAberto"
            :processing="planoProcessing"
            :errors="planoErrors"
            @submit="guardarPlano"
            @cancelar="fecharPlanoModal"
        />
    </div>
</template>
```

(Estrutura deliberadamente idêntica a `Curso/Pages/Show.vue` — mesmo card, mesma tabela, mesmas classes — para quem já conhece uma página reconhecer a outra de imediato.)

- [ ] **Step 5: Modal `NovoPlanoCurricularModal.vue` (Nível) — sem campo Curso**

`Modules/Turma/resources/js/Components/NivelAcademico/PlanosCurriculares/NovoPlanoCurricularModal.vue`:

```vue
<script setup>
import { reactive, watch } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const form = reactive({
    codigo: '',
    nome: '',
    descricao: '',
});

watch(() => props.show, (show) => {
    if (!show) return;
    form.codigo = '';
    form.nome = '';
    form.descricao = '';
});

function submeter() {
    emit('submit', { ...form });
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-5">Novo Plano Curricular</h3>
                <form @submit.prevent="submeter">
                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Código</label>
                        <input v-model="form.codigo" type="text" class="form-control form-control-solid" placeholder="ex: CR-2026" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.codigo">{{ errors.codigo }}</div>
                    </div>
                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Nome</label>
                        <input v-model="form.nome" type="text" class="form-control form-control-solid" placeholder="ex: Plano Curricular Creche I" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.nome">{{ errors.nome }}</div>
                    </div>
                    <div class="fv-row mb-7">
                        <label class="fw-semibold fs-6 mb-2">Descrição</label>
                        <textarea v-model="form.descricao" class="form-control form-control-solid" rows="3"></textarea>
                        <div class="text-danger fs-7 mt-1" v-if="errors.descricao">{{ errors.descricao }}</div>
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

(Byte-a-byte igual ao `NovoPlanoCurricularModal.vue` original do Curso, antes deste passar a pedir Nível — o `nivel_academico_id` é injectado pela página `Show.vue`, tal como o `curso_id` já é injectado do lado do Curso.)

- [ ] **Step 6: `NiveisAcademicos/Index.vue` ganha link para o Show**

Editar `Modules/Turma/resources/js/Pages/NiveisAcademicos/Index.vue`: no `<td>` do código (ou do nome), trocar o texto simples por um link:

```html
                            <td>
                                <a :href="`/niveis-academicos/${nivel.id}`" class="text-gray-800 text-hover-primary">{{ nivel.codigo }}</a>
                            </td>
```

- [ ] **Step 7: Build e verificação manual**

```bash
npm run build
```

Com a app a correr, login como ADMIN_ESCOLA:
1. Ir a Turma > Níveis Académicos, abrir um nível (ex.: uma etapa Creche/Primário já configurada no Estabelecimento).
2. Confirmar que aparece o card "Planos Curriculares", vazio.
3. Criar um Plano Curricular a partir daí — confirmar que não pede Curso, e que aparece na lista.
4. Ir ao Curso, criar um Plano Curricular a partir daí — confirmar que agora pede também o Nível Académico.
5. Abrir os dois planos criados e confirmar que cada um mostra o Nível correcto (e o Curso, quando aplicável, ou "—" quando não).

- [ ] **Step 8: Commit**

```bash
git add Modules/Turma/routes/web.php \
        Modules/Turma/app/Http/Controllers/NivelAcademicoController.php \
        Modules/Turma/app/Services/NivelAcademicoConsultaService.php \
        Modules/Turma/resources/js/Pages/NiveisAcademicos/Index.vue \
        Modules/Turma/resources/js/Pages/NiveisAcademicos/Show.vue \
        Modules/Turma/resources/js/Components/NivelAcademico/PlanosCurriculares/NovoPlanoCurricularModal.vue
git commit -m "feat(turma): Nível Académico ganha página Show com card de Planos Curriculares"
```

---

## Task 7: Verificação final

**Files:** nenhum (apenas execução).

- [ ] **Step 1: Correr toda a suite de testes do projecto**

```bash
php artisan test
```

Esperado: PASS em toda a suite (o mesmo 1 teste incompleto pré-existente, sem relação).

- [ ] **Step 2: Build final do frontend**

```bash
npm run build
```

Esperado: compila sem erros nem avisos novos.

- [ ] **Step 3: Aplicar a migração à BD local e verificar dados existentes**

```bash
php artisan migrate:status
```

Se a migração desta feature aparecer `Pending`, aplicar:

```bash
php artisan migrate
```

Se já existir algum `PlanoCurricular` real na base de dados local (verificar com
`php artisan tinker --execute="echo \Modules\PlanoCurricular\Models\PlanoCurricular::count();"`)
e a migração falhar por `nivel_academico_id` `NOT NULL` sem valor, **parar e
perguntar ao utilizador** qual nível associar a cada plano existente antes de
continuar — não decidir isto sozinho, tal como já aconteceu antes nesta sessão
com o backfill de `etapa_ensino`.

- [ ] **Step 4: Verificação manual ponta-a-ponta**

Com a app a correr, login como ADMIN_ESCOLA:
1. Confirmar que o Estabelecimento tem pelo menos uma Etapa sem Curso configurada (Creche/Pré-Escolar/Primário) e um Nível Académico nessa etapa.
2. Ir ao Nível Académico, criar um Plano Curricular sem Curso, adicionar uma disciplina.
3. Ir a um Curso existente, criar um Plano Curricular associado a esse Curso + a um Nível Académico de etapa Secundário/Superior, adicionar uma disciplina.
4. Confirmar em ambos os planos que o card de disciplinas já não pede "Nível Académico" (a disciplina já sabe o nível através do plano).
5. Confirmar que os dois planos aparecem correctamente nas respectivas páginas de origem (Nível e Curso).

Não há passo de commit nesta task — é só verificação. Se algum passo falhar, voltar à task correspondente, corrigir, e repetir esta task do início.

## Auto-review

- **Cobertura da spec:** modelo de dados (nível obrigatório no plano, curso opcional, nível sai da disciplina) → Task 1. DTO/Request/Action do plano → Task 2. DTO/Request/Action da disciplina → Task 3. Reparação de todos os testes existentes → Tasks 2, 3, 4 (distribuída por grupo de ficheiro, sem nenhum ficheiro esquecido — confirmado por `grep` exaustivo antes de escrever este plano). Frontend do lado do Curso → Task 5. Página Show do Nível Académico (ponto de entrada sem Curso) → Task 6. Backfill de dados existentes → tratado explicitamente na Task 7 como pergunta ao utilizador, não decisão automática, dado que este módulo pode já ter dados reais (ao contrário da feature de Etapa de Ensino, que partiu de um módulo vazio).
- **Placeholders:** nenhum "TBD" — todos os 21 ficheiros de teste identificados por `grep` têm o código exacto da correcção.
- **Consistência de tipos:** `PlanoCurricularDTO(nivel_academico_id: int, codigo: string, nome: string, curso_id: ?int = null, descricao: ?string = null)` e `PlanoCurricularDisciplinaDTO(disciplina_id: int, tipo: TipoDisciplinaPlano, obrigatoria: bool, ordem: int, carga_horaria: ?int = null, creditos: ?int = null, componente: ?ComponentePlanoCurricular = null)` são usados de forma idêntica em todas as tasks que os consomem (2, 3, 4) — nenhuma task posterior assume um parâmetro que não existe.
- **Escopo:** 7 tasks, todas dentro da spec aprovada. Nenhuma introduz uma segunda entidade "PlanoCurricular sem curso", nenhuma reintroduz `nivel_academico_id` em `PlanoCurricularDisciplina`, nenhuma toca no mecanismo de períodos além de confirmar (Task 7) que continua a funcionar sem alterações.
