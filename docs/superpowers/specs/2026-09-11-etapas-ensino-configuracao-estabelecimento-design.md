# Configuração de Etapas de Ensino no Estabelecimento — Design

## Objectivo

A feature anterior ("Etapa de Ensino", spec de 2026-09-11) deixou a Etapa de
Ensino livre por `NivelAcademico`, com o conjunto do estabelecimento apenas
**derivado** por consulta (`NivelAcademicoConsultaService::etapasEnsino()`).

Decisão revista pelo utilizador: o Estabelecimento deve **declarar
antecipadamente** quais Etapas de Ensino opera, e o resto do sistema
(a começar por `NivelAcademico`) só pode escolher entre as etapas
declaradas. Isto substitui o modelo "derivado" por um modelo
"configurado primeiro, consumido depois".

## Contexto e decisões confirmadas na conversa

1. **Tipo de Ensino continua single-select** (Geral/Técnico/Universitário) —
   confirmado explicitamente: o utilizador nunca vende o mesmo sistema para
   uma instituição que misture Técnico com Geral ou Universitário. Cada
   instalação serve um único Tipo de Ensino. Não há caso de negócio para
   Tipo de Ensino multi-selecção.
2. **Relação Tipo de Ensino → Etapas de Ensino:**
   - `UNIVERSITARIO` → Etapas de Ensino fica **fixo em `[SUPERIOR]`**,
     decidido pelo sistema, sem escolha do utilizador.
   - `GERAL` ou `TECNICO` → o utilizador escolhe livremente, por
     checkboxes, entre `{CRECHE, PRE_ESCOLAR, PRIMARIO, SECUNDARIO}`
     (`SUPERIOR` fica fora destas duas). **Nada vem pré-seleccionado** —
     as checkboxes começam todas desmarcadas; o administrador escolhe
     exactamente o que a escola oferece.
3. **Remover uma etapa já em uso é bloqueado**: se o estabelecimento tiver
   `NivelAcademico` numa etapa e alguém tentar desmarcá-la na configuração,
   a gravação falha com erro de validação — mesmo princípio já usado no
   resto da app (não eliminar Curso/Nível com Turmas associadas).
4. **`NivelAcademicoConsultaService::etapasEnsino()` (Task 7 da feature
   anterior) fica redundante e é removido** — não tinha consumidor em
   produção (confirmado na revisão final dessa feature), e a fonte de
   verdade passa a ser a configuração do Estabelecimento, não mais
   derivada dos níveis já criados.

## Modelo de dados

Nova tabela `estabelecimento_etapas_ensino`, módulo **Estabelecimento**
(mesmo padrão enum+descrição já usado em `niveis_academicos`):

| Coluna | Tipo | Regra |
|---|---|---|
| id | bigint | PK |
| estabelecimento_id | FK → estabelecimentos | obrigatório, `cascadeOnDelete` |
| etapa_ensino | unsignedTinyInteger | obrigatório |
| etapa_ensino_descricao | string | sincronizado automaticamente a partir de `etapa_ensino` |
| timestamps | | |

`unique(estabelecimento_id, etapa_ensino)` — uma etapa não se repete para
o mesmo estabelecimento.

**Rejeitada:** coluna JSON (`estabelecimentos.etapas_ensino`). Esta app
testa em sqlite (`phpunit.xml`) e corre em produção em pgsql
(`.env.example`) — os dois dialectos tratam operadores JSON de forma
diferente, e este projecto já tem histórico de assumir “passa nos
testes” quando na verdade só passa no dialecto de teste (ver o
incidente real desta mesma sessão: migração passou nos testes sqlite e
falhou em produção pgsql por causa de dados já existentes). Uma tabela
relacional evita esta classe de risco e segue o padrão já estabelecido
neste código.

**Sem CRUD próprio**: não há `Controller`/`Action`/`Service` dedicados a
`estabelecimento_etapas_ensino`. É gerido inteiramente dentro do fluxo
já existente de actualização do Estabelecimento
(`AtualizarDadosEstabelecimentoAction`), tal como hoje `tipo_ensino` é
só mais um campo desse mesmo fluxo.

### Model `EstabelecimentoEtapaEnsino`

`Modules\Estabelecimento\Models\EstabelecimentoEtapaEnsino`:
- `$fillable = ['estabelecimento_id', 'etapa_ensino']`.
- `$casts = ['etapa_ensino' => EtapaEnsinoEnum::class]`.
- `booted()` com `static::saving()` sincronizando
  `etapa_ensino_descricao` a partir de `label()` (mesmo padrão manual já
  usado em `Estabelecimento`/`NivelAcademico` — sem trait genérica,
  porque não é o enum binário `Estado`).
- Sem `RegistaAutoria`/estado — é configuração pura, gerida por inteiro
  pelo fluxo de actualização do Estabelecimento, não uma entidade com
  ciclo de vida próprio.

`Estabelecimento` ganha a relação:

```php
public function etapasEnsino(): HasMany
{
    return $this->hasMany(EstabelecimentoEtapaEnsino::class, 'estabelecimento_id');
}
```

## Regra de negócio: `AtualizarDadosEstabelecimentoAction`

Dentro da mesma `DB::transaction()` já existente, depois de gravar os
dados base do estabelecimento:

1. Calcular as etapas alvo:
   - Se `dto->tipo_ensino === TipoEnsinoEnum::UNIVERSITARIO` → alvo é
     sempre `[EtapaEnsinoEnum::SUPERIOR]`, **ignorando** o que vier no
     DTO para este campo (o utilizador não escolhe; o backend não confia
     no que o frontend eventualmente enviar aqui).
   - Caso contrário → alvo é `dto->etapas_ensino` tal como validado pelo
     Request (subconjunto de `{CRECHE, PRE_ESCOLAR, PRIMARIO, SECUNDARIO}`).
2. Calcular quais etapas **actuais** (já gravadas) deixam de estar no
   alvo (etapas a remover).
3. Para cada etapa a remover, verificar se existe algum
   `Modules\Turma\Models\NivelAcademico` do estabelecimento nessa etapa
   (`NivelAcademico::where('estabelecimento_id', ...)->where('etapa_ensino', ...)->exists()`).
   Se existir, `throw ValidationException::withMessages(['etapas_ensino' => "Não é possível remover a etapa \"{etapa}\" porque já existem níveis académicos associados a ela."])` — a transacção reverte tudo (incluindo os outros campos do estabelecimento que estavam a ser gravados no mesmo pedido).
4. Aplicar a diferença: apagar as linhas de `estabelecimento_etapas_ensino`
   que saíram, criar (`firstOrCreate`) as que entraram.

### Fronteira de módulo — excepção assumida

O passo 3 obriga `Modules\Estabelecimento` a importar
`Modules\Turma\Models\NivelAcademico`. Isto **quebra** o princípio
seguido até agora nesta feature (Estabelecimento nunca depender de
Turma) — mas é uma consequência directa e inevitável da regra de
negócio que o próprio utilizador pediu ("bloquear a remoção enquanto
houver níveis associados"): não há como validar isto sem consultar dados
de Turma. Assumido como excepção pontual e documentada, não como
precedente geral. Alternativa descartada por over-engineering nesta
fase: um mecanismo de eventos/contratos para desacoplar as duas
direcções — sem outro caso de uso hoje que a justifique.

## `EstabelecimentoDTO` / `AtualizarDadosRequest`

DTO ganha `public array $etapas_ensino` (array de `EtapaEnsinoEnum`).

Request (`AtualizarDadosRequest::rules()`):

```php
'etapas_ensino' => [
    'array',
    Rule::requiredIf(fn () => (int) $this->input('tipo_ensino') !== TipoEnsinoEnum::UNIVERSITARIO->value),
    'min:1',
],
'etapas_ensino.*' => [
    'integer',
    Rule::in([
        EtapaEnsinoEnum::CRECHE->value,
        EtapaEnsinoEnum::PRE_ESCOLAR->value,
        EtapaEnsinoEnum::PRIMARIO->value,
        EtapaEnsinoEnum::SECUNDARIO->value,
    ]),
],
```

Quando `tipo_ensino = UNIVERSITARIO`, `etapas_ensino` não é exigido no
pedido (o frontend nem mostra o campo nesse caso) — o DTO/Action tratam
do valor fixo `[SUPERIOR]` independentemente do que chegar.

`EstabelecimentoDTO::fromRequest`:

```php
etapas_ensino: TipoEnsinoEnum::from((int) $dados['tipo_ensino']) === TipoEnsinoEnum::UNIVERSITARIO
    ? [EtapaEnsinoEnum::SUPERIOR]
    : array_map(fn ($v) => EtapaEnsinoEnum::from((int) $v), $dados['etapas_ensino'] ?? []),
```

## `NivelAcademico` passa a validar contra a configuração

`CriarNivelAcademicoRequest`/`AtualizarNivelAcademicoRequest`: a regra de
`etapa_ensino` deixa de ser `in:1,2,3,4,5` fixo:

```php
'etapa_ensino' => [
    'required',
    'integer',
    Rule::in(
        Estabelecimento::current()?->etapasEnsino()->pluck('etapa_ensino')
            ->map(fn (EtapaEnsinoEnum $e) => $e->value)->all() ?? []
    ),
],
```

Mensagem de erro (`etapa_ensino.in`) actualizada para deixar claro que a
etapa não está configurada para o estabelecimento (não "é inválida" em
abstracto): *"A etapa de ensino indicada não está configurada para este
estabelecimento."*

`NivelAcademicoController::index` passa a enviar também as opções
disponíveis (via novo método no `GestaoEstabelecimentoService`, ver
abaixo) para o frontend filtrar o select.

## Remoção do código redundante (Task 7 da feature anterior)

- Apagar o método `NivelAcademicoConsultaService::etapasEnsino()` —
  ficheiro volta a ter só `listar()`. Remover o import
  `Illuminate\Support\Collection as SupportCollection` que só servia
  para esse método.
- Apagar `Modules/Turma/tests/Feature/NivelAcademicoConsultaServiceTest.php`
  por inteiro (os dois testes existentes testam exactamente o método
  removido).

## Serviços novos/alterados

`Modules\Estabelecimento\Services\GestaoEstabelecimentoService`: novo
método de leitura (mesmo objecto que já expõe `obterAtual()`):

```php
public function etapasEnsinoConfiguradas(): array
{
    return Estabelecimento::current()?->etapasEnsino()
        ->pluck('etapa_ensino')->map(fn (EtapaEnsinoEnum $e) => $e->value)->all() ?? [];
}
```

`EstabelecimentoController::dados()` passa a incluir
`'etapasEnsino' => $this->service->etapasEnsinoConfiguradas()` nas props
Inertia, ao lado de `estabelecimento` (mesmo padrão de props extra já
usado em `TurmaController::index`).

`Modules\Turma\Services\NivelAcademicoConsultaService` (ou onde fizer
mais sentido no controller de Turma): também precisa de expor as etapas
configuradas para o formulário de criação/edição de Nível Académico —
`NivelAcademicoController::index` passa a incluir
`'etapasEnsino' => ...` (reaproveitando a mesma leitura, via
`Estabelecimento::current()?->etapasEnsino`, desta vez do lado de Turma,
que já importa `Estabelecimento` livremente).

## Frontend

### `CampoFicha.vue` (módulo Estabelecimento) — novo `type="checkboxes"`

Extensão aditiva, sem alterar o comportamento dos tipos existentes
(`text`/`email`/`number`/`textarea`/`select`):

- `modelValue` passa a aceitar também `Array` (`type: [String, Number, Array]`).
- Modo edição, `type === 'checkboxes'`: grupo de `<input type="checkbox">`
  Bootstrap, um por opção, `checked` se `modelValue.includes(opcao.value)`,
  `@change` adiciona/remove o valor do array emitido via
  `update:modelValue`.
- Modo leitura, `type === 'checkboxes'`: `valorExibido` passa a devolver
  os `label`s dos valores seleccionados, unidos por `", "` (ou "Não
  definido" se vazio, reaproveitando o `ficha-valor--vazio` já existente).

### `DadosDaEscola.vue`

- Novo prop `etapasEnsino: { type: Array, default: () => [] }` (valores
  já configurados, vindos do backend).
- `snapshot()` ganha `etapas_ensino: props.etapasEnsino ?? []`.
- Novo campo no bloco "Identificação", a seguir a "Tipo de Ensino":
  - Se `form.tipo_ensino === TIPO_ENSINO.UNIVERSITARIO`: mostra texto
    fixo "Ensino Superior" com nota "(fixo para Ensino Universitário)",
    **sem** input, mesmo em modo edição — não é uma escolha do
    utilizador.
  - Caso contrário: `<CampoFicha v-model="form.etapas_ensino" type="checkboxes" :options="ETAPAS_NAO_SUPERIOR" required .../>`
    com as 4 opções (Creche/Pré-Escolar/Primário/Secundário) **todas
    desmarcadas por omissão** para um estabelecimento novo — nunca
    pré-seleccionar as quatro.
- `watch(() => form.tipo_ensino, (novo) => { if (novo === TIPO_ENSINO.UNIVERSITARIO) form.etapas_ensino = [5]; })`
  — mantém `form.etapas_ensino` coerente com a escolha de Tipo de Ensino
  mesmo antes de submeter (o backend força isto de qualquer forma, mas o
  formulário não deve mostrar um estado inconsistente entretanto).

### `NivelAcademicoFormModal.vue`

- `ETAPA_ENSINO_OPCOES` deixa de ser a lista fixa das 5 etapas — passa a
  vir por prop (`etapasEnsinoDisponiveis: { type: Array, required: true }`,
  no formato `[{value, label}]` já filtrado pelo backend às etapas
  configuradas do estabelecimento actual).
- `Pages/NiveisAcademicos/Index.vue` passa esta prop adiante a partir do
  que `NivelAcademicoController::index` envia.

## Dados existentes — migração com backfill

A tabela nova é criada `NOT NULL`-friendly por natureza (não há coluna
NOT NULL sem default a adicionar a uma tabela existente — é uma tabela
nova, vazia por definição). O que precisa de backfill é o **conteúdo**:
sem isto, o Estabelecimento já criado (Geral, com 10ª/11ª Classe em
Secundário — o cenário real desta sessão) ficaria com zero etapas
configuradas, e a próxima tentativa de criar/editar um Nível Académico
falharia a validação para todas as etapas.

Dentro da própria migração (`up()`), depois de criar a tabela, para cada
`estabelecimento` existente:
- Se `tipo_ensino = UNIVERSITARIO` → insere só `[SUPERIOR]`.
- Caso contrário → insere a união das etapas já em uso pelos
  `niveis_academicos` desse estabelecimento (mesma consulta que o
  método removido fazia, agora como lógica de backfill pontual dentro da
  migração, não como serviço em runtime).

Sem essa etapa em uso nenhuma (estabelecimento Geral/Técnico sem nenhum
nível académico ainda criado) → fica sem nenhuma linha, e o
administrador configura manualmente antes de conseguir criar o primeiro
Nível Académico. Comportamento aceitável e consistente com "nada vem
pré-seleccionado".

## Testes afectados

- **`Modules/Estabelecimento/tests/Feature/GestaoEstabelecimentoTest.php`**:
  novos testes — actualizar para Geral/Técnico exige `etapas_ensino` não
  vazio; actualizar para Universitário força `[SUPERIOR]`
  independentemente do que for enviado; remover uma etapa sem níveis
  associados funciona; remover uma etapa com níveis associados falha
  com erro de validação e não altera nada (transacção revertida).
- **`Modules/Turma/tests/Feature/TurmaHttpTest.php`**: o helper privado
  `criarEstabelecimento()` passa a também configurar
  `EtapaEnsinoEnum::PRIMARIO` e `EtapaEnsinoEnum::SECUNDARIO` para o
  estabelecimento criado (via `EstabelecimentoEtapaEnsino::create()`
  directo, sem passar pelo Request/Action de Estabelecimento) — cobre
  exactamente as duas etapas já usadas pelos testes existentes
  (`criarNivelAcademico()` por omissão usa `SECUNDARIO`; os testes "não
  exige curso" usam `PRIMARIO`), evitando ter de tocar em cada teste
  individualmente.
- Apagar `Modules/Turma/tests/Feature/NivelAcademicoConsultaServiceTest.php`
  (testava o método removido).
- Novo teste garante que `CriarNivelAcademicoRequest`/
  `AtualizarNivelAcademicoRequest` rejeitam uma etapa válida no enum mas
  não configurada para o estabelecimento actual (ex.: `SUPERIOR` num
  estabelecimento Geral que só configurou Secundário).

## Auto-review

- **Placeholders:** nenhum "TBD" — todas as regras têm valor concreto.
- **Consistência interna:** a excepção de fronteira de módulo
  (Estabelecimento → Turma) está declarada explicitamente como excepção
  pontual, não escondida; a spec anterior tinha decidido o oposto pela
  razão contrária (não havia, até agora, nenhuma regra de negócio que o
  exigisse — agora há).
  A remoção do método da Task 7 está coerente com a razão dada (sem
  consumidor + fonte de verdade mudou de "derivada" para "configurada").
- **Escopo:** uma tabela nova (sem CRUD próprio), uma extensão a um
  fluxo já existente (Estabelecimento), uma restrição de validação num
  fluxo já existente (NivelAcademico), uma extensão aditiva a um
  componente Vue partilhado (`CampoFicha`). Não introduz nenhuma
  entidade nova com ciclo de vida próprio, nem toca em Curso,
  PlanoCurricular, Turma (além da validação de `NivelAcademico` já
  afectada pela feature anterior).
- **Ambiguidade:** "nada pré-seleccionado" é explícito tanto na regra de
  negócio como no comportamento do formulário para um estabelecimento
  novo — sem espaço para interpretar "todas seleccionadas por omissão".
