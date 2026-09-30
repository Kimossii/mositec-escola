# Etapa de Ensino — Design

## Objectivo

Introduzir a dimensão **Etapa de Ensino** (Creche, Pré-Escolar, Ensino
Primário, Ensino Secundário, Ensino Superior), separada do **Tipo de
Ensino** já existente em Estabelecimento (Geral/Técnico/Universitário),
para que o MosiTec suporte instituições mistas (ex.: Creche +
Pré-Escolar + Ensino Geral) e para que a obrigatoriedade de `curso_id`
em Turma deixe de bloquear estabelecimentos que não trabalham por
cursos (creches, pré-escolares, ensino primário).

## Contexto (estado actual, confirmado no código)

- `TipoEnsinoEnum` (`Modules\Estabelecimento\Enums`) é um campo único
  em `estabelecimentos`, puramente descritivo — nenhum módulo lê este
  valor nem ramifica lógica a partir dele. É classificação
  administrativa/legal (tipo de registo MINED), não estrutura
  pedagógica.
- `Estabelecimento` é hoje efectivamente singleton
  (`Estabelecimento::current()` = registo com `is_active = true`) — a
  pergunta "várias etapas" é sobre uma instituição acumular etapas
  diferentes, não sobre múltiplos estabelecimentos.
- `NivelAcademico` (módulo Turma) é uma tabela livre por
  estabelecimento (`codigo`, `nome`, `ordem`), sem qualquer
  classificação fixa — é o ponto onde cada instituição desenha a sua
  estrutura pedagógica concreta.
- `Turma.curso_id` é `NOT NULL` / `restrictOnDelete`, sem excepção
  (ver `add_curso_id_to_turmas_table`). Uma creche não consegue hoje
  criar Turma.

## Decisões confirmadas

1. `TipoEnsinoEnum` não muda — fica como classificação administrativa
   independente da Etapa de Ensino.
2. `EtapaEnsinoEnum` é um enum PHP fechado (mesma família de
   `TipoEnsinoEnum`/`TipoEstabelecimentoEnum`), não uma tabela — é
   taxonomia nacional fixa, não dado customizável pela instituição.
3. **Sem pivot** `Estabelecimento` ↔ `EtapaEnsino`. As etapas "activas"
   de um estabelecimento derivam-se dos `niveis_academicos` já
   criados (`DISTINCT etapa_ensino`), evitando duas fontes de verdade
   que podem divergir.
4. `NivelAcademico` ganha FK/coluna `etapa_ensino` — é o encaixe
   natural, porque já é o ponto de customização pedagógica por
   estabelecimento.
5. **Sem coluna `exige_curso` em `NivelAcademico`.** A obrigatoriedade
   de `curso_id` em Turma deriva de uma regra fixa no próprio enum:
   `EtapaEnsinoEnum::exigeCurso(): bool` — `true` para
   `SECUNDARIO`/`SUPERIOR`, `false` para
   `CRECHE`/`PRE_ESCOLAR`/`PRIMARIO`. Reflecte o sistema angolano
   (cursos/ramos só existem a partir do secundário).
6. `Turma.curso_id` passa a nullable; a obrigatoriedade é decidida em
   validação (Form Request), não na base de dados.

## Parte A — `EtapaEnsinoEnum`

Novo ficheiro `Modules/Estabelecimento/app/Enums/EtapaEnsinoEnum.php`:

```php
enum EtapaEnsinoEnum: int
{
    case CRECHE = 1;
    case PRE_ESCOLAR = 2;
    case PRIMARIO = 3;
    case SECUNDARIO = 4;
    case SUPERIOR = 5;

    public function label(): string { ... }

    public function exigeCurso(): bool
    {
        return match ($this) {
            self::SECUNDARIO, self::SUPERIOR => true,
            default => false,
        };
    }
}
```

Mesmo padrão de `TipoEnsinoEnum`: `int`-backed, `label()` para a
coluna-descrição. `exigeCurso()` é a única lógica de negócio nova no
enum, usada exclusivamente pela validação de Turma (Parte C).

## Parte B — `NivelAcademico` ganha Etapa de Ensino

### Migração

Nova migração no módulo Turma,
`add_etapa_ensino_to_niveis_academicos_table`:

```php
$table->unsignedTinyInteger('etapa_ensino')->after('nome');
$table->string('etapa_ensino_descricao')->after('etapa_ensino');
```

Sem `default` — mesma lógica já aplicada em `curso_id` de Turma:
assume-se ambiente de desenvolvimento sem níveis académicos reais
ainda criados via UI. Se correr num ambiente com dados, a migração
falha e exige `module:migrate-fresh` ou backfill manual antes de
aplicar.

`unique(estabelecimento_id, codigo)` e `unique(estabelecimento_id,
nome)` mantêm-se inalteradas — a etapa não entra na unicidade (o mesmo
código/nome continua a não poder repetir-se dentro do estabelecimento,
independentemente da etapa).

### Model `NivelAcademico`

- `etapa_ensino` entra em `$fillable` e em `$casts` (→
  `EtapaEnsinoEnum::class`).
- `etapa_ensino_descricao` é sincronizada manualmente num `saving()`
  no `booted()` do model (mesmo padrão usado em
  `Estabelecimento::booted()` para `tipo_ensino_descricao` — a trait
  `SincronizaEstadoDescricao` é específica do enum binário `Estado` e
  não serve aqui).

### DTO / Requests

- `NivelAcademicoDTO`: novo campo `public EtapaEnsinoEnum $etapa_ensino`
  no construtor, preenchido em `fromCriarRequest`/`fromAtualizarRequest`
  via `EtapaEnsinoEnum::from((int) $dados['etapa_ensino'])`.
- `CriarNivelAcademicoRequest` e `AtualizarNivelAcademicoRequest`:
  `'etapa_ensino' => 'required|integer|in:1,2,3,4,5'`, com mensagens
  `etapa_ensino.required` / `etapa_ensino.in`. Obrigatório em ambos —
  criação e edição — sem excepção (ao contrário de `ano_lectivo_id` em
  Turma, que só se define na criação).

### Actions

`CriarNivelAcademicoAction` e `AtualizarNivelAcademicoAction` passam a
incluir `etapa_ensino` no array persistido, replicando exactamente
como já tratam `codigo`/`nome`/`ordem`.

### `Estabelecimento::etapasEnsino()`

Novo método no model `Estabelecimento`:

```php
public function etapasEnsino(): Collection
{
    return NivelAcademico::where('estabelecimento_id', $this->id)
        ->distinct()
        ->pluck('etapa_ensino');
}
```

Sem migração associada — é consulta agregada, não estado persistido.
(Import de `Modules\Turma\Models\NivelAcademico` dentro de
`Estabelecimento` introduz uma dependência Estabelecimento → Turma que
hoje não existe na direcção inversa; ver "Fronteiras de módulo"
abaixo.)

## Parte C — `Turma.curso_id` deixa de ser sempre obrigatório

### Migração

Nova migração no módulo Turma, `make_curso_id_nullable_on_turmas_table`:

```php
$table->foreignId('curso_id')->nullable()->change();
```

`restrictOnDelete` mantém-se (não se pode apagar um Curso com Turmas
associadas, mesmo que outras Turmas não tenham curso nenhum).

### `TurmaConsultaService::opcoesFormulario()`

`niveisAcademicos` passa a seleccionar também `etapa_ensino`:

```php
'niveisAcademicos' => NivelAcademico::where('estabelecimento_id', $estabelecimentoId)
    ->where('estado', 1)->orderBy('ordem')->get(['id', 'nome', 'etapa_ensino']),
```

Necessário para o frontend (Parte D) decidir se mostra o select de
Curso.

### `CriarTurmaRequest` / `AtualizarTurmaRequest`

`curso_id` deixa de ser `required` fixo e passa a `required_if`
resolvido dinamicamente a partir do `NivelAcademico` seleccionado:

```php
'curso_id' => [
    Rule::requiredIf(function () {
        $nivel = NivelAcademico::find($this->input('nivel_academico_id'));
        return $nivel && $nivel->etapa_ensino->exigeCurso();
    }),
    'nullable',
    'integer',
    'exists:cursos,id',
],
```

Mensagem `curso_id.required_if` reaproveita o texto actual ("O curso é
obrigatório."). Sem alteração ao `unique(ano_lectivo_id, codigo)`.

### `TurmaDTO`

`curso_id` já é `?int` opcional no construtor — sem alteração de
assinatura. `fromCriarRequest`/`fromAtualizarRequest` passam a usar
`isset($dados['curso_id']) ? (int) $dados['curso_id'] : null` em vez do
cast incondicional actual.

### Actions / Model

`CriarTurmaAction`/`AtualizarTurmaAction`: sem alteração de lógica —
já persistem o valor do DTO tal como vem (agora pode ser `null`).
`Turma::curso()` continua `BelongsTo`, já nullable por natureza.

## Parte D — Frontend

### `NivelAcademicoFormModal.vue`

- Novo select "Etapa de Ensino" (obrigatório), com as 5 opções fixas
  do enum — hardcoded no componente (mesmo padrão de `ESTADO_OPCOES`
  já usado no próprio ficheiro), sem endpoint dedicado.
- `Pages/NiveisAcademicos/Index.vue`: nova coluna "Etapa" na tabela,
  entre "Nome" e "Estado".

### `TurmaFormModal.vue`

- `niveisAcademicos` (prop já existente) passa a incluir
  `etapa_ensino` por item.
- Computed `nivelExigeCurso` a partir do `nivel_academico_id`
  seleccionado e do mapa fixo de etapas que exigem curso (espelha
  `EtapaEnsinoEnum::exigeCurso()` no frontend — mesma lista de 2
  valores, sem chamada ao backend).
- O bloco do select "Curso" só é renderizado
  (`v-if="nivelExigeCurso"`); quando oculto, `form.curso_id` é
  limpo (`null`) antes de submeter, para não enviar um valor obsoleto
  de uma selecção anterior.
- Label do select de Curso mantém-se `required` (sem opção "Sem
  curso") — a ocultação condicional é que resolve o caso "não se
  aplica", não uma opção nula visível.

## Fronteiras de módulo

- `EtapaEnsinoEnum` vive em `Estabelecimento` (mesma família de
  `TipoEnsinoEnum`). `Turma` já depende de `Estabelecimento`
  (`NivelAcademico::estabelecimento()`), logo `NivelAcademico` importar
  `EtapaEnsinoEnum` não introduz direcção de dependência nova.
- `Estabelecimento::etapasEnsino()` introduz a única dependência nova:
  `Estabelecimento` → `Turma\Models\NivelAcademico`. É a direcção
  inversa da habitual neste código (módulos dependem de
  Estabelecimento, não o contrário). Aceitável porque é um método de
  leitura isolado e opcional — se causar desconforto arquitectural,
  a alternativa é mover este método para
  `Modules\Turma\Services\NivelAcademicoConsultaService` como
  `etapasEnsinoDoEstabelecimento(): Collection` em vez de um método no
  model `Estabelecimento`. Fica como nota para decisão no plano de
  implementação, não bloqueia o resto do spec.
- `Curso` e `PlanoCurricular` não são tocados — continuam sem saber
  nada de etapas, exactamente como hoje.

## Dados/migrações existentes

- `estabelecimentos.tipo_ensino` / `tipo_ensino_descricao`: sem
  alteração.
- `niveis_academicos`: nova coluna `NOT NULL` sem backfill (assume-se
  fase de desenvolvimento, tabela ainda sem dados reais via UI — ver
  Parte B).
- `turmas.curso_id`: passa de `NOT NULL` para `nullable` — mudança
  sempre seguro (relaxar uma constraint nunca quebra dados
  existentes).

## Testes afectados

- `NivelAcademico*Test`: todos os `NivelAcademico::create([...])` e
  chamadas a `niveis-academicos.store`/`update` precisam de passar a
  incluir `etapa_ensino`. Novo teste garante `etapa_ensino_descricao`
  sincronizada. Novo teste garante `etapa_ensino` obrigatório
  (validação falha sem ele).
- `TurmaHttpTest`: novo teste garante criar/editar Turma **sem**
  `curso_id` quando o nível académico é de etapa `PRIMARIO` (ou
  `CRECHE`/`PRE_ESCOLAR`) — sucesso. Novo teste garante que continua a
  falhar por validação quando a etapa é `SECUNDARIO`/`SUPERIOR` e
  `curso_id` não é enviado. Testes existentes que criam Turma com nível
  de etapa "com curso" continuam a passar `curso_id` normalmente.
- Novo teste unitário para `EtapaEnsinoEnum::exigeCurso()` cobrindo os
  5 casos.

## Auto-review

- **Placeholders:** nenhum "TBD" — todas as regras (mapeamento etapa →
  exige curso, formato das colunas, mensagens de validação) têm valor
  concreto.
- **Consistência interna:** `curso_id` nullable em Turma implica
  `Rule::requiredIf` em vez de `required` fixo, já reflectido em todas
  as camadas (migração, request, DTO, frontend).
- **Escopo:** um enum novo (Estabelecimento), uma coluna nova
  (NivelAcademico), uma constraint relaxada (Turma). Não introduz
  Matrícula, Disciplina, nem qualquer conceito fora da pergunta
  original. `Curso` e `PlanoCurricular` ficam intocados.
- **Ponto em aberto (não bloqueia):** localização de
  `etapasEnsino()` — no model `Estabelecimento` ou no
  `NivelAcademicoConsultaService` de Turma. Ambas as opções são
  triviais de trocar no plano de implementação; nenhuma tem impacto no
  resto do desenho.
