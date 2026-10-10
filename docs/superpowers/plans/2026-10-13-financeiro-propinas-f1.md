# Financeiro → Propinas — F1: Fundação do domínio Propina — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Criar a base de dados e o domínio das propinas operacionais **sem as gerar**: tabela `propinas` com snapshot completo e garantias contra cobrança dupla, model `Propina` com invariantes, estado resolvido em PHP e em SQL equivalentes, `RecalcularPropina` como único escritor de `valor_pago`/`estado`/`capital_liquidado_em`, protecção de planos e da moeda por referências financeiras, bloqueio do valor e do calendário de planos com propinas, permissão `propina`, resumo de períodos no ecrã de Planos (Q3) e a infra-estrutura de testes PostgreSQL.

**Architecture:** Tudo no módulo `Financeiro` (models/serviços planos; páginas e componentes por entidade). `Propina` usa `PertenceAoTenant` + `RegistaAutoria`, um hook `saving` null-safe para as descrições e hooks `creating`/`updating`/`deleting` que repetem no PHP as invariantes que o PostgreSQL também impõe por CHECK. As datas civis usam um cast próprio (`DataCast`) que grava sempre `Y-m-d`, para SQLite e PostgreSQL compararem e indexarem igual. `EstadoCobranca::resolver()` (PHP puro) e `Propina::scopeComEstadoResolvido()` (SQL sem `DB::raw`, só comparações de datas com `data_limite`) são testados juntos. `RecalcularPropina` soma as contribuições de um contrato etiquetado (`FonteDePagamentoDePropina`, sem implementações em produção até Pagamentos) sob `lockForUpdate` dentro da transacção do chamador. `PropinasReferenciam` (`ReferenciaFinanceira`) protege planos e moeda. A grelha de permissões ganha `Modulo::PROPINA` com acções próprias. A suite normal continua em SQLite; os testes `#[Group('pgsql')]` correm à parte contra `mositec_escola_test`.

**Tech Stack:** Laravel 12.69 + nwidart/laravel-modules, Inertia + Vue 3, PHPUnit 11.5 com SQLite em memória (produção PostgreSQL), Metronic/Bootstrap.

**Fontes de verdade:** `docs/superpowers/plans/2026-10-12-financeiro-propinas-fases.md` (F1, §1–§4, §8 — Q1–Q15 aprovadas com as recomendações e as clarificações de §8.1), `docs/superpowers/specs/2026-10-08-financeiro-propinas-design.md` (§2–§6, §8, §11, §12; §14/§15 como contexto), `docs/superpowers/specs/2026-10-08-financeiro-pagamentos-design.md` (consumidor de `RecalcularPropina`), `docs/superpowers/specs/2026-10-08-modulo-financeiro-configuracao-design.md` (contrato de snapshot "Moeda e Câmbio", `ReferenciaFinanceira`, `FonteDePrecos`).

## Restrições globais

- Texto de UI, mensagens e comentários em PT-PT com acentos; identificadores, rotas, tabelas e variáveis sem acento.
- Camada fina (Controller → Service de leitura / Action de escrita). `use` no topo, nunca FQN inline (também em testes).
- Dinheiro: `bigInteger` em unidades menores, `Dinheiro` + `DinheiroCast`, nunca float. Moeda de `MoedaDoTenant`.
- Tenancy: `PertenceAoTenant` em todos os models novos; `tenant_id` nunca preenchível em massa nem vindo do cliente; tabela nova com `tenant_id` NOT NULL e FK para `tenants` (`TenancyEsquemaTest`).
- **Proibido** em `Modules/*/app` (`TenancyArquitecturaTest`): `insert`, `insertOrIgnore`, `insertGetId`, `upsert`, `DB::table|select|statement|raw|connection|…`, `*Quietly`, `withoutEvents`, `withoutGlobalScope(s)`. **Migrations e testes estão fora do varrimento** (o varrimento só lê `app`, `routes`, `database/seeders`): o SQL cru (índices parciais, CHECK) vive só na migration, e os testes pgsql podem usar `DB::table` para atacar a base directamente.
- `estado` + `estado_descricao` (e `origem_geracao` + `origem_geracao_descricao`); hooks `saving` **null-safe** (a `MatrizIsolamentoTest` grava `(new Propina())->forceFill([])->save()` sem contexto e espera `TenantNaoResolvido`). Validações que precisam de dados correm em `creating`/`updating`, que disparam **depois** da verificação de tenant da trait.
- Dentro de `DB::transaction` não se apanham excepções para continuar (PostgreSQL aborta a transacção).
- Nada desta fase gera, lista, cancela, anula ou reajusta propinas por acção do utilizador: **sem rotas novas, sem menu** (F2–F5).
- `migrate`, `db:seed --force`, `financeiro:sincronizar --todos` e a criação da base `mositec_escola_test` são do dono. **Nunca correr nada contra a base de desenvolvimento `mositec_escola`.**
- **Git: só `git add`. Nunca `git commit`.**

## Decisões e pressupostos desta fase (ler antes de implementar)

| # | Decisão | Porquê |
|---|---|---|
| D1 | **Não se cria `PrecosDasPropinas` (`FonteDePrecos`).** O bloqueio da moeda por propinas é feito por `PropinasReferenciam` respondendo `true` para a `ConfiguracaoMonetaria` quando existe qualquer propina. | O contrato `ReferenciaFinanceira` exige-o literalmente para registos operacionais ("Para a ConfiguracaoMonetaria deve devolver true se existir QUALQUER registo operacional"); `FonteDePrecos` é para **preços de configuração** e o plano de fases (§2) diz "Propinas são registos operacionais → `ReferenciaFinanceira`, não `FonteDePrecos`". Ter os dois seria duas fontes de verdade para o mesmo facto. Hoje é redundante com `PrecosDosPlanos` (não há propina sem plano), mas protege quando um plano deixar de existir. Se o dono quiser mesmo a classe, é uma classe de 5 linhas etiquetada em `FontesDePrecos::ETIQUETA`. |
| D2 | **As duas unicidades são parciais** (`WHERE estado IN (1,2,3)`): `(tenant_id, matricula_id, plano_propina_id, ordem)` e `(tenant_id, matricula_id, periodo_inicio)`. | **Contradição no spec §3:** com `unique(tenant_id, matricula_id, plano_propina_id, ordem)` total, regerar o mesmo período do mesmo plano depois de cancelar (exigido em F2/F3: "regerar depois de cancelar cria nova propina") violaria a unicidade. Com ambas parciais, Cancelada/Anulada ficam fora de ambas. O texto do spec é corrigido e a lacuna nova **L21** registada no plano de fases (Task 3, Step 6). |
| D3 | Cast próprio `DataCast` (grava `Y-m-d`) para `periodo_inicio`, `periodo_fim`, `data_vencimento`, `data_limite`, `capital_liquidado_em`. | Convenção do projecto (`Cambio`, `CambioPlataforma`, `AnoLectivo`, `Periodo`): `date:Y-m-d`. **Medido em SQLite:** esse cast só grava `Y-m-d` quando recebe exactamente esse texto; um `Carbon`/`CarbonImmutable` (ou texto com hora) é gravado como `Y-m-d H:i:s` **com a hora** (`Carbon::parse('2026-09-15 18:30')` → `'2026-09-15 18:30:00'`) — o sufixo `:Y-m-d` só afecta a serialização. Os models existentes só recebem texto validado (`date_format:Y-m-d`) e o `CambioDoDia` compensa com `whereDate`. A `Propina` grava datas calculadas como `Carbon` (`data_limite` no `creating`, `capital_liquidado_em` vindo de `ContribuicaoDePagamento`, períodos em F2): com `date:Y-m-d`, `'2026-09-15 00:00:00' < '2026-09-15'` é falso (parte o scope na fronteira da tolerância) e o índice único parcial deixaria de colidir entre um valor com e outro sem hora. Com `DataCast` as comparações `where('data_limite', '<', 'Y-m-d')` são correctas e indexáveis nos dois motores, sem `whereDate`, seja qual for o tipo do valor atribuído. (Alternativa rejeitada: `date:Y-m-d` + `->toDateString()` obrigatório em cada escritor, sem guarda.) |
| D4 | Contrato de contribuições com o nome do pedido: `Contracts\FonteDePagamentoDePropina` + agregador `Support\FontesDePagamentoDePropina` (etiqueta `financeiro.fontes-pagamento-propina`), "implementação por omissão vazia" = sem nenhuma etiquetada o agregador devolve `[]`. Cada contribuição é um `ContribuicaoDePagamento(Dinheiro $valor > 0, CarbonImmutable $data)`. | Substitui o `ContribuiParaValorPago` do plano de fases (mesmo papel). `HistoricoDePagamentosDaPropina` **fica para F3** (não tem consumidor em F1). |
| D5 | CHECK em pgsql mais completos do que o mínimo pedido: além de `valor > 0` e `0 ≤ valor_pago ≤ valor`, coerência estado ↔ valor pago, `capital_liquidado_em` ↔ saldo zero, `cancelado_em`/`anulado_em` ↔ estado, datas, ordem, origem, moeda, câmbio. O model repete **as mesmas** regras (SQLite não tem CHECK). | Torna a tradução SQL do estado resolvido provadamente segura em produção. Verificado contra os fluxos do spec: cancelar e anular (§8) exigem `valor_pago = 0`; o cancelamento automático por evento de matrícula só toca propinas sem pagamentos; Paga ↔ Parcial ↔ Em Aberto (anulação de pagamentos) é sempre uma gravação do `RecalcularPropina` com `capital_liquidado_em` acertado. **Consequência para F4:** mudar `valor` e `estado` tem de ser **um único UPDATE** (descer o preço até ou abaixo do pago, §14 "valor = valor_pago, passa a Paga", exige `valor`, `estado = Paga` e `capital_liquidado_em` na mesma gravação — o model recusa o mesmo passo intermédio também em SQLite): F4 acrescenta a `RecalcularPropina` um método que recebe o valor novo, em vez de gravar o valor e recalcular depois. |
| D6 | Coluna extra `motivo_geracao` (text, nula). Origem `RETROACTIVA` exige motivo. | §8.1/Q4: a geração retroactiva "regista autor e motivo"; acrescentar agora evita um `ALTER` com dados em F2. |
| D7 | Acções aplicáveis a `PROPINA`: `ver, listar, criar, cancelar, anular, exportar, ajustar` (sem `editar`, `eliminar`, `negociar`, `isentar-multa`). ADMIN_ESCOLA em F1: `ver, listar, criar, cancelar, exportar`. | Pedido explícito (o plano de fases listava também `isentar-multa`; entra em F5 com a funcionalidade). |
| D8 | A recusa de mudar `valor`/calendário de um plano com propinas entra **já** em `AtualizarPlanoPropinaAction` (F1, não F4), com mensagem sem referir uma operação que ainda não existe. "Ter propinas" = qualquer propina, em qualquer estado (L18). | Pedido explícito (e Q10). |
| D9 | O `creating` do model consulta a matrícula e o plano (2 consultas) para garantir mesmo tenant e mesmo ano lectivo. | Defesa em profundidade da invariante de §3 (R10). Custo aceitável na geração em massa de F2 (medir lá). |
| D10 | `capital_liquidado_em` = data da contribuição que levou o acumulado (por data crescente; empates pela ordem devolvida pelas fontes — `usort` é estável) a igualar o valor. | §6. Ids de tabelas diferentes (distribuições vs créditos) não são comparáveis; a ordem por data é a regra de negócio. |
| D11 | `cambio_usd` = `CambioDoDia::para(data de criação)` (micros de `TaxaCambio`, "1 USD = X"), nulo sem câmbio; `moeda` = `MoedaDoTenant::atual()`. Exposto como `SnapshotMonetario::em($data)` para F2 reutilizar. Ambos imutáveis. | Q1. A moeda está bloqueada enquanto houver planos (e propinas), por isso todas as propinas de uma escola têm a moeda corrente da escola; o teste de reconciliação verifica-o. |
| D12 | `RecalcularPropina` exige transacção aberta (`DB::transactionLevel() > 0`). Em testes o `RefreshDatabase` já abre uma; o teste do caso "sem transacção" sai dela com `DB::rollBack()` antes de chamar o serviço. | É a única forma de provar a guarda sem `DatabaseMigrations` (cujo `migrate:rollback` em SQLite não é garantido para todas as migrations). |
| D13 | Testes pgsql correm com `vendor/bin/phpunit`, **não** `php artisan test`. | O `php artisan test` (Collision) limpa do ambiente todas as chaves do `.env` antes de lançar o PHPUnit, incluindo `DB_CONNECTION`/`DB_DATABASE` passados na linha de comando, e a ligação voltaria a SQLite (os testes seriam ignorados). |

**Não provado em SQLite (só no grupo pgsql):** CHECK; `lockForUpdate` (SQLite ignora `FOR UPDATE`); semântica do índice parcial em PostgreSQL; ida e volta de `bigint`/`date` no PostgreSQL. A concorrência real (duas ligações) fica para F4.

## Revisão pré-voo (2026-10-10)

Plano executado na íntegra numa worktree descartável (T1–T11, incluindo `npm run build`) e corrido em SQLite (suite completa: 2457 testes verdes) e num PostgreSQL 16 descartável (porta própria, base `mositec_escola_test` criada só lá; grupo `pgsql` 5/5 verde e todos os ficheiros de teste novos de F1 verdes também em pgsql). Correcções já aplicadas a este documento:
- T1: `DataCast::serialize` recebia o valor **cru** (texto) e chamava `->toDateString()` → erro; passa a devolver os 10 primeiros caracteres. Contagem do Step 4: 9 testes (não 7).
- D3: fundamentação corrigida com o comportamento medido do `date:Y-m-d` (ver D3). O `DataCast` mantém-se.
- T3: asserções de violação de unicidade portáveis (mensagem do PostgreSQL ou do SQLite); novo Step 6 para corrigir o texto do spec §3 e registar L21 no plano de fases (D2 pedia-o, mas nenhum passo o fazia).
- T5: teste de idempotência do `RecalcularPropina` (segunda execução não grava nada).
- D5: a consequência para F4 inclui `capital_liquidado_em` no mesmo UPDATE.

## Fora de âmbito (F2+)

Geração (`GerarPropinasService`, comando, job), lista e detalhe de propinas, menu Financeiro (operação), cancelar/anular, evento de matrícula, `DependenciasDaMatricula`, alteração de preço e `propina_ajustes`, multas, `HistoricoDePagamentosDaPropina`. Nenhuma rota nova.

## Review Focus

1. Estado resolvido: PHP e SQL devolvem exactamente os mesmos conjuntos para todos os 7 estados e todas as datas do cenário, incluindo a fronteira `hoje = data_limite` (não atrasa) e `+1 dia` (atrasa), e Pendente vs Em Aberto no dia `periodo_inicio` — Tasks 2, 4, 11.
2. `valor_pago`, `capital_liquidado_em` só escritos por `RecalcularPropina` (teste de arquitectura); transições nunca tocam Cancelada/Anulada; excesso lança e reverte a transacção do chamador — Tasks 5, 10.
3. Índices únicos parciais: duas activas no mesmo início de período (mesmo de planos diferentes) falham; depois de cancelar/anular, regerar entra — Tasks 3, 11.
4. Imutabilidade do snapshot (`valor_original` e restantes), `delete` impossível, `estado_descricao`/`origem_geracao_descricao` null-safe, matriz de isolamento verde — Task 3.
5. Planos com propinas: valor e calendário recusados (também pela Action, sem passar pelo pedido), nome/alvos editáveis, eliminação bloqueada — Tasks 6, 7.
6. Permissões: grelha com "Propinas" só com as 7 acções; ADMIN_ESCOLA com as 5 de F1; `financeiro:sincronizar` concede a tenants existentes e invalida a cache — Task 8.
7. Testes pgsql: nunca tocam `mositec_escola`; ignorados sem a base de teste; excluídos da suite por omissão — Task 11.

## Mapa de ficheiros

```
Modules/Financeiro/
  app/Casts/DataCast.php                                                   (T1, novo)
  app/Enums/OrigemGeracaoPropina.php                                       (T2, novo)
  app/Enums/EstadoCobranca.php                                             (T2, + resolver)
  app/Contracts/CobrancaResolvivel.php                                     (T2, novo)
  database/migrations/2026_10_13_100000_create_propinas_table.php          (T3, novo)
  app/Models/Propina.php                                                   (T3 novo; T4 + scope)
  app/Models/PlanoPropina.php                                              (T3 + propinas(); T9 + duracoes/total)
  app/Contracts/FonteDePagamentoDePropina.php                              (T5, novo)
  app/Support/{ContribuicaoDePagamento,FontesDePagamentoDePropina,BloqueioDePropinas}.php (T5, novos)
  app/Services/RecalcularPropina.php                                       (T5, novo)
  app/Services/SnapshotMonetario.php                                       (T6, novo)
  app/Support/PropinasReferenciam.php                                      (T6, novo)
  app/Providers/FinanceiroServiceProvider.php                              (T6, + etiqueta)
  app/Actions/AtualizarPlanoPropinaAction.php                              (T7, reescrito)
  app/Support/CalendarioDePlano.php                                        (T9, + duracoes)
  app/Services/PlanoPropinaConsultaService.php                             (T9, + campos)
  resources/js/Support/dinheiro.js                                         (T9, BigInt + decimalParaUnidadesMenores)
  resources/js/Support/periodos.js                                         (T9, novo)
  resources/js/Pages/PlanosPropina/Index.vue                               (T9)
  resources/js/Components/PlanosPropina/PlanoPropinaFormModal.vue          (T9)
  tests/Concerns/ComPropinasFinanceiro.php                                 (T3 novo; T5 + fonte falsa)
  tests/Concerns/FontePagamentoFalsa.php                                   (T5, novo)
  tests/Unit/{DataCastTest,OrigemGeracaoPropinaTest,EstadoCobrancaResolverTest,ContribuicaoDePagamentoTest}.php
  tests/Unit/CalendarioDePlanoTest.php                                     (T9, + testes)
  tests/Feature/{PropinaModelTest,EstadoResolvidoConsultaTest,RecalcularPropinaTest,SnapshotMonetarioTest,
                 PropinasReferenciamTest,PlanoPropinaComPropinasTest,PlanoPropinaPeriodosTest,PermissoesPropinaTest}.php
  tests/Feature/FinanceiroTenancyTest.php                                  (T3, + propinas)
  tests/Feature/Pgsql/PropinaPgsqlTest.php                                 (T11, novo)
Modules/Permissao/
  app/Enums/Modulo.php, database/seeders/ModuloSeeder.php, app/Actions/SincronizarPerfisDeSistemaAction.php (T8)
  tests/Unit/ModuloEnumTest.php, tests/Feature/{ModuloSeederTest,AcoesAplicaveisTest}.php                     (T8)
tests/PgsqlTestCase.php, tests/Unit/PgsqlTestCaseTest.php, phpunit.xml                                        (T11)
tests/Feature/Arquitectura/{FronteiraMatriculaFinanceiroTest,EscritoresDePropinaTest}.php                     (T10)
```

---

### Task 1: `DataCast` — datas civis sempre `Y-m-d`

**Files:**
- Create: `Modules/Financeiro/app/Casts/DataCast.php`
- Test: `Modules/Financeiro/tests/Unit/DataCastTest.php`

**Interfaces:**
- Consumes: —
- Produces: `DataCast` (`CastsAttributes` + `SerializesCastableAttributes`): `get` → `?CarbonImmutable` à meia-noite (aceita valores gravados com hora, lê só os 10 primeiros caracteres); `set` aceita `null`, `CarbonInterface` ou texto `AAAA-MM-DD` válido (calendário) e devolve `?string` `Y-m-d`; qualquer outra coisa → `InvalidArgumentException`; `serialize` → `Y-m-d`.

- [ ] **Step 1: Escrever o teste que falha**

`Modules/Financeiro/tests/Unit/DataCastTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Unit;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Modules\Financeiro\Casts\DataCast;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DataCastTest extends TestCase
{
    private function modelo(): Model
    {
        return new class extends Model {
            protected $casts = ['dia' => DataCast::class];
        };
    }

    public function test_grava_sempre_ano_mes_dia_a_partir_de_texto_ou_de_data(): void
    {
        $modelo = $this->modelo();

        $modelo->dia = '2026-09-15';
        $this->assertSame('2026-09-15', $modelo->getAttributes()['dia']);

        $modelo->dia = CarbonImmutable::parse('2026-09-15 18:30:00');
        $this->assertSame('2026-09-15', $modelo->getAttributes()['dia']);

        $modelo->dia = Carbon::parse('2027-02-28 00:00:00');
        $this->assertSame('2027-02-28', $modelo->getAttributes()['dia']);
    }

    public function test_le_como_data_imutavel_a_meia_noite_mesmo_com_hora_gravada(): void
    {
        $modelo = $this->modelo();
        $modelo->setRawAttributes(['dia' => '2026-09-15 00:00:00']);

        $this->assertInstanceOf(CarbonImmutable::class, $modelo->dia);
        $this->assertSame('2026-09-15 00:00:00', $modelo->dia->toDateTimeString());
    }

    public function test_nulo_continua_nulo(): void
    {
        $modelo = $this->modelo();
        $modelo->dia = null;

        $this->assertNull($modelo->getAttributes()['dia']);
        $this->assertNull($modelo->dia);
    }

    #[DataProvider('invalidas')]
    public function test_valores_invalidos_sao_recusados(mixed $valor): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->modelo()->dia = $valor;
    }

    public static function invalidas(): array
    {
        return [
            '31 de Fevereiro' => ['2026-02-31'],
            'com hora' => ['2026-09-15 10:00:00'],
            'formato dia/mês/ano' => ['15/09/2026'],
            'inteiro' => [20260915],
            'quebra de linha no fim' => ["2026-09-15\n"],
        ];
    }

    public function test_serializa_como_ano_mes_dia(): void
    {
        $modelo = $this->modelo();
        $modelo->dia = '2026-09-15';

        $this->assertSame(['dia' => '2026-09-15'], $modelo->toArray());
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Unit/DataCastTest.php`
Expected: FAIL (classe `DataCast` inexistente).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Casts/DataCast.php`:
```php
<?php

namespace Modules\Financeiro\Casts;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Data civil (sem hora) guardada SEMPRE como texto "Y-m-d". Os casts `date`/`date:Y-m-d` do Laravel só
 * gravam "Y-m-d" quando recebem esse texto: um Carbon é gravado como "Y-m-d H:i:s" (com a hora) em
 * SQLite, o que parte as comparações por texto ("2026-09-15 00:00:00" > "2026-09-15") e os índices
 * únicos sobre datas. Com este cast, SQLite e PostgreSQL guardam, comparam e indexam igual.
 */
class DataCast implements CastsAttributes, SerializesCastableAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', substr((string) $value, 0, 10));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        if (is_string($value)
            && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $partes) === 1
            && checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1])) {
            return $value;
        }

        throw new InvalidArgumentException("Data inválida em {$key}: use o formato AAAA-MM-DD.");
    }

    /**
     * O Laravel passa aqui o valor CRU (o texto gravado), não o resultado de get().
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : substr((string) $value, 0, 10);
    }
}
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Unit/DataCastTest.php`
Expected: PASS (9 testes: 4 + 5 casos do data provider).

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Casts/DataCast.php Modules/Financeiro/tests/Unit/DataCastTest.php
```

---

### Task 2: `OrigemGeracaoPropina`, `CobrancaResolvivel` e `EstadoCobranca::resolver()`

**Files:**
- Create: `Modules/Financeiro/app/Enums/OrigemGeracaoPropina.php`
- Create: `Modules/Financeiro/app/Contracts/CobrancaResolvivel.php`
- Modify: `Modules/Financeiro/app/Enums/EstadoCobranca.php` (reescrito com `resolver`)
- Test: `Modules/Financeiro/tests/Unit/OrigemGeracaoPropinaTest.php`, `Modules/Financeiro/tests/Unit/EstadoCobrancaResolverTest.php`

**Interfaces:**
- Consumes: `Dinheiro` (existe).
- Produces:
  - `enum OrigemGeracaoPropina: int { MATRICULA = 1; COMANDO = 2; MANUAL = 3; RETROACTIVA = 4; }` com `label(): string` (`Matrícula`, `Comando automático`, `Manual`, `Retroactiva`) e `exigeMotivo(): bool` (só `RETROACTIVA`).
  - `interface CobrancaResolvivel { estadoPersistido(): EstadoCobranca; valorDevido(): Dinheiro; valorRecebido(): Dinheiro; inicioDoPeriodo(): CarbonInterface; limiteSemAtraso(): CarbonInterface; }`
  - `EstadoCobranca::resolver(CobrancaResolvivel $cobranca, CarbonInterface $hoje): EstadoCobranca` — lança `LogicException` se o estado persistido for PENDENTE/EM_ATRASO.

- [ ] **Step 1: Escrever os testes que falham**

`Modules/Financeiro/tests/Unit/OrigemGeracaoPropinaTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Unit;

use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use PHPUnit\Framework\TestCase;

class OrigemGeracaoPropinaTest extends TestCase
{
    public function test_valores_e_rotulos(): void
    {
        $this->assertSame(
            [1 => 'Matrícula', 2 => 'Comando automático', 3 => 'Manual', 4 => 'Retroactiva'],
            array_combine(
                array_map(fn (OrigemGeracaoPropina $o) => $o->value, OrigemGeracaoPropina::cases()),
                array_map(fn (OrigemGeracaoPropina $o) => $o->label(), OrigemGeracaoPropina::cases()),
            ),
        );
    }

    public function test_so_a_geracao_retroactiva_exige_motivo(): void
    {
        foreach (OrigemGeracaoPropina::cases() as $origem) {
            $this->assertSame($origem === OrigemGeracaoPropina::RETROACTIVA, $origem->exigeMotivo(), $origem->name);
        }
    }
}
```

`Modules/Financeiro/tests/Unit/EstadoCobrancaResolverTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Unit;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use LogicException;
use Modules\Financeiro\Contracts\CobrancaResolvivel;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Support\Dinheiro;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Cobrança de referência: período a começar a 01/10/2026, limite sem atraso a 15/10/2026, valor 100.
 */
class EstadoCobrancaResolverTest extends TestCase
{
    private function cobranca(EstadoCobranca $estado, int $pago, int $valor = 100, string $inicio = '2026-10-01', string $limite = '2026-10-15'): CobrancaResolvivel
    {
        return new class($estado, $valor, $pago, $inicio, $limite) implements CobrancaResolvivel {
            public function __construct(
                private EstadoCobranca $estado,
                private int $valor,
                private int $pago,
                private string $inicio,
                private string $limite,
            ) {
            }

            public function estadoPersistido(): EstadoCobranca
            {
                return $this->estado;
            }

            public function valorDevido(): Dinheiro
            {
                return Dinheiro::deUnidadesMenores($this->valor);
            }

            public function valorRecebido(): Dinheiro
            {
                return Dinheiro::deUnidadesMenores($this->pago);
            }

            public function inicioDoPeriodo(): CarbonInterface
            {
                return CarbonImmutable::parse($this->inicio);
            }

            public function limiteSemAtraso(): CarbonInterface
            {
                return CarbonImmutable::parse($this->limite);
            }
        };
    }

    private function resolver(CobrancaResolvivel $cobranca, string $hoje): EstadoCobranca
    {
        return EstadoCobranca::resolver($cobranca, CarbonImmutable::parse($hoje));
    }

    public function test_cancelada_e_anulada_sao_terminais_mesmo_muito_depois_do_limite(): void
    {
        $this->assertSame(EstadoCobranca::CANCELADA, $this->resolver($this->cobranca(EstadoCobranca::CANCELADA, 0), '2027-12-31'));
        $this->assertSame(EstadoCobranca::ANULADA, $this->resolver($this->cobranca(EstadoCobranca::ANULADA, 0), '2027-12-31'));
        $this->assertSame(EstadoCobranca::CANCELADA, $this->resolver($this->cobranca(EstadoCobranca::CANCELADA, 0), '2026-09-01'));
    }

    public function test_paga_antes_do_inicio_e_depois_do_limite(): void
    {
        $this->assertSame(EstadoCobranca::PAGA, $this->resolver($this->cobranca(EstadoCobranca::PAGA, 100), '2026-09-01'));
        $this->assertSame(EstadoCobranca::PAGA, $this->resolver($this->cobranca(EstadoCobranca::PAGA, 100), '2027-12-31'));
    }

    public function test_fronteira_da_tolerancia_o_proprio_dia_limite_ainda_nao_e_atraso(): void
    {
        $this->assertSame(EstadoCobranca::EM_ABERTO, $this->resolver($this->cobranca(EstadoCobranca::EM_ABERTO, 0), '2026-10-15'));
        $this->assertSame(EstadoCobranca::EM_ATRASO, $this->resolver($this->cobranca(EstadoCobranca::EM_ABERTO, 0), '2026-10-16'));
    }

    public function test_em_atraso_prevalece_sobre_pagamento_parcial(): void
    {
        $this->assertSame(EstadoCobranca::PARCIALMENTE_PAGA, $this->resolver($this->cobranca(EstadoCobranca::PARCIALMENTE_PAGA, 40), '2026-10-15'));
        $this->assertSame(EstadoCobranca::EM_ATRASO, $this->resolver($this->cobranca(EstadoCobranca::PARCIALMENTE_PAGA, 40), '2026-10-16'));
    }

    public function test_pendente_antes_do_inicio_e_em_aberto_no_proprio_dia_do_inicio(): void
    {
        $this->assertSame(EstadoCobranca::PENDENTE, $this->resolver($this->cobranca(EstadoCobranca::EM_ABERTO, 0), '2026-09-30'));
        $this->assertSame(EstadoCobranca::EM_ABERTO, $this->resolver($this->cobranca(EstadoCobranca::EM_ABERTO, 0), '2026-10-01'));
    }

    public function test_pagamento_antecipado_parcial_e_parcialmente_paga_e_nunca_pendente(): void
    {
        $this->assertSame(EstadoCobranca::PARCIALMENTE_PAGA, $this->resolver($this->cobranca(EstadoCobranca::PARCIALMENTE_PAGA, 10), '2026-09-01'));
    }

    public function test_as_horas_do_dia_sao_ignoradas(): void
    {
        $cobranca = $this->cobranca(EstadoCobranca::EM_ABERTO, 0);

        $this->assertSame(EstadoCobranca::EM_ABERTO, EstadoCobranca::resolver($cobranca, CarbonImmutable::parse('2026-10-15 23:59:59')));
        $this->assertSame(EstadoCobranca::EM_ATRASO, EstadoCobranca::resolver($cobranca, CarbonImmutable::parse('2026-10-16 00:00:01')));
        $this->assertSame(EstadoCobranca::PENDENTE, EstadoCobranca::resolver($cobranca, CarbonImmutable::parse('2026-09-30 23:59:59')));
    }

    public function test_o_estado_mostrado_deriva_dos_montantes_e_das_datas(): void
    {
        // O persistido só decide Cancelada/Anulada; o resto vem do valor pago e das datas (spec §4).
        $this->assertSame(EstadoCobranca::PAGA, $this->resolver($this->cobranca(EstadoCobranca::EM_ABERTO, 100), '2026-10-05'));
    }

    #[DataProvider('derivados')]
    public function test_estado_derivado_como_persistido_e_recusado(EstadoCobranca $estado): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Pendente e Em Atraso são estados derivados e nunca se gravam.');

        $this->resolver($this->cobranca($estado, 0), '2026-10-05');
    }

    public static function derivados(): array
    {
        return ['pendente' => [EstadoCobranca::PENDENTE], 'em atraso' => [EstadoCobranca::EM_ATRASO]];
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Unit/OrigemGeracaoPropinaTest.php Modules/Financeiro/tests/Unit/EstadoCobrancaResolverTest.php`
Expected: FAIL (enum, contrato e método inexistentes).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Enums/OrigemGeracaoPropina.php`:
```php
<?php

namespace Modules\Financeiro\Enums;

/**
 * Como nasceu uma propina (auditoria e sinalização de geração tardia, §8.1/Q4 do plano de fases).
 * RETROACTIVA = períodos anteriores ao mês da matrícula ou já vencidos, gerados manualmente com
 * `propina.ajustar` e motivo obrigatório.
 */
enum OrigemGeracaoPropina: int
{
    case MATRICULA = 1;
    case COMANDO = 2;
    case MANUAL = 3;
    case RETROACTIVA = 4;

    public function label(): string
    {
        return match ($this) {
            self::MATRICULA => 'Matrícula',
            self::COMANDO => 'Comando automático',
            self::MANUAL => 'Manual',
            self::RETROACTIVA => 'Retroactiva',
        };
    }

    public function exigeMotivo(): bool
    {
        return $this === self::RETROACTIVA;
    }
}
```

`Modules/Financeiro/app/Contracts/CobrancaResolvivel.php`:
```php
<?php

namespace Modules\Financeiro\Contracts;

use Carbon\CarbonInterface;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Support\Dinheiro;

/**
 * O mínimo que EstadoCobranca::resolver() precisa de saber de uma cobrança (hoje, Propina).
 */
interface CobrancaResolvivel
{
    /** Um dos cinco estados persistidos. */
    public function estadoPersistido(): EstadoCobranca;

    public function valorDevido(): Dinheiro;

    public function valorRecebido(): Dinheiro;

    /** Primeiro dia do período coberto. */
    public function inicioDoPeriodo(): CarbonInterface;

    /** Último dia sem atraso: vencimento + dias de tolerância (snapshot). */
    public function limiteSemAtraso(): CarbonInterface;
}
```

`Modules/Financeiro/app/Enums/EstadoCobranca.php` (ficheiro completo):
```php
<?php

namespace Modules\Financeiro\Enums;

use Carbon\CarbonInterface;
use LogicException;
use Modules\Financeiro\Contracts\CobrancaResolvivel;

/**
 * Estados de uma cobrança. PENDENTE (período ainda não iniciado) e EM_ATRASO são
 * derivados pela data e nunca persistidos; os restantes cinco são a coluna `estado`.
 * As transições entre EM_ABERTO, PARCIALMENTE_PAGA e PAGA são automáticas (recálculo
 * do valor pago); só CANCELADA e ANULADA são acções manuais, ambas terminais.
 */
enum EstadoCobranca: int
{
    case EM_ABERTO = 1;
    case PARCIALMENTE_PAGA = 2;
    case PAGA = 3;
    case CANCELADA = 4;
    case ANULADA = 5;
    case PENDENTE = 6;
    case EM_ATRASO = 7;

    public function label(): string
    {
        return match ($this) {
            self::EM_ABERTO => 'Em Aberto',
            self::PARCIALMENTE_PAGA => 'Parcialmente Paga',
            self::PAGA => 'Paga',
            self::CANCELADA => 'Cancelada',
            self::ANULADA => 'Anulada',
            self::PENDENTE => 'Pendente',
            self::EM_ATRASO => 'Em Atraso',
        };
    }

    public function persistido(): bool
    {
        return ! in_array($this, [self::PENDENTE, self::EM_ATRASO], true);
    }

    /**
     * @return list<self>
     */
    public function transicoesPermitidas(): array
    {
        return match ($this) {
            self::EM_ABERTO => [self::PARCIALMENTE_PAGA, self::PAGA, self::CANCELADA, self::ANULADA],
            self::PARCIALMENTE_PAGA => [self::EM_ABERTO, self::PAGA],
            self::PAGA => [self::EM_ABERTO, self::PARCIALMENTE_PAGA],
            self::CANCELADA, self::ANULADA, self::PENDENTE, self::EM_ATRASO => [],
        };
    }

    public function podeTransitarPara(self $destino): bool
    {
        return in_array($destino, $this->transicoesPermitidas(), true);
    }

    /**
     * Estado mostrado e filtrado de uma cobrança (spec Propinas §4), por esta precedência:
     * 1. Cancelada ou Anulada (persistidos, terminais);
     * 2. Paga (nada em dívida);
     * 3. Em Atraso: saldo > 0 e hoje depois do limite (vencimento + tolerância, dias corridos);
     * 4. Parcialmente Paga (algo pago);
     * 5. Pendente: período ainda por começar e nada pago;
     * 6. Em Aberto.
     * Compara dias civis, nunca horas. A tradução SQL é Propina::scopeComEstadoResolvido: as duas são
     * testadas juntas e só coincidem porque o estado persistido é sempre coerente com o valor pago
     * (garantido por RecalcularPropina, pelo model e, em PostgreSQL, por CHECK).
     */
    public static function resolver(CobrancaResolvivel $cobranca, CarbonInterface $hoje): self
    {
        $persistido = $cobranca->estadoPersistido();

        if (! $persistido->persistido()) {
            throw new LogicException('Pendente e Em Atraso são estados derivados e nunca se gravam.');
        }

        if ($persistido === self::CANCELADA || $persistido === self::ANULADA) {
            return $persistido;
        }

        $devido = $cobranca->valorDevido()->unidadesMenores();
        $recebido = $cobranca->valorRecebido()->unidadesMenores();
        $dia = $hoje->toDateString();

        return match (true) {
            $recebido >= $devido => self::PAGA,
            $dia > $cobranca->limiteSemAtraso()->toDateString() => self::EM_ATRASO,
            $recebido > 0 => self::PARCIALMENTE_PAGA,
            $cobranca->inicioDoPeriodo()->toDateString() > $dia => self::PENDENTE,
            default => self::EM_ABERTO,
        };
    }
}
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Unit/OrigemGeracaoPropinaTest.php Modules/Financeiro/tests/Unit/EstadoCobrancaResolverTest.php Modules/Financeiro/tests/Unit/EstadoCobrancaTest.php`
Expected: PASS (o `EstadoCobrancaTest` existente continua verde).

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Enums/OrigemGeracaoPropina.php Modules/Financeiro/app/Enums/EstadoCobranca.php Modules/Financeiro/app/Contracts/CobrancaResolvivel.php Modules/Financeiro/tests/Unit/OrigemGeracaoPropinaTest.php Modules/Financeiro/tests/Unit/EstadoCobrancaResolverTest.php
```

---

### Task 3: Tabela `propinas`, model `Propina`, helper de testes e isolamento

**Files:**
- Create: `Modules/Financeiro/database/migrations/2026_10_13_100000_create_propinas_table.php`
- Create: `Modules/Financeiro/app/Models/Propina.php`
- Modify: `Modules/Financeiro/app/Models/PlanoPropina.php` (relação `propinas()`)
- Create: `Modules/Financeiro/tests/Concerns/ComPropinasFinanceiro.php`
- Test: `Modules/Financeiro/tests/Feature/PropinaModelTest.php`
- Modify (test): `Modules/Financeiro/tests/Feature/FinanceiroTenancyTest.php`

**Interfaces:**
- Consumes: `DataCast` (T1), `OrigemGeracaoPropina`, `CobrancaResolvivel`, `EstadoCobranca::resolver` (T2), `DinheiroCast`, `Dinheiro`, `Moeda`, `TaxaCambio`, `Matricula`, `PlanoPropina`, `AnoLectivo`, `User`, `MoedaDoTenant`.
- Produces:
  - Tabela `propinas` (colunas, índices e CHECK abaixo).
  - `Propina` (`implements CobrancaResolvivel`): constantes `ESTADOS_ACTIVOS = [1, 2, 3]` e `IMUTAVEIS` (lista); relações `matricula()`, `plano()`, `anoLectivo()`, `canceladoPor()`, `anuladoPor()`; `saldo(): Dinheiro`; `estadoResolvido(CarbonInterface $hoje): EstadoCobranca`; `taxaCambio(): ?TaxaCambio`; `scopeActivas()`.
  - `PlanoPropina::propinas(): HasMany`.
  - Trait de testes `ComPropinasFinanceiro`: `alunoFinanceiro()`, `matriculaActiva(Turma, string $data = '2026-09-01', EstadoMatriculaEnum $estado = ACTIVA)`, `cenarioPropinas(array $atributosPlano = []): array{ano, turma, plano, matricula}`, `propina(Matricula, PlanoPropina, int $ordem = 1, array $atributos = []): Propina`, `comEstado(Propina, EstadoCobranca, ?int $valorPago = null): Propina`, `cenarioDeEstados(): array<string, Propina>`, `assertPropinasCoerentes(): void`.

**Colunas** (`propinas`): `id`; `tenant_id` FK `tenants` restrict; `matricula_id` FK `matriculas` restrict; `plano_propina_id` FK `planos_propina` restrict; `ano_lectivo_id` FK `ano_lectivos` restrict; `ordem` unsignedSmallInteger; `periodo_inicio`, `periodo_fim` date; `valor_original`, `valor` unsignedBigInteger; `valor_pago` unsignedBigInteger default 0; `moeda` char(3); `cambio_usd` unsignedBigInteger null; `data_vencimento` date; `dias_tolerancia` unsignedTinyInteger; `data_limite` date; `capital_liquidado_em` date null; `estado` unsignedTinyInteger default 1 + `estado_descricao` string; `origem_geracao` unsignedTinyInteger + `origem_geracao_descricao` string; `motivo_geracao` text null; `motivo_cancelamento` text null, `cancelado_por` FK `users` null nullOnDelete, `cancelado_em` timestamp null; idem `motivo_anulacao`/`anulado_por`/`anulado_em`; `criado_por`/`editado_por` FK `users` null; timestamps.

- [ ] **Step 1: Escrever o helper e os testes que falham**

`Modules/Financeiro/tests/Concerns/ComPropinasFinanceiro.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Concerns;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\Aluno\Models\Aluno;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\DadosPessoa;

/**
 * Alunos, matrículas e propinas para testes (F1 e fases seguintes). Requer ComDadosAcademicosFinanceiro
 * na mesma classe de teste. As propinas criadas aqui NÃO passam pela geração (F2): copiam o calendário
 * do plano e um snapshot fixo de teste (vencimento ao dia 10, tolerância de 5 dias → limite ao dia 15,
 * moeda da escola, sem câmbio, origem Manual).
 */
trait ComPropinasFinanceiro
{
    protected function alunoFinanceiro(string $nome = 'Aluno de Teste'): Aluno
    {
        $pessoa = DadosPessoa::create([
            'nome_completo' => $nome,
            'numero_identificacao' => 'BI-' . uniqid(),
            'tipo_pessoa' => DadosPessoa::TIPO_ALUNO,
        ]);

        return Aluno::create([
            'estabelecimento_id' => Estabelecimento::current()->id,
            'dados_pessoa_id' => $pessoa->id,
            'numero_matricula' => 'AL-' . uniqid(),
        ]);
    }

    protected function matriculaActiva(Turma $turma, string $dataMatricula = '2026-09-01', EstadoMatriculaEnum $estado = EstadoMatriculaEnum::ACTIVA): Matricula
    {
        return Matricula::create([
            'aluno_id' => $this->alunoFinanceiro()->id,
            'turma_id' => $turma->id,
            'ano_lectivo_id' => $turma->ano_lectivo_id,
            'numero_registo_matricula' => 'MR-' . uniqid(),
            'data_matricula' => $dataMatricula,
            'estado' => $estado->value,
        ]);
    }

    /**
     * Ano 2026/2027 (01/09/2026–31/07/2027), uma turma, o plano "Propina Mensal" (Set → Jun, 25.000,00 por
     * período, geral) e uma matrícula Activa de 01/09/2026. Uma chamada por tenant e por teste.
     *
     * @param  array<string, mixed>  $atributosPlano
     * @return array<string, mixed> chaves ano, turma, plano, matricula
     */
    protected function cenarioPropinas(array $atributosPlano = []): array
    {
        $ano = $this->anoLectivo();
        $turma = $this->turma($ano, $this->nivel('N' . uniqid()));
        $plano = $this->plano($ano, 'Propina Mensal', $atributosPlano);

        return ['ano' => $ano, 'turma' => $turma, 'plano' => $plano, 'matricula' => $this->matriculaActiva($turma)];
    }

    /**
     * @param  array<string, mixed>  $atributos  sobrepõe qualquer atributo preenchível (valor, períodos, origem…)
     */
    protected function propina(Matricula $matricula, PlanoPropina $plano, int $ordem = 1, array $atributos = []): Propina
    {
        $periodo = $plano->periodos()[$ordem - 1]
            ?? throw new InvalidArgumentException("O plano {$plano->nome} não tem o período {$ordem}.");
        $inicio = CarbonImmutable::parse($periodo['inicio']);

        return Propina::create(array_merge([
            'matricula_id' => $matricula->id,
            'plano_propina_id' => $plano->id,
            'ano_lectivo_id' => $plano->ano_lectivo_id,
            'ordem' => $ordem,
            'periodo_inicio' => $periodo['inicio'],
            'periodo_fim' => $periodo['fim'],
            'valor' => $plano->valor,
            'moeda' => app(MoedaDoTenant::class)->atual()->codigo,
            'cambio_usd' => null,
            'data_vencimento' => $inicio->setDay(10)->toDateString(),
            'dias_tolerancia' => 5,
            'origem_geracao' => OrigemGeracaoPropina::MANUAL,
        ], $atributos));
    }

    /**
     * Põe a propina num estado persistido SEM passar pelo RecalcularPropina (só para preparar cenários).
     * Paga usa valor_pago = valor e liquidação no início do período; Parcialmente Paga usa $valorPago
     * (por omissão metade); as restantes ficam com 0. Cancelada/Anulada recebem motivo e data.
     */
    protected function comEstado(Propina $propina, EstadoCobranca $estado, ?int $valorPago = null): Propina
    {
        $valor = $propina->valor->unidadesMenores();
        $pago = match ($estado) {
            EstadoCobranca::PAGA => $valor,
            EstadoCobranca::PARCIALMENTE_PAGA => $valorPago ?? intdiv($valor, 2),
            default => 0,
        };

        $propina->forceFill([
            'estado' => $estado,
            'valor_pago' => $pago,
            'capital_liquidado_em' => $estado === EstadoCobranca::PAGA ? $propina->periodo_inicio : null,
            'motivo_cancelamento' => $estado === EstadoCobranca::CANCELADA ? 'Cenário de teste' : null,
            'cancelado_em' => $estado === EstadoCobranca::CANCELADA ? now() : null,
            'motivo_anulacao' => $estado === EstadoCobranca::ANULADA ? 'Cenário de teste' : null,
            'anulado_em' => $estado === EstadoCobranca::ANULADA ? now() : null,
        ])->save();

        return $propina->refresh();
    }

    /**
     * Dez propinas mensais (Set/2026 … Jun/2027) de uma matrícula, com limites ao dia 15 de cada mês:
     * set_aberta, out_parcial (10.000,00), nov_paga, dez_cancelada, jan_anulada, fev_aberta,
     * mar_parcial (20.000,00), abr_paga, mai_aberta, jun_aberta.
     *
     * @return array<string, Propina>
     */
    protected function cenarioDeEstados(): array
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $nova = fn (int $ordem) => $this->propina($matricula, $plano, $ordem);

        return [
            'set_aberta' => $nova(1),
            'out_parcial' => $this->comEstado($nova(2), EstadoCobranca::PARCIALMENTE_PAGA, 1_000_000),
            'nov_paga' => $this->comEstado($nova(3), EstadoCobranca::PAGA),
            'dez_cancelada' => $this->comEstado($nova(4), EstadoCobranca::CANCELADA),
            'jan_anulada' => $this->comEstado($nova(5), EstadoCobranca::ANULADA),
            'fev_aberta' => $nova(6),
            'mar_parcial' => $this->comEstado($nova(7), EstadoCobranca::PARCIALMENTE_PAGA, 2_000_000),
            'abr_paga' => $this->comEstado($nova(8), EstadoCobranca::PAGA),
            'mai_aberta' => $nova(9),
            'jun_aberta' => $nova(10),
        ];
    }

    /**
     * Reconciliação global (critério 8.2/1 do plano de fases), sobre TODAS as propinas do tenant corrente.
     * F4 acrescenta Σ(ajustes) à igualdade valor = valor_original.
     */
    protected function assertPropinasCoerentes(): void
    {
        $moeda = app(MoedaDoTenant::class)->atual()->codigo;

        foreach (Propina::query()->orderBy('id')->get() as $propina) {
            $id = "propina {$propina->id}";
            $valor = $propina->valor->unidadesMenores();
            $pago = $propina->valor_pago->unidadesMenores();

            $this->assertGreaterThan(0, $valor, "{$id}: valor > 0");
            $this->assertGreaterThanOrEqual(0, $pago, "{$id}: valor_pago >= 0");
            $this->assertLessThanOrEqual($valor, $pago, "{$id}: valor_pago <= valor");
            $this->assertSame($propina->valor_original->unidadesMenores(), $valor, "{$id}: valor = valor_original (sem ajustes em F1)");

            $esperado = match (true) {
                in_array($propina->estado, [EstadoCobranca::CANCELADA, EstadoCobranca::ANULADA], true) => $propina->estado,
                $pago === 0 => EstadoCobranca::EM_ABERTO,
                $pago === $valor => EstadoCobranca::PAGA,
                default => EstadoCobranca::PARCIALMENTE_PAGA,
            };

            $this->assertSame($esperado, $propina->estado, "{$id}: estado coerente com o valor pago");
            $this->assertSame($propina->estado->label(), $propina->estado_descricao, "{$id}: estado_descricao");
            $this->assertSame($pago === $valor, $propina->capital_liquidado_em !== null, "{$id}: capital_liquidado_em só com saldo 0");
            $this->assertSame($moeda, $propina->moeda, "{$id}: moeda da escola");
            $this->assertSame(
                $propina->data_vencimento->addDays($propina->dias_tolerancia)->toDateString(),
                $propina->data_limite->toDateString(),
                "{$id}: data_limite = vencimento + tolerância",
            );
        }
    }
}
```

`Modules/Financeiro/tests/Feature/PropinaModelTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PropinaModelTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    private function umaPropina(array $atributos = []): Propina
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

        return $this->propina($matricula, $plano, 1, $atributos);
    }

    public function test_a_tabela_tem_os_indices_previstos(): void
    {
        $indices = collect(Schema::getIndexes('propinas'))->keyBy('name');

        $esperados = [
            'propinas_plano_ordem_activo_unique' => [true, ['tenant_id', 'matricula_id', 'plano_propina_id', 'ordem']],
            'propinas_periodo_activo_unique' => [true, ['tenant_id', 'matricula_id', 'periodo_inicio']],
            'propinas_matricula_periodo_index' => [false, ['tenant_id', 'matricula_id', 'periodo_inicio']],
            'propinas_estado_vencimento_index' => [false, ['tenant_id', 'estado', 'data_vencimento']],
            'propinas_estado_limite_index' => [false, ['tenant_id', 'estado', 'data_limite']],
            'propinas_plano_estado_index' => [false, ['tenant_id', 'plano_propina_id', 'estado']],
            'propinas_ano_estado_index' => [false, ['tenant_id', 'ano_lectivo_id', 'estado']],
        ];

        foreach ($esperados as $nome => [$unico, $colunas]) {
            $this->assertTrue($indices->has($nome), "Falta o índice {$nome}.");
            $this->assertSame($unico, $indices[$nome]['unique'], $nome);
            $this->assertSame($colunas, $indices[$nome]['columns'], $nome);
        }
    }

    public function test_criar_copia_o_valor_original_calcula_o_limite_e_sincroniza_as_descricoes(): void
    {
        $propina = $this->umaPropina()->refresh();

        $this->assertSame(2_500_000, $propina->valor->unidadesMenores());
        $this->assertSame(2_500_000, $propina->valor_original->unidadesMenores());
        $this->assertSame(0, $propina->valor_pago->unidadesMenores());
        $this->assertSame('2026-09-01', $propina->periodo_inicio->toDateString());
        $this->assertSame('2026-09-30', $propina->periodo_fim->toDateString());
        $this->assertSame('2026-09-10', $propina->data_vencimento->toDateString());
        $this->assertSame('2026-09-15', $propina->data_limite->toDateString());
        $this->assertSame('2026-09-15', $propina->getRawOriginal('data_limite')); // sem hora, também em SQLite
        $this->assertSame(EstadoCobranca::EM_ABERTO, $propina->estado);
        $this->assertSame('Em Aberto', $propina->estado_descricao);
        $this->assertSame(OrigemGeracaoPropina::MANUAL, $propina->origem_geracao);
        $this->assertSame('Manual', $propina->origem_geracao_descricao);
        $this->assertSame('AOA', $propina->moeda);
        $this->assertNull($propina->cambio_usd);
        $this->assertNull($propina->taxaCambio());
        $this->assertSame($this->tenant->id, (int) $propina->tenant_id);
        $this->assertSame(2_500_000, $propina->saldo()->unidadesMenores());
    }

    public function test_campos_protegidos_nao_sao_preenchiveis_e_o_valor_original_e_sempre_o_valor(): void
    {
        foreach (['tenant_id', 'valor_original', 'valor_pago', 'estado', 'estado_descricao', 'capital_liquidado_em', 'data_limite',
            'origem_geracao_descricao', 'motivo_cancelamento', 'cancelado_por', 'cancelado_em', 'motivo_anulacao', 'anulado_por',
            'anulado_em', 'criado_por', 'editado_por'] as $campo) {
            $this->assertFalse((new Propina())->isFillable($campo), $campo);
        }

        $propina = $this->umaPropina(['valor_original' => 1, 'valor_pago' => 999, 'estado' => EstadoCobranca::PAGA, 'data_limite' => '2030-01-01']);

        $this->assertSame(2_500_000, $propina->valor_original->unidadesMenores());
        $this->assertSame(0, $propina->valor_pago->unidadesMenores());
        $this->assertSame(EstadoCobranca::EM_ABERTO, $propina->estado);
        $this->assertSame('2026-09-15', $propina->data_limite->toDateString());
    }

    public function test_valor_original_imposto_por_force_fill_e_reposto_ao_criar(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

        $propina = (new Propina())->forceFill([
            'matricula_id' => $matricula->id, 'plano_propina_id' => $plano->id, 'ano_lectivo_id' => $plano->ano_lectivo_id,
            'ordem' => 1, 'periodo_inicio' => '2026-09-01', 'periodo_fim' => '2026-09-30', 'valor' => 2_500_000,
            'valor_original' => 1, 'moeda' => 'AOA', 'data_vencimento' => '2026-09-10', 'dias_tolerancia' => 5,
            'origem_geracao' => OrigemGeracaoPropina::MANUAL,
        ]);
        $propina->save();

        $this->assertSame(2_500_000, $propina->refresh()->valor_original->unidadesMenores());
    }

    public function test_a_lista_de_imutaveis_e_a_do_snapshot(): void
    {
        $this->assertSame([
            'matricula_id', 'ano_lectivo_id', 'periodo_inicio', 'periodo_fim', 'valor_original', 'moeda', 'cambio_usd',
            'data_vencimento', 'dias_tolerancia', 'data_limite', 'origem_geracao', 'motivo_geracao',
        ], Propina::IMUTAVEIS);
    }

    #[DataProvider('alteracoesImutaveis')]
    public function test_campos_do_snapshot_sao_imutaveis(string $campo, mixed $novo): void
    {
        $propina = $this->umaPropina();
        $antes = $propina->fresh()->getAttributes();

        try {
            $propina->forceFill([$campo => $novo])->save();
            $this->fail("{$campo} devia ser imutável.");
        } catch (LogicException $e) {
            $this->assertStringContainsString("Campos imutáveis de uma propina não podem mudar: {$campo}.", $e->getMessage());
        }

        $this->assertSame($antes, $propina->fresh()->getAttributes());
    }

    public static function alteracoesImutaveis(): array
    {
        return [
            'valor_original' => ['valor_original', 1],
            'matricula_id' => ['matricula_id', 999],
            'ano_lectivo_id' => ['ano_lectivo_id', 999],
            'periodo_inicio' => ['periodo_inicio', '2026-08-01'],
            'periodo_fim' => ['periodo_fim', '2026-10-31'],
            'moeda' => ['moeda', 'USD'],
            'cambio_usd' => ['cambio_usd', 910_000_000],
            'data_vencimento' => ['data_vencimento', '2026-09-12'],
            'dias_tolerancia' => ['dias_tolerancia', 9],
            'data_limite' => ['data_limite', '2026-09-30'],
            'origem_geracao' => ['origem_geracao', OrigemGeracaoPropina::COMANDO],
            'motivo_geracao' => ['motivo_geracao', 'Outro motivo'],
        ];
    }

    public function test_valor_e_plano_podem_mudar_sem_tocar_no_valor_original(): void
    {
        $propina = $this->umaPropina();

        $propina->forceFill(['valor' => Dinheiro::deUnidadesMenores(3_000_000)])->save();

        $this->assertSame(3_000_000, $propina->refresh()->valor->unidadesMenores());
        $this->assertSame(2_500_000, $propina->valor_original->unidadesMenores());
    }

    public function test_uma_propina_nunca_e_eliminada(): void
    {
        $propina = $this->umaPropina();

        try {
            $propina->delete();
            $this->fail('A eliminação devia ser recusada.');
        } catch (LogicException $e) {
            $this->assertSame('Uma propina nunca é eliminada: cancele-a ou anule-a.', $e->getMessage());
        }

        $this->assertNotNull(Propina::query()->find($propina->id));
    }

    #[DataProvider('derivados')]
    public function test_estados_derivados_nunca_se_gravam(EstadoCobranca $estado): void
    {
        $propina = $this->umaPropina();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Pendente e Em Atraso são estados derivados e nunca se gravam.');

        $propina->forceFill(['estado' => $estado])->save();
    }

    public static function derivados(): array
    {
        return ['pendente' => [EstadoCobranca::PENDENTE], 'em atraso' => [EstadoCobranca::EM_ATRASO]];
    }

    public function test_as_descricoes_acompanham_cada_estado_persistido(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $estados = [EstadoCobranca::PARCIALMENTE_PAGA, EstadoCobranca::PAGA, EstadoCobranca::CANCELADA, EstadoCobranca::ANULADA];

        foreach ($estados as $indice => $estado) {
            $propina = $this->comEstado($this->propina($matricula, $plano, $indice + 1), $estado);

            $this->assertSame($estado, $propina->estado);
            $this->assertSame($estado->label(), $propina->estado_descricao);
        }

        $this->assertPropinasCoerentes();
    }

    #[DataProvider('incoerencias')]
    public function test_estado_e_montantes_incoerentes_sao_recusados_tambem_em_sqlite(array $atributos, string $mensagem): void
    {
        $propina = $this->umaPropina();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($mensagem);

        $propina->forceFill($atributos)->save();
    }

    public static function incoerencias(): array
    {
        return [
            'pago acima do valor' => [['valor_pago' => 2_500_001, 'estado' => EstadoCobranca::PAGA, 'capital_liquidado_em' => '2026-09-05'], 'O valor pago não pode exceder o valor da propina.'],
            'paga sem pagamento' => [['estado' => EstadoCobranca::PAGA], 'O estado Paga não é coerente com o valor pago.'],
            'aberta com pagamento' => [['valor_pago' => 1], 'O estado Em Aberto não é coerente com o valor pago.'],
            'liquidação com saldo' => [['capital_liquidado_em' => '2026-09-05'], 'A data de liquidação do capital só existe quando a propina está totalmente paga.'],
            'cancelada sem data' => [['estado' => EstadoCobranca::CANCELADA], 'Uma propina cancelada exige a data de cancelamento, e só ela a tem.'],
            'data de anulação sem anular' => [['anulado_em' => '2026-09-05 10:00:00'], 'Uma propina anulada exige a data de anulação, e só ela a tem.'],
            'valor zero' => [['valor' => 0], 'O valor da propina tem de ser maior que zero.'],
        ];
    }

    #[DataProvider('criacoesInvalidas')]
    public function test_invariantes_de_criacao(array $atributos, string $mensagem): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($mensagem);

        $this->umaPropina($atributos);
    }

    public static function criacoesInvalidas(): array
    {
        return [
            'sem origem' => [['origem_geracao' => null], 'A origem da geração da propina é obrigatória.'],
            'retroactiva sem motivo' => [['origem_geracao' => OrigemGeracaoPropina::RETROACTIVA], 'Uma propina retroactiva exige o motivo da geração.'],
            'retroactiva com motivo em branco' => [['origem_geracao' => OrigemGeracaoPropina::RETROACTIVA, 'motivo_geracao' => '   '], 'Uma propina retroactiva exige o motivo da geração.'],
            'ordem zero' => [['ordem' => 0], 'A ordem do período tem de ser maior ou igual a 1.'],
            'fim antes do início' => [['periodo_fim' => '2026-08-31'], 'O período da propina é inválido: o fim não pode ser anterior ao início.'],
            'vencimento antes do início' => [['data_vencimento' => '2026-08-31'], 'O vencimento não pode ser anterior ao início do período.'],
            'tolerância negativa' => [['dias_tolerancia' => -1], 'A tolerância (dias) não pode ser negativa.'],
            'moeda desconhecida' => [['moeda' => 'XXX'], 'Moeda desconhecida na propina.'],
            'câmbio zero' => [['cambio_usd' => 0], 'O câmbio da propina tem de ser maior que zero (ou nulo).'],
            'valor zero' => [['valor' => 0], 'O valor da propina tem de ser maior que zero.'],
        ];
    }

    public function test_retroactiva_com_motivo_e_aceite(): void
    {
        $propina = $this->umaPropina(['origem_geracao' => OrigemGeracaoPropina::RETROACTIVA, 'motivo_geracao' => 'Aluno integrado em Novembro com meses em atraso.']);

        $this->assertSame('Retroactiva', $propina->refresh()->origem_geracao_descricao);
    }

    public function test_plano_e_matricula_de_anos_lectivos_diferentes_sao_recusados(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();
        $outroAno = $this->anoLectivo('2027/2028', '2027-09-01', '2028-07-31');
        $planoDoOutroAno = $this->plano($outroAno, 'Propina 2027');

        try {
            $this->propina($matricula, $planoDoOutroAno);
            $this->fail('Devia recusar o plano de outro ano lectivo.');
        } catch (LogicException $e) {
            $this->assertSame('A propina, a matrícula e o plano têm de ser do mesmo ano lectivo.', $e->getMessage());
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A propina, a matrícula e o plano têm de ser do mesmo ano lectivo.');

        $this->propina($matricula, $planoDoOutroAno, 1, ['ano_lectivo_id' => $matricula->ano_lectivo_id]);
    }

    public function test_duas_activas_no_mesmo_inicio_de_periodo_falham_mesmo_com_planos_diferentes(): void
    {
        ['ano' => $ano, 'plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $outroPlano = $this->plano($ano, 'Outro Plano');
        $this->propina($matricula, $plano);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/propinas_periodo_activo_unique|propinas\.periodo_inicio/'); // PostgreSQL | SQLite

        $this->propina($matricula, $outroPlano);
    }

    public function test_duas_activas_com_o_mesmo_plano_e_ordem_falham_mesmo_com_inicio_diferente(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->propina($matricula, $plano);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/propinas_plano_ordem_activo_unique|propinas\.plano_propina_id/'); // PostgreSQL | SQLite

        $this->propina($matricula, $plano, 1, ['periodo_inicio' => '2026-10-01', 'periodo_fim' => '2026-10-31', 'data_vencimento' => '2026-10-10']);
    }

    public function test_depois_de_cancelar_ou_anular_o_mesmo_periodo_pode_ser_regerado(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

        $this->comEstado($this->propina($matricula, $plano), EstadoCobranca::CANCELADA);
        $this->comEstado($this->propina($matricula, $plano), EstadoCobranca::ANULADA);
        $activa = $this->propina($matricula, $plano);

        $this->assertSame(3, Propina::query()->where('matricula_id', $matricula->id)->count());
        $this->assertSame([$activa->id], Propina::query()->activas()->pluck('id')->all());
        $this->assertPropinasCoerentes();
    }

    public function test_relacoes_saldo_e_estado_resolvido(): void
    {
        ['ano' => $ano, 'plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->comEstado($this->propina($matricula, $plano), EstadoCobranca::PARCIALMENTE_PAGA, 1_000_000);

        $this->assertSame($matricula->id, $propina->matricula->id);
        $this->assertSame($plano->id, $propina->plano->id);
        $this->assertSame($ano->id, $propina->anoLectivo->id);
        $this->assertSame([$propina->id], $plano->propinas()->pluck('id')->all());
        $this->assertSame(1_500_000, $propina->saldo()->unidadesMenores());
        $this->assertSame(EstadoCobranca::PARCIALMENTE_PAGA, $propina->estadoResolvido(CarbonImmutable::parse('2026-09-15')));
        $this->assertSame(EstadoCobranca::EM_ATRASO, $propina->estadoResolvido(CarbonImmutable::parse('2026-09-16')));
    }
}
```

Em `Modules/Financeiro/tests/Feature/FinanceiroTenancyTest.php`:
1. Acrescentar aos `use` do topo:
```php
use LogicException;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
```
2. Substituir `    use RefreshDatabase;` por:
```php
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;
```
3. Acrescentar ao fim da classe:
```php
    private function umaPropina(): Propina
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

        return $this->propina($matricula, $plano);
    }

    public function test_as_propinas_so_aparecem_no_tenant_dono_e_nao_mudam_de_tenant(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $minha = $this->umaPropina();
        $dela = $this->noTenant($outro, fn () => $this->umaPropina()->id);

        $this->assertSame([$minha->id], Propina::query()->pluck('id')->all());
        $this->assertNull(Propina::query()->find($dela));
        $this->assertSame([$dela], $this->noTenant($outro, fn () => Propina::query()->pluck('id')->all()));

        $this->expectException(AlteracaoDeTenantProibida::class);

        $minha->forceFill(['tenant_id' => $outro->id])->save();
    }

    public function test_sem_contexto_ler_ou_gravar_propinas_lanca_tenant_nao_resolvido(): void
    {
        app(TenantContext::class)->limpar();

        try {
            Propina::query()->count();
            $this->fail('A leitura sem contexto devia lançar.');
        } catch (TenantNaoResolvido) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(TenantNaoResolvido::class);

        (new Propina())->forceFill([])->save();
    }

    public function test_uma_propina_nao_aceita_matricula_de_outro_tenant(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        ['plano' => $plano] = $this->cenarioPropinas();
        $matriculaDela = $this->noTenant($outro, fn () => $this->cenarioPropinas()['matricula']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A matrícula e o plano têm de existir no tenant da propina.');

        $this->propina($matriculaDela, $plano);
    }
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/PropinaModelTest.php Modules/Financeiro/tests/Feature/FinanceiroTenancyTest.php`
Expected: FAIL (tabela e model inexistentes).

- [ ] **Step 3: Implementar a migration**

`Modules/Financeiro/database/migrations/2026_10_13_100000_create_propinas_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('propinas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('matricula_id')->constrained('matriculas')->restrictOnDelete();
            $table->foreignId('plano_propina_id')->constrained('planos_propina')->restrictOnDelete();
            $table->foreignId('ano_lectivo_id')->constrained('ano_lectivos')->restrictOnDelete();
            $table->unsignedSmallInteger('ordem');
            $table->date('periodo_inicio');
            $table->date('periodo_fim');
            $table->unsignedBigInteger('valor_original');
            $table->unsignedBigInteger('valor');
            $table->unsignedBigInteger('valor_pago')->default(0);
            $table->char('moeda', 3);
            $table->unsignedBigInteger('cambio_usd')->nullable();
            $table->date('data_vencimento');
            $table->unsignedTinyInteger('dias_tolerancia');
            $table->date('data_limite');
            $table->date('capital_liquidado_em')->nullable();
            $table->unsignedTinyInteger('estado')->default(1);
            $table->string('estado_descricao');
            $table->unsignedTinyInteger('origem_geracao');
            $table->string('origem_geracao_descricao');
            $table->text('motivo_geracao')->nullable();
            $table->text('motivo_cancelamento')->nullable();
            $table->foreignId('cancelado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelado_em')->nullable();
            $table->text('motivo_anulacao')->nullable();
            $table->foreignId('anulado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('anulado_em')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'matricula_id', 'periodo_inicio'], 'propinas_matricula_periodo_index');
            $table->index(['tenant_id', 'estado', 'data_vencimento'], 'propinas_estado_vencimento_index');
            $table->index(['tenant_id', 'estado', 'data_limite'], 'propinas_estado_limite_index');
            $table->index(['tenant_id', 'plano_propina_id', 'estado'], 'propinas_plano_estado_index');
            $table->index(['tenant_id', 'ano_lectivo_id', 'estado'], 'propinas_ano_estado_index');
        });

        // Cobrança dupla, camada 1: unicidades só entre propinas activas (Em Aberto, Parcialmente Paga,
        // Paga); Cancelada e Anulada ficam de fora para permitir regerar. O Blueprint não tem índices
        // parciais; a sintaxe é comum a PostgreSQL e SQLite (precedente: domains_tenant_principal_unique).
        DB::statement('CREATE UNIQUE INDEX propinas_plano_ordem_activo_unique ON propinas (tenant_id, matricula_id, plano_propina_id, ordem) WHERE estado IN (1, 2, 3)');
        DB::statement('CREATE UNIQUE INDEX propinas_periodo_activo_unique ON propinas (tenant_id, matricula_id, periodo_inicio) WHERE estado IN (1, 2, 3)');

        // SQLite não aceita ALTER TABLE … ADD CONSTRAINT: os CHECK só existem em PostgreSQL (produção).
        // O model Propina repete as mesmas regras, por isso a suite em SQLite também as exercita.
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $restricoes = [
            'propinas_valor_positivo_check' => 'valor > 0',
            'propinas_valor_original_positivo_check' => 'valor_original > 0',
            'propinas_valor_pago_intervalo_check' => 'valor_pago >= 0 AND valor_pago <= valor',
            'propinas_estado_check' => 'estado BETWEEN 1 AND 5',
            'propinas_estado_coerente_check' => '(estado = 1 AND valor_pago = 0) OR (estado = 2 AND valor_pago > 0 AND valor_pago < valor) OR (estado = 3 AND valor_pago = valor) OR (estado IN (4, 5) AND valor_pago = 0)',
            'propinas_capital_liquidado_check' => '(capital_liquidado_em IS NOT NULL) = (valor_pago = valor)',
            'propinas_cancelada_check' => '(estado = 4) = (cancelado_em IS NOT NULL)',
            'propinas_anulada_check' => '(estado = 5) = (anulado_em IS NOT NULL)',
            'propinas_ordem_check' => 'ordem >= 1',
            'propinas_periodo_check' => 'periodo_fim >= periodo_inicio',
            'propinas_vencimento_check' => 'data_vencimento >= periodo_inicio AND data_limite >= data_vencimento',
            'propinas_origem_geracao_check' => 'origem_geracao BETWEEN 1 AND 4',
            'propinas_moeda_check' => 'char_length(moeda) = 3',
            'propinas_cambio_check' => 'cambio_usd IS NULL OR cambio_usd > 0',
        ];

        foreach ($restricoes as $nome => $expressao) {
            DB::statement("ALTER TABLE propinas ADD CONSTRAINT {$nome} CHECK ({$expressao})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('propinas');
    }
};
```

- [ ] **Step 4: Implementar o model e a relação no plano**

`Modules/Financeiro/app/Models/Propina.php`:
```php
<?php

namespace Modules\Financeiro\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Financeiro\Casts\DataCast;
use Modules\Financeiro\Casts\DinheiroCast;
use Modules\Financeiro\Contracts\CobrancaResolvivel;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Support\Moeda;
use Modules\Financeiro\Support\TaxaCambio;
use Modules\Matricula\Models\Matricula;
use Modules\Usuario\Models\User;

/**
 * Obrigação financeira concreta de uma matrícula num período (spec Propinas §3). Guarda um snapshot do
 * plano, das regras e da moeda/câmbio no momento da geração; alterações posteriores nunca a afectam.
 * `valor_pago`, `estado` (entre Em Aberto, Parcialmente Paga e Paga) e `capital_liquidado_em` só são
 * escritos por RecalcularPropina. Nunca se elimina: cancela-se ou anula-se.
 */
class Propina extends Model implements CobrancaResolvivel
{
    use PertenceAoTenant;
    use RegistaAutoria;

    /** Estados persistidos de uma cobrança activa (índices únicos parciais e sobreposição). */
    public const ESTADOS_ACTIVOS = [1, 2, 3];

    /** Snapshot da geração: nunca muda depois de criada. Plano, ordem e valor só mudam pela alteração de preço (F4). */
    public const IMUTAVEIS = [
        'matricula_id', 'ano_lectivo_id', 'periodo_inicio', 'periodo_fim', 'valor_original', 'moeda', 'cambio_usd',
        'data_vencimento', 'dias_tolerancia', 'data_limite', 'origem_geracao', 'motivo_geracao',
    ];

    protected $table = 'propinas';

    protected $fillable = [
        'matricula_id',
        'plano_propina_id',
        'ano_lectivo_id',
        'ordem',
        'periodo_inicio',
        'periodo_fim',
        'valor',
        'moeda',
        'cambio_usd',
        'data_vencimento',
        'dias_tolerancia',
        'origem_geracao',
        'motivo_geracao',
    ];

    protected $hidden = ['tenant_id', 'criado_por', 'editado_por'];

    protected $attributes = [
        'estado' => 1,
        'valor_pago' => 0,
    ];

    protected $casts = [
        'matricula_id' => 'integer',
        'plano_propina_id' => 'integer',
        'ano_lectivo_id' => 'integer',
        'ordem' => 'integer',
        'periodo_inicio' => DataCast::class,
        'periodo_fim' => DataCast::class,
        'valor_original' => DinheiroCast::class,
        'valor' => DinheiroCast::class,
        'valor_pago' => DinheiroCast::class,
        'cambio_usd' => 'integer',
        'data_vencimento' => DataCast::class,
        'dias_tolerancia' => 'integer',
        'data_limite' => DataCast::class,
        'capital_liquidado_em' => DataCast::class,
        'estado' => EstadoCobranca::class,
        'origem_geracao' => OrigemGeracaoPropina::class,
        'cancelado_em' => 'datetime',
        'anulado_em' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Null-safe: a matriz de isolamento grava models vazios em cru e espera TenantNaoResolvido, que a
        // trait lança no `creating` (depois deste `saving`).
        static::saving(function (self $propina) {
            if ($propina->estado !== null && ! $propina->estado->persistido()) {
                throw new LogicException('Pendente e Em Atraso são estados derivados e nunca se gravam.');
            }

            $propina->estado_descricao = $propina->estado?->label();
            $propina->origem_geracao_descricao = $propina->origem_geracao?->label();
        });

        // Corre depois da verificação de tenant da trait (registada antes, ao arrancar a trait).
        static::creating(function (self $propina) {
            $propina->valor_original = $propina->valor;

            if ($propina->data_vencimento !== null && $propina->dias_tolerancia !== null) {
                $propina->data_limite = $propina->data_vencimento->addDays($propina->dias_tolerancia);
            }

            $propina->validarCriacao();
        });

        static::updating(function (self $propina) {
            $alterados = array_values(array_filter(self::IMUTAVEIS, fn (string $campo) => $propina->isDirty($campo)));

            if ($alterados !== []) {
                throw new LogicException('Campos imutáveis de uma propina não podem mudar: ' . implode(', ', $alterados) . '.');
            }

            $propina->validarCoerencia();
        });

        static::deleting(function () {
            throw new LogicException('Uma propina nunca é eliminada: cancele-a ou anule-a.');
        });
    }

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(Matricula::class, 'matricula_id')->withTrashed();
    }

    public function plano(): BelongsTo
    {
        return $this->belongsTo(PlanoPropina::class, 'plano_propina_id');
    }

    public function anoLectivo(): BelongsTo
    {
        return $this->belongsTo(AnoLectivo::class, 'ano_lectivo_id')->withTrashed();
    }

    public function canceladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelado_por');
    }

    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por');
    }

    public function scopeActivas(Builder $query): Builder
    {
        return $query->whereIn($this->qualifyColumn('estado'), self::ESTADOS_ACTIVOS);
    }

    public function saldo(): Dinheiro
    {
        return $this->valorDevido()->subtrair($this->valorRecebido());
    }

    public function estadoResolvido(CarbonInterface $hoje): EstadoCobranca
    {
        return EstadoCobranca::resolver($this, $hoje);
    }

    public function taxaCambio(): ?TaxaCambio
    {
        return $this->cambio_usd === null ? null : TaxaCambio::deMicros($this->cambio_usd);
    }

    public function estadoPersistido(): EstadoCobranca
    {
        return $this->estado;
    }

    public function valorDevido(): Dinheiro
    {
        return $this->valor;
    }

    public function valorRecebido(): Dinheiro
    {
        return $this->valor_pago ?? Dinheiro::deUnidadesMenores(0);
    }

    public function inicioDoPeriodo(): CarbonInterface
    {
        return $this->periodo_inicio;
    }

    public function limiteSemAtraso(): CarbonInterface
    {
        return $this->data_limite;
    }

    private function validarCriacao(): void
    {
        if ($this->origem_geracao === null) {
            throw new LogicException('A origem da geração da propina é obrigatória.');
        }

        if ($this->origem_geracao->exigeMotivo() && trim((string) $this->motivo_geracao) === '') {
            throw new LogicException('Uma propina retroactiva exige o motivo da geração.');
        }

        if ($this->ordem === null || $this->ordem < 1) {
            throw new LogicException('A ordem do período tem de ser maior ou igual a 1.');
        }

        if ($this->periodo_inicio === null || $this->periodo_fim === null || $this->periodo_fim->lt($this->periodo_inicio)) {
            throw new LogicException('O período da propina é inválido: o fim não pode ser anterior ao início.');
        }

        if ($this->data_vencimento === null || $this->data_vencimento->lt($this->periodo_inicio)) {
            throw new LogicException('O vencimento não pode ser anterior ao início do período.');
        }

        if ($this->dias_tolerancia === null || $this->dias_tolerancia < 0) {
            throw new LogicException('A tolerância (dias) não pode ser negativa.');
        }

        if ($this->moeda === null || ! Moeda::existe($this->moeda)) {
            throw new LogicException('Moeda desconhecida na propina.');
        }

        if ($this->cambio_usd !== null && $this->cambio_usd <= 0) {
            throw new LogicException('O câmbio da propina tem de ser maior que zero (ou nulo).');
        }

        $this->validarCoerencia();

        // O scope do tenant esconde matrícula e plano de outra escola: ambos têm de existir aqui.
        $matricula = Matricula::query()->find($this->matricula_id);
        $plano = PlanoPropina::query()->find($this->plano_propina_id);

        if ($matricula === null || $plano === null) {
            throw new LogicException('A matrícula e o plano têm de existir no tenant da propina.');
        }

        if ((int) $plano->ano_lectivo_id !== (int) $matricula->ano_lectivo_id || (int) $this->ano_lectivo_id !== (int) $plano->ano_lectivo_id) {
            throw new LogicException('A propina, a matrícula e o plano têm de ser do mesmo ano lectivo.');
        }
    }

    /**
     * As mesmas regras dos CHECK de PostgreSQL (migration), para valerem também em SQLite.
     */
    private function validarCoerencia(): void
    {
        $valor = $this->valor?->unidadesMenores() ?? 0;
        $pago = $this->valor_pago?->unidadesMenores() ?? 0;

        if ($valor <= 0) {
            throw new LogicException('O valor da propina tem de ser maior que zero.');
        }

        if ($pago > $valor) {
            throw new LogicException('O valor pago não pode exceder o valor da propina.');
        }

        $coerente = match ($this->estado) {
            EstadoCobranca::EM_ABERTO => $pago === 0,
            EstadoCobranca::PARCIALMENTE_PAGA => $pago > 0 && $pago < $valor,
            EstadoCobranca::PAGA => $pago === $valor,
            EstadoCobranca::CANCELADA, EstadoCobranca::ANULADA => $pago === 0,
            default => false,
        };

        if (! $coerente) {
            throw new LogicException("O estado {$this->estado?->label()} não é coerente com o valor pago.");
        }

        if (($this->capital_liquidado_em !== null) !== ($pago === $valor)) {
            throw new LogicException('A data de liquidação do capital só existe quando a propina está totalmente paga.');
        }

        if (($this->estado === EstadoCobranca::CANCELADA) !== ($this->cancelado_em !== null)) {
            throw new LogicException('Uma propina cancelada exige a data de cancelamento, e só ela a tem.');
        }

        if (($this->estado === EstadoCobranca::ANULADA) !== ($this->anulado_em !== null)) {
            throw new LogicException('Uma propina anulada exige a data de anulação, e só ela a tem.');
        }
    }
}
```

Em `Modules/Financeiro/app/Models/PlanoPropina.php`, depois do método `alvos()`, acrescentar:
```php
    /**
     * Propinas geradas a partir deste plano (em qualquer estado). Ter alguma bloqueia a eliminação,
     * o valor e o calendário do plano.
     */
    public function propinas(): HasMany
    {
        return $this->hasMany(Propina::class, 'plano_propina_id');
    }
```
(`HasMany` já está importado; `Propina` está no mesmo namespace.)

- [ ] **Step 5: Correr e ver passar (incluindo a matriz e o esquema)**

Run: `php artisan test Modules/Financeiro/tests/Feature/PropinaModelTest.php Modules/Financeiro/tests/Feature/FinanceiroTenancyTest.php tests/Feature/Tenancy/MatrizIsolamentoTest.php tests/Feature/Arquitectura/TenancyEsquemaTest.php tests/Feature/Arquitectura/TenancyArquitecturaTest.php`
Expected: PASS. Em particular `test_sem_contexto_a_leitura_e_a_escrita_de_qualquer_model_lancam` (a matriz descobre `Propina` sozinha) e `test_toda_a_tabela_com_tenant_id_exige_not_null_e_chave_para_tenants`.

- [ ] **Step 6: Corrigir o spec §3 e registar L21 (D2)**

Em `docs/superpowers/specs/2026-10-08-financeiro-propinas-design.md` (§3), substituir a linha
`- \`unique(tenant_id, matricula_id, plano_propina_id, ordem)\`.` por:
```markdown
- `unique(tenant_id, matricula_id, plano_propina_id, ordem) WHERE estado IN (Em Aberto, Parcialmente Paga, Paga)`: índice único **parcial** (SQL cru, como o da cobrança dupla abaixo). Uma unicidade total impediria regerar o mesmo período do mesmo plano depois de o cancelar ou anular (L21 do plano de fases).
```
Em `docs/superpowers/plans/2026-10-12-financeiro-propinas-fases.md`, depois da linha de **L20**, acrescentar:
```markdown
- **L21 — Unicidade `(tenant_id, matricula_id, plano_propina_id, ordem)` total contradiz "regerar depois de cancelar"** (spec §3 vs F2/F3). *Resolvido em F1:* as duas unicidades de `propinas` são parciais (`WHERE estado IN (1, 2, 3)`); Cancelada e Anulada ficam fora de ambas. Spec §3 corrigido.
```

- [ ] **Step 7: Stage**

```bash
git add Modules/Financeiro/database/migrations/2026_10_13_100000_create_propinas_table.php Modules/Financeiro/app/Models/Propina.php Modules/Financeiro/app/Models/PlanoPropina.php Modules/Financeiro/tests/Concerns/ComPropinasFinanceiro.php Modules/Financeiro/tests/Feature/PropinaModelTest.php Modules/Financeiro/tests/Feature/FinanceiroTenancyTest.php docs/superpowers/specs/2026-10-08-financeiro-propinas-design.md docs/superpowers/plans/2026-10-12-financeiro-propinas-fases.md
```

---

### Task 4: Estado resolvido em SQL (`scopeComEstadoResolvido`)

**Files:**
- Modify: `Modules/Financeiro/app/Models/Propina.php` (novo scope)
- Test: `Modules/Financeiro/tests/Feature/EstadoResolvidoConsultaTest.php`

**Interfaces:**
- Consumes: `Propina`, `EstadoCobranca::resolver`, `cenarioDeEstados()` (T3).
- Produces: `Propina::query()->comEstadoResolvido(EstadoCobranca $estado, CarbonInterface $hoje)` — só comparações de colunas com a data `Y-m-d` de hoje (sem `DB::raw`, sem aritmética de datas):

| Resolvido | WHERE |
|---|---|
| Cancelada / Anulada / Paga | `estado = 4 / 5 / 3` |
| Em Atraso | `estado IN (1,2) AND data_limite < :hoje` |
| Parcialmente Paga | `estado = 2 AND data_limite >= :hoje` |
| Pendente | `estado = 1 AND periodo_inicio > :hoje AND data_limite >= :hoje` |
| Em Aberto | `estado = 1 AND periodo_inicio <= :hoje AND data_limite >= :hoje` |

(O `data_limite >= :hoje` em Pendente é implicado por `data_limite ≥ data_vencimento ≥ periodo_inicio`, mas fica explícito para o SQL ser literalmente o mesmo ramo do PHP, que só chega a Pendente se não houver atraso.)

- [ ] **Step 1: Escrever o teste que falha**

`Modules/Financeiro/tests/Feature/EstadoResolvidoConsultaTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EstadoResolvidoConsultaTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    /** @return list<int> */
    private function idsPorSql(EstadoCobranca $estado, string $dia): array
    {
        return Propina::query()->comEstadoResolvido($estado, CarbonImmutable::parse($dia))->orderBy('id')->pluck('id')->all();
    }

    #[DataProvider('dias')]
    public function test_o_filtro_sql_coincide_com_o_php_para_os_sete_estados(string $dia): void
    {
        $this->cenarioDeEstados();
        $hoje = CarbonImmutable::parse($dia);
        $porEstado = Propina::query()->orderBy('id')->get()->groupBy(fn (Propina $p) => $p->estadoResolvido($hoje)->value);
        $todos = [];

        foreach (EstadoCobranca::cases() as $estado) {
            $sql = $this->idsPorSql($estado, $dia);

            $this->assertSame(($porEstado[$estado->value] ?? collect())->pluck('id')->all(), $sql, "{$estado->label()} em {$dia}");
            array_push($todos, ...$sql);
        }

        // Partição: cada propina cai em exactamente um estado resolvido.
        sort($todos);
        $this->assertSame(Propina::query()->orderBy('id')->pluck('id')->all(), $todos, "partição em {$dia}");
    }

    public static function dias(): array
    {
        return [
            'antes do ano' => ['2026-08-31'],
            'início de Setembro' => ['2026-09-01'],
            'limite de Setembro' => ['2026-09-15'],
            'dia seguinte ao limite' => ['2026-09-16'],
            'limite de Outubro' => ['2026-10-15'],
            'depois do limite de Outubro' => ['2026-10-16'],
            'Março' => ['2027-03-20'],
            'fim do ano lectivo' => ['2027-07-01'],
        ];
    }

    public function test_matriz_explicita_a_16_de_outubro(): void
    {
        $p = $this->cenarioDeEstados();
        $esperado = [
            EstadoCobranca::EM_ATRASO->value => [$p['set_aberta']->id, $p['out_parcial']->id],
            EstadoCobranca::PAGA->value => [$p['nov_paga']->id, $p['abr_paga']->id],
            EstadoCobranca::CANCELADA->value => [$p['dez_cancelada']->id],
            EstadoCobranca::ANULADA->value => [$p['jan_anulada']->id],
            EstadoCobranca::PENDENTE->value => [$p['fev_aberta']->id, $p['mai_aberta']->id, $p['jun_aberta']->id],
            EstadoCobranca::PARCIALMENTE_PAGA->value => [$p['mar_parcial']->id],
            EstadoCobranca::EM_ABERTO->value => [],
        ];

        foreach ($esperado as $valor => $ids) {
            $this->assertSame($ids, $this->idsPorSql(EstadoCobranca::from($valor), '2026-10-16'), EstadoCobranca::from($valor)->label());
        }
    }

    public function test_fronteira_da_tolerancia_e_do_inicio_do_periodo(): void
    {
        $p = $this->cenarioDeEstados();

        // Setembro: aberta até ao limite (15), em atraso a partir de 16.
        $this->assertContains($p['set_aberta']->id, $this->idsPorSql(EstadoCobranca::EM_ABERTO, '2026-09-15'));
        $this->assertContains($p['set_aberta']->id, $this->idsPorSql(EstadoCobranca::EM_ATRASO, '2026-09-16'));
        // Outubro, parcialmente paga: parcial no próprio limite, em atraso no dia seguinte (mostra-se o pago à parte, §4).
        $this->assertContains($p['out_parcial']->id, $this->idsPorSql(EstadoCobranca::PARCIALMENTE_PAGA, '2026-10-15'));
        $this->assertContains($p['out_parcial']->id, $this->idsPorSql(EstadoCobranca::EM_ATRASO, '2026-10-16'));
        // Pendente na véspera do início, Em Aberto no próprio dia do início.
        $this->assertContains($p['set_aberta']->id, $this->idsPorSql(EstadoCobranca::PENDENTE, '2026-08-31'));
        $this->assertContains($p['set_aberta']->id, $this->idsPorSql(EstadoCobranca::EM_ABERTO, '2026-09-01'));
        // Paga continua Paga muito depois do limite; parcial futura é parcial, nunca Pendente.
        $this->assertContains($p['nov_paga']->id, $this->idsPorSql(EstadoCobranca::PAGA, '2027-07-01'));
        $this->assertContains($p['mar_parcial']->id, $this->idsPorSql(EstadoCobranca::PARCIALMENTE_PAGA, '2026-09-01'));
    }

    public function test_o_filtro_combina_com_outras_condicoes_e_nao_ve_outro_tenant(): void
    {
        $p = $this->cenarioDeEstados();
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, fn () => $this->cenarioDeEstados());

        $ids = Propina::query()
            ->where('matricula_id', $p['set_aberta']->matricula_id)
            ->comEstadoResolvido(EstadoCobranca::EM_ATRASO, CarbonImmutable::parse('2026-10-16'))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $this->assertSame([$p['set_aberta']->id, $p['out_parcial']->id], $ids);
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/EstadoResolvidoConsultaTest.php`
Expected: FAIL (`Call to undefined method … comEstadoResolvido`).

- [ ] **Step 3: Implementar**

Em `Modules/Financeiro/app/Models/Propina.php`, depois de `scopeActivas`, acrescentar:
```php
    /**
     * Tradução SQL de EstadoCobranca::resolver() para filtros e listas (spec §4: "os filtros por estado
     * resolvido traduzem-se em queries por data"). Só compara colunas com o dia de hoje: graças a
     * `data_limite` (snapshot) não há aritmética de datas nem SQL específico de um motor. Depende de o
     * estado persistido ser coerente com o valor pago (RecalcularPropina, model e CHECK em pgsql).
     */
    public function scopeComEstadoResolvido(Builder $query, EstadoCobranca $estado, CarbonInterface $hoje): Builder
    {
        $dia = $hoje->toDateString();
        $colunaEstado = $this->qualifyColumn('estado');
        $limite = $this->qualifyColumn('data_limite');
        $inicio = $this->qualifyColumn('periodo_inicio');
        $aberto = EstadoCobranca::EM_ABERTO->value;
        $parcial = EstadoCobranca::PARCIALMENTE_PAGA->value;

        return match ($estado) {
            EstadoCobranca::CANCELADA, EstadoCobranca::ANULADA, EstadoCobranca::PAGA => $query->where($colunaEstado, $estado->value),
            EstadoCobranca::EM_ATRASO => $query->whereIn($colunaEstado, [$aberto, $parcial])->where($limite, '<', $dia),
            EstadoCobranca::PARCIALMENTE_PAGA => $query->where($colunaEstado, $parcial)->where($limite, '>=', $dia),
            EstadoCobranca::PENDENTE => $query->where($colunaEstado, $aberto)->where($inicio, '>', $dia)->where($limite, '>=', $dia),
            EstadoCobranca::EM_ABERTO => $query->where($colunaEstado, $aberto)->where($inicio, '<=', $dia)->where($limite, '>=', $dia),
        };
    }
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Feature/EstadoResolvidoConsultaTest.php`
Expected: PASS (8 datas × 7 estados + 3 testes).

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Models/Propina.php Modules/Financeiro/tests/Feature/EstadoResolvidoConsultaTest.php
```

---

### Task 5: `RecalcularPropina`, `BloqueioDePropinas` e o contrato de contribuições

**Files:**
- Create: `Modules/Financeiro/app/Contracts/FonteDePagamentoDePropina.php`
- Create: `Modules/Financeiro/app/Support/ContribuicaoDePagamento.php`
- Create: `Modules/Financeiro/app/Support/FontesDePagamentoDePropina.php`
- Create: `Modules/Financeiro/app/Support/BloqueioDePropinas.php`
- Create: `Modules/Financeiro/app/Services/RecalcularPropina.php`
- Create: `Modules/Financeiro/tests/Concerns/FontePagamentoFalsa.php`
- Modify: `Modules/Financeiro/tests/Concerns/ComPropinasFinanceiro.php` (+ `fontePagamentoFalsa()`)
- Test: `Modules/Financeiro/tests/Unit/ContribuicaoDePagamentoTest.php`, `Modules/Financeiro/tests/Feature/RecalcularPropinaTest.php`

**Interfaces:**
- Consumes: `Propina` (T3/T4), `Dinheiro`, `EstadoCobranca`.
- Produces:
  - `interface FonteDePagamentoDePropina { /** @return list<ContribuicaoDePagamento> */ public function contribuicoes(Propina $propina): array; }` — Pagamentos-A implementa (distribuições Confirmadas; utilizações de crédito Activas). Registo: `$this->app->tag([Classe::class], FontesDePagamentoDePropina::ETIQUETA)`.
  - `final class ContribuicaoDePagamento { public readonly Dinheiro $valor (> 0); public readonly CarbonImmutable $data; }`.
  - `FontesDePagamentoDePropina::ETIQUETA = 'financeiro.fontes-pagamento-propina'`; `contribuicoes(Propina): list<ContribuicaoDePagamento>` ordenadas por data (estável).
  - `BloqueioDePropinas::bloquear(array $ids): Collection<int, Propina>` — exige transacção; ids únicos, `orderBy('id')`, `lockForUpdate`; só vê o tenant corrente.
  - `RecalcularPropina::executar(Propina $propina): Propina` — devolve a instância relida e gravada. Lança `LogicException` sem transacção, para propina fora do tenant, para contribuições em Cancelada/Anulada, e se Σ > valor.

- [ ] **Step 1: Escrever os testes e a fonte falsa**

`Modules/Financeiro/tests/Concerns/FontePagamentoFalsa.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Concerns;

use Carbon\CarbonImmutable;
use Modules\Financeiro\Contracts\FonteDePagamentoDePropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Support\ContribuicaoDePagamento;
use Modules\Financeiro\Support\Dinheiro;

/**
 * Substituto de Pagamentos nos testes: contribuições definidas à mão por propina.
 */
final class FontePagamentoFalsa implements FonteDePagamentoDePropina
{
    /** @var array<int, list<ContribuicaoDePagamento>> */
    private array $porPropina = [];

    /**
     * @param  list<array{0: int, 1: string}>  $contribuicoes  [unidades menores, data Y-m-d]
     */
    public function definir(Propina $propina, array $contribuicoes): void
    {
        $this->porPropina[$propina->id] = array_map(
            fn (array $c) => new ContribuicaoDePagamento(Dinheiro::deUnidadesMenores($c[0]), CarbonImmutable::parse($c[1])),
            $contribuicoes,
        );
    }

    public function contribuicoes(Propina $propina): array
    {
        return $this->porPropina[$propina->id] ?? [];
    }
}
```

Em `Modules/Financeiro/tests/Concerns/ComPropinasFinanceiro.php`, acrescentar aos `use` do topo:
```php
use Modules\Financeiro\Support\FontesDePagamentoDePropina;
```
e, no fim do trait:
```php
    /**
     * Regista uma fonte de pagamentos falsa (pode haver várias, com chaves diferentes).
     */
    protected function fontePagamentoFalsa(string $chave = 'fonte.pagamento.falsa'): FontePagamentoFalsa
    {
        $fonte = new FontePagamentoFalsa();
        $this->app->instance($chave, $fonte);
        $this->app->tag([$chave], FontesDePagamentoDePropina::ETIQUETA);

        return $fonte;
    }
```

`Modules/Financeiro/tests/Unit/ContribuicaoDePagamentoTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Unit;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\Financeiro\Support\ContribuicaoDePagamento;
use Modules\Financeiro\Support\Dinheiro;
use PHPUnit\Framework\TestCase;

class ContribuicaoDePagamentoTest extends TestCase
{
    public function test_guarda_valor_e_data(): void
    {
        $contribuicao = new ContribuicaoDePagamento(Dinheiro::deUnidadesMenores(1_000), CarbonImmutable::parse('2026-09-05'));

        $this->assertSame(1_000, $contribuicao->valor->unidadesMenores());
        $this->assertSame('2026-09-05', $contribuicao->data->toDateString());
    }

    public function test_contribuicao_de_zero_e_recusada(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Uma contribuição para o valor pago tem de ser maior que zero.');

        new ContribuicaoDePagamento(Dinheiro::deUnidadesMenores(0), CarbonImmutable::parse('2026-09-05'));
    }
}
```

`Modules/Financeiro/tests/Feature/RecalcularPropinaTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\RecalcularPropina;
use Modules\Financeiro\Support\BloqueioDePropinas;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Tests\TestCase;

/**
 * Nota: o RefreshDatabase corre cada teste dentro de uma transacção, por isso chamar o serviço
 * directamente já está "dentro de uma transacção do chamador".
 */
class RecalcularPropinaTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    private function recalcular(Propina $propina): Propina
    {
        return app(RecalcularPropina::class)->executar($propina);
    }

    private function umaPropina(): Propina
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

        return $this->propina($matricula, $plano); // 25.000,00
    }

    private function assertEstado(Propina $propina, EstadoCobranca $estado, int $pago, ?string $liquidadaEm): void
    {
        $this->assertSame($estado, $propina->estado);
        $this->assertSame($estado->label(), $propina->estado_descricao);
        $this->assertSame($pago, $propina->valor_pago->unidadesMenores());
        $this->assertSame($liquidadaEm, $propina->capital_liquidado_em?->toDateString());
    }

    public function test_sem_fontes_registadas_o_valor_pago_volta_a_zero_e_fica_em_aberto(): void
    {
        $propina = $this->comEstado($this->umaPropina(), EstadoCobranca::PARCIALMENTE_PAGA, 1_000_000); // cache desactualizada

        $this->assertEstado($this->recalcular($propina), EstadoCobranca::EM_ABERTO, 0, null);
        $this->assertPropinasCoerentes();
    }

    public function test_transicoes_automaticas_entre_aberta_parcial_e_paga(): void
    {
        $fonte = $this->fontePagamentoFalsa();
        $propina = $this->umaPropina();

        $fonte->definir($propina, [[1_000_000, '2026-09-05']]);
        $this->assertEstado($this->recalcular($propina), EstadoCobranca::PARCIALMENTE_PAGA, 1_000_000, null);

        $fonte->definir($propina, [[1_000_000, '2026-09-05'], [1_500_000, '2026-09-20']]);
        $this->assertEstado($this->recalcular($propina), EstadoCobranca::PAGA, 2_500_000, '2026-09-20');

        $fonte->definir($propina, [[1_500_000, '2026-09-20']]); // o pagamento de 05/09 foi anulado
        $this->assertEstado($this->recalcular($propina), EstadoCobranca::PARCIALMENTE_PAGA, 1_500_000, null);

        $fonte->definir($propina, []);
        $this->assertEstado($this->recalcular($propina), EstadoCobranca::EM_ABERTO, 0, null);

        $this->assertPropinasCoerentes();
    }

    public function test_recalcular_duas_vezes_com_as_mesmas_contribuicoes_nao_grava_nada(): void
    {
        $fonte = $this->fontePagamentoFalsa();
        $propina = $this->umaPropina();
        $fonte->definir($propina, [[2_500_000, '2026-09-05']]);

        $primeira = $this->recalcular($propina);
        $this->travel(1)->days();
        $segunda = $this->recalcular($primeira);

        $this->assertEstado($segunda, EstadoCobranca::PAGA, 2_500_000, '2026-09-05');
        $this->assertSame($primeira->getAttributes(), $segunda->getAttributes()); // nem updated_at muda
    }

    public function test_soma_todas_as_fontes_e_liquida_na_data_da_contribuicao_que_fecha_o_saldo(): void
    {
        $pagamentos = $this->fontePagamentoFalsa();
        $creditos = $this->fontePagamentoFalsa('fonte.credito.falsa');
        $propina = $this->umaPropina();

        $pagamentos->definir($propina, [[1_500_000, '2026-10-02']]);
        $creditos->definir($propina, [[1_000_000, '2026-09-10']]); // crédito usado antes do pagamento

        $this->assertEstado($this->recalcular($propina), EstadoCobranca::PAGA, 2_500_000, '2026-10-02');
    }

    public function test_excesso_lanca_e_a_transacao_do_chamador_reverte_por_inteiro(): void
    {
        $fonte = $this->fontePagamentoFalsa();
        $propina = $this->umaPropina();
        $plano = $propina->plano;
        $fonte->definir($propina, [[1_000_000, '2026-09-05']]);
        DB::transaction(fn () => $this->recalcular($propina));

        $fonte->definir($propina, [[1_000_000, '2026-09-05'], [2_000_000, '2026-09-06']]);

        try {
            DB::transaction(function () use ($propina, $plano) {
                $plano->forceFill(['nome' => 'Alterado na mesma transacção'])->save();
                $this->recalcular($propina);
            });
            $this->fail('O excesso devia lançar.');
        } catch (LogicException $e) {
            $this->assertSame("O valor pago (3000000) excede o valor da propina (2500000) na propina {$propina->id}.", $e->getMessage());
        }

        $this->assertSame('Propina Mensal', $plano->fresh()->nome);
        $this->assertEstado($propina->fresh(), EstadoCobranca::PARCIALMENTE_PAGA, 1_000_000, null);
    }

    public function test_canceladas_e_anuladas_nunca_sao_alteradas(): void
    {
        $fonte = $this->fontePagamentoFalsa();
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $cancelada = $this->comEstado($this->propina($matricula, $plano, 1), EstadoCobranca::CANCELADA);
        $anulada = $this->comEstado($this->propina($matricula, $plano, 2), EstadoCobranca::ANULADA);

        foreach ([$cancelada, $anulada] as $propina) {
            $this->assertSame($propina->getAttributes(), $this->recalcular($propina)->getAttributes());
        }

        $fonte->definir($cancelada, [[1_000, '2026-09-05']]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Uma propina cancelada ou anulada não pode ter pagamentos activos.');

        $this->recalcular($cancelada);
    }

    public function test_fora_de_uma_transacao_lanca_antes_de_ler_a_base(): void
    {
        $propina = $this->umaPropina();

        // Sai da transacção do RefreshDatabase para simular uma chamada sem DB::transaction. Os dados criados
        // acima desaparecem, mas o serviço tem de lançar antes de ler a base.
        DB::rollBack();
        $this->assertSame(0, DB::transactionLevel());

        try {
            $this->recalcular($propina);
            $this->fail('Sem transacção devia lançar.');
        } catch (LogicException $e) {
            $this->assertSame('Bloquear propinas exige uma transacção aberta (DB::transaction) do chamador.', $e->getMessage());
        }

        $this->expectException(LogicException::class);

        app(BloqueioDePropinas::class)->bloquear([$propina->id]);
    }

    public function test_bloqueio_ordena_por_id_sem_repetidos_e_ignora_outro_tenant(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $a = $this->propina($matricula, $plano, 1);
        $b = $this->propina($matricula, $plano, 2);
        $c = $this->propina($matricula, $plano, 3);
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $dela = $this->noTenant($outro, function () {
            ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

            return $this->propina($matricula, $plano)->id;
        });

        $ids = DB::transaction(fn () => app(BloqueioDePropinas::class)->bloquear([$c->id, $a->id, $c->id, $dela, $b->id])->pluck('id')->all());

        $this->assertSame([$a->id, $b->id, $c->id], $ids);
        $this->assertTrue(DB::transaction(fn () => app(BloqueioDePropinas::class)->bloquear([])->isEmpty()));
    }

    public function test_recalcular_uma_propina_de_outro_tenant_lanca(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $dela = $this->noTenant($outro, fn () => $this->umaPropina());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A propina a recalcular não existe no tenant corrente.');

        $this->recalcular($dela);
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Unit/ContribuicaoDePagamentoTest.php Modules/Financeiro/tests/Feature/RecalcularPropinaTest.php`
Expected: FAIL (classes inexistentes).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Contracts/FonteDePagamentoDePropina.php`:
```php
<?php

namespace Modules\Financeiro\Contracts;

use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Support\ContribuicaoDePagamento;

/**
 * Quem contribui para o valor pago de uma propina (Pagamentos: distribuições de pagamentos Confirmados
 * e utilizações de crédito Activas). Só RecalcularPropina consulta as fontes. Sem nenhuma registada,
 * o valor pago é 0. Registo: $this->app->tag([Classe::class], FontesDePagamentoDePropina::ETIQUETA).
 */
interface FonteDePagamentoDePropina
{
    /**
     * @return list<ContribuicaoDePagamento>
     */
    public function contribuicoes(Propina $propina): array;
}
```

`Modules/Financeiro/app/Support/ContribuicaoDePagamento.php`:
```php
<?php

namespace Modules\Financeiro\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Uma parcela que conta para o valor pago de uma propina, com a data efectiva do pagamento (ou da
 * utilização de crédito). A data decide `capital_liquidado_em` e, em F5, o saldo numa data.
 */
final class ContribuicaoDePagamento
{
    public function __construct(
        public readonly Dinheiro $valor,
        public readonly CarbonImmutable $data,
    ) {
        if ($valor->unidadesMenores() <= 0) {
            throw new InvalidArgumentException('Uma contribuição para o valor pago tem de ser maior que zero.');
        }
    }
}
```

`Modules/Financeiro/app/Support/FontesDePagamentoDePropina.php`:
```php
<?php

namespace Modules\Financeiro\Support;

use Illuminate\Contracts\Container\Container;
use Modules\Financeiro\Contracts\FonteDePagamentoDePropina;
use Modules\Financeiro\Models\Propina;

class FontesDePagamentoDePropina
{
    public const ETIQUETA = 'financeiro.fontes-pagamento-propina';

    public function __construct(private Container $container)
    {
    }

    /**
     * Contribuições de todas as fontes, por data crescente (ordenação estável: empates mantêm a ordem
     * devolvida pelas fontes). Sem fontes registadas: lista vazia.
     *
     * @return list<ContribuicaoDePagamento>
     */
    public function contribuicoes(Propina $propina): array
    {
        $todas = [];

        foreach ($this->container->tagged(self::ETIQUETA) as $fonte) {
            /** @var FonteDePagamentoDePropina $fonte */
            foreach ($fonte->contribuicoes($propina) as $contribuicao) {
                $todas[] = $contribuicao;
            }
        }

        usort($todas, fn (ContribuicaoDePagamento $a, ContribuicaoDePagamento $b) => $a->data->toDateString() <=> $b->data->toDateString());

        return $todas;
    }
}
```

`Modules/Financeiro/app/Support/BloqueioDePropinas.php`:
```php
<?php

namespace Modules\Financeiro\Support;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Financeiro\Models\Propina;

/**
 * Ponto único de bloqueio de propinas (RecalcularPropina, Pagamentos, alteração de preço, multas):
 * sempre por id crescente, para que duas operações concorrentes nunca se bloqueiem em ordens opostas.
 * SQLite ignora FOR UPDATE; o efeito real só existe em PostgreSQL.
 */
class BloqueioDePropinas
{
    /**
     * @param  list<int>  $ids
     * @return Collection<int, Propina>
     */
    public function bloquear(array $ids): Collection
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Bloquear propinas exige uma transacção aberta (DB::transaction) do chamador.');
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        if ($ids === []) {
            return new Collection();
        }

        return Propina::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
    }
}
```

`Modules/Financeiro/app/Services/RecalcularPropina.php`:
```php
<?php

namespace Modules\Financeiro\Services;

use LogicException;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Support\BloqueioDePropinas;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Support\FontesDePagamentoDePropina;

/**
 * ÚNICO escritor de `valor_pago`, de `estado` entre Em Aberto / Parcialmente Paga / Paga e de
 * `capital_liquidado_em` (spec Propinas §6). Corre dentro da transacção do chamador, com a propina
 * bloqueada; qualquer falha lança e o chamador reverte tudo (nunca se apanha a excepção lá dentro).
 * Devolve a instância relida e gravada: a que o chamador passou fica desactualizada.
 */
class RecalcularPropina
{
    public function __construct(
        private BloqueioDePropinas $bloqueio,
        private FontesDePagamentoDePropina $fontes,
    ) {
    }

    public function executar(Propina $propina): Propina
    {
        $bloqueada = $this->bloqueio->bloquear([(int) $propina->getKey()])->first()
            ?? throw new LogicException('A propina a recalcular não existe no tenant corrente.');

        $contribuicoes = $this->fontes->contribuicoes($bloqueada);

        if (in_array($bloqueada->estado, [EstadoCobranca::CANCELADA, EstadoCobranca::ANULADA], true)) {
            if ($contribuicoes !== []) {
                throw new LogicException('Uma propina cancelada ou anulada não pode ter pagamentos activos.');
            }

            return $bloqueada;
        }

        $valor = $bloqueada->valor->unidadesMenores();
        $pago = 0;
        $liquidadaEm = null;

        foreach ($contribuicoes as $contribuicao) {
            $pago += $contribuicao->valor->unidadesMenores();

            if ($liquidadaEm === null && $pago >= $valor) {
                $liquidadaEm = $contribuicao->data;
            }
        }

        if ($pago > $valor) {
            throw new LogicException("O valor pago ({$pago}) excede o valor da propina ({$valor}) na propina {$bloqueada->id}.");
        }

        $novoEstado = match (true) {
            $pago === 0 => EstadoCobranca::EM_ABERTO,
            $pago === $valor => EstadoCobranca::PAGA,
            default => EstadoCobranca::PARCIALMENTE_PAGA,
        };

        if ($novoEstado !== $bloqueada->estado && ! $bloqueada->estado->podeTransitarPara($novoEstado)) {
            throw new LogicException("Transição inválida de {$bloqueada->estado->label()} para {$novoEstado->label()}.");
        }

        $bloqueada->forceFill([
            'valor_pago' => Dinheiro::deUnidadesMenores($pago),
            'estado' => $novoEstado,
            'capital_liquidado_em' => $pago === $valor ? $liquidadaEm : null,
        ])->save();

        // F5: a multa da propina (se existir) é recalculada aqui, na mesma transacção.

        return $bloqueada;
    }
}
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Unit/ContribuicaoDePagamentoTest.php Modules/Financeiro/tests/Feature/RecalcularPropinaTest.php Modules/Financeiro/tests/Feature/PropinaModelTest.php`
Expected: PASS.

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Contracts/FonteDePagamentoDePropina.php Modules/Financeiro/app/Support/ContribuicaoDePagamento.php Modules/Financeiro/app/Support/FontesDePagamentoDePropina.php Modules/Financeiro/app/Support/BloqueioDePropinas.php Modules/Financeiro/app/Services/RecalcularPropina.php Modules/Financeiro/tests/Concerns/FontePagamentoFalsa.php Modules/Financeiro/tests/Concerns/ComPropinasFinanceiro.php Modules/Financeiro/tests/Unit/ContribuicaoDePagamentoTest.php Modules/Financeiro/tests/Feature/RecalcularPropinaTest.php
```

---

### Task 6: `SnapshotMonetario` e `PropinasReferenciam` (planos e moeda)

**Files:**
- Create: `Modules/Financeiro/app/Services/SnapshotMonetario.php`
- Create: `Modules/Financeiro/app/Support/PropinasReferenciam.php`
- Modify: `Modules/Financeiro/app/Providers/FinanceiroServiceProvider.php`
- Test: `Modules/Financeiro/tests/Feature/SnapshotMonetarioTest.php`, `Modules/Financeiro/tests/Feature/PropinasReferenciamTest.php`

**Interfaces:**
- Consumes: `MoedaDoTenant`, `CambioDoDia`, `ReferenciaFinanceira`, `ReferenciasFinanceiras`, `Propina`, `PlanoPropina`, `ConfiguracaoMonetaria`, `EliminarPlanoPropinaAction` (existe; passa a bloquear).
- Produces:
  - `SnapshotMonetario::em(CarbonInterface $data): array{moeda: string, cambio_usd: ?int}` — para F2 preencher cada propina.
  - `PropinasReferenciam implements ReferenciaFinanceira` (`PlanoPropina` → existe propina do plano; `ConfiguracaoMonetaria` → existe qualquer propina; restantes → false), etiquetada em `ReferenciasFinanceiras::ETIQUETA`.

- [ ] **Step 1: Escrever os testes que falham**

`Modules/Financeiro/tests/Feature/SnapshotMonetarioTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Financeiro\Models\CambioPlataforma;
use Modules\Financeiro\Models\ConfiguracaoMonetaria;
use Modules\Financeiro\Services\SnapshotMonetario;
use Modules\Financeiro\Support\TaxaCambio;
use Tests\TestCase;

class SnapshotMonetarioTest extends TestCase
{
    use RefreshDatabase;

    private function em(string $dia): array
    {
        return app(SnapshotMonetario::class)->em(CarbonImmutable::parse($dia));
    }

    public function test_sem_cambio_guarda_a_moeda_e_cambio_nulo(): void
    {
        $this->assertSame(['moeda' => 'AOA', 'cambio_usd' => null], $this->em('2026-10-05'));
    }

    public function test_usa_o_ultimo_cambio_ate_a_data_e_nunca_um_posterior(): void
    {
        CambioPlataforma::create([
            'moeda_cotada' => 'AOA', 'moeda_base' => 'USD', 'data' => '2026-10-01',
            'taxa' => TaxaCambio::deDecimal('910')->micros(), 'fonte' => 'manual',
        ]);

        $this->assertSame(['moeda' => 'AOA', 'cambio_usd' => 910_000_000], $this->em('2026-10-05'));
        $this->assertSame(['moeda' => 'AOA', 'cambio_usd' => null], $this->em('2026-09-30'));
    }

    public function test_escola_em_usd_tem_cambio_um(): void
    {
        ConfiguracaoMonetaria::doTenant()->update(['moeda' => 'USD']);

        $this->assertSame(['moeda' => 'USD', 'cambio_usd' => 1_000_000], $this->em('2026-10-05'));
    }
}
```

`Modules/Financeiro/tests/Feature/PropinasReferenciamTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\ConfiguracaoMonetaria;
use Modules\Financeiro\Models\MetodoPagamento;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Support\PropinasReferenciam;
use Modules\Financeiro\Support\ReferenciasFinanceiras;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Tests\TestCase;

class PropinasReferenciamTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    public function test_um_plano_so_e_referenciado_pelas_suas_propinas_em_qualquer_estado(): void
    {
        ['ano' => $ano, 'plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $semPropinas = $this->plano($ano, 'Sem Propinas');
        $referencias = app(PropinasReferenciam::class);

        $this->assertFalse($referencias->existeReferenciaA($plano));

        $this->comEstado($this->propina($matricula, $plano), EstadoCobranca::CANCELADA);

        $this->assertTrue($referencias->existeReferenciaA($plano));
        $this->assertFalse($referencias->existeReferenciaA($semPropinas));
    }

    public function test_a_moeda_fica_referenciada_por_qualquer_propina_e_so_por_propinas(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $configuracao = ConfiguracaoMonetaria::doTenant();
        $referencias = app(PropinasReferenciam::class);

        $this->assertFalse($referencias->existeReferenciaA($configuracao)); // planos são preços (FonteDePrecos), não registos

        $this->propina($matricula, $plano);

        $this->assertTrue($referencias->existeReferenciaA($configuracao));
        $this->assertFalse(app(MoedaDoTenant::class)->podeAlterar());
    }

    public function test_outras_configuracoes_nao_sao_referenciadas(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->propina($matricula, $plano);

        $this->assertFalse(app(PropinasReferenciam::class)->existeReferenciaA(new MetodoPagamento()));
    }

    public function test_esta_registada_na_etiqueta_das_referencias_financeiras(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->propina($matricula, $plano);

        $this->assertTrue(app(ReferenciasFinanceiras::class)->existeReferenciaA($plano));
        $this->assertTrue(app(ReferenciasFinanceiras::class)->existeReferenciaA(ConfiguracaoMonetaria::doTenant()));
    }

    public function test_propinas_de_outro_tenant_nao_contam(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, function () {
            ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
            $this->propina($matricula, $plano);
        });

        $this->assertFalse(app(PropinasReferenciam::class)->existeReferenciaA(ConfiguracaoMonetaria::doTenant()));
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/SnapshotMonetarioTest.php Modules/Financeiro/tests/Feature/PropinasReferenciamTest.php`
Expected: FAIL (classes inexistentes).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Services/SnapshotMonetario.php`:
```php
<?php

namespace Modules\Financeiro\Services;

use Carbon\CarbonInterface;

/**
 * Moeda e câmbio a copiar para um registo operacional no momento em que nasce (contrato de snapshot do
 * spec de Configuração, "Moeda e Câmbio"): a moeda da escola e o câmbio de referência (1 USD = X, em
 * micros de TaxaCambio) do último dia com câmbio até $data, ou null. Nunca bloqueia.
 */
class SnapshotMonetario
{
    public function __construct(
        private MoedaDoTenant $moedaDoTenant,
        private CambioDoDia $cambioDoDia,
    ) {
    }

    /**
     * @return array{moeda: string, cambio_usd: ?int}
     */
    public function em(CarbonInterface $data): array
    {
        return [
            'moeda' => $this->moedaDoTenant->atual()->codigo,
            'cambio_usd' => $this->cambioDoDia->para($data)?->micros(),
        ];
    }
}
```

`Modules/Financeiro/app/Support/PropinasReferenciam.php`:
```php
<?php

namespace Modules\Financeiro\Support;

use Illuminate\Database\Eloquent\Model;
use Modules\Financeiro\Contracts\ReferenciaFinanceira;
use Modules\Financeiro\Models\ConfiguracaoMonetaria;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;

/**
 * Propinas são registos operacionais: um plano com propinas (em qualquer estado) não se elimina, e a
 * moeda da escola não muda enquanto existir qualquer propina (o contrato exige-o para a
 * ConfiguracaoMonetaria). Matrícula, turma e ano lectivo não são configuração financeira: ficam
 * protegidos pela FK restrict, pelo módulo Matricula (só Pendentes se eliminam) e pelos planos.
 */
class PropinasReferenciam implements ReferenciaFinanceira
{
    public function existeReferenciaA(Model $configuracao): bool
    {
        return match (true) {
            $configuracao instanceof PlanoPropina => Propina::query()->where('plano_propina_id', $configuracao->getKey())->exists(),
            $configuracao instanceof ConfiguracaoMonetaria => Propina::query()->exists(),
            default => false,
        };
    }
}
```

Em `Modules/Financeiro/app/Providers/FinanceiroServiceProvider.php`:
1. Acrescentar aos `use`:
```php
use Modules\Financeiro\Support\PropinasReferenciam;
use Modules\Financeiro\Support\ReferenciasFinanceiras;
```
2. No fim de `register()`, depois da linha de `FontesDePrecos::ETIQUETA`:
```php
        $this->app->tag([PropinasReferenciam::class], ReferenciasFinanceiras::ETIQUETA);
```
(**Não** se etiqueta nenhuma `FonteDePagamentoDePropina` em produção: Pagamentos-A fá-lo.)

- [ ] **Step 4: Correr e ver passar (incluindo os testes de referências e moeda existentes)**

Run: `php artisan test Modules/Financeiro/tests/Feature/SnapshotMonetarioTest.php Modules/Financeiro/tests/Feature/PropinasReferenciamTest.php Modules/Financeiro/tests/Feature/ReferenciasFinanceirasTest.php Modules/Financeiro/tests/Feature/MoedaDoTenantTest.php Modules/Financeiro/tests/Feature/PlanoPropinaTest.php`
Expected: PASS.

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Services/SnapshotMonetario.php Modules/Financeiro/app/Support/PropinasReferenciam.php Modules/Financeiro/app/Providers/FinanceiroServiceProvider.php Modules/Financeiro/tests/Feature/SnapshotMonetarioTest.php Modules/Financeiro/tests/Feature/PropinasReferenciamTest.php
```

---

### Task 7: Planos com propinas — valor e calendário bloqueados, eliminação recusada

**Files:**
- Modify: `Modules/Financeiro/app/Actions/AtualizarPlanoPropinaAction.php` (reescrito)
- Test: `Modules/Financeiro/tests/Feature/PlanoPropinaComPropinasTest.php`

**Interfaces:**
- Consumes: `PlanoPropina::propinas()` (T3), `PropinasReferenciam` (T6), `PlanoPropinaDTO`, `EliminarPlanoPropinaAction`.
- Produces: `AtualizarPlanoPropinaAction::MENSAGEM_VALOR`, `::MENSAGEM_CALENDARIO`; a Action bloqueia o plano (`lockForUpdate`) e, se tiver propinas (qualquer estado), recusa com `ValidationException` (chaves `valor` e/ou `periodicidade`) mudar `valor`, `periodicidade`, `intervalo_meses`, `mes_inicio`, `mes_fim`. Nome, descrição e alvos continuam editáveis.

- [ ] **Step 1: Escrever o teste que falha**

`Modules/Financeiro/tests/Feature/PlanoPropinaComPropinasTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Actions\AtualizarPlanoPropinaAction;
use Modules\Financeiro\DTO\PlanoPropinaDTO;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\Periodicidade;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlanoPropinaComPropinasTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    private const BASE = 'financeiro.configuracao.planos-propina.';

    private const MSG_VALOR = 'Este plano já tem propinas geradas: o valor não pode ser alterado na edição do plano. As propinas existentes mantêm o seu valor.';

    private const MSG_CALENDARIO = 'Este plano já tem propinas geradas: a periodicidade e os meses de início e de fim não podem ser alterados, porque definem os períodos das propinas existentes.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    /** O payload que mantém tudo como está (plano geral Set → Jun, 25.000,00, mensal). */
    private function payload(PlanoPropina $plano, array $sobrepor = []): array
    {
        return array_merge([
            'nome' => $plano->nome,
            'descricao' => null,
            'periodicidade' => $plano->periodicidade->value,
            'valor' => '25000',
            'mes_inicio' => $plano->mes_inicio,
            'mes_fim' => $plano->mes_fim,
            'alvos' => [],
            'confirmar_plano_geral' => true,
        ], $sobrepor);
    }

    /** @return array{0: PlanoPropina, 1: Propina, 2: array<string, mixed>} */
    private function planoComPropina(): array
    {
        $cenario = $this->cenarioPropinas();

        return [$cenario['plano'], $this->propina($cenario['matricula'], $cenario['plano']), $cenario];
    }

    public function test_com_propinas_o_valor_nao_muda_e_nada_e_gravado(): void
    {
        [$plano, $propina] = $this->planoComPropina();

        $this->actingAs($this->adminEscola())->from('/x')
            ->put(route(self::BASE . 'update', $plano), $this->payload($plano, ['valor' => '30000', 'nome' => 'Renomeado']))
            ->assertSessionHasErrors(['valor' => self::MSG_VALOR]);

        $this->assertSame(2_500_000, $plano->fresh()->valor->unidadesMenores());
        $this->assertSame('Propina Mensal', $plano->fresh()->nome); // tudo ou nada
        $this->assertSame(2_500_000, $propina->fresh()->valor->unidadesMenores());
    }

    #[DataProvider('alteracoesDeCalendario')]
    public function test_com_propinas_o_calendario_nao_muda(array $sobrepor): void
    {
        [$plano] = $this->planoComPropina();

        $this->actingAs($this->adminEscola())->from('/x')
            ->put(route(self::BASE . 'update', $plano), $this->payload($plano, $sobrepor))
            ->assertSessionHasErrors(['periodicidade' => self::MSG_CALENDARIO]);

        $plano->refresh();
        $this->assertSame(Periodicidade::MENSAL, $plano->periodicidade);
        $this->assertSame([1, 9, 6], [$plano->intervalo_meses, $plano->mes_inicio, $plano->mes_fim]);
    }

    public static function alteracoesDeCalendario(): array
    {
        return [
            'periodicidade' => [['periodicidade' => Periodicidade::TRIMESTRAL->value]],
            'mês de início' => [['mes_inicio' => 10]],
            'mês de fim' => [['mes_fim' => 7]],
            'outra periodicidade' => [['periodicidade' => Periodicidade::OUTRA->value, 'intervalo_meses' => 2]],
        ];
    }

    public function test_com_propinas_nome_descricao_e_alvos_continuam_editaveis_sem_tocar_nas_propinas(): void
    {
        [$plano, $propina, $cenario] = $this->planoComPropina();
        $antes = $propina->fresh()->getAttributes();

        $this->actingAs($this->adminEscola())->from('/x')
            ->put(route(self::BASE . 'update', $plano), $this->payload($plano, [
                'nome' => 'Propina Mensal (revista)',
                'descricao' => 'Só gerações futuras',
                'alvos' => [['nivel_academico_id' => $cenario['turma']->nivel_academico_id]],
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Propina Mensal (revista)', $plano->fresh()->nome);
        $this->assertSame(1, $plano->alvos()->count());
        $this->assertSame($antes, $propina->fresh()->getAttributes());
    }

    public function test_uma_propina_cancelada_basta_para_bloquear(): void
    {
        [$plano, $propina] = $this->planoComPropina();
        $this->comEstado($propina, EstadoCobranca::CANCELADA);

        $this->actingAs($this->adminEscola())->from('/x')
            ->put(route(self::BASE . 'update', $plano), $this->payload($plano, ['valor' => '30000']))
            ->assertSessionHasErrors(['valor' => self::MSG_VALOR]);
    }

    public function test_sem_propinas_valor_e_calendario_continuam_editaveis(): void
    {
        $plano = $this->plano($this->anoLectivo(), 'Sem Propinas');

        $this->actingAs($this->adminEscola())->from('/x')
            ->put(route(self::BASE . 'update', $plano), $this->payload($plano, ['valor' => '30000', 'periodicidade' => Periodicidade::TRIMESTRAL->value]))
            ->assertSessionHasNoErrors();

        $this->assertSame(3_000_000, $plano->fresh()->valor->unidadesMenores());
        $this->assertSame(3, $plano->fresh()->intervalo_meses);
    }

    public function test_a_action_recusa_mesmo_sem_passar_pelo_pedido_http(): void
    {
        [$plano] = $this->planoComPropina();
        $dto = new PlanoPropinaDTO(
            nome: $plano->nome,
            periodicidade: Periodicidade::MENSAL,
            intervalo_meses: 1,
            valor: Dinheiro::deUnidadesMenores(3_000_000),
            mes_inicio: 10,
            mes_fim: 6,
            alvos: [],
        );

        try {
            app(AtualizarPlanoPropinaAction::class)->executar($plano, $dto);
            $this->fail('A Action devia recusar.');
        } catch (ValidationException $e) {
            $this->assertSame(['valor' => [self::MSG_VALOR], 'periodicidade' => [self::MSG_CALENDARIO]], $e->errors());
        }

        $this->assertSame(2_500_000, $plano->fresh()->valor->unidadesMenores());
        $this->assertSame(9, $plano->fresh()->mes_inicio);
    }

    public function test_eliminar_um_plano_com_propinas_e_bloqueado(): void
    {
        [$plano] = $this->planoComPropina();

        $this->actingAs($this->adminEscola())->from('/x')
            ->delete(route(self::BASE . 'destroy', $plano))
            ->assertSessionHasErrors(['eliminar' => 'Não é possível eliminar este plano de propina: já existem registos financeiros que o utilizam. Desative-o.']);

        $this->assertNotNull(PlanoPropina::query()->find($plano->id));
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/PlanoPropinaComPropinasTest.php`
Expected: FAIL nos testes de valor/calendário/Action (a edição genérica ainda aceita tudo). O de eliminação já passa (T6).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Actions/AtualizarPlanoPropinaAction.php` (ficheiro completo):
```php
<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\DTO\PlanoPropinaDTO;
use Modules\Financeiro\Models\PlanoPropina;

class AtualizarPlanoPropinaAction
{
    public const MENSAGEM_VALOR = 'Este plano já tem propinas geradas: o valor não pode ser alterado na edição do plano. As propinas existentes mantêm o seu valor.';

    public const MENSAGEM_CALENDARIO = 'Este plano já tem propinas geradas: a periodicidade e os meses de início e de fim não podem ser alterados, porque definem os períodos das propinas existentes.';

    public function executar(PlanoPropina $plano, PlanoPropinaDTO $dto): PlanoPropina
    {
        return DB::transaction(function () use ($plano, $dto) {
            // Bloqueia o plano: a geração de propinas (F2) e a alteração de preço (F4) também o bloqueiam.
            $bloqueado = PlanoPropina::query()->whereKey($plano->getKey())->lockForUpdate()->firstOrFail();

            if ($bloqueado->propinas()->exists()) {
                $this->recusarAlteracoesQueTocamPropinas($bloqueado, $dto);
            }

            $bloqueado->update([
                'nome' => $dto->nome,
                'descricao' => $dto->descricao,
                'periodicidade' => $dto->periodicidade,
                'intervalo_meses' => $dto->intervalo_meses,
                'valor' => $dto->valor,
                'mes_inicio' => $dto->mes_inicio,
                'mes_fim' => $dto->mes_fim,
            ]);

            $bloqueado->substituirAlvos($dto->alvos);

            return $bloqueado->fresh('alvos');
        });
    }

    /**
     * Um plano com propinas (em qualquer estado, mesmo só canceladas) mantém valor e calendário: o valor
     * só muda pela operação própria de alteração de preço (spec Propinas §14, "única porta de entrada"),
     * e o calendário define a ordem, o início e o fim das propinas já geradas (Q10). Nome, descrição e
     * alvos continuam editáveis: só afectam gerações futuras. A excepção é lançada dentro da transacção
     * e não é apanhada: reverte tudo (mesmo padrão de AtualizarConfiguracaoMonetariaAction).
     */
    private function recusarAlteracoesQueTocamPropinas(PlanoPropina $plano, PlanoPropinaDTO $dto): void
    {
        $erros = [];

        if ($plano->valor->unidadesMenores() !== $dto->valor->unidadesMenores()) {
            $erros['valor'] = self::MENSAGEM_VALOR;
        }

        $calendarioMuda = $plano->periodicidade !== $dto->periodicidade
            || (int) $plano->intervalo_meses !== $dto->intervalo_meses
            || (int) $plano->mes_inicio !== $dto->mes_inicio
            || (int) $plano->mes_fim !== $dto->mes_fim;

        if ($calendarioMuda) {
            $erros['periodicidade'] = self::MENSAGEM_CALENDARIO;
        }

        if ($erros !== []) {
            throw ValidationException::withMessages($erros);
        }
    }
}
```

- [ ] **Step 4: Correr e ver passar (incluindo os testes existentes de planos)**

Run: `php artisan test Modules/Financeiro/tests/Feature/PlanoPropinaComPropinasTest.php Modules/Financeiro/tests/Feature/PlanoPropinaTest.php Modules/Financeiro/tests/Feature/AlvosObsoletosTest.php Modules/Financeiro/tests/Feature/DividaAnoLectivoTest.php`
Expected: PASS.

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Actions/AtualizarPlanoPropinaAction.php Modules/Financeiro/tests/Feature/PlanoPropinaComPropinasTest.php
```

---

### Task 8: Permissão `propina` (`Modulo::PROPINA = 22`)

**Files:**
- Modify: `Modules/Permissao/app/Enums/Modulo.php`
- Modify: `Modules/Permissao/database/seeders/ModuloSeeder.php`
- Modify: `Modules/Permissao/app/Actions/SincronizarPerfisDeSistemaAction.php`
- Modify (tests): `Modules/Permissao/tests/Unit/ModuloEnumTest.php`, `Modules/Permissao/tests/Feature/ModuloSeederTest.php`, `Modules/Permissao/tests/Feature/AcoesAplicaveisTest.php`
- Test: `Modules/Financeiro/tests/Feature/PermissoesPropinaTest.php`

**Interfaces:**
- Consumes: catálogo `Acao` (12 acções, existe), `AcoesAplicaveis`, `PermissaoCache`, `financeiro:sincronizar`.
- Produces: `Modulo::PROPINA` (22, slug `propina`, label `Propinas`); `acoesAplicaveis()` = `['ver', 'listar', 'criar', 'cancelar', 'anular', 'exportar', 'ajustar']`; ADMIN_ESCOLA com `propina.{ver,listar,criar,cancelar,exportar}`. A grelha passa a ter 23 módulos e 9 colunas (6 base + `anular`, `cancelar`, `ajustar`).

- [ ] **Step 1: Escrever/ajustar os testes que falham**

`Modules/Financeiro/tests/Feature/PermissoesPropinaTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Modulo;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Modulo as ModuloRegistro;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class PermissoesPropinaTest extends TestCase
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

    public function test_modulo_tem_slug_label_e_acoes_proprias(): void
    {
        $this->assertSame(22, Modulo::PROPINA->value);
        $this->assertSame('propina', Modulo::PROPINA->slug());
        $this->assertSame(Modulo::PROPINA, Modulo::fromSlug('propina'));
        $this->assertSame('Propinas', Modulo::PROPINA->label());
        $this->assertSame(['ver', 'listar', 'criar', 'cancelar', 'anular', 'exportar', 'ajustar'], Modulo::PROPINA->acoesAplicaveis());
    }

    public function test_admin_escola_tem_so_as_acoes_de_f1(): void
    {
        $admin = $this->utilizadorCom(Perfil::ADMIN_ESCOLA);

        foreach (['ver', 'listar', 'criar', 'cancelar', 'exportar'] as $acao) {
            $this->assertTrue(Gate::forUser($admin)->allows("propina.{$acao}"), $acao);
        }

        foreach (['anular', 'ajustar', 'editar', 'eliminar', 'negociar', 'isentar-multa'] as $acao) {
            $this->assertFalse(Gate::forUser($admin)->allows("propina.{$acao}"), $acao);
        }
    }

    public function test_funcionario_e_professor_nao_acedem(): void
    {
        foreach ([Perfil::FUNCIONARIO, Perfil::PROFESSOR] as $perfil) {
            $this->assertFalse(Gate::forUser($this->utilizadorCom($perfil))->allows('propina.ver'), $perfil->name);
        }
    }

    public function test_sincronizar_concede_as_permissoes_a_tenants_existentes_e_invalida_a_cache(): void
    {
        $admin = $this->utilizadorCom(Perfil::ADMIN_ESCOLA);
        // Simula um tenant criado antes de F1: sem nenhuma permissão de propina.
        RolePermissao::query()->where('modulo_id', ModuloRegistro::where('nome', Modulo::PROPINA->value)->value('id'))->delete();
        $this->assertTrue(Gate::forUser($admin)->denies('propina.ver')); // aquece a cache

        $this->artisan('financeiro:sincronizar', ['--tenant' => $this->tenant->codigo])->assertSuccessful();

        $this->assertTrue(Gate::forUser($admin)->allows('propina.ver'));
        $this->assertTrue(Gate::forUser($admin)->allows('propina.cancelar'));
        $this->assertFalse(Gate::forUser($admin)->allows('propina.ajustar'));
    }
}
```

Em `Modules/Permissao/tests/Unit/ModuloEnumTest.php`, acrescentar no fim da classe:
```php
    public function test_propina_slug_e_label(): void
    {
        $this->assertSame('propina', Modulo::PROPINA->slug());
        $this->assertSame('Propinas', Modulo::PROPINA->label());
        $this->assertSame(Modulo::PROPINA, Modulo::fromSlug('propina'));
        $this->assertSame(Modulo::PROPINA, Modulo::tryFrom(22));
    }
```

Em `Modules/Permissao/tests/Feature/ModuloSeederTest.php`:
1. Substituir `        $this->assertSame(22, Modulo::count());` por `        $this->assertSame(23, Modulo::count());`.
2. Acrescentar, depois de `test_seeder_inclui_o_modulo_plano_curricular`:
```php
    public function test_seeder_inclui_o_modulo_propina(): void
    {
        $this->seed(ModuloSeeder::class);

        $this->assertDatabaseHas('modulos', ['nome' => 22, 'descricao' => 'Propina']);
    }
```

Em `Modules/Permissao/tests/Feature/AcoesAplicaveisTest.php`:
1. Substituir o teste `test_todos_os_modulos_existentes_aplicam_exactamente_as_acoes_actuais` inteiro por:
```php
    public function test_os_modulos_sem_acoes_proprias_aplicam_as_acoes_base(): void
    {
        foreach (ModuloEnum::cases() as $modulo) {
            if ($modulo === ModuloEnum::PROPINA) {
                continue;
            }

            $this->assertEqualsCanonicalizing(self::BASE, $modulo->acoesAplicaveis(), $modulo->name);
        }
    }

    public function test_propina_aplica_so_as_suas_acoes(): void
    {
        $this->assertEqualsCanonicalizing(
            ['ver', 'listar', 'criar', 'cancelar', 'anular', 'exportar', 'ajustar'],
            ModuloEnum::PROPINA->acoesAplicaveis(),
        );

        foreach (['editar', 'eliminar', 'negociar', 'isentar-multa', 'confirmar'] as $acao) {
            $this->assertNotContains($acao, ModuloEnum::PROPINA->acoesAplicaveis(), $acao);
        }
    }
```
2. Em `test_grelha_do_perfil_mostra_so_acoes_aplicaveis`: `->has('acoes', 6)` → `->has('acoes', 9)` e `->has('modulos', 22)` → `->has('modulos', 23)` (a coluna `ver` continua a primeira; `modulos.0` continua a ser Utilizadores, só com as 6 base).
3. Em `test_grelha_do_utilizador_mostra_so_acoes_aplicaveis`: `->has('acoes', 6)` → `->has('acoes', 9)` (manter `->has('modulos.0.acoes', 6)`).
4. Em `test_modulo_futuro_com_extras_aparece_na_grelha_so_nele`: `->has('acoes', 8)` → `->has('acoes', 9)` (as extras do fixture já estão em uso pela PROPINA, mais `ajustar`; `PLANO_PROPINA` continua com 8 e `CURSO` com 6).

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/PermissoesPropinaTest.php Modules/Permissao/tests`
Expected: FAIL (`Modulo::PROPINA` inexistente).

- [ ] **Step 3: Implementar**

Em `Modules/Permissao/app/Enums/Modulo.php`:
1. Depois de `    case MOEDA_CAMBIO = 21;` acrescentar `    case PROPINA = 22;`.
2. Substituir o docblock e o corpo de `acoesAplicaveis()` por:
```php
    /**
     * Fonte única das acções aplicáveis a cada módulo. A grelha de permissões
     * só mostra estas, e o servidor rejeita conceder/negar qualquer outra.
     *
     * Por omissão, todos os módulos aplicam ACOES_BASE. Um módulo com acções
     * próprias declara-as aqui (lista explícita, sem herdar a base). Ver
     * docs/superpowers/plans/2026-10-12-divida-catalogo-acoes-fk.md.
     *
     * @return list<string> nomes de `acoes.nome`
     */
    public function acoesAplicaveis(): array
    {
        return match ($this) {
            // Uma propina nunca se edita nem se elimina (cancela-se, anula-se ou ajusta-se). Sem `negociar`
            // (spec de Dívidas, inexistente) e sem `isentar-multa` (entra com as multas, F5).
            self::PROPINA => ['ver', 'listar', 'criar', 'cancelar', 'anular', 'exportar', 'ajustar'],
            default => self::ACOES_BASE,
        };
    }
```
3. Em `slug()`, depois de `self::MOEDA_CAMBIO => 'moeda-cambio',` acrescentar `            self::PROPINA => 'propina',`.
4. Em `label()`, depois de `self::MOEDA_CAMBIO => 'Moeda e Câmbio',` acrescentar `            self::PROPINA => 'Propinas',`.

Em `Modules/Permissao/database/seeders/ModuloSeeder.php`, depois de `['nome' => 21, 'descricao' => 'Moeda e Cambio'],` acrescentar:
```php
            ['nome' => 22, 'descricao' => 'Propina'],
```

Em `Modules/Permissao/app/Actions/SincronizarPerfisDeSistemaAction.php`, em `PERMISSOES_POR_PERFIL[Perfil::ADMIN_ESCOLA->value]`, depois da linha de `Modulo::MOEDA_CAMBIO`, acrescentar:
```php
            // F1 de Propinas; `ajustar` entra com a alteração de preço (F4), `anular` com Pagamentos.
            Modulo::PROPINA->value => ['ver', 'listar', 'criar', 'cancelar', 'exportar'],
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Feature/PermissoesPropinaTest.php Modules/Permissao/tests tests/Feature/RotasPermissaoReconhecidaTest.php tests/Feature/Provisioning tests/Feature/Comandos`
Expected: PASS.

- [ ] **Step 5: Stage**

```bash
git add Modules/Permissao/app/Enums/Modulo.php Modules/Permissao/database/seeders/ModuloSeeder.php Modules/Permissao/app/Actions/SincronizarPerfisDeSistemaAction.php Modules/Permissao/tests/Unit/ModuloEnumTest.php Modules/Permissao/tests/Feature/ModuloSeederTest.php Modules/Permissao/tests/Feature/AcoesAplicaveisTest.php Modules/Financeiro/tests/Feature/PermissoesPropinaTest.php
```

---

### Task 9: Planos — valor por período, duração dos períodos e total (Q3)

**Files:**
- Modify: `Modules/Financeiro/app/Support/CalendarioDePlano.php` (+ `duracoes`)
- Modify: `Modules/Financeiro/app/Models/PlanoPropina.php` (+ `duracoesDosPeriodos`, `ultimoPeriodoMaisCurto`, `valorTotal`)
- Modify: `Modules/Financeiro/app/Services/PlanoPropinaConsultaService.php` (+ campos)
- Modify: `Modules/Financeiro/resources/js/Support/dinheiro.js`
- Create: `Modules/Financeiro/resources/js/Support/periodos.js`
- Modify: `Modules/Financeiro/resources/js/Pages/PlanosPropina/Index.vue`
- Modify: `Modules/Financeiro/resources/js/Components/PlanosPropina/PlanoPropinaFormModal.vue`
- Test: `Modules/Financeiro/tests/Unit/CalendarioDePlanoTest.php` (+ testes), `Modules/Financeiro/tests/Feature/PlanoPropinaPeriodosTest.php`

**Interfaces:**
- Consumes: `PlanoPropina::propinas()` (T3), `Dinheiro`.
- Produces:
  - `CalendarioDePlano::duracoes(int $mesInicio, int $mesFim, int $intervaloMeses): list<int>` (não depende do ano lectivo; igual a `array_column(periodos(...), 'meses')`).
  - `PlanoPropina::duracoesDosPeriodos(): list<int>`, `ultimoPeriodoMaisCurto(): bool`, `valorTotal(): Dinheiro` (= valor × nº de períodos; o último período curto cobra o valor inteiro, Q3).
  - Cada linha de `planos` na lista ganha `duracoes_periodos`, `ultimo_periodo_mais_curto`, `valor_total` (unidades menores) e `tem_propinas`.
  - JS: `formatarDinheiro` aceita `Number`, `BigInt` ou texto de dígitos; `decimalParaUnidadesMenores(texto, moeda): BigInt|null`; `periodos.js` com `duracoesDosPeriodos(mesInicio, mesFim, intervalo)` e `resumoPeriodos(duracoes)`.

- [ ] **Step 1: Escrever os testes que falham**

Em `Modules/Financeiro/tests/Unit/CalendarioDePlanoTest.php`, acrescentar no fim da classe:
```php
    public function test_duracoes_dos_periodos_com_o_ultimo_mais_curto(): void
    {
        $this->assertSame([3, 3, 3, 1], CalendarioDePlano::duracoes(9, 6, 3));   // Set → Jun trimestral
        $this->assertSame(array_fill(0, 10, 1), CalendarioDePlano::duracoes(9, 6, 1));
        $this->assertSame([6, 4], CalendarioDePlano::duracoes(9, 6, 6));
        $this->assertSame([12], CalendarioDePlano::duracoes(1, 12, 12));
        $this->assertSame([2, 2, 2, 2, 2], CalendarioDePlano::duracoes(9, 6, 2));
    }

    public function test_duracoes_coincidem_com_os_periodos_para_qualquer_combinacao(): void
    {
        $inicio = CarbonImmutable::parse('2026-09-01');

        for ($mesInicio = 1; $mesInicio <= 12; $mesInicio++) {
            for ($mesFim = 1; $mesFim <= 12; $mesFim++) {
                for ($intervalo = 1; $intervalo <= 12; $intervalo++) {
                    $this->assertSame(
                        array_column(CalendarioDePlano::periodos($mesInicio, $mesFim, $intervalo, $inicio), 'meses'),
                        CalendarioDePlano::duracoes($mesInicio, $mesFim, $intervalo),
                        "{$mesInicio} → {$mesFim} de {$intervalo} em {$intervalo}",
                    );
                }
            }
        }
    }

    public function test_duracoes_recusam_intervalo_fora_de_1_a_12(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CalendarioDePlano::duracoes(9, 6, 0);
    }
```

`Modules/Financeiro/tests/Feature/PlanoPropinaPeriodosTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Financeiro\Enums\Periodicidade;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

class PlanoPropinaPeriodosTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    public function test_o_plano_conhece_as_duracoes_o_aviso_e_o_total(): void
    {
        $ano = $this->anoLectivo();
        $trimestral = $this->plano($ano, 'Trimestral', ['periodicidade' => Periodicidade::TRIMESTRAL, 'intervalo_meses' => 3]);
        $mensal = $this->plano($ano, 'Mensal');

        $this->assertSame([3, 3, 3, 1], $trimestral->duracoesDosPeriodos());
        $this->assertSame(array_column($trimestral->periodos(), 'meses'), $trimestral->duracoesDosPeriodos());
        $this->assertTrue($trimestral->ultimoPeriodoMaisCurto());
        $this->assertSame(10_000_000, $trimestral->valorTotal()->unidadesMenores()); // 4 × 25.000,00 (valor integral no último)

        $this->assertSame(array_fill(0, 10, 1), $mensal->duracoesDosPeriodos());
        $this->assertFalse($mensal->ultimoPeriodoMaisCurto());
        $this->assertSame(25_000_000, $mensal->valorTotal()->unidadesMenores());
    }

    public function test_a_lista_expoe_duracoes_total_aviso_e_se_o_plano_tem_propinas(): void
    {
        ['ano' => $ano, 'plano' => $mensal, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->plano($ano, 'Trimestral', ['periodicidade' => Periodicidade::TRIMESTRAL, 'intervalo_meses' => 3]);
        $this->propina($matricula, $mensal);

        $this->actingAs($this->adminEscola())
            ->get(route('financeiro.configuracao.planos-propina.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('planos.data', 2)
                ->where('planos.data.0.nome', 'Propina Mensal')
                ->where('planos.data.0.duracoes_periodos', array_fill(0, 10, 1))
                ->where('planos.data.0.ultimo_periodo_mais_curto', false)
                ->where('planos.data.0.valor_total', 25_000_000)
                ->where('planos.data.0.tem_propinas', true)
                ->where('planos.data.1.nome', 'Trimestral')
                ->where('planos.data.1.duracoes_periodos', [3, 3, 3, 1])
                ->where('planos.data.1.ultimo_periodo_mais_curto', true)
                ->where('planos.data.1.valor_total', 10_000_000)
                ->where('planos.data.1.periodos_total', 4)
                ->where('planos.data.1.tem_propinas', false));
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Unit/CalendarioDePlanoTest.php Modules/Financeiro/tests/Feature/PlanoPropinaPeriodosTest.php`
Expected: FAIL (métodos e campos inexistentes).

- [ ] **Step 3: Implementar o backend**

Em `Modules/Financeiro/app/Support/CalendarioDePlano.php`, depois de `periodos()`, acrescentar:
```php
    /**
     * Duração, em meses, de cada período de cobrança; o último pode ser mais curto (ex.: Set → Jun
     * trimestral = 3+3+3+1). Não depende do ano lectivo.
     *
     * @return list<int>
     */
    public static function duracoes(int $mesInicio, int $mesFim, int $intervaloMeses): array
    {
        if ($intervaloMeses < 1 || $intervaloMeses > 12) {
            throw new InvalidArgumentException('O intervalo de cobrança tem de estar entre 1 e 12 meses.');
        }

        $total = self::meses($mesInicio, $mesFim);
        $duracoes = array_fill(0, intdiv($total, $intervaloMeses), $intervaloMeses);

        if ($total % $intervaloMeses !== 0) {
            $duracoes[] = $total % $intervaloMeses;
        }

        return $duracoes;
    }
```

Em `Modules/Financeiro/app/Models/PlanoPropina.php`:
1. Acrescentar aos `use`: `use Modules\Financeiro\Support\Dinheiro;`
2. Depois de `periodos()`, acrescentar:
```php
    /**
     * @return list<int>
     */
    public function duracoesDosPeriodos(): array
    {
        return CalendarioDePlano::duracoes($this->mes_inicio, $this->mes_fim, $this->intervalo_meses);
    }

    public function ultimoPeriodoMaisCurto(): bool
    {
        $duracoes = $this->duracoesDosPeriodos();

        return $duracoes !== [] && $duracoes[array_key_last($duracoes)] < $this->intervalo_meses;
    }

    /**
     * Valor por período × número de períodos: o último período, mesmo mais curto, cobra o valor inteiro (Q3).
     */
    public function valorTotal(): Dinheiro
    {
        return Dinheiro::deUnidadesMenores($this->valor->unidadesMenores() * count($this->duracoesDosPeriodos()));
    }
```

Em `Modules/Financeiro/app/Services/PlanoPropinaConsultaService.php`, no método `listar()`:
1. Substituir `            ->with(['anoLectivo', 'alvos.nivelAcademico', 'alvos.curso', 'alvos.turno', 'alvos.turma'])` por:
```php
            ->with(['anoLectivo', 'alvos.nivelAcademico', 'alvos.curso', 'alvos.turno', 'alvos.turma'])
            ->withExists('propinas')
```
2. Substituir a linha `                'periodos_total' => count($plano->periodos()),` por:
```php
                'periodos_total' => count($plano->periodos()),
                'duracoes_periodos' => $plano->duracoesDosPeriodos(),
                'ultimo_periodo_mais_curto' => $plano->ultimoPeriodoMaisCurto(),
                'valor_total' => $plano->valorTotal()->unidadesMenores(),
                'tem_propinas' => (bool) $plano->propinas_exists,
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Unit/CalendarioDePlanoTest.php Modules/Financeiro/tests/Feature/PlanoPropinaPeriodosTest.php Modules/Financeiro/tests/Feature/PlanoPropinaTest.php`
Expected: PASS.

- [ ] **Step 5: Implementar o frontend**

`Modules/Financeiro/resources/js/Support/dinheiro.js` (ficheiro completo):
```js
/**
 * Valores monetários chegam do backend em unidades menores da moeda (inteiro). `moeda` é o objeto
 * que o backend envia: { codigo, nome, simbolo, decimais }. Estes helpers só apresentam ou
 * preenchem inputs: a conversão autoritativa é Dinheiro::deDecimal no backend.
 * Os cálculos usam BigInt, para totais acima de Number.MAX_SAFE_INTEGER não perderem precisão.
 */
export function formatarDinheiro(unidadesMenores, moeda) {
    const fator = 10n ** BigInt(moeda.decimais);
    let valor = BigInt(unidadesMenores ?? 0);
    if (valor < 0n) valor = -valor;
    const milhares = (valor / fator).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    if (moeda.decimais === 0) {
        return `${milhares} ${moeda.simbolo}`;
    }

    const fraccao = (valor % fator).toString().padStart(moeda.decimais, '0');

    return `${milhares},${fraccao} ${moeda.simbolo}`;
}

export function unidadesMenoresParaDecimal(unidadesMenores, moeda) {
    const fator = 10 ** moeda.decimais;
    const valor = Math.abs(Number(unidadesMenores ?? 0));
    const inteira = Math.trunc(valor / fator);

    if (moeda.decimais === 0) {
        return String(inteira);
    }

    return `${inteira}.${String(valor % fator).padStart(moeda.decimais, '0')}`;
}

/**
 * Texto do input → unidades menores (BigInt), com as regras de Dinheiro::deDecimal (até 12 dígitos
 * inteiros, "." ou "," e até N casas, N = casas da moeda). null se inválido. Só para pré-visualizar.
 */
export function decimalParaUnidadesMenores(texto, moeda) {
    const padrao = moeda.decimais === 0
        ? /^(\d{1,12})$/
        : new RegExp(`^(\\d{1,12})(?:[.,](\\d{1,${moeda.decimais}}))?$`);
    const partes = String(texto ?? '').trim().match(padrao);

    if (!partes) return null;

    const fraccao = partes[2] ? BigInt(partes[2].padEnd(moeda.decimais, '0')) : 0n;

    return BigInt(partes[1]) * 10n ** BigInt(moeda.decimais) + fraccao;
}
```

`Modules/Financeiro/resources/js/Support/periodos.js`:
```js
/**
 * Espelho de CalendarioDePlano::duracoes (PHP): duração, em meses, de cada período de cobrança.
 * O último período pode ser mais curto. Não depende do ano lectivo. Intervalo inválido → [].
 */
export function duracoesDosPeriodos(mesInicio, mesFim, intervalo) {
    const n = Number(intervalo);
    if (!Number.isInteger(n) || n < 1 || n > 12) return [];

    const total = ((Number(mesFim) - Number(mesInicio) + 12) % 12) + 1;
    const duracoes = Array(Math.floor(total / n)).fill(n);
    if (total % n !== 0) duracoes.push(total % n);

    return duracoes;
}

/** "4 períodos (3+3+3+1 meses)" / "1 período (12 meses)". */
export function resumoPeriodos(duracoes) {
    if (!duracoes?.length) return '';

    const quantidade = duracoes.length === 1 ? '1 período' : `${duracoes.length} períodos`;
    const meses = duracoes.length === 1 && duracoes[0] === 1 ? 'mês' : 'meses';

    return `${quantidade} (${duracoes.join('+')} ${meses})`;
}
```

Em `Modules/Financeiro/resources/js/Pages/PlanosPropina/Index.vue`:
1. Depois de `import { formatarDinheiro } from '../../Support/dinheiro';` acrescentar:
```js
import { resumoPeriodos } from '../../Support/periodos';
```
2. Substituir `                            <td class="text-gray-800">{{ plano.nome }}</td>` por:
```html
                            <td class="text-gray-800">
                                {{ plano.nome }}
                                <span v-if="plano.tem_propinas" class="badge badge-light-primary ms-1" title="Já tem propinas geradas: o valor e o calendário ficam bloqueados.">Com propinas</span>
                            </td>
```
3. Substituir `                            <td class="text-end">{{ formatarDinheiro(plano.valor, moeda) }}</td>` por:
```html
                            <td class="text-end">
                                {{ formatarDinheiro(plano.valor, moeda) }}
                                <div class="text-muted fs-8">por período · total {{ formatarDinheiro(plano.valor_total, moeda) }}</div>
                            </td>
```
4. Substituir `                            <td>{{ periodoTexto(plano) }} <span class="text-muted fs-7">({{ plano.periodos_total }} períodos)</span></td>` por:
```html
                            <td>
                                {{ periodoTexto(plano) }}
                                <div class="text-muted fs-7">{{ resumoPeriodos(plano.duracoes_periodos) }}</div>
                                <span v-if="plano.ultimo_periodo_mais_curto" class="badge badge-light-warning mt-1" title="O último período tem menos meses, mas é cobrado pelo valor integral de um período.">Último período mais curto</span>
                            </td>
```

Em `Modules/Financeiro/resources/js/Components/PlanosPropina/PlanoPropinaFormModal.vue`:
1. Substituir `import { unidadesMenoresParaDecimal } from '../../Support/dinheiro';` por:
```js
import { decimalParaUnidadesMenores, formatarDinheiro, unidadesMenoresParaDecimal } from '../../Support/dinheiro';
import { duracoesDosPeriodos, resumoPeriodos } from '../../Support/periodos';
```
2. Depois de `const alvosEliminados = computed(() => form.alvos.filter((a) => a.eliminado));` acrescentar:
```js
// Plano com propinas: valor e calendário bloqueados (o servidor recusa na mesma).
const calendarioBloqueado = computed(() => props.plano?.tem_propinas === true);

// Q3: o valor é por período de cobrança; o último período pode ser mais curto e cobra o valor inteiro.
// Os valores do enum Periodicidade são os meses de cada período (OUTRA = 0 usa o intervalo indicado).
const intervaloEfectivo = computed(() => {
    if (form.periodicidade === OUTRA) {
        const meses = Number(form.intervalo_meses);
        return Number.isInteger(meses) && meses >= 1 && meses <= 12 ? meses : null;
    }

    return Number(form.periodicidade);
});
const duracoes = computed(() => (intervaloEfectivo.value === null ? [] : duracoesDosPeriodos(form.mes_inicio, form.mes_fim, intervaloEfectivo.value)));
const ultimoPeriodoMaisCurto = computed(() => duracoes.value.length > 1 && duracoes.value[duracoes.value.length - 1] < intervaloEfectivo.value);
const mesesDoUltimo = computed(() => duracoes.value[duracoes.value.length - 1]);
const resumoCobranca = computed(() => {
    if (duracoes.value.length === 0) return '';

    const intervalo = intervaloEfectivo.value;
    const partes = [`${intervalo} ${intervalo === 1 ? 'mês' : 'meses'} por período`, resumoPeriodos(duracoes.value)];
    const valor = decimalParaUnidadesMenores(form.valor, props.moeda);

    if (valor !== null) {
        const quantidade = duracoes.value.length;
        partes.push(`total ${quantidade} × ${formatarDinheiro(valor, props.moeda)} = ${formatarDinheiro(valor * BigInt(quantidade), props.moeda)}`);
    }

    return partes.join(' · ');
});
const rotuloPeriodicidade = computed(() => props.periodicidades.find((p) => p.value === form.periodicidade)?.label ?? '');
const rotuloMes = (mes) => MESES.find((m) => m.value === mes)?.label ?? '';
```
3. Substituir o bloco da periodicidade, do intervalo e do valor:
```html
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
```
por:
```html
                        <div class="col-md-4 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Periodicidade</label>
                            <input v-if="calendarioBloqueado" type="text" class="form-control form-control-solid" :value="rotuloPeriodicidade" disabled />
                            <SelectSolid v-else v-model="form.periodicidade" :options="periodicidades" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.periodicidade">{{ errors.periodicidade }}</div>
                        </div>
                        <div v-if="form.periodicidade === OUTRA" class="col-md-4 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Intervalo (meses)</label>
                            <input v-model.number="form.intervalo_meses" type="number" min="1" max="12" class="form-control form-control-solid" :disabled="calendarioBloqueado" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.intervalo_meses">{{ errors.intervalo_meses }}</div>
                        </div>
                        <div class="col-md-4 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Valor por período de cobrança ({{ moeda.simbolo }})</label>
                            <input v-model="form.valor" type="text" inputmode="decimal" class="form-control form-control-solid" :placeholder="placeholderValor" :disabled="calendarioBloqueado" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.valor">{{ errors.valor }}</div>
                        </div>
```
4. Substituir o bloco dos meses e da ajuda:
```html
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
```
por:
```html
                        <div class="col-md-4 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Mês de início</label>
                            <input v-if="calendarioBloqueado" type="text" class="form-control form-control-solid" :value="rotuloMes(form.mes_inicio)" disabled />
                            <SelectSolid v-else v-model="form.mes_inicio" :options="MESES" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.mes_inicio">{{ errors.mes_inicio }}</div>
                        </div>
                        <div class="col-md-4 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Mês de fim</label>
                            <input v-if="calendarioBloqueado" type="text" class="form-control form-control-solid" :value="rotuloMes(form.mes_fim)" disabled />
                            <SelectSolid v-else v-model="form.mes_fim" :options="MESES" />
                            <div class="text-danger fs-7 mt-1" v-if="errors.mes_fim">{{ errors.mes_fim }}</div>
                        </div>
                        <div class="col-md-4 d-flex align-items-end mb-7">
                            <div class="form-text">
                                O período pode atravessar o ano civil (ex.: Setembro → Junho). O valor é cobrado por cada período
                                de cobrança. Para mudar o preço a meio do ano, crie outro plano com os mesmos alvos e o período seguinte.
                            </div>
                        </div>
                    </div>

                    <div v-if="calendarioBloqueado" class="alert alert-info fs-7 py-3 mb-4">
                        Este plano já tem propinas geradas: o valor, a periodicidade e os meses de início e de fim ficam bloqueados.
                        Pode mudar o nome, a descrição e os alvos; essas alterações só afectam gerações futuras.
                    </div>
                    <div v-if="resumoCobranca" class="bg-body-secondary rounded fs-7 px-4 py-3 mb-4">
                        <span class="fw-semibold">Cobrança:</span> {{ resumoCobranca }}
                    </div>
                    <div v-if="ultimoPeriodoMaisCurto" class="alert alert-warning fs-7 py-3 mb-7">
                        O último período tem só {{ mesesDoUltimo }} {{ mesesDoUltimo === 1 ? 'mês' : 'meses' }}, mas é cobrado pelo valor integral de um período.
                    </div>
```
5. Substituir `                        <div v-if="form.alvos.length === 0" class="bg-body-secondary rounded fs-7 text-muted px-4 py-3 mb-2">` por (aviso não retroactivo dos alvos, Q10):
```html
                        <div v-if="calendarioBloqueado" class="text-muted fs-7 mb-2">
                            Alterar os alvos só afecta gerações futuras: as propinas já geradas mantêm o seu plano e o seu valor.
                        </div>
                        <div v-if="form.alvos.length === 0" class="bg-body-secondary rounded fs-7 text-muted px-4 py-3 mb-2">
```

- [ ] **Step 6: Build**

Run: `npm run build`
Expected: sucesso, sem erros nem avisos novos. (Verificação visual no browser — Index com "4 períodos (3+3+3+1 meses)", aviso amarelo no trimestral Set → Jun, modal com "3 meses por período · 4 períodos (3+3+3+1 meses) · total 4 × 25.000,00 Kz = 100.000,00 Kz" e campos bloqueados num plano com propinas — é do controlador, fora da dispatch; credenciais em `reference_dev_login_credentials`. Não criar propinas na base de desenvolvimento para isso: o bloqueio já está coberto pelos testes.)

- [ ] **Step 7: Stage**

```bash
git add Modules/Financeiro/app/Support/CalendarioDePlano.php Modules/Financeiro/app/Models/PlanoPropina.php Modules/Financeiro/app/Services/PlanoPropinaConsultaService.php Modules/Financeiro/resources/js/Support/dinheiro.js Modules/Financeiro/resources/js/Support/periodos.js Modules/Financeiro/resources/js/Pages/PlanosPropina/Index.vue Modules/Financeiro/resources/js/Components/PlanosPropina/PlanoPropinaFormModal.vue Modules/Financeiro/tests/Unit/CalendarioDePlanoTest.php Modules/Financeiro/tests/Feature/PlanoPropinaPeriodosTest.php
```
(Nada gerado em `public/build` no stage.)

---

### Task 10: Testes de arquitectura — escritores de propina e fronteira Matricula → Financeiro

**Files:**
- Test: `tests/Feature/Arquitectura/EscritoresDePropinaTest.php`
- Test: `tests/Feature/Arquitectura/FronteiraMatriculaFinanceiroTest.php`

**Interfaces:**
- Consumes: código de `Modules/Financeiro/app` e `Modules/Matricula/{app,routes}`.
- Produces: guarda permanente: só `Modules/Financeiro/app/Services/RecalcularPropina.php` escreve `valor_pago`/`capital_liquidado_em`; o módulo Matricula nunca importa `Modules\Financeiro\`.

- [ ] **Step 1: Escrever os testes**

`tests/Feature/Arquitectura/EscritoresDePropinaTest.php`:
```php
<?php

namespace Tests\Feature\Arquitectura;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * `valor_pago` e `capital_liquidado_em` de uma propina são caches: só RecalcularPropina os escreve
 * (spec Propinas §6). Varrimento por expressões estreitas (padrão do TenancyArquitecturaTest). Quando
 * houver outro escritor legítimo de um campo com o mesmo nome (ex.: o valor pago da multa, F5),
 * acrescenta-se aqui explicitamente.
 */
class EscritoresDePropinaTest extends TestCase
{
    private const ESCRITORES_PERMITIDOS = ['Modules/Financeiro/app/Services/RecalcularPropina.php'];

    /**
     * @return list<string> excertos que escrevem um dos campos
     */
    private function escritas(string $codigo): array
    {
        $padroes = [
            '/->\s*(?:valor_pago|capital_liquidado_em)\s*=(?!=)/',
            '/\b(?:fill|forceFill|update|create|forceCreate|make|firstOrCreate|updateOrCreate|firstOrNew)\s*\(\s*\[[^\]]*[\'"](?:valor_pago|capital_liquidado_em)[\'"]\s*=>/s',
            '/\bsetAttribute\s*\(\s*[\'"](?:valor_pago|capital_liquidado_em)[\'"]/',
            '/\b(?:increment|decrement)\s*\(\s*[\'"]valor_pago[\'"]/',
        ];
        $escritas = [];

        foreach ($padroes as $padrao) {
            if (preg_match_all($padrao, $codigo, $achados) > 0) {
                array_push($escritas, ...$achados[0]);
            }
        }

        return $escritas;
    }

    private function raiz(): string
    {
        return dirname(__DIR__, 3);
    }

    public function test_so_o_recalcular_propina_escreve_valor_pago_e_capital_liquidado_em(): void
    {
        $violacoes = [];
        $iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->raiz() . '/Modules/Financeiro/app', RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($iterador as $ficheiro) {
            if (! $ficheiro->isFile() || $ficheiro->getExtension() !== 'php') {
                continue;
            }

            $relativo = str_replace($this->raiz() . '/', '', $ficheiro->getPathname());

            if (in_array($relativo, self::ESCRITORES_PERMITIDOS, true)) {
                continue;
            }

            foreach ($this->escritas(file_get_contents($ficheiro->getPathname())) as $excerto) {
                $violacoes[] = "{$relativo}: {$excerto}";
            }
        }

        $this->assertSame([], $violacoes, "Só RecalcularPropina escreve valor_pago e capital_liquidado_em:\n" . implode("\n", $violacoes));
    }

    public function test_o_escritor_permitido_existe_e_e_apanhado_pelo_varrimento(): void
    {
        $caminho = $this->raiz() . '/' . self::ESCRITORES_PERMITIDOS[0];

        $this->assertFileExists($caminho);
        $this->assertNotSame([], $this->escritas(file_get_contents($caminho)));
    }

    #[DataProvider('exemplos')]
    public function test_o_varrimento_apanha_o_que_deve(string $codigo, bool $escrita): void
    {
        $this->assertSame($escrita, $this->escritas($codigo) !== [], $codigo);
    }

    public static function exemplos(): array
    {
        return [
            'atribuição directa' => ['$p->valor_pago = Dinheiro::deUnidadesMenores(1);', true],
            'forceFill' => ["\$p->forceFill(['estado' => 1, 'valor_pago' => 0])->save();", true],
            'update' => ["\$p->update(['capital_liquidado_em' => null]);", true],
            'setAttribute' => ["\$p->setAttribute('valor_pago', 1);", true],
            'increment' => ["\$q->increment('valor_pago', 10);", true],
            'leitura' => ['$x = $p->valor_pago;', false],
            'comparações' => ['if ($p->valor_pago == $v || $p->valor_pago === $w || $p->valor_pago >= $z) {}', false],
            'leitura encadeada' => ['$p->valor_pago?->unidadesMenores();', false],
            'casts do model' => ["protected \$casts = ['valor_pago' => DinheiroCast::class];", false],
            'atributos por omissão' => ["protected \$attributes = ['estado' => 1, 'valor_pago' => 0];", false],
        ];
    }
}
```

`tests/Feature/Arquitectura/FronteiraMatriculaFinanceiroTest.php`:
```php
<?php

namespace Tests\Feature\Arquitectura;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * O Financeiro depende da Matrícula (propinas pertencem a matrículas); o inverso seria circular. A
 * Matrícula só emitirá eventos e consultará contratos próprios (F3), implementados pelo Financeiro.
 */
class FronteiraMatriculaFinanceiroTest extends TestCase
{
    public function test_o_modulo_matricula_nao_importa_o_modulo_financeiro(): void
    {
        $raiz = dirname(__DIR__, 3) . '/Modules/Matricula';
        $violacoes = [];

        foreach (['app', 'routes'] as $pasta) {
            $iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$raiz}/{$pasta}", RecursiveDirectoryIterator::SKIP_DOTS));

            foreach ($iterador as $ficheiro) {
                if ($ficheiro->isFile() && $ficheiro->getExtension() === 'php' && str_contains(file_get_contents($ficheiro->getPathname()), 'Modules\\Financeiro\\')) {
                    $violacoes[] = $ficheiro->getPathname();
                }
            }
        }

        $this->assertSame([], $violacoes, "A Matrícula não pode importar o Financeiro:\n" . implode("\n", $violacoes));
    }
}
```

- [ ] **Step 2: Correr**

Run: `php artisan test tests/Feature/Arquitectura`
Expected: PASS. (São guardas: passam já porque T5 só escreve os campos no `RecalcularPropina`. Prova de que funcionam: o data provider e o controlo positivo. Para confirmar, acrescentar temporariamente `$p->valor_pago = 0;` a um ficheiro de `Modules/Financeiro/app`, ver o teste falhar, e reverter.)

- [ ] **Step 3: Stage**

```bash
git add tests/Feature/Arquitectura/EscritoresDePropinaTest.php tests/Feature/Arquitectura/FronteiraMatriculaFinanceiroTest.php
```

---

### Task 11: Infra de testes PostgreSQL e testes pgsql de F1

**Files:**
- Create: `tests/PgsqlTestCase.php`
- Modify: `phpunit.xml`
- Test: `tests/Unit/PgsqlTestCaseTest.php`
- Test: `Modules/Financeiro/tests/Feature/Pgsql/PropinaPgsqlTest.php`

**Interfaces:**
- Consumes: `Tests\TestCase`, `RefreshDatabase::beforeRefreshingDatabase()` (gancho do Laravel), helpers T3.
- Produces: `Tests\PgsqlTestCase` (abstracta): `BASE_DE_TESTE = 'mositec_escola_test'`; `baseDeTestePermitida(?string $ligacao, ?string $base): bool`; ignora (skip) antes de arrancar a aplicação se `DB_CONNECTION`/`DB_DATABASE` não forem `pgsql`/`mositec_escola_test`, volta a verificar a configuração já carregada e a ligação antes do `migrate:fresh`, e falha se a classe concreta não declarar `#[Group('pgsql')]`. Grupo `pgsql` excluído por omissão no `phpunit.xml`.
- **Comando (documentado no PHPDoc):** `DB_CONNECTION=pgsql DB_DATABASE=mositec_escola_test vendor/bin/phpunit --group=pgsql` (D13).

- [ ] **Step 1: Escrever os testes que falham**

`tests/Unit/PgsqlTestCaseTest.php`:
```php
<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\PgsqlTestCase;

class PgsqlTestCaseTest extends TestCase
{
    public function test_so_a_base_de_teste_em_postgresql_e_permitida(): void
    {
        $this->assertTrue(PgsqlTestCase::baseDeTestePermitida('pgsql', 'mositec_escola_test'));

        $this->assertFalse(PgsqlTestCase::baseDeTestePermitida('pgsql', 'mositec_escola')); // a base de desenvolvimento, nunca
        $this->assertFalse(PgsqlTestCase::baseDeTestePermitida('pgsql', ':memory:'));
        $this->assertFalse(PgsqlTestCase::baseDeTestePermitida('pgsql', null));
        $this->assertFalse(PgsqlTestCase::baseDeTestePermitida('sqlite', 'mositec_escola_test'));
        $this->assertFalse(PgsqlTestCase::baseDeTestePermitida(null, null));
    }

    public function test_o_phpunit_exclui_o_grupo_pgsql_por_omissao(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 2) . '/phpunit.xml');
        $excluidos = array_map('strval', $xml->xpath('/phpunit/groups/exclude/group') ?: []);

        $this->assertContains('pgsql', $excluidos);
    }
}
```

`Modules/Financeiro/tests/Feature/Pgsql/PropinaPgsqlTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature\Pgsql;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use PHPUnit\Framework\Attributes\Group;
use Tests\PgsqlTestCase;

/**
 * O que só o PostgreSQL prova em F1: CHECK, índices únicos parciais, bigint/date de ida e volta e o
 * filtro SQL do estado resolvido no motor de produção. Corre só com a base mositec_escola_test.
 */
#[Group('pgsql')]
class PropinaPgsqlTest extends PgsqlTestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;

    /**
     * Corre a operação num savepoint (DB::transaction aninhada): no PostgreSQL um erro aborta a transacção
     * inteira, e o savepoint deixa a do RefreshDatabase utilizável a seguir. O PostgreSQL verifica os
     * CHECK por ordem alfabética do nome e reporta o primeiro que falha: por isso aceitam-se vários nomes.
     *
     * @param  list<string>  $restricoes
     */
    private function assertRecusadoPelaBase(string $sqlstate, array $restricoes, Closure $operacao): void
    {
        try {
            DB::transaction($operacao);
        } catch (QueryException $e) {
            $this->assertSame($sqlstate, $e->errorInfo[0] ?? null, $e->getMessage());
            $this->assertMatchesRegularExpression('/' . implode('|', array_map('preg_quote', $restricoes)) . '/', $e->getMessage());

            return;
        }

        $this->fail('A base de dados devia ter recusado a operação (' . implode(', ', $restricoes) . ').');
    }

    private function umaPropina(): Propina
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

        return $this->propina($matricula, $plano);
    }

    public function test_check_valor_pago_entre_zero_e_o_valor(): void
    {
        $propina = $this->umaPropina();
        $valor = $propina->valor->unidadesMenores();
        $linha = fn () => DB::table('propinas')->where('id', $propina->id);

        $this->assertRecusadoPelaBase('23514', ['propinas_valor_pago_intervalo_check', 'propinas_estado_coerente_check', 'propinas_capital_liquidado_check'],
            fn () => $linha()->update(['valor_pago' => $valor + 1, 'estado' => 3, 'capital_liquidado_em' => '2026-09-05']));
        $this->assertRecusadoPelaBase('23514', ['propinas_valor_pago_intervalo_check', 'propinas_estado_coerente_check'],
            fn () => $linha()->update(['valor_pago' => -1]));

        // O limite (valor_pago = valor, Paga, com data de liquidação) é aceite.
        $linha()->update(['valor_pago' => $valor, 'estado' => 3, 'capital_liquidado_em' => '2026-09-05']);
        $this->assertSame($valor, $propina->fresh()->valor_pago->unidadesMenores());
    }

    public function test_check_valor_positivo_estado_e_datas_de_cancelamento(): void
    {
        $propina = $this->umaPropina();
        $linha = fn () => DB::table('propinas')->where('id', $propina->id);

        $this->assertRecusadoPelaBase('23514', ['propinas_valor_positivo_check', 'propinas_capital_liquidado_check'],
            fn () => $linha()->update(['valor' => 0]));
        $this->assertRecusadoPelaBase('23514', ['propinas_estado_coerente_check'],
            fn () => $linha()->update(['estado' => 3]));
        $this->assertRecusadoPelaBase('23514', ['propinas_cancelada_check'],
            fn () => $linha()->update(['estado' => 4]));
        $this->assertRecusadoPelaBase('23514', ['propinas_estado_check', 'propinas_estado_coerente_check'],
            fn () => $linha()->update(['estado' => 7]));
        $this->assertRecusadoPelaBase('23514', ['propinas_vencimento_check'],
            fn () => $linha()->update(['data_vencimento' => '2026-08-31', 'data_limite' => '2026-09-05']));
    }

    public function test_indices_parciais_impedem_duas_activas_e_permitem_regerar_depois_de_cancelar(): void
    {
        ['ano' => $ano, 'plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $outroPlano = $this->plano($ano, 'Outro Plano');
        $primeira = $this->propina($matricula, $plano);

        $this->assertRecusadoPelaBase('23505', ['propinas_periodo_activo_unique'], fn () => $this->propina($matricula, $outroPlano));
        $this->assertRecusadoPelaBase('23505', ['propinas_plano_ordem_activo_unique', 'propinas_periodo_activo_unique'], fn () => $this->propina($matricula, $plano));

        $this->comEstado($primeira, EstadoCobranca::CANCELADA);
        $segunda = $this->propina($matricula, $plano);

        $this->assertSame(2, Propina::query()->where('matricula_id', $matricula->id)->count());
        $this->assertSame([$segunda->id], Propina::query()->activas()->pluck('id')->all());
        $this->assertRecusadoPelaBase('23505', ['propinas_periodo_activo_unique'], fn () => $this->propina($matricula, $outroPlano));
    }

    public function test_dinheiro_cambio_e_datas_fazem_ida_e_volta_sem_perdas(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $maximo = 999_999_999_999_999; // 12 dígitos inteiros numa moeda de 3 casas decimais
        $propina = $this->propina($matricula, $plano, 1, ['valor' => Dinheiro::deUnidadesMenores($maximo), 'cambio_usd' => 999_999_999_999_999]);

        $linha = DB::table('propinas')->where('id', $propina->id)->first();
        $this->assertSame($maximo, (int) $linha->valor);
        $this->assertSame($maximo, (int) $linha->valor_original);
        $this->assertSame('2026-09-01', (string) $linha->periodo_inicio);
        $this->assertSame('2026-09-15', (string) $linha->data_limite);

        $lida = Propina::query()->findOrFail($propina->id);
        $this->assertSame($maximo, $lida->valor->unidadesMenores());
        $this->assertSame($maximo, $lida->valor_original->unidadesMenores());
        $this->assertSame(999_999_999_999_999, $lida->cambio_usd);
        $this->assertSame('2026-09-30', $lida->periodo_fim->toDateString());
    }

    public function test_o_filtro_sql_do_estado_resolvido_coincide_com_o_php_no_postgresql(): void
    {
        $this->cenarioDeEstados();

        foreach (['2026-08-31', '2026-09-01', '2026-09-15', '2026-09-16', '2026-10-15', '2026-10-16', '2027-07-01'] as $dia) {
            $hoje = CarbonImmutable::parse($dia);
            $porEstado = Propina::query()->orderBy('id')->get()->groupBy(fn (Propina $p) => $p->estadoResolvido($hoje)->value);

            foreach (EstadoCobranca::cases() as $estado) {
                $this->assertSame(
                    ($porEstado[$estado->value] ?? collect())->pluck('id')->all(),
                    Propina::query()->comEstadoResolvido($estado, $hoje)->orderBy('id')->pluck('id')->all(),
                    "{$estado->label()} em {$dia}",
                );
            }
        }
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test tests/Unit/PgsqlTestCaseTest.php`
Expected: FAIL (`Tests\PgsqlTestCase` inexistente; grupo não excluído).

- [ ] **Step 3: Implementar**

`tests/PgsqlTestCase.php`:
```php
<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use Throwable;

/**
 * Base dos testes que só o PostgreSQL prova (CHECK, índices parciais, bloqueios, concorrência).
 *
 * Correm APENAS contra a base de teste `mositec_escola_test` (nunca a de desenvolvimento
 * `mositec_escola`), estão fora da suite por omissão (grupo `pgsql` excluído no phpunit.xml) e são
 * ignorados (skipped) quando essa base não está configurada ou não responde. A base é recriada com
 * migrate:fresh no início de cada execução; criá-la é tarefa do dono (`createdb mositec_escola_test`).
 *
 * Como correr (NÃO usar `php artisan test`: o comando limpa as variáveis do .env antes de lançar o
 * PHPUnit e a ligação voltaria a SQLite):
 *
 *     DB_CONNECTION=pgsql DB_DATABASE=mositec_escola_test vendor/bin/phpunit --group=pgsql
 *
 * Host, porta, utilizador e palavra-passe vêm do .env (DB_HOST, DB_PORT, DB_USERNAME, DB_PASSWORD).
 * Cada classe concreta declara #[Group('pgsql')] (os atributos PHP não se herdam).
 */
abstract class PgsqlTestCase extends TestCase
{
    use RefreshDatabase;

    public const BASE_DE_TESTE = 'mositec_escola_test';

    public static function baseDeTestePermitida(?string $ligacao, ?string $base): bool
    {
        return $ligacao === 'pgsql' && $base === self::BASE_DE_TESTE;
    }

    protected function setUp(): void
    {
        $this->exigirGrupoPgsql();

        // Antes de arrancar a aplicação: sem estas variáveis nada toca em nenhuma base.
        if (! self::baseDeTestePermitida(getenv('DB_CONNECTION') ?: null, getenv('DB_DATABASE') ?: null)) {
            $this->markTestSkipped('Teste PostgreSQL: corra com DB_CONNECTION=pgsql DB_DATABASE=' . self::BASE_DE_TESTE . ' vendor/bin/phpunit --group=pgsql.');
        }

        parent::setUp();
    }

    /**
     * Gancho do RefreshDatabase, chamado antes do migrate:fresh: segunda verificação, já com a
     * configuração carregada (protege contra configuração em cache), e prova de que a base responde.
     */
    protected function beforeRefreshingDatabase()
    {
        $ligacao = config('database.default');
        $base = config("database.connections.{$ligacao}.database");

        if (! self::baseDeTestePermitida($ligacao, $base)) {
            $this->markTestSkipped("Teste PostgreSQL: a ligação configurada ({$ligacao}/{$base}) não é a base de teste " . self::BASE_DE_TESTE . '.');
        }

        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $this->markTestSkipped('Teste PostgreSQL: a base ' . self::BASE_DE_TESTE . " não responde ({$e->getMessage()}).");
        }
    }

    private function exigirGrupoPgsql(): void
    {
        foreach ((new ReflectionClass(static::class))->getAttributes(Group::class) as $atributo) {
            if (($atributo->getArguments()[0] ?? null) === 'pgsql') {
                return;
            }
        }

        $this->fail(static::class . " estende PgsqlTestCase e tem de declarar #[Group('pgsql')].");
    }
}
```

Em `phpunit.xml`, depois de `</testsuites>`, acrescentar:
```xml
    <!-- Testes PostgreSQL: fora da suite por omissão. Como correr: ver o PHPDoc de tests/PgsqlTestCase.php
         (um comentário XML não pode conter dois hífenes seguidos, por isso o comando não está aqui). -->
    <groups>
        <exclude>
            <group>pgsql</group>
        </exclude>
    </groups>
```
(O `--group=pgsql` na linha de comando retira `pgsql` das exclusões do XML — `Merger` do PHPUnit 11 faz `array_diff($excludeGroups, $groups)`.)

- [ ] **Step 4: Correr**

Run: `php artisan test tests/Unit/PgsqlTestCaseTest.php`
Expected: PASS.

Run: `vendor/bin/phpunit --group=pgsql`
Expected: todos os testes de `PropinaPgsqlTest` **skipped** com a mensagem "Teste PostgreSQL: corra com DB_CONNECTION=pgsql …" (sem variáveis, nada liga ao PostgreSQL). **Não** correr com `DB_DATABASE=mositec_escola_test` se a base não existir; **nunca** com `DB_DATABASE=mositec_escola`.

Run: `php artisan test Modules/Financeiro/tests/Feature/Pgsql`
Expected: 0 testes executados (grupo excluído por omissão).

- [ ] **Step 5: Stage**

```bash
git add tests/PgsqlTestCase.php tests/Unit/PgsqlTestCaseTest.php phpunit.xml Modules/Financeiro/tests/Feature/Pgsql/PropinaPgsqlTest.php
```

---

### Task 12: Verificação final

- [ ] **Step 1: Suite completa**

Run: `php artisan test` (timeout alargado, ~180 s)
Expected: 0 falhas (os testes do grupo `pgsql` não aparecem). Falhas fora do módulo são regressões deste plano (em especial `MatrizIsolamentoTest`, `TenancyEsquemaTest`, `TenancyArquitecturaTest`, contagens de módulos/acções em `Modules/Permissao/tests`, provisionadores): corrigir seguindo a convenção, sem enfraquecer nenhum teste, e documentar quais e porquê.

- [ ] **Step 2: Limpeza**

Run:
```bash
grep -rnE "insertOrIgnore|->insert\(|upsert\(|DB::raw|DB::statement|DB::table|withoutEvents|Quietly\(" Modules/Financeiro/app
grep -rnE "PrecosDasPropinas|GerarPropinas|CancelarPropinaAction|AnularPropinaAction|AlterarPrecoPlanoAction|HistoricoDePagamentos" Modules/Financeiro/app Modules/Financeiro/routes
grep -n "propina" Modules/Financeiro/routes/web.php | grep -v "planos-propina\|plano-propina\|PlanoPropina"
grep -n "self::PROPINA =>" Modules/Permissao/app/Enums/Modulo.php
xmllint --noout phpunit.xml
```
Expected: os três primeiros não devolvem nada (sem SQL cru na aplicação, sem peças de F2+, sem rotas de propinas); o quarto mostra uma única linha com `['ver', 'listar', 'criar', 'cancelar', 'anular', 'exportar', 'ajustar']`; o `xmllint` não reporta erros (se não estiver instalado, `php -r "var_dump(simplexml_load_file('phpunit.xml') !== false);"` tem de dar `true`).

- [ ] **Step 3: Build**

Run: `npm run build`
Expected: sucesso.

- [ ] **Step 4: Estado do git**

Run: `git status --short` e `git diff --cached --stat`
Expected: só ficheiros deste plano (mais os que já estavam em stage antes de começar) em stage, nada gerado (`public/build`), **nenhum commit**.

- [ ] **Step 5: Informar o dono do que lhe cabe**

1. `php artisan migrate` (cria `propinas`) — ou `migrate:fresh --seed` no ambiente de desenvolvimento, se preferir.
2. `php artisan db:seed --force` (módulo 22 "Propina" no catálogo global).
3. `php artisan financeiro:sincronizar --todos` (concede `propina.{ver,listar,criar,cancelar,exportar}` a ADMIN_ESCOLA nos tenants existentes e invalida a cache de permissões).
4. Criar a base de testes PostgreSQL (`createdb mositec_escola_test`, mesmo utilizador do `.env`) e correr `DB_CONNECTION=pgsql DB_DATABASE=mositec_escola_test vendor/bin/phpunit --group=pgsql` — critério de validação de F1 (§8 do plano de fases).
5. Verificação visual: grelha de permissões com "Propinas" só com as 7 acções; ecrã de Planos com o resumo de períodos e o aviso do último período curto.
6. Decisões a confirmar: D1 (sem `PrecosDasPropinas`), D2 (unicidades parciais — §3 do spec já corrigido na Task 3), D5 (CHECK de coerência obriga F4 a gravar valor e estado num só UPDATE).

---

## Tabelas e relações (no fim de F1)

| Tabela | Estado | Relações |
|---|---|---|
| `propinas` | **nova** | N:1 `tenants` (restrict); N:1 `matriculas` (restrict); N:1 `planos_propina` (restrict); N:1 `ano_lectivos` (restrict, snapshot = ano do plano = ano da matrícula); N:1 `users` (`cancelado_por`, `anulado_por`, `criado_por`, `editado_por`, null on delete). Únicos parciais (estado 1–3): `(tenant_id, matricula_id, plano_propina_id, ordem)` e `(tenant_id, matricula_id, periodo_inicio)`. Futuro: 1:N `propina_ajustes` (F4), 1:1 `propina_multas` (F5), 1:N `pagamento_propinas` e `credito_utilizacoes` (Pagamentos-A, via `FonteDePagamentoDePropina`) |
| `planos_propina` | existe | 1:N `propinas` (nova relação `propinas()`); com propinas: não se elimina (`PropinasReferenciam`), valor e calendário bloqueados (`AtualizarPlanoPropinaAction`) |
| `matriculas` | existe | 1:N `propinas`; o módulo Matricula não conhece o Financeiro (`FronteiraMatriculaFinanceiroTest`) |
| `ano_lectivos` | existe | 1:N `propinas` (snapshot); já protegido pelos planos (`DependenciasDePlanosPropina`) |
| `configuracoes_monetarias` | existe | moeda bloqueada por qualquer propina (`PropinasReferenciam`) além dos preços (`FontesDePrecos`) |
| `cambios`, `cambios_plataforma` | existem | lidos por `SnapshotMonetario` (`cambio_usd`), nunca referenciados por FK |
| `regras_cobranca` | existe | `dia_vencimento`/`dias_tolerancia` copiados por F2 (snapshot), nunca referenciados por FK |
| `modulos` (global) | existe | + linha `22 = Propina`; `role_permissoes` de ADMIN_ESCOLA ganham 5 linhas por tenant |
