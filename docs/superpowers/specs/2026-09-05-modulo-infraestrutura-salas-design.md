# Módulo Infraestrutura → Salas

## Contexto

O MosiTec Escola ainda não tem nenhum conceito de espaço físico. `Sala`
é a primeira entidade de um domínio maior — **Infraestrutura** — que no
futuro também vai cobrir Equipamentos, Inventário e Manutenção:

```
Infraestrutura
├── Salas          (esta spec)
├── Equipamentos   (futuro)
├── Inventário     (futuro)
└── Manutenção     (futuro)
```

Por decisão explícita, todas estas entidades futuras vão partilhar a
**mesma permissão de módulo** (`infraestrutura.*`), não uma por
entidade — ver secção Permissões. Por isso o módulo Laravel chama-se
`Modules\Infraestrutura` desde já (não `Modules\Sala`), mesmo só tendo
`Sala` implementada nesta fase, tal como `Modules\AnoLectivo` já aloja
`AnoLectivo`+`Periodo`+`EventoCalendario` sob um único módulo/gate.

Esta spec cobre **apenas a fase backend**: Model, migration, Enums,
DTO, Actions, Form Requests, Controller, rotas, permissões e testes.
Frontend (páginas Vue) fica para uma spec seguinte, tal como no
`AnoLectivo`.

Fontes confirmadas por leitura de código (não inventadas):
`Modules/AnoLectivo` (padrão mais recente e completo), `Modules/Estabelecimento`,
`Modules/Core` (`Horario`, `Estado`, `RegistaAutoria`, `SincronizaEstadoDescricao`),
`Modules/Permissao` (`Gate::before`, `PermissionResolver`, seeders).

## Princípio arquitetural

```
Infraestrutura
└── Sala   (belongsTo Estabelecimento)
```

Nenhuma entidade de outro domínio (Turma, Horário, Matrícula, Aluno,
Professor) entra neste módulo. Módulos futuros referenciam `sala_id`
por FK, sem `Sala` depender deles — ver decisões 6 e 7 abaixo.

## Estrutura do módulo

Segue exactamente o padrão `Modules/AnoLectivo`:

```
Modules/Infraestrutura/
  app/
    Actions/
      CriarSalaAction.php
      AtualizarSalaAction.php
      AlterarEstadoSalaAction.php
      EliminarSalaAction.php
    DTO/
      SalaDTO.php
    Enums/
      TipoSala.php
      EstadoSala.php
    Http/
      Controllers/
        SalaController.php
      Requests/
        CriarSalaRequest.php
        AtualizarSalaRequest.php
        AlterarEstadoSalaRequest.php
    Models/
      Sala.php
    Providers/
      InfraestruturaServiceProvider.php
      EventServiceProvider.php
      RouteServiceProvider.php
    Services/
      GestaoSalaService.php       (agrega Actions p/ o Controller)
      SalaConsultaService.php     (leitura/listagem)
  config/config.php
  database/
    migrations/
      2026_09_05_100000_create_salas_table.php
  routes/
    web.php
  tests/
    Feature/
  composer.json, module.json
```

Serviços e Controller são nomeados por **entidade** (`GestaoSalaService`,
`SalaController`), não pelo módulo (`GestaoInfraestruturaService`) —
para que `Equipamento`/`Inventario`/`Manutencao` ganhem depois os seus
próprios `GestaoEquipamentoService`, `EquipamentoController`, etc.,
dentro do mesmo módulo, sem colidir nem obrigar a repensar nomes.

Namespaces: `Modules\Infraestrutura\Models\Sala`,
`Modules\Infraestrutura\Actions\CriarSalaAction`, etc.

## Model e migration

### `salas` / `Modules\Infraestrutura\Models\Sala`

```
id
estabelecimento_id  FK -> estabelecimentos, nullable, nullOnDelete
codigo              string(20)
nome                string(255)
tipo                unsignedTinyInteger             -- TipoSala
tipo_descricao      string
capacidade          unsignedSmallInteger, nullable
localizacao         string(255), nullable
observacoes         text, nullable
estado              unsignedTinyInteger, default 0  -- EstadoSala: ATIVA=0 (default) | MANUTENCAO=1 | INATIVA=2
estado_descricao    string, default 'Ativa'
criado_por          FK -> users, nullable, nullOnDelete
editado_por         FK -> users, nullable, nullOnDelete
deleted_at, timestamps
unique(['estabelecimento_id', 'codigo'])
```

```php
Schema::create('salas', function (Blueprint $table) {
    $table->id();
    $table->foreignId('estabelecimento_id')->nullable()->constrained('estabelecimentos')->nullOnDelete();
    $table->string('codigo', 20);
    $table->string('nome');
    $table->unsignedTinyInteger('tipo'); // 0 sala_aula | 1 laboratorio | 2 biblioteca | 3 auditorio | 4 ginasio | 5 sala_professores | 6 gabinete_administrativo | 7 outro
    $table->string('tipo_descricao');
    $table->unsignedSmallInteger('capacidade')->nullable();
    $table->string('localizacao')->nullable();
    $table->text('observacoes')->nullable();
    $table->unsignedTinyInteger('estado')->default(0); // 0 ativa | 1 manutencao | 2 inativa
    $table->string('estado_descricao')->default('Ativa');
    $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
    $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();
    $table->softDeletes();

    $table->unique(['estabelecimento_id', 'codigo']);
});
```

**`estabelecimento_id`**: conceptualmente obrigatória, mas `nullable`
a nível de BD pela mesma razão documentada em
`docs/superpowers/specs/2026-08-30-modulo-anolectivo-design.md` —
compatibilidade com o estado single-tenant actual do sistema (não há
hoje garantia de um `Estabelecimento::current()` sempre presente). É
sempre preenchida pela Action a partir de `Estabelecimento::current()`
(ver secção Tenancy).

`SoftDeletes`: um espaço físico é referenciável por Turmas/Horários
futuros — eliminar não pode apagar a linha, só marcar `deleted_at`,
tal como `Estabelecimento` e `AnoLectivo`.

`tipo`/`estado` são enums de domínio próprios (não o par genérico
Ativo/Inativo de `Core\Enums\Estado`), por isso seguem o mesmo idioma
de `booted()` manual já usado por `AnoLectivo::estado_descricao` —
não o trait `SincronizaEstadoDescricao` (esse está fixo a
`Core\Enums\Estado`):

```php
protected static function booted(): void
{
    static::saving(function (Sala $sala) {
        $sala->tipo_descricao = $sala->tipo instanceof TipoSala
            ? $sala->tipo->label()
            : TipoSala::from((int) $sala->tipo)->label();

        $sala->estado_descricao = $sala->estado instanceof EstadoSala
            ? $sala->estado->label()
            : EstadoSala::from((int) $sala->estado)->label();
    });
}
```

Model completo:

```php
class Sala extends Model
{
    use HasFactory;
    use SoftDeletes;
    use RegistaAutoria;

    protected $table = 'salas';

    protected $fillable = [
        'estabelecimento_id',
        'codigo',
        'nome',
        'tipo',
        'capacidade',
        'localizacao',
        'observacoes',
        'estado',
        'criado_por',
        'editado_por',
    ];

    protected $casts = [
        'tipo' => TipoSala::class,
        'estado' => EstadoSala::class,
        'capacidade' => 'integer',
    ];

    public function estabelecimento(): BelongsTo { return $this->belongsTo(Estabelecimento::class); }
    public function criadoPor(): BelongsTo { return $this->belongsTo(User::class, 'criado_por'); }
    public function editadoPor(): BelongsTo { return $this->belongsTo(User::class, 'editado_por'); }
}
```

`RegistaAutoria` é o trait já existente em `Modules\Core\Traits`
(criado para o `AnoLectivo`) — reutilizado tal e qual, nenhuma cópia
nova.

Sem `turmas()`/`horarios()` nesta fase — ver decisões 6 e 7.

## Enums (`Modules\Infraestrutura\Enums`)

`int`-backed com `label()`, mesmo padrão de
`Estabelecimento\Enums\TipoEstabelecimentoEnum` / `AnoLectivo\Enums\EstadoAnoLectivo`:

```php
enum TipoSala: int
{
    case SALA_AULA = 0;
    case LABORATORIO = 1;
    case BIBLIOTECA = 2;
    case AUDITORIO = 3;
    case GINASIO = 4;
    case SALA_PROFESSORES = 5;
    case GABINETE_ADMINISTRATIVO = 6;
    case OUTRO = 7;

    public function label(): string
    {
        return match ($this) {
            self::SALA_AULA => 'Sala de Aula',
            self::LABORATORIO => 'Laboratório',
            self::BIBLIOTECA => 'Biblioteca',
            self::AUDITORIO => 'Auditório',
            self::GINASIO => 'Ginásio',
            self::SALA_PROFESSORES => 'Sala de Professores',
            self::GABINETE_ADMINISTRATIVO => 'Gabinete Administrativo',
            self::OUTRO => 'Outro',
        };
    }
}

enum EstadoSala: int
{
    case ATIVA = 0;
    case MANUTENCAO = 1;
    case INATIVA = 2;

    public function label(): string
    {
        return match ($this) {
            self::ATIVA => 'Ativa',
            self::MANUTENCAO => 'Em Manutenção',
            self::INATIVA => 'Inativa',
        };
    }
}
```

Lista de `TipoSala` enxuta e extensível (adicionar um `case` novo não
altera schema nem Model) — sem subtipos de laboratório
(Física/Química/Informática) nesta fase, como já discutido e aprovado.

## DTO (`Modules\Infraestrutura\DTO\SalaDTO`)

Mesmo padrão de `AnoLectivoDTO`: propriedades públicas, construtor
estático `fromRequest()`, enums convertidos dentro do DTO.

```php
class SalaDTO
{
    public function __construct(
        public string $codigo,
        public string $nome,
        public TipoSala $tipo,
        public ?int $capacidade = null,
        public ?string $localizacao = null,
        public ?string $observacoes = null,
        public EstadoSala $estado = EstadoSala::ATIVA,
    ) {}

    public static function fromRequest(FormRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            codigo: $dados['codigo'],
            nome: $dados['nome'],
            tipo: TipoSala::from((int) $dados['tipo']),
            capacidade: isset($dados['capacidade']) ? (int) $dados['capacidade'] : null,
            localizacao: $dados['localizacao'] ?? null,
            observacoes: $dados['observacoes'] ?? null,
            estado: isset($dados['estado']) ? EstadoSala::from((int) $dados['estado']) : EstadoSala::ATIVA,
        );
    }
}
```

## Regras de negócio

### Unicidade de `codigo` por estabelecimento

**No máximo uma Sala com o mesmo `codigo` por `estabelecimento_id`**
— nunca unicidade global (mesmo raciocínio do `nome` único por
estabelecimento em `AnoLectivo`: dois estabelecimentos diferentes
podem legitimamente ter ambos uma sala "A101"). Garantida em dois
níveis, como o resto do projecto já faz:

1. Constraint de BD: `unique(['estabelecimento_id', 'codigo'])`.
2. Form Request, com mensagem amigável (ver secção Validação).

Não há concorrência a proteger aqui (ao contrário do "único Ano
Lectivo activo" do `AnoLectivo`) — criar uma Sala não depende de
nenhuma leitura-depois-escrita sensível a corrida, por isso as Actions
de Sala não precisam de `DB::transaction`/`lockForUpdate`.

### Alterar estado

`Sala` não tem a invariante "só uma activa por estabelecimento" —
várias salas podem estar `MANUTENCAO` ao mesmo tempo sem conflito
nenhum. `AlterarEstadoSalaAction` é por isso uma simples transição,
sem verificação adicional:

```php
class AlterarEstadoSalaAction
{
    public function alterar(Sala $sala, EstadoSala $novoEstado): Sala
    {
        $sala->update(['estado' => $novoEstado->value]);

        return $sala->fresh();
    }
}
```

Existe como Action/endpoint dedicado (em vez de só passar pelo
`AtualizarSalaAction` genérico) porque "colocar em manutenção" ou
"reativar" é uma acção rápida esperada directamente na listagem —
mesmo padrão de `AlterarEstadoAnoLectivoAction`.

### Eliminar

```php
class EliminarSalaAction
{
    public function executar(Sala $sala): void
    {
        $sala->delete();
    }
}
```

Soft delete simples, sem verificação de dependentes nesta fase —
**hoje não existe nenhuma tabela com `sala_id`**. Quando `Turma` (ou a
futura entidade de agendamento Sala↔Horário, ver decisão 7) passar a
referenciar `Sala`, esta Action tem de ganhar a mesma guarda de
dependentes que `EliminarAnoLectivoAction`/`EliminarUsuarioAction` já
usam. Fica registado aqui como trabalho futuro explícito, não como algo
esquecido.

## Validação (Form Requests)

Estende `App\Http\Requests\BaseRequest`, `authorize()` delega para
`infraestrutura.{acao}`, mensagens em português:

```php
class CriarSalaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('infraestrutura.criar') ?? false;
    }

    public function rules(): array
    {
        return [
            'codigo' => [
                'required',
                'string',
                'max:20',
                Rule::unique('salas', 'codigo')
                    ->where(fn ($query) => $query->where('estabelecimento_id', Estabelecimento::current()?->id)),
            ],
            'nome' => ['required', 'string', 'max:255'],
            'tipo' => ['required', new Enum(TipoSala::class)],
            'capacidade' => ['nullable', 'integer', 'min:1', 'max:500'],
            'localizacao' => ['nullable', 'string', 'max:255'],
            'observacoes' => ['nullable', 'string'],
            'estado' => ['sometimes', new Enum(EstadoSala::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'codigo.required' => 'O código da sala é obrigatório.',
            'codigo.unique' => 'Já existe uma sala com este código neste estabelecimento.',
            'nome.required' => 'O nome da sala é obrigatório.',
            'tipo.required' => 'O tipo de sala é obrigatório.',
            'capacidade.integer' => 'A capacidade deve ser um número válido.',
            'capacidade.min' => 'A capacidade deve ser de pelo menos 1.',
        ];
    }
}
```

`AtualizarSalaRequest` é igual, com `Rule::unique(...)->ignore($this->route('sala'))`
e `'estado' => ['required', new Enum(EstadoSala::class)]` (tal como
`AtualizarAnoLectivoRequest` também aceita `estado` no formulário
completo, além do endpoint dedicado). `AlterarEstadoSalaRequest` só
valida `'estado' => ['required', new Enum(EstadoSala::class)]`.

## Permissões

Segue **exactamente** o mecanismo real hoje em vigor (não o desenho
antigo de gates fixas por nome — esse foi substituído):
`Gate::before` (`Modules\Permissao\Providers\PermissaoServiceProvider`)
intercepta qualquer `$user->can('modulo.acao')`/`$this->authorize(...)`,
reconhece o formato `{slug-do-módulo}.{acção}` via
`Permissao\Services\PermissionResolver`, e resolve a permissão
combinando `RolePermissao` (perfil) + `UserPermissao` (excepção por
utilizador), com cache (`PermissaoCache`). Não há Policy classes por
Model neste mecanismo (nenhum módulo actual — `AnoLectivo`,
`Estabelecimento` — tem uma).

Passos necessários, seguindo o padrão de quando `Horario`/`AnoLectivo`
foram integrados:

1. **`Permissao\Enums\Modulo`** — novo case:

```php
case INFRAESTRUTURA = 12;
// slug(): self::INFRAESTRUTURA => 'infraestrutura',
// label(): self::INFRAESTRUTURA => 'Infraestrutura',
```

2. **`ModuloSeeder`** — nova entrada:

```php
['nome' => 12, 'descricao' => 'Infraestrutura'],
```

3. **`RolePermissaoSeeder`** — `ADMIN_ESCOLA` ganha CRUD completo,
   mesmo padrão de `ANO_LECTIVO`/`HORARIO`:

```php
Modulo::INFRAESTRUTURA->value => ['ver', 'criar', 'editar', 'eliminar'],
```

Nenhuma acção nova em `AcaoSeeder` — `ver`/`criar`/`editar`/`eliminar`
já existem e são globais a todos os módulos.

**Ponto central desta decisão**: `infraestrutura.ver`/`infraestrutura.criar`/
`infraestrutura.editar`/`infraestrutura.eliminar` é a **única** permissão
usada por `Sala` agora e por `Equipamento`/`Inventario`/`Manutencao` no
futuro — não `sala.ver`. Isto significa que conceder "Infraestrutura"
a um perfil, hoje, já dá acesso a Salas; no dia em que Equipamentos for
implementado, esse mesmo perfil ganha Equipamentos automaticamente,
sem passar por nenhum ecrã de Permissões outra vez. Esta é a
implicação prática de a árvore de menu ser um único grupo — se algum
dia for preciso separar (ex. um perfil só gere Salas mas não
Equipamentos), essa é uma decisão de produto a tomar nessa altura, não
antecipada aqui.

Isto integra-se automaticamente na grelha de Perfis/Permissões
(`RolePermissao`/`UserPermissao`) existente — "Infraestrutura" aparece
lá como qualquer outro módulo, sem tabela nem UI paralela.

## Auditoria — `RegistaAutoria`

Reutiliza o trait já existente em `Modules\Core\Traits\RegistaAutoria`
(criado para `AnoLectivo`) — `criado_por`/`editado_por` preenchidos
automaticamente em `creating`/`updating`. Nenhum código novo.

## Tenancy / Estabelecimento

Mesma situação documentada em
`docs/superpowers/specs/2026-08-30-modulo-anolectivo-design.md`: o
projecto é single-tenant hoje, sem `GlobalScope`/`tenant_id`.
`salas.estabelecimento_id` é `nullable`, populada por
`CriarSalaAction`/`AtualizarSalaAction` a partir de
`Estabelecimento::current()?->id`, e a unicidade de `codigo` já é
escopada por `estabelecimento_id` — pronto para múltiplos
estabelecimentos sem alteração de schema quando a tenancy real chegar.

## Rotas e Controller

Só `routes/web.php` (Inertia), mesmo estilo de duplo controlo de
`AnoLectivo` — `can:` a nível de rota **e** `$this->authorize(...)`
dentro do Controller:

```php
Route::middleware(['auth'])->prefix('salas')->name('salas.')->group(function () {
    Route::get('/', [SalaController::class, 'index'])->middleware('can:infraestrutura.ver')->name('index');
    Route::post('/', [SalaController::class, 'store'])->middleware('can:infraestrutura.criar')->name('store');
    Route::get('/{sala}', [SalaController::class, 'show'])->middleware('can:infraestrutura.ver')->name('show');
    Route::put('/{sala}', [SalaController::class, 'update'])->middleware('can:infraestrutura.editar')->name('update');
    Route::patch('/{sala}/estado', [SalaController::class, 'alterarEstado'])->middleware('can:infraestrutura.editar')->name('alterar-estado');
    Route::delete('/{sala}', [SalaController::class, 'destroy'])->middleware('can:infraestrutura.eliminar')->name('destroy');
});
```

```php
class SalaController extends Controller
{
    public function __construct(
        private GestaoSalaService $service,
        private SalaConsultaService $consulta,
    ) {}

    public function index()
    {
        $this->authorize('infraestrutura.ver');

        return Inertia::render('Infraestrutura/Salas/Index', [
            'salas' => $this->consulta->listar(),
        ]);
    }

    public function show(Sala $sala)
    {
        $this->authorize('infraestrutura.ver');

        return Inertia::render('Infraestrutura/Salas/Show', [
            'sala' => $sala,
        ]);
    }

    public function store(CriarSalaRequest $request)
    {
        $this->authorize('infraestrutura.criar');
        $this->service->criar($request);

        return redirect()->back()->with('success', 'Sala criada com sucesso.');
    }

    public function update(AtualizarSalaRequest $request, Sala $sala)
    {
        $this->authorize('infraestrutura.editar');
        $this->service->atualizar($sala, $request);

        return redirect()->back()->with('success', 'Sala atualizada com sucesso.');
    }

    public function alterarEstado(AlterarEstadoSalaRequest $request, Sala $sala)
    {
        $this->authorize('infraestrutura.editar');
        $this->service->alterarEstado($sala, EstadoSala::from((int) $request->validated('estado')));

        return redirect()->back()->with('success', 'Estado da sala atualizado com sucesso.');
    }

    public function destroy(Sala $sala)
    {
        $this->authorize('infraestrutura.eliminar');
        $this->service->eliminar($sala);

        return redirect()->back()->with('success', 'Sala eliminada com sucesso.');
    }
}
```

Páginas Inertia namespaced por entidade (`Infraestrutura/Salas/...`),
não só `Infraestrutura/...` — para não colidir quando
`Infraestrutura/Equipamentos/...` for criado no mesmo módulo.

## Testes

PHPUnit, `RefreshDatabase`, `tests/Feature/`, helper `actingAsStaff()`
(utilizador com role `ADMIN_ESCOLA`), seed `PermissaoDatabaseSeeder`,
mesmo padrão de `Modules/AnoLectivo/tests`:

1. **Criar Sala** — dados válidos cria o registo associado ao
   `Estabelecimento::current()`, com `estado = ATIVA` por defeito, e
   regista `criado_por`/`editado_por`.
2. **Código duplicado no mesmo estabelecimento** — rejeitado com erro
   de validação, BD não alterada.
3. **Mesmo código em estabelecimentos diferentes** — permitido (cria
   dois `Estabelecimento`, confirma que não há conflito).
4. **Actualizar Sala** — persiste alterações e actualiza `editado_por`
   com o utilizador autenticado no `PUT` (diferente de quem criou).
5. **Alterar estado** — `PATCH /salas/{id}/estado` transita
   `ATIVA → MANUTENCAO → INATIVA` e vice-versa, sem restrição.
6. **Eliminar Sala** — soft delete: `deleted_at` preenchido, registo
   desaparece da listagem mas continua na BD.
7. **`tipo_descricao`/`estado_descricao` sincronizados** — confirma
   que gravar `tipo`/`estado` actualiza as colunas espelho.
8. **Autorização** — utilizador com `infraestrutura.*` (via
   `ADMIN_ESCOLA`) consegue todas as rotas; utilizador sem essa
   permissão (ex. `PROFESSOR`) recebe 403 em todas as rotas de escrita
   e leitura do módulo.

## Fora de escopo

Frontend (páginas Vue/Inertia) — spec seguinte. Módulos/entidades
`Equipamento`, `Inventario`, `Manutencao` (partilham módulo e
permissão, mas não são implementados agora). FK `turmas.sala_id` ou
qualquer entidade de agendamento Sala↔Horário↔Turma (ver decisões 6 e
7 da fase de domínio). Modelagem estruturada de Bloco/Edifício/Andar.
Verificação de dependentes em `EliminarSalaAction` (não há dependentes
ainda — documentado como follow-up). Relatórios/dashboards de
ocupação. Seeders de dados fictícios.

## Critério de sucesso

Módulo `Modules/Infraestrutura` completo no backend com `Sala`
(Model, migration, Enums, DTO, Actions, Requests, Controller, rotas),
integrado na permissão `infraestrutura.*` já ligada ao mecanismo real
`Gate::before`/`PermissionResolver` (via `Modulo::INFRAESTRUTURA`,
`ModuloSeeder`, `RolePermissaoSeeder`), sem nenhuma tabela ou mecanismo
de autorização paralelo. Os 8 cenários de teste passam. `codigo` é
único por `estabelecimento_id`; nenhuma Sala é eliminada
fisicamente (soft delete).
