# Tenancy — Plano 2: Estabelecimento

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ligar o Estabelecimento ao Tenant: exactamente um por tenant garantido pela BD, `Estabelecimento::current()` lido do contexto, e configuração inicial (`configurado_em`) com encaminhamento do administrador para o ecrã de dados da escola.

**Architecture:** `estabelecimentos` e `estabelecimento_etapas_ensino` passam a ter `tenant_id` (migrations originais editadas, BD recriada) e os dois models usam `PertenceAoTenant`. O estabelecimento nasce mínimo (só o nome) e `AtualizarDadosEstabelecimentoAction` marca `configurado_em` na primeira gravação válida. Um middleware no grupo `web`, dentro do módulo Estabelecimento, encaminha quem pode editar enquanto `configurado_em` for nulo. A base de testes passa a dar a cada tenant o seu estabelecimento; os ~67 testes existentes são migrados para auxiliares **antes** de o esquema mudar, para a suite estar verde em cada tarefa.

**Tech Stack:** PHP 8.2, Laravel 12, nwidart/laravel-modules 13, PHPUnit 11, Inertia + Vue 3, SQLite em memória nos testes, PostgreSQL em desenvolvimento.

**Spec:** `docs/superpowers/specs/2026-09-30-fundacao-tenancy-design.md` — este plano implementa a etapa 4 de §20.2 (§8.1 a §8.5, §17.3 linha "Estabelecimento", §19.4 último ponto). Depende do Plano 1 (etapas 1 a 3), já concluído.

**Fora deste plano (não tocar):** caminho do logótipo `tenants/{id}/…` (etapa 8), provisionador do estabelecimento e `CriarTenantAction` (etapa 9), conversão de `users`, `roles` e afins (Plano 3), chave composta nas outras tabelas com `estabelecimento_id` (etapa 6: `cursos`, `disciplinas`, `salas`, `turnos`, `niveis_academicos`, `planos_curriculares`, `ano_lectivos`, `alunos`).

## Global Constraints

- **Nunca fazer commit.** O dono do repositório faz os commits. Cada tarefa termina com `git add` dos ficheiros tocados, nada mais.
- **Nunca escrever na BD de desenvolvimento por tinker ou SQL.** Só migrations, seeders e testes. `migrate:fresh --seed` só com confirmação do utilizador (Tarefa 5).
- Fail-closed: sem tenant resolvido, `Estabelecimento::current()` lança `TenantNaoResolvido`. Nunca devolve nulo nem "o primeiro da BD".
- `Estabelecimento::current()` nunca devolve nulo dentro de um tenant. Não existe criação tardia.
- `tenant_id` nunca entra em `$fillable` nem em DTOs.
- `is_active` mantém-se na coluna mas deixa de seleccionar o estabelecimento.
- `Modules/Estabelecimento` não importa `Modules\Tenant\…`. Só os testes (`tests/`, `Modules/*/tests/`) e `database/seeders/DatabaseSeeder.php` o podem fazer.
- Não usar `withoutGlobalScopes`, `DB::table(`, nem a fachada `Cache` em `Modules/*/app`, `routes` ou `database/seeders`.
- Em PHP, importar as classes com `use` no topo; nunca nomes totalmente qualificados no meio do código.
- Colunas-enum inteiras têm sempre uma coluna irmã `*_descricao`, sincronizada num hook `saving` do model.
- Antes de estilizar algo novo, procurar o equivalente já existente no projecto e usar as mesmas classes (Bootstrap/Metronic: `alert alert-warning d-flex align-items-center`).
- Identificadores, mensagens e comentários em português.
- Comando de testes: `php artisan test`. A suite completa tem de passar no fim de cada tarefa (baseline: 756 passed, 1 incomplete).
- Nomes exactos: `configurado_em` (timestamp nulo), `estabelecimentoDeTeste()`, `estabelecimentoDeOutroTenant()`, `ExigirConfiguracaoInicial`, rota `estabelecimento.dados` (`GET /estabelecimento`), permissão `estabelecimento.editar`.

## Review Focus

Comportamentos que o spec implica e que mais facilmente ficam sem teste. Cada um tem o teste na tarefa indicada.

1. **Estabelecimento mínimo** (só `nome`, `tipo` nulo) tem de abrir o ecrã "Dados da escola" sem erro e com o formulário já em edição → Tarefa 3.
2. **`current()` depois de `executarComo` noutro tenant** não pode devolver o estabelecimento memorizado do tenant anterior → Tarefa 2.
3. **Gravação inválida** (validação falha) não preenche `configurado_em`; uma segunda gravação válida não o altera → Tarefa 3.
4. **Redirecionamento só a quem pode editar**: utilizador sem `estabelecimento.editar`, visitante anónimo, host central sem tenant, rota inexistente, `logout` e as rotas `estabelecimento.*` não são encaminhados (nem entram em ciclo de redirecionamento) → Tarefa 4.
5. **Segundo estabelecimento no mesmo tenant** e **etapa de ensino a apontar para o estabelecimento de outro tenant** são rejeitados pela BD, não pela aplicação → Tarefa 2.

## Mapa de ficheiros

| Ficheiro | Responsabilidade |
|---|---|
| `tests/Concerns/ComTenantDeTeste.php` | Estabelecimento por omissão; `estabelecimentoDeTeste()`; `estabelecimentoDeOutroTenant()` |
| `Modules/Estabelecimento/database/migrations/2026_08_30_102250_create_estabelecimentos_table.php` | `tenant_id`, únicos, `configurado_em`, `tipo` nulo |
| `Modules/Estabelecimento/database/migrations/2026_09_11_120000_create_estabelecimento_etapas_ensino_table.php` | `tenant_id` e chave estrangeira composta |
| `Modules/Estabelecimento/app/Models/Estabelecimento.php` | `PertenceAoTenant`, `current()` pelo contexto, `estaConfigurado()` |
| `Modules/Estabelecimento/app/Models/EstabelecimentoEtapaEnsino.php` | `PertenceAoTenant` |
| `Modules/Estabelecimento/app/Actions/AtualizarDadosEstabelecimentoAction.php` | Sem ramo de criação; preenche `configurado_em` |
| `Modules/Estabelecimento/app/Services/GestaoEstabelecimentoService.php` | Retornos não nulos |
| `Modules/Estabelecimento/app/Http/Middleware/ExigirConfiguracaoInicial.php` | Encaminhamento |
| `Modules/Estabelecimento/resources/js/Pages/DadosDaEscola.vue` | Aviso e edição inicial enquanto não configurado |
| `Modules/Estabelecimento/database/seeders/EstabelecimentoDesenvolvimentoSeeder.php` | Estabelecimento mínimo do tenant de desenvolvimento |
| `database/seeders/DatabaseSeeder.php` | Corre os seeders dentro do contexto do tenant de desenvolvimento |
| `config/tenancy.php` | Retirar as duas tabelas de `tabelas_por_converter` |
| `tests/Feature/Arquitectura/TenancyEsquemaTest.php` | Arquitectura 8 (transitória) |

---

### Task 1: Base de testes com estabelecimento e migração dos testes existentes

O esquema **não muda** nesta tarefa. Os testes passam a usar auxiliares que funcionam com o esquema antigo e com o novo, para que a Tarefa 2 não parta nenhum.

**Files:**
- Modify: `tests/Concerns/ComTenantDeTeste.php`
- Modify: os 67 ficheiros de teste que chamam `Estabelecimento::create` (lista no Step 3)

**Interfaces:**
- Consumes: `ComTenantDeTeste::criarTenant(string $codigo, string $nome, string $dominio): Tenant`, `noTenant(Tenant $tenant, Closure $fn): mixed` (Plano 1).
- Produces:
  - `estabelecimentoDeTeste(array $atributos = []): Estabelecimento` — o estabelecimento do tenant por omissão (`$this->tenant`); se houver `$atributos`, aplica-os com `fill()` e grava.
  - `estabelecimentoDeOutroTenant(array $atributos = []): Estabelecimento` — cria um segundo tenant, com domínio `outra-N.localhost`, e devolve o estabelecimento dele. Cada chamada cria um tenant novo.

- [ ] **Step 1: Acrescentar os auxiliares ao trait**

Em `tests/Concerns/ComTenantDeTeste.php`, acrescentar os `use`:

```php
use Modules\Estabelecimento\Enums\TipoEstabelecimentoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
```

Acrescentar, junto à propriedade `$tenant`:

```php
    /** Contador para dar códigos e domínios únicos aos tenants criados por estabelecimentoDeOutroTenant(). */
    private int $outrosTenants = 0;
```

Em `prepararTenantDeTeste()`, logo a seguir a `app(TenantContext::class)->definir(...)`:

```php
        if (Schema::hasTable('estabelecimentos')) {
            Estabelecimento::create([
                'nome' => 'Escola de Teste',
                'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
                'is_active' => true,
            ]);
        }
```

Acrescentar os dois métodos:

```php
    /**
     * O estabelecimento do tenant por omissão. Os testes que antes criavam "o"
     * estabelecimento passam a pedir este, com os atributos que lhes interessam.
     */
    protected function estabelecimentoDeTeste(array $atributos = []): Estabelecimento
    {
        $estabelecimento = Estabelecimento::current();

        if ($atributos !== []) {
            $estabelecimento->fill($atributos)->save();
        }

        return $estabelecimento;
    }

    /**
     * Um estabelecimento que NÃO é o do tenant corrente. Para os testes que
     * precisam de provar que dados de outro estabelecimento não aparecem.
     */
    protected function estabelecimentoDeOutroTenant(array $atributos = []): Estabelecimento
    {
        $n = ++$this->outrosTenants;
        $outro = $this->criarTenant(sprintf('MOSI-%06d', 900000 + $n), "Outra Escola {$n}", "outra-{$n}.localhost");

        return $this->noTenant($outro, fn () => Estabelecimento::create(array_merge([
            'nome' => "Outra Escola {$n}",
            'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
            'is_active' => false,
        ], $atributos)));
    }
```

- [ ] **Step 2: Correr a suite para ver o que o estabelecimento por omissão já parte**

Run: `php artisan test 2>&1 | tail -60`
Expected: falham os testes que criam um segundo estabelecimento activo (o `current()` antigo devolve o primeiro) e os que assumem "não há estabelecimento". Anotar a lista; o Step 3 corrige-os.

- [ ] **Step 3: Migrar os testes existentes**

Regra única: **o estabelecimento que o teste trata como "o actual" (`is_active => true`, ou o único) é o do tenant por omissão → `$this->estabelecimentoDeTeste([...])`. O que o teste trata como "outro" (`is_active => false`, `Escola B`, `Outra Escola`) → `$this->estabelecimentoDeOutroTenant([...])`.** Os atributos passam a ser os mesmos de antes, sem `is_active`.

Lista de ficheiros (todos os que contêm `Estabelecimento::create`):
`Modules/{Aluno,AnoLectivo,Core,Curso,Disciplina,Estabelecimento,Infraestrutura,Matricula,PlanoCurricular,Turma}/tests/Feature/*.php`. Obter a lista actual com:

```bash
grep -rl "Estabelecimento::create" Modules/*/tests --include='*.php' | sort
```

1. Os casos de uma linha com `'is_active' => true` convertem-se por script:

```bash
grep -rl "Estabelecimento::create" Modules/*/tests --include='*.php' | xargs perl -pi -e "s/Estabelecimento::create\((\[[^\n]*?)(?:, )?'is_active' => true\]\)/\\\$this->estabelecimentoDeTeste(\1])/g"
```

   Confirmar que um caso de exemplo ficou assim:
   `Estabelecimento::create(['nome' => 'Escola A', 'tipo' => 1, 'tipo_ensino' => 1, 'is_active' => true])` → `$this->estabelecimentoDeTeste(['nome' => 'Escola A', 'tipo' => 1, 'tipo_ensino' => 1])`.

2. Os casos de várias linhas e os de `is_active => false` fazem-se à mão. Exemplos:

   `Modules/Curso/tests/Feature/CursoModelTest.php`:

```php
    private function criarEstabelecimento(): Estabelecimento
    {
        return $this->estabelecimentoDeTeste([
            'nome' => 'Escola Teste',
            'tipo' => TipoEstabelecimentoEnum::PUBLICO->value,
        ]);
    }
```
```php
        $estabelecimentoA = $this->criarEstabelecimento();
        $estabelecimentoB = $this->estabelecimentoDeOutroTenant(['nome' => 'Escola B']);
```

   `Modules/PlanoCurricular/tests/Feature/PlanoCurricularModelTest.php` (o antigo "desactiva A, cria B activo" não tem equivalente: o teste só prova unicidade por estabelecimento, por isso o actual fica A e B é de outro tenant):

```php
        $estabelecimentoA = $this->estabelecimento();
        $estabelecimentoB = $this->estabelecimentoDeOutroTenant(['nome' => 'Escola B', 'tipo' => 1, 'tipo_ensino' => 1]);
```
   (apagar a linha `Estabelecimento::where('id', …)->update(['is_active' => false]);`)

   `Modules/Core/tests/Feature/PesquisaTextoTest.php` cria vários estabelecimentos com nomes arbitrários, o que o novo invariante (1 por tenant) não permite. Passa a usar o model `Tenant` como tabela de exemplo (`whereContem` é uma macro do `Builder`, serve para qualquer model) e a excluir o tenant por omissão:

```php
use Modules\Tenant\Models\Tenant;

    private int $n = 0;

    private function criar(string $nome): void
    {
        Tenant::create(['codigo' => sprintf('MOSI-%06d', 800000 + ++$this->n), 'nome' => $nome]);
    }

    private function buscar(string $termo): array
    {
        return Tenant::query()
            ->whereKeyNot($this->tenant->id)
            ->whereContem('nome', $termo)
            ->orderBy('nome')
            ->pluck('nome')
            ->all();
    }
```
   (remover os `use` de `Estabelecimento` e `TipoEstabelecimentoEnum` desse ficheiro.)

3. Testes que dependem de **não** haver estabelecimento (`current()` nulo) deixam de fazer sentido: o invariante novo é "existe sempre um". Reescrever cada um para o caso real ou apagá-lo, e dizer qual foi no ledger. Em `GestaoEstabelecimentoTest` ficam para a Tarefa 3 os testes `test_cria_o_estabelecimento_ao_atualizar_dados_pela_primeira_vez` e `test_nao_atualiza_logotipo_sem_estabelecimento_cadastrado`; aqui só garantir que passam com o estabelecimento por omissão existente (o primeiro passa tal como está; o segundo pode falhar e fica marcado para a Tarefa 3).

- [ ] **Step 4: Correr a suite**

Run: `php artisan test`
Expected: tudo a passar, à excepção, no máximo, de `test_nao_atualiza_logotipo_sem_estabelecimento_cadastrado` (resolvido na Tarefa 3). Se aparecer outra falha, é um teste que ainda assume dois estabelecimentos no mesmo tenant: aplicar a regra do Step 3.

- [ ] **Step 5: Stage**

```bash
git add tests/Concerns/ComTenantDeTeste.php Modules/*/tests
```

---

### Task 2: Esquema, models e `current()` pelo contexto

**Files:**
- Modify: `Modules/Estabelecimento/database/migrations/2026_08_30_102250_create_estabelecimentos_table.php`
- Modify: `Modules/Estabelecimento/database/migrations/2026_09_11_120000_create_estabelecimento_etapas_ensino_table.php`
- Modify: `Modules/Estabelecimento/app/Models/Estabelecimento.php`
- Modify: `Modules/Estabelecimento/app/Models/EstabelecimentoEtapaEnsino.php`
- Modify: `config/tenancy.php`
- Modify: `tests/Concerns/ComTenantDeTeste.php`
- Modify: `tests/Feature/Arquitectura/TenancyEsquemaTest.php`
- Create: `Modules/Estabelecimento/tests/Feature/EstabelecimentoTenancyTest.php`

**Interfaces:**
- Consumes: `Modules\Core\Tenancy\PertenceAoTenant`, `TenantContext::lembrar(string $chave, Closure $fn): mixed`, `TenantNaoResolvido`, `AlteracaoDeTenantProibida` (Plano 1); helpers da Tarefa 1.
- Produces:
  - `Estabelecimento::current(): Estabelecimento` — não nulo; lança `TenantNaoResolvido` sem contexto.
  - `Estabelecimento::estaConfigurado(): bool` — `configurado_em !== null`.
  - Colunas `estabelecimentos.tenant_id` (único), `estabelecimentos.configurado_em` (nulo), índice único `(tenant_id, id)`; `estabelecimento_etapas_ensino.tenant_id` com chave composta para `estabelecimentos (tenant_id, id)`.
  - `ComTenantDeTeste::criarTenant()` passa a criar também o estabelecimento mínimo (nome = nome do tenant).

- [ ] **Step 1: Escrever os testes**

`Modules/Estabelecimento/tests/Feature/EstabelecimentoTenancyTest.php`:

```php
<?php

namespace Modules\Estabelecimento\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Tenancy\Exceptions\AlteracaoDeTenantProibida;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;
use Modules\Core\Tenancy\TenantContext;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Estabelecimento\Models\EstabelecimentoEtapaEnsino;
use Tests\TestCase;

class EstabelecimentoTenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_devolve_o_estabelecimento_do_tenant_corrente(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        $this->assertSame('Escola de Teste', Estabelecimento::current()->nome);
        // A memória do tenant anterior não pode vazar para o seguinte.
        $this->assertSame('Escola B', $this->noTenant($outro, fn () => Estabelecimento::current()->nome));
        $this->assertSame('Escola de Teste', Estabelecimento::current()->nome);
    }

    public function test_current_sem_contexto_lanca_tenant_nao_resolvido(): void
    {
        app(TenantContext::class)->limpar();

        $this->expectException(TenantNaoResolvido::class);

        Estabelecimento::current();
    }

    public function test_current_consulta_a_bd_uma_so_vez_por_pedido(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        Estabelecimento::current();
        Estabelecimento::current();
        Estabelecimento::current();

        $consultas = collect(DB::getQueryLog())->filter(fn (array $q) => str_contains($q['query'], 'estabelecimentos'));

        $this->assertCount(1, $consultas);
    }

    public function test_current_ignora_is_active(): void
    {
        $estabelecimento = Estabelecimento::current();
        $estabelecimento->update(['is_active' => false]);

        app(TenantContext::class)->definir($this->tenant->paraTenantAtual()); // limpa a memória

        $this->assertSame($estabelecimento->id, Estabelecimento::current()->id);
    }

    public function test_segundo_estabelecimento_no_mesmo_tenant_e_rejeitado_pela_bd(): void
    {
        $this->expectException(QueryException::class);

        Estabelecimento::create(['nome' => 'Duplicado']);
    }

    public function test_etapa_nao_pode_apontar_para_o_estabelecimento_de_outro_tenant(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $idDoOutro = $this->noTenant($outro, fn () => Estabelecimento::current()->id);

        $this->expectException(QueryException::class);

        EstabelecimentoEtapaEnsino::create([
            'estabelecimento_id' => $idDoOutro,
            'etapa_ensino' => EtapaEnsinoEnum::PRIMARIO->value,
        ]);
    }

    public function test_etapas_so_aparecem_no_tenant_dono(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        Estabelecimento::current()->etapasEnsino()->create(['etapa_ensino' => EtapaEnsinoEnum::PRIMARIO->value]);

        $this->assertSame(1, EstabelecimentoEtapaEnsino::count());
        $this->assertSame(0, $this->noTenant($outro, fn () => EstabelecimentoEtapaEnsino::count()));
    }

    public function test_nao_se_muda_o_tenant_de_um_estabelecimento(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        $this->expectException(AlteracaoDeTenantProibida::class);

        Estabelecimento::current()->forceFill(['tenant_id' => $outro->id])->save();
    }

    public function test_esta_configurado_segue_configurado_em(): void
    {
        $estabelecimento = Estabelecimento::current();
        $estabelecimento->forceFill(['configurado_em' => null]);

        $this->assertFalse($estabelecimento->estaConfigurado());

        $estabelecimento->forceFill(['configurado_em' => now()]);

        $this->assertTrue($estabelecimento->estaConfigurado());
    }
}
```

Acrescentar ao fim de `tests/Feature/Arquitectura/TenancyEsquemaTest.php` (antes do método privado `classesDeModels`) o teste de arquitectura 8, transitório até a etapa 6 converter as restantes tabelas:

```php
    public function test_toda_a_tabela_com_estabelecimento_id_e_tenant_id_tem_a_chave_composta(): void
    {
        $porConverter = config('tenancy.tabelas_por_converter');
        $erros = [];
        $comEstabelecimento = 0;

        foreach ($this->tabelas() as $tabela) {
            if (! Schema::hasColumn($tabela, 'estabelecimento_id')) {
                continue;
            }

            $comEstabelecimento++;

            if (! Schema::hasColumn($tabela, 'tenant_id')) {
                if (! in_array($tabela, $porConverter, true)) {
                    $erros[] = "{$tabela}: tem estabelecimento_id sem tenant_id e não consta em tabelas_por_converter.";
                }

                continue;
            }

            $temChaveComposta = collect(Schema::getForeignKeys($tabela))->contains(function (array $fk) {
                if ($fk['foreign_table'] !== 'estabelecimentos') {
                    return false;
                }

                $pares = array_combine($fk['columns'], $fk['foreign_columns']);
                ksort($pares);

                return $pares === ['estabelecimento_id' => 'id', 'tenant_id' => 'tenant_id'];
            });

            if (! $temChaveComposta) {
                $erros[] = "{$tabela}: falta a chave estrangeira composta (tenant_id, estabelecimento_id) → estabelecimentos (tenant_id, id).";
            }
        }

        $this->assertGreaterThan(5, $comEstabelecimento, 'Não foram encontradas as tabelas com estabelecimento_id.');
        $this->assertSame([], $erros, implode("\n", $erros));
    }
```

- [ ] **Step 2: Correr os testes para ver que falham**

Run: `php artisan test --filter='EstabelecimentoTenancyTest|TenancyEsquemaTest'`
Expected: FAIL (coluna `tenant_id`/`configurado_em` inexistente; `current()` ainda devolve `?self`).

- [ ] **Step 3: Editar a migration de `estabelecimentos`**

Em `2026_08_30_102250_create_estabelecimentos_table.php`, substituir o corpo de `Schema::create` por:

```php
        Schema::create('estabelecimentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('nome');
            $table->string('nome_abreviado')->nullable();
            // Nulo no estabelecimento mínimo criado no provisioning; a obrigatoriedade
            // está na validação do formulário de configuração.
            $table->unsignedTinyInteger('tipo')->nullable(); // 1: público | 2: privado | 3: cooperativo
            $table->string('tipo_descricao')->nullable();
            $table->string('nif')->nullable();
            $table->string('codigo_mined')->nullable();
            $table->string('numero_alvara')->nullable();
            $table->string('email')->nullable();
            $table->string('telefone')->nullable();
            $table->string('telefone_alternativo')->nullable();
            $table->string('website')->nullable();
            $table->string('endereco')->nullable();
            $table->string('caixa_postal')->nullable();
            $table->string('municipio')->nullable();
            $table->string('provincia')->nullable();
            $table->string('responsavel_nome')->nullable();
            $table->string('responsavel_cargo')->nullable();
            $table->unsignedSmallInteger('ano_fundacao')->nullable();
            $table->string('logotipo_path')->nullable();
            $table->text('observacoes')->nullable();
            $table->boolean('is_active')->default(true); // já não selecciona o estabelecimento
            $table->timestamp('configurado_em')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Exactamente um estabelecimento por tenant (spec §8.1).
            $table->unique('tenant_id');
            // Alvo das chaves estrangeiras compostas das tabelas com estabelecimento_id.
            $table->unique(['tenant_id', 'id']);
        });
```

- [ ] **Step 4: Editar a migration das etapas**

Substituir o conteúdo de `2026_09_11_120000_create_estabelecimento_etapas_ensino_table.php` por (o preenchimento retroactivo sai: não há dados a preservar, spec §20):

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estabelecimento_etapas_ensino', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('estabelecimento_id');
            $table->unsignedTinyInteger('etapa_ensino');
            $table->string('etapa_ensino_descricao');
            $table->timestamps();

            $table->unique(['estabelecimento_id', 'etapa_ensino']);

            // Uma etapa só pode apontar para o estabelecimento do seu próprio tenant (spec §8.1).
            $table->foreign(['tenant_id', 'estabelecimento_id'])
                ->references(['tenant_id', 'id'])
                ->on('estabelecimentos')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estabelecimento_etapas_ensino');
    }
};
```

- [ ] **Step 5: Editar os models**

Em `Estabelecimento.php`: acrescentar os `use`

```php
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Tenancy\TenantContext;
```

a trait `use PertenceAoTenant;` a seguir a `use SoftDeletes;`, o cast `'configurado_em' => 'datetime',` em `$casts` (`configurado_em` **não** entra em `$fillable`), e substituir o método `current()` (e o seu docblock) por:

```php
    /**
     * O perfil institucional do tenant corrente.
     *
     * Não é mecanismo de isolamento (isso é o scope do tenant). Lê com scope e
     * guarda o resultado no contexto, por isso custa uma consulta por pedido.
     * Sem tenant resolvido lança TenantNaoResolvido; dentro de um tenant nunca
     * é nulo, porque o estabelecimento nasce no provisioning.
     */
    public static function current(): self
    {
        return app(TenantContext::class)->lembrar(
            'estabelecimento.actual',
            fn () => static::query()->firstOrFail(),
        );
    }

    public function estaConfigurado(): bool
    {
        return $this->configurado_em !== null;
    }
```

Em `EstabelecimentoEtapaEnsino.php`: `use Modules\Core\Tenancy\PertenceAoTenant;` e `use PertenceAoTenant;` dentro da classe.

- [ ] **Step 6: Actualizar a config de transição**

Em `config/tenancy.php`, retirar de `tabelas_por_converter` as linhas `'estabelecimento_etapas_ensino',` e `'estabelecimentos',`.

- [ ] **Step 7: Actualizar a base de testes**

Em `tests/Concerns/ComTenantDeTeste.php`:

1. `criarTenant()` passa a criar o estabelecimento mínimo, como o provisioning fará:

```php
    protected function criarTenant(string $codigo, string $nome, string $dominio): Tenant
    {
        $tenant = Tenant::create(['codigo' => $codigo, 'nome' => $nome]);
        $tenant->dominios()->create(['dominio' => $dominio, 'is_principal' => true]);

        if (Schema::hasTable('estabelecimentos')) {
            $this->noTenant($tenant, fn () => Estabelecimento::create(['nome' => $nome]));
        }

        return $tenant;
    }
```

2. Em `prepararTenantDeTeste()`, remover o bloco `Estabelecimento::create([...])` da Tarefa 1 e, depois de `definir(...)`, marcar o estabelecimento por omissão como configurado (senão o encaminhamento da Tarefa 4 apanharia todos os testes HTTP):

```php
        if (Schema::hasTable('estabelecimentos')) {
            Estabelecimento::current()->forceFill(['configurado_em' => now()])->save();
        }
```

3. `estabelecimentoDeOutroTenant()` passa a devolver o estabelecimento que `criarTenant()` já criou (não pode haver um segundo):

```php
    protected function estabelecimentoDeOutroTenant(array $atributos = []): Estabelecimento
    {
        $n = ++$this->outrosTenants;
        $outro = $this->criarTenant(sprintf('MOSI-%06d', 900000 + $n), "Outra Escola {$n}", "outra-{$n}.localhost");

        return $this->noTenant($outro, function () use ($atributos) {
            $estabelecimento = Estabelecimento::current();
            $estabelecimento->fill(array_merge(['tipo' => TipoEstabelecimentoEnum::PUBLICO->value], $atributos))->save();

            return $estabelecimento;
        });
    }
```

   `estabelecimentoDeTeste()` fica como está. `Estabelecimento::current()` no `prepararTenantDeTeste` funciona porque o contexto já foi definido; o `use` de `TipoEstabelecimentoEnum` mantém-se.

- [ ] **Step 8: Correr os testes novos**

Run: `php artisan test --filter='EstabelecimentoTenancyTest|TenancyEsquemaTest'`
Expected: PASS.

- [ ] **Step 9: Verificar o teste de arquitectura 8 por mutação**

Comentar temporariamente o bloco `$table->foreign(['tenant_id', 'estabelecimento_id'])…` da migration das etapas e correr `php artisan test --filter=test_toda_a_tabela_com_estabelecimento_id`. Expected: FAIL a nomear `estabelecimento_etapas_ensino`. Repor o bloco e confirmar que volta a passar.

- [ ] **Step 10: Correr a suite completa**

Run: `php artisan test`
Expected: tudo a passar (à excepção, eventualmente, de `test_nao_atualiza_logotipo_sem_estabelecimento_cadastrado` e de `test_cria_o_estabelecimento_ao_atualizar_dados_pela_primeira_vez` se ainda não tiverem passado: ficam para a Tarefa 3, e só esses). Falhas noutros testes: quase de certeza `Estabelecimento::create` esquecido na Tarefa 1 (UNIQUE em `tenant_id`) — aplicar a regra do Step 3 da Tarefa 1.

- [ ] **Step 11: Stage**

```bash
git add Modules/Estabelecimento config/tenancy.php tests
```

---

### Task 3: Configuração inicial — `configurado_em` e ecrã "Dados da escola"

**Files:**
- Modify: `Modules/Estabelecimento/app/Actions/AtualizarDadosEstabelecimentoAction.php`
- Modify: `Modules/Estabelecimento/app/Services/GestaoEstabelecimentoService.php`
- Modify: `Modules/Estabelecimento/resources/js/Pages/DadosDaEscola.vue`
- Modify: `Modules/Estabelecimento/tests/Feature/GestaoEstabelecimentoTest.php`

**Interfaces:**
- Consumes: `Estabelecimento::current(): Estabelecimento`, `Estabelecimento::estaConfigurado()` (Tarefa 2).
- Produces:
  - `AtualizarDadosEstabelecimentoAction::executar(EstabelecimentoDTO $dto): Estabelecimento` — sem ramo de criação; preenche `configurado_em` se nulo.
  - `GestaoEstabelecimentoService::obterAtual(): Estabelecimento` (antes `?Estabelecimento`).
  - A prop Inertia `estabelecimento` inclui `configurado_em` (já serializado com o model).

- [ ] **Step 1: Escrever os testes**

Em `Modules/Estabelecimento/tests/Feature/GestaoEstabelecimentoTest.php`:

1. Substituir `test_cria_o_estabelecimento_ao_atualizar_dados_pela_primeira_vez` por:

```php
    private function dadosValidos(array $extra = []): array
    {
        return array_merge([
            'nome' => 'Escola Exemplo',
            'tipo' => TipoEstabelecimentoEnum::PRIVADO->value,
            'tipo_ensino' => TipoEnsinoEnum::GERAL->value,
            'etapas_ensino' => [EtapaEnsinoEnum::PRIMARIO->value],
            'nif' => '5000123456',
        ], $extra);
    }

    private function desconfigurar(): Estabelecimento
    {
        $estabelecimento = Estabelecimento::current();
        $estabelecimento->forceFill(['configurado_em' => null, 'tipo' => null])->save();

        return $estabelecimento;
    }

    public function test_primeira_gravacao_valida_actualiza_o_estabelecimento_minimo_e_preenche_configurado_em(): void
    {
        $this->actingAsAdmin();
        $id = $this->desconfigurar()->id;

        $this->put('/estabelecimento', $this->dadosValidos())->assertRedirect();

        $this->assertSame(1, Estabelecimento::count());
        $this->assertDatabaseHas('estabelecimentos', [
            'id' => $id,
            'nome' => 'Escola Exemplo',
            'tipo' => TipoEstabelecimentoEnum::PRIVADO->value,
            'tipo_descricao' => 'Privado',
            'tipo_ensino' => TipoEnsinoEnum::GERAL->value,
            'tipo_ensino_descricao' => 'Ensino Geral',
        ]);
        $this->assertNotNull(Estabelecimento::current()->configurado_em);
    }

    public function test_gravacao_invalida_nao_preenche_configurado_em(): void
    {
        $this->actingAsAdmin();
        $this->desconfigurar();

        $this->put('/estabelecimento', $this->dadosValidos(['nome' => '']))->assertSessionHasErrors('nome');

        $this->assertNull(Estabelecimento::current()->configurado_em);
    }

    public function test_segunda_gravacao_nao_altera_configurado_em(): void
    {
        $this->actingAsAdmin();
        $this->desconfigurar();

        $this->put('/estabelecimento', $this->dadosValidos())->assertRedirect();
        $primeira = Estabelecimento::current()->configurado_em;

        Carbon::setTestNow($primeira->copy()->addDay());
        $this->put('/estabelecimento', $this->dadosValidos(['nome' => 'Escola Renomeada']))->assertRedirect();
        Carbon::setTestNow();

        $this->assertEquals($primeira, Estabelecimento::current()->configurado_em);
    }

    public function test_ecra_dados_da_escola_abre_com_o_estabelecimento_minimo(): void
    {
        $this->actingAsAdmin();
        $this->desconfigurar();

        $this->get('/estabelecimento')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Estabelecimento/DadosDaEscola')
                ->where('estabelecimento.tipo', null)
                ->where('estabelecimento.configurado_em', null));
    }
```

   Acrescentar os `use` no topo: `use Illuminate\Support\Carbon;` e `use Inertia\Testing\AssertableInertia;`.

2. Apagar `test_nao_atualiza_logotipo_sem_estabelecimento_cadastrado`: o estado "sem estabelecimento" deixou de existir (spec §8.4).

- [ ] **Step 2: Correr para ver falhar**

Run: `php artisan test --filter=GestaoEstabelecimentoTest`
Expected: FAIL nos três primeiros (não há `configurado_em` preenchido) e, no teste do ecrã, só falha se o Vue/props não se comportarem; o backend já serve a prop.

- [ ] **Step 3: Action**

Em `AtualizarDadosEstabelecimentoAction::executar`, substituir a obtenção do estabelecimento e acrescentar a marca, antes do `save()`:

```php
            $estabelecimento = Estabelecimento::current();
```

```php
            // Primeira gravação válida dos dados institucionais: a configuração inicial está feita.
            // Nunca volta a nulo.
            $estabelecimento->configurado_em ??= now();

            $estabelecimento->save();
```

(A gravação só é alcançada depois de o `FormRequest` validar, por isso uma validação falhada não chega a esta linha.)

- [ ] **Step 4: Service**

Em `GestaoEstabelecimentoService`:

```php
    public function obterAtual(): Estabelecimento
    {
        return Estabelecimento::current();
    }

    public function etapasEnsinoConfiguradas(): array
    {
        return Estabelecimento::current()->etapasEnsino()
            ->pluck('etapa_ensino')->map(fn (EtapaEnsinoEnum $e) => $e->value)->all();
    }
```

e `atualizarLogotipo` fica:

```php
    public function atualizarLogotipo(UploadedFile $logotipo): Estabelecimento
    {
        return $this->atualizarLogotipoAction->executar(Estabelecimento::current(), $logotipo);
    }
```

Remover o `use Illuminate\Validation\ValidationException;` que deixa de ser usado.

- [ ] **Step 5: Vue**

Em `Modules/Estabelecimento/resources/js/Pages/DadosDaEscola.vue`:

1. Substituir a linha `const editando = ref(!props.estabelecimento && can('estabelecimento.editar'));` e o comentário anterior por:

```js
// Enquanto a configuração inicial não foi feita (configurado_em nulo) entra logo em edição
// quem pode editar; o estabelecimento mínimo já existe, só falta completá-lo.
const naoConfigurado = !props.estabelecimento?.configurado_em;
const editando = ref(naoConfigurado && can('estabelecimento.editar'));
```

2. No template, logo a seguir a `<EstabelecimentoCabecalho … />`, acrescentar o aviso, com as classes já usadas em `Aparencia.vue`:

```html
        <div v-if="naoConfigurado" class="alert alert-warning d-flex align-items-center">
            <span>Complete os dados da escola para começar a usar o sistema.</span>
        </div>
```

   Se o bloco de `Aparencia.vue` tiver ícone ou estrutura própria, copiá-la tal como está.

3. Correr `npm run build` e confirmar que compila sem erros.

- [ ] **Step 6: Correr os testes**

Run: `php artisan test --filter=GestaoEstabelecimentoTest` e depois `php artisan test`
Expected: PASS em tudo.

- [ ] **Step 7: Verificar no browser**

Com a BD de desenvolvimento já recriada (Tarefa 5) ou, antes disso, num teste manual: abrir `/estabelecimento` com o estabelecimento mínimo e confirmar que o aviso aparece, o formulário está em edição e o cabeçalho não mostra "undefined" no tipo. Se a BD ainda não foi recriada, deixar este passo para o fim da Tarefa 5.

- [ ] **Step 8: Stage**

```bash
git add Modules/Estabelecimento
```

---

### Task 4: Encaminhamento para a configuração inicial

**Files:**
- Create: `Modules/Estabelecimento/app/Http/Middleware/ExigirConfiguracaoInicial.php`
- Modify: `bootstrap/app.php`
- Create: `Modules/Estabelecimento/tests/Feature/ConfiguracaoInicialTest.php`

**Interfaces:**
- Consumes: `Estabelecimento::current()`, `estaConfigurado()` (Tarefa 2); `TenantContext::temTenant()`; rotas `estabelecimento.*` e `logout`.
- Produces: `Modules\Estabelecimento\Http\Middleware\ExigirConfiguracaoInicial` — no grupo `web`.

- [ ] **Step 1: Escrever os testes**

`Modules/Estabelecimento/tests/Feature/ConfiguracaoInicialTest.php`:

```php
<?php

namespace Modules\Estabelecimento\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class ConfiguracaoInicialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function desconfigurar(): void
    {
        Estabelecimento::current()->forceFill(['configurado_em' => null])->save();
    }

    private function utilizador(Perfil $perfil, string $email): User
    {
        $user = User::create(['name' => 'Utilizador', 'email' => $email, 'password' => Hash::make('x')]);
        $user->roles()->attach(Role::where('nome', $perfil->value)->first()->id);

        return $user;
    }

    public function test_quem_pode_editar_e_encaminhado_para_os_dados_da_escola(): void
    {
        $this->desconfigurar();
        $this->actingAs($this->utilizador(Perfil::ADMIN_ESCOLA, 'admin@example.com'));

        $this->get('/usuarios')->assertRedirect(route('estabelecimento.dados'));
    }

    public function test_pedidos_de_escrita_tambem_sao_encaminhados(): void
    {
        $this->desconfigurar();
        $this->actingAs($this->utilizador(Perfil::ADMIN_ESCOLA, 'admin@example.com'));

        $this->post('/usuarios', [])->assertRedirect(route('estabelecimento.dados'));
    }

    public function test_as_rotas_do_estabelecimento_e_o_logout_ficam_de_fora(): void
    {
        $this->desconfigurar();
        $this->actingAs($this->utilizador(Perfil::ADMIN_ESCOLA, 'admin@example.com'));

        $this->get('/estabelecimento')->assertOk();
        $this->get('/estabelecimento/aparencia')->assertOk();
        $this->post('/logout')->assertRedirect();
        $this->assertGuest();
    }

    public function test_depois_de_configurar_deixa_de_encaminhar(): void
    {
        $this->actingAs($this->utilizador(Perfil::ADMIN_ESCOLA, 'admin@example.com'));

        $this->get('/usuarios')->assertOk();
    }

    public function test_quem_nao_pode_editar_nao_e_encaminhado(): void
    {
        $this->desconfigurar();
        $this->actingAs($this->utilizador(Perfil::PROFESSOR, 'prof@example.com'));

        $resposta = $this->get('/');

        $this->assertNotSame(route('estabelecimento.dados'), $resposta->headers->get('Location'));
    }

    public function test_visitante_anonimo_nao_e_encaminhado(): void
    {
        $this->desconfigurar();

        $this->get('/login')->assertOk();
    }

    public function test_rota_inexistente_continua_a_dar_404(): void
    {
        $this->desconfigurar();
        $this->actingAs($this->utilizador(Perfil::ADMIN_ESCOLA, 'admin@example.com'));

        $this->get('/nao-existe-mesmo')->assertNotFound();
    }

    public function test_host_central_sem_tenant_passa_sem_consultar_o_estabelecimento(): void
    {
        config(['tenancy.hosts_centrais' => ['central.localhost']]);
        $this->desconfigurar();

        $this->get('http://central.localhost/login')->assertOk();
    }
}
```

Antes de correr, confirmar `Perfil::PROFESSOR` e que `GET /usuarios` e `GET /login` existem (`php artisan route:list --path=usuarios`, `--path=login`); se o nome do caso do enum ou a rota forem outros, usar os reais. No teste do host central, se `/login` não responder 200 sem tenant (por exemplo por usar dados do tenant), usar outra rota pública existente.

- [ ] **Step 2: Correr para ver falhar**

Run: `php artisan test --filter=ConfiguracaoInicialTest`
Expected: FAIL nos dois primeiros (não há redirecionamento), os restantes passam.

- [ ] **Step 3: Escrever o middleware**

`Modules/Estabelecimento/app/Http/Middleware/ExigirConfiguracaoInicial.php`:

```php
<?php

namespace Modules\Estabelecimento\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Tenancy\TenantContext;
use Modules\Estabelecimento\Models\Estabelecimento;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enquanto a configuração inicial do estabelecimento não foi feita, leva quem
 * pode editá-lo para o ecrã de dados da escola (spec §8.5). Quem não tem essa
 * permissão segue normalmente.
 */
class ExigirConfiguracaoInicial
{
    public function __construct(private TenantContext $contexto) {}

    public function handle(Request $request, Closure $next): Response
    {
        $utilizador = $request->user();

        if ($utilizador === null || ! $this->contexto->temTenant() || $this->rotaIsenta($request)) {
            return $next($request);
        }

        if (Estabelecimento::current()->estaConfigurado() || ! $utilizador->can('estabelecimento.editar')) {
            return $next($request);
        }

        return redirect()->route('estabelecimento.dados');
    }

    private function rotaIsenta(Request $request): bool
    {
        $rota = $request->route();

        return $rota === null
            || $rota->getName() === 'logout'
            || $request->routeIs('estabelecimento.*');
    }
}
```

- [ ] **Step 4: Registar no grupo `web`**

Em `bootstrap/app.php`, acrescentar o `use` e a classe ao `$middleware->web(append: [...])`, **depois** de `HandleInertiaRequests` (a sessão e o utilizador já estão disponíveis no grupo `web`):

```php
use Modules\Estabelecimento\Http\Middleware\ExigirConfiguracaoInicial;
```
```php
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            ExigirConfiguracaoInicial::class,
        ]);
```

- [ ] **Step 5: Correr os testes**

Run: `php artisan test --filter=ConfiguracaoInicialTest` e depois `php artisan test`
Expected: PASS em tudo. Se algum teste HTTP antigo passar a ser encaminhado, é porque o estabelecimento por omissão não ficou configurado (Step 7.2 da Tarefa 2).

- [ ] **Step 6: Stage**

```bash
git add Modules/Estabelecimento bootstrap/app.php
```

---

### Task 5: Estabelecimento de desenvolvimento e seeders dentro do contexto

**Files:**
- Create: `Modules/Estabelecimento/database/seeders/EstabelecimentoDesenvolvimentoSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Create: `Modules/Estabelecimento/tests/Feature/EstabelecimentoDesenvolvimentoSeederTest.php`

**Interfaces:**
- Consumes: `TenantContext::executarComo(TenantAtual $tenant, Closure $fn): mixed`, `Tenant::paraTenantAtual(): TenantAtual`, `TenantDesenvolvimentoSeeder` (Plano 1).
- Produces: `Modules\Estabelecimento\Database\Seeders\EstabelecimentoDesenvolvimentoSeeder` — cria o estabelecimento mínimo do tenant corrente, se não existir; só corre em `local` e `testing`.

- [ ] **Step 1: Escrever o teste**

`Modules/Estabelecimento/tests/Feature/EstabelecimentoDesenvolvimentoSeederTest.php`:

```php
<?php

namespace Modules\Estabelecimento\Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Tenancy\TenantContext;
use Modules\Estabelecimento\Database\Seeders\EstabelecimentoDesenvolvimentoSeeder;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Tenant\Models\Tenant;
use Tests\TestCase;

class EstabelecimentoDesenvolvimentoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_nao_duplica_o_estabelecimento_do_tenant(): void
    {
        $this->seed(EstabelecimentoDesenvolvimentoSeeder::class);
        $this->seed(EstabelecimentoDesenvolvimentoSeeder::class);

        $this->assertSame(1, Estabelecimento::count());
    }

    public function test_cria_o_minimo_num_tenant_sem_estabelecimento(): void
    {
        // Um tenant sem estabelecimento já não se cria pelo auxiliar, que imita o provisioning.
        $outro = Tenant::create(['codigo' => 'MOSI-000777', 'nome' => 'Sem Estabelecimento']);

        $this->noTenant($outro, function () {
            $this->seed(EstabelecimentoDesenvolvimentoSeeder::class);

            $estabelecimento = Estabelecimento::current();
            $this->assertSame('Escola de Desenvolvimento', $estabelecimento->nome);
            $this->assertNull($estabelecimento->configurado_em);
        });
    }

    public function test_database_seeder_corre_dentro_do_contexto_e_restaura_o_anterior(): void
    {
        $antes = app(TenantContext::class)->atual()->id;

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($antes, app(TenantContext::class)->atual()->id);
        $this->assertSame(1, Estabelecimento::count());
    }
}
```

- [ ] **Step 2: Correr para ver falhar**

Run: `php artisan test --filter=EstabelecimentoDesenvolvimentoSeederTest`
Expected: FAIL (classe do seeder inexistente).

- [ ] **Step 3: Escrever o seeder**

`Modules/Estabelecimento/database/seeders/EstabelecimentoDesenvolvimentoSeeder.php`:

```php
<?php

namespace Modules\Estabelecimento\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Estabelecimento\Models\Estabelecimento;

/**
 * Estabelecimento mínimo do tenant de desenvolvimento: só o nome, com configurado_em nulo,
 * como o provisioning fará. Corre dentro do contexto de um tenant.
 * Provisório: é substituído pelo provisionador do módulo quando o provisioning existir.
 */
class EstabelecimentoDesenvolvimentoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }

        if (! Estabelecimento::query()->exists()) {
            Estabelecimento::create(['nome' => 'Escola de Desenvolvimento']);
        }
    }
}
```

- [ ] **Step 4: `DatabaseSeeder` dentro do contexto**

Substituir `database/seeders/DatabaseSeeder.php` por (mantendo os `use` e os comentários dos seeders desligados; `DatabaseSeeder` já é excepção à regra "só o módulo Tenant conhece o módulo Tenant"):

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Autenticacao\Database\Seeders\AdminUserSeeder;
use Modules\Core\Tenancy\TenantContext;
use Modules\Estabelecimento\Database\Seeders\EstabelecimentoDesenvolvimentoSeeder;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Tenant\Database\Seeders\TenantDesenvolvimentoSeeder;
use Modules\Tenant\Models\Tenant;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(TenantDesenvolvimentoSeeder::class);

        // Tudo o resto é dados de um tenant: corre dentro do contexto do tenant de desenvolvimento.
        $tenant = Tenant::where('codigo', 'MOSI-000001')->firstOrFail();

        app(TenantContext::class)->executarComo($tenant->paraTenantAtual(), function () {
            $this->call([
                EstabelecimentoDesenvolvimentoSeeder::class,
                PermissaoDatabaseSeeder::class,
                AdminUserSeeder::class,
                // DisciplinaDatabaseSeeder::class,
                // CursoDatabaseSeeder::class,
                // CoreDatabaseSeeder::class,
                // TurmaDatabaseSeeder::class,
                // InfraestruturaDatabaseSeeder::class,
            ]);
        });
    }
}
```

   Se os `use` dos seeders comentados forem precisos mais tarde, mantê-los no topo como estavam.

- [ ] **Step 5: Correr os testes**

Run: `php artisan test --filter=EstabelecimentoDesenvolvimentoSeederTest` e depois `php artisan test`
Expected: PASS em tudo.

- [ ] **Step 6: Recriar a BD de desenvolvimento — pedir confirmação ao utilizador**

As migrations originais mudaram, por isso a BD local tem de ser recriada. **Parar e pedir confirmação** antes de correr `php artisan migrate:fresh --seed` (apaga todos os dados locais). Com confirmação, correr e verificar:

- `/login` responde 200 em `localhost`, `127.0.0.1` e no host de `APP_URL`;
- `php artisan tinker` **não**: verificar só pela aplicação. Entrar como administrador (`admin@mositec.gmail.com`), confirmar que é encaminhado para `/estabelecimento`, que o aviso e o formulário em edição aparecem, e que depois de gravar o encaminhamento cessa e o menu navega normalmente;
- iniciar sessão com um utilizador sem `estabelecimento.editar` e confirmar que navega sem ser encaminhado.

Isto fecha também o Step 7 da Tarefa 3.

- [ ] **Step 7: Stage**

```bash
git add Modules/Estabelecimento database/seeders/DatabaseSeeder.php
```

---

## Auto-revisão

**Cobertura do spec (etapa 4 de §20.2 e §8):**
- §8.1 (1, 2, 3): únicos `tenant_id` e `(tenant_id, id)`, chave composta nas etapas → Tarefa 2; as outras oito tabelas ficam para a etapa 6 (arquitectura 8 já as vigia através de `tabelas_por_converter`).
- §8.4 `current()` pelo contexto, `self` não nulo, `TenantNaoResolvido`, ramo `?? new` removido, `is_active` sem função → Tarefas 2 e 3.
- §8.5 `configurado_em`, preenchido na primeira gravação, nunca volta a nulo, encaminhamento só para quem edita, rotas do ecrã e logout isentas, campos obrigatórios a aceitar nulo (`tipo`), obrigatoriedade mantida no formulário → Tarefas 2, 3 e 4.
- §17.4 ecrã existente como destino do encaminhamento → Tarefa 3.
- §19.1 tenant por omissão com estabelecimento → Tarefas 1 e 2. §19.4 último ponto (tenant novo, encaminhamento, gravação, sem permissão) → Tarefas 3 e 4. §19.2 "Consistência" (par `tenant_id`/`estabelecimento_id` e segundo estabelecimento) → Tarefa 2.
- Fora por decisão: logótipo em `tenants/{id}/…` (etapa 8) e provisionador (etapa 9); ficam registados em "Fora deste plano".

**Tipos e nomes:** `estabelecimentoDeTeste`, `estabelecimentoDeOutroTenant`, `estaConfigurado`, `configurado_em`, `ExigirConfiguracaoInicial`, `EstabelecimentoDesenvolvimentoSeeder` usados com o mesmo nome em todas as tarefas.

**Risco conhecido:** a Tarefa 1 toca ~67 ficheiros de teste por regra e por script; o que o script não apanha (casos de várias linhas e `is_active => false`) é feito à mão e a suite é o critério de fecho. O `ExigirConfiguracaoInicial` adiciona uma consulta por pedido autenticado (memorizada pelo contexto), como o spec prevê em §8.4.
