# Documentos de Pessoa (DadosPessoa → DocumentoPessoa) — Design

## Objectivo

`DadosPessoa` é a entidade comum a Aluno, Professor, Funcionário e Outro,
mas hoje não tem forma de guardar documentos/anexos (BI, Passaporte,
Certidão de Nascimento, Certificado, Declaração, Outro). Este desenho
introduz `DocumentoPessoa`, ligado apenas a `DadosPessoa` — nunca a
`Aluno` ou qualquer outro papel — para que a capacidade seja reutilizável
por qualquer módulo que já tenha uma pessoa associada, sem acoplamento
ao módulo Aluno. O tipo de documento é modelado como tabela configurável
(`TipoDocumento`), não como enum PHP, para poder crescer sem alterar
código.

## Contexto (estado actual, confirmado no código)

- O model real chama-se **`DadosPessoal`**
  (`Modules/Usuario/app/Models/DadosPessoal.php`), tabela `dados_pessoas`
  — nome de classe inconsistente com o nome da tabela e com o domínio
  (`DadosPessoa`). Não usa `RegistaAutoria`, `SincronizaEstadoDescricao`
  nem `SoftDeletes`. Não tem nenhuma relação declarada — `Aluno` e
  `User` fazem `belongsTo(DadosPessoal::class, 'dados_pessoa_id')`, mas
  não há `hasMany`/`hasOne` inverso.
- Não existe padrão Repository em nenhum módulo do projecto. O fluxo
  real e confirmado é `Request → Service → DTO → Action → Model`,
  exemplificado em `Modules/Estabelecimento` (`EstabelecimentoDTO`,
  `AtualizarLogotipoEstabelecimentoAction`,
  `GestaoEstabelecimentoService`).
- O único upload existente no projecto é o logótipo do Estabelecimento:
  `Storage::disk('public')`, só guarda `logotipo_path`, sem
  `mime_type`/`tamanho`/`nome_original`, servido por URL pública sem
  autorização. Documentos de identificação pessoal (BI, Passaporte) são
  sensíveis — este padrão não se aplica aqui.
- O padrão "tipo extensível via tabela" já usado no projecto
  (`Modulo`/`Acao`, módulo Permissao) usa um enum PHP como fonte da
  verdade, com a tabela só para permitir FK, sincronizada por Seeder.
  Aqui o requisito é o oposto: nenhum enum PHP a espelhar — a tabela
  `tipos_documentos` é a única fonte da verdade, para que um novo tipo
  seja um `insert`, nunca uma alteração de código.

## Decisão confirmada

- Renomear `DadosPessoal` → `DadosPessoa`, alinhando o nome da classe
  ao nome da tabela (`dados_pessoas`) e ao domínio. Alcance confirmado
  por grep (excluindo o worktree `.claude/worktrees/infraestrutura-salas/`,
  que pertence a outra tarefa em curso e fica fora deste plano):
  - `Modules/Usuario/app/Models/DadosPessoal.php` (rename do ficheiro e da classe)
  - `Modules/Usuario/app/Http/Requests/CriarDadosPessoalRequest.php` (referência ao model; nome do Request mantém-se, só ajusta o `use`)
  - `Modules/Usuario/app/Models/User.php` (`pessoa(): belongsTo`)
  - `Modules/Aluno/app/Models/Aluno.php` (`dadosPessoa(): belongsTo`)
  - `Modules/Aluno/app/Actions/CriarAlunoAction.php`
  - `Modules/Usuario/tests/Feature/DadosPessoaIdUnicoTest.php`
  - `Modules/Aluno/tests/Feature/AlunoUsuarioIntegracaoTest.php`
  - `Modules/Aluno/tests/Feature/AlterarEstadoAlunoActionTest.php`
  - `Modules/Aluno/tests/Feature/AtualizarAlunoActionTest.php`
  - `Modules/Aluno/tests/Feature/CriarAlunoRequestTest.php`
  - `Modules/Aluno/tests/Feature/AlunoHttpTest.php`
  - `Modules/Aluno/tests/Feature/AlunoConsultaServiceTest.php`
  - `Modules/Aluno/tests/Feature/AlunoModelTest.php`
  - `Modules/Aluno/tests/Feature/CriarAlunoActionTest.php`
  - `Modules/AnoLectivo/tests/Feature/AnoLectivoHttpTest.php`
  - `Modules/AnoLectivo/tests/Feature/EliminarAnoLectivoActionTest.php`
  - `Modules/AnoLectivo/tests/Feature/AlterarEstadoAnoLectivoActionTest.php`
  - `Modules/Matricula/tests/Feature/*.php` (9 ficheiros — fixtures que criam `DadosPessoal` para montar um Aluno)
  - `Modules/Turma/tests/Feature/TurmaHttpTest.php`

  Rename puro (find/replace `DadosPessoal` → `DadosPessoa` nestes
  ficheiros); nenhuma mudança de comportamento. Tratado como fase
  própria, antes da criação das tabelas novas.

- `DocumentoPessoa` fica em `Modules/Usuario` (mesmo módulo de
  `DadosPessoa`), sem criar módulo novo. Outros módulos (ex.: Aluno)
  acedem via injecção directa do `GestaoDocumentoPessoaService`
  (`Modules\Usuario\Services\...`) — mesmo padrão de acoplamento
  cross-module já existente entre `Usuario` e `Permissao`.

- `TipoDocumento` é tabela pura, sem enum PHP espelhado. Seed inicial:
  `bi`, `passaporte`, `certidao_nascimento`, `certificado`,
  `declaracao`, `outro`.

- Ficheiros em disco privado dedicado (não `public`), servidos só por
  rota autenticada/autorizada — nunca por URL pública directa.

- Histórico: uma pessoa pode ter vários `DocumentoPessoa` do mesmo
  `tipo_documento_id` ao longo do tempo. Ao criar um novo, o(s)
  anterior(es) ATIVO(s) do mesmo tipo passam a INATIVO automaticamente
  (nunca apagados). **Semântica explícita, para não confundir os dois
  mecanismos:**
  - `estado = INATIVO` → documento substituído por um mais recente do
    mesmo tipo, mas o registo e o ficheiro continuam preservados
    (histórico consultável).
  - `deleted_at` preenchido → remoção lógica do registo (o utilizador
    pediu para apagar aquele documento especificamente), via
    `SoftDeletes` — mecanismo independente de `estado`.
  - Um documento pode estar INATIVO sem estar soft-deleted (caso comum:
    rotação de BI), e pode ser soft-deleted estando ATIVO ou INATIVO
    (o utilizador decide apagar um registo errado, independentemente do
    seu estado de "actual").

## Modelo de dados

### `tipos_documentos` (nova)

```php
Schema::create('tipos_documentos', function (Blueprint $table) {
    $table->id();
    $table->string('nome');                    // "Bilhete de Identidade", ...
    $table->string('slug')->unique();           // 'bi', 'passaporte', ...
    $table->unsignedTinyInteger('estado')->default(1);
    $table->string('estado_descricao')->default('Ativo');
    $table->timestamps();
});
```

Model `Modules/Usuario/app/Models/TipoDocumento.php`: `use HasFactory,
SincronizaEstadoDescricao;` — mesmo padrão de `Modulo`/`Acao`
(`Modules/Permissao/app/Models/Modulo.php`).

Seeder `TipoDocumentoSeeder` (`updateOrCreate` por `slug`, mesmo padrão
de `ModuloSeeder`/`AcaoSeeder`).

### `documentos_pessoas` (nova)

```php
Schema::create('documentos_pessoas', function (Blueprint $table) {
    $table->id();
    $table->foreignId('dados_pessoa_id')->constrained('dados_pessoas')->cascadeOnDelete();
    $table->foreignId('tipo_documento_id')->constrained('tipos_documentos')->restrictOnDelete();
    $table->string('numero_documento')->nullable();
    $table->date('data_emissao')->nullable();
    $table->date('data_validade')->nullable();
    $table->string('nome_original');
    $table->string('caminho');
    $table->string('mime_type');
    $table->unsignedInteger('tamanho'); // bytes
    $table->text('observacoes')->nullable();
    $table->unsignedTinyInteger('estado')->default(1);
    $table->string('estado_descricao')->default('Ativo');
    $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
    $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();
    $table->softDeletes();

    $table->index(['dados_pessoa_id', 'tipo_documento_id']); // não único — histórico permitido
});
```

`cascadeOnDelete` em `dados_pessoa_id`: se a pessoa for apagada, os
documentos vão com ela (documentos não têm sentido órfãos). `restrictOnDelete`
em `tipo_documento_id`: não se apaga um tipo em uso (mesma cautela já
aplicada a `modulo_id`/`acao_id` em `user_permissoes`).

## Models

`DocumentoPessoa` (`Modules/Usuario/app/Models/DocumentoPessoa.php`):

```php
class DocumentoPessoa extends Model
{
    use HasFactory, RegistaAutoria, SincronizaEstadoDescricao, SoftDeletes;

    protected $table = 'documentos_pessoas';

    protected $fillable = [
        'dados_pessoa_id', 'tipo_documento_id', 'numero_documento',
        'data_emissao', 'data_validade', 'nome_original', 'caminho',
        'mime_type', 'tamanho', 'observacoes', 'estado', 'estado_descricao',
        'criado_por', 'editado_por',
    ];

    protected $attributes = ['estado' => 1];
    protected $casts = [
        'data_emissao' => 'date',
        'data_validade' => 'date',
        'estado' => 'integer',
        'tamanho' => 'integer',
    ];

    public function dadosPessoa(): BelongsTo { return $this->belongsTo(DadosPessoa::class, 'dados_pessoa_id'); }
    public function tipoDocumento(): BelongsTo { return $this->belongsTo(TipoDocumento::class, 'tipo_documento_id'); }
    public function criadoPor(): BelongsTo { return $this->belongsTo(User::class, 'criado_por'); }
    public function editadoPor(): BelongsTo { return $this->belongsTo(User::class, 'editado_por'); }
}
```

`DadosPessoa` (pós-rename) ganha a relação que hoje falta:

```php
public function documentos(): HasMany
{
    return $this->hasMany(DocumentoPessoa::class, 'dados_pessoa_id');
}
```

`TipoDocumento` ganha:

```php
public function documentos(): HasMany
{
    return $this->hasMany(DocumentoPessoa::class, 'tipo_documento_id');
}
```

## DTO / Requests

`DocumentoPessoaDTO` (`Modules/Usuario/app/DTO/DocumentoPessoaDTO.php`) —
contém só os "dados do documento"; os "dados do ficheiro" (nome
original, caminho, mime, tamanho) são derivados pela Action a partir do
`UploadedFile`, nunca vindos do DTO:

```php
class DocumentoPessoaDTO
{
    public function __construct(
        public int $tipo_documento_id,
        public ?string $numero_documento,
        public ?string $data_emissao,
        public ?string $data_validade,
        public ?string $observacoes,
    ) {}

    public static function fromRequest(GuardarDocumentoPessoaRequest $request): self
    {
        $dados = $request->validated();
        return new self(
            tipo_documento_id: (int) $dados['tipo_documento_id'],
            numero_documento: $dados['numero_documento'] ?? null,
            data_emissao: $dados['data_emissao'] ?? null,
            data_validade: $dados['data_validade'] ?? null,
            observacoes: $dados['observacoes'] ?? null,
        );
    }
}
```

`GuardarDocumentoPessoaRequest` (extends `BaseRequest`):

```php
'tipo_documento_id' => ['required', 'integer', Rule::exists('tipos_documentos', 'id')],
'numero_documento'  => ['nullable', 'string', 'max:100'],
'data_emissao'      => ['nullable', 'date'],
'data_validade'     => ['nullable', 'date', 'after_or_equal:data_emissao'],
'observacoes'       => ['nullable', 'string', 'max:1000'],
'ficheiro'          => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
```

## Actions / Service

```
Modules/Usuario/app/
├── Models/TipoDocumento.php
├── Models/DocumentoPessoa.php
├── DTO/DocumentoPessoaDTO.php
├── Actions/CriarDocumentoPessoaAction.php
├── Actions/RemoverDocumentoPessoaAction.php
├── Actions/AlternarEstadoDocumentoPessoaAction.php
└── Services/GestaoDocumentoPessoaService.php
```

`CriarDocumentoPessoaAction::executar(DadosPessoa $pessoa,
DocumentoPessoaDTO $dto, UploadedFile $ficheiro): DocumentoPessoa` —
dentro de `DB::transaction`:
1. `DocumentoPessoa::where('dados_pessoa_id', $pessoa->id)->where('tipo_documento_id', $dto->tipo_documento_id)->where('estado', Estado::ATIVO->value)->lockForUpdate()->update(['estado' => Estado::INATIVO->value])`.
2. Guarda o ficheiro no disco privado (`$ficheiro->store('documentos-pessoas/' . $pessoa->id, 'documentos')`).
3. Cria o `DocumentoPessoa` com os dados do DTO + `nome_original`,
   `caminho`, `mime_type`, `tamanho` derivados do `UploadedFile`.

`RemoverDocumentoPessoaAction::executar(DocumentoPessoa $documento):
void` — `$documento->delete()` (soft delete; ficheiro físico só é
removido em eliminação forçada, fora de alcance desta fase).

`AlternarEstadoDocumentoPessoaAction` — reaproveita
`Core\Traits\AlternaEstado`, para marcar/desmarcar um documento como
ATIVO manualmente (ex.: reverter uma substituição indevida).

`GestaoDocumentoPessoaService` — orquestra as três Actions, expõe
`listar(DadosPessoa $pessoa)`, `adicionar(...)`, `remover(...)`,
`alternarEstado(...)`, `download(DocumentoPessoa $documento):
StreamedResponse`. É este Service que módulos externos (ex.: Aluno)
injectam directamente.

## Storage e segurança

Novo disco dedicado `documentos` em `config/filesystems.php`, `driver
=> 'local'`, fora de `storage/app/public` (sem symlink público). Rotas
próprias do módulo Usuario:

```
GET    /dados-pessoais/{dadosPessoa}/documentos
POST   /dados-pessoais/{dadosPessoa}/documentos
DELETE /documentos-pessoa/{documento}
GET    /documentos-pessoa/{documento}/download
```

`download` verifica permissão antes de `Storage::disk('documentos')->download($documento->caminho, $documento->nome_original)` —
nunca expõe `caminho` bruto nem gera URL pública.

## Fronteiras de módulo

`Modules\Usuario` ganha `TipoDocumento`, `DocumentoPessoa` e o Service
associado — mesma direcção de dependência que já existe hoje (Usuario
já depende de Permissao; nenhum módulo passa a depender de Usuario que
não dependesse antes, já que `Aluno` já depende de
`Modules\Usuario\Models\DadosPessoa` via `dados_pessoa_id`).

## Uso futuro no formulário de Aluno

Sem alteração em `alunos`/`Aluno`. O formulário de Aluno ganha uma
secção "Documentos" que, dado o `Aluno::dadosPessoa` já carregado, lista
e permite adicionar/remover documentos chamando as rotas acima
(cross-module, mesmo padrão de chamada já usado entre Usuario e
Permissao). A permissão específica (`documentos.ver`,
`documentos.criar`, `documentos.eliminar` — nome exacto do módulo no
enum `Permissao\Enums\Modulo`) fica por decidir na fase de plano, não
nesta spec.

## Testes afectados (novos, além do rename)

- `TipoDocumentoModelTest` — `SincronizaEstadoDescricao` sincroniza
  `estado_descricao`.
- `TipoDocumentoSeederTest` (ou coberto por
  `DatabaseSeederTest` existente) — seed cria os 6 tipos iniciais.
- `CriarDocumentoPessoaActionTest` — cria documento, ficheiro vai para
  disco `documentos` (fake), campos derivados (`mime_type`, `tamanho`,
  `nome_original`) correctos; criar um segundo documento do mesmo tipo
  marca o primeiro como INATIVO sem apagá-lo.
- `RemoverDocumentoPessoaActionTest` — soft delete; `estado` do
  documento não muda ao ser removido (dois mecanismos independentes,
  conforme secção "Decisão confirmada").
- `GestaoDocumentoPessoaServiceTest` — `listar` devolve todos
  (ATIVO+INATIVO, incluindo histórico), `download` só serve com
  permissão.
- `DocumentoPessoaHttpTest` — upload via rota, download autenticado
  (403 sem permissão, sem URL pública acessível), listagem por pessoa.
- Rename: todos os ficheiros listados em "Decisão confirmada" passam a
  referenciar `DadosPessoa`; suite completa continua verde.

## Auto-review

- **Placeholders:** nenhum "TBD" — schema, DTO, Actions e rotas têm
  forma concreta.
- **Consistência interna:** a distinção `estado` vs `deleted_at` está
  definida uma única vez ("Decisão confirmada") e aplicada
  consistentemente em Action (`RemoverDocumentoPessoaAction` só mexe em
  `deleted_at`, `CriarDocumentoPessoaAction` só mexe em `estado`) e em
  testes.
- **Escopo:** toca `Modules/Usuario` (novo) + rename de `DadosPessoal`
  espalhado por 5 módulos (mecânico, sem mudança de comportamento). Não
  mexe em `Aluno`/`alunos` além de referenciar `DadosPessoa` já
  renomeado. Integração visual no formulário de Aluno fica fora desta
  spec (mencionada só como direcção futura).
- **Ambiguidade:** cardinalidade do índice
  `(dados_pessoa_id, tipo_documento_id)` explicitamente não-única
  (corrigido nesta revisão); disco de storage explicitamente privado,
  sem ambiguidade sobre disco `public` vs dedicado.
