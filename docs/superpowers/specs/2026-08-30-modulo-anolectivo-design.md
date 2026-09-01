# Módulo AnoLectivo

## Contexto

O MosiTec Escola ainda não tem nenhum conceito de ano lectivo, período
académico ou calendário escolar. É um domínio próprio — tem regras de
negócio (um único ano activo, datas coerentes, não perder histórico) e
será referenciado por praticamente todos os módulos académicos futuros
(Turma, Matrícula, Frequência, Avaliação). Por isso não entra no `Core`
(que é só para conceitos transversais sem regra de domínio, ver
`docs/superpowers/specs/2026-08-30-modulo-core-design.md`) nem dentro de
outro módulo existente — é um módulo `Modules\AnoLectivo` novo.

Esta spec cobre **apenas a fase backend**: Models, migrations, Enums,
DTOs, Actions, Policies, Form Requests, Controllers, Rotas Inertia,
permissões, auditoria e testes. Frontend (páginas Vue) fica para uma
spec seguinte — o backend expõe `Inertia::render(...)` desde já, para
que a fase de frontend só precise de criar os `.vue`, sem tocar em
backend.

Fontes confirmadas por leitura de código (não inventadas): padrões de
`Modules/Usuario`, `Modules/Permissao`, `Modules/Estabelecimento`,
`Modules/Core`.

## Princípio arquitetural

```
AnoLectivo
├── Periodo            (belongsTo AnoLectivo)
└── EventoCalendario   (belongsTo AnoLectivo)
```

`AnoLectivo` é dono de `Periodo` e `EventoCalendario`. Nenhuma entidade
de outro domínio (Turma, Matrícula, Aluno, Professor, Avaliação,
Frequência, Horário, Pauta) entra neste módulo. Módulos futuros
referenciam `ano_lectivo_id`/`periodo_id` por FK, sem `AnoLectivo`
depender deles.

## Estrutura do módulo

Segue o padrão `Modules/Estabelecimento` (o mais recente e mais limpo —
sem `vite.config.js`/`resources/assets` legado do scaffold nwidart):

```
Modules/AnoLectivo/
  app/
    Actions/
      CriarAnoLectivoAction.php
      AtualizarAnoLectivoAction.php
      AlterarEstadoAnoLectivoAction.php
      EliminarAnoLectivoAction.php
      CriarPeriodoAction.php
      AtualizarPeriodoAction.php
      EliminarPeriodoAction.php
      CriarEventoCalendarioAction.php
      AtualizarEventoCalendarioAction.php
      EliminarEventoCalendarioAction.php
    DTO/
      AnoLectivoDTO.php
      PeriodoDTO.php
      EventoCalendarioDTO.php
    Enums/
      EstadoAnoLectivo.php
      TipoPeriodo.php
      TipoEventoCalendario.php
    Http/
      Controllers/
        AnoLectivoController.php
        PeriodoController.php
        EventoCalendarioController.php
      Requests/
        CriarAnoLectivoRequest.php
        AtualizarAnoLectivoRequest.php
        CriarPeriodoRequest.php
        AtualizarPeriodoRequest.php
        CriarEventoCalendarioRequest.php
        AtualizarEventoCalendarioRequest.php
    Models/
      AnoLectivo.php
      Periodo.php
      EventoCalendario.php
    Policies/
      AnoLectivoPolicy.php
      PeriodoPolicy.php
      EventoCalendarioPolicy.php
    Providers/
      AnoLectivoServiceProvider.php
      EventServiceProvider.php
      RouteServiceProvider.php
    Services/
      GestaoAnoLectivoService.php      (agrega Actions p/ os Controllers)
      AnoLectivoConsultaService.php    (leitura/listagem)
  config/config.php
  database/
    migrations/
      YYYY_MM_DD_HHMMSS_create_ano_lectivos_table.php
      YYYY_MM_DD_HHMMSS_create_periodos_table.php
      YYYY_MM_DD_HHMMSS_create_eventos_calendario_table.php
  routes/
    web.php
  tests/
    Feature/
  composer.json, module.json, package.json
```

Namespaces seguem exactamente a convenção existente:
`Modules\AnoLectivo\Models\AnoLectivo`,
`Modules\AnoLectivo\Actions\CriarAnoLectivoAction`, etc.

## Models e migrations

### `ano_lectivos` / `Modules\AnoLectivo\Models\AnoLectivo`

```
id
estabelecimento_id  FK -> estabelecimentos, nullable, onDelete('set null')
nome                string                          -- ex: "2026/2027", só legível, não é identificador lógico
data_inicio         date
data_fim             date
estado               unsignedTinyInteger             -- EstadoAnoLectivo: PLANEADO=0 (default) | ATIVO=1 | ENCERRADO=2
estado_descricao     string
criado_por           FK -> users, nullable, onDelete('set null')
editado_por          FK -> users, nullable, onDelete('set null')
deleted_at, timestamps
unique(['estabelecimento_id', 'nome'])
```

**Conceito de negócio**: todo `AnoLectivo` pertence a um `Estabelecimento`
— a relação é conceptualmente obrigatória. `estabelecimento_id` é
`nullable` apenas por **compatibilidade temporária** com o estado actual
single-tenant do sistema (não há hoje nenhum mecanismo que garanta um
`Estabelecimento::current()` sempre presente). A nulabilidade não é uma
decisão de modelagem definitiva — quando a tenancy real for implementada,
esta coluna deve tornar-se `NOT NULL`. Até lá, `CriarAnoLectivoAction`
preenche-a sempre que houver um estabelecimento activo (ver secção
Tenancy).

`SoftDeletes` (trait) — decisão confirmada: histórico académico nunca é
descartável (só `Estabelecimento` usa soft delete hoje; `AnoLectivo`
passa a ser o segundo caso, pela mesma razão).

`EstadoAnoLectivo` segue exactamente o padrão de Enums já estabelecido
no projecto (`int`-backed, `label()` via `match`, ver secção Enums) — não
é uma mecânica especial criada para este módulo. A única particularidade
é que, por ter valores próprios do domínio (`PLANEADO`/`ATIVO`/
`ENCERRADO`, não `Ativo`/`Inativo`), não pode reaproveitar o trait
`Core\Traits\SincronizaEstadoDescricao` (esse está fixo ao enum
`Core\Enums\Estado`). Para este caso, o projecto já tem um segundo idioma
igualmente estabelecido — um `booted()` manual no Model, usado hoje por
`Estabelecimento::tipo_descricao` e por `User::tipo_login_descricao` —
e é esse idioma existente (não um terceiro, novo) que `AnoLectivo`
replica para `estado_descricao`:

```php
protected static function booted(): void
{
    static::saving(function (AnoLectivo $anoLectivo) {
        $anoLectivo->estado_descricao = $anoLectivo->estado instanceof EstadoAnoLectivo
            ? $anoLectivo->estado->label()
            : EstadoAnoLectivo::from((int) $anoLectivo->estado)->label();
    });
}
```

Relações:

```php
public function estabelecimento(): BelongsTo { return $this->belongsTo(Estabelecimento::class); }
public function periodos(): HasMany { return $this->hasMany(Periodo::class); }
public function eventosCalendario(): HasMany { return $this->hasMany(EventoCalendario::class); }
public function criadoPor(): BelongsTo { return $this->belongsTo(User::class, 'criado_por'); }
public function editadoPor(): BelongsTo { return $this->belongsTo(User::class, 'editado_por'); }

public static function current(?int $estabelecimentoId = null): ?self
{
    return static::where('estado', EstadoAnoLectivo::ATIVO)
        ->when($estabelecimentoId, fn ($q) => $q->where('estabelecimento_id', $estabelecimentoId))
        ->first();
}
```

`current()` espelha `Estabelecimento::current()` — mesmo idioma do
projecto para "o registo activo agora".

### `periodos` / `Modules\AnoLectivo\Models\Periodo`

```
id
ano_lectivo_id  FK -> ano_lectivos, onDelete('cascade')
nome            string          -- ex: "1.º Trimestre"
tipo            unsignedTinyInteger  -- TipoPeriodo: TRIMESTRE=0 | SEMESTRE=1 | OUTRO=2
tipo_descricao  string
numero          unsignedTinyInteger, nullable   -- ordenação
data_inicio     date
data_fim        date
estado          unsignedTinyInteger, default 1  -- reaproveita Core\Enums\Estado (ativo/inativo)
estado_descricao string, default 'Ativo'
criado_por, editado_por  FK -> users, nullable, onDelete('set null')
timestamps
unique(['ano_lectivo_id', 'numero'])
```

Sem soft delete (sem requisito de histórico próprio e sem dependentes
noutros módulos ainda — reavaliar quando `Avaliacao.periodo_id`
existir). Usa o trait existente `Core\Traits\SincronizaEstadoDescricao`
para `estado`/`estado_descricao` (é literalmente o par ativo/inativo
genérico que o trait já resolve, sem reinventar nada), e o `tipo` tem o
seu próprio `booted()` manual, igual ao de `AnoLectivo`.

```php
public function anoLectivo(): BelongsTo { return $this->belongsTo(AnoLectivo::class); }
```

### `eventos_calendario` / `Modules\AnoLectivo\Models\EventoCalendario`

```
id
ano_lectivo_id  FK -> ano_lectivos, onDelete('cascade')
titulo          string
descricao       text, nullable
tipo            unsignedTinyInteger  -- TipoEventoCalendario
tipo_descricao  string
data_inicio     date
data_fim        date
dia_inteiro     boolean, default true
estado          unsignedTinyInteger, default 1  -- reaproveita Core\Enums\Estado
estado_descricao string, default 'Ativo'
criado_por, editado_por  FK -> users, nullable, onDelete('set null')
timestamps
```

Sem soft delete (eventos de calendário são recriáveis, sem requisito de
histórico). `estado`/`estado_descricao` via `SincronizaEstadoDescricao`,
`tipo`/`tipo_descricao` via `booted()` manual.

```php
public function anoLectivo(): BelongsTo { return $this->belongsTo(AnoLectivo::class); }
```

## Enums (`Modules\AnoLectivo\Enums`)

Todos `int`-backed com `label()`, seguindo exactamente o padrão de
`Core\Enums\Estado` / `Estabelecimento\Enums\TipoEstabelecimentoEnum`:

```php
enum EstadoAnoLectivo: int
{
    case PLANEADO = 0;
    case ATIVO = 1;
    case ENCERRADO = 2;

    public function label(): string
    {
        return match ($this) {
            self::PLANEADO => 'Planeado',
            self::ATIVO => 'Activo',
            self::ENCERRADO => 'Encerrado',
        };
    }
}

enum TipoPeriodo: int
{
    case TRIMESTRE = 0;
    case SEMESTRE = 1;
    case OUTRO = 2;

    public function label(): string { /* match análogo */ }
}

enum TipoEventoCalendario: int
{
    case AULA = 0;
    case AVALIACAO = 1;
    case REUNIAO = 2;
    case FERIAS = 3;
    case FERIADO = 4;
    case ACTIVIDADE = 5;
    case EVENTO = 6;
    case OUTRO = 7;

    public function label(): string { /* match análogo */ }
}
```

`TipoPeriodo` existe precisamente para não prender a arquitectura a
"3 trimestres fixos": hoje só `TRIMESTRE` é usado na prática, mas o
enum já permite `SEMESTRE`/`OUTRO` sem alterar schema ou Model.

## DTOs (`Modules\AnoLectivo\DTO`)

Mesmo padrão de `UsuarioDTO`/`EstabelecimentoDTO`: propriedades públicas
não-`readonly`, construtor estático `fromRequest()`, enums convertidos
dentro do DTO (nunca no Controller).

```php
class AnoLectivoDTO
{
    public function __construct(
        public string $nome,
        public string $dataInicio,
        public string $dataFim,
        public EstadoAnoLectivo $estado = EstadoAnoLectivo::PLANEADO,
        public ?int $estabelecimentoId = null,
    ) {}

    public static function fromRequest(FormRequest $request): self { /* ... */ }
}
```

`PeriodoDTO` e `EventoCalendarioDTO` seguem a mesma forma, com os
respectivos campos.

## Regras de negócio

### No máximo um Ano Lectivo activo por estabelecimento

A regra é, precisamente: **no máximo um `AnoLectivo` com `estado = ATIVO`
por `estabelecimento_id`** — nunca uma unicidade global. Isto já prepara
correctamente o modelo para múltiplas escolas no futuro: quando existir
mais de um `Estabelecimento`, cada um pode ter o seu próprio ano activo
em simultâneo, sem conflito entre si.

Aplicada na camada de Actions (não no frontend, não só numa constraint
solta), em `CriarAnoLectivoAction`, `AtualizarAnoLectivoAction` e
`AlterarEstadoAnoLectivoAction`, sempre que o `estado` alvo é `ATIVO`. A
verificação — incluindo o `lockForUpdate()` — é sempre escopada por
`estabelecimento_id`, nunca feita sobre a tabela inteira:

```php
DB::transaction(function () use (...) {
    $existeOutroAtivo = AnoLectivo::where('estado', EstadoAnoLectivo::ATIVO->value)
        ->where('estabelecimento_id', $estabelecimentoId)
        ->when($idAtual, fn ($q) => $q->where('id', '!=', $idAtual))
        ->lockForUpdate()
        ->exists();

    if ($existeOutroAtivo) {
        throw ValidationException::withMessages([
            'estado' => 'Já existe um Ano Lectivo activo para este estabelecimento.',
        ]);
    }

    // criar/actualizar dentro da mesma transacção
});
```

`lockForUpdate()` dentro da transacção evita a condição de corrida de
duas requisições a activarem anos lectivos diferentes em simultâneo
(mesmo mecanismo pedido no requisito — "validação contra condições de
concorrência"). Não é criada nenhuma constraint de unicidade parcial ao
nível da base de dados (ex.: coluna computada + índice único) — seria
uma técnica sem precedente no projecto para uma operação administrativa
de baixa frequência. Fica registado como possível reforço futuro, não
como parte desta implementação.

### Não eliminar histórico académico

`EliminarAnoLectivoAction`:

1. `AnoLectivo` usa `SoftDeletes` — um "eliminar" nunca apaga a linha,
   apenas marca `deleted_at`.
2. Antes disso, a Action bloqueia com `ValidationException` se existirem
   `Periodos` ou `EventosCalendario` associados (mesmo padrão de
   verificação de dependentes já usado em `EliminarUsuarioAction`):

```php
public function executar(AnoLectivo $anoLectivo): void
{
    if ($anoLectivo->periodos()->exists() || $anoLectivo->eventosCalendario()->exists()) {
        throw ValidationException::withMessages([
            'ano_lectivo' => 'Este Ano Lectivo tem períodos ou eventos associados e não pode ser eliminado.',
        ]);
    }

    $anoLectivo->delete();
}
```

Anos lectivos `ENCERRADO` continuam a existir na tabela indefinidamente
— nenhuma rotina os remove.

### Períodos

Validado na Action (a forma/tipos ficam no Form Request; a regra de
negócio, que precisa de consultar a BD, fica na Action — mesmo critério
de `EliminarUsuarioAction`):

- `ano_lectivo_id` tem de existir (FK + `exists:ano_lectivos,id` no Request).
- `data_inicio <= data_fim`.
- Dentro do intervalo do `AnoLectivo`: `data_inicio >= anoLectivo.data_inicio` e `data_fim <= anoLectivo.data_fim`.
- Sem sobreposição com outros períodos do mesmo ano lectivo:

```php
$sobrepoe = Periodo::where('ano_lectivo_id', $anoLectivoId)
    ->when($idAtual, fn ($q) => $q->where('id', '!=', $idAtual))
    ->where('data_inicio', '<=', $dataFim)
    ->where('data_fim', '>=', $dataInicio)
    ->exists();
```

- `numero`, quando informado, é único por `ano_lectivo_id` (garantido
  também pela constraint de BD).

`EliminarPeriodoAction`: eliminação física (sem soft delete) — nesta
fase não há dependentes de outros módulos; quando `Avaliacao.periodo_id`
existir, esta Action terá de ganhar a mesma verificação de dependentes
que `AnoLectivo`/`Usuario` já usam.

### Eventos de calendário

- `ano_lectivo_id` tem de existir.
- `titulo` obrigatório, `tipo` válido (enum).
- `data_inicio <= data_fim`.
- Datas dentro do intervalo do `AnoLectivo` (mesma regra de `Periodo`).

`EliminarEventoCalendarioAction`: eliminação física, sem restrição
adicional (eventos não têm requisito de histórico).

### Separação Período vs EventoCalendario

Mantida à risca: `Periodo` é uma divisão estrutural do ano lectivo
(1.º/2.º/3.º Trimestre); `EventoCalendario` é um acontecimento pontual
(início das aulas, reunião, avaliação, féria, feriado, actividade). Não
existe nenhuma FK entre `Periodo` e `EventoCalendario` — ambos só se
relacionam através de `AnoLectivo`.

## Validação (Form Requests)

Estende `App\Http\Requests\BaseRequest` (igual a todos os módulos),
`authorize()` delega para a gate `gerir-ano-letivo`, `messages()` em
português. Exemplo:

```php
class CriarAnoLectivoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gerir-ano-letivo') ?? false;
    }

    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'data_inicio' => ['required', 'date'],
            'data_fim' => ['required', 'date', 'after_or_equal:data_inicio'],
            'estado' => ['sometimes', new Enum(EstadoAnoLectivo::class)],
        ];
    }

    public function messages(): array { /* mensagens em PT */ }
}
```

`CriarPeriodoRequest`/`CriarEventoCalendarioRequest` validam forma e
tipos (`ano_lectivo_id` existe, datas são datas, `tipo` é um valor do
enum); as regras que precisam de consultar outros registos (sobreposição,
intervalo do ano lectivo) ficam na Action, como descrito acima.

## Permissões

Segue exactamente o único mecanismo de autorização hoje em vigor no
MosiTec (gate grosseira por perfil, ver `app/Providers/AppServiceProvider.php`):

```php
Gate::define('gerir-ano-letivo', fn (User $user) =>
    $user->roles->contains('nome', Perfil::ADMIN_ESCOLA->value));
```

Uma única gate cobre `AnoLectivo`, `Periodo` e `EventoCalendario` — os
três pertencem ao mesmo ecrã/domínio, tal como `gerir-estabelecimento`
cobre tudo dentro de Estabelecimento hoje.

`AnoLectivoPolicy`, `PeriodoPolicy`, `EventoCalendarioPolicy` (Laravel
descobre-as automaticamente por convenção `Models\X` → `Policies\XPolicy`,
sem registo manual, igual a `EstabelecimentoPolicy`/`UserPolicy`):

```php
class AnoLectivoPolicy
{
    public function view(User $user): bool { return $user->can('gerir-ano-letivo'); }
    public function create(User $user): bool { return $user->can('gerir-ano-letivo'); }
    public function update(User $user): bool { return $user->can('gerir-ano-letivo'); }
    public function delete(User $user): bool { return $user->can('gerir-ano-letivo'); }
}
```

Deixa espaço para regras por instância mais tarde (ex.: impedir editar
um ano `ENCERRADO`) sem tocar na gate.

**Integração com o mecanismo `Módulo → Acção → Permissão` já existente**
(`Modules\Permissao`): o catálogo (`Modulo` × `Acao`) já tem a entrada
`['nome' => 2, 'descricao' => 'Ano Letivo']` reservada em `ModuloSeeder`,
e as `Acao`s (`ver`, `criar`, `editar`, `eliminar`, `listar`, `exportar`)
já são globais e cobrem qualquer módulo novo sem alteração. Assim que
esse seeder correr, "Ano Letivo" aparece automaticamente na grelha de
Perfis/Permissões (`RolePermissao`/`UserPermissao`), exactamente como
"Usuário" ou "Estabelecimento" hoje — nada de novo a criar nesse
catálogo, e nenhuma tabela/Model paralela é introduzida.

A gate `gerir-ano-letivo` **é** o mecanismo de autorização deste módulo,
não um sistema alternativo: é o mesmo tipo de gate grosseira por perfil
que já autoriza `Usuario` e `Estabelecimento`, e o único ponto onde a
autorização é de facto avaliada hoje no MosiTec. O sistema fino
Módulo×Acção alimenta a UI de Permissões (para que o utilizador veja e
configure "Ano Letivo" como qualquer outro módulo), mas **não é
consultado** por nenhuma Gate/Policy real em nenhum módulo existente —
`AnoLectivo` replica esse mesmo estado, sem inventar uma segunda forma
de autorizar nem ligar a gate a `RolePermissao`/`UserPermissao` (isso
seria trabalho de infraestrutura novo, fora do escopo desta spec).

## Auditoria — `RegistaAutoria`

`criado_por`/`editado_por` existem como colunas em todo o projecto mas
nunca são preenchidos por nenhum código actual (confirmado por leitura
exaustiva). Para `AnoLectivo`, `Periodo` e `EventoCalendario`,
introduz-se um trait novo e genérico no Core (reutilizável por outros
módulos no futuro, mas usado só aqui por agora):

```php
// Modules\Core\Traits\RegistaAutoria
trait RegistaAutoria
{
    protected static function bootRegistaAutoria(): void
    {
        static::creating(function ($model) {
            $model->criado_por = $model->criado_por ?? auth()->id();
            $model->editado_por = $model->editado_por ?? auth()->id();
        });

        static::updating(function ($model) {
            $model->editado_por = auth()->id();
        });
    }
}
```

Usado nos três Models do `AnoLectivo`. Não se aplica retroactivamente a
`Usuario`/`Permissao`/`Estabelecimento` — fora de escopo desta spec.

## Tenancy / Estabelecimento

O projecto é **single-tenant hoje** (confirmado: nenhuma tabela tem
`tenant_id`/`escola_id`, nenhum `GlobalScope`, nenhum middleware de
isolamento — `Estabelecimento::current()` é literalmente um comentário
no código a dizer que é só "single-active-record", não tenancy real).

`ano_lectivos.estabelecimento_id` é uma FK **nullable**, sem nenhum
`GlobalScope` automático, populada por `CriarAnoLectivoAction` a partir
de `Estabelecimento::current()?->id`. `Periodo` e `EventoCalendario` não
duplicam esta FK — herdam o estabelecimento através de `ano_lectivo_id`.

Isto prepara o terreno (a regra "um único activo" e a unicidade de
`nome` já são escopadas por `estabelecimento_id`) sem inventar um
mecanismo de tenancy que não existe em mais lado nenhum do sistema.
**Consequência directa para os testes**: o cenário "isolamento entre
tenants" pedido no requisito de testes **não é testável nesta fase** —
não há mais de um tenant possível no sistema hoje (é sempre um único
`Estabelecimento` "activo"). O teste correspondente fica documentado
como pendente, com uma nota explícita a apontar para quando a tenancy
real for implementada, em vez de simular um cenário multi-tenant
artificial que não existe no resto da aplicação.

## Rotas e Controllers

Só `routes/web.php` — Inertia é o canal principal em todos os módulos
existentes (API JSON é um canal secundário que só existe em `Usuario`).
`index`/`show` já usam `Inertia::render('AnoLectivo/...', ...)` nesta
fase backend — a resposta é testável via `assertInertia()` sem que a
página `.vue` exista; a fase de frontend só terá de criar os
componentes, sem alterar o backend.

```php
Route::middleware(['auth', 'can:gerir-ano-letivo'])
    ->prefix('ano-lectivos')
    ->name('ano-lectivos.')
    ->group(function () {
        Route::get('/', [AnoLectivoController::class, 'index'])->name('index');
        Route::post('/', [AnoLectivoController::class, 'store'])->name('store');
        Route::get('/{anoLectivo}', [AnoLectivoController::class, 'show'])->name('show');
        Route::put('/{anoLectivo}', [AnoLectivoController::class, 'update'])->name('update');
        Route::patch('/{anoLectivo}/estado', [AnoLectivoController::class, 'alterarEstado'])->name('alterar-estado');
        Route::delete('/{anoLectivo}', [AnoLectivoController::class, 'destroy'])->name('destroy');

        Route::post('/{anoLectivo}/periodos', [PeriodoController::class, 'store'])->name('periodos.store');
        Route::post('/{anoLectivo}/eventos-calendario', [EventoCalendarioController::class, 'store'])->name('eventos.store');
    });

Route::middleware(['auth', 'can:gerir-ano-letivo'])->group(function () {
    Route::put('/periodos/{periodo}', [PeriodoController::class, 'update'])->name('periodos.update');
    Route::delete('/periodos/{periodo}', [PeriodoController::class, 'destroy'])->name('periodos.destroy');
    Route::put('/eventos-calendario/{evento}', [EventoCalendarioController::class, 'update'])->name('eventos.update');
    Route::delete('/eventos-calendario/{evento}', [EventoCalendarioController::class, 'destroy'])->name('eventos.destroy');
});
```

Listagem de períodos/eventos vem embutida nas props do `show` de
`AnoLectivoController` (como `Estabelecimento`/`Usuario` já fazem para
dados relacionados), sem endpoints de listagem extra não pedidos.

Controllers injectam `GestaoAnoLectivoService`/`AnoLectivoConsultaService`
no construtor (nunca Actions directamente), com o fluxo padrão:
`$this->authorize(...)` → `$request->validated()` →
`$this->service->metodo(...)` → `redirect()->back()->with('success', '...')`
para escrita, `Inertia::render(...)` para leitura.

## Testes

PHPUnit (não Pest — confirmado que é o único framework realmente em
uso), `RefreshDatabase`, `tests/Feature/`, helper `actingAsStaff()`
(utilizador com role `ADMIN_ESCOLA`), tal como os testes de
`Usuario`/`Estabelecimento`. Cobre os 10 cenários pedidos:

1. **Criar Ano Lectivo** — `POST /ano-lectivos` com dados válidos cria o registo, devolve sucesso e regista `criado_por`/`editado_por` (`RegistaAutoria`) com o `id` do utilizador autenticado.
2. **Bloquear dois Anos Lectivos activos** — criar/activar um segundo ano com `estado=ATIVO` enquanto outro já está `ATIVO` no mesmo estabelecimento devolve erro de validação e não altera a BD.
3. **Manter Anos Lectivos antigos** — encerrar um ano lectivo não o remove; continua consultável e listado; eliminar um ano com Períodos/Eventos associados é bloqueado.
4. **Criar Período** — `POST /ano-lectivos/{id}/periodos` com datas dentro do intervalo cria o registo.
5. **Período fora do intervalo do Ano Lectivo** — datas fora do intervalo do `AnoLectivo` são rejeitadas.
6. **Períodos sobrepostos** — um segundo período com datas sobrepostas ao primeiro é rejeitado.
7. **Criar Evento de Calendário** — `POST /ano-lectivos/{id}/eventos-calendario` com dados válidos cria o registo.
8. **Evento fora do intervalo do Ano Lectivo** — datas fora do intervalo do `AnoLectivo` são rejeitadas.
9. **Autorização** — um utilizador sem a permissão `gerir-ano-letivo` recebe 403 em todas as rotas de escrita e leitura do módulo.
10. **Isolamento entre tenants** — **não implementado nesta fase**: documentado como teste pendente (`@todo` explícito no ficheiro de testes, sem `markTestSkipped` silencioso), a activar quando a tenancy real existir no projecto; não é simulada tenancy artificial.

`RegistaAutoria` fica disponível como parte desta mesma implementação
(não é um trait futuro) — por isso é validado pelo comportamento real do
módulo, via Feature tests, em vez de um teste isolado tipo Capsule:
o cenário 1 confirma `criado_por` na criação, e o teste de actualização
de `AnoLectivo` confirma que `editado_por` passa a reflectir o
utilizador autenticado que fez o `PUT` (autenticado com um utilizador
diferente do que criou o registo, para o assert ser inequívoco). Não
fica nenhum `@todo` para este ponto.

## Fora de escopo

Frontend (páginas Vue/Inertia) — spec seguinte. Módulos `Trimestre`
ou `Calendario` como módulos independentes. Qualquer entidade de outro
domínio (Turma, Matrícula, Aluno, Professor, Avaliação, Frequência,
Horário, Pauta). Ligar a autorização ao sistema fino
Módulo×Acção (`RolePermissao`/`UserPermissao`) — fica como está hoje.
Constraint de unicidade de "único activo" ao nível da base de dados
(coluna computada). Retrofit de `RegistaAutoria` nos módulos existentes.
Seeders de dados fictícios (podem ser adicionados depois, só se
pedido).

## Critério de sucesso

Módulo `Modules/AnoLectivo` completo no backend, com Models, migrations,
Enums, DTOs, Actions, Policies, Requests, Controllers, Rotas e
permissões integradas seguindo os padrões já existentes (sem
arquitectura paralela). As 9 regras de negócio testáveis passam; o
cenário de isolamento entre tenants fica documentado como pendente.
`Periodo` e `EventoCalendario` nunca existem sem `AnoLectivo` (FK
`cascade`), e nenhum `AnoLectivo` com dependentes é eliminável.
