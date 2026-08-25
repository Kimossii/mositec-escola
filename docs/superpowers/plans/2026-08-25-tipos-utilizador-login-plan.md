# Tipos de Utilizador e Login Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implementar os 5 perfis reais de utilizador (Admin escola, Secretário, Professor, Aluno, Encarregado), a mecânica de login com duas vias (email vs matrícula, detetada pelo formato do identificador) e a ligação encarregado↔aluno, substituindo o fluxo de criação de utilizador hoje limitado a name/email/password.

**Architecture:** Um enum `TipoLogin` (Usuario) e `Perfil` (Permissao) formalizam os valores hoje guardados como inteiros crus. `GeradorMatriculaService` gera matrículas `ANO-SEQUENCIAL` de forma atómica via `upsert` + `lockForUpdate`. A relação `encarregados_alunos` (muitos-para-muitos entre `users`) substitui a necessidade de credenciais partilhadas. O login deteta o tipo de credencial pelo formato do identificador (`@` → email, senão → matrícula) sem nenhum código específico por perfil. `UsuarioAction::criar()` orquestra tudo dentro de uma transação: cria o `User`, gera a matrícula se aplicável, e liga aos educandos se `matriculas_educandos` vier preenchido.

**Tech Stack:** Laravel 11 (Modules: Usuario, Autenticacao, Permissao), Eloquent, PHPUnit (`php artisan test`), Vue 3 + Inertia.js, RefreshDatabase + SQLite in-memory para testes.

**Spec:** `docs/superpowers/specs/2026-08-25-tipos-utilizador-login-design.md`

## Global Constraints

- Sem alterações à integração stancl/tenancy, ao ator Admin SaaS, ao módulo Ano Letivo, ao Sistema de Permissões (perfil × módulo × ação) ou à UI de pesquisa de alunos por nome — tudo isso fica fora deste plano, conforme a spec.
- A matrícula usa o ano civil (`now()->year`) como substituto interino do Ano Letivo.
- A ligação encarregado→educando na UI usa apenas matrícula (texto livre + botão "Adicionar"), não pesquisa por nome — simplificação deliberada da spec para este plano; pesquisa por nome fica para uma iteração futura.
- Atribuição de `role_id` (`user_roles`) durante o cadastro não é implementada neste plano — já não existe hoje para nenhum tipo de utilizador (nem Administrador), fica para o subprojeto do Sistema de Permissões.
- Seguir o padrão de imports (`use X;` no topo, nunca FQN inline) já estabelecido no projeto.
- Todas as migrations, models e testes seguem os nomes/convenções em português já usados no módulo (ex: `criado_por`, `estado`).

---

## Estrutura de ficheiros

**Backend — novos:**
- `Modules/Usuario/app/Enums/TipoLogin.php`
- `Modules/Permissao/app/Enums/Perfil.php`
- `Modules/Usuario/database/migrations/2026_08_25_000001_create_matricula_sequencias_table.php`
- `Modules/Usuario/app/Models/MatriculaSequencia.php`
- `Modules/Usuario/app/Services/GeradorMatriculaService.php`
- `Modules/Usuario/database/migrations/2026_08_25_000002_create_encarregados_alunos_table.php`
- `Modules/Usuario/app/Models/EncarregadoAluno.php`

**Backend — modificados:**
- `Modules/Usuario/app/Models/User.php`
- `Modules/Permissao/database/seeders/RoleSeeder.php`
- `Modules/Autenticacao/app/Http/Requests/LoginRequest.php`
- `Modules/Autenticacao/app/Http/Controllers/AutenticacaoController.php`
- `Modules/Autenticacao/app/Service/GestaoAutenticacao.php`
- `Modules/Usuario/app/Http/Requests/CriarUsuarioRequest.php`
- `Modules/Usuario/app/DTO/UsuarioDTO.php`
- `Modules/Usuario/app/Actions/UsuarioAction.php`

**Frontend — novos:**
- `Modules/Usuario/resources/js/Forms/Encarregados/EncarregadoForm.vue`

**Frontend — modificados:**
- `Modules/Autenticacao/resources/js/Pages/Login.vue`
- `Modules/Usuario/resources/js/Components/UsuarioFormFields.vue`
- `Modules/Usuario/resources/js/Forms/Alunos/AlunoForm.vue`
- `Modules/Usuario/resources/js/Forms/Professores/ProfessorForm.vue`
- `Modules/Usuario/resources/js/Forms/Funcionarios/FuncionarioForm.vue`
- `Modules/Usuario/resources/js/Forms/Administradores/AdministradorForm.vue`
- `Modules/Usuario/resources/js/Forms/UsuarioForm.vue`

**Testes — novos:**
- `Modules/Usuario/tests/Unit/TipoLoginTest.php`
- `Modules/Usuario/tests/Unit/GeradorMatriculaServiceTest.php`
- `Modules/Usuario/tests/Feature/EncarregadoAlunoRelationTest.php`
- `Modules/Usuario/tests/Feature/CriarUsuarioTest.php`
- `Modules/Permissao/tests/Feature/RoleSeederTest.php` (a pasta `Modules/Permissao/tests` ainda não existe)

**Testes — modificados:**
- `Modules/Autenticacao/tests/Feature/LoginTest.php`

---

### Task 1: Enums TipoLogin e Perfil

**Files:**
- Create: `Modules/Usuario/app/Enums/TipoLogin.php`
- Create: `Modules/Permissao/app/Enums/Perfil.php`
- Test: `Modules/Usuario/tests/Unit/TipoLoginTest.php`

**Interfaces:**
- Produces: `TipoLogin::EMAIL`, `TipoLogin::MATRICULA`, `TipoLogin::fromLabel(string): self` — usados pelas Tasks 3, 5, 7. `Perfil::cases()`, `Perfil::label(): string` — usados pela Task 4.

- [ ] **Step 1: Escrever o teste do enum TipoLogin**

```php
<?php

namespace Modules\Usuario\Tests\Unit;

use Modules\Usuario\Enums\TipoLogin;
use Tests\TestCase;

class TipoLoginTest extends TestCase
{
    public function test_from_label_mapeia_email_e_matricula(): void
    {
        $this->assertSame(TipoLogin::EMAIL, TipoLogin::fromLabel('email'));
        $this->assertSame(TipoLogin::MATRICULA, TipoLogin::fromLabel('matricula'));
    }
}
```

- [ ] **Step 2: Correr o teste e confirmar que falha**

Run: `php artisan test Modules/Usuario/tests/Unit/TipoLoginTest.php`
Expected: FAIL — `Class "Modules\Usuario\Enums\TipoLogin" not found`.

- [ ] **Step 3: Criar o enum TipoLogin**

```php
<?php

namespace Modules\Usuario\Enums;

enum TipoLogin: int
{
    case EMAIL = 0;
    case MATRICULA = 1;

    public static function fromLabel(string $label): self
    {
        return match ($label) {
            'email' => self::EMAIL,
            'matricula' => self::MATRICULA,
        };
    }
}
```

- [ ] **Step 4: Criar o enum Perfil**

```php
<?php

namespace Modules\Permissao\Enums;

enum Perfil: int
{
    case ADMIN_ESCOLA = 0;
    case SECRETARIO = 1;
    case PROFESSOR = 2;
    case ALUNO = 3;
    case ENCARREGADO = 4;

    public function label(): string
    {
        return match ($this) {
            self::ADMIN_ESCOLA => 'Admin escola',
            self::SECRETARIO => 'Secretário',
            self::PROFESSOR => 'Professor',
            self::ALUNO => 'Aluno',
            self::ENCARREGADO => 'Encarregado',
        };
    }
}
```

- [ ] **Step 5: Correr o teste e confirmar que passa**

Run: `php artisan test Modules/Usuario/tests/Unit/TipoLoginTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add Modules/Usuario/app/Enums/TipoLogin.php Modules/Permissao/app/Enums/Perfil.php Modules/Usuario/tests/Unit/TipoLoginTest.php
git commit -m "feat: adicionar enums TipoLogin e Perfil"
```

---

### Task 2: Geração de matrícula

**Files:**
- Create: `Modules/Usuario/database/migrations/2026_08_25_000001_create_matricula_sequencias_table.php`
- Create: `Modules/Usuario/app/Models/MatriculaSequencia.php`
- Create: `Modules/Usuario/app/Services/GeradorMatriculaService.php`
- Test: `Modules/Usuario/tests/Unit/GeradorMatriculaServiceTest.php`

**Interfaces:**
- Produces: `GeradorMatriculaService::gerar(): string` (formato `ANO-0001`) — usado pela Task 7.

- [ ] **Step 1: Criar a migration da tabela de contadores**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matricula_sequencias', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('ano')->unique();
            $table->unsignedInteger('ultimo_numero')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matricula_sequencias');
    }
};
```

- [ ] **Step 2: Correr a migration**

Run: `php artisan migrate`
Expected: `Migrated: ..._create_matricula_sequencias_table`

- [ ] **Step 3: Criar o model MatriculaSequencia**

```php
<?php

namespace Modules\Usuario\Models;

use Illuminate\Database\Eloquent\Model;

class MatriculaSequencia extends Model
{
    protected $table = 'matricula_sequencias';

    protected $fillable = ['ano', 'ultimo_numero'];
}
```

- [ ] **Step 4: Escrever o teste do serviço de geração**

```php
<?php

namespace Modules\Usuario\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Usuario\Services\GeradorMatriculaService;
use Tests\TestCase;

class GeradorMatriculaServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_gera_matricula_no_formato_ano_sequencial(): void
    {
        $matricula = (new GeradorMatriculaService())->gerar();

        $this->assertMatchesRegularExpression('/^\d{4}-\d{4}$/', $matricula);
        $this->assertStringStartsWith((string) now()->year, $matricula);
    }

    public function test_sequencial_incrementa_a_cada_chamada(): void
    {
        $servico = new GeradorMatriculaService();
        $ano = now()->year;

        $this->assertSame("{$ano}-0001", $servico->gerar());
        $this->assertSame("{$ano}-0002", $servico->gerar());
        $this->assertSame("{$ano}-0003", $servico->gerar());
    }
}
```

- [ ] **Step 5: Correr o teste e confirmar que falha**

Run: `php artisan test Modules/Usuario/tests/Unit/GeradorMatriculaServiceTest.php`
Expected: FAIL — `Class "Modules\Usuario\Services\GeradorMatriculaService" not found`.

- [ ] **Step 6: Implementar o serviço**

```php
<?php

namespace Modules\Usuario\Services;

use Illuminate\Support\Facades\DB;
use Modules\Usuario\Models\MatriculaSequencia;

class GeradorMatriculaService
{
    public function gerar(): string
    {
        return DB::transaction(function () {
            $ano = now()->year;

            DB::table('matricula_sequencias')->upsert(
                [['ano' => $ano, 'ultimo_numero' => 0, 'created_at' => now(), 'updated_at' => now()]],
                ['ano'],
                []
            );

            $sequencia = MatriculaSequencia::where('ano', $ano)->lockForUpdate()->first();
            $sequencia->increment('ultimo_numero');

            return sprintf('%d-%04d', $ano, $sequencia->ultimo_numero);
        });
    }
}
```

- [ ] **Step 7: Correr o teste e confirmar que passa**

Run: `php artisan test Modules/Usuario/tests/Unit/GeradorMatriculaServiceTest.php`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add Modules/Usuario/database/migrations/2026_08_25_000001_create_matricula_sequencias_table.php Modules/Usuario/app/Models/MatriculaSequencia.php Modules/Usuario/app/Services/GeradorMatriculaService.php Modules/Usuario/tests/Unit/GeradorMatriculaServiceTest.php
git commit -m "feat: adicionar geração atómica de matrícula por ano"
```

---

### Task 3: Relação Encarregado ↔ Aluno e atualização do User

**Files:**
- Create: `Modules/Usuario/database/migrations/2026_08_25_000002_create_encarregados_alunos_table.php`
- Create: `Modules/Usuario/app/Models/EncarregadoAluno.php`
- Modify: `Modules/Usuario/app/Models/User.php`
- Test: `Modules/Usuario/tests/Feature/EncarregadoAlunoRelationTest.php`

**Interfaces:**
- Consumes: `TipoLogin` (Task 1).
- Produces: `User::educandos()`, `User::encarregados()`, `User::$fillable` inclui `numero_matricula`/`tipo_login` — usados pela Task 7.

- [ ] **Step 1: Criar a migration da relação**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('encarregados_alunos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('encarregado_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('aluno_id')->constrained('users')->onDelete('cascade');
            $table->string('parentesco')->nullable();
            $table->unique(['encarregado_id', 'aluno_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('encarregados_alunos');
    }
};
```

- [ ] **Step 2: Correr a migration**

Run: `php artisan migrate`
Expected: `Migrated: ..._create_encarregados_alunos_table`

- [ ] **Step 3: Criar o model EncarregadoAluno**

```php
<?php

namespace Modules\Usuario\Models;

use Illuminate\Database\Eloquent\Model;

class EncarregadoAluno extends Model
{
    protected $table = 'encarregados_alunos';

    protected $fillable = ['encarregado_id', 'aluno_id', 'parentesco'];

    public function encarregado()
    {
        return $this->belongsTo(User::class, 'encarregado_id');
    }

    public function aluno()
    {
        return $this->belongsTo(User::class, 'aluno_id');
    }
}
```

- [ ] **Step 4: Escrever o teste da relação no User**

```php
<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class EncarregadoAlunoRelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_encarregado_pode_ter_varios_educandos(): void
    {
        $encarregado = User::create([
            'name' => 'Maria Pais',
            'email' => 'maria@example.com',
            'password' => Hash::make('segredo123'),
        ]);

        $filho1 = User::create(['name' => 'Filho Um', 'numero_matricula' => '2026-0001', 'password' => Hash::make('x')]);
        $filho2 = User::create(['name' => 'Filho Dois', 'numero_matricula' => '2026-0002', 'password' => Hash::make('x')]);

        $encarregado->educandos()->attach([$filho1->id, $filho2->id]);

        $this->assertCount(2, $encarregado->fresh()->educandos);
        $this->assertTrue($filho1->fresh()->encarregados->contains($encarregado));
    }
}
```

- [ ] **Step 5: Correr o teste e confirmar que falha**

Run: `php artisan test Modules/Usuario/tests/Feature/EncarregadoAlunoRelationTest.php`
Expected: FAIL — `Call to undefined method Modules\Usuario\Models\User::educandos()`.

- [ ] **Step 6: Atualizar o model User**

Editar `Modules/Usuario/app/Models/User.php`:

```php
<?php

namespace Modules\Usuario\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\UserPermissao;
use Modules\Usuario\Enums\TipoLogin;

class User extends Authenticatable
{
    use HasFactory;
    use HasApiTokens, Notifiable;

    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'numero_matricula',
        'tipo_login',
        'dados_pessoa_id',
        'estado',
        'criado_por',
        'editado_por',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'tipo_login' => TipoLogin::class,
    ];

    // =========================
    // RELACIONAMENTOS
    // =========================

    public function pessoa()
    {
        return $this->belongsTo(DadosPessoal::class, 'dados_pessoa_id');
    }

    public function criadoPor()
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function editadoPor()
    {
        return $this->belongsTo(User::class, 'editado_por');
    }

    public function usuariosCriados()
    {
        return $this->hasMany(User::class, 'criado_por');
    }

    public function usuariosEditados()
    {
        return $this->hasMany(User::class, 'editado_por');
    }

    public function educandos()
    {
        return $this->belongsToMany(User::class, 'encarregados_alunos', 'encarregado_id', 'aluno_id')
            ->withPivot('parentesco')
            ->withTimestamps();
    }

    public function encarregados()
    {
        return $this->belongsToMany(User::class, 'encarregados_alunos', 'aluno_id', 'encarregado_id')
            ->withPivot('parentesco')
            ->withTimestamps();
    }

    const ESTADO_INATIVO = 0;
    const ESTADO_ATIVO = 1;

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'user_roles', 'users_id', 'role_id');
    }
    public function permissoes()
    {
        return $this->hasMany(UserPermissao::class, 'users_id');
    }
}
```

- [ ] **Step 7: Correr o teste e confirmar que passa**

Run: `php artisan test Modules/Usuario/tests/Feature/EncarregadoAlunoRelationTest.php`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add Modules/Usuario/database/migrations/2026_08_25_000002_create_encarregados_alunos_table.php Modules/Usuario/app/Models/EncarregadoAluno.php Modules/Usuario/app/Models/User.php Modules/Usuario/tests/Feature/EncarregadoAlunoRelationTest.php
git commit -m "feat: adicionar relação encarregado-aluno e expor numero_matricula/tipo_login no User"
```

---

### Task 4: Atualizar RoleSeeder para os 5 perfis

**Files:**
- Modify: `Modules/Permissao/database/seeders/RoleSeeder.php`
- Test: `Modules/Permissao/tests/Feature/RoleSeederTest.php`

**Interfaces:**
- Consumes: `Perfil::cases()`, `Perfil::label()` (Task 1).

- [ ] **Step 1: Criar a pasta de testes do módulo Permissao**

Run: `mkdir -p Modules/Permissao/tests/Feature Modules/Permissao/tests/Unit`

- [ ] **Step 2: Escrever o teste do seeder**

```php
<?php

namespace Modules\Permissao\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Permissao\Database\Seeders\RoleSeeder;
use Modules\Permissao\Models\Role;
use Tests\TestCase;

class RoleSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_semeia_os_5_perfis(): void
    {
        (new RoleSeeder())->run();

        $this->assertSame(5, Role::count());
        $this->assertTrue(Role::where('descricao', 'Encarregado')->exists());
        $this->assertTrue(Role::where('descricao', 'Admin escola')->exists());
    }
}
```

- [ ] **Step 3: Correr o teste e confirmar que falha**

Run: `php artisan test Modules/Permissao/tests/Feature/RoleSeederTest.php`
Expected: FAIL — `Role::count()` não é 5 (seeder atual só cria 4 com descrições antigas).

- [ ] **Step 4: Atualizar o RoleSeeder**

```php
<?php

namespace Modules\Permissao\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        Role::truncate();
        $now = now();

        Role::insert(array_map(
            fn (Perfil $perfil) => [
                'nome' => $perfil->value,
                'descricao' => $perfil->label(),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            Perfil::cases(),
        ));
    }
}
```

- [ ] **Step 5: Correr o teste e confirmar que passa**

Run: `php artisan test Modules/Permissao/tests/Feature/RoleSeederTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add Modules/Permissao/database/seeders/RoleSeeder.php Modules/Permissao/tests/Feature/RoleSeederTest.php Modules/Permissao/tests/Unit/.gitkeep
git commit -m "feat: semear os 5 perfis reais em RoleSeeder"
```

---

### Task 5: Login com duas vias (email / matrícula)

**Files:**
- Modify: `Modules/Autenticacao/app/Http/Requests/LoginRequest.php`
- Modify: `Modules/Autenticacao/app/Http/Controllers/AutenticacaoController.php`
- Modify: `Modules/Autenticacao/app/Service/GestaoAutenticacao.php`
- Modify: `Modules/Autenticacao/tests/Feature/LoginTest.php`

**Interfaces:**
- Consumes: `User::$fillable` inclui `numero_matricula` (Task 3).
- Produces: campo de request `login` (email ou matrícula) — usado pela Task 6 (Login.vue).

- [ ] **Step 1: Atualizar os testes existentes que dependem do campo `email`**

Em `Modules/Autenticacao/tests/Feature/LoginTest.php`, substituir os 3 métodos que usam o campo de login:

```php
    public function test_user_can_login_with_valid_credentials(): void
    {
        User::create([
            'name' => 'Ana Silva',
            'email' => 'ana@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->post('/login', [
            'login' => 'ana@example.com',
            'password' => 'password123',
        ]);

        $response->assertRedirect('/');
        $this->assertTrue(Auth::check());
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        User::create([
            'name' => 'Ana Silva',
            'email' => 'ana@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->post('/login', [
            'login' => 'ana@example.com',
            'password' => 'senha-errada',
        ]);

        $response->assertSessionHasErrors('login');
        $this->assertFalse(Auth::check());
    }
```

E em `test_login_forces_a_full_page_visit_on_an_inertia_request`:

```php
        $response = $this->withHeaders(['X-Inertia' => 'true'])->post('/login', [
            'login' => 'ana@example.com',
            'password' => 'password123',
        ]);
```

Adicionar dois testes novos no fim da classe (antes do `}` final):

```php
    public function test_aluno_can_login_with_matricula(): void
    {
        User::create([
            'name' => 'Aluno Teste',
            'numero_matricula' => '2026-0001',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->post('/login', [
            'login' => '2026-0001',
            'password' => 'password123',
        ]);

        $response->assertRedirect('/');
        $this->assertTrue(Auth::check());
    }

    public function test_encarregado_logs_in_by_email_like_any_staff_account(): void
    {
        $filho = User::create([
            'name' => 'Filho',
            'numero_matricula' => '2026-0002',
            'password' => Hash::make('x'),
        ]);

        $encarregado = User::create([
            'name' => 'Encarregado Teste',
            'email' => 'encarregado@example.com',
            'password' => Hash::make('password123'),
        ]);
        $encarregado->educandos()->attach($filho->id);

        $response = $this->post('/login', [
            'login' => 'encarregado@example.com',
            'password' => 'password123',
        ]);

        $response->assertRedirect('/');
        $this->assertTrue(Auth::check());
        $this->assertTrue(Auth::user()->educandos->contains($filho));
    }
```

- [ ] **Step 2: Correr os testes e confirmar que falham**

Run: `php artisan test Modules/Autenticacao/tests/Feature/LoginTest.php`
Expected: FAIL — os testes que usam `login` falham porque `LoginRequest` ainda só aceita `email`; `test_aluno_can_login_with_matricula` falha por credenciais inválidas.

- [ ] **Step 3: Atualizar LoginRequest**

```php
<?php

namespace Modules\Autenticacao\Http\Requests;

use App\Http\Requests\BaseRequest;

class LoginRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'login' => 'required|string',
            'password' => 'required',
            'remember' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'login.required' => 'O email ou matrícula é obrigatório.',
            'password.required' => 'A senha é obrigatória.',
        ];
    }
}
```

- [ ] **Step 4: Atualizar GestaoAutenticacao**

```php
<?php

namespace Modules\Autenticacao\Service;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Auth;

class GestaoAutenticacao
{
    public function login($identificador, $password, $remember = false, $key = null)
    {
        $limiterResponse = $this->checkLoginAttempts($identificador, $password, $remember, $key);
        if ($limiterResponse !== true) {
            Log::warning('Falha no login', ['identificador' => $identificador, 'ip' => request()->ip(), 'motivo' => $limiterResponse['message']]);
            return $limiterResponse;
        }

        $user = Auth::user();
        Log::info('Login realizado com sucesso', ['user_id' => $user->id, 'identificador' => $identificador, 'ip' => request()->ip()]);

        if ($key)
            RateLimiter::clear($key);

        return [
            'success' => true,
            'user' => $user,
            'code' => 200
        ];
    }

    private function checkLoginAttempts($identificador, $password, $remember = false, $key = null)
    {
        if ($key && RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            Log::warning('Usuário bloqueado por muitas tentativas', [
                'identificador' => $identificador,
                'ip' => request()->ip(),
                'tempo_restante' => $seconds
            ]);
            return [
                'success' => false,
                'message' => 'Muitas tentativas de login. Tente novamente em ' . $seconds . ' segundos.',
                'code' => 429
            ];
        }

        $campo = str_contains($identificador, '@') ? 'email' : 'numero_matricula';

        if (!Auth::attempt([$campo => $identificador, 'password' => $password], $remember)) {
            if ($key)
                RateLimiter::hit($key, 60);
            Log::warning('Credenciais inválidas', [
                'identificador' => $identificador,
                'ip' => request()->ip()
            ]);
            return [
                'success' => false,
                'message' => 'Credenciais inválidas',
                'code' => 401
            ];
        }

        return true;
    }
}
```

- [ ] **Step 5: Atualizar AutenticacaoController**

Editar `Modules/Autenticacao/app/Http/Controllers/AutenticacaoController.php`, método `store()`:

```php
    public function store(LoginRequest $request, GestaoAutenticacao $gestaoAutenticacao)
    {
        $key = 'login-attempts:' . $request->ip();

        $resposta = $gestaoAutenticacao->login(
            $request->login,
            $request->password,
            $request->boolean('remember'),
            $key
        );

        if (!$resposta['success']) {
            return back()->withErrors(['login' => $resposta['message']])->onlyInput('login');
        }

        $request->session()->regenerate();

        return Inertia::location('/');
    }
```

- [ ] **Step 6: Correr os testes e confirmar que passam**

Run: `php artisan test Modules/Autenticacao/tests/Feature/LoginTest.php`
Expected: PASS (12 testes)

- [ ] **Step 7: Commit**

```bash
git add Modules/Autenticacao/app/Http/Requests/LoginRequest.php Modules/Autenticacao/app/Http/Controllers/AutenticacaoController.php Modules/Autenticacao/app/Service/GestaoAutenticacao.php Modules/Autenticacao/tests/Feature/LoginTest.php
git commit -m "feat: suportar login por email ou matrícula num único campo"
```

---

### Task 6: Login.vue — campo genérico

**Files:**
- Modify: `Modules/Autenticacao/resources/js/Pages/Login.vue`

**Interfaces:**
- Consumes: campo de request `login` (Task 5).

- [ ] **Step 1: Renomear `form.email` para `form.login` e atualizar o campo**

Em `Modules/Autenticacao/resources/js/Pages/Login.vue`, substituir:

```js
const form = reactive({
    email: '',
    password: '',
    remember: false,
});
```

por:

```js
const form = reactive({
    login: '',
    password: '',
    remember: false,
});
```

E substituir o bloco do campo Email:

```html
                        <div class="fv-row mb-8">
                            <label class="required fw-semibold fs-6 mb-2">Email</label>
                            <input
                                v-model="form.email"
                                type="email"
                                name="email"
                                autocomplete="username"
                                class="form-control form-control-solid"
                                placeholder="exemplo@dominio.com"
                            />
                            <div class="text-danger fs-7 mt-1" v-if="errors.email">{{ errors.email }}</div>
                        </div>
```

por:

```html
                        <div class="fv-row mb-8">
                            <label class="required fw-semibold fs-6 mb-2">Email ou Matrícula</label>
                            <input
                                v-model="form.login"
                                type="text"
                                name="login"
                                autocomplete="username"
                                class="form-control form-control-solid"
                                placeholder="exemplo@dominio.com ou 2026-0001"
                            />
                            <div class="text-danger fs-7 mt-1" v-if="errors.login">{{ errors.login }}</div>
                        </div>
```

- [ ] **Step 2: Compilar o frontend**

Run: `npm run build`
Expected: build sem erros.

- [ ] **Step 3: Correr os testes de backend que exercitam esta página (garantia de que a rota /login continua íntegra)**

Run: `php artisan test Modules/Autenticacao/tests/Feature/LoginTest.php`
Expected: PASS

- [ ] **Step 4: Commit**

```bash
git add Modules/Autenticacao/resources/js/Pages/Login.vue
git commit -m "feat: campo de login genérico (email ou matrícula) em Login.vue"
```

---

### Task 7: Criação de utilizador com tipo_login, matrícula gerada e ligação a educandos

**Files:**
- Modify: `Modules/Usuario/app/Http/Requests/CriarUsuarioRequest.php`
- Modify: `Modules/Usuario/app/DTO/UsuarioDTO.php`
- Modify: `Modules/Usuario/app/Actions/UsuarioAction.php`
- Test: `Modules/Usuario/tests/Feature/CriarUsuarioTest.php`

**Interfaces:**
- Consumes: `TipoLogin::fromLabel()` (Task 1), `GeradorMatriculaService::gerar()` (Task 2), `User::educandos()` (Task 3).
- Produces: contrato de request usado pela Task 8 — campos `tipo_login` (`'email'|'matricula'`), `email` (obrigatório apenas se `tipo_login=email`), `matriculas_educandos` (array de strings, opcional).

- [ ] **Step 1: Escrever os testes do fluxo de criação**

```php
<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Usuario\Enums\TipoLogin;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class CriarUsuarioTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsStaff(): User
    {
        $staff = User::create([
            'name' => 'Staff',
            'email' => 'staff@example.com',
            'password' => Hash::make('segredo123'),
        ]);

        $this->actingAs($staff);

        return $staff;
    }

    public function test_cria_utilizador_com_email(): void
    {
        $this->actingAsStaff();

        $response = $this->post('/usuarios/cadastrarUsuario', [
            'name' => 'Professor Novo',
            'tipo_login' => 'email',
            'email' => 'professor@example.com',
            'password' => 'segredo123',
            'estado' => 1,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('users', [
            'email' => 'professor@example.com',
            'numero_matricula' => null,
        ]);
    }

    public function test_cria_aluno_com_matricula_gerada(): void
    {
        $this->actingAsStaff();

        $response = $this->post('/usuarios/alunos/cadastrar', [
            'name' => 'Aluno Novo',
            'tipo_login' => 'matricula',
            'password' => 'segredo123',
            'estado' => 1,
        ]);

        $response->assertRedirect();
        $aluno = User::where('name', 'Aluno Novo')->first();

        $this->assertNotNull($aluno);
        $this->assertNull($aluno->email);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{4}$/', $aluno->numero_matricula);
    }

    public function test_email_e_obrigatorio_quando_tipo_login_e_email(): void
    {
        $this->actingAsStaff();

        $response = $this->post('/usuarios/cadastrarUsuario', [
            'name' => 'Sem Email',
            'tipo_login' => 'email',
            'password' => 'segredo123',
        ]);

        $response->assertSessionHasErrors('email');
    }

    public function test_cria_encarregado_e_liga_aos_educandos_por_matricula(): void
    {
        $this->actingAsStaff();

        $aluno = User::create([
            'name' => 'Filho',
            'numero_matricula' => '2026-0001',
            'tipo_login' => TipoLogin::MATRICULA,
            'password' => Hash::make('segredo123'),
        ]);

        $response = $this->post('/usuarios/cadastrarUsuario', [
            'name' => 'Encarregado',
            'tipo_login' => 'email',
            'email' => 'encarregado@example.com',
            'password' => 'segredo123',
            'matriculas_educandos' => ['2026-0001'],
        ]);

        $response->assertRedirect();

        $encarregado = User::where('email', 'encarregado@example.com')->first();
        $this->assertTrue($encarregado->educandos->contains($aluno));
    }
}
```

- [ ] **Step 2: Correr os testes e confirmar que falham**

Run: `php artisan test Modules/Usuario/tests/Feature/CriarUsuarioTest.php`
Expected: FAIL — `tipo_login` não é aceite pelo `CriarUsuarioRequest` atual, `UsuarioDTO::fromArray` não tem essa chave.

- [ ] **Step 3: Atualizar CriarUsuarioRequest**

```php
<?php

namespace Modules\Usuario\Http\Requests;

use App\Http\Requests\BaseRequest;

class CriarUsuarioRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'tipo_login' => 'required|in:email,matricula',
            'email' => 'required_if:tipo_login,email|nullable|email|unique:users,email',
            'password' => 'required|min:6',
            'dados_pessoa_id' => 'nullable|exists:dados_pessoas,id',
            'estado' => 'nullable|in:1,0',
            'matriculas_educandos' => 'nullable|array',
            'matriculas_educandos.*' => 'string|exists:users,numero_matricula',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'O nome é obrigatório.',
            'name.string' => 'O nome deve ser um texto válido.',
            'name.max' => 'O nome não pode ter mais de 255 caracteres.',

            'tipo_login.required' => 'O tipo de login é obrigatório.',
            'tipo_login.in' => 'O tipo de login deve ser email ou matrícula.',

            'email.required_if' => 'O email é obrigatório para este tipo de utilizador.',
            'email.email' => 'Informe um email válido.',
            'email.unique' => 'Este email já está em uso.',

            'password.required' => 'A senha é obrigatória.',
            'password.min' => 'A senha deve ter pelo menos 6 caracteres.',

            'dados_pessoa_id.exists' => 'O registro de dados pessoais não existe.',

            'estado.in' => 'O estado deve ser 1 (ativo) ou 0 (inativo).',

            'matriculas_educandos.*.exists' => 'Uma das matrículas indicadas não existe.',
        ];
    }
}
```

- [ ] **Step 4: Atualizar UsuarioDTO**

```php
<?php

namespace Modules\Usuario\DTO;

use Modules\Usuario\Enums\EstadoUsuario;
use Modules\Usuario\Enums\TipoLogin;

class UsuarioDTO
{
    public function __construct(
        public string $name,
        public string $password,
        public TipoLogin $tipoLogin,
        public ?string $email = null,
        public ?int $dados_pessoa_id = null,
        public EstadoUsuario $estado = EstadoUsuario::ATIVO,
        public array $matriculasEducandos = [],
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            password: $data['password'],
            tipoLogin: TipoLogin::fromLabel($data['tipo_login']),
            email: $data['email'] ?? null,
            dados_pessoa_id: $data['dados_pessoa_id'] ?? null,
            estado: isset($data['estado'])
                ? EstadoUsuario::from($data['estado'])
                : EstadoUsuario::ATIVO,
            matriculasEducandos: $data['matriculas_educandos'] ?? [],
        );
    }
}
```

- [ ] **Step 5: Atualizar UsuarioAction**

```php
<?php

namespace Modules\Usuario\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Usuario\DTO\UsuarioDTO;
use Modules\Usuario\Enums\TipoLogin;
use Modules\Usuario\Models\User;
use Modules\Usuario\Services\GeradorMatriculaService;

class UsuarioAction
{
    public function __construct(
        private GeradorMatriculaService $geradorMatricula,
    ) {}

    public function criar(UsuarioDTO $dto): User
    {
        return DB::transaction(function () use ($dto) {
            $user = User::create([
                'name' => $dto->name,
                'email' => $dto->tipoLogin === TipoLogin::EMAIL ? $dto->email : null,
                'numero_matricula' => $dto->tipoLogin === TipoLogin::MATRICULA
                    ? $this->geradorMatricula->gerar()
                    : null,
                'tipo_login' => $dto->tipoLogin,
                'password' => Hash::make($dto->password),
                'dados_pessoa_id' => $dto->dados_pessoa_id,
                'estado' => $dto->estado->value,
            ]);

            if (! empty($dto->matriculasEducandos)) {
                $alunosIds = User::whereIn('numero_matricula', $dto->matriculasEducandos)->pluck('id');
                $user->educandos()->attach($alunosIds);
            }

            return $user;
        });
    }
}
```

- [ ] **Step 6: Atualizar UsuarioController para injetar a Action corretamente**

`UsuarioController::store()` já delega em `GestaoUsuarioService`, que por sua vez resolve `UsuarioAction` via o container — nenhuma alteração necessária aqui além de confirmar que `GeradorMatriculaService` é resolvível sem argumentos (não tem dependências), o que já é o caso.

- [ ] **Step 7: Correr os testes e confirmar que passam**

Run: `php artisan test Modules/Usuario/tests/Feature/CriarUsuarioTest.php`
Expected: PASS (4 testes)

- [ ] **Step 8: Correr a suite completa do módulo para garantir que nada quebrou**

Run: `php artisan test Modules/Usuario Modules/Autenticacao Modules/Permissao`
Expected: PASS em todos

- [ ] **Step 9: Commit**

```bash
git add Modules/Usuario/app/Http/Requests/CriarUsuarioRequest.php Modules/Usuario/app/DTO/UsuarioDTO.php Modules/Usuario/app/Actions/UsuarioAction.php Modules/Usuario/tests/Feature/CriarUsuarioTest.php
git commit -m "feat: criação de utilizador com tipo_login, matrícula gerada e ligação a educandos"
```

---

### Task 8: Formulários — tipoLogin por tipo de pessoa e novo EncarregadoForm

**Files:**
- Modify: `Modules/Usuario/resources/js/Components/UsuarioFormFields.vue`
- Modify: `Modules/Usuario/resources/js/Forms/Alunos/AlunoForm.vue`
- Modify: `Modules/Usuario/resources/js/Forms/Professores/ProfessorForm.vue`
- Modify: `Modules/Usuario/resources/js/Forms/Funcionarios/FuncionarioForm.vue`
- Modify: `Modules/Usuario/resources/js/Forms/Administradores/AdministradorForm.vue`
- Create: `Modules/Usuario/resources/js/Forms/Encarregados/EncarregadoForm.vue`
- Modify: `Modules/Usuario/resources/js/Forms/UsuarioForm.vue`

**Interfaces:**
- Consumes: contrato de request da Task 7 (`tipo_login`, `email` condicional, `matriculas_educandos`).

- [ ] **Step 1: Adicionar a prop `tipoLogin` a UsuarioFormFields.vue**

Editar `Modules/Usuario/resources/js/Components/UsuarioFormFields.vue`. Substituir o `<script setup>` por:

```html
<script setup>
// Campos comuns a qualquer pessoa (Aluno/Professor/Funcionário/Administrador/
// Encarregado): avatar, nome, email OU matrícula (conforme tipoLogin), senha.
// Usado pelos Forms de cada lista pra não repetir esse bloco — o que muda
// entre eles é só a secção de tipo/papel, que fica no próprio Form.
const name = defineModel('name', { default: '' });
const email = defineModel('email', { default: '' });
const password = defineModel('password', { default: '' });

defineProps({
    tipoLogin: {
        type: String,
        required: true,
        validator: (value) => ['email', 'matricula'].includes(value),
    },
    errors: {
        type: Object,
        default: () => ({}),
    },
});
</script>
```

Substituir o bloco do campo Email + o bloco do campo Matrícula (linhas do input Email até ao fim do input Matrícula) por:

```html
    <!--begin::Input group-->
    <div class="fv-row mb-7" v-if="tipoLogin === 'email'">
        <!--begin::Label-->
        <label class="required fw-semibold fs-6 mb-2">Email</label>
        <!--end::Label-->

        <!--begin::Input-->
        <input v-model="email" type="email" name="user_email" class="form-control form-control-solid mb-3 mb-lg-0" placeholder="exemplo@dominio.com" />
        <!--end::Input-->

        <div class="text-danger fs-7 mt-1" v-if="errors.email">{{ errors.email[0] }}</div>
    </div>
    <!--end::Input group-->

    <!--begin::Input group-->
    <div class="fv-row mb-7" v-else>
        <!--begin::Label-->
        <label class="fw-semibold fs-6 mb-2">Matrícula</label>
        <!--end::Label-->

        <div class="form-text">Será gerada automaticamente ao guardar.</div>
    </div>
    <!--end::Input group-->
```

- [ ] **Step 2: Passar `tipo-login="matricula"` em AlunoForm.vue**

Editar `Modules/Usuario/resources/js/Forms/Alunos/AlunoForm.vue`. Remover `email` do estado do form e do payload/reset:

```js
const form = reactive({
    name: '',
    password: '',
});
```

```js
        router.post(
            '/usuarios/alunos/cadastrar',
            { name: form.name, password: form.password, tipo_login: 'matricula', estado },
```

Substituir o corpo de `criar()` (o objeto passado a `router.post`, dentro do `onSuccess`) e de `onCancelar()`:

```js
                onSuccess: () => {
                    form.name = '';
                    form.password = '';
                    resolve();
                },
```

```js
function onCancelar() {
    form.name = '';
    form.password = '';
    errors.value = {};
    errorMessage.value = '';
    fecharModal();
}
```

No template, atualizar a chamada a `UsuarioFormFields`:

```html
            <UsuarioFormFields v-model:name="form.name" v-model:password="form.password"
                tipo-login="matricula" :errors="errors" />
```

- [ ] **Step 3a: Passar `tipo-login="email"` em ProfessorForm.vue**

Em `Modules/Usuario/resources/js/Forms/Professores/ProfessorForm.vue`, substituir:

```js
        router.post(
            '/usuarios/professores/cadastrar',
            { name: form.name, email: form.email, password: form.password, estado },
```

por:

```js
        router.post(
            '/usuarios/professores/cadastrar',
            { name: form.name, email: form.email, password: form.password, tipo_login: 'email', estado },
```

E substituir:

```html
            <UsuarioFormFields v-model:name="form.name" v-model:email="form.email" v-model:password="form.password"
                :errors="errors" />
```

por:

```html
            <UsuarioFormFields v-model:name="form.name" v-model:email="form.email" v-model:password="form.password"
                tipo-login="email" :errors="errors" />
```

- [ ] **Step 3b: Passar `tipo-login="email"` em FuncionarioForm.vue**

Em `Modules/Usuario/resources/js/Forms/Funcionarios/FuncionarioForm.vue`, substituir:

```js
        router.post(
            '/usuarios/funcionarios/cadastrar',
            { name: form.name, email: form.email, password: form.password, estado },
```

por:

```js
        router.post(
            '/usuarios/funcionarios/cadastrar',
            { name: form.name, email: form.email, password: form.password, tipo_login: 'email', estado },
```

E substituir:

```html
            <UsuarioFormFields v-model:name="form.name" v-model:email="form.email" v-model:password="form.password"
                :errors="errors" />
```

por:

```html
            <UsuarioFormFields v-model:name="form.name" v-model:email="form.email" v-model:password="form.password"
                tipo-login="email" :errors="errors" />
```

- [ ] **Step 3c: Passar `tipo-login="email"` em AdministradorForm.vue**

Em `Modules/Usuario/resources/js/Forms/Administradores/AdministradorForm.vue`, substituir:

```js
        router.post(
            '/usuarios/administradores/cadastrar',
            { name: form.name, email: form.email, password: form.password, estado },
```

por:

```js
        router.post(
            '/usuarios/administradores/cadastrar',
            { name: form.name, email: form.email, password: form.password, tipo_login: 'email', estado },
```

E substituir:

```html
            <UsuarioFormFields v-model:name="form.name" v-model:email="form.email" v-model:password="form.password"
                :errors="errors" />
```

por:

```html
            <UsuarioFormFields v-model:name="form.name" v-model:email="form.email" v-model:password="form.password"
                tipo-login="email" :errors="errors" />
```

- [ ] **Step 4: Criar EncarregadoForm.vue**

```html
<script setup>
import { reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import Loader from '@/Components/Shared/Loader.vue';
import UsuarioFormFields from '../../Components/UsuarioFormFields.vue';

// Form de criação específico da lista Encarregados — igual aos outros tipos
// de staff (login por email), mas com um campo extra para ligar aos
// educandos já cadastrados, por matrícula (ver AlunoForm.vue para a
// matrícula ser sempre gerada pelo sistema, nunca digitada).
const form = reactive({
    name: '',
    email: '',
    password: '',
});
const matriculaEducando = ref('');
const matriculasEducandos = ref([]);
const processing = ref(false);
const errors = ref({});
const errorMessage = ref('');

function adicionarEducando() {
    const matricula = matriculaEducando.value.trim();
    if (matricula && !matriculasEducandos.value.includes(matricula)) {
        matriculasEducandos.value.push(matricula);
    }
    matriculaEducando.value = '';
}

function removerEducando(matricula) {
    matriculasEducandos.value = matriculasEducandos.value.filter((m) => m !== matricula);
}

function criar(estado) {
    processing.value = true;
    errors.value = {};
    errorMessage.value = '';

    return new Promise((resolve, reject) => {
        router.post(
            '/usuarios/encarregados/cadastrar',
            {
                name: form.name,
                email: form.email,
                password: form.password,
                tipo_login: 'email',
                matriculas_educandos: matriculasEducandos.value,
                estado,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    form.name = '';
                    form.email = '';
                    form.password = '';
                    matriculasEducandos.value = [];
                    resolve();
                },
                onError: (erros) => {
                    errors.value = erros;
                    if (Object.keys(erros).length === 0) {
                        errorMessage.value = 'Não foi possível guardar o utilizador. Tenta novamente.';
                    }
                    reject(erros);
                },
                onFinish: () => {
                    processing.value = false;
                },
            },
        );
    });
}

function fecharModal() {
    window.bootstrap?.Modal.getInstance(document.getElementById('kt_modal_add_user'))?.hide();
}

async function onGuardar() {
    try {
        await criar(1);
        fecharModal();
    } catch {
        // erros de validação já ficam em `errors`/`errorMessage`, mostrados no template
    }
}

async function onGuardarRascunho() {
    try {
        await criar(0);
        fecharModal();
    } catch {
        // erros de validação já ficam em `errors`/`errorMessage`, mostrados no template
    }
}

function onCancelar() {
    form.name = '';
    form.email = '';
    form.password = '';
    matriculasEducandos.value = [];
    errors.value = {};
    errorMessage.value = '';
    fecharModal();
}
</script>

<template>
    <form id="kt_modal_add_user_form" class="form" action="#" @submit.prevent="onGuardar">
        <div class="d-flex flex-column scroll-y me-n7 pe-7" id="kt_modal_add_user_scroll" data-kt-scroll="true" data-kt-scroll-activate="{default: false, lg: true}" data-kt-scroll-max-height="auto" data-kt-scroll-dependencies="#kt_modal_add_user_header" data-kt-scroll-wrappers="#kt_modal_add_user_scroll" data-kt-scroll-offset="300px">
            <div class="alert alert-danger" v-if="errorMessage">{{ errorMessage }}</div>

            <UsuarioFormFields v-model:name="form.name" v-model:email="form.email" v-model:password="form.password"
                tipo-login="email" :errors="errors" />

            <div class="mb-7">
                <label class="fw-semibold fs-6 mb-2 d-block">Tipo</label>
                <span class="badge badge-light-info fs-6">Encarregado</span>
            </div>

            <!--begin::Input group-->
            <div class="fv-row mb-7">
                <label class="fw-semibold fs-6 mb-2">Educandos</label>
                <div class="d-flex gap-2 mb-2">
                    <input v-model="matriculaEducando" type="text" class="form-control form-control-solid"
                        placeholder="Matrícula do educando" @keydown.enter.prevent="adicionarEducando" />
                    <button type="button" class="btn btn-light-primary" @click="adicionarEducando">Adicionar</button>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <span v-for="matricula in matriculasEducandos" :key="matricula" class="badge badge-light-primary fs-7">
                        {{ matricula }}
                        <a href="#" class="ms-2 text-danger" @click.prevent="removerEducando(matricula)">&times;</a>
                    </span>
                </div>
                <div class="text-danger fs-7 mt-1" v-if="errors.matriculas_educandos">{{ errors.matriculas_educandos[0] }}</div>
            </div>
            <!--end::Input group-->
        </div>

        <div class="text-center pt-15">
            <button type="button" class="btn btn-danger me-3" data-kt-users-modal-action="cancel"
                :disabled="processing" @click="onCancelar">
                Cancelar
            </button>

            <button type="button" class="btn btn-castanho me-3" data-kt-users-modal-action="draft"
                :disabled="processing" @click="onGuardarRascunho">
                Guardar rascunho
            </button>

            <button type="submit" class="btn btn-primary" data-kt-users-modal-action="submit"
                :data-kt-indicator="processing ? 'on' : 'off'" :disabled="processing">
                <span class="indicator-label">Guardar</span>
                <span class="indicator-progress">
                    Aguarde... <Loader size="0.3px" class="align-middle ms-2" />
                </span>
            </button>
        </div>
    </form>
</template>
```

- [ ] **Step 5: Adicionar a rota do EncarregadoForm**

Em `Modules/Usuario/routes/web.php`, dentro do grupo `usuarios`, adicionar (seguindo o padrão dos outros grupos):

```php
    Route::group(['prefix' => 'encarregados'], function () {
        Route::get('/', [UsuarioController::class, 'encarregados'])->name('usuario.encarregados');
        Route::post('/cadastrar', [UsuarioController::class, 'store'])->name('usuario.encarregados.store');
    });
```

E em `Modules/Usuario/app/Http/Controllers/UsuarioController.php`, adicionar o método (seguindo o padrão de `administradores()`):

```php
    public function encarregados()
    {
        return Inertia::render('Usuario/Encarregados');
    }
```

- [ ] **Step 6: Atualizar UsuarioForm.vue (genérico) para derivar tipoLogin do tipo de pessoa selecionado**

Editar `Modules/Usuario/resources/js/Forms/UsuarioForm.vue`. Adicionar imports e estado:

```js
import { computed, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import Loader from '@/Components/Shared/Loader.vue';
import UsuarioFormFields from '../Components/UsuarioFormFields.vue';
import { TIPO_PESSOA } from '../Models/Usuario';

const form = reactive({
    name: '',
    email: '',
    password: '',
});
const tipoPessoa = ref(TIPO_PESSOA.ALUNO);
const tipoLogin = computed(() => (tipoPessoa.value === TIPO_PESSOA.ALUNO ? 'matricula' : 'email'));
const processing = ref(false);
const errors = ref({});
const errorMessage = ref('');
```

Atualizar `criar()` para enviar `tipo_login` e só enviar `email` quando aplicável:

```js
function criar(estado) {
    processing.value = true;
    errors.value = {};
    errorMessage.value = '';

    return new Promise((resolve, reject) => {
        router.post(
            '/usuarios/cadastrarUsuario',
            {
                name: form.name,
                email: tipoLogin.value === 'email' ? form.email : undefined,
                password: form.password,
                tipo_login: tipoLogin.value,
                estado,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    form.name = '';
                    form.email = '';
                    form.password = '';
                    resolve();
                },
                onError: (erros) => {
                    errors.value = erros;
                    if (Object.keys(erros).length === 0) {
                        errorMessage.value = 'Não foi possível guardar o utilizador. Tenta novamente.';
                    }
                    reject(erros);
                },
                onFinish: () => {
                    processing.value = false;
                },
            },
        );
    });
}
```

No template, atualizar a chamada a `UsuarioFormFields` e ligar o `<select>` de tipo de pessoa com `v-model`:

```html
            <UsuarioFormFields v-model:name="form.name" v-model:email="form.email" v-model:password="form.password"
                :tipo-login="tipoLogin" :errors="errors" />

            <!--begin::Input group-->
            <div class="fv-row mb-7">
                <label class="required fw-semibold fs-6 mb-2" for="kt_modal_add_user_tipo_pessoa">Tipo de pessoa</label>

                <select v-model="tipoPessoa" name="user_tipo_pessoa" id="kt_modal_add_user_tipo_pessoa" data-control="select2"
                    data-placeholder="Selecione o tipo" data-hide-search="true"
                    class="form-select form-select-solid">
                    <option :value="TIPO_PESSOA.ALUNO">Aluno</option>
                    <option :value="TIPO_PESSOA.PROFESSOR">Professor</option>
                    <option :value="TIPO_PESSOA.FUNCIONARIO">Funcionário</option>
                    <option :value="TIPO_PESSOA.OUTRO">Outro</option>
                </select>
            </div>
            <!--end::Input group-->
```

- [ ] **Step 7: Compilar o frontend**

Run: `npm run build`
Expected: build sem erros.

- [ ] **Step 8: Correr toda a suite de backend para garantir que os endpoints continuam íntegros**

Run: `php artisan test Modules/Usuario Modules/Autenticacao Modules/Permissao`
Expected: PASS em todos

- [ ] **Step 9: Verificação manual (não coberta por testes automatizados neste repositório)**

Arrancar o servidor de desenvolvimento e, para cada um dos 5 tipos (Alunos, Professores, Funcionarios, Administradores, Encarregados — novo), abrir o modal de criação e confirmar visualmente: campo Email aparece só quando aplicável; Aluno mostra a nota "Será gerada automaticamente"; Encarregado mostra o campo de adicionar matrículas.

- [ ] **Step 10: Commit**

```bash
git add Modules/Usuario/resources/js/Components/UsuarioFormFields.vue Modules/Usuario/resources/js/Forms/Alunos/AlunoForm.vue Modules/Usuario/resources/js/Forms/Professores/ProfessorForm.vue Modules/Usuario/resources/js/Forms/Funcionarios/FuncionarioForm.vue Modules/Usuario/resources/js/Forms/Administradores/AdministradorForm.vue Modules/Usuario/resources/js/Forms/Encarregados/EncarregadoForm.vue Modules/Usuario/resources/js/Forms/UsuarioForm.vue Modules/Usuario/routes/web.php Modules/Usuario/app/Http/Controllers/UsuarioController.php
git commit -m "feat: formulários por tipo de login e novo EncarregadoForm"
```
