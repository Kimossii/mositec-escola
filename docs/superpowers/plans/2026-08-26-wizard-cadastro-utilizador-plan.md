# Wizard de Cadastro/Edição de Utilizador Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Unir o cadastro de utilizador e o sistema de permissões: um wizard de 2 passos (dados básicos + perfil → matriz de permissões tri-estado) usado tanto para criar como para editar, substituindo os 5 formulários por tipo duplicados por um único componente partilhado.

**Architecture:** `UsuarioAction::criar()` passa a também sincronizar overrides individuais reutilizando `SincronizarPermissoesUtilizadorAction` do módulo Permissao. Um `AtualizarUsuarioAction` novo cobre a edição (nome/email/perfil/overrides), preservando outros perfis que o utilizador já tenha. As 6 páginas de listagem passam a enviar `perfis`/`modulos`/`acoes`/`permissoesPorPerfil` como props, para o wizard não precisar de pedidos HTTP extra ao trocar de perfil no Passo 1. `UsuarioWizardForm.vue` (novo) concentra a lógica dos 2 passos; os 5 Forms por tipo tornam-se cascas finas que só fixam o perfil.

**Tech Stack:** Laravel 11 (Modules: Usuario, Permissao), Eloquent, PHPUnit, Vue 3 + Inertia.js, vue-sonner.

**Spec:** `docs/superpowers/specs/2026-08-26-wizard-cadastro-utilizador-design.md`

## Global Constraints

- `tipo_login`/`numero_matricula` nunca mudam depois de criados — editar um utilizador não permite trocar entre email e matrícula (limitação aceite, documentada, não implementada como bloqueio explícito na UI).
- Editar o perfil de um utilizador nunca remove outros perfis que ele já tenha ganho pelo ecrã dedicado de Permissões (`syncWithoutDetaching`, nunca `sync`).
- O wizard só lida com os 5 perfis de sistema (slugs fixos); perfis personalizados continuam a atribuir-se exclusivamente pelo ecrã `UtilizadorPermissoes.vue` já existente.
- Sem verificação em runtime (`hasPermission()`) — fora do âmbito.
- Nenhum commit — todas as alterações ficam `git add` (staged), nunca `git commit`.

---

## Estrutura de ficheiros

**Backend — novos:**
- `Modules/Usuario/app/Actions/AtualizarUsuarioAction.php`
- `Modules/Usuario/app/Http/Requests/AtualizarUsuarioRequest.php`

**Backend — modificados:**
- `Modules/Permissao/app/Enums/Perfil.php` (novo método `slug()`)
- `Modules/Usuario/app/Http/Requests/CriarUsuarioRequest.php` (`celulas`)
- `Modules/Usuario/app/DTO/UsuarioDTO.php` (`celulas`)
- `Modules/Usuario/app/Actions/UsuarioAction.php` (sincroniza overrides)
- `Modules/Usuario/app/Http/Controllers/UsuarioController.php` (`dadosDeApoio()`, `edit()`, `update()`)
- `Modules/Usuario/routes/web.php` (rotas `editar`/`update`)

**Frontend — novos:**
- `Modules/Usuario/resources/js/Components/UsuarioWizardForm.vue`

**Frontend — modificados:**
- `Modules/Usuario/resources/js/Forms/Alunos/AlunoForm.vue`
- `Modules/Usuario/resources/js/Forms/Professores/ProfessorForm.vue`
- `Modules/Usuario/resources/js/Forms/Funcionarios/FuncionarioForm.vue`
- `Modules/Usuario/resources/js/Forms/Administradores/AdministradorForm.vue`
- `Modules/Usuario/resources/js/Forms/Encarregados/EncarregadoForm.vue`
- `Modules/Usuario/resources/js/Forms/UsuarioForm.vue`
- `Modules/Usuario/resources/js/Components/UsuarioTable.vue` (botão Edit funcional)
- `Modules/Usuario/resources/js/Components/UsuarioListLayout.vue` (estado partilhado de edição)
- `Modules/Usuario/resources/js/Components/UsuarioToolbar.vue` (reencaminha props novas)
- `Modules/Usuario/resources/js/Components/UsuarioCreateModal.vue` (título dinâmico + props ao form)
- Os 6 `Pages/*.vue` do módulo Usuario (passam `perfis`/`modulos`/`acoes`/`permissoesPorPerfil` ao form)

**Testes — novos:**
- `Modules/Usuario/tests/Feature/AtualizarUsuarioTest.php`

**Testes — modificados:**
- `Modules/Usuario/tests/Feature/CriarUsuarioTest.php` (novo teste com `celulas`)

---

### Task 1: Backend — overrides na criação + dados de apoio nas listagens

**Files:**
- Modify: `Modules/Permissao/app/Enums/Perfil.php`
- Modify: `Modules/Usuario/app/Http/Requests/CriarUsuarioRequest.php`
- Modify: `Modules/Usuario/app/DTO/UsuarioDTO.php`
- Modify: `Modules/Usuario/app/Actions/UsuarioAction.php`
- Modify: `Modules/Usuario/app/Http/Controllers/UsuarioController.php`
- Test: `Modules/Usuario/tests/Feature/CriarUsuarioTest.php`

**Interfaces:**
- Consumes: `SincronizarPermissoesUtilizadorAction::executar(User, array)` (já existe, módulo Permissao).
- Produces: `Perfil::slug(): string`; prop `celulas` aceite por `CriarUsuarioRequest`/`UsuarioDTO`; props `perfis`/`modulos`/`acoes`/`permissoesPorPerfil` em todas as páginas de listagem — usados pelas Tasks 3 e 4.

- [ ] **Step 1: Escrever o teste de criação com overrides**

Adicionar a `Modules/Usuario/tests/Feature/CriarUsuarioTest.php` (a seguir aos testes já existentes, mesma classe):

```php
    public function test_cria_utilizador_com_permissao_extra_alem_do_perfil(): void
    {
        $this->actingAsStaff();

        $modulo = \Modules\Permissao\Models\Modulo::create(['nome' => 0, 'descricao' => 'Usuario', 'estado' => 1]);
        $acao = \Modules\Permissao\Models\Acao::create(['nome' => 'exportar', 'numero' => 5, 'estado' => 1]);

        $response = $this->post('/usuarios/cadastrarUsuario', [
            'name' => 'Professor Com Extra',
            'perfil' => 'professor',
            'tipo_login' => 'email',
            'email' => 'professor.extra@example.com',
            'password' => 'segredo123',
            'celulas' => [
                ['modulo_id' => $modulo->id, 'acao_id' => $acao->id, 'permitido' => true],
            ],
        ]);

        $response->assertRedirect();
        $user = User::where('email', 'professor.extra@example.com')->first();
        $this->assertDatabaseHas('user_permissoes', [
            'users_id' => $user->id,
            'modulo_id' => $modulo->id,
            'acao_id' => $acao->id,
            'permitido' => true,
        ]);
    }
```

- [ ] **Step 2: Correr o teste e confirmar que falha**

Run: `php artisan test Modules/Usuario/tests/Feature/CriarUsuarioTest.php`
Expected: FAIL — `celulas` não é uma regra aceite/gravada ainda (o `user_permissoes` fica vazio).

- [ ] **Step 3: Adicionar `Perfil::slug()`**

Em `Modules/Permissao/app/Enums/Perfil.php`, adicionar (a seguir a `fromSlug()`):

```php
    public function slug(): string
    {
        return match ($this) {
            self::ADMIN_ESCOLA => 'admin_escola',
            self::SECRETARIO => 'secretario',
            self::PROFESSOR => 'professor',
            self::ALUNO => 'aluno',
            self::ENCARREGADO => 'encarregado',
        };
    }
```

- [ ] **Step 4: Adicionar `celulas` ao CriarUsuarioRequest**

Em `Modules/Usuario/app/Http/Requests/CriarUsuarioRequest.php`, adicionar a `rules()`:

```php
            'celulas' => 'nullable|array',
            'celulas.*.modulo_id' => 'required_with:celulas|integer|exists:modulos,id',
            'celulas.*.acao_id' => 'required_with:celulas|integer|exists:acoes,id',
            'celulas.*.permitido' => 'required_with:celulas|boolean',
```

- [ ] **Step 5: Adicionar `celulas` ao UsuarioDTO**

Em `Modules/Usuario/app/DTO/UsuarioDTO.php`, substituir o construtor e `fromArray()`:

```php
    public function __construct(
        public string $name,
        public string $password,
        public Perfil $perfil,
        public TipoLogin $tipoLogin,
        public ?string $email = null,
        public ?int $dados_pessoa_id = null,
        public EstadoUsuario $estado = EstadoUsuario::ATIVO,
        public array $matriculasEducandos = [],
        public array $celulas = [],
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            password: $data['password'],
            perfil: Perfil::fromSlug($data['perfil']),
            tipoLogin: TipoLogin::fromLabel($data['tipo_login']),
            email: $data['email'] ?? null,
            dados_pessoa_id: $data['dados_pessoa_id'] ?? null,
            estado: isset($data['estado'])
                ? EstadoUsuario::from($data['estado'])
                : EstadoUsuario::ATIVO,
            matriculasEducandos: $data['matriculas_educandos'] ?? [],
            celulas: $data['celulas'] ?? [],
        );
    }
```

- [ ] **Step 6: Sincronizar overrides em UsuarioAction::criar()**

Em `Modules/Usuario/app/Actions/UsuarioAction.php`, substituir o ficheiro inteiro:

```php
<?php

namespace Modules\Usuario\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Actions\SincronizarPermissoesUtilizadorAction;
use Modules\Permissao\Models\Role;
use Modules\Usuario\DTO\UsuarioDTO;
use Modules\Usuario\Enums\TipoLogin;
use Modules\Usuario\Models\User;
use Modules\Usuario\Services\GeradorMatriculaService;

class UsuarioAction
{
    public function __construct(
        private GeradorMatriculaService $geradorMatricula,
        private SincronizarPermissoesUtilizadorAction $sincronizarPermissoes,
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

            $role = Role::where('nome', $dto->perfil->value)->firstOrFail();
            $user->roles()->attach($role->id);

            $this->sincronizarPermissoes->executar($user, $dto->celulas);

            if (! empty($dto->matriculasEducandos)) {
                $alunosIds = User::whereIn('numero_matricula', $dto->matriculasEducandos)->pluck('id');
                $user->educandos()->attach($alunosIds);
            }

            return $user;
        });
    }
}
```

- [ ] **Step 7: Correr o teste e confirmar que passa**

Run: `php artisan test Modules/Usuario/tests/Feature/CriarUsuarioTest.php`
Expected: PASS (todos)

- [ ] **Step 8: Adicionar `dadosDeApoio()` e usá-lo nas 6 listagens**

Em `Modules/Usuario/app/Http/Controllers/UsuarioController.php`, adicionar os imports:

```php
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
```

Adicionar o método privado (a seguir a `serializar()`):

```php
    private function dadosDeApoio(): array
    {
        $perfis = collect(Perfil::cases())->map(fn (Perfil $perfil) => [
            'id' => Role::where('nome', $perfil->value)->value('id'),
            'slug' => $perfil->slug(),
            'descricao' => $perfil->label(),
        ]);

        $roleIds = $perfis->pluck('id')->filter()->values();

        return [
            'perfis' => $perfis->values(),
            'modulos' => Modulo::orderBy('nome')->get(['id', 'nome', 'descricao']),
            'acoes' => Acao::orderBy('numero')->get(['id', 'nome']),
            'permissoesPorPerfil' => RolePermissao::whereIn('role_id', $roleIds)
                ->get(['role_id', 'modulo_id', 'acao_id'])
                ->groupBy('role_id')
                ->map(fn ($grupo) => $grupo->map(fn ($p) => ['modulo_id' => $p->modulo_id, 'acao_id' => $p->acao_id])->values()),
        ];
    }
```

Atualizar cada um dos 6 métodos de listagem para fundir `dadosDeApoio()` no `Inertia::render`, por exemplo `index()`:

```php
    public function index()
    {
        return Inertia::render('Usuario/Index', array_merge([
            'usuarios' => $this->serializar(User::all()),
        ], $this->dadosDeApoio()));
    }
```

Aplicar a mesma alteração (envolver o array existente em `array_merge([...], $this->dadosDeApoio())`) aos outros 5 métodos:

```php
    public function alunos()
    {
        return Inertia::render('Usuario/Alunos', array_merge([
            'usuarios' => $this->listarPorPerfil(Perfil::ALUNO),
        ], $this->dadosDeApoio()));
    }

    public function professores()
    {
        return Inertia::render('Usuario/Professores', array_merge([
            'usuarios' => $this->listarPorPerfil(Perfil::PROFESSOR),
        ], $this->dadosDeApoio()));
    }

    public function funcionarios()
    {
        return Inertia::render('Usuario/Funcionarios', array_merge([
            'usuarios' => $this->listarPorPerfil(Perfil::SECRETARIO),
        ], $this->dadosDeApoio()));
    }

    public function administradores()
    {
        return Inertia::render('Usuario/Administradores', array_merge([
            'usuarios' => $this->listarPorPerfil(Perfil::ADMIN_ESCOLA),
        ], $this->dadosDeApoio()));
    }

    public function encarregados()
    {
        return Inertia::render('Usuario/Encarregados', array_merge([
            'usuarios' => $this->listarPorPerfil(Perfil::ENCARREGADO),
        ], $this->dadosDeApoio()));
    }
```

- [ ] **Step 9: Correr a suite completa para garantir que nada quebrou**

Run: `php artisan test Modules`
Expected: PASS em todos

- [ ] **Step 10: Commit**

Sem commits (ver Global Constraints) — `git add` apenas.

---

### Task 2: Backend — editar utilizador

**Files:**
- Create: `Modules/Usuario/app/Http/Requests/AtualizarUsuarioRequest.php`
- Create: `Modules/Usuario/app/Actions/AtualizarUsuarioAction.php`
- Modify: `Modules/Usuario/app/Http/Controllers/UsuarioController.php`
- Modify: `Modules/Usuario/routes/web.php`
- Test: `Modules/Usuario/tests/Feature/AtualizarUsuarioTest.php`

**Interfaces:**
- Consumes: `SincronizarPermissoesUtilizadorAction` (Permissao), `Perfil::slug()`/`fromSlug()` (Task 1).
- Produces: `GET /usuarios/{user}/editar` (JSON), `PUT /usuarios/{user}` — usados pela Task 4.

- [ ] **Step 1: Escrever os testes**

```php
<?php

namespace Modules\Usuario\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Database\Seeders\RoleSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class AtualizarUsuarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function actingAsStaff(): User
    {
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('x')]);
        $this->actingAs($staff);

        return $staff;
    }

    public function test_edit_devolve_dados_atuais_do_utilizador(): void
    {
        $this->actingAsStaff();

        $professor = User::create(['name' => 'Prof', 'email' => 'prof@example.com', 'password' => Hash::make('x')]);
        $roleProfessor = Role::where('nome', Perfil::PROFESSOR->value)->first();
        $professor->roles()->attach($roleProfessor->id);

        $modulo = Modulo::create(['nome' => 0, 'descricao' => 'Usuario', 'estado' => 1]);
        $acao = Acao::create(['nome' => 'eliminar', 'numero' => 3, 'estado' => 1]);
        $professor->permissoes()->create(['modulo_id' => $modulo->id, 'acao_id' => $acao->id, 'permitido' => false]);

        $response = $this->getJson("/usuarios/{$professor->id}/editar");

        $response->assertOk();
        $response->assertJson([
            'name' => 'Prof',
            'email' => 'prof@example.com',
            'tipo_login' => 'email',
            'perfil' => 'professor',
        ]);
        $response->assertJsonFragment(['modulo_id' => $modulo->id, 'acao_id' => $acao->id, 'permitido' => false]);
    }

    public function test_atualiza_nome_email_perfil_e_overrides(): void
    {
        $this->actingAsStaff();

        $professor = User::create(['name' => 'Prof', 'email' => 'prof2@example.com', 'password' => Hash::make('x')]);
        $roleProfessor = Role::where('nome', Perfil::PROFESSOR->value)->first();
        $professor->roles()->attach($roleProfessor->id);

        $modulo = Modulo::create(['nome' => 0, 'descricao' => 'Usuario', 'estado' => 1]);
        $acao = Acao::create(['nome' => 'eliminar', 'numero' => 3, 'estado' => 1]);

        $response = $this->put("/usuarios/{$professor->id}", [
            'name' => 'Prof Atualizado',
            'email' => 'prof2@example.com',
            'perfil' => 'secretario',
            'celulas' => [
                ['modulo_id' => $modulo->id, 'acao_id' => $acao->id, 'permitido' => true],
            ],
        ]);

        $response->assertRedirect();
        $professor->refresh();
        $this->assertSame('Prof Atualizado', $professor->name);

        $roleSecretario = Role::where('nome', Perfil::SECRETARIO->value)->first();
        $this->assertTrue($professor->roles->contains($roleSecretario));
        $this->assertTrue($professor->roles->contains($roleProfessor), 'não deve remover o perfil anterior');

        $this->assertDatabaseHas('user_permissoes', [
            'users_id' => $professor->id,
            'modulo_id' => $modulo->id,
            'acao_id' => $acao->id,
            'permitido' => true,
        ]);
    }
}
```

- [ ] **Step 2: Correr os testes e confirmar que falham**

Run: `php artisan test Modules/Usuario/tests/Feature/AtualizarUsuarioTest.php`
Expected: FAIL — rotas `/usuarios/{user}/editar` e `PUT /usuarios/{user}` ainda não existem (404).

- [ ] **Step 3: Criar o AtualizarUsuarioRequest**

```php
<?php

namespace Modules\Usuario\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;

class AtualizarUsuarioRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'perfil' => 'required|in:admin_escola,secretario,professor,aluno,encarregado',
            'email' => ['nullable', 'email', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'celulas' => 'nullable|array',
            'celulas.*.modulo_id' => 'required_with:celulas|integer|exists:modulos,id',
            'celulas.*.acao_id' => 'required_with:celulas|integer|exists:acoes,id',
            'celulas.*.permitido' => 'required_with:celulas|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'O nome é obrigatório.',
            'perfil.required' => 'O perfil é obrigatório.',
            'perfil.in' => 'O perfil indicado é inválido.',
            'email.email' => 'Informe um email válido.',
            'email.unique' => 'Este email já está em uso.',
        ];
    }
}
```

- [ ] **Step 4: Criar o AtualizarUsuarioAction**

```php
<?php

namespace Modules\Usuario\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Permissao\Actions\SincronizarPermissoesUtilizadorAction;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;

class AtualizarUsuarioAction
{
    public function __construct(
        private SincronizarPermissoesUtilizadorAction $sincronizarPermissoes,
    ) {}

    public function atualizar(User $user, array $dados): User
    {
        return DB::transaction(function () use ($user, $dados) {
            $user->update([
                'name' => $dados['name'],
                'email' => $dados['email'] ?? $user->email,
            ]);

            $role = Role::where('nome', Perfil::fromSlug($dados['perfil'])->value)->firstOrFail();
            $user->roles()->syncWithoutDetaching([$role->id]);

            $this->sincronizarPermissoes->executar($user, $dados['celulas'] ?? []);

            return $user->fresh();
        });
    }
}
```

- [ ] **Step 5: Adicionar `edit()` e `update()` ao UsuarioController**

Adicionar o import `use Modules\Usuario\Actions\AtualizarUsuarioAction;`, `use Modules\Usuario\Enums\TipoLogin;`, `use Modules\Usuario\Http\Requests\AtualizarUsuarioRequest;` e os dois métodos (antes do `store()`):

```php
    public function edit(User $user)
    {
        $roleSistema = $user->roles->first(fn ($role) => $role->eSistema());

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'tipo_login' => $user->tipo_login === TipoLogin::MATRICULA ? 'matricula' : 'email',
            'matricula' => $user->numero_matricula,
            'perfil' => $roleSistema ? Perfil::from($roleSistema->nome)->slug() : null,
            'celulas' => $user->permissoes()->get(['modulo_id', 'acao_id', 'permitido']),
        ]);
    }

    public function update(AtualizarUsuarioRequest $request, User $user, AtualizarUsuarioAction $action)
    {
        $action->atualizar($user, $request->validated());

        return redirect()->back()->with('success', 'Utilizador atualizado com sucesso.');
    }
```

(`Perfil` já deve estar importado da Task 1.)

- [ ] **Step 6: Adicionar as rotas**

Em `Modules/Usuario/routes/web.php`, dentro do grupo `prefix('usuarios')`, a seguir à rota `usuario.store`:

```php
    Route::get('/{user}/editar', [UsuarioController::class, 'edit'])->name('usuario.edit');
    Route::put('/{user}', [UsuarioController::class, 'update'])->name('usuario.update');
```

- [ ] **Step 7: Correr os testes e confirmar que passam**

Run: `php artisan test Modules/Usuario/tests/Feature/AtualizarUsuarioTest.php`
Expected: PASS

- [ ] **Step 8: Correr a suite completa**

Run: `php artisan test Modules`
Expected: PASS em todos

- [ ] **Step 9: Commit**

Sem commits (ver Global Constraints) — `git add` apenas.

---

### Task 3: Frontend — UsuarioWizardForm.vue

**Files:**
- Create: `Modules/Usuario/resources/js/Components/UsuarioWizardForm.vue`

**Interfaces:**
- Consumes: `UsuarioFormFields.vue` (já existe); props `perfis`/`modulos`/`acoes`/`permissoesPorPerfil` (Task 1); rotas `usuario.update` via `PUT /usuarios/{id}` (Task 2); contrato de `CriarUsuarioRequest`/`AtualizarUsuarioRequest` (Tasks 1, 2).
- Produces: componente `UsuarioWizardForm` com props `perfilFixo`, `perfis`, `modulos`, `acoes`, `permissoesPorPerfil`, `utilizador`, `rotaCriar`, e evento `fechado` — usado pela Task 4.

- [ ] **Step 1: Criar o componente**

```html
<script setup>
import { computed, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import Loader from '@/Components/Shared/Loader.vue';
import UsuarioFormFields from './UsuarioFormFields.vue';

const props = defineProps({
    perfilFixo: { type: String, default: null },
    perfis: { type: Array, required: true },
    modulos: { type: Array, required: true },
    acoes: { type: Array, required: true },
    permissoesPorPerfil: { type: Object, required: true },
    utilizador: { type: Object, default: null },
    rotaCriar: { type: String, required: true },
});
const emit = defineEmits(['fechado']);

const passo = ref(1);
const processing = ref(false);
const errors = ref({});
const errorMessage = ref('');

const form = reactive({
    name: props.utilizador?.name ?? '',
    email: props.utilizador?.email ?? '',
    password: '',
});

const perfilSelecionado = ref(props.perfilFixo ?? props.utilizador?.perfil ?? props.perfis[0]?.slug ?? '');

const matriculaEducando = ref('');
const matriculasEducandos = ref([]);

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

const tipoLogin = computed(() => {
    if (props.utilizador) return props.utilizador.tipo_login;
    return perfilSelecionado.value === 'aluno' ? 'matricula' : 'email';
});

const perfilAtual = computed(() => props.perfis.find((p) => p.slug === perfilSelecionado.value));

const chave = (moduloId, acaoId) => `${moduloId}-${acaoId}`;

// -1 = herda, 1 = concede, 0 = nega
const overridesEstado = reactive(
    Object.fromEntries(
        (props.utilizador?.celulas ?? []).map((o) => [chave(o.modulo_id, o.acao_id), o.permitido ? 1 : 0]),
    ),
);

function permiteDefault(moduloId, acaoId) {
    const permissoes = props.permissoesPorPerfil[perfilAtual.value?.id] ?? [];
    return permissoes.some((p) => p.modulo_id === moduloId && p.acao_id === acaoId);
}

function estadoCelula(moduloId, acaoId) {
    return overridesEstado[chave(moduloId, acaoId)] ?? -1;
}

function proximoEstado(moduloId, acaoId) {
    const k = chave(moduloId, acaoId);
    const atual = overridesEstado[k] ?? -1;
    const seguinte = atual === -1 ? 1 : atual === 1 ? 0 : -1;

    if (seguinte === -1) {
        delete overridesEstado[k];
    } else {
        overridesEstado[k] = seguinte;
    }
}

function avancar() {
    if (perfilSelecionado.value === 'encarregado' && matriculasEducandos.value.length === 0) {
        errorMessage.value = 'É preciso ligar pelo menos um educando.';
        return;
    }
    errorMessage.value = '';
    passo.value = 2;
}

function voltar() {
    passo.value = 1;
}

function fecharModal() {
    window.bootstrap?.Modal.getInstance(document.getElementById('kt_modal_add_user'))?.hide();
}

function guardar() {
    processing.value = true;
    errors.value = {};

    const celulas = Object.entries(overridesEstado).map(([k, valor]) => {
        const [modulo_id, acao_id] = k.split('-').map(Number);
        return { modulo_id, acao_id, permitido: valor === 1 };
    });

    const payload = {
        name: form.name,
        email: tipoLogin.value === 'email' ? form.email : undefined,
        perfil: perfilSelecionado.value,
        celulas,
    };

    if (!props.utilizador) {
        payload.password = form.password;
        payload.tipo_login = tipoLogin.value;
        if (perfilSelecionado.value === 'encarregado') {
            payload.matriculas_educandos = matriculasEducandos.value;
        }
    }

    const url = props.utilizador ? `/usuarios/${props.utilizador.id}` : props.rotaCriar;
    const metodo = props.utilizador ? 'put' : 'post';

    router[metodo](url, payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(props.utilizador ? 'Utilizador atualizado com sucesso.' : 'Utilizador criado com sucesso.');
            fecharModal();
            emit('fechado');
        },
        onError: (erros) => {
            errors.value = erros;
            passo.value = 1;
            toast.error(Object.values(erros)[0] ?? 'Não foi possível guardar o utilizador.');
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}
</script>

<template>
    <div>
        <div class="alert alert-danger" v-if="errorMessage">{{ errorMessage }}</div>

        <div class="mb-5 d-flex align-items-center gap-2">
            <span class="badge" :class="passo === 1 ? 'badge-primary' : 'badge-light-primary'">1. Dados</span>
            <span class="badge" :class="passo === 2 ? 'badge-primary' : 'badge-light-primary'">2. Permissões</span>
        </div>

        <div v-if="passo === 1">
            <UsuarioFormFields v-model:name="form.name" v-model:email="form.email" v-model:password="form.password"
                :tipo-login="tipoLogin" :errors="errors" />

            <div class="fv-row mb-7">
                <label class="required fw-semibold fs-6 mb-2">Perfil</label>
                <span v-if="perfilFixo" class="badge badge-light-primary fs-6 d-block w-auto" style="width: fit-content;">
                    {{ perfis.find((p) => p.slug === perfilFixo)?.descricao }}
                </span>
                <select v-else v-model="perfilSelecionado" class="form-select form-select-solid">
                    <option v-for="perfil in perfis" :key="perfil.slug" :value="perfil.slug">{{ perfil.descricao }}</option>
                </select>
            </div>

            <div class="fv-row mb-7" v-if="perfilSelecionado === 'encarregado'">
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

            <div class="text-end pt-5">
                <button type="button" class="btn btn-primary" @click="avancar">Seguinte</button>
            </div>
        </div>

        <div v-else>
            <p class="text-muted fs-7">
                Clique numa célula para alternar entre Herda (o que o perfil "{{ perfilAtual?.descricao }}" já dá por
                padrão), Concede e Nega.
            </p>
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th>Módulo</th>
                        <th v-for="acao in acoes" :key="acao.id" class="text-center text-capitalize">{{ acao.nome }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="modulo in modulos" :key="modulo.id">
                        <td>{{ modulo.descricao }}</td>
                        <td v-for="acao in acoes" :key="acao.id" class="text-center">
                            <button
                                type="button"
                                class="btn btn-sm"
                                :class="{
                                    'btn-light': estadoCelula(modulo.id, acao.id) === -1 && !permiteDefault(modulo.id, acao.id),
                                    'btn-light-success': estadoCelula(modulo.id, acao.id) === 1 || (estadoCelula(modulo.id, acao.id) === -1 && permiteDefault(modulo.id, acao.id)),
                                    'btn-light-danger': estadoCelula(modulo.id, acao.id) === 0,
                                }"
                                @click="proximoEstado(modulo.id, acao.id)"
                            >
                                {{ estadoCelula(modulo.id, acao.id) === -1 ? (permiteDefault(modulo.id, acao.id) ? 'Herda (concede)' : 'Herda (nega)') : estadoCelula(modulo.id, acao.id) === 1 ? 'Concede' : 'Nega' }}
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div class="d-flex justify-content-between pt-5">
                <button type="button" class="btn btn-light" :disabled="processing" @click="voltar">Voltar</button>
                <button type="button" class="btn btn-primary" :disabled="processing" @click="guardar">
                    <span v-if="!processing">Guardar</span>
                    <span v-else>Aguarde... <Loader size="0.3px" class="align-middle ms-2" /></span>
                </button>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 2: Compilar o frontend**

Run: `npm run build`
Expected: build sem erros (o componente ainda não é usado por nenhuma Page, mas deve compilar isoladamente sem erros de sintaxe — confirmar correndo o build completo).

- [ ] **Step 3: Commit**

Sem commits (ver Global Constraints) — `git add` apenas.

---

### Task 4: Frontend — ligar o wizard às 6 listagens e ao botão Edit

**Files:**
- Modify: `Modules/Usuario/resources/js/Forms/Alunos/AlunoForm.vue`
- Modify: `Modules/Usuario/resources/js/Forms/Professores/ProfessorForm.vue`
- Modify: `Modules/Usuario/resources/js/Forms/Funcionarios/FuncionarioForm.vue`
- Modify: `Modules/Usuario/resources/js/Forms/Administradores/AdministradorForm.vue`
- Modify: `Modules/Usuario/resources/js/Forms/Encarregados/EncarregadoForm.vue`
- Modify: `Modules/Usuario/resources/js/Forms/UsuarioForm.vue`
- Modify: `Modules/Usuario/resources/js/Components/UsuarioTable.vue`
- Modify: `Modules/Usuario/resources/js/Components/UsuarioListLayout.vue`
- Modify: `Modules/Usuario/resources/js/Components/UsuarioToolbar.vue`
- Modify: `Modules/Usuario/resources/js/Components/UsuarioCreateModal.vue`
- Modify: `Modules/Usuario/resources/js/Pages/Alunos.vue`, `Professores.vue`, `Funcionarios.vue`, `Administradores.vue`, `Encarregados.vue`, `Index.vue`

**Interfaces:**
- Consumes: `UsuarioWizardForm` (Task 3); props `perfis`/`modulos`/`acoes`/`permissoesPorPerfil` (Task 1); `GET /usuarios/{id}/editar` (Task 2).

- [ ] **Step 1: Reescrever AlunoForm.vue como casca fina**

Substituir o conteúdo inteiro de `Modules/Usuario/resources/js/Forms/Alunos/AlunoForm.vue`:

```html
<script setup>
defineProps({
    perfis: { type: Array, required: true },
    modulos: { type: Array, required: true },
    acoes: { type: Array, required: true },
    permissoesPorPerfil: { type: Object, required: true },
    utilizador: { type: Object, default: null },
});
</script>

<template>
    <UsuarioWizardForm
        perfil-fixo="aluno"
        :perfis="perfis"
        :modulos="modulos"
        :acoes="acoes"
        :permissoes-por-perfil="permissoesPorPerfil"
        :utilizador="utilizador"
        rota-criar="/usuarios/alunos/cadastrar"
    />
</template>

<script>
import UsuarioWizardForm from '../../Components/UsuarioWizardForm.vue';
export default { components: { UsuarioWizardForm } };
</script>
```

- [ ] **Step 2: Reescrever ProfessorForm.vue, FuncionarioForm.vue, AdministradorForm.vue e EncarregadoForm.vue**

`Modules/Usuario/resources/js/Forms/Professores/ProfessorForm.vue`:

```html
<script setup>
defineProps({
    perfis: { type: Array, required: true },
    modulos: { type: Array, required: true },
    acoes: { type: Array, required: true },
    permissoesPorPerfil: { type: Object, required: true },
    utilizador: { type: Object, default: null },
});
</script>

<template>
    <UsuarioWizardForm
        perfil-fixo="professor"
        :perfis="perfis"
        :modulos="modulos"
        :acoes="acoes"
        :permissoes-por-perfil="permissoesPorPerfil"
        :utilizador="utilizador"
        rota-criar="/usuarios/professores/cadastrar"
    />
</template>

<script>
import UsuarioWizardForm from '../../Components/UsuarioWizardForm.vue';
export default { components: { UsuarioWizardForm } };
</script>
```

`Modules/Usuario/resources/js/Forms/Funcionarios/FuncionarioForm.vue`:

```html
<script setup>
defineProps({
    perfis: { type: Array, required: true },
    modulos: { type: Array, required: true },
    acoes: { type: Array, required: true },
    permissoesPorPerfil: { type: Object, required: true },
    utilizador: { type: Object, default: null },
});
</script>

<template>
    <UsuarioWizardForm
        perfil-fixo="secretario"
        :perfis="perfis"
        :modulos="modulos"
        :acoes="acoes"
        :permissoes-por-perfil="permissoesPorPerfil"
        :utilizador="utilizador"
        rota-criar="/usuarios/funcionarios/cadastrar"
    />
</template>

<script>
import UsuarioWizardForm from '../../Components/UsuarioWizardForm.vue';
export default { components: { UsuarioWizardForm } };
</script>
```

`Modules/Usuario/resources/js/Forms/Administradores/AdministradorForm.vue`:

```html
<script setup>
defineProps({
    perfis: { type: Array, required: true },
    modulos: { type: Array, required: true },
    acoes: { type: Array, required: true },
    permissoesPorPerfil: { type: Object, required: true },
    utilizador: { type: Object, default: null },
});
</script>

<template>
    <UsuarioWizardForm
        perfil-fixo="admin_escola"
        :perfis="perfis"
        :modulos="modulos"
        :acoes="acoes"
        :permissoes-por-perfil="permissoesPorPerfil"
        :utilizador="utilizador"
        rota-criar="/usuarios/administradores/cadastrar"
    />
</template>

<script>
import UsuarioWizardForm from '../../Components/UsuarioWizardForm.vue';
export default { components: { UsuarioWizardForm } };
</script>
```

`Modules/Usuario/resources/js/Forms/Encarregados/EncarregadoForm.vue`:

```html
<script setup>
defineProps({
    perfis: { type: Array, required: true },
    modulos: { type: Array, required: true },
    acoes: { type: Array, required: true },
    permissoesPorPerfil: { type: Object, required: true },
    utilizador: { type: Object, default: null },
});
</script>

<template>
    <UsuarioWizardForm
        perfil-fixo="encarregado"
        :perfis="perfis"
        :modulos="modulos"
        :acoes="acoes"
        :permissoes-por-perfil="permissoesPorPerfil"
        :utilizador="utilizador"
        rota-criar="/usuarios/encarregados/cadastrar"
    />
</template>

<script>
import UsuarioWizardForm from '../../Components/UsuarioWizardForm.vue';
export default { components: { UsuarioWizardForm } };
</script>
```

- [ ] **Step 3: Reescrever UsuarioForm.vue (genérico, sem perfilFixo)**

```html
<script setup>
defineProps({
    perfis: { type: Array, required: true },
    modulos: { type: Array, required: true },
    acoes: { type: Array, required: true },
    permissoesPorPerfil: { type: Object, required: true },
    utilizador: { type: Object, default: null },
});
</script>

<template>
    <UsuarioWizardForm
        :perfis="perfis"
        :modulos="modulos"
        :acoes="acoes"
        :permissoes-por-perfil="permissoesPorPerfil"
        :utilizador="utilizador"
        rota-criar="/usuarios/cadastrarUsuario"
    />
</template>

<script>
import UsuarioWizardForm from '../Components/UsuarioWizardForm.vue';
export default { components: { UsuarioWizardForm } };
</script>
```

- [ ] **Step 4: Passar as novas props em cada Page.vue**

Em cada um dos 6 `Pages/*.vue`, adicionar `perfis`, `modulos`, `acoes`, `permissoesPorPerfil` a `defineProps` e passá-los ao componente de formulário via `UsuarioListLayout`. `UsuarioListLayout.vue` precisa de aceitar e reencaminhar estas 4 props para o `formComponent` — editar `Modules/Usuario/resources/js/Components/UsuarioListLayout.vue`:

```html
<script setup>
import UsuarioTable from './UsuarioTable.vue';
import UsuarioToolbar from './UsuarioToolbar.vue';

defineProps({
    title: { type: String, required: true },
    icon: { type: String, required: true },
    accent: { type: String, default: 'primary' },
    usuarios: { type: Array, required: true },
    formComponent: { type: [Object, Function], default: undefined },
    perfis: { type: Array, default: () => [] },
    modulos: { type: Array, default: () => [] },
    acoes: { type: Array, default: () => [] },
    permissoesPorPerfil: { type: Object, default: () => ({}) },
});
</script>
```

Continuar a editar `UsuarioListLayout.vue`: adicionar o estado partilhado de edição e o listener que o limpa quando o modal fecha (por qualquer via — X, Cancelar, ou guardar com sucesso):

```html
<script setup>
import { onMounted, ref } from 'vue';
import UsuarioTable from './UsuarioTable.vue';
import UsuarioToolbar from './UsuarioToolbar.vue';

defineProps({
    title: { type: String, required: true },
    icon: { type: String, required: true },
    accent: { type: String, default: 'primary' },
    usuarios: { type: Array, required: true },
    formComponent: { type: [Object, Function], default: undefined },
    perfis: { type: Array, default: () => [] },
    modulos: { type: Array, default: () => [] },
    acoes: { type: Array, default: () => [] },
    permissoesPorPerfil: { type: Object, default: () => ({}) },
});

const utilizadorEmEdicao = ref(null);

function abrirEdicao(utilizador) {
    utilizadorEmEdicao.value = utilizador;
    window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('kt_modal_add_user')).show();
}

onMounted(() => {
    document.getElementById('kt_modal_add_user')?.addEventListener('hidden.bs.modal', () => {
        utilizadorEmEdicao.value = null;
    });
});
</script>
```

No `<template>`, atualizar as tags `UsuarioToolbar` e `UsuarioTable` existentes:

```html
    <UsuarioToolbar
        :form-component="formComponent"
        :utilizador-em-edicao="utilizadorEmEdicao"
        :perfis="perfis"
        :modulos="modulos"
        :acoes="acoes"
        :permissoes-por-perfil="permissoesPorPerfil"
    />
```

```html
    <UsuarioTable :usuarios="usuarios" @editar="abrirEdicao" />
```

Reescrever `Modules/Usuario/resources/js/Components/UsuarioToolbar.vue` para aceitar e reencaminhar as novas props ao `UsuarioCreateModal` (só a parte do `<script setup>` e a linha do `UsuarioCreateModal` no template mudam — o resto do ficheiro, toolbar de filtro/exportar, fica igual):

```html
<script setup>
import UsuarioExportModal from './UsuarioExportModal.vue';
import UsuarioCreateModal from './UsuarioCreateModal.vue';

defineProps({
    formComponent: {
        type: [Object, Function],
        default: undefined,
    },
    utilizadorEmEdicao: { type: Object, default: null },
    perfis: { type: Array, default: () => [] },
    modulos: { type: Array, default: () => [] },
    acoes: { type: Array, default: () => [] },
    permissoesPorPerfil: { type: Object, default: () => ({}) },
});
</script>
```

```html
        <UsuarioExportModal />
        <UsuarioCreateModal
            :form-component="formComponent"
            :utilizador="utilizadorEmEdicao"
            :perfis="perfis"
            :modulos="modulos"
            :acoes="acoes"
            :permissoes-por-perfil="permissoesPorPerfil"
        />
```

Reescrever `Modules/Usuario/resources/js/Components/UsuarioCreateModal.vue` por inteiro:

```html
<script setup>
import UsuarioForm from '../Forms/UsuarioForm.vue';

defineProps({
    formComponent: {
        type: [Object, Function],
        default: () => UsuarioForm,
    },
    utilizador: { type: Object, default: null },
    perfis: { type: Array, default: () => [] },
    modulos: { type: Array, default: () => [] },
    acoes: { type: Array, default: () => [] },
    permissoesPorPerfil: { type: Object, default: () => ({}) },
});
</script>

<template>
    <!--begin::Modal - Add user-->
    <div class="modal fade" id="kt_modal_add_user" tabindex="-1" aria-hidden="true">
        <!--begin::Modal dialog-->
        <div class="modal-dialog modal-dialog-centered mw-650px">
            <!--begin::Modal content-->
            <div class="modal-content">
                <!--begin::Modal header-->
                <div class="modal-header" id="kt_modal_add_user_header">
                    <!--begin::Modal title-->
                    <h2 class="fw-bold">{{ utilizador ? 'Editar Utilizador' : 'Registar Novo Utilizador' }}</h2>
                    <!--end::Modal title-->

                    <!--begin::Close-->
                    <div class="btn btn-icon btn-sm btn-active-icon-primary" data-bs-dismiss="modal">
                        <i class="ki-duotone ki-cross fs-1"><span class="path1"></span><span class="path2"></span></i>
                    </div>
                    <!--end::Close-->
                </div>
                <!--end::Modal header-->

                <!--begin::Modal body-->
                <div class="modal-body scroll-y mx-5 mx-xl-15 my-7">
                    <component
                        :is="formComponent"
                        :key="utilizador?.id ?? 'novo'"
                        :utilizador="utilizador"
                        :perfis="perfis"
                        :modulos="modulos"
                        :acoes="acoes"
                        :permissoes-por-perfil="permissoesPorPerfil"
                    />
                </div>
                <!--end::Modal body-->
            </div>
            <!--end::Modal content-->
        </div>
        <!--end::Modal dialog-->
    </div>
    <!--end::Modal - Add user-->
</template>
```

Em cada um dos 6 `Pages/*.vue`, o único ficheiro a mudar é o bloco `defineProps` (adicionar as 4 props novas) e os atributos da tag `UsuarioListLayout` (adicionar os 4 `v-bind` novos) — o resto do ficheiro (imports de `onMounted`/`usePageScripts`, `defineOptions`, a lista de scripts do Metronic) fica exatamente igual ao que já está.

`defineProps` novo, igual nos 6 ficheiros:

```js
defineProps({
    usuarios: { type: Array, required: true },
    perfis: { type: Array, required: true },
    modulos: { type: Array, required: true },
    acoes: { type: Array, required: true },
    permissoesPorPerfil: { type: Object, required: true },
});
```

Atributos a adicionar em cada `<UsuarioListLayout>` (mantendo `title`/`icon`/`accent`/`form-component` como já estão hoje em cada página):

```html
        :perfis="perfis"
        :modulos="modulos"
        :acoes="acoes"
        :permissoes-por-perfil="permissoesPorPerfil"
```

Concretamente, cada `<UsuarioListLayout>` fica:

`Pages/Alunos.vue`:
```html
    <UsuarioListLayout
        title="Alunos"
        icon="ki-profile-user"
        accent="primary"
        :usuarios="usuarios"
        :form-component="AlunoForm"
        :perfis="perfis"
        :modulos="modulos"
        :acoes="acoes"
        :permissoes-por-perfil="permissoesPorPerfil"
    />
```

`Pages/Professores.vue`:
```html
    <UsuarioListLayout
        title="Professores"
        icon="ki-teacher"
        accent="success"
        :usuarios="usuarios"
        :form-component="ProfessorForm"
        :perfis="perfis"
        :modulos="modulos"
        :acoes="acoes"
        :permissoes-por-perfil="permissoesPorPerfil"
    />
```

`Pages/Funcionarios.vue`:
```html
    <UsuarioListLayout
        title="Funcionários"
        icon="ki-briefcase"
        accent="warning"
        :usuarios="usuarios"
        :form-component="FuncionarioForm"
        :perfis="perfis"
        :modulos="modulos"
        :acoes="acoes"
        :permissoes-por-perfil="permissoesPorPerfil"
    />
```

`Pages/Administradores.vue`:
```html
    <UsuarioListLayout
        title="Administradores"
        icon="ki-shield-tick"
        accent="danger"
        :usuarios="usuarios"
        :form-component="AdministradorForm"
        :perfis="perfis"
        :modulos="modulos"
        :acoes="acoes"
        :permissoes-por-perfil="permissoesPorPerfil"
    />
```

`Pages/Encarregados.vue`:
```html
    <UsuarioListLayout
        title="Encarregados"
        icon="ki-people"
        accent="info"
        :usuarios="usuarios"
        :form-component="EncarregadoForm"
        :perfis="perfis"
        :modulos="modulos"
        :acoes="acoes"
        :permissoes-por-perfil="permissoesPorPerfil"
    />
```

`Pages/Index.vue` (sem `form-component` — o `UsuarioCreateModal` já usa `UsuarioForm` genérico por omissão):
```html
    <UsuarioListLayout
        title="Todos os Utilizadores"
        icon="ki-people"
        accent="dark"
        :usuarios="usuarios"
        :perfis="perfis"
        :modulos="modulos"
        :acoes="acoes"
        :permissoes-por-perfil="permissoesPorPerfil"
    />
```

- [ ] **Step 5: Ligar o botão Edit em UsuarioTable.vue**

Editar `Modules/Usuario/resources/js/Components/UsuarioTable.vue`: adicionar `defineEmits`, a função `editar()`, e trocar o link "Edit" de `href="#"` (sem handler) para chamar essa função:

```html
<script setup>
import UsuarioAvatar from './UsuarioAvatar.vue';
import UsuarioStatusBadge from './UsuarioStatusBadge.vue';

defineProps({
    usuarios: {
        type: Array,
        required: true,
    },
});
const emit = defineEmits(['editar']);

async function editar(usuario) {
    const resposta = await fetch(`/usuarios/${usuario.id}/editar`, {
        headers: { Accept: 'application/json' },
    });
    const dados = await resposta.json();
    emit('editar', dados);
}
</script>
```

No template, substituir:

```html
                        <div class="menu-item px-3">
                            <a href="#" class="menu-link px-3">
                                Edit
                            </a>
                        </div>
```

por:

```html
                        <div class="menu-item px-3">
                            <a href="#" class="menu-link px-3" @click.prevent="editar(usuario)">
                                Edit
                            </a>
                        </div>
```

- [ ] **Step 6: Compilar o frontend**

Run: `npm run build`
Expected: build sem erros.

- [ ] **Step 7: Correr a suite completa de backend por garantia**

Run: `php artisan test Modules`
Expected: PASS em todos

- [ ] **Step 8: Commit**

Sem commits (ver Global Constraints) — `git add` apenas.
