# Módulo Aluno Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Criar o módulo `Aluno` (entidade académica), 1:1 com `dados_pessoas`, com `numero_matricula` próprio e único, User de acesso opcional (role `ALUNO`), isolado por estabelecimento, seguindo exactamente o padrão de `Curso`/`Disciplina`.

**Architecture:** Novo módulo `Modules/Aluno` (nwidart/laravel-modules), camadas Migration → Model (`RegistaAutoria`+`SincronizaEstadoDescricao`) → DTO → Actions → Services (consulta/gestão) → Requests → Controller fino → rotas `can:` → Pages/Componentes Vue (Inertia). Reaproveita `Modules\Usuario\Models\DadosPessoal` e `Modules\Usuario\Actions\UsuarioAction` (não duplica lógica de criação de User). Autorização via `Gate::before` genérico já existente (`Modulo::ALUNO`/`Perfil::ALUNO` já estão nos enums) — só falta seed de `RolePermissaoSeeder`.

**Tech Stack:** Laravel 11 + nwidart/laravel-modules, Inertia + Vue 3, PHPUnit + `RefreshDatabase` (SQLite `:memory:`), Bootstrap/Metronic no frontend.

**Spec:** issue "Implementar módulo Aluno" fornecida pelo utilizador (ver corpo da conversa) — arquitectura DadosPessoa/Aluno/User conforme secções 1–17.

## Global Constraints

- `numero_matricula` pertence só ao Aluno; não existe `alunos.codigo` separado.
- `alunos.dados_pessoa_id` obrigatório, `UNIQUE` (1:1 com `dados_pessoas`).
- Não existe `alunos.user_id`.
- `users.dados_pessoa_id` passa a ter `UNIQUE` na BD (0..1 conta por pessoa).
- Nenhuma funcionalidade de Matrícula/Turma/Frequência/Avaliações/Encarregado é implementada.
- Seguir exactamente o esqueleto de `Modules/Curso` (sem Policy classes, sem Resources).
- Todos os textos de UI e mensagens de validação em português.

---

### Task 1: Migration `users.dados_pessoa_id` UNIQUE + extensão retrocompatível de `UsuarioDTO`/`UsuarioAction`

**Files:**
- Create: `Modules/Usuario/database/migrations/2026_09_10_000000_add_unique_to_users_dados_pessoa_id.php`
- Modify: `Modules/Usuario/app/DTO/UsuarioDTO.php`
- Modify: `Modules/Usuario/app/Actions/UsuarioAction.php`
- Test: `Modules/Usuario/tests/Feature/DadosPessoaIdUnicoTest.php`

**Interfaces:**
- Produces: `UsuarioDTO` ganha propriedade pública opcional `?string $numeroMatricula = null`. `UsuarioAction::criar()` usa `$dto->numeroMatricula` quando fornecido em vez de gerar via `GeradorMatriculaService`, só quando `tipoLogin === TipoLogin::MATRICULA`.

- [ ] **Step 1: Escrever migration da constraint**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unique('dados_pessoa_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['dados_pessoa_id']);
        });
    }
};
```

- [ ] **Step 2: Escrever teste que falha (unicidade ainda não garantida se a migration não correr)**

```php
<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Usuario\Models\DadosPessoal;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class DadosPessoaIdUnicoTest extends TestCase
{
    use RefreshDatabase;

    public function test_uma_dados_pessoa_nao_pode_ter_dois_users(): void
    {
        $pessoa = DadosPessoal::create([
            'nome_completo' => 'Ana Silva',
            'numero_identificacao' => 'BI12345',
            'tipo_pessoa' => DadosPessoal::TIPO_ALUNO,
        ]);

        User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => Hash::make('x'), 'dados_pessoa_id' => $pessoa->id]);

        $this->expectException(QueryException::class);

        User::create(['name' => 'Ana 2', 'email' => 'ana2@example.com', 'password' => Hash::make('x'), 'dados_pessoa_id' => $pessoa->id]);
    }

    public function test_varios_users_sem_dados_pessoa_sao_permitidos(): void
    {
        User::create(['name' => 'A', 'email' => 'a@example.com', 'password' => Hash::make('x')]);
        User::create(['name' => 'B', 'email' => 'b@example.com', 'password' => Hash::make('x')]);

        $this->assertSame(2, User::count());
    }
}
```

- [ ] **Step 3: Rodar o teste, confirmar que passa** (`php artisan test --filter=DadosPessoaIdUnicoTest`) — a primeira asserção só passa depois da migration do Step 1 existir.

- [ ] **Step 4: Adicionar `numeroMatricula` ao `UsuarioDTO`**

Em `Modules/Usuario/app/DTO/UsuarioDTO.php`, acrescentar ao construtor (mantendo ordem/nomes existentes, só acrescenta no fim):

```php
public array $celulas = [],
public ?string $numeroMatricula = null,
```

- [ ] **Step 5: Adaptar `UsuarioAction::criar` para preferir `numeroMatricula` do DTO**

Em `Modules/Usuario/app/Actions/UsuarioAction.php`, trocar:

```php
'numero_matricula' => $dto->tipoLogin === TipoLogin::MATRICULA
    ? $this->geradorMatricula->gerar()
    : null,
```

por:

```php
'numero_matricula' => $dto->tipoLogin === TipoLogin::MATRICULA
    ? ($dto->numeroMatricula ?? $this->geradorMatricula->gerar())
    : null,
```

- [ ] **Step 6: Rodar toda a suite do módulo Usuario para garantir que nada quebrou**

Run: `php artisan test Modules/Usuario`
Expected: PASS (comportamento antigo inalterado quando `numeroMatricula` não é passado)

- [ ] **Step 7: Commit**

```bash
git add Modules/Usuario/database/migrations/2026_09_10_000000_add_unique_to_users_dados_pessoa_id.php \
        Modules/Usuario/app/DTO/UsuarioDTO.php \
        Modules/Usuario/app/Actions/UsuarioAction.php \
        Modules/Usuario/tests/Feature/DadosPessoaIdUnicoTest.php
git commit -m "feat(usuario): garantir 1:1 dados_pessoa-user e permitir numero_matricula explicito"
```

---

### Task 2: Esqueleto do módulo `Aluno` (module.json, composer.json, providers, config, registo)

**Files:**
- Create: `Modules/Aluno/module.json`
- Create: `Modules/Aluno/composer.json`
- Create: `Modules/Aluno/config/config.php`
- Create: `Modules/Aluno/app/Providers/AlunoServiceProvider.php`
- Create: `Modules/Aluno/app/Providers/EventServiceProvider.php`
- Create: `Modules/Aluno/app/Providers/RouteServiceProvider.php`
- Create: `Modules/Aluno/routes/web.php` (placeholder vazio de rotas, preenchido na Task 6)
- Create: `Modules/Aluno/routes/api.php`
- Modify: `modules_statuses.json`

**Interfaces:**
- Produces: namespace `Modules\Aluno\*` autoloadável, módulo activo.

- [ ] **Step 1: Criar `module.json`**

```json
{
    "name": "Aluno",
    "alias": "aluno",
    "description": "",
    "keywords": [],
    "priority": 0,
    "providers": [
        "Modules\\Aluno\\Providers\\AlunoServiceProvider"
    ],
    "files": []
}
```

- [ ] **Step 2: Criar `composer.json`**

```json
{
    "name": "nwidart/aluno",
    "description": "",
    "authors": [
        {
            "name": "Nicolas Widart",
            "email": "n.widart@gmail.com"
        }
    ],
    "extra": {
        "laravel": {
            "providers": [],
            "aliases": {}
        }
    },
    "autoload": {
        "psr-4": {
            "Modules\\Aluno\\": "app/",
            "Modules\\Aluno\\Database\\Factories\\": "database/factories/",
            "Modules\\Aluno\\Database\\Seeders\\": "database/seeders/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Modules\\Aluno\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 3: Criar `config/config.php`**

```php
<?php

return [
    'name' => 'Aluno',
];
```

- [ ] **Step 4: Criar os três Providers (copiar padrão de `Modules/Curso`, trocando o nome)**

`Modules/Aluno/app/Providers/AlunoServiceProvider.php`:
```php
<?php

namespace Modules\Aluno\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class AlunoServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Aluno';

    protected string $nameLower = 'aluno';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
    }
}
```

`Modules/Aluno/app/Providers/EventServiceProvider.php`:
```php
<?php

namespace Modules\Aluno\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [];

    protected static $shouldDiscoverEvents = true;

    protected function configureEmailVerification(): void {}
}
```

`Modules/Aluno/app/Providers/RouteServiceProvider.php`:
```php
<?php

namespace Modules\Aluno\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    protected string $name = 'Aluno';

    public function boot(): void
    {
        parent::boot();
    }

    public function map(): void
    {
        $this->mapApiRoutes();
        $this->mapWebRoutes();
    }

    protected function mapWebRoutes(): void
    {
        Route::middleware('web')->group(module_path($this->name, '/routes/web.php'));
    }

    protected function mapApiRoutes(): void
    {
        Route::middleware('api')->prefix('api')->name('api.')->group(module_path($this->name, '/routes/api.php'));
    }
}
```

- [ ] **Step 5: Criar `routes/web.php` e `routes/api.php` vazios**

`Modules/Aluno/routes/web.php`:
```php
<?php

// Rotas adicionadas na Task 6.
```

`Modules/Aluno/routes/api.php`:
```php
<?php

// Este módulo não expõe rotas de API; é apenas Inertia-web.
```

- [ ] **Step 6: Registar o módulo em `modules_statuses.json`**

Adicionar `"Aluno": true` ao objecto (ordem alfabética não importa, seguir o padrão dos últimos módulos adicionados).

- [ ] **Step 7: Rodar `composer dump-autoload` e confirmar que o módulo é reconhecido**

Run: `composer dump-autoload && php artisan module:list`
Expected: `Aluno` aparece na lista, status Enabled.

- [ ] **Step 8: Commit**

```bash
git add Modules/Aluno/module.json Modules/Aluno/composer.json Modules/Aluno/config \
        Modules/Aluno/app/Providers Modules/Aluno/routes modules_statuses.json composer.lock
git commit -m "feat(aluno): scaffold do modulo Aluno"
```

---

### Task 3: Migration + Model `Aluno`

**Files:**
- Create: `Modules/Aluno/database/migrations/2026_09_10_000001_create_alunos_table.php`
- Create: `Modules/Aluno/app/Models/Aluno.php`
- Test: `Modules/Aluno/tests/Feature/AlunoModelTest.php`

**Interfaces:**
- Consumes: `Modules\Core\Traits\RegistaAutoria`, `Modules\Core\Traits\SincronizaEstadoDescricao`, `Modules\Estabelecimento\Models\Estabelecimento`, `Modules\Usuario\Models\{DadosPessoal,User}`.
- Produces: `Modules\Aluno\Models\Aluno` com `$fillable = ['estabelecimento_id','dados_pessoa_id','numero_matricula','estado','estado_descricao','criado_por','editado_por']`, relations `estabelecimento()`, `dadosPessoa()`, `criadoPor()`, `editadoPor()`.

- [ ] **Step 1: Escrever a migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alunos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estabelecimento_id')->constrained('estabelecimentos')->restrictOnDelete();
            $table->foreignId('dados_pessoa_id')->unique()->constrained('dados_pessoas')->restrictOnDelete();
            $table->string('numero_matricula')->unique();
            $table->unsignedTinyInteger('estado')->default(1);
            $table->string('estado_descricao')->default('Ativo');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alunos');
    }
};
```

- [ ] **Step 2: Escrever o teste do Model (autoria, sincronização de estado, unicidade)**

```php
<?php

namespace Modules\Aluno\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Modules\Aluno\Models\Aluno;
use Modules\Core\Enums\Estado;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Models\DadosPessoal;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class AlunoModelTest extends TestCase
{
    use RefreshDatabase;

    private function criarEstabelecimento(): Estabelecimento
    {
        return Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
    }

    private function criarDadosPessoa(string $numeroIdentificacao = 'BI0001'): DadosPessoal
    {
        return DadosPessoal::create([
            'nome_completo' => 'Ana Silva',
            'numero_identificacao' => $numeroIdentificacao,
            'tipo_pessoa' => DadosPessoal::TIPO_ALUNO,
        ]);
    }

    public function test_regista_autoria_e_sincroniza_estado_descricao(): void
    {
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        Auth::login($staff);

        $estabelecimento = $this->criarEstabelecimento();
        $pessoa = $this->criarDadosPessoa();

        $aluno = Aluno::create([
            'estabelecimento_id' => $estabelecimento->id,
            'dados_pessoa_id' => $pessoa->id,
            'numero_matricula' => '2026-0001',
        ]);

        $this->assertSame($staff->id, $aluno->criado_por);
        $this->assertSame($staff->id, $aluno->editado_por);
        $this->assertSame(Estado::ATIVO->value, $aluno->estado);
        $this->assertSame('Ativo', $aluno->estado_descricao);
    }

    public function test_dados_pessoa_id_e_unico_em_alunos(): void
    {
        $estabelecimento = $this->criarEstabelecimento();
        $pessoa = $this->criarDadosPessoa();

        Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);

        $this->expectException(QueryException::class);
        Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0002']);
    }

    public function test_numero_matricula_e_unico_globalmente(): void
    {
        $estabelecimento = $this->criarEstabelecimento();

        Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $this->criarDadosPessoa('BI0001')->id, 'numero_matricula' => '2026-0001']);

        $this->expectException(QueryException::class);
        Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $this->criarDadosPessoa('BI0002')->id, 'numero_matricula' => '2026-0001']);
    }
}
```

- [ ] **Step 3: Rodar o teste, confirmar que falha** (model ainda não existe)

Run: `php artisan test --filter=AlunoModelTest`
Expected: FAIL (classe `Modules\Aluno\Models\Aluno` não encontrada)

- [ ] **Step 4: Escrever o Model**

```php
<?php

namespace Modules\Aluno\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Core\Traits\SincronizaEstadoDescricao;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Models\DadosPessoal;
use Modules\Usuario\Models\User;

class Aluno extends Model
{
    use RegistaAutoria;
    use SincronizaEstadoDescricao;

    protected $table = 'alunos';

    protected $fillable = [
        'estabelecimento_id',
        'dados_pessoa_id',
        'numero_matricula',
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

    public function dadosPessoa(): BelongsTo
    {
        return $this->belongsTo(DadosPessoal::class, 'dados_pessoa_id');
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

- [ ] **Step 5: Rodar o teste, confirmar que passa**

Run: `php artisan test --filter=AlunoModelTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add Modules/Aluno/database/migrations/2026_09_10_000001_create_alunos_table.php \
        Modules/Aluno/app/Models/Aluno.php \
        Modules/Aluno/tests/Feature/AlunoModelTest.php
git commit -m "feat(aluno): tabela e model Aluno com autoria e estado"
```

---

### Task 4: DTO `AlunoDTO`, Requests, Actions

**Files:**
- Create: `Modules/Aluno/app/DTO/AlunoDTO.php`
- Create: `Modules/Aluno/app/Http/Requests/CriarAlunoRequest.php`
- Create: `Modules/Aluno/app/Http/Requests/AtualizarAlunoRequest.php`
- Create: `Modules/Aluno/app/Http/Requests/AlterarEstadoAlunoRequest.php`
- Create: `Modules/Aluno/app/Actions/CriarAlunoAction.php`
- Create: `Modules/Aluno/app/Actions/AtualizarAlunoAction.php`
- Create: `Modules/Aluno/app/Actions/AlterarEstadoAlunoAction.php`
- Test: `Modules/Aluno/tests/Feature/CriarAlunoActionTest.php`
- Test: `Modules/Aluno/tests/Feature/AtualizarAlunoActionTest.php`
- Test: `Modules/Aluno/tests/Feature/AlterarEstadoAlunoActionTest.php`
- Test: `Modules/Aluno/tests/Feature/CriarAlunoRequestTest.php`

**Interfaces:**
- Produces: `AlunoDTO(?int $dadosPessoaId, ?string $nomeCompleto, ?string $email, ?string $telefone, ?string $dataNascimento, int $sexo, ?string $numeroIdentificacao, string $numeroMatricula)`; `CriarAlunoAction::executar(AlunoDTO $dto): Aluno`; `AtualizarAlunoAction::executar(Aluno $aluno, AlunoDTO $dto): Aluno`; `AlterarEstadoAlunoAction::executar(Aluno $aluno, Estado $novoEstado): Aluno`.

- [ ] **Step 1: Escrever `AlunoDTO`**

```php
<?php

namespace Modules\Aluno\DTO;

use Modules\Aluno\Http\Requests\AtualizarAlunoRequest;
use Modules\Aluno\Http\Requests\CriarAlunoRequest;

class AlunoDTO
{
    public function __construct(
        public ?int $dadosPessoaId,
        public ?string $nomeCompleto,
        public ?string $email,
        public ?string $telefone,
        public ?string $dataNascimento,
        public int $sexo,
        public ?string $numeroIdentificacao,
        public string $numeroMatricula,
    ) {
    }

    public static function fromCriarRequest(CriarAlunoRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            dadosPessoaId: $dados['dados_pessoa_id'] ?? null,
            nomeCompleto: $dados['nome_completo'] ?? null,
            email: $dados['email'] ?? null,
            telefone: $dados['telefone'] ?? null,
            dataNascimento: $dados['data_nascimento'] ?? null,
            sexo: $dados['sexo'] ?? 0,
            numeroIdentificacao: $dados['numero_identificacao'] ?? null,
            numeroMatricula: $dados['numero_matricula'],
        );
    }

    public static function fromAtualizarRequest(AtualizarAlunoRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            dadosPessoaId: null,
            nomeCompleto: $dados['nome_completo'],
            email: $dados['email'] ?? null,
            telefone: $dados['telefone'] ?? null,
            dataNascimento: $dados['data_nascimento'] ?? null,
            sexo: $dados['sexo'] ?? 0,
            numeroIdentificacao: null,
            numeroMatricula: $dados['numero_matricula'],
        );
    }
}
```

- [ ] **Step 2: Escrever `CriarAlunoRequest`**

```php
<?php

namespace Modules\Aluno\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;

class CriarAlunoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('aluno.criar') ?? false;
    }

    public function rules(): array
    {
        return [
            'dados_pessoa_id' => [
                'nullable',
                'integer',
                'exists:dados_pessoas,id',
                Rule::unique('alunos', 'dados_pessoa_id'),
            ],
            'nome_completo' => ['required_without:dados_pessoa_id', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'telefone' => ['nullable', 'string', 'max:50'],
            'data_nascimento' => ['nullable', 'date'],
            'sexo' => ['nullable', 'integer', Rule::in([0, 1, 2])],
            'numero_identificacao' => [
                'required_without:dados_pessoa_id',
                'string',
                'max:100',
                Rule::unique('dados_pessoas', 'numero_identificacao'),
            ],
            'numero_matricula' => ['required', 'string', 'max:50', 'unique:alunos,numero_matricula'],
        ];
    }

    public function messages(): array
    {
        return [
            'dados_pessoa_id.exists' => 'A pessoa seleccionada não existe.',
            'dados_pessoa_id.unique' => 'Esta pessoa já está associada a um aluno.',
            'nome_completo.required_without' => 'O nome completo é obrigatório.',
            'numero_identificacao.required_without' => 'O número de identificação é obrigatório.',
            'numero_identificacao.unique' => 'Já existe uma pessoa com este número de identificação.',
            'numero_matricula.required' => 'O número de matrícula é obrigatório.',
            'numero_matricula.unique' => 'Já existe um aluno com este número de matrícula.',
        ];
    }
}
```

- [ ] **Step 3: Escrever `AtualizarAlunoRequest`**

```php
<?php

namespace Modules\Aluno\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;

class AtualizarAlunoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('aluno.editar') ?? false;
    }

    public function rules(): array
    {
        $aluno = $this->route('aluno');

        return [
            'nome_completo' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'telefone' => ['nullable', 'string', 'max:50'],
            'data_nascimento' => ['nullable', 'date'],
            'sexo' => ['nullable', 'integer', Rule::in([0, 1, 2])],
            'numero_matricula' => [
                'required',
                'string',
                'max:50',
                Rule::unique('alunos', 'numero_matricula')->ignore($aluno),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'nome_completo.required' => 'O nome completo é obrigatório.',
            'numero_matricula.required' => 'O número de matrícula é obrigatório.',
            'numero_matricula.unique' => 'Já existe um aluno com este número de matrícula.',
        ];
    }
}
```

- [ ] **Step 4: Escrever `AlterarEstadoAlunoRequest`** (idêntico ao de Curso)

```php
<?php

namespace Modules\Aluno\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rules\Enum;
use Modules\Core\Enums\Estado;

class AlterarEstadoAlunoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('aluno.editar') ?? false;
    }

    public function rules(): array
    {
        return [
            'estado' => ['required', new Enum(Estado::class)],
        ];
    }

    public function messages(): array
    {
        return [
            'estado.required' => 'O novo estado é obrigatório.',
        ];
    }
}
```

- [ ] **Step 5: Escrever teste das Actions (deve falhar — Actions ainda não existem)**

`Modules/Aluno/tests/Feature/CriarAlunoActionTest.php`:
```php
<?php

namespace Modules\Aluno\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Aluno\Actions\CriarAlunoAction;
use Modules\Aluno\DTO\AlunoDTO;
use Modules\Aluno\Models\Aluno;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Models\DadosPessoal;
use Tests\TestCase;

class CriarAlunoActionTest extends TestCase
{
    use RefreshDatabase;

    private function criarEstabelecimento(): Estabelecimento
    {
        return Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
    }

    public function test_cria_aluno_com_nova_dados_pessoa(): void
    {
        $this->criarEstabelecimento();

        $dto = new AlunoDTO(
            dadosPessoaId: null,
            nomeCompleto: 'Ana Silva',
            email: 'ana@example.com',
            telefone: '923000000',
            dataNascimento: '2010-05-01',
            sexo: DadosPessoal::SEXO_FEMININO,
            numeroIdentificacao: 'BI0001',
            numeroMatricula: '2026-0001',
        );

        $aluno = (new CriarAlunoAction())->executar($dto);

        $this->assertInstanceOf(Aluno::class, $aluno);
        $this->assertSame('2026-0001', $aluno->numero_matricula);
        $this->assertSame(Estabelecimento::current()->id, $aluno->estabelecimento_id);

        $pessoa = $aluno->dadosPessoa;
        $this->assertSame('Ana Silva', $pessoa->nome_completo);
        $this->assertSame('BI0001', $pessoa->numero_identificacao);
        $this->assertSame(DadosPessoal::TIPO_ALUNO, $pessoa->tipo_pessoa);
    }

    public function test_cria_aluno_reutilizando_dados_pessoa_existente(): void
    {
        $this->criarEstabelecimento();
        $pessoa = DadosPessoal::create([
            'nome_completo' => 'Bruno Costa',
            'numero_identificacao' => 'BI0002',
            'tipo_pessoa' => DadosPessoal::TIPO_ALUNO,
        ]);

        $dto = new AlunoDTO(
            dadosPessoaId: $pessoa->id,
            nomeCompleto: null,
            email: null,
            telefone: null,
            dataNascimento: null,
            sexo: 0,
            numeroIdentificacao: null,
            numeroMatricula: '2026-0002',
        );

        $aluno = (new CriarAlunoAction())->executar($dto);

        $this->assertSame($pessoa->id, $aluno->dados_pessoa_id);
        $this->assertSame(1, DadosPessoal::count());
    }
}
```

`Modules/Aluno/tests/Feature/AtualizarAlunoActionTest.php`:
```php
<?php

namespace Modules\Aluno\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Aluno\Actions\AtualizarAlunoAction;
use Modules\Aluno\DTO\AlunoDTO;
use Modules\Aluno\Models\Aluno;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Models\DadosPessoal;
use Tests\TestCase;

class AtualizarAlunoActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_actualiza_dados_pessoa_e_numero_matricula(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
        $pessoa = DadosPessoal::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);

        $dto = new AlunoDTO(
            dadosPessoaId: null,
            nomeCompleto: 'Ana Silva Santos',
            email: 'ana.santos@example.com',
            telefone: null,
            dataNascimento: null,
            sexo: 0,
            numeroIdentificacao: null,
            numeroMatricula: '2026-0099',
        );

        $actualizado = (new AtualizarAlunoAction())->executar($aluno, $dto);

        $this->assertSame('2026-0099', $actualizado->numero_matricula);
        $this->assertSame('Ana Silva Santos', $actualizado->dadosPessoa->fresh()->nome_completo);
        $this->assertSame('ana.santos@example.com', $actualizado->dadosPessoa->fresh()->email);
    }
}
```

`Modules/Aluno/tests/Feature/AlterarEstadoAlunoActionTest.php`:
```php
<?php

namespace Modules\Aluno\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Aluno\Actions\AlterarEstadoAlunoAction;
use Modules\Aluno\Models\Aluno;
use Modules\Core\Enums\Estado;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Models\DadosPessoal;
use Tests\TestCase;

class AlterarEstadoAlunoActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_altera_estado_do_aluno(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
        $pessoa = DadosPessoal::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);

        $actualizado = (new AlterarEstadoAlunoAction())->executar($aluno, Estado::INATIVO);

        $this->assertSame(Estado::INATIVO->value, $actualizado->estado);
        $this->assertSame('Inativo', $actualizado->estado_descricao);
    }
}
```

`Modules/Aluno/tests/Feature/CriarAlunoRequestTest.php`:
```php
<?php

namespace Modules\Aluno\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Modules\Aluno\Http\Requests\CriarAlunoRequest;
use Modules\Aluno\Models\Aluno;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Models\DadosPessoal;
use Tests\TestCase;

class CriarAlunoRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_falha_sem_nome_e_sem_dados_pessoa_id(): void
    {
        $validator = Validator::make(['numero_matricula' => '2026-0001'], (new CriarAlunoRequest())->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('nome_completo', $validator->errors()->toArray());
        $this->assertArrayHasKey('numero_identificacao', $validator->errors()->toArray());
    }

    public function test_falha_com_numero_matricula_duplicado(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
        $pessoa = DadosPessoal::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);
        Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);

        $validator = Validator::make([
            'nome_completo' => 'Bruno Costa',
            'numero_identificacao' => 'BI0002',
            'numero_matricula' => '2026-0001',
        ], (new CriarAlunoRequest())->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('numero_matricula', $validator->errors()->toArray());
    }

    public function test_passa_com_dados_pessoa_id_existente(): void
    {
        $pessoa = DadosPessoal::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);

        $validator = Validator::make([
            'dados_pessoa_id' => $pessoa->id,
            'numero_matricula' => '2026-0001',
        ], (new CriarAlunoRequest())->rules());

        $this->assertFalse($validator->fails());
    }
}
```

- [ ] **Step 6: Rodar os testes, confirmar que falham** (Actions não existem)

Run: `php artisan test Modules/Aluno`
Expected: FAIL

- [ ] **Step 7: Escrever `CriarAlunoAction`**

```php
<?php

namespace Modules\Aluno\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Aluno\DTO\AlunoDTO;
use Modules\Aluno\Models\Aluno;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Models\DadosPessoal;

class CriarAlunoAction
{
    public function executar(AlunoDTO $dto): Aluno
    {
        return DB::transaction(function () use ($dto) {
            $dadosPessoa = $dto->dadosPessoaId
                ? DadosPessoal::findOrFail($dto->dadosPessoaId)
                : DadosPessoal::create([
                    'nome_completo' => $dto->nomeCompleto,
                    'email' => $dto->email,
                    'telefone' => $dto->telefone,
                    'data_nascimento' => $dto->dataNascimento,
                    'sexo' => $dto->sexo,
                    'numero_identificacao' => $dto->numeroIdentificacao,
                    'tipo_pessoa' => DadosPessoal::TIPO_ALUNO,
                ]);

            return Aluno::create([
                'estabelecimento_id' => Estabelecimento::current()?->id,
                'dados_pessoa_id' => $dadosPessoa->id,
                'numero_matricula' => $dto->numeroMatricula,
            ]);
        });
    }
}
```

- [ ] **Step 8: Escrever `AtualizarAlunoAction`**

```php
<?php

namespace Modules\Aluno\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Aluno\DTO\AlunoDTO;
use Modules\Aluno\Models\Aluno;

class AtualizarAlunoAction
{
    public function executar(Aluno $aluno, AlunoDTO $dto): Aluno
    {
        return DB::transaction(function () use ($aluno, $dto) {
            $aluno->dadosPessoa->fill([
                'nome_completo' => $dto->nomeCompleto,
                'email' => $dto->email,
                'telefone' => $dto->telefone,
                'data_nascimento' => $dto->dataNascimento,
                'sexo' => $dto->sexo,
            ])->save();

            $aluno->fill(['numero_matricula' => $dto->numeroMatricula])->save();

            return $aluno->fresh();
        });
    }
}
```

- [ ] **Step 9: Escrever `AlterarEstadoAlunoAction`**

```php
<?php

namespace Modules\Aluno\Actions;

use Modules\Aluno\Models\Aluno;
use Modules\Core\Enums\Estado;

class AlterarEstadoAlunoAction
{
    public function executar(Aluno $aluno, Estado $novoEstado): Aluno
    {
        $aluno->estado = $novoEstado->value;
        $aluno->save();

        return $aluno->fresh();
    }
}
```

- [ ] **Step 10: Rodar os testes, confirmar que passam**

Run: `php artisan test Modules/Aluno`
Expected: PASS

- [ ] **Step 11: Commit**

```bash
git add Modules/Aluno/app/DTO Modules/Aluno/app/Http/Requests Modules/Aluno/app/Actions Modules/Aluno/tests
git commit -m "feat(aluno): DTO, Requests e Actions de criacao/edicao/estado"
```

---

### Task 5: Services `AlunoConsultaService` e `GestaoAlunoService`

**Files:**
- Create: `Modules/Aluno/app/Services/AlunoConsultaService.php`
- Create: `Modules/Aluno/app/Services/GestaoAlunoService.php`
- Test: `Modules/Aluno/tests/Feature/AlunoConsultaServiceTest.php`

**Interfaces:**
- Consumes: `CriarAlunoAction`, `AtualizarAlunoAction`, `AlterarEstadoAlunoAction`, `AlunoDTO` (Task 4).
- Produces: `AlunoConsultaService::listar(): Collection` (eager-load `dadosPessoa`, isolado por `Estabelecimento::current()`); `GestaoAlunoService::criar(CriarAlunoRequest): Aluno`, `::atualizar(Aluno, AtualizarAlunoRequest): Aluno`, `::alterarEstado(Aluno, Estado): Aluno`.

- [ ] **Step 1: Escrever teste do `AlunoConsultaService` (isolamento por estabelecimento)**

```php
<?php

namespace Modules\Aluno\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Aluno\Models\Aluno;
use Modules\Aluno\Services\AlunoConsultaService;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Usuario\Models\DadosPessoal;
use Tests\TestCase;

class AlunoConsultaServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_lista_apenas_alunos_do_estabelecimento_actual(): void
    {
        $actual = Estabelecimento::create(['nome' => 'Escola Actual', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
        $outra = Estabelecimento::create(['nome' => 'Outra Escola', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => false]);

        $pessoa1 = DadosPessoal::create(['nome_completo' => 'Ana', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);
        $pessoa2 = DadosPessoal::create(['nome_completo' => 'Bruno', 'numero_identificacao' => 'BI0002', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);

        Aluno::create(['estabelecimento_id' => $actual->id, 'dados_pessoa_id' => $pessoa1->id, 'numero_matricula' => '2026-0001']);
        Aluno::create(['estabelecimento_id' => $outra->id, 'dados_pessoa_id' => $pessoa2->id, 'numero_matricula' => '2026-0002']);

        $alunos = (new AlunoConsultaService())->listar();

        $this->assertCount(1, $alunos);
        $this->assertSame('2026-0001', $alunos->first()->numero_matricula);
    }
}
```

- [ ] **Step 2: Rodar, confirmar que falha**

Run: `php artisan test --filter=AlunoConsultaServiceTest`
Expected: FAIL

- [ ] **Step 3: Escrever `AlunoConsultaService`**

```php
<?php

namespace Modules\Aluno\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Aluno\Models\Aluno;
use Modules\Estabelecimento\Models\Estabelecimento;

class AlunoConsultaService
{
    public function listar(): Collection
    {
        return Aluno::with('dadosPessoa')
            ->where('estabelecimento_id', Estabelecimento::current()?->id)
            ->orderBy('numero_matricula')
            ->get();
    }
}
```

- [ ] **Step 4: Escrever `GestaoAlunoService`**

```php
<?php

namespace Modules\Aluno\Services;

use Modules\Aluno\Actions\AlterarEstadoAlunoAction;
use Modules\Aluno\Actions\AtualizarAlunoAction;
use Modules\Aluno\Actions\CriarAlunoAction;
use Modules\Aluno\DTO\AlunoDTO;
use Modules\Aluno\Http\Requests\AtualizarAlunoRequest;
use Modules\Aluno\Http\Requests\CriarAlunoRequest;
use Modules\Aluno\Models\Aluno;
use Modules\Core\Enums\Estado;

class GestaoAlunoService
{
    public function __construct(
        private CriarAlunoAction $criarAluno,
        private AtualizarAlunoAction $atualizarAluno,
        private AlterarEstadoAlunoAction $alterarEstadoAluno,
    ) {
    }

    public function criar(CriarAlunoRequest $request): Aluno
    {
        return $this->criarAluno->executar(AlunoDTO::fromCriarRequest($request));
    }

    public function atualizar(Aluno $aluno, AtualizarAlunoRequest $request): Aluno
    {
        return $this->atualizarAluno->executar($aluno, AlunoDTO::fromAtualizarRequest($request));
    }

    public function alterarEstado(Aluno $aluno, Estado $novoEstado): Aluno
    {
        return $this->alterarEstadoAluno->executar($aluno, $novoEstado);
    }
}
```

- [ ] **Step 5: Rodar, confirmar que passa**

Run: `php artisan test --filter=AlunoConsultaServiceTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add Modules/Aluno/app/Services Modules/Aluno/tests/Feature/AlunoConsultaServiceTest.php
git commit -m "feat(aluno): services de consulta e gestao"
```

---

### Task 6: Controller, rotas, permissões (`RolePermissaoSeeder`) e autorização

**Files:**
- Create: `Modules/Aluno/app/Http/Controllers/AlunoController.php`
- Modify: `Modules/Aluno/routes/web.php`
- Modify: `Modules/Permissao/database/seeders/RolePermissaoSeeder.php`
- Test: `Modules/Aluno/tests/Feature/AlunoAutorizacaoTest.php`
- Test: `Modules/Aluno/tests/Feature/AlunoHttpTest.php`

**Interfaces:**
- Consumes: `GestaoAlunoService`, `AlunoConsultaService` (Task 5).
- Produces: rotas nomeadas `alunos.index|store|show|update|alterar-estado`, abilities `aluno.ver|criar|editar`.

- [ ] **Step 1: Adicionar entrada de `Modulo::ALUNO` ao `RolePermissaoSeeder`**

Em `Modules/Permissao/database/seeders/RolePermissaoSeeder.php`, dentro do array de `Perfil::ADMIN_ESCOLA->value`, logo a seguir a `Modulo::DISCIPLINA->value => ['ver', 'criar', 'editar'],`, acrescentar:

```php
Modulo::ALUNO->value => ['ver', 'criar', 'editar'],
```

- [ ] **Step 2: Escrever `AlunoAutorizacaoTest`** (copiar `CursoAutorizacaoTest`, trocando `curso` por `aluno`)

```php
<?php

namespace Modules\Aluno\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class AlunoAutorizacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    public function test_admin_escola_tem_permissao_em_aluno(): void
    {
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);

        foreach (['ver', 'criar', 'editar'] as $acao) {
            $this->assertTrue(Gate::forUser($staff)->allows("aluno.{$acao}"), "aluno.{$acao}");
        }
    }

    public function test_professor_nao_tem_permissao_em_aluno(): void
    {
        $professor = User::create(['name' => 'Professor', 'email' => 'professor@example.com', 'password' => Hash::make('x')]);
        $professor->roles()->syncWithoutDetaching([Role::where('nome', Perfil::PROFESSOR->value)->first()->id]);

        $this->assertFalse(Gate::forUser($professor)->allows('aluno.ver'));
    }
}
```

- [ ] **Step 3: Rodar, confirmar que passa** (não depende do Controller, só do seeder)

Run: `php artisan test --filter=AlunoAutorizacaoTest`
Expected: PASS

- [ ] **Step 4: Escrever `AlunoController`**

```php
<?php

namespace Modules\Aluno\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Modules\Aluno\Http\Requests\AlterarEstadoAlunoRequest;
use Modules\Aluno\Http\Requests\AtualizarAlunoRequest;
use Modules\Aluno\Http\Requests\CriarAlunoRequest;
use Modules\Aluno\Models\Aluno;
use Modules\Aluno\Services\AlunoConsultaService;
use Modules\Aluno\Services\GestaoAlunoService;
use Modules\Core\Enums\Estado;

class AlunoController extends Controller
{
    public function __construct(
        private GestaoAlunoService $service,
        private AlunoConsultaService $consulta,
    ) {
    }

    public function index()
    {
        $this->authorize('aluno.ver');

        return Inertia::render('Aluno/Index', [
            'alunos' => $this->consulta->listar(),
        ]);
    }

    public function show(Aluno $aluno)
    {
        $this->authorize('aluno.ver');

        return Inertia::render('Aluno/Show', [
            'aluno' => $aluno->load('dadosPessoa'),
        ]);
    }

    public function store(CriarAlunoRequest $request)
    {
        $this->authorize('aluno.criar');

        $this->service->criar($request);

        return redirect()->back()->with('success', 'Aluno criado com sucesso.');
    }

    public function update(AtualizarAlunoRequest $request, Aluno $aluno)
    {
        $this->authorize('aluno.editar');

        $this->service->atualizar($aluno, $request);

        return redirect()->back()->with('success', 'Aluno atualizado com sucesso.');
    }

    public function alterarEstado(AlterarEstadoAlunoRequest $request, Aluno $aluno)
    {
        $this->authorize('aluno.editar');

        $this->service->alterarEstado($aluno, Estado::from((int) $request->validated('estado')));

        return redirect()->back()->with('success', 'Estado do aluno atualizado com sucesso.');
    }
}
```

- [ ] **Step 5: Escrever `Modules/Aluno/routes/web.php`**

```php
<?php

use Illuminate\Support\Facades\Route;
use Modules\Aluno\Http\Controllers\AlunoController;

Route::middleware(['auth'])->prefix('alunos')->name('alunos.')->group(function () {
    Route::get('/', [AlunoController::class, 'index'])->middleware('can:aluno.ver')->name('index');
    Route::post('/', [AlunoController::class, 'store'])->middleware('can:aluno.criar')->name('store');
    Route::get('/{aluno}', [AlunoController::class, 'show'])->middleware('can:aluno.ver')->name('show');
    Route::put('/{aluno}', [AlunoController::class, 'update'])->middleware('can:aluno.editar')->name('update');
    Route::patch('/{aluno}/estado', [AlunoController::class, 'alterarEstado'])->middleware('can:aluno.editar')->name('alterar-estado');
});
```

- [ ] **Step 6: Escrever `AlunoHttpTest`** (copiar estrutura de `CursoHttpTest`, adaptando campos)

```php
<?php

namespace Modules\Aluno\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Aluno\Models\Aluno;
use Modules\Core\Enums\Estado;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\DadosPessoal;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class AlunoHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function actingAsStaff(): User
    {
        $staff = User::firstOrCreate(
            ['email' => 'staff@example.com'],
            ['name' => 'Staff', 'password' => Hash::make('segredo123')],
        );
        $staff->roles()->syncWithoutDetaching([Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id]);
        $this->actingAs($staff);

        return $staff;
    }

    private function actingAsProfessor(): User
    {
        $professor = User::firstOrCreate(
            ['email' => 'professor@example.com'],
            ['name' => 'Professor', 'password' => Hash::make('segredo123')],
        );
        $professor->roles()->syncWithoutDetaching([Role::where('nome', Perfil::PROFESSOR->value)->first()->id]);
        $this->actingAs($professor);

        return $professor;
    }

    private function criarEstabelecimento(bool $activo = true): Estabelecimento
    {
        return Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => $activo]);
    }

    public function test_cria_aluno_via_http_infere_estabelecimento_actual_e_regista_autoria(): void
    {
        $staff = $this->actingAsStaff();
        $this->criarEstabelecimento();

        $this->post(route('alunos.store'), [
            'nome_completo' => 'Ana Silva',
            'numero_identificacao' => 'BI0001',
            'numero_matricula' => '2026-0001',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $aluno = Aluno::firstWhere('numero_matricula', '2026-0001');
        $this->assertNotNull($aluno);
        $this->assertSame($staff->id, $aluno->criado_por);
        $this->assertSame(Estabelecimento::current()->id, $aluno->estabelecimento_id);
        $this->assertSame('Ana Silva', $aluno->dadosPessoa->nome_completo);
    }

    public function test_actualiza_aluno_via_http(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $pessoa = DadosPessoal::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);

        $this->put(route('alunos.update', $aluno), [
            'nome_completo' => 'Ana Silva Santos',
            'numero_matricula' => '2026-0001',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('Ana Silva Santos', $aluno->dadosPessoa->fresh()->nome_completo);
    }

    public function test_altera_estado_via_http(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $pessoa = DadosPessoal::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);

        $this->patch(route('alunos.alterar-estado', $aluno), ['estado' => Estado::INATIVO->value])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(Estado::INATIVO->value, $aluno->fresh()->estado);
    }

    public function test_index_expoe_apenas_alunos_do_estabelecimento_actual(): void
    {
        $this->actingAsStaff();
        $actual = $this->criarEstabelecimento();
        $pessoa1 = DadosPessoal::create(['nome_completo' => 'Ana', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);
        Aluno::create(['estabelecimento_id' => $actual->id, 'dados_pessoa_id' => $pessoa1->id, 'numero_matricula' => '2026-0001']);

        $outra = $this->criarEstabelecimento(false);
        $pessoa2 = DadosPessoal::create(['nome_completo' => 'Bruno', 'numero_identificacao' => 'BI0002', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);
        Aluno::create(['estabelecimento_id' => $outra->id, 'dados_pessoa_id' => $pessoa2->id, 'numero_matricula' => '2026-0002']);

        $this->get(route('alunos.index'))->assertInertia(fn (Assert $page) => $page
            ->component('Aluno/Index')
            ->has('alunos', 1)
            ->where('alunos.0.numero_matricula', '2026-0001')
        );
    }

    public function test_show_expoe_o_aluno(): void
    {
        $this->actingAsStaff();
        $estabelecimento = $this->criarEstabelecimento();
        $pessoa = DadosPessoal::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);

        $this->get(route('alunos.show', $aluno))->assertInertia(fn (Assert $page) => $page
            ->component('Aluno/Show')
            ->where('aluno.numero_matricula', '2026-0001')
        );
    }

    public function test_professor_recebe_403_em_todas_as_rotas_de_escrita(): void
    {
        $this->actingAsProfessor();
        $estabelecimento = $this->criarEstabelecimento();
        $pessoa = DadosPessoal::create(['nome_completo' => 'Ana Silva', 'numero_identificacao' => 'BI0001', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);
        $aluno = Aluno::create(['estabelecimento_id' => $estabelecimento->id, 'dados_pessoa_id' => $pessoa->id, 'numero_matricula' => '2026-0001']);

        $this->post(route('alunos.store'), ['nome_completo' => 'X', 'numero_identificacao' => 'BI9999', 'numero_matricula' => '2026-0002'])->assertForbidden();
        $this->put(route('alunos.update', $aluno), ['nome_completo' => 'Y', 'numero_matricula' => '2026-0001'])->assertForbidden();
        $this->patch(route('alunos.alterar-estado', $aluno), ['estado' => 0])->assertForbidden();
    }

    public function test_professor_recebe_403_ao_listar(): void
    {
        $this->actingAsProfessor();

        $this->get(route('alunos.index'))->assertForbidden();
    }
}
```

- [ ] **Step 7: Rodar toda a suite do módulo, confirmar que passa**

Run: `php artisan test Modules/Aluno Modules/Permissao`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add Modules/Aluno/app/Http/Controllers Modules/Aluno/routes/web.php \
        Modules/Permissao/database/seeders/RolePermissaoSeeder.php \
        Modules/Aluno/tests/Feature/AlunoAutorizacaoTest.php Modules/Aluno/tests/Feature/AlunoHttpTest.php
git commit -m "feat(aluno): controller, rotas e permissoes"
```

---

### Task 7: Integração com `User` — testes de ponta-a-ponta (aluno sem/​com conta, role ALUNO, fonte única de `numero_matricula`)

**Files:**
- Test: `Modules/Aluno/tests/Feature/AlunoUsuarioIntegracaoTest.php`

**Interfaces:**
- Consumes: `Modules\Usuario\Actions\UsuarioAction`, `Modules\Usuario\DTO\UsuarioDTO`, `Modules\Usuario\Enums\TipoLogin`, `Modules\Permissao\Enums\Perfil` (todos já existentes — reaproveitados sem alteração de assinatura pública, excepto o `numeroMatricula` opcional da Task 1).

- [ ] **Step 1: Escrever o teste de integração completo**

```php
<?php

namespace Modules\Aluno\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Aluno\Models\Aluno;
use Modules\Core\Enums\Estado;
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Usuario\Actions\UsuarioAction;
use Modules\Usuario\DTO\UsuarioDTO;
use Modules\Usuario\Enums\TipoLogin;
use Modules\Usuario\Models\DadosPessoal;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class AlunoUsuarioIntegracaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function criarAluno(string $numeroIdentificacao, string $numeroMatricula): Aluno
    {
        $estabelecimento = Estabelecimento::current() ?? Estabelecimento::create([
            'nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true,
        ]);
        $pessoa = DadosPessoal::create([
            'nome_completo' => 'Ana Silva',
            'numero_identificacao' => $numeroIdentificacao,
            'tipo_pessoa' => DadosPessoal::TIPO_ALUNO,
        ]);

        return Aluno::create([
            'estabelecimento_id' => $estabelecimento->id,
            'dados_pessoa_id' => $pessoa->id,
            'numero_matricula' => $numeroMatricula,
        ]);
    }

    public function test_aluno_pode_existir_sem_user(): void
    {
        $aluno = $this->criarAluno('BI0001', '2026-0001');

        $this->assertSame(0, User::where('dados_pessoa_id', $aluno->dados_pessoa_id)->count());
    }

    public function test_aluno_pode_receber_user_reaproveitando_o_fluxo_do_modulo_usuario(): void
    {
        $aluno = $this->criarAluno('BI0001', '2026-0001');

        $dto = new UsuarioDTO(
            name: 'Ana Silva',
            password: 'segredo123',
            perfil: Perfil::ALUNO,
            tipoLogin: TipoLogin::MATRICULA,
            dados_pessoa_id: $aluno->dados_pessoa_id,
            numeroMatricula: $aluno->numero_matricula,
        );

        $user = app(UsuarioAction::class)->criar($dto);

        $this->assertSame($aluno->dados_pessoa_id, $user->dados_pessoa_id);
        $this->assertSame($aluno->numero_matricula, $user->numero_matricula, 'numero_matricula do User deve ser o mesmo do Aluno — fonte unica');
        $this->assertTrue($user->roles()->where('nome', Perfil::ALUNO->value)->exists());
    }

    public function test_uma_pessoa_nao_pode_ter_duas_contas(): void
    {
        $aluno = $this->criarAluno('BI0001', '2026-0001');

        $dto = fn () => new UsuarioDTO(
            name: 'Ana Silva',
            password: 'segredo123',
            perfil: Perfil::ALUNO,
            tipoLogin: TipoLogin::MATRICULA,
            dados_pessoa_id: $aluno->dados_pessoa_id,
            numeroMatricula: $aluno->numero_matricula,
        );

        app(UsuarioAction::class)->criar($dto());

        $this->expectException(\Illuminate\Database\QueryException::class);
        app(UsuarioAction::class)->criar(new UsuarioDTO(
            name: 'Ana Silva 2',
            password: 'segredo123',
            perfil: Perfil::ALUNO,
            tipoLogin: TipoLogin::MATRICULA,
            dados_pessoa_id: $aluno->dados_pessoa_id,
            numeroMatricula: '2026-9999',
        ));
    }

    public function test_criar_user_para_aluno_nao_cria_outro_aluno(): void
    {
        $aluno = $this->criarAluno('BI0001', '2026-0001');

        app(UsuarioAction::class)->criar(new UsuarioDTO(
            name: 'Ana Silva',
            password: 'segredo123',
            perfil: Perfil::ALUNO,
            tipoLogin: TipoLogin::MATRICULA,
            dados_pessoa_id: $aluno->dados_pessoa_id,
            numeroMatricula: $aluno->numero_matricula,
        ));

        $this->assertSame(1, Aluno::count());
    }

    public function test_user_criado_primeiro_pode_depois_ser_associado_a_um_aluno_da_mesma_pessoa(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => TipoEstabelecimentoEnum::PUBLICO->value, 'is_active' => true]);
        $pessoa = DadosPessoal::create(['nome_completo' => 'Carlos Neto', 'numero_identificacao' => 'BI0003', 'tipo_pessoa' => DadosPessoal::TIPO_ALUNO]);

        $user = app(UsuarioAction::class)->criar(new UsuarioDTO(
            name: 'Carlos Neto',
            password: 'segredo123',
            perfil: Perfil::ALUNO,
            tipoLogin: TipoLogin::MATRICULA,
            dados_pessoa_id: $pessoa->id,
            numeroMatricula: '2026-0003',
        ));

        $aluno = Aluno::create([
            'estabelecimento_id' => $estabelecimento->id,
            'dados_pessoa_id' => $pessoa->id,
            'numero_matricula' => $user->numero_matricula,
        ]);

        $this->assertSame($pessoa->id, $aluno->dados_pessoa_id);
        $this->assertSame($pessoa->id, $user->dados_pessoa_id);
        $this->assertSame(1, DadosPessoal::count());
        $this->assertSame(1, User::count());
    }
}
```

- [ ] **Step 2: Rodar, confirmar que passa** (depende só de Tasks 1–3 já implementadas)

Run: `php artisan test --filter=AlunoUsuarioIntegracaoTest`
Expected: PASS

- [ ] **Step 3: Commit**

```bash
git add Modules/Aluno/tests/Feature/AlunoUsuarioIntegracaoTest.php
git commit -m "test(aluno): integracao com modulo Usuario (conta opcional, role ALUNO, fonte unica de matricula)"
```

---

### Task 8: Frontend Vue (Index, Show, FormModal, Estado.js, menu)

**Files:**
- Create: `Modules/Aluno/resources/js/Pages/Index.vue`
- Create: `Modules/Aluno/resources/js/Pages/Show.vue`
- Create: `Modules/Aluno/resources/js/Components/AlunoFormModal.vue`
- Create: `Modules/Aluno/resources/js/Components/Shared/EstadoBadge.vue`
- Create: `Modules/Aluno/resources/js/Models/Estado.js`
- Modify: `resources/js/Composables/useAcademicoMenu.js`

**Interfaces:**
- Consumes: rotas `alunos.*` (Task 6), props Inertia `alunos` (array com `dados_pessoa` carregado) e `aluno`.

- [ ] **Step 1: Criar `Models/Estado.js`** (idêntico ao de Curso)

```js
/**
 * Espelha Modules/Core/app/Enums/Estado.php — usado por Aluno, que
 * reutiliza o mesmo Estado binário Ativo/Inativo do backend.
 * Só para apresentação (labels, cor de badge); a autoridade é o backend.
 */

export const ESTADO = Object.freeze({ INATIVO: 0, ATIVO: 1 });

export const estadoBadgeClass = (estado) => {
    switch (estado) {
        case ESTADO.ATIVO: return 'badge-light-success';
        case ESTADO.INATIVO: return 'badge-light-secondary';
        default: return 'badge-light-secondary';
    }
};
```

- [ ] **Step 2: Criar `Components/Shared/EstadoBadge.vue`** (idêntico ao de Curso)

```vue
<script setup>
import { estadoBadgeClass } from '../../Models/Estado';

defineProps({
    estado: { type: Number, required: true },
    estadoDescricao: { type: String, required: true },
});
</script>

<template>
    <div class="badge fw-bold" :class="estadoBadgeClass(estado)">
        {{ estadoDescricao }}
    </div>
</template>
```

- [ ] **Step 3: Criar `Components/AlunoFormModal.vue`**

```vue
<script setup>
import { reactive, watch } from 'vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import { ESTADO } from '../Models/Estado';

const props = defineProps({
    show: { type: Boolean, default: false },
    aluno: { type: Object, default: null },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const SEXO_OPCOES = [
    { value: 0, label: 'Não especificado' },
    { value: 1, label: 'Masculino' },
    { value: 2, label: 'Feminino' },
];

const ESTADO_OPCOES = [
    { value: ESTADO.ATIVO, label: 'Ativo' },
    { value: ESTADO.INATIVO, label: 'Inativo' },
];

const form = reactive({
    nome_completo: '',
    email: '',
    telefone: '',
    data_nascimento: '',
    sexo: 0,
    numero_identificacao: '',
    numero_matricula: '',
    estado: ESTADO.ATIVO,
});

watch(() => props.show, (show) => {
    if (!show) return;
    const pessoa = props.aluno?.dados_pessoa ?? {};
    form.nome_completo = pessoa.nome_completo ?? '';
    form.email = pessoa.email ?? '';
    form.telefone = pessoa.telefone ?? '';
    form.data_nascimento = pessoa.data_nascimento ?? '';
    form.sexo = pessoa.sexo ?? 0;
    form.numero_identificacao = pessoa.numero_identificacao ?? '';
    form.numero_matricula = props.aluno?.numero_matricula ?? '';
    form.estado = props.aluno?.estado ?? ESTADO.ATIVO;
});

function submeter() {
    const payload = { ...form };
    if (props.aluno) {
        delete payload.numero_identificacao;
    } else {
        delete payload.estado;
    }
    emit('submit', payload);
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5);" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-5">{{ aluno ? 'Editar Aluno' : 'Novo Aluno' }}</h3>
                <form @submit.prevent="submeter">
                    <div class="row">
                        <div class="col-md-8 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Nome completo</label>
                            <input v-model="form.nome_completo" type="text" class="form-control form-control-solid" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.nome_completo">{{ errors.nome_completo }}</div>
                        </div>
                        <div class="col-md-4 fv-row mb-7">
                            <label class="fw-semibold fs-6 mb-2">Sexo</label>
                            <SelectSolid v-model="form.sexo" :options="SEXO_OPCOES" />
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="fw-semibold fs-6 mb-2">Email</label>
                            <input v-model="form.email" type="email" class="form-control form-control-solid" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.email">{{ errors.email }}</div>
                        </div>
                        <div class="col-md-6 fv-row mb-7">
                            <label class="fw-semibold fs-6 mb-2">Telefone</label>
                            <input v-model="form.telefone" type="text" class="form-control form-control-solid" />
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="fw-semibold fs-6 mb-2">Data de nascimento</label>
                            <input v-model="form.data_nascimento" type="date" class="form-control form-control-solid" />
                        </div>
                        <div v-if="!aluno" class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Número de identificação</label>
                            <input v-model="form.numero_identificacao" type="text" class="form-control form-control-solid" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.numero_identificacao">{{ errors.numero_identificacao }}</div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Número de matrícula</label>
                            <input v-model="form.numero_matricula" type="text" class="form-control form-control-solid" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.numero_matricula">{{ errors.numero_matricula }}</div>
                        </div>
                        <div v-if="aluno" class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Estado</label>
                            <SelectSolid v-model="form.estado" :options="ESTADO_OPCOES" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.estado">{{ errors.estado }}</div>
                        </div>
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

- [ ] **Step 4: Criar `Pages/Index.vue`**

```vue
<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import EstadoBadge from '../Components/Shared/EstadoBadge.vue';
import AlunoFormModal from '../Components/AlunoFormModal.vue';
import { ESTADO } from '../Models/Estado';

defineProps({
    alunos: { type: Array, required: true },
});
defineOptions({ layout: AppLayout });

const modalAberto = ref(false);
const alunoEmEdicao = ref(null);
const processing = ref(false);
const errors = ref({});

function abrirCriacao() {
    alunoEmEdicao.value = null;
    errors.value = {};
    modalAberto.value = true;
}

function abrirEdicao(aluno) {
    alunoEmEdicao.value = aluno;
    errors.value = {};
    modalAberto.value = true;
}

function fecharModal() {
    modalAberto.value = false;
}

function guardar(payload) {
    processing.value = true;
    errors.value = {};

    const url = alunoEmEdicao.value ? `/alunos/${alunoEmEdicao.value.id}` : '/alunos';
    const metodo = alunoEmEdicao.value ? 'put' : 'post';

    router[metodo](url, payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(alunoEmEdicao.value ? 'Aluno atualizado com sucesso.' : 'Aluno criado com sucesso.');
            fecharModal();
        },
        onError: (erros) => {
            errors.value = erros;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}

const alunoParaAlterarEstado = ref(null);
const novoEstado = ref(null);
const alterandoEstado = ref(false);

function pedirAlteracaoEstado(aluno, estado) {
    alunoParaAlterarEstado.value = aluno;
    novoEstado.value = estado;
}

function cancelarAlteracaoEstado() {
    alunoParaAlterarEstado.value = null;
    novoEstado.value = null;
}

function confirmarAlteracaoEstado() {
    alterandoEstado.value = true;
    router.patch(`/alunos/${alunoParaAlterarEstado.value.id}/estado`, { estado: novoEstado.value }, {
        preserveScroll: true,
        onSuccess: () => toast.success('Estado do aluno atualizado com sucesso.'),
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            alterandoEstado.value = false;
            alunoParaAlterarEstado.value = null;
            novoEstado.value = null;
        },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="d-flex justify-content-between align-items-center mb-6">
            <h1 class="fs-2 fw-bold">Alunos</h1>
            <button v-if="can('aluno.criar')" class="btn btn-primary" @click="abrirCriacao">Novo Aluno</button>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-125px">Matrícula</th>
                            <th class="min-w-200px">Nome</th>
                            <th class="min-w-125px">Estado</th>
                            <th class="text-end min-w-125px">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="alunos.length === 0">
                            <td colspan="4" class="text-center text-muted py-6">Nenhum aluno criado.</td>
                        </tr>
                        <tr v-for="aluno in alunos" :key="aluno.id">
                            <td>
                                <a :href="`/alunos/${aluno.id}`" class="text-gray-800 text-hover-primary">{{ aluno.numero_matricula }}</a>
                            </td>
                            <td>{{ aluno.dados_pessoa?.nome_completo }}</td>
                            <td>
                                <EstadoBadge :estado="aluno.estado" :estado-descricao="aluno.estado_descricao" />
                            </td>
                            <td class="text-end">
                                <a href="#" class="btn btn-light btn-active-light-primary btn-flex btn-center btn-sm" data-kt-menu-trigger="click" data-kt-menu-placement="bottom-end">
                                    Ações
                                    <i class="ki-duotone ki-down fs-5 ms-1"></i>
                                </a>
                                <div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-600 menu-state-bg-light-primary fw-semibold fs-7 w-200px py-4" data-kt-menu="true">
                                    <div v-if="can('aluno.editar')" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="abrirEdicao(aluno)">
                                            <AcaoIcone acao="editar" class="me-2" />
                                            Editar
                                        </a>
                                    </div>
                                    <div v-if="can('aluno.editar') && aluno.estado !== ESTADO.ATIVO" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="pedirAlteracaoEstado(aluno, ESTADO.ATIVO)">
                                            <AcaoIcone acao="ativar" class="me-2" />
                                            Ativar
                                        </a>
                                    </div>
                                    <div v-if="can('aluno.editar') && aluno.estado === ESTADO.ATIVO" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="pedirAlteracaoEstado(aluno, ESTADO.INATIVO)">
                                            <AcaoIcone acao="desativar" class="me-2" />
                                            Desativar
                                        </a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <AlunoFormModal
            :show="modalAberto"
            :aluno="alunoEmEdicao"
            :processing="processing"
            :errors="errors"
            @submit="guardar"
            @cancelar="fecharModal"
        />

        <ConfirmModal
            :show="!!alunoParaAlterarEstado"
            titulo="Alterar estado"
            :mensagem="`Alterar o estado do aluno ${alunoParaAlterarEstado?.dados_pessoa?.nome_completo} para '${novoEstado === ESTADO.ATIVO ? 'Ativo' : 'Inativo'}'?`"
            texto-confirmar="Confirmar"
            :processando="alterandoEstado"
            @confirmar="confirmarAlteracaoEstado"
            @cancelar="cancelarAlteracaoEstado"
        />
    </div>
</template>
```

- [ ] **Step 5: Criar `Pages/Show.vue`**

```vue
<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import BotaoVoltar from '@/Components/Shared/BotaoVoltar.vue';
import EstadoBadge from '../Components/Shared/EstadoBadge.vue';
import AlunoFormModal from '../Components/AlunoFormModal.vue';

const props = defineProps({
    aluno: { type: Object, required: true },
});
defineOptions({ layout: AppLayout });

const modalAberto = ref(false);
const processing = ref(false);
const errors = ref({});

function abrirEdicao() {
    errors.value = {};
    modalAberto.value = true;
}

function fecharModal() {
    modalAberto.value = false;
}

function guardar(payload) {
    processing.value = true;
    errors.value = {};
    router.put(`/alunos/${props.aluno.id}`, payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Aluno atualizado com sucesso.');
            fecharModal();
        },
        onError: (erros) => {
            errors.value = erros;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <BotaoVoltar href="/alunos" class="mb-4" />

        <div class="d-flex justify-content-between align-items-center mb-6">
            <div>
                <h1 class="fs-2 fw-bold mb-1">{{ aluno.dados_pessoa?.nome_completo }}</h1>
                <span class="text-muted">Matrícula: {{ aluno.numero_matricula }}</span>
            </div>
            <button v-if="can('aluno.editar')" class="btn btn-primary" @click="abrirEdicao">Editar</button>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="row mb-4">
                    <div class="col-md-3 fw-bold text-muted">Estado</div>
                    <div class="col-md-9">
                        <EstadoBadge :estado="aluno.estado" :estado-descricao="aluno.estado_descricao" />
                    </div>
                </div>
                <div class="row mb-4">
                    <div class="col-md-3 fw-bold text-muted">Email</div>
                    <div class="col-md-9">{{ aluno.dados_pessoa?.email ?? '—' }}</div>
                </div>
                <div class="row mb-4">
                    <div class="col-md-3 fw-bold text-muted">Telefone</div>
                    <div class="col-md-9">{{ aluno.dados_pessoa?.telefone ?? '—' }}</div>
                </div>
                <div class="row mb-4">
                    <div class="col-md-3 fw-bold text-muted">Data de nascimento</div>
                    <div class="col-md-9">{{ aluno.dados_pessoa?.data_nascimento ?? '—' }}</div>
                </div>
                <div class="row mb-4">
                    <div class="col-md-3 fw-bold text-muted">Número de identificação</div>
                    <div class="col-md-9">{{ aluno.dados_pessoa?.numero_identificacao ?? '—' }}</div>
                </div>
            </div>
        </div>

        <AlunoFormModal
            :show="modalAberto"
            :aluno="aluno"
            :processing="processing"
            :errors="errors"
            @submit="guardar"
            @cancelar="fecharModal"
        />
    </div>
</template>
```

- [ ] **Step 6: Actualizar `useAcademicoMenu.js`**

Trocar `{ href: '#', label: 'Alunos' },` por:

```js
{ href: '/alunos', label: 'Alunos', permissao: 'aluno.ver' },
```

- [ ] **Step 7: Rodar o build do frontend**

Run: `npm run build`
Expected: build sem erros.

- [ ] **Step 8: Commit**

```bash
git add Modules/Aluno/resources resources/js/Composables/useAcademicoMenu.js
git commit -m "feat(aluno): paginas e componentes Vue, item de menu"
```

---

### Task 9: Verificação final

**Files:** nenhum novo — só verificação.

- [ ] **Step 1: Rodar a suite PHP completa**

Run: `php artisan test`
Expected: todos os testes a passar (nenhuma regressão em Usuario, Autenticacao, Permissao, Curso, Disciplina, etc.).

- [ ] **Step 2: Rodar o build do frontend**

Run: `npm run build`
Expected: sucesso.

- [ ] **Step 3: Confirmar critérios de aceitação da spec** (checklist manual rápida contra a secção 16 da issue) — módulo Aluno criado, tabela `alunos` com os campos correctos, sem `codigo`/`user_id`, 1:1 DadosPessoa↔Aluno, `numero_matricula` único, User opcional, role ALUNO, isolamento por estabelecimento, permissões integradas, testes a passar, nenhuma funcionalidade de Encarregado/Matrícula/Turma implementada.

- [ ] **Step 4: Commit final se houver ajustes pendentes** (normalmente nenhum — esta task é só verificação).
