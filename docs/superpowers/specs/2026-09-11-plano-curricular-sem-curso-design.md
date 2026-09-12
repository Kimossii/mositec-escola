# Plano Curricular sem Curso — Nível Académico como Âncora — Design

## Objectivo

Hoje é impossível criar um Plano Curricular para Creche, Pré-Escolar ou
Ensino Primário: `PlanoCurricular.curso_id` é `NOT NULL` e obrigatório na
validação, e essas etapas nunca têm Curso (confirmado pela regra
`EtapaEnsinoEnum::exigeCurso()` já implementada). Este desenho corrige
isto tornando o **Nível Académico** a âncora obrigatória do Plano
Curricular, com o **Curso** a passar a opcional — espelhando
exactamente o padrão já adoptado em `Turma` (nível obrigatório, curso
opcional).

## Contexto (estado actual, confirmado no código)

```
Curso (1) ──< PlanoCurricular (N)     curso_id NOT NULL, obrigatório
                  │
                  └──< PlanoCurricularDisciplina (N)
                           ├── disciplina_id      NOT NULL
                           └── nivel_academico_id NOT NULL
```

- `PlanoCurricular` não tem nenhuma coluna `nivel_academico_id` — o
  nível vive só na disciplina.
- A criação só é possível a partir do Curso:
  `Modules/Curso/resources/js/Components/PlanosCurriculares/NovoPlanoCurricularModal.vue`,
  chamado de `Curso/Pages/Show.vue`, que injecta `curso_id` no payload.
  **Não existe rota `planos-curriculares.index`/`.create`** — não há
  nenhum caminho para criar um plano sem passar por um Curso.
- Consequência do modelo actual: um curso técnico de 4 anos tem **um
  único** `PlanoCurricular`, com disciplinas de anos diferentes todas
  lá dentro, cada uma a saber o seu próprio `nivel_academico_id`.
- O módulo é recente e sem dados reais
  (`PlanoCurricularDatabaseSeeder::run()` está vazio) — é o momento mais
  barato para corrigir a estrutura, antes de existirem planos reais no
  formato antigo.

## Decisão confirmada

- `PlanoCurricular.nivel_academico_id` passa a **obrigatório**,
  directamente no plano (não na disciplina).
- `PlanoCurricular.curso_id` passa a **opcional**.
- Consequência aceite: um curso técnico de 4 anos passa a ter **4
  registos `PlanoCurricular`** (um por nível), todos com o mesmo
  `curso_id` — em vez de 1 registo a abranger os 4 anos.
- `PlanoCurricularDisciplina.nivel_academico_id` **deixa de existir** —
  torna-se redundante, porque o nível já vem do plano-pai.
- **Sem duas entidades**: continua a existir um único módulo/tabela
  `PlanoCurricular`. A diferença entre "com Curso" e "sem Curso" é só o
  valor de `curso_id` (presente ou `null`), nunca uma tabela ou fluxo
  paralelo.
- **Gestão visual em dois pontos de entrada**, mesma entidade por
  trás: a partir do Curso (quando existe) e a partir do Nível Académico
  (sempre disponível, é o caso universal). Isto exige uma página nova —
  ver secção "Nível Académico ganha página Show" abaixo.

## Modelo de dados

### `planos_curriculares`

| Coluna | Antes | Depois |
|---|---|---|
| `curso_id` | `NOT NULL`, `restrictOnDelete` | `nullable()`, `restrictOnDelete` mantido |
| `nivel_academico_id` | *(não existe)* | nova, `NOT NULL`, FK → `niveis_academicos`, `restrictOnDelete` |

Migração (`Modules/PlanoCurricular/database/migrations/2026_09_11_140000_...`):

```php
Schema::table('planos_curriculares', function (Blueprint $table) {
    $table->foreignId('curso_id')->nullable()->change();
    $table->foreignId('nivel_academico_id')->after('curso_id')
        ->constrained('niveis_academicos')->restrictOnDelete();
});
```

Sem backfill: mesma convenção já usada nesta sessão para colunas `NOT
NULL` novas em tabelas deste projecto (`add_curso_id_to_turmas_table`,
`add_etapa_ensino_to_niveis_academicos_table`) — assume-se ambiente de
desenvolvimento sem planos reais ainda criados. Se correr num ambiente
com dados, falha e exige backfill manual antes.

### `plano_curricular_disciplinas`

| Coluna | Antes | Depois |
|---|---|---|
| `nivel_academico_id` | `NOT NULL`, FK | **removida** |
| `unique` | `(plano_curricular_id, disciplina_id, nivel_academico_id)` | `(plano_curricular_id, disciplina_id)` |

Migração:

```php
Schema::table('plano_curricular_disciplinas', function (Blueprint $table) {
    $table->dropUnique('plano_disciplina_nivel_unique');
    $table->dropForeign(['nivel_academico_id']);
    $table->dropColumn('nivel_academico_id');
    $table->unique(['plano_curricular_id', 'disciplina_id']);
});
```

(Pode ser a mesma migração das duas tabelas, ou duas migrações
separadas — decisão de granularidade para o plano de implementação.)

## Models

`PlanoCurricular`: ganha

```php
public function nivelAcademico(): BelongsTo
{
    return $this->belongsTo(NivelAcademico::class);
}
```

(precisa do import `Modules\Turma\Models\NivelAcademico` — já é uma
dependência aceite deste módulo, hoje só usada em
`PlanoCurricularDisciplina`.)

`PlanoCurricularDisciplina`: perde `nivelAcademico()`,
`nivel_academico_id` sai de `$fillable`.

`NivelAcademico` (módulo Turma): ganha

```php
public function planosCurriculares(): HasMany
{
    return $this->hasMany(PlanoCurricular::class);
}
```

(mesma dependência inversa que `Curso::planosCurriculares()` já tem
hoje — nenhuma fronteira nova.)

## DTO / Requests

`PlanoCurricularDTO`: `curso_id` passa a `?int`; novo campo
`public int $nivel_academico_id`.

`CriarPlanoCurricularRequest` / `AtualizarPlanoCurricularRequest`:

```php
'curso_id' => [
    'nullable',
    'integer',
    Rule::exists('cursos', 'id')->where(fn ($q) => $q->where('estabelecimento_id', $estabelecimentoId)),
],
'nivel_academico_id' => [
    'required',
    'integer',
    Rule::exists('niveis_academicos', 'id')->where(fn ($q) => $q->where('estabelecimento_id', $estabelecimentoId)),
],
```

(`curso_id.required` deixa de existir; mensagens ajustadas.)

`PlanoCurricularDisciplinaDTO`: perde `nivel_academico_id`.

`AdicionarDisciplinaRequest` / `AtualizarDisciplinaRequest`: perdem a
regra `nivel_academico_id`; a regra `unique` de `disciplina_id` deixa
de filtrar por `nivel_academico_id` — passa a
`->where('plano_curricular_id', $planoId)` apenas (uma disciplina não
se repete no mesmo plano, ponto final — antes podia repetir-se desde
que em níveis diferentes dentro do mesmo plano; deixa de fazer sentido
porque o plano agora só tem um nível).

## Actions

`CriarPlanoCurricularAction` / `AtualizarPlanoCurricularAction`: passam
a persistir `nivel_academico_id`; `curso_id` continua a persistir tal
como vem do DTO (`null` ou um id).

`AdicionarDisciplinaAoPlanoAction`: deixa de persistir
`nivel_academico_id`.

## Novo: Nível Académico ganha página "Show"

Hoje `NivelAcademicoController` só tem `index`/`store`/`update`/
`alterarEstado`/`destroy` — não há página de detalhe por nível, ao
contrário de `Curso` (que já tem `Curso::show()` →
`Curso/Pages/Show.vue` com o card "Planos Curriculares"). Sem Curso, o
Nível Académico é o único contexto de gestão possível — por isso
precisa do mesmo tipo de página.

- Nova rota: `GET /niveis-academicos/{nivelAcademico}` →
  `NivelAcademicoController::show()`.
- Novo método `NivelAcademicoConsultaService::comRelacoes(NivelAcademico $nivel): NivelAcademico`
  — mesmo padrão de `CursoConsultaService::comRelacoes()`, eager-load
  de `planosCurriculares`.
- Nova página `Modules/Turma/resources/js/Pages/NiveisAcademicos/Show.vue`
  — estrutura análoga a `Curso/Pages/Show.vue` (cabeçalho com
  nome/etapa/estado, card "Planos Curriculares" com tabela + botão
  "Novo Plano").
- `NiveisAcademicos/Index.vue`: cada linha ganha um link para o Show
  (hoje a tabela só tem acções em dropdown, sem "ver detalhe").

## Frontend — os dois pontos de entrada, mesma entidade

**A partir do Curso** (`NovoPlanoCurricularModal.vue`, em
`Modules/Curso`): ganha um select "Nível Académico" (agora obrigatório
— antes não perguntava nada disto, o curso já chegava implícito). O
`Curso::show()` passa a enviar também `niveisAcademicos` como prop
(reaproveitando a mesma consulta já usada em
`PlanoCurricularConsultaService::opcoesFormulario()`).

**A partir do Nível Académico** (novo componente, em
`Modules/Turma/resources/js/Components/NivelAcademico/PlanosCurriculares/NovoPlanoCurricularModal.vue`):
injecta `nivel_academico_id` a partir do contexto da página (como o do
Curso já faz com `curso_id`), **sem perguntar Curso** — fica `null`.
Cobre directamente os exemplos dados (Creche I, 1ª Classe). Se um
estabelecimento raro quiser mesmo ligar um Curso a um plano criado a
partir do Nível, fá-lo depois por edição (próximo parágrafo) — sem
necessidade de duplicar o formulário de criação com um segundo campo
opcional que a esmagadora maioria dos casos (Creche/Pré-Escolar/
Primário) nunca usa.

**Edição** (`PlanoCurricularFormModal.vue`, dentro de
`PlanoCurricular/Show.vue`): ganha select "Nível Académico"
(obrigatório); o select "Curso" ganha uma opção "Sem curso" (valor
`null`) — é o único sítio onde se pode alterar o Curso de um plano já
criado, em qualquer direcção.

**`DisciplinaPlanoFormModal.vue`**: perde o select "Nível Académico" —
deixa de fazer sentido perguntar outra vez algo que o plano-pai já
fixou. `PlanoCurricularConsultaService::opcoesFormulario()` deixa de
precisar de expor `niveisAcademicos` para este formulário — mas
continua a ser preciso para os dois formulários de criação/edição do
Plano em si (movido de "opção da disciplina" para "opção do plano").

**`PlanoCurricular/Show.vue`**: mostra o Nível Académico no cabeçalho
do plano; o campo "Curso" mostra `—` quando `null`.

## Fronteiras de módulo

Sem direcção nova. `Modules\PlanoCurricular` já importa
`Modules\Turma\Models\NivelAcademico` hoje (via
`PlanoCurricularDisciplina`) — a mudança só desloca essa mesma
dependência já aceite de um filho para o pai. `Modules\Turma` ganha
`NivelAcademico::planosCurriculares()`, espelhando exactamente
`Curso::planosCurriculares()` já existente — mesma direcção
(PlanoCurricular → Turma), não o inverso.

## Testes afectados

- **`CriarPlanoCurricularRequestTest`**: `curso_id` deixa de ser
  obrigatório nos casos de teste; `nivel_academico_id` passa a
  obrigatório. Novo teste: criar plano só com nível (sem curso)
  sucede.
- **`CriarPlanoCurricularActionTest`**, **`AtualizarPlanoCurricularActionTest`**:
  fixtures ganham `nivel_academico_id`; `curso_id` passa a opcional em
  pelo menos um caso de teste.
- **`AdicionarDisciplinaRequestTest`**, **`AdicionarDisciplinaAoPlanoActionTest`**,
  **`AtualizarDisciplinaDoPlanoActionTest`**, **`RemoverDisciplinaDoPlanoActionTest`**:
  removem `nivel_academico_id` dos payloads/fixtures de disciplina (o
  nível já não se pergunta aqui — vem do plano criado no `setUp`).
- **`PlanoCurricularModelTest`**, **`PlanoCurricularDisciplinaModelTest`**:
  ajustar para a relação `nivelAcademico()` ter saído de
  `PlanoCurricularDisciplina` e entrado em `PlanoCurricular`.
- **`PlanoCurricularConsultaServiceTest`**: `niveisAcademicos` continua
  exposto, sem alteração de comportamento aqui.
- **`PlanoCurricularHistoricoTest`**, **`PlanoCurricularIsolamentoTest`**,
  **`PlanoCurricularHttpTest`**: fixtures de criação de plano ganham
  `nivel_academico_id`.
- Novo teste HTTP: criar um Nível Académico, aceder a
  `niveis-academicos.show`, criar um Plano Curricular a partir daí sem
  Curso, confirmar que aparece na página do Nível.
- Novo teste HTTP: a partir do Curso, criar dois Planos Curriculares
  para o mesmo curso em níveis diferentes (10ª e 11ª), confirmar que
  ficam como dois registos distintos, ambos visíveis na página do
  Curso.

## Auto-review

- **Placeholders:** nenhum "TBD" — todas as regras (nullable, required,
  unique ajustada) têm valor concreto.
- **Consistência interna:** a remoção de `nivel_academico_id` de
  `PlanoCurricularDisciplina` está reflectida em todas as camadas que a
  usavam (DTO, Requests, Action, frontend do formulário de disciplina)
  — não fica nenhuma referência órfã.
- **Escopo:** toca só o módulo PlanoCurricular + duas adições pontuais
  em Turma (`NivelAcademico::planosCurriculares()`, página Show nova) e
  Curso (`niveisAcademicos` na prop do Show). Não mexe no mecanismo de
  períodos (`PlanoCurricularDisciplinaPeriodo`), que opera sobre
  `plano_curricular_ano_lectivo_id` + `plano_curricular_disciplina_id`
  e nunca referenciou `curso_id` nem `nivel_academico_id` directamente
  — confirmado seguro.
- **Ambiguidade:** o ponto "criar a partir do Nível não pergunta
  Curso" está explicitamente resolvido (YAGNI, editável depois) — sem
  espaço para reintroduzir um segundo campo opcional raramente usado
  no formulário de criação mais comum.
