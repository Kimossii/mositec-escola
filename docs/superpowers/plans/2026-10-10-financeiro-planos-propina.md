# Financeiro — Planos de Propina — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Entregar `Configurações → Financeiro → Planos de Propina`: planos por ano lectivo com periodicidade, valor e período de cobrança (que pode atravessar o ano civil), aplicáveis a turmas por nível, curso, turno ou turma específica, com precedência de 9 níveis, fases de preço no mesmo ano e a resolução do plano aplicável (`ResolvePlanoAplicavel`).

**Architecture:** Continua `Modules/Financeiro`. A matemática de calendário e a precedência são classes puras (`CalendarioDePlano`, `Precedencia`), testadas sem base de dados. `PlanoPropina` (+ `PlanoPropinaAlvo`) é tenant-aware; a validação cruzada (período × periodicidade, alvos, colisão por sobreposição de períodos) vive num trait de FormRequest partilhado por Criar e Atualizar. `ResolvePlanoAplicavel` é o ponto único que o futuro módulo de Propinas usa. O valor é sempre na **moeda da escola** (`MoedaDoTenant`): validado por `ValorMonetario(false)` (regra partilhada com Produtos e Serviços, já implementada no plano Moeda e Câmbio), guardado em unidades menores e apresentado com `formatarDinheiro(valor, moeda)`. `PrecosDosPlanos` (`FonteDePrecos`) bloqueia a troca de moeda enquanto existir algum plano.

**Tech Stack:** Laravel + nwidart/laravel-modules, Inertia + Vue 3, PHPUnit 11 com SQLite (produção pgsql), Metronic/Bootstrap.

**Spec:** `docs/superpowers/specs/2026-10-08-modulo-financeiro-configuracao-design.md` (secções 2, 3 `planos_propina` e `plano_propina_alvos`, 5, 6, 7, 8).

## Global Constraints

- Texto de UI, mensagens e comentários em PT-PT com acentos correctos. Identificadores, rotas, tabelas e variáveis sem acento.
- Dinheiro: `bigInteger` em **unidades menores da moeda da escola** (`Dinheiro::deUnidadesMenores` / `->unidadesMenores()`), nunca float; sem moeda nem casas decimais fixas no código. Formulários recebem o valor decimal na moeda da escola e convertem com `Dinheiro::deDecimal($valor, app(MoedaDoTenant::class)->atual())`; a validação é `new ValorMonetario(false)` (inválido, casas a mais e zero rejeitados, mensagens PT-PT vindas da regra). O valor do plano é **por período de cobrança** e tem de ser **> 0**. Os testes assumem a moeda do tenant de teste por omissão (**AOA**, 2 casas) e declaram-no; a lógica não depende das casas.
- **Fonte de preços:** os planos guardam preços de configuração, logo `PrecosDosPlanos` implementa `FonteDePrecos` e é registada com a etiqueta `financeiro.fontes-de-precos` (como `PrecosDoCatalogo`): enquanto existir um plano, `MoedaDoTenant::podeAlterar()` é falso.
- Período: `mes_inicio` e `mes_fim` em 1–12; `mes_inicio > mes_fim` é válido (atravessa o ano civil). `meses = ((mes_fim - mes_inicio + 12) % 12) + 1`. O plano gera `ceil(meses / intervalo_meses)` períodos de cobrança e o último pode ser mais curto; só se rejeita `intervalo_meses > meses`.
- Competências ancoradas em `ano_lectivo.data_inicio`: a primeira é a primeira ocorrência de `mes_inicio` igual ou posterior ao mês de `data_inicio` (Set→Jun com ano a começar em 2026-09-01: Set/2026…Jun/2027; Jan→Dez no mesmo ano: Jan/2027…Dez/2027).
- Estado: `estado` + `estado_descricao` (`SincronizaEstadoDescricao`); enums inteiros têm coluna irmã `*_descricao`. **Hooks `saving` têm de ser null-safe** (`$m->periodicidade?->label()`): a matriz de isolamento cria cada model em cru e espera `TenantNaoResolvido`.
- Tenancy: só `tenant_id` (`PertenceAoTenant`), nunca vem do cliente; FKs (`ano_lectivo_id`, `nivel_academico_id`, `curso_id`, `turno_id`, `turma_id`) validadas contra o tenant e com `deleted_at` nulo onde há soft delete (níveis, turnos, turmas, anos lectivos; **cursos não têm soft delete**). Unicidade por tenant.
- Permissões por recurso: `plano-propina.{ver,criar,editar,eliminar}`. Sem mecanismo paralelo de autorização. Eliminar só se `ReferenciasFinanceiras` não encontrar referência (erro na chave `eliminar`).
- **Alvos** (cada linha com `nivel_academico_id`, `curso_id`, `turno_id`, `turma_id`, todos opcionais): sem alvos = plano geral; `turma_id` é **exclusivo** (os outros três nulos) e a turma tem de ser do **ano lectivo do plano**; linhas totalmente vazias são ignoradas; uma turma sem turno nunca casa um alvo de turno.
- **Precedência** (ordem total; ganha o maior): `[turma específica ? 1 : 0, nº de dimensões (curso, nível, turno), máscara curso=4 + nível=2 + turno=1]`, comparado por posição. Ordem: turma > curso+nível+turno > curso+nível > curso+turno > nível+turno > só curso > só nível > só turno > geral. Empate entre planos **distintos** = **conflito** (nunca vencedor arbitrário).
- **Colisão ao gravar** = mesmos alvos **e** competências sobrepostas no mesmo ano lectivo (planos inactivos contam). Alvos iguais com períodos disjuntos coexistem (fases de preço). O ano lectivo de um plano é imutável na edição.
- **Resolução por competência:** `paraTurma(Turma, ?array $competencia)`; com `['ano' => int, 'mes' => int]` só entram planos cujo período a contém; sem ela o tempo é ignorado.
- PHP: `use` no topo, nunca FQN inline. Controllers finos: leitura via Service, escrita via Action, com DTO e FormRequest.
- Testes em SQLite (sem JSON, sem CHECK por SQL cru). Produção é pgsql.
- **Menus: acrescentar SEMPRE nos dois sítios** — `resources/js/Composables/useConfiguracoesMenu.js` (`links/label`) e `resources/js/Components/Layout/SidebarMenuWrapper.vue` (`items/title`, array `configuracoesMenu`).
- A suite completa (`php artisan test`) é o critério de aceitação final (a matriz de isolamento só falha lá).
- Aplicar migrations e seeds em bases existentes é do dono (`migrate`, `db:seed --force`, `financeiro:sincronizar --todos`).
- **Git: só `git add` (stage). Nunca `git commit` sem o dono pedir.**
- Fora deste plano: gerar propinas, associar a matrículas, descontos/bolsas, `confirmar/anular/cancelar` no catálogo `Acao` (plano de Propinas), captura de SQLSTATE 23503 nas eliminações (plano de Propinas).

## Review Focus

1. Set→Jun trimestral dá 4 períodos (3+3+3+1); Jan→Dez com ano a começar em Set/2026 começa em Jan/2027; ano a começar em Ago/2026 com plano a começar em Set dá Set/2026 — Task 3.
2. Valor `0`, vazio, `-1`, casas a mais, `25.000,50` e `"25000\n"` rejeitados; `25000,50` aceite e gravado como 2.500.050 unidades menores (moeda de teste AOA); com moeda de 0 casas só inteiros — Task 5 (regra `ValorMonetario(false)`, já testada no plano Moeda e Câmbio).
3. Com planos existentes a moeda não pode mudar (`PrecosDosPlanos`); sem planos pode — Task 5.
4. `ano_lectivo_id`, `nivel_academico_id`, `curso_id`, `turno_id` e `turma_id` de outro tenant rejeitados; turma de outro ano lectivo rejeitada; turma combinada com outros campos rejeitada; PUT/PATCH/DELETE de outro tenant dão 404 — Task 5.
5. Fases de preço: mesmos alvos com períodos disjuntos coexistem; sobrepostos colidem; dois planos gerais com períodos sobrepostos colidem; editar sem mudar alvos não colide consigo — Task 5.
6. Precedência: cada um dos 9 níveis vence o de baixo; curso vence nível; nível+turno vence só curso; turma específica vence tudo; com competência escolhe a fase certa; empate real devolve conflito; inactivo e outro ano ignorados — Tasks 3, 6.

## Mapa de ficheiros

```
Modules/Financeiro/
  (T1 absorvida: a regra de valor é `Rules/ValorMonetario.php`, do plano Moeda e Câmbio)
  app/Enums/Periodicidade.php                                    (T3)
  app/Support/{CalendarioDePlano,Precedencia}.php                (T3)
  app/Support/{AlvosDoPlano,PrecosDosPlanos}.php                 (T5)
  database/migrations/2026_10_10_{100000_create_planos_propina,100100_create_plano_propina_alvos}_table.php (T4)
  app/Models/{PlanoPropina,PlanoPropinaAlvo}.php                 (T4)
  app/DTO/PlanoPropinaDTO.php                                    (T5)
  app/Http/Requests/Concerns/ValidaPlanoPropina.php              (T5)
  app/Http/Requests/{CriarPlanoPropina,AtualizarPlanoPropina,AlterarEstadoPlanoPropina}Request.php (T5)
  app/Actions/{Criar,Atualizar,AlterarEstado,Eliminar}PlanoPropinaAction.php (T5)
  app/Services/{GestaoPlanoPropinaService,PlanoPropinaConsultaService}.php (T5)
  app/Http/Controllers/PlanoPropinaController.php                (T5)
  app/Services/ResolvePlanoAplicavel.php, app/Support/ResultadoResolucaoPlano.php (T6)
  resources/js/Components/PlanosPropina/PlanoPropinaFormModal.vue (T7)
  resources/js/Pages/PlanosPropina/Index.vue                     (T7)
  tests/Concerns/ComDadosAcademicosFinanceiro.php                (T4)
  tests/{Unit,Feature}/*
Modificados: Modules/Financeiro/routes/web.php; Providers/FinanceiroServiceProvider.php (T5, tag da fonte de preços);
  Modules/Permissao/{app/Enums/Modulo.php, database/seeders/ModuloSeeder.php,
  app/Actions/SincronizarPerfisDeSistemaAction.php, tests/Feature/ModuloSeederTest.php} (T2);
  resources/js/Composables/useConfiguracoesMenu.js, resources/js/Components/Layout/SidebarMenuWrapper.vue (T7)
```

---

### Task 1: SUPERSEDED — absorvida pela regra `ValorMonetario`

> **Substituída.** O plano Moeda e Câmbio (`2026-10-11-financeiro-moeda-cambio.md`) criou `Modules/Financeiro/app/Rules/ValorMonetario.php` e refez os Requests de Produto e Serviço para a usar. `new ValorMonetario(false)` rejeita zero (`new ValorMonetario()` aceita-o) e valida as casas decimais da **moeda da escola**. Nada a implementar aqui: a Task 5 usa `ValorMonetario(false)` e `Dinheiro::deDecimal`. A numeração mantém-se.


---

### Task 2: Permissão `plano-propina`

**Files:**
- Modify: `Modules/Permissao/app/Enums/Modulo.php` (`PLANO_PROPINA = 20` em `case`, `slug()` e `label()`)
- Modify: `Modules/Permissao/database/seeders/ModuloSeeder.php`
- Modify: `Modules/Permissao/app/Actions/SincronizarPerfisDeSistemaAction.php`
- Modify: `Modules/Permissao/tests/Feature/ModuloSeederTest.php` (`20` passa a `21`)
- Test: `Modules/Financeiro/tests/Feature/PermissoesPlanoPropinaTest.php`

**Interfaces:**
- Produces: abilities `plano-propina.{ver,criar,editar,eliminar}` concedidas só ao `ADMIN_ESCOLA`.

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Modulo;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class PermissoesPlanoPropinaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function utilizadorCom(Perfil $perfil): User
    {
        $user = User::create(['name' => 'Teste', 'email' => $perfil->value . '@example.com', 'password' => Hash::make('x')]);
        $user->roles()->syncWithoutDetaching([Role::where('nome', $perfil->value)->first()->id]);

        return $user;
    }

    public function test_modulo_tem_slug_e_label(): void
    {
        $this->assertSame('plano-propina', Modulo::PLANO_PROPINA->slug());
        $this->assertSame(Modulo::PLANO_PROPINA, Modulo::fromSlug('plano-propina'));
        $this->assertSame('Plano de Propina', Modulo::PLANO_PROPINA->label());
    }

    public function test_admin_escola_tem_as_quatro_accoes(): void
    {
        $admin = $this->utilizadorCom(Perfil::ADMIN_ESCOLA);

        foreach (['ver', 'criar', 'editar', 'eliminar'] as $acao) {
            $this->assertTrue(Gate::forUser($admin)->allows("plano-propina.{$acao}"), $acao);
        }
    }

    public function test_funcionario_e_professor_nao_acedem(): void
    {
        foreach ([Perfil::FUNCIONARIO, Perfil::PROFESSOR] as $perfil) {
            $this->assertFalse(Gate::forUser($this->utilizadorCom($perfil))->allows('plano-propina.ver'), $perfil->name);
        }
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/PermissoesPlanoPropinaTest.php`
Expected: FAIL (`Modulo::PLANO_PROPINA` inexistente).

- [ ] **Step 3: Implementar**

Em `Modules/Permissao/app/Enums/Modulo.php`: depois de `case CATALOGO_FINANCEIRO = 19;` acrescentar `case PLANO_PROPINA = 20;`; em `slug()`, depois de `self::CATALOGO_FINANCEIRO => 'catalogo-financeiro',`: `self::PLANO_PROPINA => 'plano-propina',`; em `label()`, depois de `self::CATALOGO_FINANCEIRO => 'Catálogo Financeiro',`: `self::PLANO_PROPINA => 'Plano de Propina',`.

Em `ModuloSeeder.php`, depois da linha do `19`: `['nome' => 20, 'descricao' => 'Plano de Propina'],`.

Em `SincronizarPerfisDeSistemaAction::PERMISSOES_POR_PERFIL`, bloco ADMIN_ESCOLA, depois de `Modulo::CATALOGO_FINANCEIRO->value => [...]`: `Modulo::PLANO_PROPINA->value => ['ver', 'criar', 'editar', 'eliminar'],`.

Em `ModuloSeederTest.php` trocar `assertSame(20,` por `assertSame(21,`.

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Feature/PermissoesPlanoPropinaTest.php Modules/Permissao`
Expected: PASS. Se outro teste existente falhar só por assumir 20 módulos, corrigi-lo minimamente e dizê-lo no relatório.

- [ ] **Step 5: Stage**

```bash
git add Modules/Permissao Modules/Financeiro/tests/Feature/PermissoesPlanoPropinaTest.php
```

---

### Task 3: `Periodicidade`, `CalendarioDePlano` e `Precedencia` (lógica pura)

**Files:**
- Create: `Modules/Financeiro/app/Enums/Periodicidade.php`
- Create: `Modules/Financeiro/app/Support/CalendarioDePlano.php`, `Modules/Financeiro/app/Support/Precedencia.php`
- Test: `Modules/Financeiro/tests/Unit/PeriodicidadeTest.php`, `Modules/Financeiro/tests/Unit/CalendarioDePlanoTest.php`, `Modules/Financeiro/tests/Unit/PrecedenciaTest.php`

**Interfaces:**
- Produces:
  - `Periodicidade` (int): `OUTRA=0`, `MENSAL=1`, `BIMESTRAL=2`, `TRIMESTRAL=3`, `SEMESTRAL=6`, `ANUAL=12`; `label(): string`; `meses(): ?int` (`null` em `OUTRA`); `static opcoes(): list<array{value:int,label:string}>` (Mensal … Anual, Outra no fim);
  - `CalendarioDePlano::meses(int $mesInicio, int $mesFim): int` (lança `InvalidArgumentException` fora de 1–12);
  - `CalendarioDePlano::competencias(int $mesInicio, int $mesFim, CarbonInterface $inicioAnoLectivo): list<array{ano:int,mes:int}>`;
  - `CalendarioDePlano::periodos(int $mesInicio, int $mesFim, int $intervaloMeses, CarbonInterface $inicioAnoLectivo): list<array{ordem:int,meses:int,inicio:string,fim:string}>` (`inicio`/`fim` em `Y-m-d`; o último período pode ser mais curto; lança se o intervalo estiver fora de 1–12);
  - `CalendarioDePlano::sobrepoem(array $a, array $b): bool` (duas listas de competências têm algum mês em comum) e `CalendarioDePlano::contem(array $competencias, int $ano, int $mes): bool`;
  - `Precedencia::rank(array $alvo): array{int,int,int}` (comparável com `<=>`) e `Precedencia::descricao(array $alvo): string`, onde `$alvo` tem as chaves opcionais `nivel_academico_id`, `curso_id`, `turno_id`, `turma_id` (nulo ou ausente = não definido).

- [ ] **Step 1: Escrever os testes que falham**

`Modules/Financeiro/tests/Unit/PeriodicidadeTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Unit;

use Modules\Financeiro\Enums\Periodicidade;
use PHPUnit\Framework\TestCase;

class PeriodicidadeTest extends TestCase
{
    public function test_valores_e_meses(): void
    {
        $this->assertSame(1, Periodicidade::MENSAL->meses());
        $this->assertSame(2, Periodicidade::BIMESTRAL->meses());
        $this->assertSame(3, Periodicidade::TRIMESTRAL->meses());
        $this->assertSame(6, Periodicidade::SEMESTRAL->meses());
        $this->assertSame(12, Periodicidade::ANUAL->meses());
        $this->assertNull(Periodicidade::OUTRA->meses());
        $this->assertSame(0, Periodicidade::OUTRA->value);
    }

    public function test_labels(): void
    {
        $this->assertSame('Mensal', Periodicidade::MENSAL->label());
        $this->assertSame('Bimestral', Periodicidade::BIMESTRAL->label());
        $this->assertSame('Trimestral', Periodicidade::TRIMESTRAL->label());
        $this->assertSame('Semestral', Periodicidade::SEMESTRAL->label());
        $this->assertSame('Anual', Periodicidade::ANUAL->label());
        $this->assertSame('Outra periodicidade', Periodicidade::OUTRA->label());
    }

    public function test_opcoes_tem_outra_no_fim(): void
    {
        $opcoes = Periodicidade::opcoes();

        $this->assertCount(6, $opcoes);
        $this->assertSame(['value' => 1, 'label' => 'Mensal'], $opcoes[0]);
        $this->assertSame(['value' => 0, 'label' => 'Outra periodicidade'], $opcoes[5]);
    }
}
```

`Modules/Financeiro/tests/Unit/CalendarioDePlanoTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Unit;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\Financeiro\Support\CalendarioDePlano;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CalendarioDePlanoTest extends TestCase
{
    public function test_meses_do_periodo(): void
    {
        $this->assertSame(10, CalendarioDePlano::meses(9, 6)); // Set -> Jun atravessa o ano civil
        $this->assertSame(12, CalendarioDePlano::meses(1, 12));
        $this->assertSame(12, CalendarioDePlano::meses(9, 8));
        $this->assertSame(1, CalendarioDePlano::meses(6, 6));
        $this->assertSame(2, CalendarioDePlano::meses(12, 1));
    }

    #[DataProvider('mesesInvalidos')]
    public function test_meses_fora_de_1_a_12_sao_rejeitados(int $inicio, int $fim): void
    {
        $this->expectException(InvalidArgumentException::class);

        CalendarioDePlano::meses($inicio, $fim);
    }

    public static function mesesInvalidos(): array
    {
        return ['inicio 0' => [0, 6], 'inicio 13' => [13, 6], 'fim 0' => [9, 0], 'fim 13' => [9, 13]];
    }

    public function test_set_a_jun_com_ano_a_comecar_em_setembro_atravessa_o_ano_civil(): void
    {
        $competencias = CalendarioDePlano::competencias(9, 6, CarbonImmutable::parse('2026-09-01'));

        $this->assertCount(10, $competencias);
        $this->assertSame(['ano' => 2026, 'mes' => 9], $competencias[0]);
        $this->assertSame(['ano' => 2026, 'mes' => 12], $competencias[3]);
        $this->assertSame(['ano' => 2027, 'mes' => 1], $competencias[4]);
        $this->assertSame(['ano' => 2027, 'mes' => 6], $competencias[9]);
    }

    public function test_jan_a_dez_com_ano_a_comecar_em_setembro_comeca_no_ano_seguinte(): void
    {
        $competencias = CalendarioDePlano::competencias(1, 12, CarbonImmutable::parse('2026-09-01'));

        $this->assertCount(12, $competencias);
        $this->assertSame(['ano' => 2027, 'mes' => 1], $competencias[0]);
        $this->assertSame(['ano' => 2027, 'mes' => 12], $competencias[11]);
    }

    public function test_mes_de_inicio_igual_ao_do_ano_lectivo_fica_no_mesmo_ano(): void
    {
        $competencias = CalendarioDePlano::competencias(9, 6, CarbonImmutable::parse('2026-08-15'));

        $this->assertSame(['ano' => 2026, 'mes' => 9], $competencias[0]);
    }

    public function test_mes_de_inicio_anterior_ao_do_ano_lectivo_vai_para_o_ano_seguinte(): void
    {
        $competencias = CalendarioDePlano::competencias(9, 6, CarbonImmutable::parse('2026-10-01'));

        $this->assertSame(['ano' => 2027, 'mes' => 9], $competencias[0]);
        $this->assertSame(['ano' => 2028, 'mes' => 6], $competencias[9]);
    }

    public function test_mes_unico(): void
    {
        $this->assertSame(
            [['ano' => 2027, 'mes' => 6]],
            CalendarioDePlano::competencias(6, 6, CarbonImmutable::parse('2026-09-01')),
        );
    }

    public function test_trimestral_em_dez_meses_da_tres_tres_tres_e_um(): void
    {
        $periodos = CalendarioDePlano::periodos(9, 6, 3, CarbonImmutable::parse('2026-09-01'));

        $this->assertSame([3, 3, 3, 1], array_column($periodos, 'meses'));
        $this->assertSame([1, 2, 3, 4], array_column($periodos, 'ordem'));
        $this->assertSame(['ordem' => 1, 'meses' => 3, 'inicio' => '2026-09-01', 'fim' => '2026-11-30'], $periodos[0]);
        $this->assertSame(['ordem' => 2, 'meses' => 3, 'inicio' => '2026-12-01', 'fim' => '2027-02-28'], $periodos[1]);
        $this->assertSame(['ordem' => 4, 'meses' => 1, 'inicio' => '2027-06-01', 'fim' => '2027-06-30'], $periodos[3]);
    }

    public function test_mensal_da_um_periodo_por_mes(): void
    {
        $periodos = CalendarioDePlano::periodos(9, 6, 1, CarbonImmutable::parse('2026-09-01'));

        $this->assertCount(10, $periodos);
        $this->assertSame(range(1, 10), array_column($periodos, 'ordem'));
        $this->assertSame('2027-02-28', $periodos[5]['fim']);
    }

    public function test_anual_em_dez_meses_da_um_periodo_curto(): void
    {
        $periodos = CalendarioDePlano::periodos(9, 6, 12, CarbonImmutable::parse('2026-09-01'));

        $this->assertCount(1, $periodos);
        $this->assertSame(10, $periodos[0]['meses']);
        $this->assertSame('2027-06-30', $periodos[0]['fim']);
    }

    #[DataProvider('intervalosInvalidos')]
    public function test_intervalo_fora_de_1_a_12_e_rejeitado(int $intervalo): void
    {
        $this->expectException(InvalidArgumentException::class);

        CalendarioDePlano::periodos(9, 6, $intervalo, CarbonImmutable::parse('2026-09-01'));
    }

    public static function intervalosInvalidos(): array
    {
        return ['zero' => [0], 'treze' => [13], 'negativo' => [-1]];
    }

    public function test_fases_de_preco_set_a_dez_e_jan_a_jun_nao_se_sobrepoem(): void
    {
        $inicio = CarbonImmutable::parse('2026-09-01');
        $fase1 = CalendarioDePlano::competencias(9, 12, $inicio);
        $fase2 = CalendarioDePlano::competencias(1, 6, $inicio);

        $this->assertFalse(CalendarioDePlano::sobrepoem($fase1, $fase2));
        $this->assertFalse(CalendarioDePlano::sobrepoem($fase2, $fase1));
    }

    public function test_periodos_com_um_mes_em_comum_sobrepoem_se(): void
    {
        $inicio = CarbonImmutable::parse('2026-09-01');
        $a = CalendarioDePlano::competencias(9, 12, $inicio);
        $b = CalendarioDePlano::competencias(12, 6, $inicio); // Dez/2026 .. Jun/2027

        $this->assertTrue(CalendarioDePlano::sobrepoem($a, $b));
        $this->assertTrue(CalendarioDePlano::sobrepoem($a, $a));
    }

    public function test_contem_compara_ano_e_mes(): void
    {
        $competencias = CalendarioDePlano::competencias(9, 6, CarbonImmutable::parse('2026-09-01'));

        $this->assertTrue(CalendarioDePlano::contem($competencias, 2026, 9));
        $this->assertTrue(CalendarioDePlano::contem($competencias, 2027, 6));
        $this->assertFalse(CalendarioDePlano::contem($competencias, 2026, 8));
        $this->assertFalse(CalendarioDePlano::contem($competencias, 2027, 9)); // mesmo mês, outro ano
    }
}
```

`Modules/Financeiro/tests/Unit/PrecedenciaTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Unit;

use Modules\Financeiro\Support\Precedencia;
use PHPUnit\Framework\TestCase;

class PrecedenciaTest extends TestCase
{
    /**
     * Os nove níveis, do menos para o mais específico.
     *
     * @return array<string, array<string, int>>
     */
    private function niveis(): array
    {
        return [
            'Geral' => [],
            'Turno' => ['turno_id' => 1],
            'Nível' => ['nivel_academico_id' => 1],
            'Curso' => ['curso_id' => 1],
            'Nível + Turno' => ['nivel_academico_id' => 1, 'turno_id' => 1],
            'Curso + Turno' => ['curso_id' => 1, 'turno_id' => 1],
            'Curso + Nível' => ['curso_id' => 1, 'nivel_academico_id' => 1],
            'Curso + Nível + Turno' => ['curso_id' => 1, 'nivel_academico_id' => 1, 'turno_id' => 1],
            'Turma específica' => ['turma_id' => 1],
        ];
    }

    public function test_a_ordem_dos_nove_niveis_e_estritamente_crescente(): void
    {
        $alvos = array_values($this->niveis());

        for ($i = 0; $i < count($alvos) - 1; $i++) {
            $this->assertSame(-1, Precedencia::rank($alvos[$i]) <=> Precedencia::rank($alvos[$i + 1]), "nível {$i} deve perder para o seguinte");
        }
    }

    public function test_curso_vence_nivel_e_nivel_mais_turno_vence_so_curso(): void
    {
        $this->assertSame(1, Precedencia::rank(['curso_id' => 1]) <=> Precedencia::rank(['nivel_academico_id' => 1]));
        $this->assertSame(1, Precedencia::rank(['nivel_academico_id' => 1, 'turno_id' => 1]) <=> Precedencia::rank(['curso_id' => 1]));
    }

    public function test_so_conta_se_o_valor_esta_definido(): void
    {
        $this->assertSame(
            Precedencia::rank([]),
            Precedencia::rank(['nivel_academico_id' => null, 'curso_id' => null, 'turno_id' => null, 'turma_id' => null]),
        );
        $this->assertSame(Precedencia::rank(['curso_id' => 7]), Precedencia::rank(['curso_id' => 99, 'turno_id' => null]));
    }

    public function test_descricoes(): void
    {
        foreach ($this->niveis() as $descricao => $alvo) {
            $this->assertSame($descricao, Precedencia::descricao($alvo));
        }
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Unit/PeriodicidadeTest.php Modules/Financeiro/tests/Unit/CalendarioDePlanoTest.php Modules/Financeiro/tests/Unit/PrecedenciaTest.php`
Expected: FAIL (classes inexistentes).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Enums/Periodicidade.php`:
```php
<?php

namespace Modules\Financeiro\Enums;

enum Periodicidade: int
{
    case OUTRA = 0;
    case MENSAL = 1;
    case BIMESTRAL = 2;
    case TRIMESTRAL = 3;
    case SEMESTRAL = 6;
    case ANUAL = 12;

    public function label(): string
    {
        return match ($this) {
            self::OUTRA => 'Outra periodicidade',
            self::MENSAL => 'Mensal',
            self::BIMESTRAL => 'Bimestral',
            self::TRIMESTRAL => 'Trimestral',
            self::SEMESTRAL => 'Semestral',
            self::ANUAL => 'Anual',
        };
    }

    /**
     * Meses de cada período de cobrança; null em OUTRA (o plano indica o intervalo).
     */
    public function meses(): ?int
    {
        return $this === self::OUTRA ? null : $this->value;
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public static function opcoes(): array
    {
        $ordem = [self::MENSAL, self::BIMESTRAL, self::TRIMESTRAL, self::SEMESTRAL, self::ANUAL, self::OUTRA];

        return array_map(fn (self $p) => ['value' => $p->value, 'label' => $p->label()], $ordem);
    }
}
```

`Modules/Financeiro/app/Support/CalendarioDePlano.php`:
```php
<?php

namespace Modules\Financeiro\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Calendário de cobrança de um plano de propina: funções puras, sem base de dados.
 * O período (mes_inicio -> mes_fim) pode atravessar o ano civil (Set -> Jun).
 */
final class CalendarioDePlano
{
    public static function meses(int $mesInicio, int $mesFim): int
    {
        self::validarMes($mesInicio);
        self::validarMes($mesFim);

        return (($mesFim - $mesInicio + 12) % 12) + 1;
    }

    /**
     * Competências (ano, mês) do período. A primeira é a primeira ocorrência de $mesInicio
     * igual ou posterior ao mês de início do ano lectivo.
     *
     * @return list<array{ano: int, mes: int}>
     */
    public static function competencias(int $mesInicio, int $mesFim, CarbonInterface $inicioAnoLectivo): array
    {
        $total = self::meses($mesInicio, $mesFim);
        $anoDaPrimeira = $mesInicio >= $inicioAnoLectivo->month ? $inicioAnoLectivo->year : $inicioAnoLectivo->year + 1;

        $competencias = [];
        for ($i = 0; $i < $total; $i++) {
            $indice = ($mesInicio - 1) + $i;
            $competencias[] = ['ano' => $anoDaPrimeira + intdiv($indice, 12), 'mes' => ($indice % 12) + 1];
        }

        return $competencias;
    }

    /**
     * Períodos de cobrança: as competências agrupadas de $intervaloMeses em $intervaloMeses.
     * O último período pode ser mais curto.
     *
     * @return list<array{ordem: int, meses: int, inicio: string, fim: string}>
     */
    public static function periodos(int $mesInicio, int $mesFim, int $intervaloMeses, CarbonInterface $inicioAnoLectivo): array
    {
        if ($intervaloMeses < 1 || $intervaloMeses > 12) {
            throw new InvalidArgumentException('O intervalo de cobrança tem de estar entre 1 e 12 meses.');
        }

        $periodos = [];
        foreach (array_chunk(self::competencias($mesInicio, $mesFim, $inicioAnoLectivo), $intervaloMeses) as $indice => $grupo) {
            $primeiro = $grupo[0];
            $ultimo = $grupo[array_key_last($grupo)];

            $periodos[] = [
                'ordem' => $indice + 1,
                'meses' => count($grupo),
                'inicio' => CarbonImmutable::createFromDate($primeiro['ano'], $primeiro['mes'], 1)->startOfDay()->toDateString(),
                'fim' => CarbonImmutable::createFromDate($ultimo['ano'], $ultimo['mes'], 1)->endOfMonth()->toDateString(),
            ];
        }

        return $periodos;
    }

    /**
     * Duas listas de competências têm algum mês (ano + mês) em comum?
     *
     * @param  list<array{ano: int, mes: int}>  $a
     * @param  list<array{ano: int, mes: int}>  $b
     */
    public static function sobrepoem(array $a, array $b): bool
    {
        $ordinaisDeA = array_map(fn (array $c) => $c['ano'] * 12 + $c['mes'], $a);

        foreach ($b as $competencia) {
            if (in_array($competencia['ano'] * 12 + $competencia['mes'], $ordinaisDeA, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{ano: int, mes: int}>  $competencias
     */
    public static function contem(array $competencias, int $ano, int $mes): bool
    {
        return self::sobrepoem($competencias, [['ano' => $ano, 'mes' => $mes]]);
    }

    private static function validarMes(int $mes): void
    {
        if ($mes < 1 || $mes > 12) {
            throw new InvalidArgumentException('O mês tem de estar entre 1 e 12.');
        }
    }
}
```

`Modules/Financeiro/app/Support/Precedencia.php`:
```php
<?php

namespace Modules\Financeiro\Support;

/**
 * Precedência entre alvos de planos de propina (ordem total; ganha o maior rank):
 * [turma específica ? 1 : 0, nº de dimensões definidas (curso, nível, turno),
 *  máscara de desempate curso=4 + nível=2 + turno=1]. Compara-se com `<=>`.
 *
 * Um alvo é um array com as chaves opcionais nivel_academico_id, curso_id, turno_id e
 * turma_id; nulo ou ausente = não definido. Um alvo que casa tem todas as suas dimensões
 * definidas casadas, por isso o rank depende só de quais estão definidas.
 */
final class Precedencia
{
    /**
     * @param  array<string, mixed>  $alvo
     * @return array{0: int, 1: int, 2: int}
     */
    public static function rank(array $alvo): array
    {
        if (self::definido($alvo, 'turma_id')) {
            return [1, 0, 0];
        }

        $curso = self::definido($alvo, 'curso_id');
        $nivel = self::definido($alvo, 'nivel_academico_id');
        $turno = self::definido($alvo, 'turno_id');

        return [
            0,
            (int) $curso + (int) $nivel + (int) $turno,
            ($curso ? 4 : 0) + ($nivel ? 2 : 0) + ($turno ? 1 : 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $alvo
     */
    public static function descricao(array $alvo): string
    {
        if (self::definido($alvo, 'turma_id')) {
            return 'Turma específica';
        }

        $partes = array_filter([
            self::definido($alvo, 'curso_id') ? 'Curso' : null,
            self::definido($alvo, 'nivel_academico_id') ? 'Nível' : null,
            self::definido($alvo, 'turno_id') ? 'Turno' : null,
        ]);

        return $partes === [] ? 'Geral' : implode(' + ', $partes);
    }

    /**
     * @param  array<string, mixed>  $alvo
     */
    private static function definido(array $alvo, string $campo): bool
    {
        return ($alvo[$campo] ?? null) !== null && $alvo[$campo] !== '';
    }
}
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Unit`
Expected: PASS.

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Enums/Periodicidade.php Modules/Financeiro/app/Support/CalendarioDePlano.php Modules/Financeiro/app/Support/Precedencia.php Modules/Financeiro/tests/Unit
```

---

### Task 4: Modelos `PlanoPropina` e `PlanoPropinaAlvo`

**Files:**
- Create: `Modules/Financeiro/database/migrations/2026_10_10_100000_create_planos_propina_table.php`, `Modules/Financeiro/database/migrations/2026_10_10_100100_create_plano_propina_alvos_table.php`
- Create: `Modules/Financeiro/app/Models/PlanoPropina.php`, `Modules/Financeiro/app/Models/PlanoPropinaAlvo.php`
- Create: `Modules/Financeiro/tests/Concerns/ComDadosAcademicosFinanceiro.php`
- Test: `Modules/Financeiro/tests/Feature/PlanoPropinaModelTest.php`

**Interfaces:**
- Consumes: `Periodicidade`, `CalendarioDePlano` (T3), `DinheiroCast`, `AnoLectivo`, `NivelAcademico`, `Curso`, `Turno`, `Turma`.
- Produces:
  - `PlanoPropina` com `ano_lectivo_id, nome, descricao, periodicidade (enum), periodicidade_descricao, intervalo_meses, valor (Dinheiro), mes_inicio, mes_fim, estado, estado_descricao`; relações `anoLectivo()`, `alvos()`; `totalMeses(): int`, `competencias(): array`, `periodos(): array` (vazios se o ano lectivo não existir), `substituirAlvos(array $alvos): void` (cada alvo com as chaves opcionais `nivel_academico_id`, `curso_id`, `turno_id`, `turma_id`), `scopeActivos()`;
  - `PlanoPropinaAlvo` com `plano_propina_id, nivel_academico_id, curso_id, turno_id, turma_id` (inteiros ou nulos) e relações `plano()`, `nivelAcademico()`, `curso()`, `turno()`, `turma()`;
  - trait de teste `ComDadosAcademicosFinanceiro` com `anoLectivo(string $nome = '2026/2027', string $inicio = '2026-09-01', string $fim = '2027-07-31'): AnoLectivo`, `nivel(string $codigo): NivelAcademico`, `curso(string $codigo): Curso`, `turno(string $nome): Turno`, `turma(AnoLectivo $ano, NivelAcademico $nivel, ?Curso $curso = null, ?Turno $turno = null): Turma`, `plano(AnoLectivo $ano, string $nome, array $atributos = [], array $alvos = []): PlanoPropina` onde cada alvo é um array com as chaves opcionais `'nivel'`, `'curso'`, `'turno'`, `'turma'` (os models).

- [ ] **Step 1: Escrever o trait e os testes que falham**

`Modules/Financeiro/tests/Concerns/ComDadosAcademicosFinanceiro.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Concerns;

use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Enums\EtapaEnsinoEnum;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Financeiro\Enums\Periodicidade;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\PlanoPropinaAlvo;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Turma\Models\Turno;

trait ComDadosAcademicosFinanceiro
{
    protected function anoLectivo(string $nome = '2026/2027', string $inicio = '2026-09-01', string $fim = '2027-07-31'): AnoLectivo
    {
        return AnoLectivo::create([
            'estabelecimento_id' => Estabelecimento::current()->id,
            'nome' => $nome,
            'data_inicio' => $inicio,
            'data_fim' => $fim,
            'estado' => EstadoAnoLectivo::ATIVO,
        ]);
    }

    protected function nivel(string $codigo): NivelAcademico
    {
        return NivelAcademico::create([
            'estabelecimento_id' => Estabelecimento::current()->id,
            'codigo' => $codigo,
            'nome' => "Nível {$codigo}",
            'etapa_ensino' => EtapaEnsinoEnum::SECUNDARIO,
            'ordem' => 1,
        ]);
    }

    protected function curso(string $codigo): Curso
    {
        return Curso::create([
            'estabelecimento_id' => Estabelecimento::current()->id,
            'codigo' => $codigo,
            'nome' => "Curso {$codigo}",
        ]);
    }

    protected function turno(string $nome): Turno
    {
        return Turno::create([
            'estabelecimento_id' => Estabelecimento::current()->id,
            'nome' => $nome,
        ]);
    }

    protected function turma(AnoLectivo $ano, NivelAcademico $nivel, ?Curso $curso = null, ?Turno $turno = null): Turma
    {
        $sufixo = uniqid();

        return Turma::create([
            'ano_lectivo_id' => $ano->id,
            'nivel_academico_id' => $nivel->id,
            'curso_id' => $curso?->id,
            'turno_id' => $turno?->id,
            'codigo' => "TU{$sufixo}",
            'nome' => "Turma {$sufixo}",
        ]);
    }

    /**
     * @param  array<string, mixed>  $atributos
     * @param  list<array{nivel?: ?NivelAcademico, curso?: ?Curso, turno?: ?Turno, turma?: ?Turma}>  $alvos
     */
    protected function plano(AnoLectivo $ano, string $nome, array $atributos = [], array $alvos = []): PlanoPropina
    {
        $plano = PlanoPropina::create(array_merge([
            'ano_lectivo_id' => $ano->id,
            'nome' => $nome,
            'periodicidade' => Periodicidade::MENSAL,
            'intervalo_meses' => 1,
            'valor' => Dinheiro::deUnidadesMenores(2_500_000),
            'mes_inicio' => 9,
            'mes_fim' => 6,
        ], $atributos));

        foreach ($alvos as $alvo) {
            PlanoPropinaAlvo::create([
                'plano_propina_id' => $plano->id,
                'nivel_academico_id' => ($alvo['nivel'] ?? null)?->id,
                'curso_id' => ($alvo['curso'] ?? null)?->id,
                'turno_id' => ($alvo['turno'] ?? null)?->id,
                'turma_id' => ($alvo['turma'] ?? null)?->id,
            ]);
        }

        return $plano;
    }
}
```

`Modules/Financeiro/tests/Feature/PlanoPropinaModelTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Enums\Estado;
use Modules\Core\Tenancy\Exceptions\TenantNaoResolvido;
use Modules\Core\Tenancy\TenantContext;
use Modules\Financeiro\Enums\Periodicidade;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\PlanoPropinaAlvo;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Tests\TestCase;

class PlanoPropinaModelTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use RefreshDatabase;

    public function test_cria_plano_com_valor_em_unidades_menores_e_descricoes_sincronizadas(): void
    {
        $plano = $this->plano($this->anoLectivo(), 'Propina Mensal', [
            'periodicidade' => Periodicidade::TRIMESTRAL, 'intervalo_meses' => 3,
        ]);

        $plano->refresh();
        $this->assertSame($this->tenant->id, $plano->tenant_id);
        $this->assertSame(2_500_000, $plano->valor->unidadesMenores());
        $this->assertSame(2_500_000, (int) DB::table('planos_propina')->where('id', $plano->id)->value('valor'));
        $this->assertSame(Periodicidade::TRIMESTRAL, $plano->periodicidade);
        $this->assertSame('Trimestral', $plano->periodicidade_descricao);
        $this->assertSame('Ativo', $plano->estado_descricao);
        $this->assertSame(2_500_000, $plano->toArray()['valor']);
    }

    public function test_competencias_e_periodos_usam_o_inicio_do_ano_lectivo(): void
    {
        $plano = $this->plano($this->anoLectivo('2026/2027', '2026-09-01'), 'P', [
            'periodicidade' => Periodicidade::TRIMESTRAL, 'intervalo_meses' => 3,
        ]);

        $this->assertSame(10, $plano->totalMeses());
        $this->assertCount(10, $plano->competencias());
        $this->assertSame(['ano' => 2026, 'mes' => 9], $plano->competencias()[0]);
        $this->assertSame([3, 3, 3, 1], array_column($plano->periodos(), 'meses'));
    }

    public function test_jan_a_dez_comeca_no_ano_seguinte_ao_inicio_do_ano_lectivo(): void
    {
        $plano = $this->plano($this->anoLectivo(), 'P', ['mes_inicio' => 1, 'mes_fim' => 12]);

        $this->assertSame(['ano' => 2027, 'mes' => 1], $plano->competencias()[0]);
    }

    public function test_nome_unico_por_ano_lectivo_no_tenant(): void
    {
        $ano = $this->anoLectivo('2026/2027');
        $outroAno = $this->anoLectivo('2027/2028', '2027-09-01', '2028-07-31');
        $this->plano($ano, 'Propina');

        $this->plano($outroAno, 'Propina'); // outro ano: permitido
        $this->assertSame(2, PlanoPropina::count());

        $this->expectException(QueryException::class);
        $this->plano($ano, 'Propina');
    }

    public function test_mesmo_nome_e_ano_em_tenants_diferentes_e_permitido(): void
    {
        $this->plano($this->anoLectivo(), 'Propina');
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        $this->noTenant($outro, fn () => $this->plano($this->anoLectivo(), 'Propina'));

        $this->assertSame(1, PlanoPropina::count());
        $this->assertSame(1, $this->noTenant($outro, fn () => PlanoPropina::count()));
    }

    public function test_substituir_alvos_troca_os_alvos_e_so_activos_entram_no_scope(): void
    {
        $ano = $this->anoLectivo();
        $nivel = $this->nivel('N1');
        $curso = $this->curso('C1');
        $turno = $this->turno('Noite');
        $plano = $this->plano($ano, 'A', [], [['nivel' => $nivel]]);

        $plano->substituirAlvos([
            ['nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id],
            ['curso_id' => $curso->id, 'turno_id' => $turno->id],
        ]);

        $this->assertSame(2, $plano->alvos()->count());
        $alvo = $plano->alvos()->orderBy('id')->first();
        $this->assertSame($this->tenant->id, $alvo->tenant_id);
        $this->assertSame($nivel->id, $alvo->nivel_academico_id);
        $this->assertNull($alvo->turno_id);
        $this->assertNull($alvo->turma_id);

        $this->plano($ano, 'B', ['estado' => Estado::INATIVO->value]);
        $this->assertSame(['A'], PlanoPropina::activos()->pluck('nome')->all());
        $this->assertSame(2, PlanoPropina::count());
    }

    public function test_alvo_guarda_turno_e_turma(): void
    {
        $ano = $this->anoLectivo();
        $turma = $this->turma($ano, $this->nivel('N1'));
        $plano = $this->plano($ano, 'A', [], [['turma' => $turma]]);

        $alvo = $plano->alvos()->first();
        $this->assertSame($turma->id, $alvo->turma_id);
        $this->assertSame($turma->id, $alvo->turma->id);
    }

    public function test_eliminar_o_plano_apaga_os_seus_alvos(): void
    {
        $plano = $this->plano($this->anoLectivo(), 'A', [], [['nivel' => $this->nivel('N1')]]);
        $this->assertSame(1, PlanoPropinaAlvo::count());

        $plano->delete();

        $this->assertSame(0, PlanoPropinaAlvo::count());
    }

    public function test_sem_contexto_de_tenant_a_escrita_lanca_tenant_nao_resolvido(): void
    {
        app(TenantContext::class)->limpar();

        $this->expectException(TenantNaoResolvido::class);

        (new PlanoPropina())->save();
    }

    public function test_sem_contexto_de_tenant_a_escrita_de_um_alvo_lanca_tenant_nao_resolvido(): void
    {
        app(TenantContext::class)->limpar();

        $this->expectException(TenantNaoResolvido::class);

        (new PlanoPropinaAlvo())->save();
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/PlanoPropinaModelTest.php`
Expected: FAIL (tabelas e models inexistentes).

- [ ] **Step 3: Implementar**

`2026_10_10_100000_create_planos_propina_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planos_propina', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('ano_lectivo_id')->constrained('ano_lectivos')->restrictOnDelete();
            $table->string('nome', 100);
            $table->text('descricao')->nullable();
            $table->unsignedTinyInteger('periodicidade');
            $table->string('periodicidade_descricao');
            $table->unsignedTinyInteger('intervalo_meses');
            $table->unsignedBigInteger('valor');
            $table->unsignedTinyInteger('mes_inicio');
            $table->unsignedTinyInteger('mes_fim');
            $table->unsignedTinyInteger('estado')->default(1);
            $table->string('estado_descricao')->default('Ativo');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'ano_lectivo_id', 'nome']);
            $table->index(['tenant_id', 'ano_lectivo_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planos_propina');
    }
};
```

`2026_10_10_100100_create_plano_propina_alvos_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plano_propina_alvos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('plano_propina_id')->constrained('planos_propina')->cascadeOnDelete();
            $table->foreignId('nivel_academico_id')->nullable()->constrained('niveis_academicos')->restrictOnDelete();
            $table->foreignId('curso_id')->nullable()->constrained('cursos')->restrictOnDelete();
            $table->foreignId('turno_id')->nullable()->constrained('turnos')->restrictOnDelete();
            $table->foreignId('turma_id')->nullable()->constrained('turmas')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['plano_propina_id', 'nivel_academico_id', 'curso_id', 'turno_id', 'turma_id'], 'plano_propina_alvos_unico');
            $table->index(['tenant_id', 'plano_propina_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plano_propina_alvos');
    }
};
```
(A unicidade com colunas nuláveis não impede dois alvos iguais com nulos: a validação do Request trata disso.)

`Modules/Financeiro/app/Models/PlanoPropina.php`:
```php
<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Enums\Estado;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Core\Traits\SincronizaEstadoDescricao;
use Modules\Financeiro\Casts\DinheiroCast;
use Modules\Financeiro\Enums\Periodicidade;
use Modules\Financeiro\Support\CalendarioDePlano;

/**
 * Configuração de propina de um ano lectivo (NÃO é uma cobrança): periodicidade, valor por
 * período de cobrança e período de aplicação. Alterá-lo só afecta gerações futuras.
 */
class PlanoPropina extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;
    use SincronizaEstadoDescricao;

    protected $table = 'planos_propina';

    protected $fillable = [
        'ano_lectivo_id',
        'nome',
        'descricao',
        'periodicidade',
        'intervalo_meses',
        'valor',
        'mes_inicio',
        'mes_fim',
        'estado',
    ];

    protected $hidden = ['tenant_id', 'criado_por', 'editado_por'];

    protected $attributes = [
        'estado' => 1,
    ];

    protected $casts = [
        'periodicidade' => Periodicidade::class,
        'intervalo_meses' => 'integer',
        'valor' => DinheiroCast::class,
        'mes_inicio' => 'integer',
        'mes_fim' => 'integer',
        'estado' => 'integer',
    ];

    protected static function booted(): void
    {
        // Null-safe: a matriz de isolamento cria models em cru e espera TenantNaoResolvido.
        static::saving(function (self $plano) {
            $plano->periodicidade_descricao = $plano->periodicidade?->label();
        });
    }

    public function anoLectivo(): BelongsTo
    {
        return $this->belongsTo(AnoLectivo::class, 'ano_lectivo_id');
    }

    public function alvos(): HasMany
    {
        return $this->hasMany(PlanoPropinaAlvo::class, 'plano_propina_id');
    }

    public function scopeActivos(Builder $query): Builder
    {
        return $query->where('estado', Estado::ATIVO->value);
    }

    public function totalMeses(): int
    {
        return CalendarioDePlano::meses($this->mes_inicio, $this->mes_fim);
    }

    /**
     * @return list<array{ano: int, mes: int}>
     */
    public function competencias(): array
    {
        if ($this->anoLectivo === null) {
            return [];
        }

        return CalendarioDePlano::competencias($this->mes_inicio, $this->mes_fim, $this->anoLectivo->data_inicio);
    }

    /**
     * @return list<array{ordem: int, meses: int, inicio: string, fim: string}>
     */
    public function periodos(): array
    {
        if ($this->anoLectivo === null) {
            return [];
        }

        return CalendarioDePlano::periodos($this->mes_inicio, $this->mes_fim, $this->intervalo_meses, $this->anoLectivo->data_inicio);
    }

    /**
     * Troca os alvos do plano. Cada alvo tem as chaves opcionais nivel_academico_id, curso_id,
     * turno_id e turma_id. Sem alvos, o plano aplica-se a todas as turmas do ano lectivo.
     *
     * @param  list<array<string, ?int>>  $alvos
     */
    public function substituirAlvos(array $alvos): void
    {
        $this->alvos()->delete();

        foreach ($alvos as $alvo) {
            $this->alvos()->create([
                'nivel_academico_id' => $alvo['nivel_academico_id'] ?? null,
                'curso_id' => $alvo['curso_id'] ?? null,
                'turno_id' => $alvo['turno_id'] ?? null,
                'turma_id' => $alvo['turma_id'] ?? null,
            ]);
        }
    }
}
```

`Modules/Financeiro/app/Models/PlanoPropinaAlvo.php`:
```php
<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Curso\Models\Curso;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Turma\Models\Turno;

/**
 * A que turmas um plano se aplica: nível, curso, turno (qualquer combinação) ou uma turma
 * específica (exclusiva); sem linhas, todas as do ano lectivo.
 */
class PlanoPropinaAlvo extends Model
{
    use PertenceAoTenant;

    protected $table = 'plano_propina_alvos';

    protected $fillable = [
        'plano_propina_id',
        'nivel_academico_id',
        'curso_id',
        'turno_id',
        'turma_id',
    ];

    protected $hidden = ['tenant_id'];

    protected $casts = [
        'plano_propina_id' => 'integer',
        'nivel_academico_id' => 'integer',
        'curso_id' => 'integer',
        'turno_id' => 'integer',
        'turma_id' => 'integer',
    ];

    public function plano(): BelongsTo
    {
        return $this->belongsTo(PlanoPropina::class, 'plano_propina_id');
    }

    public function nivelAcademico(): BelongsTo
    {
        return $this->belongsTo(NivelAcademico::class, 'nivel_academico_id');
    }

    public function curso(): BelongsTo
    {
        return $this->belongsTo(Curso::class, 'curso_id');
    }

    public function turno(): BelongsTo
    {
        return $this->belongsTo(Turno::class, 'turno_id');
    }

    public function turma(): BelongsTo
    {
        return $this->belongsTo(Turma::class, 'turma_id');
    }
}
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro tests/Feature/Tenancy`
Expected: PASS (inclui a matriz de isolamento, que passa a exercitar os dois models novos criados "em cru").

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro
```

---

### Task 5: Planos de Propina (backend)

**Files:**
- Create: `Modules/Financeiro/app/Support/AlvosDoPlano.php`, `Modules/Financeiro/app/Support/PrecosDosPlanos.php`
- Modify: `Modules/Financeiro/app/Providers/FinanceiroServiceProvider.php` (tag da fonte de preços)
- Create: `Modules/Financeiro/app/DTO/PlanoPropinaDTO.php`
- Create: `Modules/Financeiro/app/Http/Requests/Concerns/ValidaPlanoPropina.php`
- Create: `Modules/Financeiro/app/Http/Requests/{CriarPlanoPropinaRequest,AtualizarPlanoPropinaRequest,AlterarEstadoPlanoPropinaRequest}.php`
- Create: `Modules/Financeiro/app/Actions/{CriarPlanoPropinaAction,AtualizarPlanoPropinaAction,AlterarEstadoPlanoPropinaAction,EliminarPlanoPropinaAction}.php`
- Create: `Modules/Financeiro/app/Services/{GestaoPlanoPropinaService,PlanoPropinaConsultaService}.php`
- Create: `Modules/Financeiro/app/Http/Controllers/PlanoPropinaController.php`
- Modify: `Modules/Financeiro/routes/web.php`
- Test: `Modules/Financeiro/tests/Unit/AlvosDoPlanoTest.php`, `Modules/Financeiro/tests/Feature/PlanoPropinaTest.php`

**Interfaces:**
- Consumes: modelos e trait de teste (T4), `ValorMonetario`, `Dinheiro`, `MoedaDoTenant`, `FonteDePrecos`/`FontesDePrecos` (plano Moeda e Câmbio; T1 absorvida), abilities `plano-propina.*` (T2), `CalendarioDePlano`, `Precedencia`, `Periodicidade` (T3), `ReferenciasFinanceiras`, `ComUtilizadoresFinanceiro`.
- Produces:
  - rotas `financeiro.configuracao.planos-propina.*`: `GET /financeiro/configuracao/planos-propina` (`index`), `POST` (`store`), `PUT /{plano}` (`update`), `PATCH /{plano}/estado` (`alterar-estado`), `DELETE /{plano}` (`destroy`);
  - componente Inertia `Financeiro/PlanosPropina/Index` com props `planos` (paginador; linha: `id, nome, descricao, ano_lectivo_id, ano_lectivo_nome, periodicidade, periodicidade_descricao, intervalo_meses, valor (unidades menores, inteiro), mes_inicio, mes_fim, periodos_total, precedencia (string), alvos [{nivel_academico_id, nivel_nome, curso_id, curso_nome, turno_id, turno_nome, turma_id, turma_nome, precedencia}], estado, estado_descricao`), `filtros` (`pesquisa, estado, ano_lectivo_id`), `anosLectivos`, `niveis`, `cursos`, `turnos` (`[{id, nome}]`), `turmas` (`[{id, nome, ano_lectivo_id}]`), `periodicidades` (`Periodicidade::opcoes()`), `moeda` (`MoedaDoTenant::atual()->paraFrontend()` = `{codigo, nome, simbolo, decimais}`, como no `CatalogoFinanceiroController`);
  - payload de escrita: `ano_lectivo_id` (só no POST; ignorado no PUT), `nome`, `descricao`, `periodicidade`, `intervalo_meses` (obrigatório só em Outra), `valor` (decimal na moeda da escola, ex.: `25000` ou `25000,50` em AOA), `mes_inicio`, `mes_fim`, `alvos` (`[{nivel_academico_id, curso_id, turno_id, turma_id}]`);
  - `AlvosDoPlano`: `paraGravar(array): list<array{nivel_academico_id,curso_id,turno_id,turma_id}>` (pares únicos, sem os vazios), `normalizar(array)` (como `paraGravar`, mas sem alvos devolve um alvo vazio), `chave(array): string`, `temRepetidos(array): bool`, `violaExclusividadeDaTurma(array): bool`, `idsDeTurma(array): list<int>`;
  - `PrecosDosPlanos` (`Support/PrecosDosPlanos.php`, implementa `FonteDePrecos`; `existemPrecos()` = há algum `PlanoPropina` do tenant, activo ou não), registada em `FinanceiroServiceProvider::register()` com `FontesDePrecos::ETIQUETA`: com planos, `MoedaDoTenant::podeAlterar()` é falso;
  - `PlanoPropinaConsultaService::colisaoDeAlvos(int $anoLectivoId, array $alvos, array $competencias, ?int $ignorarPlanoId): bool` e `turmasForaDoAno(int $anoLectivoId, array $turmaIds): bool`.

- [ ] **Step 1: Escrever os testes que falham**

`Modules/Financeiro/tests/Unit/AlvosDoPlanoTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Unit;

use Modules\Financeiro\Support\AlvosDoPlano;
use PHPUnit\Framework\TestCase;

class AlvosDoPlanoTest extends TestCase
{
    private function vazio(): array
    {
        return ['nivel_academico_id' => null, 'curso_id' => null, 'turno_id' => null, 'turma_id' => null];
    }

    public function test_sem_alvos_normaliza_para_o_alvo_vazio(): void
    {
        $this->assertSame([$this->vazio()], AlvosDoPlano::normalizar([]));
        $this->assertSame([], AlvosDoPlano::paraGravar([]));
    }

    public function test_linhas_vazias_sao_ignoradas(): void
    {
        $alvos = [['nivel_academico_id' => '', 'curso_id' => null], []];

        $this->assertSame([$this->vazio()], AlvosDoPlano::normalizar($alvos));
        $this->assertSame([], AlvosDoPlano::paraGravar($alvos));
    }

    public function test_normaliza_ids_para_inteiros_e_remove_alvos_repetidos(): void
    {
        $alvos = [
            ['nivel_academico_id' => '3', 'curso_id' => null],
            ['nivel_academico_id' => 3, 'curso_id' => ''],
            ['curso_id' => '7', 'turno_id' => '2'],
        ];

        $this->assertSame(
            [
                ['nivel_academico_id' => 3, 'curso_id' => null, 'turno_id' => null, 'turma_id' => null],
                ['nivel_academico_id' => null, 'curso_id' => 7, 'turno_id' => 2, 'turma_id' => null],
            ],
            AlvosDoPlano::paraGravar($alvos),
        );
    }

    public function test_chave_distingue_os_quatro_campos(): void
    {
        $this->assertNotSame(
            AlvosDoPlano::chave(['nivel_academico_id' => 1]),
            AlvosDoPlano::chave(['curso_id' => 1]),
        );
        $this->assertNotSame(
            AlvosDoPlano::chave(['turno_id' => 1]),
            AlvosDoPlano::chave(['turma_id' => 1]),
        );
        $this->assertSame(AlvosDoPlano::chave(['curso_id' => 1]), AlvosDoPlano::chave(['curso_id' => '1', 'turno_id' => null]));
    }

    public function test_detecta_repetidos(): void
    {
        $this->assertTrue(AlvosDoPlano::temRepetidos([
            ['nivel_academico_id' => 3, 'curso_id' => null],
            ['nivel_academico_id' => '3', 'curso_id' => null],
        ]));
        $this->assertFalse(AlvosDoPlano::temRepetidos([
            ['nivel_academico_id' => 3, 'curso_id' => null],
            ['nivel_academico_id' => 3, 'curso_id' => 7],
        ]));
        $this->assertFalse(AlvosDoPlano::temRepetidos([$this->vazio()]));
    }

    public function test_turma_e_exclusiva(): void
    {
        $this->assertTrue(AlvosDoPlano::violaExclusividadeDaTurma([['turma_id' => 5, 'curso_id' => 1]]));
        $this->assertTrue(AlvosDoPlano::violaExclusividadeDaTurma([['turma_id' => 5, 'turno_id' => 1]]));
        $this->assertFalse(AlvosDoPlano::violaExclusividadeDaTurma([['turma_id' => 5], ['curso_id' => 1, 'turno_id' => 2]]));
        $this->assertFalse(AlvosDoPlano::violaExclusividadeDaTurma([]));
    }

    public function test_ids_de_turma(): void
    {
        $this->assertSame([5, 8], AlvosDoPlano::idsDeTurma([['turma_id' => '5'], ['turma_id' => 8], ['turma_id' => 5], ['curso_id' => 1]]));
        $this->assertSame([], AlvosDoPlano::idsDeTurma([]));
    }
}
```

`Modules/Financeiro/tests/Feature/PlanoPropinaTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Core\Enums\Estado;
use Modules\Financeiro\Contracts\ReferenciaFinanceira;
use Modules\Financeiro\Enums\Periodicidade;
use Modules\Financeiro\Models\ConfiguracaoMonetaria;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\PlanoPropinaAlvo;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Support\FontesDePrecos;
use Modules\Financeiro\Support\PrecosDosPlanos;
use Modules\Financeiro\Support\ReferenciasFinanceiras;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlanoPropinaTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    private const BASE = 'financeiro.configuracao.planos-propina.';

    // A moeda por omissão do tenant de teste é AOA (2 casas decimais); os testes que dependem de
    // outra moeda mudam-na explicitamente (ver test_valor_respeita_as_casas_da_moeda_da_escola).

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function payload(int $anoId, array $sobrepor = []): array
    {
        return array_merge([
            'ano_lectivo_id' => $anoId,
            'nome' => 'Propina Mensal',
            'descricao' => 'Plano regular',
            'periodicidade' => Periodicidade::MENSAL->value,
            'valor' => '25000',
            'mes_inicio' => 9,
            'mes_fim' => 6,
            'alvos' => [],
        ], $sobrepor);
    }

    public function test_index_lista_so_os_planos_do_tenant_com_a_forma_esperada(): void
    {
        $ano = $this->anoLectivo();
        $nivel = $this->nivel('N1');
        $curso = $this->curso('C1');
        $turno = $this->turno('Noite');
        $this->plano($ano, 'Propina Mensal', [], [['nivel' => $nivel, 'curso' => $curso, 'turno' => $turno]]);
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, fn () => $this->plano($this->anoLectivo(), 'Do Outro'));

        $this->actingAs($this->adminEscola())
            ->get(route(self::BASE . 'index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Financeiro/PlanosPropina/Index')
                ->has('planos.data', 1)
                ->where('planos.data.0.nome', 'Propina Mensal')
                ->where('planos.data.0.ano_lectivo_nome', '2026/2027')
                ->where('planos.data.0.valor', 2_500_000)
                ->where('planos.data.0.periodos_total', 10)
                ->where('planos.data.0.periodicidade_descricao', 'Mensal')
                ->where('planos.data.0.precedencia', 'Curso + Nível + Turno')
                ->where('planos.data.0.alvos.0.nivel_nome', 'Nível N1')
                ->where('planos.data.0.alvos.0.curso_nome', 'Curso C1')
                ->where('planos.data.0.alvos.0.turno_nome', 'Noite')
                ->missing('planos.data.0.tenant_id')
                ->has('anosLectivos', 1)
                ->has('niveis', 1)
                ->has('cursos', 1)
                ->has('turnos', 1)
                ->has('turmas', 0)
                ->has('periodicidades', 6)
                ->where('moeda.codigo', 'AOA')
                ->where('moeda.decimais', 2));
    }

    public function test_index_mostra_geral_quando_nao_ha_alvos(): void
    {
        $this->plano($this->anoLectivo(), 'Geral');

        $this->actingAs($this->adminEscola())->get(route(self::BASE . 'index'))
            ->assertInertia(fn (Assert $page) => $page->where('planos.data.0.precedencia', 'Geral')->has('planos.data.0.alvos', 0));
    }

    public function test_index_filtra_por_ano_estado_e_pesquisa_e_ignora_filtros_invalidos(): void
    {
        $a = $this->anoLectivo('2026/2027');
        $b = $this->anoLectivo('2027/2028', '2027-09-01', '2028-07-31');
        $this->plano($a, 'Mensal A');
        $this->plano($b, 'Anual B', ['estado' => Estado::INATIVO->value]);
        $this->actingAs($this->adminEscola());

        $this->get(route(self::BASE . 'index', ['ano_lectivo_id' => $b->id]))
            ->assertInertia(fn (Assert $p) => $p->has('planos.data', 1)->where('planos.data.0.nome', 'Anual B'));
        $this->get(route(self::BASE . 'index', ['estado' => '0']))
            ->assertInertia(fn (Assert $p) => $p->has('planos.data', 1)->where('planos.data.0.estado_descricao', 'Inativo'));
        $this->get(route(self::BASE . 'index', ['pesquisa' => 'mensal']))
            ->assertInertia(fn (Assert $p) => $p->has('planos.data', 1)->where('planos.data.0.nome', 'Mensal A'));
        $this->get(route(self::BASE . 'index', ['estado' => 'abc', 'ano_lectivo_id' => 'x']))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('planos.data', 2));
        $this->get(route(self::BASE . 'index') . '?pesquisa[]=x')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->has('planos.data', 2));
    }

    public function test_cria_plano_mensal_set_a_jun_com_valor_em_unidades_menores_e_autoria(): void
    {
        $ano = $this->anoLectivo();
        $admin = $this->adminEscola();

        $this->actingAs($admin)->post(route(self::BASE . 'store'), $this->payload($ano->id))
            ->assertSessionHasNoErrors()->assertRedirect();

        $plano = PlanoPropina::firstWhere('nome', 'Propina Mensal');
        $this->assertNotNull($plano);
        $this->assertSame(2_500_000, $plano->valor->unidadesMenores()); // 25000 em AOA (2 casas)
        $this->assertSame(1, $plano->intervalo_meses);
        $this->assertSame(9, $plano->mes_inicio);
        $this->assertSame(6, $plano->mes_fim); // inicio > fim: atravessa o ano civil
        $this->assertSame('Mensal', $plano->periodicidade_descricao);
        $this->assertSame('Ativo', $plano->estado_descricao);
        $this->assertSame($this->tenant->id, $plano->tenant_id);
        $this->assertSame($admin->id, $plano->criado_por);
        $this->assertSame(0, $plano->alvos()->count());
    }

    public function test_trimestral_em_dez_meses_e_aceite_e_da_quatro_periodos(): void
    {
        $ano = $this->anoLectivo();

        $this->actingAs($this->adminEscola())
            ->post(route(self::BASE . 'store'), $this->payload($ano->id, ['periodicidade' => Periodicidade::TRIMESTRAL->value]))
            ->assertSessionHasNoErrors();

        $this->assertSame(3, PlanoPropina::firstWhere('nome', 'Propina Mensal')->intervalo_meses);
        $this->assertCount(4, PlanoPropina::firstWhere('nome', 'Propina Mensal')->periodos());
    }

    public function test_outra_periodicidade_exige_intervalo_e_usa_o_indicado(): void
    {
        $ano = $this->anoLectivo();
        $this->actingAs($this->adminEscola())->from('/x');

        $this->post(route(self::BASE . 'store'), $this->payload($ano->id, ['periodicidade' => Periodicidade::OUTRA->value]))
            ->assertSessionHasErrors('intervalo_meses');

        $this->post(route(self::BASE . 'store'), $this->payload($ano->id, ['periodicidade' => Periodicidade::OUTRA->value, 'intervalo_meses' => 5]))
            ->assertSessionHasNoErrors();

        $this->assertSame(5, PlanoPropina::firstWhere('nome', 'Propina Mensal')->intervalo_meses);
    }

    public function test_periodicidade_maior_que_o_periodo_e_rejeitada(): void
    {
        $ano = $this->anoLectivo();

        $this->actingAs($this->adminEscola())->from('/x')
            ->post(route(self::BASE . 'store'), $this->payload($ano->id, [
                'periodicidade' => Periodicidade::TRIMESTRAL->value, 'mes_inicio' => 1, 'mes_fim' => 2,
            ]))
            ->assertSessionHasErrors(['periodicidade' => 'A periodicidade excede a duração do período de cobrança.']);

        $this->assertSame(0, PlanoPropina::count());
    }

    #[DataProvider('camposInvalidos')]
    public function test_campos_invalidos_sao_rejeitados(string $campo, mixed $valor): void
    {
        $ano = $this->anoLectivo();

        $this->actingAs($this->adminEscola())->from('/x')
            ->post(route(self::BASE . 'store'), $this->payload($ano->id, [$campo => $valor]))
            ->assertSessionHasErrors($campo);

        $this->assertSame(0, PlanoPropina::count());
    }

    public static function camposInvalidos(): array
    {
        return [
            'mes_inicio 0' => ['mes_inicio', 0],
            'mes_inicio 13' => ['mes_inicio', 13],
            'mes_fim 0' => ['mes_fim', 0],
            'mes_fim 13' => ['mes_fim', 13],
            'valor zero' => ['valor', '0'],
            'valor vazio' => ['valor', ''],
            'valor negativo' => ['valor', '-1'],
            'valor três casas' => ['valor', '12.345'],
            'valor com milhares' => ['valor', '25.000,50'],
            'nome vazio' => ['nome', ''],
            'periodicidade inexistente' => ['periodicidade', 99],
            'ano inexistente' => ['ano_lectivo_id', 999999],
        ];
    }

    public function test_valor_com_virgula_e_gravado_em_unidades_menores_exactas(): void
    {
        $ano = $this->anoLectivo();

        $this->actingAs($this->adminEscola())
            ->post(route(self::BASE . 'store'), $this->payload($ano->id, ['valor' => '25000,50'])) // AOA: 2 casas
            ->assertSessionHasNoErrors();

        $this->assertSame(2_500_050, PlanoPropina::firstWhere('nome', 'Propina Mensal')->valor->unidadesMenores());
    }

    public function test_valor_respeita_as_casas_da_moeda_da_escola(): void
    {
        $ano = $this->anoLectivo();
        ConfiguracaoMonetaria::doTenant()->update(['moeda' => 'JPY']); // 0 casas decimais
        $admin = $this->adminEscola();

        $this->actingAs($admin)->from('/x')
            ->post(route(self::BASE . 'store'), $this->payload($ano->id, ['valor' => '25000,5']))
            ->assertSessionHasErrors('valor');
        $this->assertSame(0, PlanoPropina::count());

        $this->actingAs($admin)
            ->post(route(self::BASE . 'store'), $this->payload($ano->id, ['valor' => '25000']))
            ->assertSessionHasNoErrors();
        $this->assertSame(25000, PlanoPropina::firstWhere('nome', 'Propina Mensal')->valor->unidadesMenores());
    }

    public function test_precos_dos_planos_so_existem_com_planos_e_bloqueiam_a_troca_de_moeda(): void
    {
        $this->assertFalse(app(PrecosDosPlanos::class)->existemPrecos());
        $this->assertFalse(app(FontesDePrecos::class)->existem());
        $this->assertTrue(app(MoedaDoTenant::class)->podeAlterar());

        $this->plano($this->anoLectivo(), 'Propina Mensal', ['estado' => Estado::INATIVO->value]); // inactivo também conta

        $this->assertTrue(app(PrecosDosPlanos::class)->existemPrecos());
        $this->assertTrue(app(FontesDePrecos::class)->existem()); // a fonte está registada com a etiqueta
        $this->assertFalse(app(MoedaDoTenant::class)->podeAlterar());
    }

    public function test_precos_dos_planos_ignoram_planos_de_outro_tenant(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, fn () => $this->plano($this->anoLectivo(), 'Do Outro'));

        $this->assertFalse(app(PrecosDosPlanos::class)->existemPrecos());
    }

    public function test_nome_unico_por_ano_mas_repetivel_noutro_ano_e_noutro_tenant(): void
    {
        $a = $this->anoLectivo('2026/2027');
        $b = $this->anoLectivo('2027/2028', '2027-09-01', '2028-07-31');
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, fn () => $this->plano($this->anoLectivo(), 'Propina Mensal'));
        $this->actingAs($this->adminEscola());

        $this->post(route(self::BASE . 'store'), $this->payload($a->id))->assertSessionHasNoErrors();
        $this->post(route(self::BASE . 'store'), $this->payload($b->id))->assertSessionHasNoErrors();
        $this->from('/x')->post(route(self::BASE . 'store'), $this->payload($a->id))->assertSessionHasErrors('nome');

        $this->assertSame(2, PlanoPropina::count());
    }

    public function test_ano_lectivo_de_outro_tenant_e_rejeitado(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $anoDoOutro = $this->noTenant($outro, fn () => $this->anoLectivo());

        $this->actingAs($this->adminEscola())->from('/x')
            ->post(route(self::BASE . 'store'), $this->payload($anoDoOutro->id))
            ->assertSessionHasErrors('ano_lectivo_id');

        $this->assertSame(0, PlanoPropina::count());
    }

    public function test_alvos_de_outro_tenant_sao_rejeitados(): void
    {
        $ano = $this->anoLectivo();
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        [$nivelDoOutro, $cursoDoOutro, $turnoDoOutro, $turmaDoOutro] = $this->noTenant($outro, function () {
            $ano = $this->anoLectivo();
            $nivel = $this->nivel('NX');

            return [$nivel, $this->curso('CX'), $this->turno('TX'), $this->turma($ano, $nivel)];
        });
        $this->actingAs($this->adminEscola())->from('/x');

        $this->post(route(self::BASE . 'store'), $this->payload($ano->id, ['alvos' => [['nivel_academico_id' => $nivelDoOutro->id]]]))
            ->assertSessionHasErrors('alvos.0.nivel_academico_id');
        $this->post(route(self::BASE . 'store'), $this->payload($ano->id, ['alvos' => [['curso_id' => $cursoDoOutro->id]]]))
            ->assertSessionHasErrors('alvos.0.curso_id');
        $this->post(route(self::BASE . 'store'), $this->payload($ano->id, ['alvos' => [['turno_id' => $turnoDoOutro->id]]]))
            ->assertSessionHasErrors('alvos.0.turno_id');
        $this->post(route(self::BASE . 'store'), $this->payload($ano->id, ['alvos' => [['turma_id' => $turmaDoOutro->id]]]))
            ->assertSessionHasErrors('alvos.0.turma_id');

        $this->assertSame(0, PlanoPropina::count());
    }

    public function test_cria_com_alvos_de_nivel_curso_e_turno_e_ignora_linhas_vazias(): void
    {
        $ano = $this->anoLectivo();
        $nivel = $this->nivel('N1');
        $curso = $this->curso('C1');
        $turno = $this->turno('Noite');

        $this->actingAs($this->adminEscola())
            ->post(route(self::BASE . 'store'), $this->payload($ano->id, ['alvos' => [
                ['nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id, 'turno_id' => $turno->id],
                ['nivel_academico_id' => null, 'curso_id' => null, 'turno_id' => null, 'turma_id' => null],
            ]]))
            ->assertSessionHasNoErrors();

        $plano = PlanoPropina::firstWhere('nome', 'Propina Mensal');
        $this->assertSame(1, $plano->alvos()->count());
        $alvo = $plano->alvos()->first();
        $this->assertSame([$nivel->id, $curso->id, $turno->id, null], [$alvo->nivel_academico_id, $alvo->curso_id, $alvo->turno_id, $alvo->turma_id]);
    }

    public function test_cria_com_turma_especifica_do_mesmo_ano(): void
    {
        $ano = $this->anoLectivo();
        $turma = $this->turma($ano, $this->nivel('N1'));

        $this->actingAs($this->adminEscola())
            ->post(route(self::BASE . 'store'), $this->payload($ano->id, ['alvos' => [['turma_id' => $turma->id]]]))
            ->assertSessionHasNoErrors();

        $this->assertSame($turma->id, PlanoPropina::firstWhere('nome', 'Propina Mensal')->alvos()->first()->turma_id);
    }

    public function test_turma_de_outro_ano_lectivo_e_rejeitada(): void
    {
        $a = $this->anoLectivo('2026/2027');
        $b = $this->anoLectivo('2027/2028', '2027-09-01', '2028-07-31');
        $turmaDoB = $this->turma($b, $this->nivel('N1'));

        $this->actingAs($this->adminEscola())->from('/x')
            ->post(route(self::BASE . 'store'), $this->payload($a->id, ['alvos' => [['turma_id' => $turmaDoB->id]]]))
            ->assertSessionHasErrors(['alvos' => 'A turma escolhida não pertence ao ano lectivo do plano.']);
    }

    public function test_turma_combinada_com_outros_campos_e_rejeitada(): void
    {
        $ano = $this->anoLectivo();
        $nivel = $this->nivel('N1');
        $turma = $this->turma($ano, $nivel);

        $this->actingAs($this->adminEscola())->from('/x')
            ->post(route(self::BASE . 'store'), $this->payload($ano->id, ['alvos' => [['turma_id' => $turma->id, 'nivel_academico_id' => $nivel->id]]]))
            ->assertSessionHasErrors(['alvos' => 'Uma turma específica não se combina com nível, curso ou turno.']);
    }

    public function test_alvos_repetidos_no_payload_sao_rejeitados(): void
    {
        $ano = $this->anoLectivo();
        $nivel = $this->nivel('N1');

        $this->actingAs($this->adminEscola())->from('/x')
            ->post(route(self::BASE . 'store'), $this->payload($ano->id, ['alvos' => [
                ['nivel_academico_id' => $nivel->id], ['nivel_academico_id' => $nivel->id],
            ]]))
            ->assertSessionHasErrors(['alvos' => 'Há alvos repetidos no plano.']);
    }

    public function test_mesmos_alvos_com_periodos_sobrepostos_colidem(): void
    {
        $ano = $this->anoLectivo();
        $nivel = $this->nivel('N1');
        $this->plano($ano, 'Existente', ['mes_inicio' => 9, 'mes_fim' => 12], [['nivel' => $nivel]]);
        $this->actingAs($this->adminEscola())->from('/x');

        $this->post(route(self::BASE . 'store'), $this->payload($ano->id, [
            'nome' => 'Novo', 'mes_inicio' => 12, 'mes_fim' => 6, 'alvos' => [['nivel_academico_id' => $nivel->id]],
        ]))->assertSessionHasErrors(['alvos' => 'Já existe outro plano deste ano lectivo, com período sobreposto, aplicável aos mesmos alvos.']);
    }

    public function test_fases_de_preco_mesmos_alvos_com_periodos_disjuntos_coexistem(): void
    {
        $ano = $this->anoLectivo();
        $nivel = $this->nivel('N1');
        $curso = $this->curso('C1');
        $this->actingAs($this->adminEscola());

        $this->post(route(self::BASE . 'store'), $this->payload($ano->id, [
            'nome' => 'Fase 1', 'mes_inicio' => 9, 'mes_fim' => 12, 'valor' => '30000',
            'alvos' => [['nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id]],
        ]))->assertSessionHasNoErrors();
        $this->post(route(self::BASE . 'store'), $this->payload($ano->id, [
            'nome' => 'Fase 2', 'mes_inicio' => 1, 'mes_fim' => 6, 'valor' => '35000',
            'alvos' => [['nivel_academico_id' => $nivel->id, 'curso_id' => $curso->id]],
        ]))->assertSessionHasNoErrors();

        $this->assertSame(2, PlanoPropina::count());
    }

    public function test_dois_planos_gerais_colidem_se_os_periodos_se_sobrepoem_mas_nao_se_forem_disjuntos(): void
    {
        $ano = $this->anoLectivo();
        $this->plano($ano, 'Geral Fase 1', ['mes_inicio' => 9, 'mes_fim' => 12]);
        $this->actingAs($this->adminEscola());

        $this->from('/x')->post(route(self::BASE . 'store'), $this->payload($ano->id, ['nome' => 'Geral Sobreposto']))
            ->assertSessionHasErrors('alvos');

        $this->post(route(self::BASE . 'store'), $this->payload($ano->id, ['nome' => 'Geral Fase 2', 'mes_inicio' => 1, 'mes_fim' => 6]))
            ->assertSessionHasNoErrors();
    }

    public function test_mesmos_alvos_noutro_ano_lectivo_nao_colidem(): void
    {
        $a = $this->anoLectivo('2026/2027');
        $b = $this->anoLectivo('2027/2028', '2027-09-01', '2028-07-31');
        $nivel = $this->nivel('N1');
        $this->plano($a, 'Existente', [], [['nivel' => $nivel]]);

        $this->actingAs($this->adminEscola())
            ->post(route(self::BASE . 'store'), $this->payload($b->id, ['nome' => 'Novo', 'alvos' => [['nivel_academico_id' => $nivel->id]]]))
            ->assertSessionHasNoErrors();
    }

    public function test_alvos_diferentes_no_mesmo_ano_coexistem(): void
    {
        $ano = $this->anoLectivo();
        $n1 = $this->nivel('N1');
        $n2 = $this->nivel('N2');
        $this->plano($ano, 'Geral');
        $this->plano($ano, 'Nivel 1', [], [['nivel' => $n1]]);

        $this->actingAs($this->adminEscola())
            ->post(route(self::BASE . 'store'), $this->payload($ano->id, ['nome' => 'Nivel 2', 'alvos' => [['nivel_academico_id' => $n2->id]]]))
            ->assertSessionHasNoErrors();

        $this->assertSame(3, PlanoPropina::count());
    }

    public function test_curso_e_nivel_em_alvos_distintos_do_mesmo_ano_coexistem_porque_a_precedencia_desempata(): void
    {
        $ano = $this->anoLectivo();
        $nivel = $this->nivel('N1');
        $curso = $this->curso('C1');
        $this->plano($ano, 'Por Nivel', [], [['nivel' => $nivel]]);

        $this->actingAs($this->adminEscola())
            ->post(route(self::BASE . 'store'), $this->payload($ano->id, ['nome' => 'Por Curso', 'alvos' => [['curso_id' => $curso->id]]]))
            ->assertSessionHasNoErrors();
    }

    public function test_actualiza_sem_colidir_consigo_substitui_alvos_e_ignora_o_ano(): void
    {
        $a = $this->anoLectivo('2026/2027');
        $b = $this->anoLectivo('2027/2028', '2027-09-01', '2028-07-31');
        $nivel = $this->nivel('N1');
        $curso = $this->curso('C1');
        $plano = $this->plano($a, 'Propina', [], [['nivel' => $nivel]]);

        $this->actingAs($this->adminEscola())
            ->put(route(self::BASE . 'update', $plano), $this->payload($b->id, [
                'nome' => 'Propina Renomeada', 'valor' => '30000', 'periodicidade' => Periodicidade::SEMESTRAL->value,
                'alvos' => [['nivel_academico_id' => $nivel->id], ['curso_id' => $curso->id]],
            ]))
            ->assertSessionHasNoErrors();

        $plano->refresh();
        $this->assertSame('Propina Renomeada', $plano->nome);
        $this->assertSame(3_000_000, $plano->valor->unidadesMenores()); // 30000 em AOA
        $this->assertSame(6, $plano->intervalo_meses);
        $this->assertSame('Semestral', $plano->periodicidade_descricao);
        $this->assertSame($a->id, $plano->ano_lectivo_id); // o ano não muda na edição
        $this->assertSame(2, $plano->alvos()->count());
    }

    public function test_actualizar_valida_a_turma_contra_o_ano_do_plano_e_nao_o_do_payload(): void
    {
        $a = $this->anoLectivo('2026/2027');
        $b = $this->anoLectivo('2027/2028', '2027-09-01', '2028-07-31');
        $turmaDoB = $this->turma($b, $this->nivel('N1'));
        $plano = $this->plano($a, 'Propina');

        $this->actingAs($this->adminEscola())->from('/x')
            ->put(route(self::BASE . 'update', $plano), $this->payload($b->id, ['alvos' => [['turma_id' => $turmaDoB->id]]]))
            ->assertSessionHasErrors(['alvos' => 'A turma escolhida não pertence ao ano lectivo do plano.']);
    }

    public function test_actualizar_com_nome_de_outro_plano_do_mesmo_ano_falha(): void
    {
        $ano = $this->anoLectivo();
        $nivel = $this->nivel('N1');
        $this->plano($ano, 'A');
        $b = $this->plano($ano, 'B', [], [['nivel' => $nivel]]);

        $this->actingAs($this->adminEscola())->from('/x')
            ->put(route(self::BASE . 'update', $b), $this->payload($ano->id, ['nome' => 'A', 'alvos' => [['nivel_academico_id' => $nivel->id]]]))
            ->assertSessionHasErrors('nome');
    }

    public function test_desactiva_e_reactiva(): void
    {
        $plano = $this->plano($this->anoLectivo(), 'A');
        $this->actingAs($this->adminEscola());

        $this->patch(route(self::BASE . 'alterar-estado', $plano), ['estado' => 0])->assertSessionHasNoErrors();
        $this->assertSame('Inativo', $plano->fresh()->estado_descricao);
        $this->assertSame(0, PlanoPropina::activos()->count());

        $this->patch(route(self::BASE . 'alterar-estado', $plano), ['estado' => 1])->assertSessionHasNoErrors();
        $this->assertSame(1, PlanoPropina::activos()->count());
    }

    public function test_elimina_sem_referencias_e_apaga_os_alvos(): void
    {
        $plano = $this->plano($this->anoLectivo(), 'A', [], [['nivel' => $this->nivel('N1')]]);

        $this->actingAs($this->adminEscola())
            ->delete(route(self::BASE . 'destroy', $plano))
            ->assertSessionHasNoErrors();

        $this->assertNull(PlanoPropina::find($plano->id));
        $this->assertSame(0, PlanoPropinaAlvo::count());
    }

    public function test_eliminar_com_referencia_financeira_e_bloqueado(): void
    {
        $plano = $this->plano($this->anoLectivo(), 'A');
        $this->app->instance('ref.fake', new class implements ReferenciaFinanceira {
            public function existeReferenciaA(Model $configuracao): bool
            {
                return true;
            }
        });
        $this->app->tag(['ref.fake'], ReferenciasFinanceiras::ETIQUETA);

        $this->actingAs($this->adminEscola())->from('/x')
            ->delete(route(self::BASE . 'destroy', $plano))
            ->assertSessionHasErrors(['eliminar' => 'Não é possível eliminar este plano de propina: já existem registos financeiros que o utilizam. Desative-o.']);

        $this->assertNotNull(PlanoPropina::find($plano->id));
    }

    public function test_professor_recebe_403_em_todas_as_rotas(): void
    {
        $plano = $this->plano($this->anoLectivo(), 'A');
        $this->actingAs($this->professor());

        $this->get(route(self::BASE . 'index'))->assertForbidden();
        $this->post(route(self::BASE . 'store'), $this->payload($plano->ano_lectivo_id))->assertForbidden();
        $this->put(route(self::BASE . 'update', $plano), $this->payload($plano->ano_lectivo_id))->assertForbidden();
        $this->patch(route(self::BASE . 'alterar-estado', $plano), ['estado' => 0])->assertForbidden();
        $this->delete(route(self::BASE . 'destroy', $plano))->assertForbidden();
    }

    public function test_plano_de_outro_tenant_da_404_e_nada_muda(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $doOutro = $this->noTenant($outro, fn () => $this->plano($this->anoLectivo(), 'Do Outro'));
        $this->actingAs($this->adminEscola());

        $this->put(route(self::BASE . 'update', $doOutro->id), $this->payload($doOutro->ano_lectivo_id, ['nome' => 'Alterado']))->assertNotFound();
        $this->patch(route(self::BASE . 'alterar-estado', $doOutro->id), ['estado' => 0])->assertNotFound();
        $this->delete(route(self::BASE . 'destroy', $doOutro->id))->assertNotFound();

        $linha = DB::table('planos_propina')->where('id', $doOutro->id)->first();
        $this->assertSame('Do Outro', $linha->nome);
        $this->assertSame(1, (int) $linha->estado);
    }

    public function test_tenant_id_forjado_no_payload_e_ignorado(): void
    {
        $ano = $this->anoLectivo();
        $outro = $this->criarTenant('MOSI-000003', 'Escola C', 'c.localhost');

        $this->actingAs($this->adminEscola())
            ->post(route(self::BASE . 'store'), $this->payload($ano->id, ['tenant_id' => $outro->id]))
            ->assertSessionHasNoErrors();

        $this->assertSame($this->tenant->id, PlanoPropina::firstWhere('nome', 'Propina Mensal')->tenant_id);
    }

    public function test_mensagens_ao_utilizador_em_portugues(): void
    {
        $ano = $this->anoLectivo();
        $this->actingAs($this->adminEscola())->from('/x');

        $this->post(route(self::BASE . 'store'), $this->payload($ano->id, ['nome' => '']))
            ->assertSessionHasErrors(['nome' => 'O nome do plano de propina é obrigatório.']);

        $this->post(route(self::BASE . 'store'), $this->payload($ano->id))->assertSessionHas('success', 'Plano de propina criado com sucesso.');
        $plano = PlanoPropina::firstWhere('nome', 'Propina Mensal');

        $this->from('/x')->post(route(self::BASE . 'store'), $this->payload($ano->id))
            ->assertSessionHasErrors(['nome' => 'Já existe um plano de propina com este nome neste ano lectivo.']);

        $this->put(route(self::BASE . 'update', $plano), $this->payload($ano->id))
            ->assertSessionHas('success', 'Plano de propina atualizado com sucesso.');
        $this->patch(route(self::BASE . 'alterar-estado', $plano), ['estado' => 0])
            ->assertSessionHas('success', 'Estado do plano de propina atualizado com sucesso.');
        $this->delete(route(self::BASE . 'destroy', $plano))
            ->assertSessionHas('success', 'Plano de propina eliminado com sucesso.');
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Unit/AlvosDoPlanoTest.php Modules/Financeiro/tests/Feature/PlanoPropinaTest.php`
Expected: FAIL (classes e rotas inexistentes).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Support/AlvosDoPlano.php`:
```php
<?php

namespace Modules\Financeiro\Support;

/**
 * Normalização dos alvos de um plano (nivel_academico_id, curso_id, turno_id, turma_id).
 * Linhas totalmente vazias são ignoradas; sem nenhum alvo o plano aplica-se a tudo,
 * representado por um alvo vazio ao comparar planos.
 */
final class AlvosDoPlano
{
    private const CAMPOS = ['nivel_academico_id', 'curso_id', 'turno_id', 'turma_id'];

    /**
     * Alvos únicos para gravar (exclui os vazios).
     *
     * @param  array<int, mixed>  $alvos
     * @return list<array{nivel_academico_id: ?int, curso_id: ?int, turno_id: ?int, turma_id: ?int}>
     */
    public static function paraGravar(array $alvos): array
    {
        $unicos = [];

        foreach ($alvos as $alvo) {
            $normalizado = self::alvo($alvo);

            if (self::vazio($normalizado)) {
                continue;
            }

            $unicos[self::chave($normalizado)] = $normalizado;
        }

        return array_values($unicos);
    }

    /**
     * Como paraGravar, mas sem alvos devolve um alvo vazio: serve para comparar planos.
     *
     * @param  array<int, mixed>  $alvos
     * @return list<array{nivel_academico_id: ?int, curso_id: ?int, turno_id: ?int, turma_id: ?int}>
     */
    public static function normalizar(array $alvos): array
    {
        $alvosParaGravar = self::paraGravar($alvos);

        return $alvosParaGravar === [] ? [self::alvo([])] : $alvosParaGravar;
    }

    /**
     * @param  array<string, mixed>  $alvo
     */
    public static function chave(array $alvo): string
    {
        $normalizado = self::alvo($alvo);

        return implode('|', array_map(fn (string $campo) => (string) $normalizado[$campo], self::CAMPOS));
    }

    /**
     * @param  array<int, mixed>  $alvos
     */
    public static function temRepetidos(array $alvos): bool
    {
        $chaves = [];

        foreach ($alvos as $alvo) {
            $normalizado = self::alvo($alvo);

            if (self::vazio($normalizado)) {
                continue;
            }

            $chave = self::chave($normalizado);

            if (isset($chaves[$chave])) {
                return true;
            }

            $chaves[$chave] = true;
        }

        return false;
    }

    /**
     * Um alvo com turma definida não pode ter nível, curso nem turno.
     *
     * @param  array<int, mixed>  $alvos
     */
    public static function violaExclusividadeDaTurma(array $alvos): bool
    {
        foreach ($alvos as $alvo) {
            $normalizado = self::alvo($alvo);

            if ($normalizado['turma_id'] !== null
                && ($normalizado['nivel_academico_id'] !== null || $normalizado['curso_id'] !== null || $normalizado['turno_id'] !== null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, mixed>  $alvos
     * @return list<int>
     */
    public static function idsDeTurma(array $alvos): array
    {
        $ids = [];

        foreach ($alvos as $alvo) {
            $turma = self::alvo($alvo)['turma_id'];

            if ($turma !== null) {
                $ids[$turma] = $turma;
            }
        }

        return array_values($ids);
    }

    /**
     * @return array{nivel_academico_id: ?int, curso_id: ?int, turno_id: ?int, turma_id: ?int}
     */
    private static function alvo(mixed $alvo): array
    {
        $alvo = is_array($alvo) ? $alvo : [];
        $resultado = [];

        foreach (self::CAMPOS as $campo) {
            $valor = $alvo[$campo] ?? null;
            $resultado[$campo] = $valor === null || $valor === '' ? null : (int) $valor;
        }

        return $resultado;
    }

    /**
     * @param  array{nivel_academico_id: ?int, curso_id: ?int, turno_id: ?int, turma_id: ?int}  $alvo
     */
    private static function vazio(array $alvo): bool
    {
        return $alvo['nivel_academico_id'] === null && $alvo['curso_id'] === null
            && $alvo['turno_id'] === null && $alvo['turma_id'] === null;
    }
}
```

`Modules/Financeiro/app/DTO/PlanoPropinaDTO.php`:
```php
<?php

namespace Modules\Financeiro\DTO;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Financeiro\Enums\Periodicidade;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Support\AlvosDoPlano;
use Modules\Financeiro\Support\Dinheiro;

class PlanoPropinaDTO
{
    /**
     * @param  list<array{nivel_academico_id: ?int, curso_id: ?int, turno_id: ?int, turma_id: ?int}>  $alvos
     */
    public function __construct(
        public string $nome,
        public Periodicidade $periodicidade,
        public int $intervalo_meses,
        public Dinheiro $valor,
        public int $mes_inicio,
        public int $mes_fim,
        public array $alvos,
        public ?int $ano_lectivo_id = null,
        public ?string $descricao = null,
    ) {
    }

    public static function fromRequest(FormRequest $request): self
    {
        $dados = $request->validated();
        $periodicidade = Periodicidade::from((int) $dados['periodicidade']);

        return new self(
            nome: $dados['nome'],
            periodicidade: $periodicidade,
            intervalo_meses: $periodicidade->meses() ?? (int) $dados['intervalo_meses'],
            valor: Dinheiro::deDecimal((string) $dados['valor'], app(MoedaDoTenant::class)->atual()),
            mes_inicio: (int) $dados['mes_inicio'],
            mes_fim: (int) $dados['mes_fim'],
            alvos: AlvosDoPlano::paraGravar((array) ($dados['alvos'] ?? [])),
            ano_lectivo_id: isset($dados['ano_lectivo_id']) ? (int) $dados['ano_lectivo_id'] : null,
            descricao: ($dados['descricao'] ?? null) !== '' ? ($dados['descricao'] ?? null) : null,
        );
    }
}
```

`Modules/Financeiro/app/Http/Requests/Concerns/ValidaPlanoPropina.php`:
```php
<?php

namespace Modules\Financeiro\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Tenancy\TenantContext;
use Modules\Financeiro\Enums\Periodicidade;
use Modules\Financeiro\Rules\ValorMonetario;
use Modules\Financeiro\Services\PlanoPropinaConsultaService;
use Modules\Financeiro\Support\AlvosDoPlano;
use Modules\Financeiro\Support\CalendarioDePlano;

/**
 * Regras e validação cruzada partilhadas por Criar e Atualizar um plano de propina.
 */
trait ValidaPlanoPropina
{
    /**
     * @return array<string, mixed>
     */
    protected function regrasComuns(): array
    {
        $tenantId = app(TenantContext::class)->id();
        $existeNoTenant = fn (string $tabela, bool $comSoftDelete) => Rule::exists($tabela, 'id')
            ->where(function ($query) use ($tenantId, $comSoftDelete) {
                $query->where('tenant_id', $tenantId);

                if ($comSoftDelete) {
                    $query->whereNull('deleted_at');
                }
            });

        return [
            'descricao' => ['nullable', 'string'],
            'periodicidade' => ['required', new Enum(Periodicidade::class)],
            'intervalo_meses' => [
                Rule::requiredIf(fn () => (int) $this->input('periodicidade') === Periodicidade::OUTRA->value),
                'nullable',
                'integer',
                'between:1,12',
            ],
            'valor' => ['required', new ValorMonetario(false)],
            'mes_inicio' => ['required', 'integer', 'between:1,12'],
            'mes_fim' => ['required', 'integer', 'between:1,12'],
            'alvos' => ['nullable', 'array'],
            'alvos.*.nivel_academico_id' => ['nullable', 'integer', $existeNoTenant('niveis_academicos', true)],
            'alvos.*.curso_id' => ['nullable', 'integer', $existeNoTenant('cursos', false)],
            'alvos.*.turno_id' => ['nullable', 'integer', $existeNoTenant('turnos', true)],
            'alvos.*.turma_id' => ['nullable', 'integer', $existeNoTenant('turmas', true)],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function mensagensComuns(): array
    {
        return [
            'nome.required' => 'O nome do plano de propina é obrigatório.',
            'nome.max' => 'O nome do plano de propina não pode ultrapassar 100 caracteres.',
            'nome.unique' => 'Já existe um plano de propina com este nome neste ano lectivo.',
            'ano_lectivo_id.required' => 'O ano lectivo é obrigatório.',
            'ano_lectivo_id.exists' => 'O ano lectivo escolhido é inválido.',
            'periodicidade.required' => 'A periodicidade é obrigatória.',
            'intervalo_meses.required' => 'Indique o intervalo, em meses, da periodicidade.',
            'intervalo_meses.between' => 'O intervalo tem de estar entre 1 e 12 meses.',
            'valor.required' => 'O valor do plano é obrigatório.',
            'mes_inicio.required' => 'O mês de início é obrigatório.',
            'mes_inicio.between' => 'O mês de início tem de estar entre 1 e 12.',
            'mes_fim.required' => 'O mês de fim é obrigatório.',
            'mes_fim.between' => 'O mês de fim tem de estar entre 1 e 12.',
            'alvos.*.nivel_academico_id.exists' => 'O nível académico escolhido é inválido.',
            'alvos.*.curso_id.exists' => 'O curso escolhido é inválido.',
            'alvos.*.turno_id.exists' => 'O turno escolhido é inválido.',
            'alvos.*.turma_id.exists' => 'A turma escolhida é inválida.',
        ];
    }

    protected function validarCoerencia(Validator $validator, AnoLectivo $ano, ?int $ignorarPlanoId): void
    {
        $erros = $validator->errors();

        if (! $erros->hasAny(['periodicidade', 'intervalo_meses', 'mes_inicio', 'mes_fim'])) {
            $periodicidade = Periodicidade::from((int) $this->input('periodicidade'));
            $intervalo = $periodicidade->meses() ?? (int) $this->input('intervalo_meses');
            $meses = CalendarioDePlano::meses((int) $this->input('mes_inicio'), (int) $this->input('mes_fim'));

            if ($intervalo > $meses) {
                $validator->errors()->add('periodicidade', 'A periodicidade excede a duração do período de cobrança.');
            }
        }

        $temErroEmAlvos = collect($erros->keys())->contains(fn (string $chave) => str_starts_with($chave, 'alvos'));

        if ($temErroEmAlvos) {
            return;
        }

        $alvos = (array) $this->input('alvos', []);
        $consulta = app(PlanoPropinaConsultaService::class);

        if (AlvosDoPlano::temRepetidos($alvos)) {
            $validator->errors()->add('alvos', 'Há alvos repetidos no plano.');

            return;
        }

        if (AlvosDoPlano::violaExclusividadeDaTurma($alvos)) {
            $validator->errors()->add('alvos', 'Uma turma específica não se combina com nível, curso ou turno.');

            return;
        }

        if ($consulta->turmasForaDoAno($ano->id, AlvosDoPlano::idsDeTurma($alvos))) {
            $validator->errors()->add('alvos', 'A turma escolhida não pertence ao ano lectivo do plano.');

            return;
        }

        if ($erros->hasAny(['mes_inicio', 'mes_fim'])) {
            return;
        }

        $competencias = CalendarioDePlano::competencias((int) $this->input('mes_inicio'), (int) $this->input('mes_fim'), $ano->data_inicio);

        if ($consulta->colisaoDeAlvos($ano->id, $alvos, $competencias, $ignorarPlanoId)) {
            $validator->errors()->add('alvos', 'Já existe outro plano deste ano lectivo, com período sobreposto, aplicável aos mesmos alvos.');
        }
    }
}
```

`Modules/Financeiro/app/Http/Requests/CriarPlanoPropinaRequest.php`:
```php
<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Tenancy\TenantContext;
use Modules\Financeiro\Http\Requests\Concerns\ValidaPlanoPropina;

class CriarPlanoPropinaRequest extends BaseRequest
{
    use ValidaPlanoPropina;

    public function authorize(): bool
    {
        return $this->user()?->can('plano-propina.criar') ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();

        return array_merge($this->regrasComuns(), [
            'ano_lectivo_id' => [
                'required',
                'integer',
                Rule::exists('ano_lectivos', 'id')
                    ->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at')),
            ],
            'nome' => [
                'required',
                'string',
                'max:100',
                Rule::unique('planos_propina', 'nome')
                    ->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('ano_lectivo_id', $this->input('ano_lectivo_id'))),
            ],
        ]);
    }

    public function messages(): array
    {
        return $this->mensagensComuns();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('ano_lectivo_id')) {
                return;
            }

            $ano = AnoLectivo::find((int) $this->input('ano_lectivo_id'));

            if ($ano !== null) {
                $this->validarCoerencia($validator, $ano, null);
            }
        });
    }
}
```

`Modules/Financeiro/app/Http/Requests/AtualizarPlanoPropinaRequest.php`:
```php
<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Modules\Core\Tenancy\TenantContext;
use Modules\Financeiro\Http\Requests\Concerns\ValidaPlanoPropina;

class AtualizarPlanoPropinaRequest extends BaseRequest
{
    use ValidaPlanoPropina;

    public function authorize(): bool
    {
        return $this->user()?->can('plano-propina.editar') ?? false;
    }

    public function rules(): array
    {
        $plano = $this->route('plano');

        return array_merge($this->regrasComuns(), [
            'nome' => [
                'required',
                'string',
                'max:100',
                Rule::unique('planos_propina', 'nome')
                    ->where(fn ($query) => $query
                        ->where('tenant_id', app(TenantContext::class)->id())
                        ->where('ano_lectivo_id', $plano?->ano_lectivo_id))
                    ->ignore($plano?->id),
            ],
        ]);
    }

    public function messages(): array
    {
        return $this->mensagensComuns();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $plano = $this->route('plano');

            if ($plano->anoLectivo !== null) {
                $this->validarCoerencia($validator, $plano->anoLectivo, (int) $plano->id);
            }
        });
    }
}
```

`Modules/Financeiro/app/Http/Requests/AlterarEstadoPlanoPropinaRequest.php`: igual ao `AlterarEstadoMetodoPagamentoRequest` (Plano 2) com `authorize` → `plano-propina.editar`.

Actions (`Modules/Financeiro/app/Actions/`):
```php
// CriarPlanoPropinaAction.php
<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Financeiro\DTO\PlanoPropinaDTO;
use Modules\Financeiro\Models\PlanoPropina;

class CriarPlanoPropinaAction
{
    public function executar(PlanoPropinaDTO $dto): PlanoPropina
    {
        return DB::transaction(function () use ($dto) {
            $plano = PlanoPropina::create([
                'ano_lectivo_id' => $dto->ano_lectivo_id,
                'nome' => $dto->nome,
                'descricao' => $dto->descricao,
                'periodicidade' => $dto->periodicidade,
                'intervalo_meses' => $dto->intervalo_meses,
                'valor' => $dto->valor,
                'mes_inicio' => $dto->mes_inicio,
                'mes_fim' => $dto->mes_fim,
            ]);

            $plano->substituirAlvos($dto->alvos);

            return $plano->fresh('alvos');
        });
    }
}
```
```php
// AtualizarPlanoPropinaAction.php  (o ano lectivo nunca muda)
<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Financeiro\DTO\PlanoPropinaDTO;
use Modules\Financeiro\Models\PlanoPropina;

class AtualizarPlanoPropinaAction
{
    public function executar(PlanoPropina $plano, PlanoPropinaDTO $dto): PlanoPropina
    {
        return DB::transaction(function () use ($plano, $dto) {
            $plano->update([
                'nome' => $dto->nome,
                'descricao' => $dto->descricao,
                'periodicidade' => $dto->periodicidade,
                'intervalo_meses' => $dto->intervalo_meses,
                'valor' => $dto->valor,
                'mes_inicio' => $dto->mes_inicio,
                'mes_fim' => $dto->mes_fim,
            ]);

            $plano->substituirAlvos($dto->alvos);

            return $plano->fresh('alvos');
        });
    }
}
```
```php
// AlterarEstadoPlanoPropinaAction.php
<?php

namespace Modules\Financeiro\Actions;

use Modules\Core\Enums\Estado;
use Modules\Financeiro\Models\PlanoPropina;

class AlterarEstadoPlanoPropinaAction
{
    public function executar(PlanoPropina $plano, Estado $novoEstado): PlanoPropina
    {
        $plano->estado = $novoEstado->value;
        $plano->save();

        return $plano->fresh();
    }
}
```
```php
// EliminarPlanoPropinaAction.php
<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Support\ReferenciasFinanceiras;

class EliminarPlanoPropinaAction
{
    public function __construct(private ReferenciasFinanceiras $referencias)
    {
    }

    public function executar(PlanoPropina $plano): void
    {
        if ($this->referencias->existeReferenciaA($plano)) {
            throw ValidationException::withMessages([
                'eliminar' => 'Não é possível eliminar este plano de propina: já existem registos financeiros que o utilizam. Desative-o.',
            ]);
        }

        $plano->delete();
    }
}
```

`Modules/Financeiro/app/Services/GestaoPlanoPropinaService.php`:
```php
<?php

namespace Modules\Financeiro\Services;

use Modules\Core\Enums\Estado;
use Modules\Financeiro\Actions\AlterarEstadoPlanoPropinaAction;
use Modules\Financeiro\Actions\AtualizarPlanoPropinaAction;
use Modules\Financeiro\Actions\CriarPlanoPropinaAction;
use Modules\Financeiro\Actions\EliminarPlanoPropinaAction;
use Modules\Financeiro\DTO\PlanoPropinaDTO;
use Modules\Financeiro\Http\Requests\AtualizarPlanoPropinaRequest;
use Modules\Financeiro\Http\Requests\CriarPlanoPropinaRequest;
use Modules\Financeiro\Models\PlanoPropina;

class GestaoPlanoPropinaService
{
    public function __construct(
        private CriarPlanoPropinaAction $criarAction,
        private AtualizarPlanoPropinaAction $atualizarAction,
        private AlterarEstadoPlanoPropinaAction $alterarEstadoAction,
        private EliminarPlanoPropinaAction $eliminarAction,
    ) {
    }

    public function criar(CriarPlanoPropinaRequest $request): PlanoPropina
    {
        return $this->criarAction->executar(PlanoPropinaDTO::fromRequest($request));
    }

    public function atualizar(PlanoPropina $plano, AtualizarPlanoPropinaRequest $request): PlanoPropina
    {
        return $this->atualizarAction->executar($plano, PlanoPropinaDTO::fromRequest($request));
    }

    public function alterarEstado(PlanoPropina $plano, Estado $estado): PlanoPropina
    {
        return $this->alterarEstadoAction->executar($plano, $estado);
    }

    public function eliminar(PlanoPropina $plano): void
    {
        $this->eliminarAction->executar($plano);
    }
}
```

`Modules/Financeiro/app/Services/PlanoPropinaConsultaService.php`:
```php
<?php

namespace Modules\Financeiro\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Curso\Models\Curso;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\PlanoPropinaAlvo;
use Modules\Financeiro\Support\AlvosDoPlano;
use Modules\Financeiro\Support\CalendarioDePlano;
use Modules\Financeiro\Support\Precedencia;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Turma\Models\Turno;

class PlanoPropinaConsultaService
{
    public function listar(array $filtros = [], int $porPagina = 10): LengthAwarePaginator
    {
        return PlanoPropina::query()
            ->with(['anoLectivo', 'alvos.nivelAcademico', 'alvos.curso', 'alvos.turno', 'alvos.turma'])
            ->when(in_array($filtros['estado'] ?? null, ['0', '1', 0, 1], true), fn ($query) => $query->where('estado', $filtros['estado']))
            ->when(is_numeric($filtros['ano_lectivo_id'] ?? null), fn ($query) => $query->where('ano_lectivo_id', (int) $filtros['ano_lectivo_id']))
            ->when(is_string($filtros['pesquisa'] ?? null) && $filtros['pesquisa'] !== '', fn ($query) => $query->whereContem('nome', $filtros['pesquisa']))
            ->orderBy('ano_lectivo_id', 'desc')
            ->orderBy('nome')
            ->paginate($porPagina)
            ->withQueryString()
            ->through(fn (PlanoPropina $plano) => [
                'id' => $plano->id,
                'nome' => $plano->nome,
                'descricao' => $plano->descricao,
                'ano_lectivo_id' => $plano->ano_lectivo_id,
                'ano_lectivo_nome' => $plano->anoLectivo?->nome,
                'periodicidade' => $plano->periodicidade->value,
                'periodicidade_descricao' => $plano->periodicidade_descricao,
                'intervalo_meses' => $plano->intervalo_meses,
                'valor' => $plano->valor->unidadesMenores(),
                'mes_inicio' => $plano->mes_inicio,
                'mes_fim' => $plano->mes_fim,
                'periodos_total' => count($plano->periodos()),
                'precedencia' => $this->precedenciaDoPlano($plano),
                'alvos' => $plano->alvos->map(fn (PlanoPropinaAlvo $alvo) => [
                    'nivel_academico_id' => $alvo->nivel_academico_id,
                    'nivel_nome' => $alvo->nivelAcademico?->nome,
                    'curso_id' => $alvo->curso_id,
                    'curso_nome' => $alvo->curso?->nome,
                    'turno_id' => $alvo->turno_id,
                    'turno_nome' => $alvo->turno?->nome,
                    'turma_id' => $alvo->turma_id,
                    'turma_nome' => $alvo->turma?->nome,
                    'precedencia' => Precedencia::descricao($alvo->only(['nivel_academico_id', 'curso_id', 'turno_id', 'turma_id'])),
                ])->values()->all(),
                'estado' => $plano->estado,
                'estado_descricao' => $plano->estado_descricao,
            ]);
    }

    /**
     * Há outro plano (não $ignorarPlanoId) do mesmo ano lectivo, com competências sobrepostas
     * às dadas, e com algum dos mesmos alvos? Planos sem alvos contam como o alvo vazio
     * (dois "gerais" colidem). Planos inactivos contam.
     *
     * @param  array<int, mixed>  $alvos
     * @param  list<array{ano: int, mes: int}>  $competencias
     */
    public function colisaoDeAlvos(int $anoLectivoId, array $alvos, array $competencias, ?int $ignorarPlanoId): bool
    {
        $chaves = array_map(fn (array $alvo) => AlvosDoPlano::chave($alvo), AlvosDoPlano::normalizar($alvos));

        $outros = PlanoPropina::query()
            ->where('ano_lectivo_id', $anoLectivoId)
            ->when($ignorarPlanoId !== null, fn ($query) => $query->where('id', '!=', $ignorarPlanoId))
            ->with(['alvos', 'anoLectivo'])
            ->get();

        foreach ($outros as $outro) {
            if (! CalendarioDePlano::sobrepoem($competencias, $outro->competencias())) {
                continue;
            }

            $alvosDoOutro = AlvosDoPlano::normalizar($outro->alvos->map(fn (PlanoPropinaAlvo $alvo) => $alvo->only([
                'nivel_academico_id', 'curso_id', 'turno_id', 'turma_id',
            ]))->all());

            foreach ($alvosDoOutro as $alvoDoOutro) {
                if (in_array(AlvosDoPlano::chave($alvoDoOutro), $chaves, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Alguma das turmas dadas não pertence ao ano lectivo?
     *
     * @param  list<int>  $turmaIds
     */
    public function turmasForaDoAno(int $anoLectivoId, array $turmaIds): bool
    {
        if ($turmaIds === []) {
            return false;
        }

        $doAno = Turma::query()->whereIn('id', $turmaIds)->where('ano_lectivo_id', $anoLectivoId)->count();

        return $doAno !== count(array_unique($turmaIds));
    }

    /**
     * @return Collection<int, AnoLectivo>
     */
    public function anosLectivos(): Collection
    {
        return AnoLectivo::query()->orderByDesc('data_inicio')->get(['id', 'nome']);
    }

    /**
     * @return Collection<int, NivelAcademico>
     */
    public function niveis(): Collection
    {
        return NivelAcademico::query()->orderBy('ordem')->orderBy('nome')->get(['id', 'nome']);
    }

    /**
     * @return Collection<int, Curso>
     */
    public function cursos(): Collection
    {
        return Curso::query()->orderBy('nome')->get(['id', 'nome']);
    }

    /**
     * @return Collection<int, Turno>
     */
    public function turnos(): Collection
    {
        return Turno::query()->orderBy('nome')->get(['id', 'nome']);
    }

    /**
     * @return Collection<int, Turma>
     */
    public function turmas(): Collection
    {
        return Turma::query()->orderBy('nome')->get(['id', 'nome', 'ano_lectivo_id']);
    }

    private function precedenciaDoPlano(PlanoPropina $plano): string
    {
        if ($plano->alvos->isEmpty()) {
            return Precedencia::descricao([]);
        }

        return $plano->alvos
            ->map(fn (PlanoPropinaAlvo $alvo) => Precedencia::descricao($alvo->only(['nivel_academico_id', 'curso_id', 'turno_id', 'turma_id'])))
            ->unique()
            ->implode('; ');
    }
}
```

`Modules/Financeiro/app/Http/Controllers/PlanoPropinaController.php`:
```php
<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Core\Enums\Estado;
use Modules\Financeiro\Enums\Periodicidade;
use Modules\Financeiro\Http\Requests\AlterarEstadoPlanoPropinaRequest;
use Modules\Financeiro\Http\Requests\AtualizarPlanoPropinaRequest;
use Modules\Financeiro\Http\Requests\CriarPlanoPropinaRequest;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Services\GestaoPlanoPropinaService;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Services\PlanoPropinaConsultaService;

class PlanoPropinaController extends Controller
{
    public function __construct(
        private GestaoPlanoPropinaService $service,
        private PlanoPropinaConsultaService $consulta,
        private MoedaDoTenant $moedaDoTenant,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('plano-propina.ver');

        $filtros = $request->only(['pesquisa', 'estado', 'ano_lectivo_id']);

        return Inertia::render('Financeiro/PlanosPropina/Index', [
            'planos' => $this->consulta->listar($filtros),
            'filtros' => $filtros,
            'anosLectivos' => $this->consulta->anosLectivos(),
            'niveis' => $this->consulta->niveis(),
            'cursos' => $this->consulta->cursos(),
            'turnos' => $this->consulta->turnos(),
            'turmas' => $this->consulta->turmas(),
            'periodicidades' => Periodicidade::opcoes(),
            'moeda' => $this->moedaDoTenant->atual()->paraFrontend(),
        ]);
    }

    public function store(CriarPlanoPropinaRequest $request)
    {
        $this->authorize('plano-propina.criar');

        $this->service->criar($request);

        return redirect()->back()->with('success', 'Plano de propina criado com sucesso.');
    }

    public function update(AtualizarPlanoPropinaRequest $request, PlanoPropina $plano)
    {
        $this->authorize('plano-propina.editar');

        $this->service->atualizar($plano, $request);

        return redirect()->back()->with('success', 'Plano de propina atualizado com sucesso.');
    }

    public function alterarEstado(AlterarEstadoPlanoPropinaRequest $request, PlanoPropina $plano)
    {
        $this->authorize('plano-propina.editar');

        $this->service->alterarEstado($plano, Estado::from((int) $request->validated('estado')));

        return redirect()->back()->with('success', 'Estado do plano de propina atualizado com sucesso.');
    }

    public function destroy(PlanoPropina $plano)
    {
        $this->authorize('plano-propina.eliminar');

        $this->service->eliminar($plano);

        return redirect()->back()->with('success', 'Plano de propina eliminado com sucesso.');
    }
}
```

`Modules/Financeiro/routes/web.php`: acrescentar `use Modules\Financeiro\Http\Controllers\PlanoPropinaController;` e, dentro do grupo `financeiro/configuracao`, depois do bloco `produtos-servicos` (mantendo todas as rotas existentes):
```php
    Route::prefix('planos-propina')->name('planos-propina.')->group(function () {
        Route::get('/', [PlanoPropinaController::class, 'index'])->middleware('can:plano-propina.ver')->name('index');
        Route::post('/', [PlanoPropinaController::class, 'store'])->middleware('can:plano-propina.criar')->name('store');
        Route::put('/{plano}', [PlanoPropinaController::class, 'update'])->middleware('can:plano-propina.editar')->name('update');
        Route::patch('/{plano}/estado', [PlanoPropinaController::class, 'alterarEstado'])->middleware('can:plano-propina.editar')->name('alterar-estado');
        Route::delete('/{plano}', [PlanoPropinaController::class, 'destroy'])->middleware('can:plano-propina.eliminar')->name('destroy');
    });
```

`Modules/Financeiro/app/Support/PrecosDosPlanos.php` (espelha `PrecosDoCatalogo`; `PlanoPropina` é tenant-aware, por isso `exists()` já é do tenant corrente):
```php
<?php

namespace Modules\Financeiro\Support;

use Modules\Financeiro\Contracts\FonteDePrecos;
use Modules\Financeiro\Models\PlanoPropina;

class PrecosDosPlanos implements FonteDePrecos
{
    public function existemPrecos(): bool
    {
        return PlanoPropina::query()->exists();
    }
}
```

`Modules/Financeiro/app/Providers/FinanceiroServiceProvider.php`: acrescentar `use Modules\Financeiro\Support\PrecosDosPlanos;` e, em `register()`, trocar a linha da etiqueta por:
```php
        $this->app->tag([PrecosDoCatalogo::class, PrecosDosPlanos::class], FontesDePrecos::ETIQUETA);
```
(os testes `test_precos_dos_planos_*` do Step 1 cobrem a classe, o registo na etiqueta e o bloqueio de `MoedaDoTenant::podeAlterar()`).

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro`
Expected: PASS.

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro
```

---

### Task 6: `ResolvePlanoAplicavel`

**Files:**
- Create: `Modules/Financeiro/app/Support/ResultadoResolucaoPlano.php`
- Create: `Modules/Financeiro/app/Services/ResolvePlanoAplicavel.php`
- Test: `Modules/Financeiro/tests/Feature/ResolvePlanoAplicavelTest.php`

**Interfaces:**
- Consumes: `PlanoPropina::activos()`, `competencias()`, `alvos`, `Precedencia::rank`, `CalendarioDePlano::contem`, trait `ComDadosAcademicosFinanceiro` (T4).
- Produces:
  - `ResultadoResolucaoPlano` (final, imutável) com `?PlanoPropina $plano`, `bool $conflito`, `list<PlanoPropina> $candidatos`, `static aplicavel(PlanoPropina)`, `static semPlano()`, `static conflito(array $candidatos)`, `temPlano(): bool` (verdadeiro só com um plano único e sem conflito);
  - `ResolvePlanoAplicavel::paraTurma(Turma $turma, ?array $competencia = null): ResultadoResolucaoPlano` — considera só planos **activos** do ano lectivo da turma; com `$competencia` (`['ano' => int, 'mes' => int]`) só os planos cujo período a contém; um alvo casa se cada campo definido (nível, curso, turno, turma) for igual ao da turma (uma turma sem turno nunca casa um alvo de turno); plano sem alvos tem rank do alvo vazio; ganha o maior `Precedencia::rank` (entre os alvos que casam, o melhor); empate entre planos distintos = conflito.

- [ ] **Step 1: Escrever o teste que falha**

```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Enums\Estado;
use Modules\Financeiro\Services\ResolvePlanoAplicavel;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Tests\TestCase;

class ResolvePlanoAplicavelTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use RefreshDatabase;

    private function resolver(): ResolvePlanoAplicavel
    {
        return app(ResolvePlanoAplicavel::class);
    }

    public function test_sem_planos_nao_ha_plano(): void
    {
        $ano = $this->anoLectivo();
        $turma = $this->turma($ano, $this->nivel('N1'));

        $resultado = $this->resolver()->paraTurma($turma);

        $this->assertFalse($resultado->temPlano());
        $this->assertNull($resultado->plano);
        $this->assertFalse($resultado->conflito);
    }

    public function test_plano_geral_aplica_se_a_todas_as_turmas_do_ano(): void
    {
        $ano = $this->anoLectivo();
        $geral = $this->plano($ano, 'Geral');

        $resultado = $this->resolver()->paraTurma($this->turma($ano, $this->nivel('N1')));

        $this->assertTrue($resultado->temPlano());
        $this->assertSame($geral->id, $resultado->plano->id);
    }

    public function test_plano_de_nivel_so_aplica_ao_nivel(): void
    {
        $ano = $this->anoLectivo();
        $n1 = $this->nivel('N1');
        $n2 = $this->nivel('N2');
        $plano = $this->plano($ano, 'Nivel 1', [], [['nivel' => $n1]]);

        $this->assertSame($plano->id, $this->resolver()->paraTurma($this->turma($ano, $n1))->plano->id);
        $this->assertFalse($this->resolver()->paraTurma($this->turma($ano, $n2))->temPlano());
    }

    public function test_plano_de_curso_nao_aplica_a_turma_sem_curso(): void
    {
        $ano = $this->anoLectivo();
        $n1 = $this->nivel('N1');
        $curso = $this->curso('C1');
        $porCurso = $this->plano($ano, 'Por Curso', [], [['curso' => $curso]]);

        $this->assertFalse($this->resolver()->paraTurma($this->turma($ano, $n1))->temPlano());
        $this->assertSame($porCurso->id, $this->resolver()->paraTurma($this->turma($ano, $n1, $curso))->plano->id);
    }

    public function test_plano_de_turno_nao_aplica_a_turma_sem_turno(): void
    {
        $ano = $this->anoLectivo();
        $n1 = $this->nivel('N1');
        $noite = $this->turno('Noite');
        $plano = $this->plano($ano, 'Noite', [], [['turno' => $noite]]);

        $this->assertFalse($this->resolver()->paraTurma($this->turma($ano, $n1))->temPlano());
        $this->assertFalse($this->resolver()->paraTurma($this->turma($ano, $n1, null, $this->turno('Manha')))->temPlano());
        $this->assertSame($plano->id, $this->resolver()->paraTurma($this->turma($ano, $n1, null, $noite))->plano->id);
    }

    public function test_curso_vence_nivel_na_mesma_turma(): void
    {
        $ano = $this->anoLectivo();
        $n1 = $this->nivel('N1');
        $curso = $this->curso('C1');
        $this->plano($ano, 'Por Nivel', [], [['nivel' => $n1]]);
        $porCurso = $this->plano($ano, 'Por Curso', [], [['curso' => $curso]]);

        $resultado = $this->resolver()->paraTurma($this->turma($ano, $n1, $curso));

        $this->assertTrue($resultado->temPlano());
        $this->assertSame($porCurso->id, $resultado->plano->id);
    }

    public function test_nivel_mais_turno_vence_so_curso(): void
    {
        $ano = $this->anoLectivo();
        $n1 = $this->nivel('N1');
        $curso = $this->curso('C1');
        $noite = $this->turno('Noite');
        $this->plano($ano, 'Por Curso', [], [['curso' => $curso]]);
        $nivelNoite = $this->plano($ano, 'Nivel e Noite', [], [['nivel' => $n1, 'turno' => $noite]]);

        $this->assertSame($nivelNoite->id, $this->resolver()->paraTurma($this->turma($ano, $n1, $curso, $noite))->plano->id);
    }

    public function test_cada_um_dos_nove_niveis_vence_o_de_baixo(): void
    {
        $ano = $this->anoLectivo();
        $n1 = $this->nivel('N1');
        $curso = $this->curso('C1');
        $noite = $this->turno('Noite');
        $turma = $this->turma($ano, $n1, $curso, $noite);

        // Do mais específico para o menos: cada um, quando é o único mais específico presente, ganha.
        $planos = [
            'turma' => $this->plano($ano, 'P1 Turma', [], [['turma' => $turma]]),
            'curso+nivel+turno' => $this->plano($ano, 'P2', [], [['curso' => $curso, 'nivel' => $n1, 'turno' => $noite]]),
            'curso+nivel' => $this->plano($ano, 'P3', [], [['curso' => $curso, 'nivel' => $n1]]),
            'curso+turno' => $this->plano($ano, 'P4', [], [['curso' => $curso, 'turno' => $noite]]),
            'nivel+turno' => $this->plano($ano, 'P5', [], [['nivel' => $n1, 'turno' => $noite]]),
            'curso' => $this->plano($ano, 'P6', [], [['curso' => $curso]]),
            'nivel' => $this->plano($ano, 'P7', [], [['nivel' => $n1]]),
            'turno' => $this->plano($ano, 'P8', [], [['turno' => $noite]]),
            'geral' => $this->plano($ano, 'P9'),
        ];

        foreach ($planos as $nome => $esperado) {
            $this->assertSame($esperado->id, $this->resolver()->paraTurma($turma)->plano->id, "deve ganhar: {$nome}");
            $esperado->update(['estado' => Estado::INATIVO->value]);
        }

        $this->assertFalse($this->resolver()->paraTurma($turma)->temPlano());
    }

    public function test_turma_especifica_so_aplica_a_essa_turma(): void
    {
        $ano = $this->anoLectivo();
        $n1 = $this->nivel('N1');
        $a = $this->turma($ano, $n1);
        $b = $this->turma($ano, $n1);
        $geral = $this->plano($ano, 'Geral');
        $daTurma = $this->plano($ano, 'So A', [], [['turma' => $a]]);

        $this->assertSame($daTurma->id, $this->resolver()->paraTurma($a)->plano->id);
        $this->assertSame($geral->id, $this->resolver()->paraTurma($b)->plano->id);
    }

    public function test_empate_real_entre_planos_distintos_e_conflito(): void
    {
        $ano = $this->anoLectivo();
        $n1 = $this->nivel('N1');
        // O Request impede este estado ao gravar; cria-se directamente no modelo
        // (por exemplo, um plano reactivado depois) para provar a rede de segurança.
        $a = $this->plano($ano, 'Nivel A', [], [['nivel' => $n1]]);
        $b = $this->plano($ano, 'Nivel B', [], [['nivel' => $n1]]);

        $resultado = $this->resolver()->paraTurma($this->turma($ano, $n1));

        $this->assertFalse($resultado->temPlano());
        $this->assertTrue($resultado->conflito);
        $this->assertNull($resultado->plano);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_map(fn ($p) => $p->id, $resultado->candidatos));
    }

    public function test_plano_inactivo_e_ignorado(): void
    {
        $ano = $this->anoLectivo();
        $n1 = $this->nivel('N1');
        $geral = $this->plano($ano, 'Geral');
        $this->plano($ano, 'Nivel', ['estado' => Estado::INATIVO->value], [['nivel' => $n1]]);

        $this->assertSame($geral->id, $this->resolver()->paraTurma($this->turma($ano, $n1))->plano->id);
    }

    public function test_planos_de_outro_ano_lectivo_sao_ignorados(): void
    {
        $a = $this->anoLectivo('2026/2027');
        $b = $this->anoLectivo('2027/2028', '2027-09-01', '2028-07-31');
        $this->plano($b, 'Geral B');

        $this->assertFalse($this->resolver()->paraTurma($this->turma($a, $this->nivel('N1')))->temPlano());
    }

    public function test_plano_com_varios_alvos_usa_o_alvo_que_casa_melhor(): void
    {
        $ano = $this->anoLectivo();
        $n1 = $this->nivel('N1');
        $n2 = $this->nivel('N2');
        $curso = $this->curso('C1');
        $multi = $this->plano($ano, 'Multi', [], [['nivel' => $n2], ['nivel' => $n1, 'curso' => $curso]]);
        $geral = $this->plano($ano, 'Geral');

        // N1+C1 casa o alvo mais específico do multi (curso+nível) e vence o geral.
        $this->assertSame($multi->id, $this->resolver()->paraTurma($this->turma($ano, $n1, $curso))->plano->id);
        // N2 casa o alvo só de nível, também acima do geral.
        $this->assertSame($multi->id, $this->resolver()->paraTurma($this->turma($ano, $n2))->plano->id);
        // N1 sem curso não casa nenhum alvo do multi: cai no geral.
        $this->assertSame($geral->id, $this->resolver()->paraTurma($this->turma($ano, $n1))->plano->id);
    }

    public function test_fases_de_preco_a_competencia_escolhe_o_plano_certo(): void
    {
        $ano = $this->anoLectivo(); // começa em 2026-09-01
        $n1 = $this->nivel('N1');
        $curso = $this->curso('C1');
        $fase1 = $this->plano($ano, 'Fase 1', ['mes_inicio' => 9, 'mes_fim' => 12], [['nivel' => $n1, 'curso' => $curso]]);
        $fase2 = $this->plano($ano, 'Fase 2', ['mes_inicio' => 1, 'mes_fim' => 6], [['nivel' => $n1, 'curso' => $curso]]);
        $turma = $this->turma($ano, $n1, $curso);

        $this->assertSame($fase1->id, $this->resolver()->paraTurma($turma, ['ano' => 2026, 'mes' => 10])->plano->id);
        $this->assertSame($fase2->id, $this->resolver()->paraTurma($turma, ['ano' => 2027, 'mes' => 2])->plano->id);
        $this->assertFalse($this->resolver()->paraTurma($turma, ['ano' => 2027, 'mes' => 7])->temPlano()); // fora de ambas
        $this->assertTrue($this->resolver()->paraTurma($turma)->conflito); // sem competência o tempo é ignorado
    }

    public function test_com_competencia_o_mais_especifico_so_ganha_nos_meses_em_que_existe(): void
    {
        $ano = $this->anoLectivo();
        $n1 = $this->nivel('N1');
        $geral = $this->plano($ano, 'Geral Ano Todo');
        $nivelSoNoInicio = $this->plano($ano, 'Nivel Set-Dez', ['mes_inicio' => 9, 'mes_fim' => 12], [['nivel' => $n1]]);
        $turma = $this->turma($ano, $n1);

        $this->assertSame($nivelSoNoInicio->id, $this->resolver()->paraTurma($turma, ['ano' => 2026, 'mes' => 11])->plano->id);
        $this->assertSame($geral->id, $this->resolver()->paraTurma($turma, ['ano' => 2027, 'mes' => 3])->plano->id);
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/ResolvePlanoAplicavelTest.php`
Expected: FAIL (classes inexistentes).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Support/ResultadoResolucaoPlano.php`:
```php
<?php

namespace Modules\Financeiro\Support;

use Modules\Financeiro\Models\PlanoPropina;

/**
 * Resultado de escolher o plano de propina de uma turma: um plano, nenhum, ou conflito
 * (dois ou mais planos com a mesma precedência). Nunca se escolhe um vencedor arbitrário.
 */
final class ResultadoResolucaoPlano
{
    /**
     * @param  list<PlanoPropina>  $candidatos
     */
    private function __construct(
        public readonly ?PlanoPropina $plano,
        public readonly bool $conflito,
        public readonly array $candidatos,
    ) {
    }

    public static function aplicavel(PlanoPropina $plano): self
    {
        return new self($plano, false, [$plano]);
    }

    public static function semPlano(): self
    {
        return new self(null, false, []);
    }

    /**
     * @param  list<PlanoPropina>  $candidatos
     */
    public static function conflito(array $candidatos): self
    {
        return new self(null, true, $candidatos);
    }

    public function temPlano(): bool
    {
        return $this->plano !== null && ! $this->conflito;
    }
}
```

`Modules/Financeiro/app/Services/ResolvePlanoAplicavel.php`:
```php
<?php

namespace Modules\Financeiro\Services;

use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Support\CalendarioDePlano;
use Modules\Financeiro\Support\Precedencia;
use Modules\Financeiro\Support\ResultadoResolucaoPlano;
use Modules\Turma\Models\Turma;

/**
 * Ponto único que decide que plano de propina se aplica a uma turma (e, opcionalmente, a uma
 * competência). Só entram planos activos do ano lectivo da turma. Ganha o de maior
 * Precedencia::rank (turma > nº de dimensões > curso > nível > turno); empate = conflito.
 */
class ResolvePlanoAplicavel
{
    /**
     * @param  array{ano: int, mes: int}|null  $competencia  null = ignora o tempo
     */
    public function paraTurma(Turma $turma, ?array $competencia = null): ResultadoResolucaoPlano
    {
        $candidatos = [];

        $planos = PlanoPropina::query()
            ->activos()
            ->where('ano_lectivo_id', $turma->ano_lectivo_id)
            ->with(['alvos', 'anoLectivo'])
            ->get();

        foreach ($planos as $plano) {
            if ($competencia !== null && ! CalendarioDePlano::contem($plano->competencias(), $competencia['ano'], $competencia['mes'])) {
                continue;
            }

            $rank = $this->melhorRank($plano, $turma);

            if ($rank !== null) {
                $candidatos[] = ['plano' => $plano, 'rank' => $rank];
            }
        }

        if ($candidatos === []) {
            return ResultadoResolucaoPlano::semPlano();
        }

        $maximo = $candidatos[0]['rank'];
        foreach ($candidatos as $candidato) {
            if (($candidato['rank'] <=> $maximo) > 0) {
                $maximo = $candidato['rank'];
            }
        }

        $topo = array_values(array_filter($candidatos, fn (array $c) => ($c['rank'] <=> $maximo) === 0));

        if (count($topo) === 1) {
            return ResultadoResolucaoPlano::aplicavel($topo[0]['plano']);
        }

        return ResultadoResolucaoPlano::conflito(array_map(fn (array $c) => $c['plano'], $topo));
    }

    /**
     * Melhor rank entre os alvos do plano que casam com a turma; null se nenhum casa.
     * Plano sem alvos = plano geral (rank do alvo vazio).
     *
     * @return array{0: int, 1: int, 2: int}|null
     */
    private function melhorRank(PlanoPropina $plano, Turma $turma): ?array
    {
        if ($plano->alvos->isEmpty()) {
            return Precedencia::rank([]);
        }

        $melhor = null;

        foreach ($plano->alvos as $alvo) {
            if (! $this->casa($alvo->nivel_academico_id, $turma->nivel_academico_id)
                || ! $this->casa($alvo->curso_id, $turma->curso_id)
                || ! $this->casa($alvo->turno_id, $turma->turno_id)
                || ! $this->casa($alvo->turma_id, $turma->id)) {
                continue;
            }

            $rank = Precedencia::rank($alvo->only(['nivel_academico_id', 'curso_id', 'turno_id', 'turma_id']));

            if ($melhor === null || ($rank <=> $melhor) > 0) {
                $melhor = $rank;
            }
        }

        return $melhor;
    }

    /**
     * Um campo do alvo não definido casa sempre; definido casa só se a turma tiver o mesmo
     * valor (uma turma sem esse campo nunca casa um alvo que o exige).
     */
    private function casa(?int $doAlvo, mixed $daTurma): bool
    {
        if ($doAlvo === null) {
            return true;
        }

        return $daTurma !== null && (int) $daTurma === $doAlvo;
    }
}
```
- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro`
Expected: PASS.

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro
```

---

### Task 7: Frontend — Planos de Propina (+ menus)

**Files:**
- Create: `Modules/Financeiro/resources/js/Components/PlanosPropina/PlanoPropinaFormModal.vue`
- Create: `Modules/Financeiro/resources/js/Pages/PlanosPropina/Index.vue`
- Modify: `resources/js/Composables/useConfiguracoesMenu.js`, `resources/js/Components/Layout/SidebarMenuWrapper.vue`

**Interfaces:**
- Consumes: componente `Financeiro/PlanosPropina/Index` e as props/payloads da Task 5; `formatarDinheiro`, `unidadesMenoresParaDecimal` (`Support/dinheiro.js`, recebem a `moeda` da prop; mesmo padrão de `Pages/ProdutosServicos/Index.vue` e `Components/ProdutosServicos/CatalogoItemFormModal.vue`); `EstadoBadge`, `Estado.js`; componentes partilhados `AppLayout`, `AcaoIcone`, `ConfirmModal`, `SelectSolid`, `Pagination`; `can()`.

- [ ] **Step 1: Modal do formulário**

`Modules/Financeiro/resources/js/Components/PlanosPropina/PlanoPropinaFormModal.vue`:
```vue
<script setup>
import { computed, reactive, watch } from 'vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import { unidadesMenoresParaDecimal } from '../../Support/dinheiro';

const OUTRA = 0;

const props = defineProps({
    show: { type: Boolean, default: false },
    plano: { type: Object, default: null },
    anosLectivos: { type: Array, required: true },
    niveis: { type: Array, required: true },
    cursos: { type: Array, required: true },
    turnos: { type: Array, required: true },
    turmas: { type: Array, required: true },
    periodicidades: { type: Array, required: true },
    moeda: { type: Object, required: true },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['submit', 'cancelar']);

const placeholderValor = computed(() => props.moeda.decimais === 0
    ? 'ex: 25000'
    : 'ex: 25000 ou 25000,' + '50'.padEnd(props.moeda.decimais, '0').slice(0, props.moeda.decimais));

const MESES = [
    'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
    'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro',
].map((label, indice) => ({ value: indice + 1, label }));

const form = reactive({
    ano_lectivo_id: '',
    nome: '',
    descricao: '',
    periodicidade: 1,
    intervalo_meses: '',
    valor: '',
    mes_inicio: 9,
    mes_fim: 6,
    alvos: [],
});

const opcoesAno = computed(() => props.anosLectivos.map((a) => ({ value: a.id, label: a.nome })));
const opcoesNivel = computed(() => [{ value: '', label: 'Todos os níveis' }, ...props.niveis.map((n) => ({ value: n.id, label: n.nome }))]);
const opcoesCurso = computed(() => [{ value: '', label: 'Todos os cursos' }, ...props.cursos.map((c) => ({ value: c.id, label: c.nome }))]);
const opcoesTurno = computed(() => [{ value: '', label: 'Todos os turnos' }, ...props.turnos.map((t) => ({ value: t.id, label: t.nome }))]);
const opcoesTurma = computed(() => [
    { value: '', label: 'Nenhuma (usar nível/curso/turno)' },
    ...props.turmas.filter((t) => t.ano_lectivo_id === form.ano_lectivo_id).map((t) => ({ value: t.id, label: t.nome })),
]);

watch(() => props.show, (show) => {
    if (!show) return;
    form.ano_lectivo_id = props.plano?.ano_lectivo_id ?? props.anosLectivos[0]?.id ?? '';
    form.nome = props.plano?.nome ?? '';
    form.descricao = props.plano?.descricao ?? '';
    form.periodicidade = props.plano?.periodicidade ?? 1;
    form.intervalo_meses = props.plano && props.plano.periodicidade === OUTRA ? props.plano.intervalo_meses : '';
    form.valor = props.plano ? unidadesMenoresParaDecimal(props.plano.valor, props.moeda) : '';
    form.mes_inicio = props.plano?.mes_inicio ?? 9;
    form.mes_fim = props.plano?.mes_fim ?? 6;
    form.alvos = (props.plano?.alvos ?? []).map((a) => ({
        nivel_academico_id: a.nivel_academico_id ?? '',
        curso_id: a.curso_id ?? '',
        turno_id: a.turno_id ?? '',
        turma_id: a.turma_id ?? '',
    }));
});

function adicionarAlvo() {
    form.alvos.push({ nivel_academico_id: '', curso_id: '', turno_id: '', turma_id: '' });
}

function removerAlvo(indice) {
    form.alvos.splice(indice, 1);
}

// Uma turma específica é exclusiva: escolhê-la limpa nível, curso e turno, e vice-versa.
function definirCampo(alvo, campo, valor) {
    alvo[campo] = valor;

    if (valor === '') return;

    if (campo === 'turma_id') {
        alvo.nivel_academico_id = '';
        alvo.curso_id = '';
        alvo.turno_id = '';
    } else {
        alvo.turma_id = '';
    }
}

const vazioParaNulo = (valor) => (valor === '' ? null : valor);

function submeter() {
    const payload = {
        nome: form.nome,
        descricao: form.descricao,
        periodicidade: form.periodicidade,
        valor: form.valor,
        mes_inicio: form.mes_inicio,
        mes_fim: form.mes_fim,
        alvos: form.alvos.map((a) => ({
            nivel_academico_id: vazioParaNulo(a.nivel_academico_id),
            curso_id: vazioParaNulo(a.curso_id),
            turno_id: vazioParaNulo(a.turno_id),
            turma_id: vazioParaNulo(a.turma_id),
        })),
    };

    if (form.periodicidade === OUTRA) payload.intervalo_meses = form.intervalo_meses;
    if (!props.plano) payload.ano_lectivo_id = form.ano_lectivo_id;

    emit('submit', payload);
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5); overflow-y: auto;" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content p-6">
                <h3 class="mb-5">{{ plano ? 'Editar Plano de Propina' : 'Novo Plano de Propina' }}</h3>
                <form @submit.prevent="submeter">
                    <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Nome</label>
                            <input v-model="form.nome" type="text" class="form-control form-control-solid" placeholder="ex: Propina Informática 11.ª" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.nome">{{ errors.nome }}</div>
                        </div>
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Ano Lectivo</label>
                            <input v-if="plano" type="text" class="form-control form-control-solid" :value="plano.ano_lectivo_nome" disabled />
                            <SelectSolid v-else v-model="form.ano_lectivo_id" :options="opcoesAno" />
                            <div class="form-text" v-if="plano">O ano lectivo não pode ser alterado.</div>
                            <div class="text-danger fs-7 mt-1" v-if="errors.ano_lectivo_id">{{ errors.ano_lectivo_id }}</div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Periodicidade</label>
                            <SelectSolid v-model="form.periodicidade" :options="periodicidades" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.periodicidade">{{ errors.periodicidade }}</div>
                        </div>
                        <div v-if="form.periodicidade === OUTRA" class="col-md-4 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Intervalo (meses)</label>
                            <input v-model.number="form.intervalo_meses" type="number" min="1" max="12" class="form-control form-control-solid" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.intervalo_meses">{{ errors.intervalo_meses }}</div>
                        </div>
                        <div class="col-md-4 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Valor por período ({{ moeda.simbolo }})</label>
                            <input v-model="form.valor" type="text" inputmode="decimal" class="form-control form-control-solid" :placeholder="placeholderValor" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.valor">{{ errors.valor }}</div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Mês de início</label>
                            <SelectSolid v-model="form.mes_inicio" :options="MESES" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.mes_inicio">{{ errors.mes_inicio }}</div>
                        </div>
                        <div class="col-md-4 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Mês de fim</label>
                            <SelectSolid v-model="form.mes_fim" :options="MESES" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.mes_fim">{{ errors.mes_fim }}</div>
                        </div>
                        <div class="col-md-4 d-flex align-items-end mb-7">
                            <div class="form-text">
                                O período pode atravessar o ano civil (ex.: Setembro → Junho). Para mudar o preço a meio do ano,
                                crie outro plano com os mesmos alvos e o período seguinte.
                            </div>
                        </div>
                    </div>

                    <div class="fv-row mb-7">
                        <label class="fw-semibold fs-6 mb-2">Descrição</label>
                        <textarea v-model="form.descricao" class="form-control form-control-solid" rows="2"></textarea>
                        <div class="text-danger fs-7 mt-1" v-if="errors.descricao">{{ errors.descricao }}</div>
                    </div>

                    <div class="fv-row mb-7">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label class="fw-semibold fs-6">Aplica-se a</label>
                            <button type="button" class="btn btn-sm btn-light-primary" @click="adicionarAlvo">Adicionar alvo</button>
                        </div>
                        <div v-if="form.alvos.length === 0" class="text-muted fs-7">
                            Sem alvos: plano geral, aplica-se a todas as turmas do ano lectivo.
                        </div>
                        <div v-else class="text-muted fs-7 mb-2">
                            Vence o alvo mais específico: turma &gt; mais campos preenchidos &gt; curso &gt; nível &gt; turno. Uma turma específica não se combina com os outros campos.
                        </div>
                        <div v-for="(alvo, indice) in form.alvos" :key="indice" class="row g-3 align-items-center mb-2">
                            <div class="col-md-2"><SelectSolid :model-value="alvo.nivel_academico_id" :options="opcoesNivel" @update:model-value="(v) => definirCampo(alvo, 'nivel_academico_id', v)" /></div>
                            <div class="col-md-3"><SelectSolid :model-value="alvo.curso_id" :options="opcoesCurso" @update:model-value="(v) => definirCampo(alvo, 'curso_id', v)" /></div>
                            <div class="col-md-2"><SelectSolid :model-value="alvo.turno_id" :options="opcoesTurno" @update:model-value="(v) => definirCampo(alvo, 'turno_id', v)" /></div>
                            <div class="col-md-3"><SelectSolid :model-value="alvo.turma_id" :options="opcoesTurma" @update:model-value="(v) => definirCampo(alvo, 'turma_id', v)" /></div>
                            <div class="col-md-2 text-end">
                                <button type="button" class="btn btn-sm btn-light-danger" @click="removerAlvo(indice)">Remover</button>
                            </div>
                        </div>
                        <div class="text-danger fs-7 mt-1" v-if="errors.alvos">{{ errors.alvos }}</div>
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

- [ ] **Step 2: Página**

`Modules/Financeiro/resources/js/Pages/PlanosPropina/Index.vue`:
```vue
<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import ConfirmModal from '@/Components/Shared/ConfirmModal.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import EstadoBadge from '../../Components/Shared/EstadoBadge.vue';
import PlanoPropinaFormModal from '../../Components/PlanosPropina/PlanoPropinaFormModal.vue';
import { ESTADO } from '../../Models/Estado';
import { formatarDinheiro } from '../../Support/dinheiro';

const BASE = '/financeiro/configuracao/planos-propina';
const MESES_CURTOS = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

const props = defineProps({
    planos: { type: Object, required: true }, // paginador: { data, links, ... }
    filtros: { type: Object, default: () => ({}) },
    anosLectivos: { type: Array, required: true },
    niveis: { type: Array, required: true },
    cursos: { type: Array, required: true },
    turnos: { type: Array, required: true },
    turmas: { type: Array, required: true },
    periodicidades: { type: Array, required: true },
    moeda: { type: Object, required: true },
});
defineOptions({ layout: AppLayout });

const opcoesEstado = computed(() => [
    { value: '', label: 'Todos os estados' },
    { value: ESTADO.ATIVO, label: 'Ativo' },
    { value: ESTADO.INATIVO, label: 'Inativo' },
]);
const opcoesAno = computed(() => [{ value: '', label: 'Todos os anos lectivos' }, ...props.anosLectivos.map((a) => ({ value: a.id, label: a.nome }))]);

const numeroOuVazio = (valor) => (valor !== undefined && valor !== null && valor !== '' ? Number(valor) : '');

const filtros = reactive({
    pesquisa: props.filtros.pesquisa ?? '',
    estado: numeroOuVazio(props.filtros.estado),
    ano_lectivo_id: numeroOuVazio(props.filtros.ano_lectivo_id),
});

let debounceId = null;
watch(filtros, (valor) => {
    clearTimeout(debounceId);
    debounceId = setTimeout(() => {
        router.get(BASE, valor, { preserveState: true, preserveScroll: true, replace: true });
    }, 300);
});

function periodoTexto(plano) {
    return `${MESES_CURTOS[plano.mes_inicio - 1]} → ${MESES_CURTOS[plano.mes_fim - 1]}`;
}

function alvoTexto(alvo) {
    if (alvo.turma_id) return `Turma ${alvo.turma_nome}`;

    return [alvo.curso_nome, alvo.nivel_nome, alvo.turno_nome].filter(Boolean).join(' · ');
}

function alvosTexto(plano) {
    return plano.alvos.length === 0 ? 'Todas as turmas' : plano.alvos.map(alvoTexto).join('; ');
}

const modalAberto = ref(false);
const planoEmEdicao = ref(null);
const processing = ref(false);
const errors = ref({});

function abrirCriacao() {
    planoEmEdicao.value = null;
    errors.value = {};
    modalAberto.value = true;
}

function abrirEdicao(plano) {
    planoEmEdicao.value = plano;
    errors.value = {};
    modalAberto.value = true;
}

function guardar(payload) {
    processing.value = true;
    errors.value = {};

    const edicao = planoEmEdicao.value;
    const url = edicao ? `${BASE}/${edicao.id}` : BASE;

    router[edicao ? 'put' : 'post'](url, payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(edicao ? 'Plano de propina atualizado com sucesso.' : 'Plano de propina criado com sucesso.');
            modalAberto.value = false;
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

const paraEstado = ref(null);
const novoEstado = ref(null);
const paraEliminar = ref(null);
const aProcessar = ref(false);

function confirmarEstado() {
    aProcessar.value = true;
    router.patch(`${BASE}/${paraEstado.value.id}/estado`, { estado: novoEstado.value }, {
        preserveScroll: true,
        onSuccess: () => toast.success('Estado do plano de propina atualizado com sucesso.'),
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            aProcessar.value = false;
            paraEstado.value = null;
            novoEstado.value = null;
        },
    });
}

function confirmarEliminacao() {
    aProcessar.value = true;
    router.delete(`${BASE}/${paraEliminar.value.id}`, {
        preserveScroll: true,
        onSuccess: () => toast.success('Plano de propina eliminado com sucesso.'),
        onError: (erros) => toast.error(Object.values(erros)[0]),
        onFinish: () => {
            aProcessar.value = false;
            paraEliminar.value = null;
        },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="d-flex justify-content-between align-items-center mb-6">
            <div>
                <h1 class="fs-2 fw-bold mb-1">Planos de Propina</h1>
                <p class="text-muted fs-6 mb-0" style="max-width: 720px">
                    Definem como a escola cobra a propina em cada ano lectivo, por nível, curso, turno ou turma. Um plano é uma
                    configuração: não cria cobranças. Quando vários planos se aplicam, vence o mais específico (ver "Precedência").
                </p>
            </div>
            <button v-if="can('plano-propina.criar')" class="btn btn-primary" @click="abrirCriacao">Novo Plano</button>
        </div>

        <div class="card mb-6">
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-6 col-md-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">Pesquisa</label>
                        <input v-model="filtros.pesquisa" type="text" class="form-control form-control-solid" placeholder="Nome" />
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">Ano Lectivo</label>
                        <SelectSolid v-model="filtros.ano_lectivo_id" :options="opcoesAno" />
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">Estado</label>
                        <SelectSolid v-model="filtros.estado" :options="opcoesEstado" />
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                    <thead>
                        <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                            <th class="min-w-150px">Nome</th>
                            <th class="min-w-100px">Ano Lectivo</th>
                            <th class="min-w-100px">Periodicidade</th>
                            <th class="text-end min-w-125px">Valor</th>
                            <th class="min-w-125px">Período</th>
                            <th class="min-w-175px">Aplica-se a</th>
                            <th class="min-w-150px">Precedência</th>
                            <th class="min-w-100px">Estado</th>
                            <th class="text-end min-w-125px">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600 fw-semibold">
                        <tr v-if="planos.data.length === 0">
                            <td colspan="9" class="text-center text-muted py-6">Nenhum plano de propina encontrado.</td>
                        </tr>
                        <tr v-for="plano in planos.data" :key="plano.id">
                            <td class="text-gray-800">{{ plano.nome }}</td>
                            <td>{{ plano.ano_lectivo_nome ?? '—' }}</td>
                            <td>{{ plano.periodicidade_descricao }}</td>
                            <td class="text-end">{{ formatarDinheiro(plano.valor, moeda) }}</td>
                            <td>{{ periodoTexto(plano) }} <span class="text-muted fs-7">({{ plano.periodos_total }} períodos)</span></td>
                            <td>{{ alvosTexto(plano) }}</td>
                            <td><span class="badge badge-light-info">{{ plano.precedencia }}</span></td>
                            <td><EstadoBadge :estado="plano.estado" :estado-descricao="plano.estado_descricao" /></td>
                            <td class="text-end">
                                <a href="#" class="btn btn-light btn-active-light-primary btn-flex btn-center btn-sm" data-kt-menu-trigger="click" data-kt-menu-placement="bottom-end">
                                    Ações
                                    <i class="ki-duotone ki-down fs-5 ms-1"></i>
                                </a>
                                <div class="menu menu-sub menu-sub-dropdown menu-column menu-rounded menu-gray-600 menu-state-bg-light-primary fw-semibold fs-7 w-200px py-4" data-kt-menu="true">
                                    <div v-if="can('plano-propina.editar')" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="abrirEdicao(plano)">
                                            <AcaoIcone acao="editar" class="me-2" /> Editar
                                        </a>
                                    </div>
                                    <div v-if="can('plano-propina.editar') && plano.estado !== ESTADO.ATIVO" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="paraEstado = plano; novoEstado = ESTADO.ATIVO">
                                            <AcaoIcone acao="ativar" class="me-2" /> Ativar
                                        </a>
                                    </div>
                                    <div v-if="can('plano-propina.editar') && plano.estado === ESTADO.ATIVO" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="paraEstado = plano; novoEstado = ESTADO.INATIVO">
                                            <AcaoIcone acao="desativar" class="me-2" /> Desativar
                                        </a>
                                    </div>
                                    <div v-if="can('plano-propina.eliminar')" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="paraEliminar = plano">
                                            <AcaoIcone acao="eliminar" class="me-2" /> Eliminar
                                        </a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="planos.data.length" class="card-footer d-flex justify-content-end">
                <Pagination :links="planos.links" />
            </div>
        </div>

        <PlanoPropinaFormModal
            :show="modalAberto"
            :plano="planoEmEdicao"
            :anos-lectivos="anosLectivos"
            :niveis="niveis"
            :cursos="cursos"
            :turnos="turnos"
            :turmas="turmas"
            :periodicidades="periodicidades"
            :moeda="moeda"
            :processing="processing"
            :errors="errors"
            @submit="guardar"
            @cancelar="modalAberto = false"
        />

        <ConfirmModal
            :show="!!paraEstado"
            titulo="Alterar estado"
            :mensagem="`Alterar o estado de ${paraEstado?.nome} para '${novoEstado === ESTADO.ATIVO ? 'Ativo' : 'Inativo'}'?`"
            texto-confirmar="Confirmar"
            :processando="aProcessar"
            @confirmar="confirmarEstado"
            @cancelar="paraEstado = null; novoEstado = null"
        />

        <ConfirmModal
            :show="!!paraEliminar"
            titulo="Eliminar plano de propina"
            :mensagem="`Eliminar ${paraEliminar?.nome}? Se já tiver registos financeiros, desative-o em vez disso.`"
            :processando="aProcessar"
            @confirmar="confirmarEliminacao"
            @cancelar="paraEliminar = null"
        />
    </div>
</template>
```

- [ ] **Step 3: Menus — os DOIS sítios**

Em `resources/js/Composables/useConfiguracoesMenu.js`, no grupo `Financeiro`, **antes** de "Regras de Cobrança" (ordem do spec: Planos de Propina, Regras de Cobrança, Métodos de Pagamento, Produtos / Serviços):
```js
            { href: '/financeiro/configuracao/planos-propina', label: 'Planos de Propina', permissao: 'plano-propina.ver' },
```
Em `resources/js/Components/Layout/SidebarMenuWrapper.vue`, array `configuracoesMenu`, grupo `Financeiro`, antes de "Regras de Cobrança":
```js
            { href: '/financeiro/configuracao/planos-propina', title: 'Planos de Propina', permissao: 'plano-propina.ver' },
```

- [ ] **Step 4: Build e stage**

Run: `npm run build`
Expected: sem erros; nada gerado (`public/build`) em stage.

```bash
git add Modules/Financeiro/resources resources/js/Composables/useConfiguracoesMenu.js resources/js/Components/Layout/SidebarMenuWrapper.vue
```

(Verificação visual no browser: do controlador, fora da dispatch.)

---

### Task 8: Verificação final

- [ ] **Step 1: Suite completa**

Run: `php artisan test` (timeout alargado, ~150 s)
Expected: 0 falhas. Falhas fora do módulo são regressões deste plano (em especial a matriz de isolamento, que cria os models novos "em cru", e contagens de módulos de permissão): corrigir seguindo a convenção, sem enfraquecer nenhum teste, e documentar quais e porquê.

- [ ] **Step 2: Build**

Run: `npm run build`
Expected: sucesso.

- [ ] **Step 3: Sem moeda fixa**

Run: `grep -rniE "kz|kwanza|centimo|cêntimo|\* 100|% 100" Modules/Financeiro/app/Support/CalendarioDePlano.php Modules/Financeiro/app/Support/PrecosDosPlanos.php Modules/Financeiro/app/Http/Requests/Concerns/ValidaPlanoPropina.php Modules/Financeiro/app/DTO/PlanoPropinaDTO.php Modules/Financeiro/resources/js/Pages/PlanosPropina Modules/Financeiro/resources/js/Components/PlanosPropina`
Expected: sem resultados (a moeda só aparece no registo `Moeda` e no seeder; nos testes, apenas o comentário a declarar o AOA por omissão).

- [ ] **Step 4: Estado do git**

Run: `git status --short`
Expected: só ficheiros deste plano em stage, nada gerado, nenhum commit. Informar o dono de que falta (a) `php artisan migrate`, (b) `php artisan db:seed --force` (módulo 20), (c) `php artisan financeiro:sincronizar --todos`, e (d) a verificação visual no browser (incluindo que, com um plano criado, a troca de moeda em Moeda e Câmbio fica bloqueada).
