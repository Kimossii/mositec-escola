# Configuração de Etapas de Ensino no Estabelecimento — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** O Estabelecimento passa a declarar antecipadamente quais Etapas de Ensino opera (fixo em Superior para Universitário; escolha livre por checkboxes, nada pré-seleccionado, para Geral/Técnico); `NivelAcademico` só pode escolher entre as etapas declaradas; remove-se o método derivado (`NivelAcademicoConsultaService::etapasEnsino()`) que a feature anterior tinha criado como fonte de verdade provisória.

**Architecture:** Nova tabela `estabelecimento_etapas_ensino` (módulo Estabelecimento, sem CRUD próprio — gerida dentro do fluxo já existente `AtualizarDadosEstabelecimentoAction`). `NivelAcademico`'s Form Requests passam a validar `etapa_ensino` contra essa configuração em vez de `in:1,2,3,4,5` fixo. Excepção de fronteira assumida e documentada: `Modules\Estabelecimento` passa a importar `Modules\Turma\Models\NivelAcademico` só para o bloqueio de remoção de etapa em uso.

**Tech Stack:** Laravel 12 + nwidart/laravel-modules, Inertia + Vue 3, PHPUnit (`Tests\TestCase`, `RefreshDatabase`, sqlite `:memory:` em testes).

**Spec:** `docs/superpowers/specs/2026-09-11-etapas-ensino-configuracao-estabelecimento-design.md`

## Global Constraints

- Tipo de Ensino continua single-select (Geral/Técnico/Universitário) — **sem alterações** a `TipoEnsinoEnum` nem à coluna `estabelecimentos.tipo_ensino`.
- `UNIVERSITARIO` → Etapas de Ensino fica **sempre** `[SUPERIOR]`, decidido pelo backend, **ignorando** qualquer `etapas_ensino` que o pedido enviar.
- `GERAL`/`TECNICO` → utilizador escolhe livremente entre `{CRECHE, PRE_ESCOLAR, PRIMARIO, SECUNDARIO}` (nunca `SUPERIOR`). **Nada pré-seleccionado por omissão** — nem no backend nem no frontend.
- Remover uma etapa já usada por algum `NivelAcademico` do estabelecimento é **bloqueado** com erro de validação — nunca silenciosamente permitido.
- Tabela relacional (`estabelecimento_etapas_ensino`), **nunca** coluna JSON — este projecto testa em sqlite e corre em produção em pgsql, dialectos de JSON divergem.
- Sem `Controller`/`Action`/`Service` dedicados à tabela nova — gerida por inteiro dentro do fluxo já existente de `AtualizarDadosEstabelecimentoAction`.
- `NivelAcademicoConsultaService::etapasEnsino()` (método da feature anterior) e o seu ficheiro de teste dedicado são **removidos**, não mantidos "por via das dúvidas".
- `use` sempre no topo de cada ficheiro PHP; nunca FQN inline.
- Controllers finos: leitura em `*ConsultaService`/métodos de leitura do `Gestao*Service`, escrita em `Gestao*Service` → `*Action`.

---

## Estrutura de Ficheiros

**Novo:**
```
Modules/Estabelecimento/database/migrations/2026_09_11_120000_create_estabelecimento_etapas_ensino_table.php
Modules/Estabelecimento/app/Models/EstabelecimentoEtapaEnsino.php
```

**Modificado:**
```
Modules/Estabelecimento/app/Models/Estabelecimento.php                    — relação etapasEnsino()
Modules/Estabelecimento/app/DTO/EstabelecimentoDTO.php                    — campo etapas_ensino
Modules/Estabelecimento/app/Http/Requests/AtualizarDadosRequest.php       — validação etapas_ensino
Modules/Estabelecimento/app/Actions/AtualizarDadosEstabelecimentoAction.php — sincroniza etapas + bloqueio de remoção
Modules/Estabelecimento/app/Services/GestaoEstabelecimentoService.php     — etapasEnsinoConfiguradas()
Modules/Estabelecimento/app/Http/Controllers/EstabelecimentoController.php — prop etapasEnsino
Modules/Estabelecimento/tests/Feature/GestaoEstabelecimentoTest.php       — fixtures + novos testes
Modules/Estabelecimento/resources/js/Components/CampoFicha.vue           — type="checkboxes" novo
Modules/Estabelecimento/resources/js/Pages/DadosDaEscola.vue             — campo Etapas de Ensino

Modules/Turma/app/Http/Requests/CriarNivelAcademicoRequest.php           — etapa_ensino validado contra configuração
Modules/Turma/app/Http/Requests/AtualizarNivelAcademicoRequest.php       — idem
Modules/Turma/app/Http/Controllers/NivelAcademicoController.php          — prop etapasEnsino
Modules/Turma/app/Services/NivelAcademicoConsultaService.php             — remove etapasEnsino()
Modules/Turma/resources/js/Pages/NiveisAcademicos/Index.vue              — forward da prop etapasEnsino
Modules/Turma/resources/js/Components/NivelAcademico/NivelAcademicoFormModal.vue — opções por prop, não import estático
Modules/Turma/tests/Feature/TurmaHttpTest.php                            — helper criarEstabelecimento() configura etapas

Removido:
Modules/Turma/tests/Feature/NivelAcademicoConsultaServiceTest.php
```

---

## Task 1: Tabela + Model `EstabelecimentoEtapaEnsino` + relação em `Estabelecimento`

**Files:**
- Create: `Modules/Estabelecimento/database/migrations/2026_09_11_120000_create_estabelecimento_etapas_ensino_table.php`
- Create: `Modules/Estabelecimento/app/Models/EstabelecimentoEtapaEnsino.php`
- Modify: `Modules/Estabelecimento/app/Models/Estabelecimento.php`
- Test: `Modules/Estabelecimento/tests/Feature/GestaoEstabelecimentoTest.php` (só o teste novo desta task)

**Interfaces:**
- Consumes: `EtapaEnsinoEnum`, `TipoEnsinoEnum` (`Modules\Estabelecimento\Enums`).
- Produces: `Estabelecimento::etapasEnsino(): HasMany` → `EstabelecimentoEtapaEnsino`; usado pelas Tasks 2, 3, 4.

- [ ] **Step 1: Escrever o teste que expõe a relação e a descrição sincronizada**

Adicionar a `Modules/Estabelecimento/tests/Feature/GestaoEstabelecimentoTest.php`, no topo, os imports que faltam:

```php
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Models\EstabelecimentoEtapaEnsino;
```

E o teste (colocar a seguir a `test_nao_atualiza_logotipo_sem_estabelecimento_cadastrado`):

```php
    public function test_etapas_ensino_relaciona_com_estabelecimento_e_sincroniza_descricao(): void
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Exemplo', 'tipo' => 1, 'tipo_ensino' => TipoEnsinoEnum::GERAL, 'is_active' => true]);

        $etapa = EstabelecimentoEtapaEnsino::create([
            'estabelecimento_id' => $estabelecimento->id,
            'etapa_ensino' => EtapaEnsinoEnum::PRIMARIO,
        ]);

        $this->assertSame(EtapaEnsinoEnum::PRIMARIO, $etapa->etapa_ensino);
        $this->assertSame('Ensino Primário', $etapa->etapa_ensino_descricao);
        $this->assertCount(1, $estabelecimento->etapasEnsino);
        $this->assertSame(EtapaEnsinoEnum::PRIMARIO, $estabelecimento->etapasEnsino->first()->etapa_ensino);
    }
```

- [ ] **Step 2: Correr o teste e confirmar que falha**

```bash
php artisan test --filter=test_etapas_ensino_relaciona_com_estabelecimento_e_sincroniza_descricao
```

Esperado: FAIL — `Class "Modules\Estabelecimento\Models\EstabelecimentoEtapaEnsino" not found`.

- [ ] **Step 3: Criar a migração (com backfill dos dados existentes)**

`Modules/Estabelecimento/database/migrations/2026_09_11_120000_create_estabelecimento_etapas_ensino_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Enums\TipoEnsinoEnum;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estabelecimento_etapas_ensino', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estabelecimento_id')->constrained('estabelecimentos')->cascadeOnDelete();
            $table->unsignedTinyInteger('etapa_ensino');
            $table->string('etapa_ensino_descricao');
            $table->timestamps();

            $table->unique(['estabelecimento_id', 'etapa_ensino']);
        });

        $this->backfillEtapasExistentes();
    }

    public function down(): void
    {
        Schema::dropIfExists('estabelecimento_etapas_ensino');
    }

    private function backfillEtapasExistentes(): void
    {
        $agora = now();

        DB::table('estabelecimentos')->select('id', 'tipo_ensino')->get()->each(function ($estabelecimento) use ($agora) {
            if ((int) $estabelecimento->tipo_ensino === TipoEnsinoEnum::UNIVERSITARIO->value) {
                $etapas = [EtapaEnsinoEnum::SUPERIOR];
            } else {
                $etapas = DB::table('niveis_academicos')
                    ->where('estabelecimento_id', $estabelecimento->id)
                    ->distinct()
                    ->pluck('etapa_ensino')
                    ->map(fn ($valor) => EtapaEnsinoEnum::from((int) $valor))
                    ->all();
            }

            foreach ($etapas as $etapa) {
                DB::table('estabelecimento_etapas_ensino')->insert([
                    'estabelecimento_id' => $estabelecimento->id,
                    'etapa_ensino' => $etapa->value,
                    'etapa_ensino_descricao' => $etapa->label(),
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ]);
            }
        });
    }
};
```

Nota: `niveis_academicos.etapa_ensino` já existe e é `NOT NULL` desde a feature anterior — não há risco de `EtapaEnsinoEnum::from()` falhar aqui com um valor inesperado.

- [ ] **Step 4: Criar o Model**

`Modules/Estabelecimento/app/Models/EstabelecimentoEtapaEnsino.php`:

```php
<?php

namespace Modules\Estabelecimento\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;

class EstabelecimentoEtapaEnsino extends Model
{
    protected $table = 'estabelecimento_etapas_ensino';

    protected $fillable = [
        'estabelecimento_id',
        'etapa_ensino',
    ];

    protected $casts = [
        'etapa_ensino' => EtapaEnsinoEnum::class,
    ];

    public function estabelecimento(): BelongsTo
    {
        return $this->belongsTo(Estabelecimento::class, 'estabelecimento_id');
    }

    protected static function booted(): void
    {
        static::saving(function (self $etapa) {
            if ($etapa->etapa_ensino !== null) {
                $etapa->etapa_ensino_descricao = $etapa->etapa_ensino->label();
            }
        });
    }
}
```

- [ ] **Step 5: Adicionar a relação a `Estabelecimento`**

Editar `Modules/Estabelecimento/app/Models/Estabelecimento.php` — adicionar o import e o método, a seguir a `logotipoUrl()`:

```php
use Illuminate\Database\Eloquent\Relations\HasMany;
```

```php
    public function etapasEnsino(): HasMany
    {
        return $this->hasMany(EstabelecimentoEtapaEnsino::class, 'estabelecimento_id');
    }
```

(Sem novo import de `EstabelecimentoEtapaEnsino` necessário — mesmo namespace `Modules\Estabelecimento\Models`.)

- [ ] **Step 6: Correr o teste e confirmar que passa**

```bash
php artisan test --filter=test_etapas_ensino_relaciona_com_estabelecimento_e_sincroniza_descricao
```

Esperado: PASS.

- [ ] **Step 7: Correr toda a suite de Estabelecimento para confirmar que nada quebrou**

```bash
php artisan test --filter=Estabelecimento
```

Esperado: PASS em todos (esta task não altera nenhum fluxo de validação existente, só adiciona).

- [ ] **Step 8: Commit**

```bash
git add Modules/Estabelecimento/database/migrations/2026_09_11_120000_create_estabelecimento_etapas_ensino_table.php \
        Modules/Estabelecimento/app/Models/EstabelecimentoEtapaEnsino.php \
        Modules/Estabelecimento/app/Models/Estabelecimento.php \
        Modules/Estabelecimento/tests/Feature/GestaoEstabelecimentoTest.php
git commit -m "feat(estabelecimento): tabela e model EstabelecimentoEtapaEnsino"
```

---

## Task 2: `AtualizarDadosRequest` + `EstabelecimentoDTO` + `AtualizarDadosEstabelecimentoAction`

**Files:**
- Modify: `Modules/Estabelecimento/app/Http/Requests/AtualizarDadosRequest.php`
- Modify: `Modules/Estabelecimento/app/DTO/EstabelecimentoDTO.php`
- Modify: `Modules/Estabelecimento/app/Actions/AtualizarDadosEstabelecimentoAction.php`
- Modify: `Modules/Estabelecimento/tests/Feature/GestaoEstabelecimentoTest.php`

**Interfaces:**
- Consumes: `Estabelecimento::etapasEnsino()` (Task 1).
- Produces: `EstabelecimentoDTO::$etapas_ensino` (`array<EtapaEnsinoEnum>`) — nenhuma task posterior o consome directamente, mas a Action que o consome é o efeito visível desta task.

**Nota importante:** esta task torna `etapas_ensino` obrigatório no pedido `PUT /estabelecimento` sempre que `tipo_ensino` não for Universitário. Isto **quebra 3 testes já existentes** em `GestaoEstabelecimentoTest.php` que hoje enviam `tipo_ensino=GERAL`/`TECNICO` sem `etapas_ensino`. Corrigir essas 3 fixtures faz parte desta mesma task (não deixar a suite vermelha entre tasks).

- [ ] **Step 1: Corrigir as 3 fixtures existentes que vão passar a falhar, e escrever os 3 testes novos**

Em `Modules/Estabelecimento/tests/Feature/GestaoEstabelecimentoTest.php`:

**1a.** `test_cria_o_estabelecimento_ao_atualizar_dados_pela_primeira_vez` — adicionar `etapas_ensino` ao payload:

```php
    public function test_cria_o_estabelecimento_ao_atualizar_dados_pela_primeira_vez(): void
    {
        $this->actingAsAdmin();

        $response = $this->put('/estabelecimento', [
            'nome' => 'Escola Exemplo',
            'tipo' => TipoEstabelecimentoEnum::PRIVADO->value,
            'tipo_ensino' => TipoEnsinoEnum::GERAL->value,
            'etapas_ensino' => [EtapaEnsinoEnum::PRIMARIO->value],
            'nif' => '5000123456',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('estabelecimentos', [
            'nome' => 'Escola Exemplo',
            'tipo' => TipoEstabelecimentoEnum::PRIVADO->value,
            'tipo_descricao' => 'Privado',
            'tipo_ensino' => TipoEnsinoEnum::GERAL->value,
            'tipo_ensino_descricao' => 'Ensino Geral',
            'is_active' => true,
        ]);
    }
```

**1b.** `test_atualiza_o_estabelecimento_atual_em_vez_de_duplicar` — adicionar `etapas_ensino`:

```php
    public function test_atualiza_o_estabelecimento_atual_em_vez_de_duplicar(): void
    {
        $this->actingAsAdmin();

        Estabelecimento::create(['nome' => 'Escola Antiga', 'tipo' => TipoEstabelecimentoEnum::PUBLICO, 'tipo_ensino' => TipoEnsinoEnum::GERAL, 'is_active' => true]);

        $response = $this->put('/estabelecimento', [
            'nome' => 'Escola Renomeada',
            'tipo' => TipoEstabelecimentoEnum::COOPERATIVO->value,
            'tipo_ensino' => TipoEnsinoEnum::TECNICO->value,
            'etapas_ensino' => [EtapaEnsinoEnum::SECUNDARIO->value],
        ]);

        $response->assertRedirect();
        $this->assertSame(1, Estabelecimento::count());
        $this->assertDatabaseHas('estabelecimentos', [
            'nome' => 'Escola Renomeada',
            'tipo' => TipoEstabelecimentoEnum::COOPERATIVO->value,
        ]);
    }
```

**1c.** `test_atualiza_tipo_ensino_com_valores_validos` — enviar `etapas_ensino` só para Geral/Técnico:

```php
    public function test_atualiza_tipo_ensino_com_valores_validos(): void
    {
        $this->actingAsAdmin();

        foreach ([1 => 'Ensino Geral', 2 => 'Ensino Técnico', 3 => 'Ensino Universitário'] as $valor => $descricao) {
            $payload = [
                'nome' => 'Escola Teste',
                'tipo' => TipoEstabelecimentoEnum::PRIVADO->value,
                'tipo_ensino' => $valor,
            ];
            if ($valor !== TipoEnsinoEnum::UNIVERSITARIO->value) {
                $payload['etapas_ensino'] = [EtapaEnsinoEnum::PRIMARIO->value];
            }

            $this->put('/estabelecimento', $payload)->assertSessionHasNoErrors();

            $this->assertDatabaseHas('estabelecimentos', [
                'tipo_ensino' => $valor,
                'tipo_ensino_descricao' => $descricao,
            ]);
        }
    }
```

**1d.** Três testes novos, a seguir a `test_rejeita_tipo_ensino_invalido`:

```php
    public function test_universitario_forca_etapa_superior_independente_do_enviado(): void
    {
        $this->actingAsAdmin();

        $this->put('/estabelecimento', [
            'nome' => 'Instituto Superior',
            'tipo' => TipoEstabelecimentoEnum::PRIVADO->value,
            'tipo_ensino' => TipoEnsinoEnum::UNIVERSITARIO->value,
            'etapas_ensino' => [EtapaEnsinoEnum::PRIMARIO->value],
        ])->assertSessionHasNoErrors();

        $etapas = Estabelecimento::current()->etapasEnsino()->pluck('etapa_ensino')->map(fn (EtapaEnsinoEnum $e) => $e->value)->all();
        $this->assertSame([EtapaEnsinoEnum::SUPERIOR->value], $etapas);
    }

    public function test_remove_etapa_sem_niveis_academicos_associados(): void
    {
        $this->actingAsAdmin();
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola', 'tipo' => 1, 'tipo_ensino' => TipoEnsinoEnum::GERAL, 'is_active' => true]);
        EstabelecimentoEtapaEnsino::create(['estabelecimento_id' => $estabelecimento->id, 'etapa_ensino' => EtapaEnsinoEnum::PRIMARIO]);
        EstabelecimentoEtapaEnsino::create(['estabelecimento_id' => $estabelecimento->id, 'etapa_ensino' => EtapaEnsinoEnum::SECUNDARIO]);

        $this->put('/estabelecimento', [
            'nome' => 'Escola',
            'tipo' => TipoEstabelecimentoEnum::PRIVADO->value,
            'tipo_ensino' => TipoEnsinoEnum::GERAL->value,
            'etapas_ensino' => [EtapaEnsinoEnum::PRIMARIO->value],
        ])->assertSessionHasNoErrors();

        $etapas = $estabelecimento->etapasEnsino()->pluck('etapa_ensino')->map(fn (EtapaEnsinoEnum $e) => $e->value)->all();
        $this->assertSame([EtapaEnsinoEnum::PRIMARIO->value], $etapas);
    }

    public function test_remove_etapa_com_niveis_academicos_associados_falha(): void
    {
        $this->actingAsAdmin();
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola', 'tipo' => 1, 'tipo_ensino' => TipoEnsinoEnum::GERAL, 'is_active' => true]);
        EstabelecimentoEtapaEnsino::create(['estabelecimento_id' => $estabelecimento->id, 'etapa_ensino' => EtapaEnsinoEnum::PRIMARIO]);
        EstabelecimentoEtapaEnsino::create(['estabelecimento_id' => $estabelecimento->id, 'etapa_ensino' => EtapaEnsinoEnum::SECUNDARIO]);
        \Modules\Turma\Models\NivelAcademico::create([
            'estabelecimento_id' => $estabelecimento->id,
            'codigo' => '10C',
            'nome' => '10ª Classe',
            'ordem' => 10,
            'etapa_ensino' => EtapaEnsinoEnum::SECUNDARIO,
        ]);

        $this->put('/estabelecimento', [
            'nome' => 'Escola',
            'tipo' => TipoEstabelecimentoEnum::PRIVADO->value,
            'tipo_ensino' => TipoEnsinoEnum::GERAL->value,
            'etapas_ensino' => [EtapaEnsinoEnum::PRIMARIO->value],
        ])->assertSessionHasErrors('etapas_ensino');

        $this->assertDatabaseHas('estabelecimento_etapas_ensino', [
            'estabelecimento_id' => $estabelecimento->id,
            'etapa_ensino' => EtapaEnsinoEnum::SECUNDARIO->value,
        ]);
    }
```

- [ ] **Step 2: Correr a suite e confirmar o padrão de falhas esperado**

```bash
php artisan test --filter=GestaoEstabelecimentoTest
```

Esperado: FAIL nos testes 1a, 1b, 1c (por `etapas_ensino` não ser reconhecido/validado ainda — a Request actual ignora chaves desconhecidas, por isso estes na verdade falham por outra razão até à Step 3: ainda `assertSessionHasNoErrors` deve passar porque a regra nova não existe; a falha real só aparece depois da Step 3 se a Request ficar mais restritiva sem a Action acompanhar). Os 3 testes novos (1d) falham já agora — `etapas_ensino` não é sequer guardado.

- [ ] **Step 3: Actualizar `AtualizarDadosRequest`**

`Modules/Estabelecimento/app/Http/Requests/AtualizarDadosRequest.php`:

```php
<?php

namespace Modules\Estabelecimento\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Enums\TipoEnsinoEnum;

class AtualizarDadosRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('estabelecimento.editar') ?? false;
    }

    public function rules(): array
    {
        return [
            'nome' => 'required|string|max:255',
            'nome_abreviado' => 'nullable|string|max:100',
            'tipo' => 'required|integer|in:1,2,3',
            'tipo_ensino' => 'required|integer|in:1,2,3',
            'etapas_ensino' => [
                'array',
                Rule::requiredIf(fn () => TipoEnsinoEnum::tryFrom((int) $this->input('tipo_ensino')) !== TipoEnsinoEnum::UNIVERSITARIO),
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
            'nif' => 'nullable|string|max:50',
            'codigo_mined' => 'nullable|string|max:50',
            'numero_alvara' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'telefone' => 'nullable|string|max:30',
            'telefone_alternativo' => 'nullable|string|max:30',
            'website' => 'nullable|string|max:255',
            'endereco' => 'nullable|string|max:255',
            'caixa_postal' => 'nullable|string|max:50',
            'municipio' => 'nullable|string|max:100',
            'provincia' => 'nullable|string|max:100',
            'responsavel_nome' => 'nullable|string|max:255',
            'responsavel_cargo' => 'nullable|string|max:100',
            'ano_fundacao' => 'nullable|integer|min:1900|max:' . (int) date('Y'),
            'observacoes' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'nome.required' => 'O nome do estabelecimento é obrigatório.',
            'tipo.required' => 'O tipo de estabelecimento é obrigatório.',
            'tipo.in' => 'O tipo de estabelecimento indicado é inválido.',
            'tipo_ensino.required' => 'O tipo de ensino é obrigatório.',
            'tipo_ensino.in' => 'O tipo de ensino indicado é inválido.',
            'etapas_ensino.required' => 'Selecione pelo menos uma etapa de ensino.',
            'etapas_ensino.min' => 'Selecione pelo menos uma etapa de ensino.',
            'etapas_ensino.*.in' => 'Uma das etapas de ensino indicadas é inválida.',
            'email.email' => 'Informe um email válido.',
            'ano_fundacao.integer' => 'O ano de fundação deve ser um número válido.',
        ];
    }
}
```

Nota deliberada: o closure usa `TipoEnsinoEnum::tryFrom()`, nunca `::from()` — `tipo_ensino` pode chegar inválido (ex.: `99`, testado em `test_rejeita_tipo_ensino_invalido`), e `::from()" lançaria `ValueError` não apanhado antes da validação sequer correr. `tryFrom()` devolve `null` nesse caso, que é `!== UNIVERSITARIO`, por isso `etapas_ensino` fica exigido — resultado correcto (erro de validação em vez de crash), mesmo que `tipo_ensino` também acabe com erro próprio.

- [ ] **Step 4: Actualizar `EstabelecimentoDTO`**

Editar `Modules/Estabelecimento/app/DTO/EstabelecimentoDTO.php` — adicionar o import e o campo:

```php
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
```

Construtor: adicionar `public array $etapas_ensino,` logo a seguir a `public TipoEnsinoEnum $tipo_ensino,`.

`fromRequest()`:

```php
    public static function fromRequest(AtualizarDadosRequest $request): self
    {
        $dados = $request->validated();
        $tipoEnsino = TipoEnsinoEnum::from((int) $dados['tipo_ensino']);

        return new self(
            nome: $dados['nome'],
            tipo: TipoEstabelecimentoEnum::from((int) $dados['tipo']),
            tipo_ensino: $tipoEnsino,
            etapas_ensino: $tipoEnsino === TipoEnsinoEnum::UNIVERSITARIO
                ? [EtapaEnsinoEnum::SUPERIOR]
                : array_map(fn ($v) => EtapaEnsinoEnum::from((int) $v), $dados['etapas_ensino'] ?? []),
            nome_abreviado: $dados['nome_abreviado'] ?? null,
            nif: $dados['nif'] ?? null,
            codigo_mined: $dados['codigo_mined'] ?? null,
            numero_alvara: $dados['numero_alvara'] ?? null,
            email: $dados['email'] ?? null,
            telefone: $dados['telefone'] ?? null,
            telefone_alternativo: $dados['telefone_alternativo'] ?? null,
            website: $dados['website'] ?? null,
            endereco: $dados['endereco'] ?? null,
            caixa_postal: $dados['caixa_postal'] ?? null,
            municipio: $dados['municipio'] ?? null,
            provincia: $dados['provincia'] ?? null,
            responsavel_nome: $dados['responsavel_nome'] ?? null,
            responsavel_cargo: $dados['responsavel_cargo'] ?? null,
            ano_fundacao: isset($dados['ano_fundacao']) ? (int) $dados['ano_fundacao'] : null,
            observacoes: $dados['observacoes'] ?? null,
        );
    }
```

(`::from()` aqui é seguro — corre depois de `$request->validated()`, que já garantiu `tipo_ensino` e cada item de `etapas_ensino` válidos.)

- [ ] **Step 5: Actualizar `AtualizarDadosEstabelecimentoAction`**

`Modules/Estabelecimento/app/Actions/AtualizarDadosEstabelecimentoAction.php`:

```php
<?php

namespace Modules\Estabelecimento\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Estabelecimento\DTO\EstabelecimentoDTO;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Estabelecimento\Models\EstabelecimentoEtapaEnsino;
use Modules\Turma\Models\NivelAcademico;

class AtualizarDadosEstabelecimentoAction
{
    public function executar(EstabelecimentoDTO $dto): Estabelecimento
    {
        return DB::transaction(function () use ($dto) {
            $estabelecimento = Estabelecimento::current() ?? new Estabelecimento(['is_active' => true]);

            $estabelecimento->fill([
                'nome' => $dto->nome,
                'nome_abreviado' => $dto->nome_abreviado,
                'tipo' => $dto->tipo,
                'tipo_ensino' => $dto->tipo_ensino,
                'nif' => $dto->nif,
                'codigo_mined' => $dto->codigo_mined,
                'numero_alvara' => $dto->numero_alvara,
                'email' => $dto->email,
                'telefone' => $dto->telefone,
                'telefone_alternativo' => $dto->telefone_alternativo,
                'website' => $dto->website,
                'endereco' => $dto->endereco,
                'caixa_postal' => $dto->caixa_postal,
                'municipio' => $dto->municipio,
                'provincia' => $dto->provincia,
                'responsavel_nome' => $dto->responsavel_nome,
                'responsavel_cargo' => $dto->responsavel_cargo,
                'ano_fundacao' => $dto->ano_fundacao,
                'observacoes' => $dto->observacoes,
            ]);

            $estabelecimento->save();

            $this->sincronizarEtapasEnsino($estabelecimento, $dto->etapas_ensino);

            return $estabelecimento->fresh();
        });
    }

    /**
     * @param  EtapaEnsinoEnum[]  $etapasAlvo
     */
    private function sincronizarEtapasEnsino(Estabelecimento $estabelecimento, array $etapasAlvo): void
    {
        $valoresAlvo = array_map(fn (EtapaEnsinoEnum $etapa) => $etapa->value, $etapasAlvo);

        $etapasActuais = $estabelecimento->etapasEnsino()->pluck('etapa_ensino');
        $etapasRemovidas = $etapasActuais->filter(fn (EtapaEnsinoEnum $etapa) => !in_array($etapa->value, $valoresAlvo, true));

        foreach ($etapasRemovidas as $etapaRemovida) {
            $temNiveis = NivelAcademico::where('estabelecimento_id', $estabelecimento->id)
                ->where('etapa_ensino', $etapaRemovida)
                ->exists();

            if ($temNiveis) {
                throw ValidationException::withMessages([
                    'etapas_ensino' => "Não é possível remover a etapa \"{$etapaRemovida->label()}\" porque já existem níveis académicos associados a ela.",
                ]);
            }
        }

        $estabelecimento->etapasEnsino()->whereNotIn('etapa_ensino', $valoresAlvo)->delete();

        foreach ($etapasAlvo as $etapa) {
            EstabelecimentoEtapaEnsino::firstOrCreate([
                'estabelecimento_id' => $estabelecimento->id,
                'etapa_ensino' => $etapa->value,
            ]);
        }
    }
}
```

Nota (excepção de fronteira de módulo, documentada na spec): esta é a primeira vez que `Modules\Estabelecimento` importa uma classe de `Modules\Turma`. Intencional — é a única forma de aplicar o bloqueio de remoção que o utilizador pediu.

- [ ] **Step 6: Correr a suite completa de Estabelecimento e confirmar que passa**

```bash
php artisan test --filter=Estabelecimento
```

Esperado: PASS em todos os testes (os 6 antigos corrigidos/existentes + os 3 novos).

- [ ] **Step 7: Commit**

```bash
git add Modules/Estabelecimento/app/Http/Requests/AtualizarDadosRequest.php \
        Modules/Estabelecimento/app/DTO/EstabelecimentoDTO.php \
        Modules/Estabelecimento/app/Actions/AtualizarDadosEstabelecimentoAction.php \
        Modules/Estabelecimento/tests/Feature/GestaoEstabelecimentoTest.php
git commit -m "feat(estabelecimento): valida, persiste e sincroniza etapas_ensino"
```

---

## Task 3: Expor as etapas configuradas para o frontend do Estabelecimento

**Files:**
- Modify: `Modules/Estabelecimento/app/Services/GestaoEstabelecimentoService.php`
- Modify: `Modules/Estabelecimento/app/Http/Controllers/EstabelecimentoController.php`
- Modify: `Modules/Estabelecimento/tests/Feature/GestaoEstabelecimentoTest.php`

**Interfaces:**
- Consumes: `Estabelecimento::etapasEnsino()` (Task 1).
- Produces: prop Inertia `etapasEnsino: number[]` na página `Estabelecimento/DadosDaEscola` — consumido pela Task 7 (frontend).

- [ ] **Step 1: Escrever o teste que expõe a prop nova**

Adicionar a `Modules/Estabelecimento/tests/Feature/GestaoEstabelecimentoTest.php`, a seguir a `test_pagina_dados_da_escola_devolve_o_estabelecimento_atual`:

```php
    public function test_pagina_dados_da_escola_expoe_as_etapas_de_ensino_configuradas(): void
    {
        $this->actingAsAdmin();

        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Exemplo', 'tipo' => TipoEstabelecimentoEnum::PUBLICO, 'tipo_ensino' => TipoEnsinoEnum::GERAL, 'is_active' => true]);
        EstabelecimentoEtapaEnsino::create(['estabelecimento_id' => $estabelecimento->id, 'etapa_ensino' => EtapaEnsinoEnum::PRIMARIO]);
        EstabelecimentoEtapaEnsino::create(['estabelecimento_id' => $estabelecimento->id, 'etapa_ensino' => EtapaEnsinoEnum::SECUNDARIO]);

        $this->get('/estabelecimento')->assertInertia(fn ($page) => $page
            ->component('Estabelecimento/DadosDaEscola')
            ->where('etapasEnsino', [EtapaEnsinoEnum::PRIMARIO->value, EtapaEnsinoEnum::SECUNDARIO->value])
        );
    }
```

- [ ] **Step 2: Correr o teste e confirmar que falha**

```bash
php artisan test --filter=test_pagina_dados_da_escola_expoe_as_etapas_de_ensino_configuradas
```

Esperado: FAIL — a prop `etapasEnsino` não existe na resposta Inertia.

- [ ] **Step 3: Adicionar o método de leitura ao Service**

Editar `Modules/Estabelecimento/app/Services/GestaoEstabelecimentoService.php` — adicionar o import e o método:

```php
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
```

```php
    public function etapasEnsinoConfiguradas(): array
    {
        return Estabelecimento::current()?->etapasEnsino()
            ->pluck('etapa_ensino')->map(fn (EtapaEnsinoEnum $e) => $e->value)->all() ?? [];
    }
```

- [ ] **Step 4: Passar a prop no Controller**

Editar `Modules/Estabelecimento/app/Http/Controllers/EstabelecimentoController.php`, método `dados()`:

```php
    public function dados()
    {
        $this->authorize('estabelecimento.ver');

        return Inertia::render('Estabelecimento/DadosDaEscola', [
            'estabelecimento' => $this->service->obterAtual(),
            'etapasEnsino' => $this->service->etapasEnsinoConfiguradas(),
        ]);
    }
```

- [ ] **Step 5: Correr o teste e confirmar que passa**

```bash
php artisan test --filter=test_pagina_dados_da_escola_expoe_as_etapas_de_ensino_configuradas
```

Esperado: PASS.

- [ ] **Step 6: Correr a suite completa de Estabelecimento**

```bash
php artisan test --filter=Estabelecimento
```

Esperado: PASS em todos.

- [ ] **Step 7: Commit**

```bash
git add Modules/Estabelecimento/app/Services/GestaoEstabelecimentoService.php \
        Modules/Estabelecimento/app/Http/Controllers/EstabelecimentoController.php \
        Modules/Estabelecimento/tests/Feature/GestaoEstabelecimentoTest.php
git commit -m "feat(estabelecimento): expõe etapasEnsino configuradas para o frontend"
```

---

## Task 4: `NivelAcademico` só aceita etapas configuradas no Estabelecimento

**Files:**
- Modify: `Modules/Turma/app/Http/Requests/CriarNivelAcademicoRequest.php`
- Modify: `Modules/Turma/app/Http/Requests/AtualizarNivelAcademicoRequest.php`
- Modify: `Modules/Turma/tests/Feature/TurmaHttpTest.php`

**Interfaces:**
- Consumes: `Estabelecimento::etapasEnsino()` (Task 1).
- Produces: nenhuma interface nova — é uma restrição de validação. A partir desta task, `criarEstabelecimento()` (helper de teste) passa a também configurar `PRIMARIO` e `SECUNDARIO` — todas as tasks/testes seguintes que usam este helper herdam isso automaticamente.

**Nota importante:** esta task altera o helper `criarEstabelecimento()` já usado por **todos** os testes de `TurmaHttpTest.php`. Sem essa alteração, a restrição nova bloquearia toda a suite (nenhuma etapa estaria configurada para o estabelecimento de teste). A escolha de pré-configurar exactamente `PRIMARIO` e `SECUNDARIO` no helper cobre as duas etapas já usadas pelos testes existentes (`criarNivelAcademico()` usa `SECUNDARIO` por omissão; os testes "não exige curso" usam `PRIMARIO`) — nenhum outro teste precisa de ser tocado.

- [ ] **Step 1: Escrever o teste novo e ajustar o helper**

Em `Modules/Turma/tests/Feature/TurmaHttpTest.php`, adicionar o import:

```php
use Modules\Estabelecimento\Models\EstabelecimentoEtapaEnsino;
```

Substituir o helper `criarEstabelecimento()`:

```php
    private function criarEstabelecimento(): Estabelecimento
    {
        $estabelecimento = Estabelecimento::create(['nome' => 'Escola Teste', 'tipo' => 1, 'is_active' => true]);

        foreach ([EtapaEnsinoEnum::PRIMARIO, EtapaEnsinoEnum::SECUNDARIO] as $etapa) {
            EstabelecimentoEtapaEnsino::create(['estabelecimento_id' => $estabelecimento->id, 'etapa_ensino' => $etapa]);
        }

        return $estabelecimento;
    }
```

Adicionar o teste novo, a seguir a `test_criar_nivel_academico_com_etapa_ensino_invalida_falha_com_erro_de_validacao`:

```php
    public function test_criar_nivel_academico_com_etapa_nao_configurada_falha_com_erro_de_validacao(): void
    {
        $this->actingAsStaff();
        $this->criarEstabelecimento();

        $this->post(route('niveis-academicos.store'), [
            'codigo' => 'SUP1',
            'nome' => '1º Ano Universitário',
            'ordem' => 1,
            'etapa_ensino' => EtapaEnsinoEnum::SUPERIOR->value,
        ])->assertSessionHasErrors('etapa_ensino');
    }
```

(`SUPERIOR` é um valor válido no enum mas não está entre as etapas configuradas pelo helper — exactamente o caso que esta task deve rejeitar.)

- [ ] **Step 2: Correr a suite e confirmar o padrão de falhas**

```bash
php artisan test --filter=TurmaHttpTest
```

Esperado: o teste novo falha (a Request actual ainda aceita `SUPERIOR` porque a regra continua `in:1,2,3,4,5`). Os restantes testes já devem passar (o helper alterado só adiciona configuração, não muda nada que os outros testes verificam).

- [ ] **Step 3: Restringir a validação de `etapa_ensino` às etapas configuradas**

`Modules/Turma/app/Http/Requests/CriarNivelAcademicoRequest.php` — trocar a regra e adicionar imports:

```php
<?php

namespace Modules\Turma\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;

class CriarNivelAcademicoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('turmas.criar') ?? false;
    }

    public function rules(): array
    {
        return [
            'codigo' => [
                'required',
                'string',
                'max:50',
                Rule::unique('niveis_academicos', 'codigo')
                    ->where(fn ($query) => $query->where('estabelecimento_id', Estabelecimento::current()?->id)),
            ],
            'nome' => 'required|string|max:255',
            'ordem' => 'required|integer|min:1',
            'etapa_ensino' => [
                'required',
                'integer',
                Rule::in(
                    Estabelecimento::current()?->etapasEnsino()->pluck('etapa_ensino')
                        ->map(fn (EtapaEnsinoEnum $e) => $e->value)->all() ?? []
                ),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'codigo.required' => 'O código do nível académico é obrigatório.',
            'codigo.unique' => 'Já existe um nível académico com este código neste estabelecimento.',
            'codigo.max' => 'O código do nível académico não pode ultrapassar 50 caracteres.',
            'nome.required' => 'O nome do nível académico é obrigatório.',
            'nome.max' => 'O nome do nível académico não pode ultrapassar 255 caracteres.',
            'ordem.required' => 'A ordem do nível académico é obrigatória.',
            'ordem.min' => 'A ordem deve ser igual ou superior a 1.',
            'etapa_ensino.required' => 'A etapa de ensino é obrigatória.',
            'etapa_ensino.in' => 'A etapa de ensino indicada não está configurada para este estabelecimento.',
        ];
    }
}
```

`Modules/Turma/app/Http/Requests/AtualizarNivelAcademicoRequest.php` — mesma alteração:

```php
<?php

namespace Modules\Turma\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;

class AtualizarNivelAcademicoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('turmas.editar') ?? false;
    }

    public function rules(): array
    {
        return [
            'codigo' => 'required|string|max:50',
            'nome' => 'required|string|max:255',
            'ordem' => 'required|integer|min:1',
            'etapa_ensino' => [
                'required',
                'integer',
                Rule::in(
                    Estabelecimento::current()?->etapasEnsino()->pluck('etapa_ensino')
                        ->map(fn (EtapaEnsinoEnum $e) => $e->value)->all() ?? []
                ),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'codigo.required' => 'O código do nível académico é obrigatório.',
            'codigo.max' => 'O código do nível académico não pode ultrapassar 50 caracteres.',
            'nome.required' => 'O nome do nível académico é obrigatório.',
            'nome.max' => 'O nome do nível académico não pode ultrapassar 255 caracteres.',
            'ordem.required' => 'A ordem do nível académico é obrigatória.',
            'ordem.min' => 'A ordem deve ser igual ou superior a 1.',
            'etapa_ensino.required' => 'A etapa de ensino é obrigatória.',
            'etapa_ensino.in' => 'A etapa de ensino indicada não está configurada para este estabelecimento.',
        ];
    }
}
```

- [ ] **Step 4: Correr toda a suite de Turma e confirmar que passa**

```bash
php artisan test --filter=TurmaHttpTest
```

Esperado: PASS em todos, incluindo o teste novo.

- [ ] **Step 5: Commit**

```bash
git add Modules/Turma/app/Http/Requests/CriarNivelAcademicoRequest.php \
        Modules/Turma/app/Http/Requests/AtualizarNivelAcademicoRequest.php \
        Modules/Turma/tests/Feature/TurmaHttpTest.php
git commit -m "feat(turma): NivelAcademico só aceita etapas configuradas no Estabelecimento"
```

---

## Task 5: Frontend de `NivelAcademico` — opções vêm do backend, não de lista fixa

**Files:**
- Modify: `Modules/Turma/app/Http/Controllers/NivelAcademicoController.php`
- Modify: `Modules/Turma/resources/js/Pages/NiveisAcademicos/Index.vue`
- Modify: `Modules/Turma/resources/js/Components/NivelAcademico/NivelAcademicoFormModal.vue`

**Interfaces:**
- Consumes: `Estabelecimento::etapasEnsino()` (Task 1); `EtapaEnsinoEnum` (`Modules/Turma/resources/js/Models/EtapaEnsino.js`, já existente, para os `label`s).
- Produces: prop `etapasEnsino: {value, label}[]` passada de `NiveisAcademicos/Index.vue` a `NivelAcademicoFormModal.vue`.

- [ ] **Step 1: Passar as opções do Controller para a página**

Editar `Modules/Turma/app/Http/Controllers/NivelAcademicoController.php`, método `index()`:

```php
<?php

namespace Modules\Turma\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Modules\Core\Enums\Estado;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Turma\Http\Requests\AlterarEstadoNivelAcademicoRequest;
use Modules\Turma\Http\Requests\AtualizarNivelAcademicoRequest;
use Modules\Turma\Http\Requests\CriarNivelAcademicoRequest;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Services\GestaoNivelAcademicoService;
use Modules\Turma\Services\NivelAcademicoConsultaService;

class NivelAcademicoController extends Controller
{
    public function __construct(
        private GestaoNivelAcademicoService $service,
        private NivelAcademicoConsultaService $consulta,
    ) {}

    public function index()
    {
        $this->authorize('turmas.ver');

        return Inertia::render('Turma/NiveisAcademicos/Index', [
            'niveisAcademicos' => $this->consulta->listar(),
            'etapasEnsino' => Estabelecimento::current()?->etapasEnsino()
                ->get(['etapa_ensino', 'etapa_ensino_descricao']) ?? [],
        ]);
    }

    public function store(CriarNivelAcademicoRequest $request)
    {
        $this->authorize('turmas.criar');

        $this->service->criar($request);

        return redirect()->back()->with('success', 'Nível académico criado com sucesso.');
    }

    public function update(AtualizarNivelAcademicoRequest $request, NivelAcademico $nivelAcademico)
    {
        $this->authorize('turmas.editar');

        $this->service->atualizar($nivelAcademico, $request);

        return redirect()->back()->with('success', 'Nível académico atualizado com sucesso.');
    }

    public function alterarEstado(AlterarEstadoNivelAcademicoRequest $request, NivelAcademico $nivelAcademico)
    {
        $this->authorize('turmas.editar');

        $this->service->alterarEstado($nivelAcademico, Estado::from((int) $request->validated('estado')));

        return redirect()->back()->with('success', 'Estado do nível académico atualizado com sucesso.');
    }

    public function destroy(NivelAcademico $nivelAcademico)
    {
        $this->authorize('turmas.eliminar');

        $this->service->eliminar($nivelAcademico);

        return redirect()->back()->with('success', 'Nível académico eliminado com sucesso.');
    }
}
```

(`etapasEnsino` devolve a coleção de `EstabelecimentoEtapaEnsino` — o Inertia serializa `etapa_ensino` (via cast enum, sai como int) e `etapa_ensino_descricao` automaticamente; o frontend usa isto directamente, sem precisar de outro formato.)

- [ ] **Step 2: `NiveisAcademicos/Index.vue` recebe e reenvia a prop**

Editar `Modules/Turma/resources/js/Pages/NiveisAcademicos/Index.vue`:

```js
defineProps({
    niveisAcademicos: { type: Array, required: true },
    etapasEnsino: { type: Array, required: true },
});
```

E no template, passar a prop ao modal:

```html
        <NivelAcademicoFormModal
            :show="modalAberto"
            :nivel-academico="nivelEmEdicao"
            :etapas-ensino="etapasEnsino"
            :processing="processing"
            :errors="errors"
            @submit="guardar"
            @cancelar="fecharModal"
        />
```

- [ ] **Step 3: `NivelAcademicoFormModal.vue` usa a prop em vez do import estático**

Editar `Modules/Turma/resources/js/Components/NivelAcademico/NivelAcademicoFormModal.vue`:

Trocar o import:

```js
import { reactive, watch, computed } from 'vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import { ESTADO } from '../../Models/Estado';
```

(remove o `import { ETAPA_ENSINO_OPCOES } from '../../Models/EtapaEnsino';`)

Adicionar a prop:

```js
const props = defineProps({
    show: { type: Boolean, default: false },
    nivelAcademico: { type: Object, default: null },
    etapasEnsino: { type: Array, required: true },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
```

Adicionar o `computed` que traduz `etapasEnsino` (formato `{etapa_ensino, etapa_ensino_descricao}`) para o formato que `SelectSolid` espera (`{value, label}`):

```js
const opcoesEtapaEnsino = computed(() => props.etapasEnsino.map((e) => ({ value: e.etapa_ensino, label: e.etapa_ensino_descricao })));
```

No template, trocar `:options="ETAPA_ENSINO_OPCOES"` por `:options="opcoesEtapaEnsino"`:

```html
                    <div class="fv-row mb-7">
                        <label class="required fw-semibold fs-6 mb-2">Etapa de Ensino</label>
                        <SelectSolid v-model="form.etapa_ensino" :options="opcoesEtapaEnsino" placeholder="Selecione a etapa de ensino" />
                        <div class="text-danger fs-7 mt-1" v-if="errors.etapa_ensino">{{ errors.etapa_ensino }}</div>
                    </div>
```

- [ ] **Step 4: Build e verificação estática**

```bash
npm run build
```

Confirmar que compila sem erros. `TurmaFormModal.vue` continua a importar `etapaExigeCurso`/`ETAPAS_QUE_EXIGEM_CURSO` de `Models/EtapaEnsino.js` normalmente — este ficheiro não é tocado nesta task (só o `NivelAcademicoFormModal.vue` deixa de usar `ETAPA_ENSINO_OPCOES` dele).

- [ ] **Step 5: Commit**

```bash
git add Modules/Turma/app/Http/Controllers/NivelAcademicoController.php \
        Modules/Turma/resources/js/Pages/NiveisAcademicos/Index.vue \
        Modules/Turma/resources/js/Components/NivelAcademico/NivelAcademicoFormModal.vue
git commit -m "feat(turma): opções de Etapa de Ensino no formulário vêm da configuração do Estabelecimento"
```

---

## Task 6: Remover o método derivado (Task 7 da feature anterior)

**Files:**
- Modify: `Modules/Turma/app/Services/NivelAcademicoConsultaService.php`
- Delete: `Modules/Turma/tests/Feature/NivelAcademicoConsultaServiceTest.php`

**Interfaces:** nenhuma — remoção pura. Confirmado sem consumidor em produção (Task 5 usa `Estabelecimento::current()?->etapasEnsino()` directamente no Controller, não este método).

- [ ] **Step 1: Apagar o ficheiro de teste**

```bash
rm Modules/Turma/tests/Feature/NivelAcademicoConsultaServiceTest.php
```

- [ ] **Step 2: Remover o método e o import agora não usado**

`Modules/Turma/app/Services/NivelAcademicoConsultaService.php` volta a:

```php
<?php

namespace Modules\Turma\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Turma\Models\NivelAcademico;

class NivelAcademicoConsultaService
{
    public function listar(): Collection
    {
        return NivelAcademico::where('estabelecimento_id', Estabelecimento::current()?->id)
            ->orderBy('ordem')
            ->get();
    }
}
```

- [ ] **Step 3: Correr a suite de Turma e confirmar que passa**

```bash
php artisan test --filter=Turma
```

Esperado: PASS — nenhum teste restante referencia o método removido.

- [ ] **Step 4: Commit**

```bash
git add Modules/Turma/app/Services/NivelAcademicoConsultaService.php \
        Modules/Turma/tests/Feature/NivelAcademicoConsultaServiceTest.php
git commit -m "refactor(turma): remove NivelAcademicoConsultaService::etapasEnsino() (redundante)"
```

---

## Task 7: Frontend do Estabelecimento — configurar Etapas de Ensino

**Files:**
- Modify: `Modules/Estabelecimento/resources/js/Components/CampoFicha.vue`
- Modify: `Modules/Estabelecimento/resources/js/Pages/DadosDaEscola.vue`

**Interfaces:**
- Consumes: prop `etapasEnsino: number[]` (Task 3).

- [ ] **Step 1: Estender `CampoFicha.vue` com `type="checkboxes"`**

Editar `Modules/Estabelecimento/resources/js/Components/CampoFicha.vue`. Alterar a prop `modelValue`:

```js
    modelValue: { type: [String, Number, Array], default: '' },
```

Alterar o comentário do `type` (linha 8): `// text | email | number | textarea | select | checkboxes`.

Alterar `valorExibido`:

```js
const valorExibido = computed(() => {
    if (props.type === 'select') {
        return props.options.find((opcao) => opcao.value === props.modelValue)?.label ?? '';
    }
    if (props.type === 'checkboxes') {
        return props.options
            .filter((opcao) => Array.isArray(props.modelValue) && props.modelValue.includes(opcao.value))
            .map((opcao) => opcao.label)
            .join(', ');
    }
    return props.modelValue;
});
```

No template, adicionar o bloco de checkboxes como alternativa ao `textarea`/`select`/`input` (mesmo padrão Metronic já usado em `Modules/PlanoCurricular/resources/js/Components/DefinirPeriodosDisciplinaModal.vue`):

```html
        <template v-if="editing">
            <textarea
                v-if="type === 'textarea'"
                class="form-control form-control-solid ficha-input"
                rows="3"
                :placeholder="placeholder"
                :value="modelValue"
                @input="$emit('update:modelValue', $event.target.value)"
            ></textarea>

            <div v-else-if="type === 'checkboxes'" class="d-flex flex-wrap gap-4">
                <div v-for="opcao in options" :key="opcao.value" class="form-check form-check-custom form-check-solid">
                    <input
                        :id="`campo-ficha-${label}-${opcao.value}`"
                        type="checkbox"
                        class="form-check-input"
                        :checked="Array.isArray(modelValue) && modelValue.includes(opcao.value)"
                        @change="$emit('update:modelValue', $event.target.checked
                            ? [...(Array.isArray(modelValue) ? modelValue : []), opcao.value]
                            : (Array.isArray(modelValue) ? modelValue : []).filter((v) => v !== opcao.value))"
                    />
                    <label class="form-check-label fw-semibold fs-6" :for="`campo-ficha-${label}-${opcao.value}`">{{ opcao.label }}</label>
                </div>
            </div>

            <div v-else class="ficha-input-wrap" :class="{ 'ficha-input-wrap--icone': temCampoComIcone }">
                ... (inalterado)
            </div>

            <span class="text-danger fs-8 mt-1" v-if="error">{{ error }}</span>
        </template>
```

(O `id` usa `label` porque `CampoFicha` não recebe nenhum identificador único hoje — como `label` já é obrigatório e cada campo desta página tem um `label` distinto, serve para associar `<label>`/`<input>` sem colidir entre instâncias.)

- [ ] **Step 2: `DadosDaEscola.vue` ganha o campo Etapas de Ensino**

Editar `Modules/Estabelecimento/resources/js/Pages/DadosDaEscola.vue`. Adicionar `watch` ao import:

```js
import { reactive, ref, watch } from 'vue';
```

Adicionar a prop:

```js
const props = defineProps({
    estabelecimento: {
        type: Object,
        default: null,
    },
    etapasEnsino: {
        type: Array,
        default: () => [],
    },
});
```

Adicionar as constantes, a seguir a `tiposEnsino`:

```js
const TIPO_ENSINO_UNIVERSITARIO = 3;
const ETAPA_SUPERIOR = 5;

const etapasNaoSuperior = [
    { value: 1, label: 'Creche' },
    { value: 2, label: 'Pré-Escolar' },
    { value: 3, label: 'Ensino Primário' },
    { value: 4, label: 'Ensino Secundário' },
];
```

`snapshot()` ganha o campo:

```js
        etapas_ensino: props.etapasEnsino ?? [],
```

(colocar a seguir a `tipo_ensino: props.estabelecimento?.tipo_ensino ?? 1,`)

Depois de `const form = reactive(snapshot());`, adicionar o `watch` que mantém `form.etapas_ensino` coerente:

```js
watch(() => form.tipo_ensino, (novo) => {
    if (novo === TIPO_ENSINO_UNIVERSITARIO) form.etapas_ensino = [ETAPA_SUPERIOR];
});
```

No template, adicionar o novo campo logo a seguir ao bloco "Tipo de Ensino" (dentro do mesmo `row` do card "Identificação"):

```html
                        <div class="col-md-8">
                            <template v-if="form.tipo_ensino === TIPO_ENSINO_UNIVERSITARIO">
                                <span class="ficha-rotulo">Etapas de Ensino</span>
                                <div class="ficha-valor-wrap">
                                    <span class="ficha-valor">Ensino Superior <span class="text-muted fs-8">(fixo para Ensino Universitário)</span></span>
                                </div>
                            </template>
                            <CampoFicha
                                v-else
                                v-model="form.etapas_ensino" label="Etapas de Ensino" type="checkboxes" :options="etapasNaoSuperior"
                                required :editing="editando" :error="errors.etapas_ensino?.[0]" icon="ki-teacher" :icon-paths="2"
                            />
                        </div>
```

- [ ] **Step 3: Build e verificação manual**

```bash
npm run build
```

Confirmar que compila sem erros. Com a app a correr, login como ADMIN_ESCOLA, ir a "Dados da Escola" e verificar:
1. Com Tipo de Ensino = Geral ou Técnico: aparecem 4 checkboxes (Creche/Pré-Escolar/Primário/Secundário), todas desmarcadas se o estabelecimento ainda não tiver nada configurado.
2. Trocar Tipo de Ensino para Universitário: as checkboxes desaparecem, mostra o texto fixo "Ensino Superior (fixo para Ensino Universitário)".
3. Guardar com Geral/Técnico sem marcar nenhuma etapa: erro de validação.
4. Configurar Primário+Secundário, guardar, recarregar a página: as duas checkboxes continuam marcadas.
5. Ir a Turma > Níveis Académicos > Novo Nível Académico: o select "Etapa de Ensino" só mostra as etapas configuradas no passo 4 (não as 5 todas).

- [ ] **Step 4: Commit**

```bash
git add Modules/Estabelecimento/resources/js/Components/CampoFicha.vue \
        Modules/Estabelecimento/resources/js/Pages/DadosDaEscola.vue
git commit -m "feat(estabelecimento): frontend de configuração de Etapas de Ensino"
```

---

## Task 8: Verificação final

**Files:** nenhum (apenas execução).

- [ ] **Step 1: Correr toda a suite de testes do projecto**

```bash
php artisan test
```

Esperado: PASS em toda a suite (o mesmo 1 teste incompleto pré-existente sobre isolamento de tenants continua incompleto, sem relação com esta feature).

- [ ] **Step 2: Build final do frontend**

```bash
npm run build
```

Esperado: compila sem erros nem avisos novos.

- [ ] **Step 3: Verificação manual ponta-a-ponta**

Com a app a correr, login como ADMIN_ESCOLA:
1. Confirmar que o Estabelecimento actual (já com Tipo de Ensino Geral e Níveis em Secundário, da feature anterior) foi migrado com `estabelecimento_etapas_ensino` já a conter `Secundário` (efeito do backfill da Task 1) — visível em "Dados da Escola", checkbox "Secundário" já vem marcada.
2. Marcar também "Primário" e guardar.
3. Criar um Nível Académico de Primário (ex.: "1ª Classe") — confirmar que o select só oferece Primário/Secundário, não as 5 etapas.
4. Tentar desmarcar "Secundário" em "Dados da Escola" (que já tem a 10ª/11ª Classe) — confirmar que a gravação falha com erro claro.
5. Desmarcar "Primário" (sem níveis ainda associados, se nenhum tiver sido criado no passo 3 além do teste) — confirmar que funciona.

Não há passo de commit nesta task — é só verificação. Se algum passo falhar, voltar à task correspondente, corrigir, e repetir esta task do início.

## Auto-review

- **Cobertura da spec:** modelo de dados + relação → Task 1. Regra de negócio (forçar Superior, bloquear remoção) → Task 2. Exposição ao frontend do Estabelecimento → Task 3. Restrição de `NivelAcademico` → Task 4. Frontend de `NivelAcademico` consumindo a configuração → Task 5. Remoção do código redundante → Task 6. Frontend do Estabelecimento (checkboxes, texto fixo para Universitário) → Task 7. Backfill de dados existentes → dentro da migração da Task 1.
- **Placeholders:** nenhum "TBD" — todo o código e todas as mensagens têm valor concreto.
- **Consistência de tipos:** `EstabelecimentoDTO::$etapas_ensino` é sempre `array<EtapaEnsinoEnum>`, nunca `array<int>`, em todas as camadas onde é lido (Action). `GestaoEstabelecimentoService::etapasEnsinoConfiguradas()` e `NivelAcademicoController::index()` devolvem formatos diferentes de propósito — o primeiro `int[]` simples (é só isso que `DadosDaEscola.vue` precisa para pré-marcar checkboxes), o segundo `{etapa_ensino, etapa_ensino_descricao}[]` (é isso que `NivelAcademicoFormModal.vue` precisa para montar `{value, label}`) — nenhuma task later assume o formato errado.
- **Escopo:** 8 tasks, todas dentro da spec aprovada. A excepção de fronteira de módulo (Estabelecimento→Turma) está isolada a um único método privado (`sincronizarEtapasEnsino`) na Task 2, exactamente como a spec descreveu. Nenhuma task introduz CRUD próprio para `estabelecimento_etapas_ensino`, nem reintroduz `exige_curso`, nem mexe em `TipoEnsinoEnum`.
