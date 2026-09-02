# Módulo AnoLectivo — Frontend (Vue 3 + Inertia)

## Contexto

O backend do módulo `Modules\AnoLectivo` está completo, revisto e testado
(ver `docs/superpowers/specs/2026-08-30-modulo-anolectivo-design.md` e
`docs/superpowers/plans/2026-08-31-modulo-anolectivo-backend.md`). Faltam
as páginas Vue/Inertia que consomem as rotas já existentes
(`Modules/AnoLectivo/routes/web.php`): `AnoLectivoController`,
`PeriodoController`, `EventoCalendarioController`.

Esta spec cobre a fase frontend, numa única fase (Ano Lectivo, Períodos e
Calendário Escolar juntos — decisão confirmada: os padrões de UI são
consistentes entre as três partes e o backend já está todo pronto).

Fontes confirmadas por leitura de código (não inventadas): padrões reais de
`Modules/Usuario/resources/js`, `Modules/Estabelecimento/resources/js`,
`Modules/Permissao/resources/js`, `resources/js/Components/Shared`,
`resources/js/Components/Layout`, `resources/js/composables/useActiveMenu.js`.

## Princípio arquitetural

```
Modules/AnoLectivo/resources/js/
├── Models/AnoLectivo.js        (espelha os 3 enums do backend, só apresentação)
├── Components/
│   ├── AnoLectivoStatusBadge.vue
│   ├── AnoLectivoFormModal.vue
│   ├── Periodos/
│   │   ├── PeriodoTable.vue         (apresentação — emite eventos)
│   │   └── PeriodoFormModal.vue
│   └── Calendario/
│       ├── AnoLectivoCalendario.vue (apresentação — emite eventos)
│       └── EventoFormModal.vue
└── Pages/
    ├── Index.vue                (lista de Anos Lectivos)
    └── Show.vue                 (orquestrador: modais + chamadas Inertia)
```

**Princípio de responsabilidade**: `Show.vue` é o único ponto que decide
quando abrir/fechar modais e que faz as chamadas `router.*`.
`PeriodoTable.vue` e `AnoLectivoCalendario.vue` são puramente
apresentacionais — recebem dados por prop e emitem eventos
(`editar-periodo`, `eliminar-periodo`, `criar-evento`, `editar-evento`)
em vez de chamar o Inertia ou abrir modais directamente. Isto mantém os
dois componentes reutilizáveis/testáveis isoladamente e evita que a
lógica de navegação/estado fique espalhada por vários ficheiros.

Resolução automática já garantida por `resources/js/app.js` (glob
`/Modules/*/resources/js/Pages/**/*.vue`) — `Inertia::render('AnoLectivo/Index', ...)`
e `Inertia::render('AnoLectivo/Show', ...)` (já existentes nos
Controllers) resolvem para estes ficheiros sem qualquer configuração
adicional.

## Achados relevantes da exploração (não são decisões, são factos confirmados)

- **`useForm` do Inertia nunca é usado no projecto** — todo o formulário é
  `reactive`/`ref` manual + `router.post/put/patch/delete(...)` com
  `onSuccess`/`onError`/`onFinish`. Seguir exactamente este padrão.
- **Não existe paginação de servidor em nenhuma listagem** — todas enviam
  o array completo. Seguir o mesmo (as listas deste módulo são
  inerentemente pequenas: poucos Anos Lectivos, poucos Períodos por ano).
- **O backend não partilha `flash`/`session('success')` como prop
  Inertia** (`app/Http/Middleware/HandleInertiaRequests.php` só partilha
  `auth.user`) — nenhuma página do projecto lê flash; o toast que o
  utilizador vê vem sempre do `onSuccess` do próprio pedido. Não inventar
  um mecanismo de flash novo só para este módulo — manter a mesma
  inconsistência que já existe em todo o resto do projecto.
- **Não existe biblioteca de calendário no projecto** — decisão já tomada
  com o utilizador: introduzir `@fullcalendar/vue3` (primeira biblioteca
  nova do projecto para isto), espelhando o exemplo já presente no tema
  Metronic comprado (`_theme/apps/calendar` + `_theme/assets/js/custom/apps/calendar/calendar.js`,
  que usa FullCalendar em jQuery — aqui adaptado ao Vue 3 idiomático).
- **Não existe precedente de formulário em modal** — os formulários
  existentes (Usuario) são páginas dedicadas; o `ConfirmModal.vue` é o
  único modal do projecto. As rotas do AnoLectivo não têm GET dedicado
  para "criar"/"editar" (só `index`/`show` + `store`/`update`/`destroy`
  via POST/PUT/DELETE), o que **exige** modais em vez de páginas —
  não é uma escolha estética, é a única forma de encaixar nas rotas já
  aprovadas no backend.

## Estrutura de páginas

### `Pages/Index.vue`

Tabela de Anos Lectivos (`nome`, `data_inicio`–`data_fim`, badge de
`estado`, coluna de acções), seguindo o padrão de tabela do
`Modules/Permissao/resources/js/Pages/Perfis.vue` (classes Metronic
`table align-middle table-row-dashed table-hover fs-6 gy-5`, sem
componente de tabela separado dado o tamanho pequeno da lista).

Acções por linha (dropdown com `AcaoIcone.vue`, mesmo padrão de
`UsuarioTable.vue`):
- **Ver** → `router.get('/ano-lectivos/{id}')` (navega para `Show.vue`).
- **Alterar Estado** → submenu com os estados válidos diferentes do
  actual (ex.: se `PLANEADO`, mostra "Activar"; se `ATIVO`, mostra
  "Encerrar"). Confirmado via `ConfirmModal.vue` antes de
  `router.patch('/ano-lectivos/{id}/estado', { estado })`.
- **Eliminar** → `ConfirmModal.vue` → `router.delete('/ano-lectivos/{id}')`.
  Se a resposta vier com erro de validação (ano tem períodos/eventos), o
  `onError` mostra o erro via `toast.error(...)`.

Botão "Novo Ano Lectivo" no cabeçalho da página (mesmo estilo do
`page-title`/symbol de `UsuarioListLayout.vue`) abre `AnoLectivoFormModal.vue`
em modo criação.

### `Pages/Show.vue`

Recebe `anoLectivo` (com `periodos` e `eventosCalendario` já carregados,
via `AnoLectivoConsultaService::comRelacoes()`).

Estrutura:
1. **Cabeçalho/ficha** — nome, intervalo de datas, badge de estado,
   botão "Editar" (abre `AnoLectivoFormModal.vue` em modo edição,
   pré-preenchido).
2. **Abas** (mesma técnica de `EstabelecimentoTabs.vue` — abas Bootstrap
   `nav-line-tabs`):
   - **Períodos** — `PeriodoTable.vue` recebe `periodos` por prop e
     emite `editar-periodo`/`eliminar-periodo`; `Show.vue` escuta esses
     eventos e decide abrir `PeriodoFormModal.vue` ou `ConfirmModal.vue`
     e disparar o `router.*` correspondente. Botão "Novo Período" (no
     próprio `Show.vue`) abre `PeriodoFormModal.vue` em modo criação.
   - **Calendário Escolar** — `AnoLectivoCalendario.vue` recebe
     `anoLectivo`/`eventos` por prop e emite `criar-evento` (com a data
     clicada) e `editar-evento` (com o evento clicado); `Show.vue` reage
     abrindo `EventoFormModal.vue` e fazendo o `router.*`. Botão "Novo
     Evento" (no próprio `Show.vue`) faz o mesmo que `criar-evento`.

## Componentes do módulo

`Modules/AnoLectivo/resources/js/Components/`:

- **`AnoLectivoStatusBadge.vue`** — recebe `estado` (número 0/1/2) e
  `estadoDescricao` (string já traduzida vinda do backend). Mapeia para
  3 cores seguindo a convenção `badge badge-light-*` já usada em
  `UsuarioStatusBadge.vue`/`Perfis.vue`: `PLANEADO` →
  `badge-light-secondary`, `ATIVO` → `badge-light-success`, `ENCERRADO` →
  `badge-light-dark`.

- **`AnoLectivoFormModal.vue`** — modal manual (mesma técnica do
  `ConfirmModal.vue`: `show` prop, `modal d-block`, sem
  `data-bs-toggle`). Campos: `nome` (text), `data_inicio`/`data_fim`
  (date), `estado` (select, só visível/obrigatório em modo edição —
  `CriarAnoLectivoRequest` aceita `estado` opcional, mas
  `AtualizarAnoLectivoRequest` exige-o). Emite `router.post('/ano-lectivos', ...)`
  em criação ou `router.put('/ano-lectivos/{id}', ...)` em edição,
  escolhido dinamicamente pelo mesmo padrão de
  `UsuarioWizardForm.vue:163-182` (`router[metodo](url, payload, {...})`).

- **`Periodos/PeriodoFormModal.vue`** — mesma técnica. Campos: `nome`,
  `tipo` (select: Trimestre/Semestre/Outro), `numero` (number, opcional),
  `data_inicio`/`data_fim`. `Show.vue` decide quando abrir (criação ou
  edição) e faz `router.post('/ano-lectivos/{id}/periodos', ...)` /
  `router.put('/periodos/{id}', ...)` a partir do `onSubmit` do modal —
  o modal em si não conhece rotas, só emite `submit`/`cancelar` com o
  payload preenchido.

- **`Periodos/PeriodoTable.vue`** — componente de apresentação puro:
  prop `periodos` (array), emite `editar-periodo(periodo)` e
  `eliminar-periodo(periodo)`. Não faz nenhuma chamada Inertia nem abre
  modais — isso é responsabilidade de `Show.vue`.

- **`Calendario/EventoFormModal.vue`** — campos: `titulo`, `descricao`
  (textarea, opcional), `tipo` (select: Aula/Avaliação/Reunião/Férias/
  Feriado/Actividade/Evento/Outro), `data_inicio`/`data_fim`,
  `dia_inteiro` (checkbox). Recebe os valores iniciais por prop (nova
  data clicada, ou evento a editar); só emite `submit`/`cancelar` — o
  `router.post('/ano-lectivos/{id}/eventos-calendario', ...)` /
  `router.put('/eventos-calendario/{id}', ...)` fica em `Show.vue`.

- **`Calendario/AnoLectivoCalendario.vue`** — componente de apresentação
  puro: props `anoLectivo`, `eventos`; emite `criar-evento(data)` e
  `editar-evento(evento)`. Ver secção dedicada abaixo para a configuração
  do FullCalendar.

## Calendário Escolar (`AnoLectivoCalendario.vue`)

Bibliotecas: `@fullcalendar/vue3`, `@fullcalendar/core`,
`@fullcalendar/daygrid`, `@fullcalendar/timegrid`,
`@fullcalendar/interaction` (necessário para `dateClick`/`select`).

Configuração:
- `headerToolbar`: `{ left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek' }`
  — mesmo layout do exemplo do tema (`_theme/assets/js/custom/apps/calendar/calendar.js`),
  sem a vista `timeGridDay` (não há necessidade de granularidade horária
  para eventos escolares tipicamente de dia inteiro).
- `initialDate`: `anoLectivo.data_inicio` (mostra o calendário a começar
  no mês de início do ano lectivo, não no mês actual).
- `validRange`: `{ start: anoLectivo.data_inicio, end: <data_fim + 1 dia> }`
  — **atenção**: o `end` do `validRange` do FullCalendar é **exclusivo**
  (documentação oficial: a última data navegável/seleccionável é o dia
  anterior a `end`). Se se usasse `anoLectivo.data_fim` directamente, o
  próprio último dia do Ano Lectivo ficaria fora do intervalo navegável
  — errado. Calcular `end` como `data_fim` + 1 dia (uma pequena função
  local, ex. `proximoDia(dataISO)`, sem introduzir nenhuma biblioteca de
  datas nova) para garantir que `data_fim` continua visível/seleccionável
  como dia válido. Isto é só uma restrição de navegação no calendário —
  a validação real de intervalo continua inteiramente no backend
  (`ValidaIntervaloEvento`); se por algum motivo uma data inválida
  chegasse ao servidor, é o backend que rejeita.
- `events`: mapeados a partir de `anoLectivo.eventosCalendario`, com
  `backgroundColor`/`borderColor` por `tipo` (paleta baseada nas mesmas
  cores Bootstrap já usadas nos badges: Aula=`--bs-primary`,
  Avaliação=`--bs-danger`, Reunião=`--bs-info`, Férias=`--bs-warning`,
  Feriado=`--bs-dark`, Actividade=`--bs-success`, Evento=`--bs-secondary`,
  Outro=`--bs-gray-500`).
- `dateClick`: abre `EventoFormModal.vue` com `data_inicio`/`data_fim`
  pré-preenchidos com a data clicada (só chamado se a data estiver
  dentro do `validRange` — o FullCalendar já impede cliques fora dele).
- `eventClick`: abre `EventoFormModal.vue` em modo edição, pré-preenchido
  com os dados do evento clicado.

Estilo: usar o CSS por omissão do FullCalendar (não importar o bundle
jQuery/CSS do tema, que seria invasivo e colidiria com o resto da app —
já há uma colisão documentada entre Tailwind e Bootstrap `.collapse`,
não introduzir mais uma). Aplicar um pequeno ajuste CSS scoped para a
cor primária do FullCalendar (`--fc-button-bg-color` etc.) apontar para
`var(--bs-primary)`, mantendo coerência visual sem reescrever o tema
inteiro.

## O backend continua a única autoridade — o frontend não duplica regras

- **Regras de negócio**: autorização (`gerir-ano-letivo`), transições de
  estado válidas, validação de intervalo de datas, e a regra de "no
  máximo um Ano Lectivo activo" continuam a viver exclusivamente nas
  Actions/Policies do backend (já implementadas e testadas). O frontend
  **não reimplementa nenhuma destas regras** — só reage ao resultado
  (sucesso → toast + fecha modal; erro de validação → mostra a mensagem
  que o backend devolveu). O `Models/AnoLectivo.js` espelha os enums
  apenas para *apresentação* (labels, cores de badge) — não é lógica de
  negócio, é só tradução de um número para texto/cor.
- **Sem sistema de permissões novo no frontend**: não criar nenhuma
  verificação de permissão client-side (nem esconder botões com base em
  `auth.user`, nem replicar a matriz Módulo×Acção do backend em Vue). O
  mecanismo já existente — o próprio grupo de rotas protegido por
  `can:gerir-ano-letivo` — é suficiente: quem chega à página já tem
  permissão para todas as acções dela; um pedido sem permissão volta
  403 do backend, tratado como qualquer outro erro de `onError`. Isto
  segue o mesmo padrão (nenhuma outra página do projecto esconde botões
  por permissão) e mantém o backend como única autoridade.
- **Eventos do Calendário Escolar podem sobrepor-se** (já é uma decisão
  intencional do backend — só `Periodo` tem regra de não-sobreposição,
  `EventoCalendario` não). O frontend não introduz nenhuma validação de
  conflito de datas entre eventos; o FullCalendar mostra eventos
  sobrepostos lado a lado normalmente (comportamento por omissão da
  biblioteca), sem tratamento especial.

## Modelo JS (`Models/AnoLectivo.js`)

Mesmo formato de `Modules/Usuario/resources/js/Models/Usuario.js`
(`Object.freeze` + funções de label), espelhando os 3 enums do backend
num único ficheiro (o módulo é pequeno o suficiente para não precisar de
um ficheiro por enum):

```js
export const ESTADO_ANO_LECTIVO = Object.freeze({ PLANEADO: 0, ATIVO: 1, ENCERRADO: 2 });
export const TIPO_PERIODO = Object.freeze({ TRIMESTRE: 0, SEMESTRE: 1, OUTRO: 2 });
export const TIPO_EVENTO_CALENDARIO = Object.freeze({
    AULA: 0, AVALIACAO: 1, REUNIAO: 2, FERIAS: 3, FERIADO: 4, ACTIVIDADE: 5, EVENTO: 6, OUTRO: 7,
});

export const estadoAnoLectivoLabel = (estado) => { /* match análogo ao backend */ };
export const tipoPeriodoLabel = (tipo) => { /* ... */ };
export const tipoEventoCalendarioLabel = (tipo) => { /* ... */ };
export const tipoEventoCalendarioCor = (tipo) => { /* mapa tipo -> variável CSS Bootstrap, usado pelo AnoLectivoCalendario */ };
```

Os valores/labels devem coincidir exactamente com os enums PHP
(`Modules/AnoLectivo/app/Enums/{EstadoAnoLectivo,TipoPeriodo,TipoEventoCalendario}.php`) —
nenhuma lógica de negócio nova, só espelhar o que o backend já define.

## Validação e erros

Sem validação client-side nova (nem VeeValidate nem Zod — o projecto não
usa nenhuma). Cada modal mantém um `errors` ref local, populado
directamente do `onError` do Inertia (`{ campo: ['mensagem'] }` vindo do
Laravel), exibido por baixo do campo correspondente. `toast.error(...)`
com a primeira mensagem de erro (mesmo padrão de `DadosDaEscola.vue`).

## Menu

**Sidebar** (`resources/js/Components/Layout/SidebarMenuWrapper.vue`) —
novo bloco "Ano Lectivo" dentro da secção "Configurações", ao lado do
bloco "Estabelecimento" já existente, com o mesmo formato exacto
(`menu-item menu-accordion`, ícone `ki-duotone`, item único "Anos
Lectivos" → `/ano-lectivos`).

**Header** (`resources/js/Components/Layout/menus/AppsMenu.vue`) —
mesmo grupo replicado no dropdown "Configurações" do cabeçalho, seguindo
a duplicação já existente para Estabelecimento/Usuário/Permissões.

## Fora de escopo

- Paginação de servidor (não introduzida; seguir o padrão actual de
  enviar a lista completa).
- Corrigir a inconsistência de flash messages do backend (documentada,
  não é deste módulo resolver um problema transversal ao projecto).
- Exportação de calendário (iCal/PDF), impressão, ou vista de calendário
  partilhada entre múltiplos Anos Lectivos.
- Drag-and-drop de eventos no calendário (o FullCalendar suporta, mas
  não foi pedido; manter `editable: false` nesta fase — só criar/editar
  via modal).
- Alterar o design global do tema ou os componentes partilhados
  existentes além do necessário para os novos blocos de menu.

## Critério de sucesso

Um utilizador com a permissão `gerir-ano-letivo` consegue, a partir do
menu "Ano Lectivo": listar, criar, editar, activar/encerrar e eliminar
Anos Lectivos; dentro de um Ano Lectivo, gerir os seus Períodos; e
visualizar/criar/editar eventos do Calendário Escolar numa grelha de
calendário real, tudo consistente com o padrão visual e de interacção já
estabelecido no resto do MosiTec Escola (mesmos componentes partilhados,
mesmo estilo de tabela/badge/toast/modal, nenhuma biblioteca nova além do
FullCalendar já acordado).
