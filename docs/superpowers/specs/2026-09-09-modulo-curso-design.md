# Módulo Curso — Design

## Objectivo

Criar o módulo `Curso`, um catálogo simples de cursos/formações oferecidas
por um Estabelecimento, e ligar a Turma a um Curso de forma obrigatória.

Curso é conceptualmente diferente de NivelAcademico (que já existe em
Turma): NivelAcademico é o ano/classe ("10ª Classe"); Curso é a
especialização/formação ("Informática", "Contabilidade e Gestão"). Uma
Turma cruza os dois — nível académico + curso (+ turno).

## Parte A — Módulo Curso (standalone)

### Modelo de dados

Tabela `cursos`:

| Coluna | Tipo | Regra |
|---|---|---|
| id | bigint | PK |
| estabelecimento_id | FK → estabelecimentos | obrigatório, `restrictOnDelete` |
| codigo | string | obrigatório |
| nome | string | obrigatório |
| descricao | text nullable | opcional |
| estado | unsignedTinyInteger, default 1 | binário (`Modules\Core\Enums\Estado`: 0=Inativo, 1=Ativo) |
| estado_descricao | string, default 'Ativo' | sincronizado automaticamente a partir de `estado` |
| criado_por | FK → users nullable | `nullOnDelete` |
| editado_por | FK → users nullable | `nullOnDelete` |
| timestamps | | |

Sem soft delete — não há operação de eliminação neste módulo (ver
"Fora de escopo").

**Integridade:**
- `unique(estabelecimento_id, codigo)`
- `unique(estabelecimento_id, nome)`

Ambas reforçadas também a nível de validação (Form Request), para
mensagens de erro amigáveis antes de bater na BD.

### Modelo Eloquent

`Modules\Curso\Models\Curso` usa os traits já estabelecidos no módulo
Turma (`NivelAcademico`, `Turno`, `Turma`):
- `Modules\Core\Traits\RegistaAutoria` — preenche `criado_por`/`editado_por`.
- `Modules\Core\Traits\SincronizaEstadoDescricao` — sincroniza
  `estado_descricao` a partir de `estado` em `saving`.

Relações: `estabelecimento()`, `criadoPor()`, `editadoPor()`.

### Operações (escopo do spec original)

criar, listar, consultar, editar, activar/desactivar. **Sem eliminar** —
não existe `EliminarCursoAction`, rota `destroy`, nem permissão
`curso.eliminar`.

Alterar estado usa o mesmo padrão dos módulos irmãos: o cliente envia o
estado alvo (`Estado::ATIVO` ou `Estado::INATIVO`) via
`PATCH /cursos/{curso}/estado`, não um "toggle" cego.

### Camadas (Controllers finos)

Réplica exacta do padrão `NivelAcademico` (Turma):

- `DTO\CursoDTO` — `codigo`, `nome`, `?descricao`.
- `Actions\CriarCursoAction`, `AtualizarCursoAction`, `AlterarEstadoCursoAction`.
- `Http\Requests\CriarCursoRequest`, `AtualizarCursoRequest`, `AlterarEstadoCursoRequest`.
- `Services\GestaoCursoService` (escrita, delega às Actions),
  `Services\CursoConsultaService` (leitura, scoped por
  `Estabelecimento::current()`).
- `Http\Controllers\CursoController` — só chama Service/Consulta e
  autoriza.

### Permissões

Novo módulo no enum `Modules\Permissao\Enums\Modulo`:
`CURSO = 13`, slug `curso`, label `Curso`.

Acções: `curso.ver`, `curso.criar`, `curso.editar` (sem `curso.eliminar`
— não há rota que a exija).

`ModuloSeeder`: adicionar `['nome' => 13, 'descricao' => 'Curso']`.
`RolePermissaoSeeder`: ADMIN_ESCOLA recebe `Modulo::CURSO->value =>
['ver', 'criar', 'editar']`.

`ModuloSeederTest::test_seeder_e_idempotente` assume hoje 13 módulos
(`assertSame(13, Modulo::count())`) — passa a 14.

### Rotas

```
GET    /cursos                index   curso.ver
POST   /cursos                store   curso.criar
GET    /cursos/{curso}        show    curso.ver
PUT    /cursos/{curso}        update  curso.editar
PATCH  /cursos/{curso}/estado alterarEstado  curso.editar
```

### Frontend

Módulo de entidade única (sem subpastas por entidade — essa convenção
só se aplica a módulos multi-entidade como Infraestrutura/Turma):

- `resources/js/Models/Estado.js` — espelha `Core\Enums\Estado`, igual
  ao já usado em Turma/NivelAcademico.
- `resources/js/Components/Shared/EstadoBadge.vue` — igual ao de Turma.
- `resources/js/Components/CursoFormModal.vue` — campos código, nome,
  descrição (textarea), estado (só em edição).
- `resources/js/Pages/Index.vue` — tabela + acções (editar,
  activar/desactivar).
- `resources/js/Pages/Show.vue` — consulta simples (código, nome,
  descrição, estado, autoria).

### Menu

Entra em `useAcademicoMenu.js`, junto de "Turmas":
`{ href: '/cursos', label: 'Cursos', permissao: 'curso.ver' }`.

### Fora de escopo (confirmado)

Eliminar curso; Turmas, Alunos, Matrículas, Disciplinas, Professores,
Ano Lectivo, Classes/anos de formação como conceito diferente de Curso,
Programas, Graus, Ciclos, Áreas Científicas, Departamentos, Faculdades.

---

## Parte B — Ligação Turma ↔ Curso (obrigatória)

Decisão confirmada: **toda Turma exige um Curso** — regra de negócio,
não um relacionamento opcional.

### Migração

Nova migração no módulo Turma,
`add_curso_id_to_turmas_table`:

```php
$table->foreignId('curso_id')->after('nivel_academico_id')
    ->constrained('cursos')->restrictOnDelete();
```

Sem `nullable()` — coluna obrigatória. `restrictOnDelete` (não
`nullOnDelete`, que exigiria a coluna aceitar null) — mesmo critério já
usado em `ano_lectivo_id` e `nivel_academico_id` na mesma tabela: não
se pode apagar um Curso com Turmas associadas. Como o módulo Curso não
tem operação de eliminar, esta protecção só entra em jogo se alguém
mexer directamente na BD.

Assume-se ambiente de desenvolvimento sem dados reais de Turma em
produção — a coluna é adicionada já como NOT NULL, sem migração de
backfill. Se isto correr num ambiente com Turmas já criadas sem curso,
a migração falha e será necessário `module:migrate-fresh` ou um
backfill manual antes de aplicar.

### Unicidade da Turma — inalterada

Mantém-se `unique(ano_lectivo_id, codigo)`. `curso_id` não entra na
constraint (decisão confirmada) — dois cursos diferentes no mesmo ano
lectivo não podem ter o mesmo código de turma.

### Model `Turma`

- `curso_id` entra em `$fillable`.
- Nova relação: `curso(): BelongsTo` → `Modules\Curso\Models\Curso`.

### DTO / Requests

- `TurmaDTO`: novo campo `?int $curso_id = null` no construtor (mesma
  convenção "solta" já usada para `ano_lectivo_id`/`nivel_academico_id`
  — o tipo é nullable por conveniência, mas a obrigatoriedade real vem
  do Form Request). Preenchido em `fromCriarRequest` e
  `fromAtualizarRequest`.
- `CriarTurmaRequest` e `AtualizarTurmaRequest`: `'curso_id' =>
  'required|integer|exists:cursos,id'`, com mensagens
  `curso_id.required` / `curso_id.exists`.

Ao contrário de `ano_lectivo_id` (só se define na criação, não é
editável depois), `curso_id` é obrigatório e editável tanto em criar
como em editar — pedido explícito do utilizador.

### Actions / Service

`CriarTurmaAction` e `AtualizarTurmaAction` passam a incluir `curso_id`
no array persistido, replicando exactamente como já tratam
`nivel_academico_id`.

`TurmaConsultaService::opcoesFormulario()` passa a devolver também
`cursos`:

```php
'cursos' => Curso::where('estabelecimento_id', $estabelecimentoId)->orderBy('nome')->get(['id', 'nome']),
```

(mesmo critério dos níveis académicos e turnos — sem filtrar por
estado; consistente com o resto do formulário, que também não filtra
turnos/níveis inactivos).

### Frontend

`TurmaFormModal.vue`:
- Novo select "Curso", obrigatório (`required` na label, sem opção
  "Sem curso" — ao contrário do Turno), colocado ao lado do select de
  Nível Académico. Presente tanto na criação como na edição (ao
  contrário do Ano Lectivo, que só aparece na criação).
- Nova prop `cursos: { type: Array, required: true }`.

`Pages/Turmas/Index.vue` e `Pages/Turmas/Show.vue`: passam a mostrar o
nome do curso da turma (coluna/linha adicional), mesmo padrão visual
já usado para nível académico/turno.

### Permissões

Sem alterações — continua tudo sob `turmas.ver` / `turmas.criar` /
`turmas.editar`. Escolher um curso ao criar/editar turma não introduz
nova permissão.

### Testes afectados

- `TurmaHttpTest`: todos os `Turma::create([...])` e chamadas
  `route('turmas.store'/'turmas.update', ...)` nos testes existentes
  precisam de passar a incluir `curso_id` (criar um Curso de apoio no
  `setUp`/helper), senão passam a falhar por violação de NOT NULL /
  validação obrigatória.
- Novo teste garante que criar/editar Turma sem `curso_id` falha com
  erro de validação em `curso_id`.
- Novo teste garante que `turmas.index` expõe `cursos` nas opções de
  formulário.

## Auto-review

- **Cobertura do spec:** Parte A cobre os 5 requisitos do issue
  original (criar/listar/consultar/editar/activar-desactivar,
  identificação por código+nome, descrição opcional, autoria,
  unicidade por estabelecimento). Parte B cobre a decisão de ligação
  obrigatória Turma↔Curso, incluindo a correcção
  `nullOnDelete`→`restrictOnDelete`.
- **Placeholders:** nenhum "TBD" — todas as regras têm valor concreto.
- **Consistência interna:** `curso_id` obrigatório em Turma implica
  `restrictOnDelete` (não `nullOnDelete`), já reflectido acima.
- **Escopo:** módulo único (Curso) + uma alteração cirúrgica no módulo
  Turma (coluna + wiring). Não introduz Matrícula, Disciplina, ou
  qualquer conceito da lista "fora de escopo".
