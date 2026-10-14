# Financeiro → Propinas — F2: Geração de propinas, lista e menu de operação — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> ⚠️ **F2 NÃO PODE SER USADA COM DADOS REAIS ANTES DE F3.** Até F3 existir, a Matrícula não protege as propinas: mudar a turma, o ano lectivo ou a data de uma matrícula com propinas, ou cancelá-la/transferi-la/concluí-la, **não** cancela nem bloqueia nada (não há `MatriculaEstadoAlterado` nem `DependenciasDaMatricula`). Em produção: **não** ligar `regras_cobranca.gerar_automaticamente` e **não** gerar propinas manualmente antes de F3 estar concluída e validada. O agendamento diário criado aqui é inerte enquanto a flag estiver desligada (valor por omissão).

**Goal:** Criar propinas de forma idempotente, sem cobrança dupla, para matrículas Activas, pelo plano aplicável **a cada competência**, com pré-visualização obrigatória na geração manual e com um comando diário para a geração automática; ver o resultado numa lista com filtros; e abrir o menu **Financeiro (operação)** na sidebar e no header com o item "Propinas".

**Architecture:** Um único serviço, `GerarPropinasService`, faz o cálculo (`calcular`, puro) e serve a pré-visualização (só leitura), a geração manual confirmada (via `GerarPropinasAction`) e a automática (`financeiro:gerar-propinas`, `ParaTodosOsTenants`). O plano decide-se **mês a mês** (`ResolvePlanoAplicavel::paraTurma($turma, $competencia)`, L8) e só se gera um período quando **todos** os seus meses resolvem para o mesmo plano; com um plano escolhido, os períodos de outro plano ficam "ignorados — aplica-se outro plano" (L9). A elegibilidade vive em `ElegibilidadeDePeriodos` (L19). Cada matrícula corre na **sua** transacção: `lockForUpdate` da matrícula → `sharedLock` + impressão digital dos planos do ano (L15) → leitura das propinas activas → `Propina::create` (L4, sem `insertOrIgnore`); os índices únicos parciais de F1 são a rede de segurança, e uma violação é apanhada **fora** da transacção e reportada em `erros`. O "hoje" de negócio vem sempre de `RelogioDoTenant::hoje()` (§8.2b). A lista (`PropinaConsultaService` + `Financeiro/Propinas/Index`) filtra o estado resolvido com o scope SQL de F1. A concorrência real é provada num grupo `pgsql` com dados confirmados e uma segunda ligação.

**Tech Stack:** Laravel 12.69 + nwidart/laravel-modules, Inertia + Vue 3, PHPUnit 11.5 com SQLite em memória (produção PostgreSQL), Metronic/Bootstrap, axios para o endpoint JSON de pré-visualização.

**Fontes de verdade:** `docs/superpowers/plans/2026-10-12-financeiro-propinas-fases.md` (F2, §1–§4 — L4, L8, L9, L15, L19 —, §8 com Q1–Q15 aprovadas, §8.1, §8.2 critério 3, §8.2b), `docs/superpowers/plans/2026-10-13-fuso-horario-escola.md`, `docs/superpowers/plans/2026-10-13-financeiro-propinas-f1.md` e o **código real de F1** (`Propina`, `RecalcularPropina`, `BloqueioDePropinas`, `SnapshotMonetario`, `ResolvePlanoAplicavel`, `CalendarioDePlano`, `Precedencia`, `PropinasReferenciam`, `ContribuicaoDePagamento`, `EstadoCobranca`, `OrigemGeracaoPropina`, `RegraCobranca`, `ComPropinasFinanceiro`, `PgsqlTestCase`, `EscritoresDePropinaTest`, `FronteiraMatriculaFinanceiroTest`, `RelogioDoTenantArquitecturaTest`), `docs/superpowers/specs/2026-10-08-financeiro-propinas-design.md` (§3, §5, §7, §9, §10, §11, §12, §14), `docs/superpowers/specs/2026-10-08-modulo-financeiro-configuracao-design.md`.

## Restrições globais

- Texto de UI, mensagens e comentários em PT-PT com acentos; identificadores, rotas, tabelas e variáveis sem acento.
- Camada fina: Controller → Service (leitura) / Action (escrita), com DTO e FormRequest. `use` no topo, nunca FQN inline (também em testes).
- Dinheiro: unidades menores (`Dinheiro`, `DinheiroCast`), nunca float; moeda de `MoedaDoTenant` (via `SnapshotMonetario`).
- Tenancy: nenhum model novo nesta fase; tudo o que se lê e escreve passa pelos models com `PertenceAoTenant`; `tenant_id` nunca vem do cliente; ids de outro tenant dão 422 (FormRequest) ou não aparecem (scope).
- **Proibido** em `Modules/*/app` (`TenancyArquitecturaTest`): `insert`, `insertOrIgnore`, `insertGetId`, `upsert`, `DB::table|raw|statement|…`, `*Quietly`, `withoutEvents`, `withoutGlobalScope(s)`. Testes e migrations ficam fora do varrimento.
- **"Hoje" de negócio só por `RelogioDoTenant::hoje()`**: `now()`, `today()`, `Carbon(Immutable)::now/today`, `new DateTime()` e regras `:today` são recusados pelo `RelogioDoTenantArquitecturaTest` em `Modules/Financeiro/app` (lista de excepções vazia — não a alargar).
- `valor_pago`, `capital_liquidado_em` e `estado` 2/3 continuam só no `RecalcularPropina` (`EscritoresDePropinaTest`). A geração **não** escreve `estado` (nasce 1 pelo `$attributes` do model).
- Dentro de `DB::transaction` não se apanham excepções para continuar (PostgreSQL aborta a transacção). As conversões de erro são **à volta** da transacção de cada matrícula.
- Nada desta fase toca no módulo `Matricula` (`FronteiraMatriculaFinanceiroTest` continua verde; F3 é que acrescenta evento e contrato).
- `migrate`, `db:seed --force`, `financeiro:sincronizar --todos`, a base `mositec_escola_test` e o `schedule:work`/cron são do dono. **Nunca correr nada contra a base de desenvolvimento `mositec_escola`.**
- **Git: só `git add`. Nunca `git commit`.**

## Decisões e pressupostos desta fase (ler antes de implementar)

| # | Decisão | Porquê |
|---|---|---|
| D1 | **Resolução por competência (L8) com período inteiro:** cada mês candidato (união das competências dos planos activos do ano) resolve para um plano, para nenhum (`sem_plano`) ou para um conflito. Um período de um plano P só se gera se **todos** os seus meses resolvem para P; se só parte resolve para P, o período **não** se gera e fica em `conflitos` com o nome do período e do plano. | O plano de fases dizia "o 1.º mês do período resolve para P". Isso cobraria, num trimestre Set–Nov de um plano geral, o mês de Outubro que pertence a um plano mais específico (cobrança dupla de facto, que o índice não apanha se o outro plano for mensal) ou exigiria cortar o período (mudaria o calendário do plano). Com planos mensais (o caso comum) as duas regras coincidem. **P4 aprovado pelo dono:** período com meses de outro plano = conflito, não gerado, com o motivo no resumo. |
| D2 | **Ordem de bloqueio por matrícula:** `Matricula::lockForUpdate()` → `PlanoPropina` do ano com `sharedLock()` + impressão digital (planos + alvos) → propinas activas da matrícula → `Propina::create`. A memória do resolvedor (`ResolucaoDePlanosMemorizada`, R6) é esquecida sempre que a impressão muda. | L15: a divisão de um plano (F4) faz `lockForUpdate` no plano e espera pela geração; uma divisão já confirmada muda a impressão e obriga a resolver de novo. `FOR SHARE` é compatível entre gerações concorrentes (não se bloqueiam umas às outras nos planos). Inserir uma propina de uma matrícula exige `KEY SHARE` na matrícula (FK), que espera pelo `FOR UPDATE`: duas gerações da mesma matrícula fazem fila (provado no grupo pgsql, Task 13). |
| D3 | **Sem `insertOrIgnore` (L4):** verificação de sobreposição + `Propina::create` sob o bloqueio da matrícula; os índices parciais de F1 são a rede. Qualquer `Throwable` de uma matrícula é apanhado **fora** da sua transacção: violação de unicidade → "Outra geração gravou propinas desta matrícula ao mesmo tempo…"; outro erro → `report($e)` + "Erro inesperado…". Ambos ficam em `erros`, com `Log::warning` (tenant + matrícula); as outras matrículas seguem. | Spec §7.4 ("transacção por matrícula"), L4, regra global sobre `try/catch` em transacções. |
| D4 | **Pré-visualização é leitura pura** e a confirmação **recalcula tudo** (sem impressão digital da pré-visualização). As regras de cobrança lêem-se com `RegraCobranca::query()->first()` (não `doTenant()`, que faz `firstOrCreate`); sem linha valem os `DEFAULTS`. Mudar um parâmetro no modal apaga a pré-visualização. | A geração é aditiva e idempotente: confirmar sobre dados que mudaram nunca duplica nem altera o que existe; o resultado mostrado no fim é o real. Uma impressão digital (como em F4) só faria sentido se a confirmação pudesse alterar registos existentes. |
| D5 | **Geração automática síncrona por tenant (sem Job):** `financeiro:gerar-propinas` (`ParaTodosOsTenants`) chama `GerarPropinasService::gerarAutomaticamente()` dentro do contexto de cada tenant; só actua se `gerar_automaticamente = true`; processa **todas as matrículas Activas**, ano lectivo a ano lectivo; regista uma linha `Log::info('financeiro.gerar-propinas', …)` por tenant e ano; se houver erros numa matrícula, o tenant fica "falhado" (código de saída ≠ 0) **depois** de as outras ficarem gravadas. Agendado `dailyAt('02:00')` (UTC = 03:00 em Luanda) com `withoutOverlapping()`. | O plano de fases propunha um `GerarPropinasDoTenantJob`. O `ParaTodosOsTenants` já isola falhas por tenant, e um job obrigaria a ter um worker de filas activo em produção só para isto. A idempotência faz o "apanhar o que falhou" (critério 3 de §8.2) sem estado extra. **P2 aprovado pelo dono** (síncrono por tenant). |
| D6 | **Âmbitos HTTP:** uma matrícula (pelo `numero_registo_matricula`), uma turma, um nível, ou "todas as turmas de um plano" (plano **activo** obrigatório). `TODAS` só existe para o comando. Ano lectivo **sempre explícito**. Matrículas não Activas dentro do âmbito aparecem como ignoradas com o estado (o comando só lê Activas). | Spec §7.5 e pedido do dono; o número de registo é o que o balcão conhece (a matrícula não tem outro identificador visível). |
| D7 | **Períodos anteriores ao mês da matrícula** (`periodo_fim < 1.º dia do mês de data_matricula`) só com `incluir_periodos_anteriores = true`, **`propina.ajustar`** (o FormRequest recusa com 403) e `motivo_geracao` (422 sem ele); nascem com origem `RETROACTIVA` e o motivo; os restantes períodos do mesmo pedido mantêm a origem do pedido (`MANUAL`). | Q9 + §8.1/Q4. **P1 aprovado pelo dono:** o ADMIN_ESCOLA passa a ter `propina.ajustar` já em F2 (Task 8: `SincronizarPerfisDeSistemaAction` + `financeiro:sincronizar` para os tenants existentes + `PermissoesPropinaTest`). |
| D8 | **"Geração tardia" não é coluna:** na pré-visualização = `hoje (escola) > data_vencimento`; na lista = origem `RETROACTIVA` ou dia local de `created_at` (fuso da escola) > `data_vencimento`. | §8.1/Q4 pede a sinalização; derivá-la evita uma migração e não pode divergir do snapshot. |
| D9 | **Permissões:** lista com `can:propina.ver` (+ `authorize` no controller); pré-visualizar e gerar com `can:propina.criar` + `authorize` no controller + `authorize()` no FormRequest; menu com `propina.ver`. `propina.listar` continua declarada mas não usada nas rotas (Q11, convenção do projecto). | Q11 aprovado. Nada muda em `Modulo` nem em `ModuloSeeder`. F1 concedeu `ver, listar, criar, cancelar, exportar` ao ADMIN_ESCOLA; **P1:** `SincronizarPerfisDeSistemaAction` acrescenta `ajustar` (Task 8). |
| D10 | Origem `MATRICULA` fica reservada a F3 (geração ao activar). F2 usa `MANUAL`, `COMANDO` e `RETROACTIVA`. | Q7 vive em F3. |
| D11 | **Nenhuma tabela, coluna, model ou migração novos** (a única mudança de dados por omissão é `propina.ajustar` no ADMIN_ESCOLA, P1, aplicada por `SincronizarPerfisDeSistemaAction`). A matriz de isolamento (`MatrizIsolamentoTest`) e o `TenancyEsquemaTest` não mudam. O único ponto de partilha novo é a chave flash `geracao_propinas` em `HandleInertiaRequests`. | F1 já criou tudo o que a geração precisa (incluindo `motivo_geracao` e `origem_geracao`). |
| D12 | **Ponto único de criação:** só `GerarPropinasService` cria `Propina` em `app`/`routes`/seeders (novo `CriadoresDePropinaTest`, Task 12). | §1.2 do plano de fases ("Criação de propinas → `GerarPropinasService`"). |
| D13 | Resultado com detalhe **limitado a 200 matrículas** (erros e conflitos primeiro); os totais contam sempre todas. | Uma escola inteira daria um JSON/flash de vários MB. |
| D14 | Testes de concorrência numa base nova `Tests\PgsqlConcorrenciaTestCase` (`DatabaseTruncation`: dados **confirmados**, visíveis a uma segunda ligação `pgsql_concorrente` com `lock_timeout`), que trunca de novo no `tearDown` para não sujar os testes `pgsql` com `RefreshDatabase`. | Com `RefreshDatabase` nada é confirmado e a segunda ligação não vê os dados; sem limpeza, o tenant `MOSI-000001` ficaria gravado e o `PgsqlTestCase` seguinte falharia ao criá-lo. |
| D15 | O cartão de filtros da lista segue `Pages/PlanosPropina/Index.vue` (`card mb-6`); `bg-body-secondary` só nos cartões internos do modal (resultado por matrícula), como em `CopiarPlanosModal.vue`. | Memória "reaproveitar padrão visual existente" (o plano de fases sugeria `bg-body-secondary` no cartão de filtros, mas a página irmã não o usa). |
| D16 | Ícone `ki-dollar` com **3** caminhos (`path1..3`, medido em `plugins.bundle.css`); o placeholder da sidebar dizia `paths: 2`. | Ícone duotone completo nos dois menus. |
| D17 | **Limite de 500 matrículas por pedido manual (P5 aprovado):** a pré-visualização e a confirmação recusam com 422 (`ValidationException` no campo `ambito`, a pedir que se restrinja por turma ou nível) quando o âmbito tem mais de `GerarPropinasService::LIMITE_MATRICULAS_POR_PEDIDO` (500) matrículas; nada é calculado nem gravado. O comando (`TODAS`) não tem limite. | R-F2.4: um pedido HTTP com milhares de matrículas passaria o tempo de resposta. |
| D18 | **Geração automática segue o spec §7.2 (P3 aprovado):** desde o mês da matrícula, incluindo meses já passados (que nascem Em Atraso). Em Regras de Cobrança, junto do interruptor `gerar_automaticamente`, um aviso recomenda pré-visualizar e gerar manualmente por turma antes de ligar (depois disso o comando é idempotente e só cria o que falta). | R-F2.3. |

**Não provado em SQLite (só no grupo pgsql, Task 13):** `lockForUpdate` da matrícula a impedir outra ligação de inserir propinas dela; `sharedLock` dos planos a bloquear um `FOR UPDATE` (divisão F4) e a deixar passar um `FOR SHARE` (outra geração); violação do índice parcial vinda de outra ligação; reversão só da matrícula que falhou com a transacção do PostgreSQL ainda utilizável a seguir.

## Notas pré-voo (verificadas no código e por execução numa cópia descartável em 2026-10-11)

- `ResolvePlanoAplicavel::paraTurma` carrega **todos** os planos activos do ano (com alvos e ano) a cada chamada → `ResolucaoDePlanosMemorizada` (Task 4).
- `RegraCobranca::doTenant()`, `ConfiguracaoMonetaria::doTenant()` e por isso `MoedaDoTenant::atual()`/`CambioDoDia` usam `firstOrCreate`. Em produção as linhas são provisionadas (`ProvisionarRegrasCobranca`, `ProvisionarConfiguracaoMonetaria`); nos testes a configuração monetária nasce na 1.ª leitura — o teste "a pré-visualização não escreve" aquece-a antes de medir.
- `Propina::creating` faz 2 consultas (matrícula e plano, D9 de F1) e calcula `data_limite`; o `$attributes` põe `estado = 1`, `valor_pago = 0`. `origem_geracao` é obrigatória; `RETROACTIVA` exige `motivo_geracao`.
- `Matricula::data_matricula` tem cast `date` (Carbon mutável); `Matricula` tem `SoftDeletes`; `Propina::matricula()` usa `withTrashed()`.
- `AnoLectivo::current()` devolve o primeiro ano `ATIVO` (filtro por omissão da lista, como em Planos).
- `HandleInertiaRequests` partilha chaves flash explícitas (`success`, `copia_planos`, `senha_temporaria`): `geracao_propinas` tem de ser acrescentada.
- `RotasPermissaoReconhecidaTest` e `RotasEscritaAutorizadasTest` cobrem as rotas novas automaticamente (`can:propina.ver|criar` são abilities reais).
- `PesquisaTexto` regista `whereContem`/`orWhereContem` no query builder (`ilike` em pgsql).
- Sidebar: `const financeiro` e `<SidebarAccordion :item="financeiro" …>` estão comentados; o header não tem dropdown Financeiro (Q13: criar `useFinanceiroMenu.js` + `menus/FinanceiroMenu.vue`).
- O `php artisan test` limpa as variáveis do `.env`: os testes pgsql correm com `vendor/bin/phpunit` (D13 de F1).
- **Pré-voo executado em 2026-10-11** (cópia descartável do repositório, SQLite + PostgreSQL 16 descartável em 127.0.0.1:55432): Tasks 1–14 aplicadas tal como escritas aqui, todas verdes; correcções e decisões P1–P5 já incorporadas neste documento. Relatório: `.superpowers/sdd/2026-10-13-financeiro-propinas-f1/f2-preflight-report.md`.
- **Antes da Task 1:** guardar a lista do que já está em stage (`git diff --cached --name-only > /tmp/f2-stage-antes.txt`) para a verificação da Task 14.
- **Testes sensíveis à hora (pré-existentes, não são de F2):** entre as 23:00 e as 24:00 UTC (00:00–01:00 em Luanda) 5 testes falham porque comparam com `now()` em UTC enquanto o código usa o dia da escola: `CambioPlataformaCommandTest` (sem data usa hoje…), `MoedaCambioTest` (câmbio com data futura…), `PropinaRobustezTest` (contribuição com data futura…), `AlterarEstadoInscricaoDisciplinaActionTest` (conclui inscrição…), `MatriculaActionTest` (preenche data fim ao cancelar…). Não correr a suite nessa janela, ou fixar `Carbon::setTestNow` nesses testes (dívida a registar, fora de F2).

## Fora de âmbito (F3+)

Detalhe da propina, cancelar, anular, `MatriculaEstadoAlterado`, `DependenciasDaMatricula`, geração ao activar uma matrícula (Q7), reconciliação de cancelamentos no comando diário, alteração de preço (F4), multas, pagamentos, exportação. Nenhuma rota de escrita sobre propinas existentes.

## Review Focus

1. Resolução por competência: fases de preço, precedência, conflitos, períodos mistos e meses sem plano dão exactamente as propinas esperadas — Task 5.
2. Idempotência e cobrança dupla: 2.ª execução cria 0; sobreposição mensal × trimestral nos dois sentidos; regerar depois de cancelar; violação de unicidade reverte só essa matrícula — Tasks 6 e 13.
3. Pré-visualização sem nenhuma escrita (`DB::listen`); confirmação recalcula — Tasks 5 e 8.
4. "Hoje" da escola em todas as datas de negócio (tardia, câmbio, estado resolvido na lista) — Tasks 5, 7 e 9.
5. Retroactivas: só com `propina.ajustar` + motivo; origem e motivo só nos períodos anteriores — Tasks 5, 6 e 8.
6. Comando: desligado não faz nada; por tenant; recupera falhas sem duplicar; agendado — Task 9.
7. Menus: sidebar e header com o mesmo link e a mesma permissão — Task 11.
8. Concorrência em PostgreSQL com duas ligações — Task 13.
9. P1 (`propina.ajustar` ao ADMIN_ESCOLA + *backfill*), P5 (limite de 500 por pedido manual, nada calculado nem gravado acima dele) — Task 8; P3 (aviso junto de `gerar_automaticamente`) — Task 10.

## Mapa de ficheiros

```
Modules/Financeiro/
  app/Support/ViolacaoDeUnicidade.php                                       (T1, novo)
  app/Support/{ElegibilidadeDePeriodos,RotuloDePeriodo}.php                 (T2, novos)
  app/Enums/AmbitoGeracaoPropinas.php                                       (T3, novo)
  app/DTO/{PedidoGeracaoPropinasDTO,ResultadoGeracaoMatriculaDTO,ResultadoGeracaoPropinasDTO}.php (T3, novos)
  app/Support/{ResolucaoDePlanosMemorizada,ContextoDeGeracao}.php           (T4, novos)
  app/Services/GerarPropinasService.php                                     (T5 novo; T6 + gerar; T9 + gerarAutomaticamente)
  app/Services/PropinaConsultaService.php                                   (T7, novo)
  app/Http/Controllers/PropinaController.php                                (T7 novo; T8 + geração)
  app/Actions/GerarPropinasAction.php                                       (T8, novo)
  app/Http/Requests/Concerns/ValidaGeracaoPropinas.php                      (T8, novo)
  app/Http/Requests/{PreVisualizarGeracaoPropinasRequest,GerarPropinasRequest}.php (T8, novos)
  app/Console/GerarPropinasCommand.php                                      (T9, novo)
  app/Providers/FinanceiroServiceProvider.php                               (T9, + comando)
  routes/web.php                                                            (T7 GET; T8 POST)
  resources/js/Models/Propina.js                                            (T10, novo)
  resources/js/Support/periodos.js                                          (T10, + dataCurta)
  resources/js/Components/Shared/EstadoCobrancaBadge.vue                    (T10, novo)
  resources/js/Components/Propinas/{GerarPropinasModal,ResultadoGeracao}.vue (T10, novos)
  resources/js/Pages/Propinas/Index.vue                                     (T10, novo)
  resources/js/Pages/RegrasCobranca/Edit.vue                                (T10, + aviso P3)
  tests/Unit/{ViolacaoDeUnicidadeTest,ElegibilidadeDePeriodosTest,RotuloDePeriodoTest,
              PedidoGeracaoPropinasDTOTest,ResultadoGeracaoPropinasDTOTest}.php
  tests/Feature/{ResolucaoDePlanosMemorizadaTest,GerarPropinasPreVisualizacaoTest,GerarPropinasTest,
                 PropinaListaTest,PropinaGeracaoHttpTest,GerarPropinasCommandTest}.php
  tests/Unit/AvisoGeracaoAutomaticaTest.php                                 (T10, novo, P3)
  tests/Feature/PermissoesPropinaTest.php                                   (T8, modificado, P1)
Modules/Permissao/app/Actions/SincronizarPerfisDeSistemaAction.php          (T8, + propina.ajustar no ADMIN_ESCOLA, P1)
  tests/Feature/Pgsql/GeracaoPropinasPgsqlTest.php                          (T13, novo)
app/Http/Middleware/HandleInertiaRequests.php                               (T8, + flash geracao_propinas)
routes/console.php                                                          (T9, + agendamento)
resources/js/Components/Layout/SidebarMenuWrapper.vue                       (T11)
resources/js/Components/Layout/HeaderMenu.vue                               (T11)
resources/js/Components/Layout/menus/FinanceiroMenu.vue                     (T11, novo)
resources/js/Composables/useFinanceiroMenu.js                               (T11, novo)
tests/Feature/Arquitectura/MenuFinanceiroTest.php                           (T11, novo)
tests/Feature/Arquitectura/CriadoresDePropinaTest.php                       (T12, novo)
tests/PgsqlConcorrenciaTestCase.php                                         (T13, novo)
```

---

### Task 1: `ViolacaoDeUnicidade` — reconhecer a rede de segurança dos índices únicos

**Files:**
- Create: `Modules/Financeiro/app/Support/ViolacaoDeUnicidade.php`
- Test: `Modules/Financeiro/tests/Unit/ViolacaoDeUnicidadeTest.php`

**Interfaces:**
- Consumes: `Illuminate\Database\QueryException` (padrão de `Support\ViolacaoDeChave`).
- Produces: `ViolacaoDeUnicidade::e(Throwable $e): bool` — `true` para SQLSTATE `23505` (PostgreSQL) ou `UNIQUE constraint failed` (SQLite, SQLSTATE `23000` genérico); `false` para chave estrangeira, CHECK e qualquer excepção que não seja `QueryException`.

- [ ] **Step 1: Escrever o teste que falha**

`Modules/Financeiro/tests/Unit/ViolacaoDeUnicidadeTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Unit;

use Exception;
use Illuminate\Database\QueryException;
use LogicException;
use Modules\Financeiro\Support\ViolacaoDeUnicidade;
use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class ViolacaoDeUnicidadeTest extends TestCase
{
    private function consulta(string $driver, string $sqlstate, string $mensagem, ?int $codigoDriver = null): QueryException
    {
        $pdo = new PDOException($mensagem);
        $pdo->errorInfo = [$sqlstate, $codigoDriver, $mensagem];
        // PDOException::$code guarda o SQLSTATE (string), como no PDO real.
        (new ReflectionProperty(Exception::class, 'code'))->setValue($pdo, $sqlstate);

        return new QueryException($driver, 'insert into propinas (x) values (?)', [1], $pdo);
    }

    public function test_reconhece_a_violacao_de_unicidade_no_postgresql(): void
    {
        $e = $this->consulta('pgsql', '23505', 'SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "propinas_periodo_activo_unique"');

        $this->assertTrue(ViolacaoDeUnicidade::e($e));
    }

    public function test_reconhece_a_violacao_de_unicidade_no_sqlite(): void
    {
        $e = $this->consulta('sqlite', '23000', 'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: propinas.tenant_id, propinas.matricula_id, propinas.periodo_inicio', 19);

        $this->assertTrue(ViolacaoDeUnicidade::e($e));
    }

    public function test_nao_confunde_chave_estrangeira_check_nem_outras_excepcoes(): void
    {
        $this->assertFalse(ViolacaoDeUnicidade::e($this->consulta('pgsql', '23503', 'SQLSTATE[23503]: Foreign key violation: 7 ERROR:  insert or update on table "propinas" violates foreign key constraint "propinas_matricula_id_foreign"')));
        $this->assertFalse(ViolacaoDeUnicidade::e($this->consulta('pgsql', '23514', 'SQLSTATE[23514]: Check violation: 7 ERROR:  new row for relation "propinas" violates check constraint "propinas_valor_positivo_check"')));
        $this->assertFalse(ViolacaoDeUnicidade::e($this->consulta('sqlite', '23000', 'SQLSTATE[23000]: Integrity constraint violation: 19 FOREIGN KEY constraint failed', 19)));
        $this->assertFalse(ViolacaoDeUnicidade::e(new LogicException('UNIQUE constraint failed')));
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Unit/ViolacaoDeUnicidadeTest.php`
Expected: FAIL (classe `ViolacaoDeUnicidade` inexistente).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Support/ViolacaoDeUnicidade.php`:
```php
<?php

namespace Modules\Financeiro\Support;

use Illuminate\Database\QueryException;
use Throwable;

/**
 * Reconhece uma violação de unicidade (incluindo os índices únicos parciais de `propinas`, a camada 1
 * contra cobrança dupla). Só se usa FORA da transacção que falhou: lá dentro, em PostgreSQL, a
 * transacção já está abortada e qualquer consulta seguinte falharia.
 */
final class ViolacaoDeUnicidade
{
    /** PostgreSQL: SQLSTATE 23505 (unique_violation). */
    private const SQLSTATE_UNICIDADE = '23505';

    public static function e(Throwable $e): bool
    {
        if (! $e instanceof QueryException) {
            return false;
        }

        if ((string) ($e->errorInfo[0] ?? $e->getCode()) === self::SQLSTATE_UNICIDADE) {
            return true;
        }

        // SQLite reporta SQLSTATE 23000 genérico (partilhado por UNIQUE, NOT NULL e FK): distingue-se pela mensagem.
        return stripos($e->getMessage(), 'UNIQUE constraint failed') !== false
            || stripos($e->getMessage(), 'duplicate key value violates unique constraint') !== false;
    }
}
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Unit/ViolacaoDeUnicidadeTest.php`
Expected: PASS (3 testes).

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Support/ViolacaoDeUnicidade.php Modules/Financeiro/tests/Unit/ViolacaoDeUnicidadeTest.php
```

---

### Task 2: `ElegibilidadeDePeriodos` (L19) e `RotuloDePeriodo`

**Files:**
- Create: `Modules/Financeiro/app/Support/ElegibilidadeDePeriodos.php`
- Create: `Modules/Financeiro/app/Support/RotuloDePeriodo.php`
- Test: `Modules/Financeiro/tests/Unit/ElegibilidadeDePeriodosTest.php`, `Modules/Financeiro/tests/Unit/RotuloDePeriodoTest.php`

**Interfaces:**
- Consumes: —
- Produces:
  - `ElegibilidadeDePeriodos` (classe injectável, **não** `final`, para o trancamento a estender — L19): `inicio(CarbonInterface $dataMatricula): CarbonImmutable` (1.º dia do mês, 00:00); `anteriorAMatricula(CarbonInterface $dataMatricula, string $fimDoPeriodo): bool` (`$fimDoPeriodo` em `Y-m-d`; `true` se o período acaba antes desse 1.º dia).
  - `RotuloDePeriodo::competencia(int $ano, int $mes): string` ("Set/2026"); `RotuloDePeriodo::periodo(string $inicio, string $fim): string` ("Set/2026", "Set–Nov/2026", "Dez/2026–Fev/2027"; travessão `–` U+2013).

- [ ] **Step 1: Escrever os testes que falham**

`Modules/Financeiro/tests/Unit/ElegibilidadeDePeriodosTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Unit;

use Carbon\CarbonImmutable;
use Modules\Financeiro\Support\ElegibilidadeDePeriodos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ElegibilidadeDePeriodosTest extends TestCase
{
    public function test_o_inicio_e_o_primeiro_dia_do_mes_da_matricula_a_meia_noite(): void
    {
        $elegibilidade = new ElegibilidadeDePeriodos();

        $this->assertSame('2026-11-01 00:00:00', $elegibilidade->inicio(CarbonImmutable::parse('2026-11-15 18:45:00'))->toDateTimeString());
        $this->assertSame('2026-11-01 00:00:00', $elegibilidade->inicio(CarbonImmutable::parse('2026-11-01'))->toDateTimeString());
    }

    #[DataProvider('casos')]
    public function test_um_periodo_e_anterior_a_matricula_quando_acaba_antes_do_mes_dela(string $dataMatricula, string $fimDoPeriodo, bool $anterior): void
    {
        $this->assertSame($anterior, (new ElegibilidadeDePeriodos())->anteriorAMatricula(CarbonImmutable::parse($dataMatricula), $fimDoPeriodo));
    }

    public static function casos(): array
    {
        return [
            'mês anterior ao da matrícula' => ['2026-11-15', '2026-10-31', true],
            'o próprio mês da matrícula' => ['2026-11-15', '2026-11-30', false],
            'matrícula no dia 1' => ['2026-11-01', '2026-11-30', false],
            'trimestre Set–Nov em curso com matrícula a meio de Outubro' => ['2026-10-15', '2026-11-30', false],
            'trimestre Set–Nov já acabado' => ['2026-12-02', '2026-11-30', true],
            'atravessa o ano civil' => ['2027-01-31', '2026-12-31', true],
            'período futuro' => ['2026-09-01', '2027-06-30', false],
        ];
    }
}
```

`Modules/Financeiro/tests/Unit/RotuloDePeriodoTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Unit;

use Modules\Financeiro\Support\RotuloDePeriodo;
use PHPUnit\Framework\TestCase;

class RotuloDePeriodoTest extends TestCase
{
    public function test_competencia(): void
    {
        $this->assertSame('Set/2026', RotuloDePeriodo::competencia(2026, 9));
        $this->assertSame('Jan/2027', RotuloDePeriodo::competencia(2027, 1));
    }

    public function test_periodos_de_um_mes_do_mesmo_ano_e_a_atravessar_o_ano(): void
    {
        $this->assertSame('Set/2026', RotuloDePeriodo::periodo('2026-09-01', '2026-09-30'));
        $this->assertSame('Set–Nov/2026', RotuloDePeriodo::periodo('2026-09-01', '2026-11-30'));
        $this->assertSame('Dez/2026–Fev/2027', RotuloDePeriodo::periodo('2026-12-01', '2027-02-28'));
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Unit/ElegibilidadeDePeriodosTest.php Modules/Financeiro/tests/Unit/RotuloDePeriodoTest.php`
Expected: FAIL (classes inexistentes).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Support/ElegibilidadeDePeriodos.php`:
```php
<?php

namespace Modules\Financeiro\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Que períodos de cobrança uma matrícula deve (spec §7.2): os que acabam no mês da matrícula ou depois
 * (`periodo_fim >= 1.º dia do mês de data_matricula`). Os anteriores só se geram manualmente, com
 * `propina.ajustar` e motivo (Q9), como RETROACTIVA. Matrícula a meio de um período paga o período
 * inteiro (Q4). Classe própria e injectável (L19): o trancamento de matrícula vai estendê-la com
 * intervalos de actividade sem reescrever o gerador.
 */
class ElegibilidadeDePeriodos
{
    public function inicio(CarbonInterface $dataMatricula): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', sprintf('%04d-%02d-01', $dataMatricula->year, $dataMatricula->month));
    }

    /**
     * @param  string  $fimDoPeriodo  Y-m-d
     */
    public function anteriorAMatricula(CarbonInterface $dataMatricula, string $fimDoPeriodo): bool
    {
        return $fimDoPeriodo < $this->inicio($dataMatricula)->toDateString();
    }
}
```

`Modules/Financeiro/app/Support/RotuloDePeriodo.php`:
```php
<?php

namespace Modules\Financeiro\Support;

/**
 * Rótulos curtos de competências e períodos de cobrança para mensagens e listas ("Set/2026",
 * "Set–Nov/2026", "Dez/2026–Fev/2027"). Recebe datas Y-m-d já validadas (vindas do calendário do plano
 * ou da própria propina).
 */
final class RotuloDePeriodo
{
    private const MESES = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

    public static function competencia(int $ano, int $mes): string
    {
        return self::MESES[$mes - 1] . '/' . $ano;
    }

    public static function periodo(string $inicio, string $fim): string
    {
        $anoInicio = (int) substr($inicio, 0, 4);
        $mesInicio = (int) substr($inicio, 5, 2);
        $anoFim = (int) substr($fim, 0, 4);
        $mesFim = (int) substr($fim, 5, 2);

        if ($anoInicio === $anoFim && $mesInicio === $mesFim) {
            return self::competencia($anoInicio, $mesInicio);
        }

        if ($anoInicio === $anoFim) {
            return self::MESES[$mesInicio - 1] . '–' . self::MESES[$mesFim - 1] . '/' . $anoFim;
        }

        return self::competencia($anoInicio, $mesInicio) . '–' . self::competencia($anoFim, $mesFim);
    }
}
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Unit/ElegibilidadeDePeriodosTest.php Modules/Financeiro/tests/Unit/RotuloDePeriodoTest.php`
Expected: PASS (10 testes: 1 + 7 casos + 2).

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Support/ElegibilidadeDePeriodos.php Modules/Financeiro/app/Support/RotuloDePeriodo.php \
  Modules/Financeiro/tests/Unit/ElegibilidadeDePeriodosTest.php Modules/Financeiro/tests/Unit/RotuloDePeriodoTest.php
```

---

### Task 3: Pedido e resultado da geração (`AmbitoGeracaoPropinas`, DTOs)

**Files:**
- Create: `Modules/Financeiro/app/Enums/AmbitoGeracaoPropinas.php`
- Create: `Modules/Financeiro/app/DTO/PedidoGeracaoPropinasDTO.php`
- Create: `Modules/Financeiro/app/DTO/ResultadoGeracaoMatriculaDTO.php`
- Create: `Modules/Financeiro/app/DTO/ResultadoGeracaoPropinasDTO.php`
- Test: `Modules/Financeiro/tests/Unit/PedidoGeracaoPropinasDTOTest.php`, `Modules/Financeiro/tests/Unit/ResultadoGeracaoPropinasDTOTest.php`

**Interfaces:**
- Consumes: `OrigemGeracaoPropina` (F1), `Matricula` (só leitura de campos de apresentação).
- Produces:
  - `enum AmbitoGeracaoPropinas: string { MATRICULA='matricula'; TURMA='turma'; NIVEL='nivel'; PLANO='plano'; TODAS='todas' }` + `manuais(): list<self>` (sem `TODAS`). Não é persistido (sem `*_descricao`).
  - `PedidoGeracaoPropinasDTO` (readonly): `ano_lectivo_id`, `ambito`, `origem`, `alvo_id ?int` (turma ou nível), `matricula_numero ?string`, `plano_propina_id ?int`, `incluir_periodos_anteriores bool`, `motivo_geracao ?string`; o construtor recusa (`InvalidArgumentException`) combinações impossíveis; `fromRequest(FormRequest)` (origem `MANUAL`); `automatica(int $anoLectivoId)` (âmbito `TODAS`, origem `COMANDO`).
  - `ResultadoGeracaoMatriculaDTO`: `de(Matricula)`, construtor com `matricula_id` e campos de apresentação; `aCriar(array)`, `criadas(): list<array>`, `criada(int $indice, int $propinaId)`, `ignorada(string $motivo, ?string $periodo = null, ?string $plano = null)`, `conflito(string)`, `semPlano(string)`, `erro(string)`, `contagens(): array{criadas,ignoradas,conflitos,sem_plano,erros}`, `toArray()`.
  - `ResultadoGeracaoPropinasDTO`: `__construct(bool $previsualizacao)`, `juntar(ResultadoGeracaoMatriculaDTO)`, `matriculas()`, `totais(): array{matriculas,criadas,ignoradas,conflitos,sem_plano,erros}`, `toArray(int $limite = 200)` (`previsualizacao`, `totais`, `matriculas` — erros, depois conflitos, depois com criadas, depois por id — e `detalhe_truncado`).

- [ ] **Step 1: Escrever os testes que falham**

`Modules/Financeiro/tests/Unit/PedidoGeracaoPropinasDTOTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Unit;

use InvalidArgumentException;
use Modules\Financeiro\DTO\PedidoGeracaoPropinasDTO;
use Modules\Financeiro\Enums\AmbitoGeracaoPropinas;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PedidoGeracaoPropinasDTOTest extends TestCase
{
    public function test_o_pedido_automatico_e_de_todas_as_matriculas_com_origem_comando(): void
    {
        $pedido = PedidoGeracaoPropinasDTO::automatica(5);

        $this->assertSame(5, $pedido->ano_lectivo_id);
        $this->assertSame(AmbitoGeracaoPropinas::TODAS, $pedido->ambito);
        $this->assertSame(OrigemGeracaoPropina::COMANDO, $pedido->origem);
        $this->assertNull($pedido->plano_propina_id);
        $this->assertFalse($pedido->incluir_periodos_anteriores);
        $this->assertNull($pedido->motivo_geracao);
    }

    public function test_os_ambitos_manuais_nao_incluem_todas(): void
    {
        $this->assertNotContains(AmbitoGeracaoPropinas::TODAS, AmbitoGeracaoPropinas::manuais());
        $this->assertCount(4, AmbitoGeracaoPropinas::manuais());
    }

    #[DataProvider('invalidos')]
    public function test_combinacoes_impossiveis_sao_recusadas(array $argumentos, string $mensagem): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($mensagem);

        new PedidoGeracaoPropinasDTO(...array_merge([
            'ano_lectivo_id' => 1,
            'ambito' => AmbitoGeracaoPropinas::TURMA,
            'origem' => OrigemGeracaoPropina::MANUAL,
            'alvo_id' => 3,
        ], $argumentos));
    }

    public static function invalidos(): array
    {
        return [
            'origem retroactiva no pedido' => [['origem' => OrigemGeracaoPropina::RETROACTIVA], 'por período'],
            'períodos anteriores sem motivo' => [['incluir_periodos_anteriores' => true, 'motivo_geracao' => '   '], 'motivo'],
            'períodos anteriores pelo comando' => [['origem' => OrigemGeracaoPropina::COMANDO, 'incluir_periodos_anteriores' => true, 'motivo_geracao' => 'x'], 'manual'],
            'turma sem turma' => [['alvo_id' => null], 'turma ou o nível'],
            'todas as do plano sem plano' => [['ambito' => AmbitoGeracaoPropinas::PLANO, 'alvo_id' => null], 'exige o plano'],
            'matrícula sem número' => [['ambito' => AmbitoGeracaoPropinas::MATRICULA, 'alvo_id' => null], 'número de registo'],
        ];
    }
}
```

`Modules/Financeiro/tests/Unit/ResultadoGeracaoPropinasDTOTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Unit;

use Modules\Financeiro\DTO\ResultadoGeracaoMatriculaDTO;
use Modules\Financeiro\DTO\ResultadoGeracaoPropinasDTO;
use PHPUnit\Framework\TestCase;

class ResultadoGeracaoPropinasDTOTest extends TestCase
{
    private function matricula(int $id, int $criadas = 0, int $conflitos = 0, int $erros = 0): ResultadoGeracaoMatriculaDTO
    {
        $matricula = new ResultadoGeracaoMatriculaDTO(matricula_id: $id, aluno_nome: "Aluno {$id}");

        for ($i = 0; $i < $criadas; $i++) {
            $matricula->aCriar(['periodo' => "P{$i}"]);
        }

        for ($i = 0; $i < $conflitos; $i++) {
            $matricula->conflito("C{$i}");
        }

        for ($i = 0; $i < $erros; $i++) {
            $matricula->erro("E{$i}");
        }

        $matricula->ignorada('Já coberto.', 'Set/2026', 'Plano');
        $matricula->semPlano('Jul/2027');

        return $matricula;
    }

    public function test_os_totais_somam_todas_as_matriculas(): void
    {
        $resultado = new ResultadoGeracaoPropinasDTO(previsualizacao: true);
        $resultado->juntar($this->matricula(1, criadas: 2));
        $resultado->juntar($this->matricula(2, conflitos: 1));
        $resultado->juntar($this->matricula(3, erros: 1));

        $this->assertSame(
            ['matriculas' => 3, 'criadas' => 2, 'ignoradas' => 3, 'conflitos' => 1, 'sem_plano' => 3, 'erros' => 1],
            $resultado->totais(),
        );
    }

    public function test_o_detalhe_mostra_erros_depois_conflitos_depois_criadas_e_corta_no_limite(): void
    {
        $resultado = new ResultadoGeracaoPropinasDTO(previsualizacao: false);
        $resultado->juntar($this->matricula(1));
        $resultado->juntar($this->matricula(2, criadas: 1));
        $resultado->juntar($this->matricula(3, conflitos: 1));
        $resultado->juntar($this->matricula(4, erros: 1));

        $array = $resultado->toArray(limite: 3);

        $this->assertSame([4, 3, 2], array_column($array['matriculas'], 'matricula_id'));
        $this->assertTrue($array['detalhe_truncado']);
        $this->assertFalse($array['previsualizacao']);
        $this->assertSame(4, $array['totais']['matriculas']);
        $this->assertFalse($resultado->toArray()['detalhe_truncado']);
        $this->assertSame(
            ['matricula_id', 'numero_registo', 'aluno_nome', 'aluno_numero', 'turma_nome', 'criadas', 'ignoradas', 'conflitos', 'sem_plano', 'erros'],
            array_keys($array['matriculas'][0]),
        );
    }

    public function test_criada_regista_o_id_na_linha_certa(): void
    {
        $matricula = new ResultadoGeracaoMatriculaDTO(matricula_id: 9);
        $matricula->aCriar(['periodo' => 'Set/2026']);
        $matricula->aCriar(['periodo' => 'Out/2026']);

        $matricula->criada(1, 77);

        $this->assertSame([['periodo' => 'Set/2026'], ['periodo' => 'Out/2026', 'id' => 77]], $matricula->criadas());
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Unit/PedidoGeracaoPropinasDTOTest.php Modules/Financeiro/tests/Unit/ResultadoGeracaoPropinasDTOTest.php`
Expected: FAIL (classes inexistentes).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Enums/AmbitoGeracaoPropinas.php`:
```php
<?php

namespace Modules\Financeiro\Enums;

/**
 * Que matrículas uma geração de propinas percorre. Não é persistido. TODAS é só do comando automático;
 * a geração manual escolhe uma matrícula, uma turma, um nível ou as turmas de um plano (spec §7.5).
 */
enum AmbitoGeracaoPropinas: string
{
    case MATRICULA = 'matricula';
    case TURMA = 'turma';
    case NIVEL = 'nivel';
    case PLANO = 'plano';
    case TODAS = 'todas';

    /**
     * @return list<self>
     */
    public static function manuais(): array
    {
        return [self::MATRICULA, self::TURMA, self::NIVEL, self::PLANO];
    }
}
```

`Modules/Financeiro/app/DTO/PedidoGeracaoPropinasDTO.php`:
```php
<?php

namespace Modules\Financeiro\DTO;

use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;
use Modules\Financeiro\Enums\AmbitoGeracaoPropinas;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;

/**
 * O que gerar: ano lectivo (sempre explícito), âmbito, origem e, opcionalmente, o plano a que a geração
 * se limita (L9) e os períodos anteriores ao mês da matrícula (com motivo, só manual). A origem
 * RETROACTIVA nunca vem no pedido: atribui-se por período, aos anteriores à matrícula.
 */
final class PedidoGeracaoPropinasDTO
{
    public function __construct(
        public readonly int $ano_lectivo_id,
        public readonly AmbitoGeracaoPropinas $ambito,
        public readonly OrigemGeracaoPropina $origem,
        public readonly ?int $alvo_id = null,
        public readonly ?string $matricula_numero = null,
        public readonly ?int $plano_propina_id = null,
        public readonly bool $incluir_periodos_anteriores = false,
        public readonly ?string $motivo_geracao = null,
    ) {
        if ($origem === OrigemGeracaoPropina::RETROACTIVA) {
            throw new InvalidArgumentException('A origem Retroactiva atribui-se por período, nunca ao pedido.');
        }

        if ($incluir_periodos_anteriores && trim((string) $motivo_geracao) === '') {
            throw new InvalidArgumentException('Incluir períodos anteriores exige o motivo da geração.');
        }

        if ($incluir_periodos_anteriores && $origem !== OrigemGeracaoPropina::MANUAL) {
            throw new InvalidArgumentException('Só a geração manual pode incluir períodos anteriores.');
        }

        if (in_array($ambito, [AmbitoGeracaoPropinas::TURMA, AmbitoGeracaoPropinas::NIVEL], true) && $alvo_id === null) {
            throw new InvalidArgumentException('O âmbito turma ou nível exige a turma ou o nível.');
        }

        if ($ambito === AmbitoGeracaoPropinas::PLANO && $plano_propina_id === null) {
            throw new InvalidArgumentException('O âmbito «todas as do plano» exige o plano.');
        }

        if ($ambito === AmbitoGeracaoPropinas::MATRICULA && trim((string) $matricula_numero) === '') {
            throw new InvalidArgumentException('O âmbito matrícula exige o número de registo da matrícula.');
        }
    }

    public static function fromRequest(FormRequest $request): self
    {
        $dados = $request->validated();
        $ambito = AmbitoGeracaoPropinas::from($dados['ambito']);
        $incluir = (bool) ($dados['incluir_periodos_anteriores'] ?? false);

        return new self(
            ano_lectivo_id: (int) $dados['ano_lectivo_id'],
            ambito: $ambito,
            origem: OrigemGeracaoPropina::MANUAL,
            alvo_id: match ($ambito) {
                AmbitoGeracaoPropinas::TURMA => (int) $dados['turma_id'],
                AmbitoGeracaoPropinas::NIVEL => (int) $dados['nivel_academico_id'],
                default => null,
            },
            matricula_numero: $ambito === AmbitoGeracaoPropinas::MATRICULA ? trim((string) $dados['matricula_numero']) : null,
            plano_propina_id: isset($dados['plano_propina_id']) ? (int) $dados['plano_propina_id'] : null,
            incluir_periodos_anteriores: $incluir,
            motivo_geracao: $incluir ? trim((string) $dados['motivo_geracao']) : null,
        );
    }

    public static function automatica(int $anoLectivoId): self
    {
        return new self(
            ano_lectivo_id: $anoLectivoId,
            ambito: AmbitoGeracaoPropinas::TODAS,
            origem: OrigemGeracaoPropina::COMANDO,
        );
    }
}
```

`Modules/Financeiro/app/DTO/ResultadoGeracaoMatriculaDTO.php`:
```php
<?php

namespace Modules\Financeiro\DTO;

use Modules\Matricula\Models\Matricula;

/**
 * O que a geração fez (ou faria, na pré-visualização) para UMA matrícula. Cada item de `criadas` traz os
 * dados a gravar e a mostrar; depois de gravado ganha `id`. Se a transacção da matrícula falhar, o
 * serviço deita este objecto fora e devolve um novo só com o erro (nada desta matrícula ficou gravado).
 */
final class ResultadoGeracaoMatriculaDTO
{
    /** @var list<array<string, mixed>> */
    private array $criadas = [];

    /** @var list<array{periodo: ?string, plano: ?string, motivo: string}> */
    private array $ignoradas = [];

    /** @var list<string> */
    private array $conflitos = [];

    /** @var list<string> */
    private array $semPlano = [];

    /** @var list<string> */
    private array $erros = [];

    public function __construct(
        public readonly int $matricula_id,
        public readonly ?string $numero_registo = null,
        public readonly ?string $aluno_nome = null,
        public readonly ?string $aluno_numero = null,
        public readonly ?string $turma_nome = null,
    ) {
    }

    public static function de(Matricula $matricula): self
    {
        return new self(
            matricula_id: (int) $matricula->id,
            numero_registo: $matricula->numero_registo_matricula,
            aluno_nome: $matricula->aluno?->dadosPessoa?->nome_completo,
            aluno_numero: $matricula->aluno?->numero_matricula,
            turma_nome: $matricula->turma?->nome,
        );
    }

    /**
     * @param  array<string, mixed>  $propina
     */
    public function aCriar(array $propina): void
    {
        $this->criadas[] = $propina;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function criadas(): array
    {
        return $this->criadas;
    }

    public function criada(int $indice, int $propinaId): void
    {
        $this->criadas[$indice]['id'] = $propinaId;
    }

    public function ignorada(string $motivo, ?string $periodo = null, ?string $plano = null): void
    {
        $this->ignoradas[] = ['periodo' => $periodo, 'plano' => $plano, 'motivo' => $motivo];
    }

    public function conflito(string $descricao): void
    {
        $this->conflitos[] = $descricao;
    }

    public function semPlano(string $competencia): void
    {
        $this->semPlano[] = $competencia;
    }

    public function erro(string $mensagem): void
    {
        $this->erros[] = $mensagem;
    }

    /**
     * @return array{criadas: int, ignoradas: int, conflitos: int, sem_plano: int, erros: int}
     */
    public function contagens(): array
    {
        return [
            'criadas' => count($this->criadas),
            'ignoradas' => count($this->ignoradas),
            'conflitos' => count($this->conflitos),
            'sem_plano' => count($this->semPlano),
            'erros' => count($this->erros),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'matricula_id' => $this->matricula_id,
            'numero_registo' => $this->numero_registo,
            'aluno_nome' => $this->aluno_nome,
            'aluno_numero' => $this->aluno_numero,
            'turma_nome' => $this->turma_nome,
            'criadas' => $this->criadas,
            'ignoradas' => $this->ignoradas,
            'conflitos' => $this->conflitos,
            'sem_plano' => $this->semPlano,
            'erros' => $this->erros,
        ];
    }
}
```

`Modules/Financeiro/app/DTO/ResultadoGeracaoPropinasDTO.php`:
```php
<?php

namespace Modules\Financeiro\DTO;

/**
 * Resultado de uma geração (ou pré-visualização): totais de todas as matrículas e o detalhe por matrícula,
 * limitado (D13) com as matrículas com erros e conflitos primeiro, para o recorte nunca esconder problemas.
 */
final class ResultadoGeracaoPropinasDTO
{
    public const LIMITE_DETALHE = 200;

    /** @var list<ResultadoGeracaoMatriculaDTO> */
    private array $matriculas = [];

    public function __construct(public readonly bool $previsualizacao)
    {
    }

    public function juntar(ResultadoGeracaoMatriculaDTO $matricula): void
    {
        $this->matriculas[] = $matricula;
    }

    /**
     * @return list<ResultadoGeracaoMatriculaDTO>
     */
    public function matriculas(): array
    {
        return $this->matriculas;
    }

    /**
     * @return array{matriculas: int, criadas: int, ignoradas: int, conflitos: int, sem_plano: int, erros: int}
     */
    public function totais(): array
    {
        $totais = ['matriculas' => count($this->matriculas), 'criadas' => 0, 'ignoradas' => 0, 'conflitos' => 0, 'sem_plano' => 0, 'erros' => 0];

        foreach ($this->matriculas as $matricula) {
            foreach ($matricula->contagens() as $chave => $quantidade) {
                $totais[$chave] += $quantidade;
            }
        }

        return $totais;
    }

    /**
     * @return array{previsualizacao: bool, totais: array<string, int>, matriculas: list<array<string, mixed>>, detalhe_truncado: bool}
     */
    public function toArray(int $limite = self::LIMITE_DETALHE): array
    {
        $ordenadas = $this->matriculas;

        usort($ordenadas, function (ResultadoGeracaoMatriculaDTO $a, ResultadoGeracaoMatriculaDTO $b): int {
            $ca = $a->contagens();
            $cb = $b->contagens();

            return [$cb['erros'] > 0, $cb['conflitos'] > 0, $cb['criadas'] > 0, $a->matricula_id]
                <=> [$ca['erros'] > 0, $ca['conflitos'] > 0, $ca['criadas'] > 0, $b->matricula_id];
        });

        return [
            'previsualizacao' => $this->previsualizacao,
            'totais' => $this->totais(),
            'matriculas' => array_map(fn (ResultadoGeracaoMatriculaDTO $m) => $m->toArray(), array_slice($ordenadas, 0, $limite)),
            'detalhe_truncado' => count($ordenadas) > $limite,
        ];
    }
}
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Unit/PedidoGeracaoPropinasDTOTest.php Modules/Financeiro/tests/Unit/ResultadoGeracaoPropinasDTOTest.php`
Expected: PASS (11 testes: 2 + 6 casos + 3).

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Enums/AmbitoGeracaoPropinas.php Modules/Financeiro/app/DTO/PedidoGeracaoPropinasDTO.php \
  Modules/Financeiro/app/DTO/ResultadoGeracaoMatriculaDTO.php Modules/Financeiro/app/DTO/ResultadoGeracaoPropinasDTO.php \
  Modules/Financeiro/tests/Unit/PedidoGeracaoPropinasDTOTest.php Modules/Financeiro/tests/Unit/ResultadoGeracaoPropinasDTOTest.php
```

---

### Task 4: `ResolucaoDePlanosMemorizada` (R6 + L15) e `ContextoDeGeracao`

**Files:**
- Create: `Modules/Financeiro/app/Support/ResolucaoDePlanosMemorizada.php`
- Create: `Modules/Financeiro/app/Support/ContextoDeGeracao.php`
- Test: `Modules/Financeiro/tests/Feature/ResolucaoDePlanosMemorizadaTest.php`

**Interfaces:**
- Consumes: `ResolvePlanoAplicavel::paraTurma(Turma, ?array{ano,mes}): ResultadoResolucaoPlano`, `PlanoPropina::activos()`, `PlanoPropina::competencias()`, `PlanoPropinaAlvo`.
- Produces:
  - `ResolucaoDePlanosMemorizada(ResolvePlanoAplicavel)` — `paraTurma(Turma $turma, int $ano, int $mes): ResultadoResolucaoPlano` (memorizado por turma+mês); `mesesCobraveis(int $anoLectivoId): list<array{ano,mes}>` (união ordenada das competências dos planos **activos** do ano); `sincronizar(int $anoLectivoId, bool $bloquear): bool` (impressão sha256 de planos + alvos do ano; com `$bloquear` usa `sharedLock()` e exige transacção aberta; devolve `true` se esqueceu a memória).
  - `ContextoDeGeracao` (readonly): `resolucao`, `hoje CarbonImmutable`, `dia_vencimento int`, `dias_tolerancia int`, `moeda string`, `cambio_usd ?int`. Uma instância **por execução** (nunca partilhada entre tenants).

- [ ] **Step 1: Escrever o teste que falha**

`Modules/Financeiro/tests/Feature/ResolucaoDePlanosMemorizadaTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Enums\Estado;
use Modules\Financeiro\Models\PlanoPropinaAlvo;
use Modules\Financeiro\Services\ResolvePlanoAplicavel;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Support\ResolucaoDePlanosMemorizada;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Tests\TestCase;

class ResolucaoDePlanosMemorizadaTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use RefreshDatabase;

    private int $consultas = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::listen(function () {
            $this->consultas++;
        });
    }

    private function memoria(): ResolucaoDePlanosMemorizada
    {
        return new ResolucaoDePlanosMemorizada(app(ResolvePlanoAplicavel::class));
    }

    public function test_memoriza_por_turma_e_competencia(): void
    {
        $ano = $this->anoLectivo();
        $turma = $this->turma($ano, $this->nivel('N1'));
        $plano = $this->plano($ano, 'Geral');
        $memoria = $this->memoria();
        $memoria->sincronizar($ano->id, bloquear: false);

        $primeira = $memoria->paraTurma($turma, 2026, 9);
        $antes = $this->consultas;
        $segunda = $memoria->paraTurma($turma, 2026, 9);

        $this->assertSame($plano->id, $primeira->plano->id);
        $this->assertSame($primeira, $segunda);
        $this->assertSame($antes, $this->consultas, 'A segunda resolução da mesma turma e mês não vai à base.');
        $this->assertNotSame($primeira, $memoria->paraTurma($turma, 2026, 10));
    }

    public function test_os_meses_cobraveis_sao_a_uniao_ordenada_dos_planos_activos_do_ano(): void
    {
        $ano = $this->anoLectivo();
        $this->plano($ano, 'Jan–Jun', ['mes_inicio' => 1, 'mes_fim' => 6]);
        $this->plano($ano, 'Set–Dez', ['mes_inicio' => 9, 'mes_fim' => 12]);
        $this->plano($ano, 'Julho inactivo', ['mes_inicio' => 7, 'mes_fim' => 7, 'estado' => Estado::INATIVO->value]);

        $meses = $this->memoria()->mesesCobraveis($ano->id);

        $this->assertSame(
            ['2026-09', '2026-10', '2026-11', '2026-12', '2027-01', '2027-02', '2027-03', '2027-04', '2027-05', '2027-06'],
            array_map(fn (array $c) => sprintf('%04d-%02d', $c['ano'], $c['mes']), $meses),
        );
    }

    public function test_esquece_tudo_quando_um_plano_ou_os_seus_alvos_mudam(): void
    {
        $ano = $this->anoLectivo();
        $nivel = $this->nivel('N1');
        $turma = $this->turma($ano, $nivel);
        $plano = $this->plano($ano, 'Geral');
        $memoria = $this->memoria();

        $this->assertTrue($memoria->sincronizar($ano->id, bloquear: false), 'A primeira impressão conta como mudança.');
        $this->assertSame(2_500_000, $memoria->paraTurma($turma, 2026, 9)->plano->valor->unidadesMenores());
        $this->assertFalse($memoria->sincronizar($ano->id, bloquear: false), 'Nada mudou: a memória mantém-se.');

        $plano->forceFill(['valor' => Dinheiro::deUnidadesMenores(3_000_000)])->save();

        $this->assertTrue($memoria->sincronizar($ano->id, bloquear: false));
        $this->assertSame(3_000_000, $memoria->paraTurma($turma, 2026, 9)->plano->valor->unidadesMenores());

        PlanoPropinaAlvo::create(['plano_propina_id' => $plano->id, 'nivel_academico_id' => $nivel->id]);

        $this->assertTrue($memoria->sincronizar($ano->id, bloquear: false), 'Mudar só os alvos também invalida a memória.');
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/ResolucaoDePlanosMemorizadaTest.php`
Expected: FAIL (classe `ResolucaoDePlanosMemorizada` inexistente).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Support/ResolucaoDePlanosMemorizada.php`:
```php
<?php

namespace Modules\Financeiro\Support;

use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\PlanoPropinaAlvo;
use Modules\Financeiro\Services\ResolvePlanoAplicavel;
use Modules\Turma\Models\Turma;

/**
 * Memória do resolvedor de planos durante UMA execução da geração (R6: ResolvePlanoAplicavel carrega
 * todos os planos do ano a cada chamada). A memória só vale enquanto os planos do ano não mudarem:
 * `sincronizar` tira uma impressão (planos + alvos, valores crus) e esquece tudo se ela mudou.
 *
 * Na geração, `sincronizar(..., bloquear: true)` corre dentro da transacção de cada matrícula e bloqueia
 * as linhas dos planos em modo partilhado (L15): a divisão de um plano (F4, lockForUpdate) espera pelo fim
 * dessa transacção, e uma divisão já confirmada muda a impressão. Nunca partilhar uma instância entre
 * tenants nem entre execuções.
 */
final class ResolucaoDePlanosMemorizada
{
    /** @var array<string, ResultadoResolucaoPlano> */
    private array $resolucoes = [];

    /** @var array<int, list<array{ano: int, mes: int}>> */
    private array $meses = [];

    /** @var array<int, string> impressão por ano lectivo */
    private array $impressoes = [];

    public function __construct(private ResolvePlanoAplicavel $resolver)
    {
    }

    public function paraTurma(Turma $turma, int $ano, int $mes): ResultadoResolucaoPlano
    {
        return $this->resolucoes["{$turma->id}:{$ano}:{$mes}"] ??= $this->resolver->paraTurma($turma, ['ano' => $ano, 'mes' => $mes]);
    }

    /**
     * Meses (ano, mês) em que algum plano activo do ano lectivo cobra, por ordem cronológica.
     *
     * @return list<array{ano: int, mes: int}>
     */
    public function mesesCobraveis(int $anoLectivoId): array
    {
        return $this->meses[$anoLectivoId] ??= $this->calcularMeses($anoLectivoId);
    }

    /**
     * Esquece a memória se os planos do ano (ou os seus alvos) mudaram desde a última impressão.
     * Devolve true se esqueceu.
     */
    public function sincronizar(int $anoLectivoId, bool $bloquear): bool
    {
        if ($bloquear && DB::transactionLevel() === 0) {
            throw new LogicException('Bloquear os planos exige uma transacção aberta (DB::transaction) do chamador.');
        }

        $consulta = PlanoPropina::query()->where('ano_lectivo_id', $anoLectivoId)->orderBy('id');

        if ($bloquear) {
            $consulta->sharedLock();
        }

        $planos = $consulta->get();
        $alvos = PlanoPropinaAlvo::query()->whereIn('plano_propina_id', $planos->modelKeys())->orderBy('id')->get();

        $impressao = hash('sha256', json_encode([
            $planos->map(fn (PlanoPropina $plano) => $plano->getAttributes())->all(),
            $alvos->map(fn (PlanoPropinaAlvo $alvo) => $alvo->getAttributes())->all(),
        ], JSON_THROW_ON_ERROR));

        if (($this->impressoes[$anoLectivoId] ?? null) === $impressao) {
            return false;
        }

        $this->resolucoes = [];
        $this->meses = [];
        $this->impressoes[$anoLectivoId] = $impressao;

        return true;
    }

    /**
     * @return list<array{ano: int, mes: int}>
     */
    private function calcularMeses(int $anoLectivoId): array
    {
        $porOrdinal = [];

        foreach (PlanoPropina::query()->activos()->where('ano_lectivo_id', $anoLectivoId)->with('anoLectivo')->get() as $plano) {
            foreach ($plano->competencias() as $competencia) {
                $porOrdinal[$competencia['ano'] * 12 + $competencia['mes']] = $competencia;
            }
        }

        ksort($porOrdinal);

        return array_values($porOrdinal);
    }
}
```

`Modules/Financeiro/app/Support/ContextoDeGeracao.php`:
```php
<?php

namespace Modules\Financeiro\Support;

use Carbon\CarbonImmutable;

/**
 * Tudo o que uma execução da geração lê uma vez: a memória do resolvedor, o "hoje" da escola
 * (RelogioDoTenant, §8.2b), as regras de cobrança a copiar para o snapshot e a moeda/câmbio do dia.
 * Criado de novo em cada execução e em cada tenant.
 */
final class ContextoDeGeracao
{
    public function __construct(
        public readonly ResolucaoDePlanosMemorizada $resolucao,
        public readonly CarbonImmutable $hoje,
        public readonly int $dia_vencimento,
        public readonly int $dias_tolerancia,
        public readonly string $moeda,
        public readonly ?int $cambio_usd,
    ) {
    }
}
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Feature/ResolucaoDePlanosMemorizadaTest.php`
Expected: PASS (3 testes).

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Support/ResolucaoDePlanosMemorizada.php Modules/Financeiro/app/Support/ContextoDeGeracao.php \
  Modules/Financeiro/tests/Feature/ResolucaoDePlanosMemorizadaTest.php
```

---

### Task 5: `GerarPropinasService` — cálculo por competência e pré-visualização (só leitura)

**Files:**
- Create: `Modules/Financeiro/app/Services/GerarPropinasService.php`
- Test: `Modules/Financeiro/tests/Feature/GerarPropinasPreVisualizacaoTest.php`

**Interfaces:**
- Consumes: T2 (`ElegibilidadeDePeriodos`, `RotuloDePeriodo`), T3 (DTOs, `AmbitoGeracaoPropinas`), T4 (`ResolucaoDePlanosMemorizada`, `ContextoDeGeracao`), `ResolvePlanoAplicavel`, `SnapshotMonetario::em(CarbonInterface): array{moeda, cambio_usd}`, `RelogioDoTenant::hoje()`, `RegraCobranca` (`DEFAULTS`, `dia_vencimento`, `dias_tolerancia`), `PlanoPropina::periodos()/competencias()`, `Propina::scopeActivas()`, `EstadoMatriculaEnum`.
- Produces: `GerarPropinasService::preVisualizar(PedidoGeracaoPropinasDTO): ResultadoGeracaoPropinasDTO` (`previsualizacao = true`, **nenhuma** escrita). Cada item de `criadas` tem, por esta ordem: `plano_propina_id, plano, ordem, periodo_inicio, periodo_fim, periodo, meses, valor, data_vencimento, dias_tolerancia, data_limite, origem_geracao, origem_geracao_descricao, motivo_geracao, tardia`. Motivos de `ignoradas`: matrícula não Activa; sem turma; anos lectivos diferentes; "Aplica-se outro plano a este período: «X»." (L9); "Já coberto pela propina de … (plano «X»)."; "Anterior ao mês da matrícula (dd/mm/aaaa): …". `conflitos`: mês com planos empatados; período com meses de outro plano (D1). `sem_plano`: "Jul/2027".

- [ ] **Step 1: Escrever o teste que falha**

`Modules/Financeiro/tests/Feature/GerarPropinasPreVisualizacaoTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Core\Enums\Estado;
use Modules\Financeiro\DTO\PedidoGeracaoPropinasDTO;
use Modules\Financeiro\Enums\AmbitoGeracaoPropinas;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use Modules\Financeiro\Enums\Periodicidade;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\GerarPropinasService;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Turma\Models\Turma;
use Tests\TestCase;

/**
 * O cálculo da geração (spec §7, L8, L9, L19) visto pela pré-visualização, que nunca escreve.
 * Ano 2026/2027 a começar a 01/09/2026; "hoje" = 01/09/2026 salvo indicação.
 */
class GerarPropinasPreVisualizacaoTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-09-01 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $extras  argumentos nomeados de PedidoGeracaoPropinasDTO
     */
    private function pedido(Turma $turma, array $extras = []): PedidoGeracaoPropinasDTO
    {
        return new PedidoGeracaoPropinasDTO(...array_merge([
            'ano_lectivo_id' => (int) $turma->ano_lectivo_id,
            'ambito' => AmbitoGeracaoPropinas::TURMA,
            'origem' => OrigemGeracaoPropina::MANUAL,
            'alvo_id' => (int) $turma->id,
        ], $extras));
    }

    /** @return array<string, mixed> */
    private function previa(PedidoGeracaoPropinasDTO $pedido): array
    {
        return app(GerarPropinasService::class)->preVisualizar($pedido)->toArray();
    }

    /** @return array<string, mixed> */
    private function daMatricula(array $resultado, Matricula $matricula): array
    {
        foreach ($resultado['matriculas'] as $linha) {
            if ($linha['matricula_id'] === $matricula->id) {
                return $linha;
            }
        }

        $this->fail("A matrícula {$matricula->id} não está no resultado.");
    }

    /** @return list<string> */
    private function periodos(array $linha): array
    {
        return array_column($linha['criadas'], 'periodo');
    }

    public function test_mensal_set_a_jun_da_dez_propinas_com_o_snapshot_das_regras(): void
    {
        ['turma' => $turma, 'matricula' => $matricula, 'plano' => $plano] = $this->cenarioPropinas();

        $linha = $this->daMatricula($this->previa($this->pedido($turma)), $matricula);

        $this->assertSame(
            ['Set/2026', 'Out/2026', 'Nov/2026', 'Dez/2026', 'Jan/2027', 'Fev/2027', 'Mar/2027', 'Abr/2027', 'Mai/2027', 'Jun/2027'],
            $this->periodos($linha),
        );
        $this->assertSame([
            'plano_propina_id' => $plano->id,
            'plano' => 'Propina Mensal',
            'ordem' => 1,
            'periodo_inicio' => '2026-09-01',
            'periodo_fim' => '2026-09-30',
            'periodo' => 'Set/2026',
            'meses' => 1,
            'valor' => 2_500_000,
            'data_vencimento' => '2026-09-10',
            'dias_tolerancia' => 5,
            'data_limite' => '2026-09-15',
            'origem_geracao' => OrigemGeracaoPropina::MANUAL->value,
            'origem_geracao_descricao' => 'Manual',
            'motivo_geracao' => null,
            'tardia' => false,
        ], $linha['criadas'][0]);
        $this->assertSame(range(1, 10), array_column($linha['criadas'], 'ordem'));
        $this->assertSame([], $linha['ignoradas']);
        $this->assertSame([], $linha['conflitos']);
        $this->assertSame([], $linha['sem_plano']);
    }

    public function test_trimestral_set_a_jun_da_3_3_3_1_e_o_ultimo_cobra_o_valor_inteiro(): void
    {
        ['turma' => $turma, 'matricula' => $matricula] = $this->cenarioPropinas([
            'periodicidade' => Periodicidade::TRIMESTRAL,
            'intervalo_meses' => 3,
            'valor' => Dinheiro::deUnidadesMenores(7_000_000),
        ]);

        $linha = $this->daMatricula($this->previa($this->pedido($turma)), $matricula);

        $this->assertSame(['Set–Nov/2026', 'Dez/2026–Fev/2027', 'Mar–Mai/2027', 'Jun/2027'], $this->periodos($linha));
        $this->assertSame([3, 3, 3, 1], array_column($linha['criadas'], 'meses'));
        $this->assertSame(['2026-11-30', '2027-02-28', '2027-05-31', '2027-06-30'], array_column($linha['criadas'], 'periodo_fim'));
        $this->assertSame(array_fill(0, 4, 7_000_000), array_column($linha['criadas'], 'valor'));
    }

    public function test_matricula_a_meio_do_ano_so_gera_a_partir_do_mes_da_matricula(): void
    {
        ['turma' => $turma] = $this->cenarioPropinas();
        $tardia = $this->matriculaActiva($turma, '2026-11-15');

        $linha = $this->daMatricula($this->previa($this->pedido($turma)), $tardia);

        $this->assertCount(8, $linha['criadas']);
        $this->assertSame('Nov/2026', $linha['criadas'][0]['periodo']);
        $this->assertSame(['Set/2026', 'Out/2026'], array_column($linha['ignoradas'], 'periodo'));
        $this->assertStringContainsString('Anterior ao mês da matrícula (15/11/2026)', $linha['ignoradas'][0]['motivo']);
    }

    public function test_trimestral_inclui_o_periodo_em_curso_no_mes_da_matricula(): void
    {
        ['turma' => $turma] = $this->cenarioPropinas(['periodicidade' => Periodicidade::TRIMESTRAL, 'intervalo_meses' => 3]);
        $aMeio = $this->matriculaActiva($turma, '2026-10-15');

        $linha = $this->daMatricula($this->previa($this->pedido($turma)), $aMeio);

        $this->assertSame(['Set–Nov/2026', 'Dez/2026–Fev/2027', 'Mar–Mai/2027', 'Jun/2027'], $this->periodos($linha));
    }

    public function test_incluir_periodos_anteriores_marca_so_esses_como_retroactivos_com_motivo(): void
    {
        ['turma' => $turma] = $this->cenarioPropinas();
        $tardia = $this->matriculaActiva($turma, '2026-11-15');
        $motivo = 'Aluno transferido com propinas de Setembro e Outubro por cobrar.';

        $linha = $this->daMatricula($this->previa($this->pedido($turma, [
            'incluir_periodos_anteriores' => true,
            'motivo_geracao' => $motivo,
        ])), $tardia);

        $this->assertCount(10, $linha['criadas']);
        $this->assertSame(
            [4, 4, 3, 3, 3, 3, 3, 3, 3, 3],
            array_column($linha['criadas'], 'origem_geracao'),
        );
        $this->assertSame($motivo, $linha['criadas'][0]['motivo_geracao']);
        $this->assertNull($linha['criadas'][2]['motivo_geracao']);
        $this->assertSame([], $linha['ignoradas']);
    }

    public function test_so_matriculas_activas_geram_as_outras_ficam_ignoradas_com_o_estado(): void
    {
        ['turma' => $turma] = $this->cenarioPropinas();
        $outras = [];

        foreach ([EstadoMatriculaEnum::PENDENTE, EstadoMatriculaEnum::CANCELADA, EstadoMatriculaEnum::CONCLUIDA, EstadoMatriculaEnum::TRANSFERIDA] as $estado) {
            $outras[$estado->label()] = $this->matriculaActiva($turma, '2026-09-01', $estado);
        }

        $resultado = $this->previa($this->pedido($turma));

        foreach ($outras as $rotulo => $matricula) {
            $linha = $this->daMatricula($resultado, $matricula);
            $this->assertSame([], $linha['criadas'], $rotulo);
            $this->assertStringContainsString("A matrícula está {$rotulo}", $linha['ignoradas'][0]['motivo']);
        }

        $this->assertSame(10, $resultado['totais']['criadas']);
    }

    public function test_precedencia_o_plano_da_turma_vence_o_geral(): void
    {
        $ano = $this->anoLectivo();
        $nivel = $this->nivel('N1');
        $turmaA = $this->turma($ano, $nivel);
        $turmaB = $this->turma($ano, $nivel);
        $this->plano($ano, 'Geral');
        $this->plano($ano, 'Turma A', ['valor' => Dinheiro::deUnidadesMenores(3_000_000)], [['turma' => $turmaA]]);
        $a = $this->matriculaActiva($turmaA);
        $b = $this->matriculaActiva($turmaB);

        $resultado = $this->previa($this->pedido($turmaA, ['ambito' => AmbitoGeracaoPropinas::NIVEL, 'alvo_id' => $nivel->id]));

        $this->assertSame(array_fill(0, 10, 3_000_000), array_column($this->daMatricula($resultado, $a)['criadas'], 'valor'));
        $this->assertSame(['Turma A'], array_values(array_unique(array_column($this->daMatricula($resultado, $a)['criadas'], 'plano'))));
        $this->assertSame(array_fill(0, 10, 2_500_000), array_column($this->daMatricula($resultado, $b)['criadas'], 'valor'));
    }

    public function test_fases_de_preco_resolvem_mes_a_mes(): void
    {
        $ano = $this->anoLectivo();
        $turma = $this->turma($ano, $this->nivel('N1'));
        $this->plano($ano, 'Set–Dez', ['mes_inicio' => 9, 'mes_fim' => 12, 'valor' => Dinheiro::deUnidadesMenores(1_000_000)]);
        $this->plano($ano, 'Jan–Jun', ['mes_inicio' => 1, 'mes_fim' => 6, 'valor' => Dinheiro::deUnidadesMenores(1_200_000)]);
        $matricula = $this->matriculaActiva($turma);

        $linha = $this->daMatricula($this->previa($this->pedido($turma)), $matricula);

        $this->assertSame([...array_fill(0, 4, 1_000_000), ...array_fill(0, 6, 1_200_000)], array_column($linha['criadas'], 'valor'));
        $this->assertSame([1, 2, 3, 4, 1, 2, 3, 4, 5, 6], array_column($linha['criadas'], 'ordem'));
        $this->assertSame('Jan–Jun', $linha['criadas'][4]['plano']);
    }

    public function test_planos_empatados_dao_conflito_e_nada_e_criado(): void
    {
        $ano = $this->anoLectivo();
        $turma = $this->turma($ano, $this->nivel('N1'));
        $this->plano($ano, 'Geral 1');
        $this->plano($ano, 'Geral 2');
        $matricula = $this->matriculaActiva($turma);

        $linha = $this->daMatricula($this->previa($this->pedido($turma)), $matricula);

        $this->assertSame([], $linha['criadas']);
        $this->assertCount(10, $linha['conflitos']);
        $this->assertStringContainsString('Set/2026', $linha['conflitos'][0]);
        $this->assertStringContainsString('«Geral 1»', $linha['conflitos'][0]);
        $this->assertStringContainsString('«Geral 2»', $linha['conflitos'][0]);
    }

    public function test_periodo_com_meses_de_outro_plano_e_conflito_e_os_restantes_geram(): void
    {
        $ano = $this->anoLectivo();
        $turma = $this->turma($ano, $this->nivel('N1'));
        $this->plano($ano, 'Trimestral', ['periodicidade' => Periodicidade::TRIMESTRAL, 'intervalo_meses' => 3]);
        $this->plano($ano, 'Outubro da turma', ['mes_inicio' => 10, 'mes_fim' => 10, 'valor' => Dinheiro::deUnidadesMenores(900_000)], [['turma' => $turma]]);
        $matricula = $this->matriculaActiva($turma);

        $linha = $this->daMatricula($this->previa($this->pedido($turma)), $matricula);

        $this->assertSame(['Out/2026', 'Dez/2026–Fev/2027', 'Mar–Mai/2027', 'Jun/2027'], $this->periodos($linha));
        $this->assertCount(1, $linha['conflitos']);
        $this->assertStringContainsString('Set–Nov/2026', $linha['conflitos'][0]);
        $this->assertStringContainsString('«Trimestral»', $linha['conflitos'][0]);
    }

    public function test_meses_sem_plano_aplicavel_sao_reportados(): void
    {
        $ano = $this->anoLectivo();
        $n1 = $this->nivel('N1');
        $turma = $this->turma($ano, $n1);
        $this->plano($ano, 'Geral');
        $this->plano($ano, 'Julho do N2', ['mes_inicio' => 7, 'mes_fim' => 7], [['nivel' => $this->nivel('N2')]]);
        $matricula = $this->matriculaActiva($turma);

        $linha = $this->daMatricula($this->previa($this->pedido($turma)), $matricula);

        $this->assertCount(10, $linha['criadas']);
        $this->assertSame(['Jul/2027'], $linha['sem_plano']);
    }

    public function test_plano_inactivo_nao_gera(): void
    {
        ['turma' => $turma, 'matricula' => $matricula] = $this->cenarioPropinas(['estado' => Estado::INATIVO->value]);

        $linha = $this->daMatricula($this->previa($this->pedido($turma)), $matricula);

        $this->assertSame([], $linha['criadas']);
        $this->assertSame([], $linha['conflitos']);
    }

    public function test_com_um_plano_escolhido_os_periodos_de_outro_plano_ficam_ignorados(): void
    {
        $ano = $this->anoLectivo();
        $turma = $this->turma($ano, $this->nivel('N1'));
        $setDez = $this->plano($ano, 'Set–Dez', ['mes_inicio' => 9, 'mes_fim' => 12]);
        $this->plano($ano, 'Jan–Jun', ['mes_inicio' => 1, 'mes_fim' => 6]);
        $matricula = $this->matriculaActiva($turma);

        $linha = $this->daMatricula($this->previa($this->pedido($turma, ['plano_propina_id' => $setDez->id])), $matricula);

        $this->assertSame(['Set/2026', 'Out/2026', 'Nov/2026', 'Dez/2026'], $this->periodos($linha));
        $this->assertCount(6, $linha['ignoradas']);
        $this->assertSame('Aplica-se outro plano a este período: «Jan–Jun».', $linha['ignoradas'][0]['motivo']);
    }

    public function test_ambito_plano_so_inclui_as_turmas_a_que_o_plano_se_aplica(): void
    {
        $ano = $this->anoLectivo();
        $n1 = $this->nivel('N1');
        $turma1 = $this->turma($ano, $n1);
        $turma2 = $this->turma($ano, $this->nivel('N2'));
        $this->plano($ano, 'Geral');
        $doN1 = $this->plano($ano, 'Do N1', ['valor' => Dinheiro::deUnidadesMenores(2_800_000)], [['nivel' => $n1]]);
        $m1 = $this->matriculaActiva($turma1);
        $this->matriculaActiva($turma2);

        $resultado = $this->previa($this->pedido($turma1, [
            'ambito' => AmbitoGeracaoPropinas::PLANO,
            'alvo_id' => null,
            'plano_propina_id' => $doN1->id,
        ]));

        $this->assertSame([$m1->id], array_column($resultado['matriculas'], 'matricula_id'));
        $this->assertSame(10, $resultado['totais']['criadas']);
    }

    public function test_ambito_matricula_pelo_numero_de_registo(): void
    {
        ['turma' => $turma, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->matriculaActiva($turma);

        $resultado = $this->previa($this->pedido($turma, [
            'ambito' => AmbitoGeracaoPropinas::MATRICULA,
            'alvo_id' => null,
            'matricula_numero' => $matricula->numero_registo_matricula,
        ]));

        $this->assertSame([$matricula->id], array_column($resultado['matriculas'], 'matricula_id'));
    }

    public function test_a_pre_visualizacao_nao_escreve_nada(): void
    {
        ['turma' => $turma] = $this->cenarioPropinas();
        // Nos testes a configuração monetária nasce na primeira leitura (em produção é provisionada).
        app(MoedaDoTenant::class)->atual();
        $escritas = [];
        DB::listen(function (QueryExecuted $consulta) use (&$escritas) {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $consulta->sql) === 1) {
                $escritas[] = $consulta->sql;
            }
        });

        $resultado = $this->previa($this->pedido($turma));

        $this->assertSame(10, $resultado['totais']['criadas']);
        $this->assertTrue($resultado['previsualizacao']);
        $this->assertSame([], $escritas);
        $this->assertSame(0, Propina::query()->count());
    }

    public function test_a_geracao_tardia_usa_o_dia_da_escola_e_nao_o_de_utc(): void
    {
        // 10/10/2026 23:30 UTC = 11/10/2026 00:30 em Luanda: o vencimento de Outubro (dia 10) já passou na escola.
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-10 23:30:00', 'UTC'));
        ['turma' => $turma, 'matricula' => $matricula] = $this->cenarioPropinas();

        $linha = $this->daMatricula($this->previa($this->pedido($turma)), $matricula);
        $tardias = array_column($linha['criadas'], 'tardia', 'periodo');

        $this->assertTrue($tardias['Set/2026']);
        $this->assertTrue($tardias['Out/2026']);
        $this->assertFalse($tardias['Nov/2026']);
    }

    public function test_turma_de_outro_tenant_nao_traz_matriculas(): void
    {
        $this->cenarioPropinas();
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $turmaDoOutro = $this->noTenant($outro, fn () => $this->cenarioPropinas()['turma']);

        $resultado = $this->previa($this->pedido($turmaDoOutro));

        $this->assertSame([], $resultado['matriculas']);
        $this->assertSame(0, $resultado['totais']['criadas']);
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/GerarPropinasPreVisualizacaoTest.php`
Expected: FAIL (classe `GerarPropinasService` inexistente).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Services/GerarPropinasService.php`:
```php
<?php

namespace Modules\Financeiro\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Modules\Core\Tenancy\TenantContext;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\DTO\PedidoGeracaoPropinasDTO;
use Modules\Financeiro\DTO\ResultadoGeracaoMatriculaDTO;
use Modules\Financeiro\DTO\ResultadoGeracaoPropinasDTO;
use Modules\Financeiro\Enums\AmbitoGeracaoPropinas;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Support\ContextoDeGeracao;
use Modules\Financeiro\Support\ElegibilidadeDePeriodos;
use Modules\Financeiro\Support\ResolucaoDePlanosMemorizada;
use Modules\Financeiro\Support\ResultadoResolucaoPlano;
use Modules\Financeiro\Support\RotuloDePeriodo;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Turma\Models\Turma;

/**
 * Ponto ÚNICO de criação de propinas (spec §7; CriadoresDePropinaTest). Serve a pré-visualização (só
 * leitura), a geração manual confirmada (GerarPropinasAction) e a automática (financeiro:gerar-propinas),
 * todas com o MESMO cálculo:
 *
 * 1. Só matrículas Activas, cuja turma é do ano lectivo da matrícula e do pedido.
 * 2. O plano decide-se mês a mês (L8): ResolvePlanoAplicavel::paraTurma($turma, $competencia), memorizado.
 * 3. Um período de um plano só se gera se TODOS os seus meses resolvem para esse plano (D1).
 * 4. Com um plano escolhido, os períodos de outro plano ficam ignorados — nunca se gera contra o
 *    resolvedor (L9).
 * 5. Período já coberto por uma propina activa da matrícula (qualquer plano, intervalos sobrepostos)
 *    fica ignorado (cobrança dupla, camada 2; a camada 1 são os índices únicos parciais de F1).
 * 6. Períodos anteriores ao mês da matrícula só com «incluir períodos anteriores», como RETROACTIVA (L19, Q9).
 * 7. Snapshot: valor do plano, regras de cobrança, moeda e câmbio do dia, todos no "hoje" da escola (§8.2b).
 */
class GerarPropinasService
{
    public function __construct(
        private ResolvePlanoAplicavel $resolver,
        private ElegibilidadeDePeriodos $elegibilidade,
        private SnapshotMonetario $snapshot,
        private RelogioDoTenant $relogio,
        private TenantContext $tenant,
    ) {
    }

    /**
     * O que a geração faria agora. Nunca escreve (nem regras de cobrança por omissão).
     */
    public function preVisualizar(PedidoGeracaoPropinasDTO $pedido): ResultadoGeracaoPropinasDTO
    {
        $contexto = $this->contexto();
        $contexto->resolucao->sincronizar($pedido->ano_lectivo_id, bloquear: false);
        $resultado = new ResultadoGeracaoPropinasDTO(previsualizacao: true);

        foreach ($this->idsDoAmbito($pedido, $contexto) as $id) {
            $matricula = Matricula::query()->with(['aluno.dadosPessoa', 'turma'])->find($id);

            if ($matricula !== null) {
                $resultado->juntar($this->calcular($matricula, $pedido, $contexto, $this->activas((int) $matricula->id)));
            }
        }

        return $resultado;
    }

    private function contexto(): ContextoDeGeracao
    {
        // Leitura pura: RegraCobranca::doTenant() faria firstOrCreate. Sem linha valem os valores por omissão.
        $regras = RegraCobranca::query()->first();
        $hoje = $this->relogio->hoje();
        $snapshot = $this->snapshot->em($hoje);

        return new ContextoDeGeracao(
            resolucao: new ResolucaoDePlanosMemorizada($this->resolver),
            hoje: $hoje,
            dia_vencimento: (int) ($regras?->dia_vencimento ?? RegraCobranca::DEFAULTS['dia_vencimento']),
            dias_tolerancia: (int) ($regras?->dias_tolerancia ?? RegraCobranca::DEFAULTS['dias_tolerancia']),
            moeda: $snapshot['moeda'],
            cambio_usd: $snapshot['cambio_usd'],
        );
    }

    /**
     * Matrículas do âmbito, por id crescente (ordem estável). O comando só lê as Activas; os âmbitos
     * manuais trazem todas, para as não Activas aparecerem como ignoradas com o motivo.
     *
     * @return list<int>
     */
    private function idsDoAmbito(PedidoGeracaoPropinasDTO $pedido, ContextoDeGeracao $contexto): array
    {
        $consulta = Matricula::query()->where('ano_lectivo_id', $pedido->ano_lectivo_id);

        match ($pedido->ambito) {
            AmbitoGeracaoPropinas::MATRICULA => $consulta->where('numero_registo_matricula', $pedido->matricula_numero),
            AmbitoGeracaoPropinas::TURMA => $consulta->where('turma_id', $pedido->alvo_id),
            AmbitoGeracaoPropinas::NIVEL => $consulta->whereHas('turma', fn ($turma) => $turma->where('nivel_academico_id', $pedido->alvo_id)),
            AmbitoGeracaoPropinas::PLANO => $consulta->whereIn('turma_id', $this->turmasDoPlano($pedido, $contexto)),
            AmbitoGeracaoPropinas::TODAS => $consulta->where('estado', EstadoMatriculaEnum::ACTIVA->value),
        };

        return $consulta->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Turmas do ano a que o plano se aplica em pelo menos um dos seus meses (âmbito "todas as do plano").
     *
     * @return list<int>
     */
    private function turmasDoPlano(PedidoGeracaoPropinasDTO $pedido, ContextoDeGeracao $contexto): array
    {
        $plano = PlanoPropina::query()->with('anoLectivo')->find($pedido->plano_propina_id);

        if ($plano === null) {
            return [];
        }

        $ids = [];

        foreach (Turma::query()->where('ano_lectivo_id', $pedido->ano_lectivo_id)->orderBy('id')->get() as $turma) {
            foreach ($plano->competencias() as $competencia) {
                if ($contexto->resolucao->paraTurma($turma, $competencia['ano'], $competencia['mes'])->plano?->id === $plano->id) {
                    $ids[] = (int) $turma->id;

                    break;
                }
            }
        }

        return $ids;
    }

    /**
     * @return Collection<int, Propina>
     */
    private function activas(int $matriculaId): Collection
    {
        return Propina::query()
            ->activas()
            ->where('matricula_id', $matriculaId)
            ->with('plano:id,nome')
            ->orderBy('periodo_inicio')
            ->get();
    }

    /**
     * O cálculo partilhado pela pré-visualização e pela geração. Não escreve.
     *
     * @param  Collection<int, Propina>  $activas  propinas activas (Em Aberto, Parcial, Paga) da matrícula
     */
    private function calcular(Matricula $matricula, PedidoGeracaoPropinasDTO $pedido, ContextoDeGeracao $contexto, Collection $activas): ResultadoGeracaoMatriculaDTO
    {
        $resultado = ResultadoGeracaoMatriculaDTO::de($matricula);

        if ($matricula->estado !== EstadoMatriculaEnum::ACTIVA) {
            $resultado->ignorada("A matrícula está {$matricula->estado?->label()}: só se geram propinas para matrículas activas.");

            return $resultado;
        }

        $turma = $matricula->turma;

        if ($turma === null) {
            $resultado->ignorada('A matrícula não tem turma (a turma pode ter sido eliminada).');

            return $resultado;
        }

        // Invariante de §3: plano, matrícula e turma do mesmo ano lectivo (o resolvedor só vê planos do ano da turma).
        if ((int) $matricula->ano_lectivo_id !== $pedido->ano_lectivo_id || (int) $turma->ano_lectivo_id !== (int) $matricula->ano_lectivo_id) {
            $resultado->ignorada('A matrícula, a turma e o pedido têm de ser do mesmo ano lectivo: corrija a matrícula.');

            return $resultado;
        }

        $porMes = $this->resolverMeses($turma, $contexto, $resultado);

        foreach ($this->periodosCandidatos($porMes, $resultado) as $candidato) {
            $this->avaliarPeriodo($matricula, $candidato['plano'], $candidato['periodo'], $pedido, $contexto, $activas, $resultado);
        }

        return $resultado;
    }

    /**
     * L8: o plano aplicável decide-se mês a mês. Conflitos e meses sem plano ficam no resultado.
     *
     * @return array<int, ResultadoResolucaoPlano> ordinal (ano × 12 + mês) => resolução
     */
    private function resolverMeses(Turma $turma, ContextoDeGeracao $contexto, ResultadoGeracaoMatriculaDTO $resultado): array
    {
        $porMes = [];

        foreach ($contexto->resolucao->mesesCobraveis((int) $turma->ano_lectivo_id) as $competencia) {
            $resolucao = $contexto->resolucao->paraTurma($turma, $competencia['ano'], $competencia['mes']);
            $porMes[$this->ordinal($competencia)] = $resolucao;
            $rotulo = RotuloDePeriodo::competencia($competencia['ano'], $competencia['mes']);

            if ($resolucao->conflito) {
                $resultado->conflito("{$rotulo}: os planos {$this->nomes($resolucao->candidatos)} aplicam-se com a mesma precedência. Corrija os alvos dos planos.");
            } elseif ($resolucao->plano === null) {
                $resultado->semPlano($rotulo);
            }
        }

        return $porMes;
    }

    /**
     * Períodos dos planos resolvidos cujos meses resolvem TODOS para esse plano, por ordem de início (D1).
     * Um período com meses de outro plano (ou em conflito, ou sem plano) não se gera e fica como conflito:
     * cobrá-lo inteiro cobraria meses de outro plano, e cortá-lo mudaria o calendário do plano.
     *
     * @param  array<int, ResultadoResolucaoPlano>  $porMes
     * @return list<array{plano: PlanoPropina, periodo: array{ordem: int, meses: int, inicio: string, fim: string}}>
     */
    private function periodosCandidatos(array $porMes, ResultadoGeracaoMatriculaDTO $resultado): array
    {
        $planos = [];

        foreach ($porMes as $resolucao) {
            if ($resolucao->temPlano()) {
                $planos[(int) $resolucao->plano->id] = $resolucao->plano;
            }
        }

        ksort($planos);
        $candidatos = [];

        foreach ($planos as $plano) {
            $periodos = $plano->periodos();

            foreach (array_chunk($plano->competencias(), $plano->intervalo_meses) as $indice => $meses) {
                $doPlano = array_filter($meses, fn (array $competencia) => ($porMes[$this->ordinal($competencia)] ?? null)?->plano?->id === $plano->id);

                if ($doPlano === []) {
                    continue;
                }

                $periodo = $periodos[$indice];

                if (count($doPlano) !== count($meses)) {
                    $resultado->conflito('O período ' . RotuloDePeriodo::periodo($periodo['inicio'], $periodo['fim'])
                        . " do plano «{$plano->nome}» inclui meses em que se aplica outro plano (ou nenhum): esse período não foi gerado. Reveja os alvos e os meses dos planos.");

                    continue;
                }

                $candidatos[] = ['plano' => $plano, 'periodo' => $periodo];
            }
        }

        usort($candidatos, fn (array $a, array $b) => [$a['periodo']['inicio'], $a['plano']->id] <=> [$b['periodo']['inicio'], $b['plano']->id]);

        return $candidatos;
    }

    /**
     * @param  array{ordem: int, meses: int, inicio: string, fim: string}  $periodo
     * @param  Collection<int, Propina>  $activas
     */
    private function avaliarPeriodo(
        Matricula $matricula,
        PlanoPropina $plano,
        array $periodo,
        PedidoGeracaoPropinasDTO $pedido,
        ContextoDeGeracao $contexto,
        Collection $activas,
        ResultadoGeracaoMatriculaDTO $resultado,
    ): void {
        $rotulo = RotuloDePeriodo::periodo($periodo['inicio'], $periodo['fim']);

        // L9: nunca se gera contra o resolvedor; com um plano escolhido, os períodos de outro plano ficam de fora.
        if ($pedido->plano_propina_id !== null && (int) $plano->id !== $pedido->plano_propina_id) {
            $resultado->ignorada("Aplica-se outro plano a este período: «{$plano->nome}».", $rotulo, $plano->nome);

            return;
        }

        // Cobrança dupla, camada 2 (spec §3, §14 "regras fechadas"): propina activa da matrícula, de qualquer plano, sobreposta.
        $cobertura = $activas->first(fn (Propina $propina) => $propina->periodo_inicio->toDateString() <= $periodo['fim']
            && $propina->periodo_fim->toDateString() >= $periodo['inicio']);

        if ($cobertura !== null) {
            $resultado->ignorada(
                'Já coberto pela propina de ' . RotuloDePeriodo::periodo($cobertura->periodo_inicio->toDateString(), $cobertura->periodo_fim->toDateString())
                    . " (plano «{$cobertura->plano?->nome}»).",
                $rotulo,
                $plano->nome,
            );

            return;
        }

        $anterior = $this->elegibilidade->anteriorAMatricula($matricula->data_matricula, $periodo['fim']);

        if ($anterior && ! $pedido->incluir_periodos_anteriores) {
            $resultado->ignorada(
                'Anterior ao mês da matrícula (' . $matricula->data_matricula->format('d/m/Y') . '): só se gera com «incluir períodos anteriores».',
                $rotulo,
                $plano->nome,
            );

            return;
        }

        $origem = $anterior ? OrigemGeracaoPropina::RETROACTIVA : $pedido->origem;
        // Spec §5: dia de vencimento das regras no mês de início do período (1–28, sempre válido).
        $vencimento = CarbonImmutable::createFromFormat('!Y-m-d', $periodo['inicio'])->setDay($contexto->dia_vencimento);

        $resultado->aCriar([
            'plano_propina_id' => (int) $plano->id,
            'plano' => $plano->nome,
            'ordem' => $periodo['ordem'],
            'periodo_inicio' => $periodo['inicio'],
            'periodo_fim' => $periodo['fim'],
            'periodo' => $rotulo,
            'meses' => $periodo['meses'],
            'valor' => $plano->valor->unidadesMenores(),
            'data_vencimento' => $vencimento->toDateString(),
            'dias_tolerancia' => $contexto->dias_tolerancia,
            'data_limite' => $vencimento->addDays($contexto->dias_tolerancia)->toDateString(),
            'origem_geracao' => $origem->value,
            'origem_geracao_descricao' => $origem->label(),
            'motivo_geracao' => $origem->exigeMotivo() ? $pedido->motivo_geracao : null,
            // Geração tardia (§8.1/Q4): nasce depois do seu vencimento. Dia da escola, não de UTC (§8.2b).
            'tardia' => $contexto->hoje->toDateString() > $vencimento->toDateString(),
        ]);
    }

    /**
     * @param  array{ano: int, mes: int}  $competencia
     */
    private function ordinal(array $competencia): int
    {
        return $competencia['ano'] * 12 + $competencia['mes'];
    }

    /**
     * @param  list<PlanoPropina>  $planos
     */
    private function nomes(array $planos): string
    {
        return implode(', ', array_map(fn (PlanoPropina $plano) => "«{$plano->nome}»", $planos));
    }
}
```

> O `TenantContext` do construtor só é usado na Task 6 (registo de falhas); fica já injectado para o construtor não mudar.

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Feature/GerarPropinasPreVisualizacaoTest.php`
Expected: PASS (18 testes).

Run: `php artisan test tests/Feature/Arquitectura/RelogioDoTenantArquitecturaTest.php tests/Feature/Arquitectura/EscritoresDePropinaTest.php tests/Feature/Arquitectura/TenancyArquitecturaTest.php`
Expected: PASS (nenhum `now()`/`today()`, nenhuma escrita de cache da propina, nenhum bypass).

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Services/GerarPropinasService.php Modules/Financeiro/tests/Feature/GerarPropinasPreVisualizacaoTest.php
```

---

### Task 6: `GerarPropinasService::gerar` — uma transacção por matrícula, bloqueios e rede de segurança

**Files:**
- Modify: `Modules/Financeiro/app/Services/GerarPropinasService.php`
- Test: `Modules/Financeiro/tests/Feature/GerarPropinasTest.php`

**Interfaces:**
- Consumes: T1 (`ViolacaoDeUnicidade`), T5 (`calcular`, `contexto`, `idsDoAmbito`, `activas`), `Propina::create` (F1: `creating` valida e calcula `data_limite`, `valor_original`), `Dinheiro::deUnidadesMenores`, `TenantContext::id()`.
- Produces: `GerarPropinasService::gerar(PedidoGeracaoPropinasDTO): ResultadoGeracaoPropinasDTO` (`previsualizacao = false`; cada `criadas[i]` ganha `id`; falhas por matrícula em `erros`, sem interromper as outras). **Não chamar dentro de outra transacção.**

- [ ] **Step 1: Escrever o teste que falha**

`Modules/Financeiro/tests/Feature/GerarPropinasTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Core\Enums\Estado;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\DTO\PedidoGeracaoPropinasDTO;
use Modules\Financeiro\DTO\ResultadoGeracaoPropinasDTO;
use Modules\Financeiro\Enums\AmbitoGeracaoPropinas;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use Modules\Financeiro\Enums\Periodicidade;
use Modules\Financeiro\Models\CambioPlataforma;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Services\GerarPropinasService;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Support\TaxaCambio;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Turma\Models\Turma;
use RuntimeException;
use Tests\TestCase;

class GerarPropinasTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-09-01 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $extras
     */
    private function gerar(Turma $turma, array $extras = []): ResultadoGeracaoPropinasDTO
    {
        return app(GerarPropinasService::class)->gerar(new PedidoGeracaoPropinasDTO(...array_merge([
            'ano_lectivo_id' => (int) $turma->ano_lectivo_id,
            'ambito' => AmbitoGeracaoPropinas::TURMA,
            'origem' => OrigemGeracaoPropina::MANUAL,
            'alvo_id' => (int) $turma->id,
        ], $extras)));
    }

    public function test_grava_as_propinas_com_o_snapshot_completo(): void
    {
        ['ano' => $ano, 'turma' => $turma, 'plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

        $resultado = $this->gerar($turma);

        $this->assertFalse($resultado->toArray()['previsualizacao']);
        $this->assertSame(10, $resultado->totais()['criadas']);
        $propinas = Propina::query()->where('matricula_id', $matricula->id)->orderBy('periodo_inicio')->get();
        $this->assertCount(10, $propinas);
        $primeira = $propinas->first();
        $this->assertSame($plano->id, $primeira->plano_propina_id);
        $this->assertSame($ano->id, $primeira->ano_lectivo_id);
        $this->assertSame(1, $primeira->ordem);
        $this->assertSame('2026-09-01', $primeira->periodo_inicio->toDateString());
        $this->assertSame('2026-09-30', $primeira->periodo_fim->toDateString());
        $this->assertSame(2_500_000, $primeira->valor->unidadesMenores());
        $this->assertSame(2_500_000, $primeira->valor_original->unidadesMenores());
        $this->assertSame(0, $primeira->valor_pago->unidadesMenores());
        $this->assertSame(EstadoCobranca::EM_ABERTO, $primeira->estado);
        $this->assertSame('AOA', $primeira->moeda);
        $this->assertNull($primeira->cambio_usd);
        $this->assertSame('2026-09-10', $primeira->data_vencimento->toDateString());
        $this->assertSame(5, $primeira->dias_tolerancia);
        $this->assertSame('2026-09-15', $primeira->data_limite->toDateString());
        $this->assertSame(OrigemGeracaoPropina::MANUAL, $primeira->origem_geracao);
        $this->assertNull($primeira->motivo_geracao);
        $this->assertNull($primeira->criado_por);
        $this->assertSame(
            $propinas->pluck('id')->all(),
            array_column($resultado->toArray()['matriculas'][0]['criadas'], 'id'),
        );
        $this->assertPropinasCoerentes();
    }

    public function test_grava_o_cambio_do_dia_da_escola(): void
    {
        CambioPlataforma::create([
            'moeda_cotada' => 'AOA', 'moeda_base' => 'USD', 'data' => '2026-08-31',
            'taxa' => TaxaCambio::deDecimal('910')->micros(), 'fonte' => 'manual',
        ]);
        ['turma' => $turma] = $this->cenarioPropinas();

        $this->gerar($turma);

        $this->assertSame([910_000_000], Propina::query()->pluck('cambio_usd')->unique()->values()->all());
    }

    public function test_regista_o_autor_quando_ha_utilizador_autenticado(): void
    {
        $this->seed(PermissaoDatabaseSeeder::class);
        $admin = $this->adminEscola();
        ['turma' => $turma] = $this->cenarioPropinas();

        $this->actingAs($admin);
        $this->gerar($turma);

        $this->assertSame([$admin->id], Propina::query()->pluck('criado_por')->unique()->values()->all());
    }

    public function test_e_idempotente_a_segunda_execucao_nao_cria_nada(): void
    {
        ['turma' => $turma] = $this->cenarioPropinas();
        $this->gerar($turma);

        $segunda = $this->gerar($turma);

        $this->assertSame(0, $segunda->totais()['criadas']);
        $this->assertSame(10, $segunda->totais()['ignoradas']);
        $this->assertSame(10, Propina::query()->count());
        $this->assertSame(
            'Já coberto pela propina de Set/2026 (plano «Propina Mensal»).',
            $segunda->toArray()['matriculas'][0]['ignoradas'][0]['motivo'],
        );
    }

    public function test_uma_mensal_existente_cobre_o_trimestre_que_a_contem(): void
    {
        ['ano' => $ano, 'turma' => $turma, 'plano' => $mensal, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->propina($matricula, $mensal, 2); // Out/2026 já cobrado pelo plano mensal
        $mensal->update(['estado' => Estado::INATIVO->value]);
        $this->plano($ano, 'Trimestral', ['periodicidade' => Periodicidade::TRIMESTRAL, 'intervalo_meses' => 3]);

        $linha = $this->gerar($turma)->toArray()['matriculas'][0];

        $this->assertSame(['Dez/2026–Fev/2027', 'Mar–Mai/2027', 'Jun/2027'], array_column($linha['criadas'], 'periodo'));
        $this->assertSame('Set–Nov/2026', $linha['ignoradas'][0]['periodo']);
        $this->assertStringContainsString('Já coberto pela propina de Out/2026', $linha['ignoradas'][0]['motivo']);
        $this->assertPropinasCoerentes();
    }

    public function test_um_trimestre_existente_cobre_os_meses_dele(): void
    {
        ['ano' => $ano, 'turma' => $turma, 'plano' => $trimestral, 'matricula' => $matricula] = $this->cenarioPropinas([
            'periodicidade' => Periodicidade::TRIMESTRAL,
            'intervalo_meses' => 3,
        ]);
        $this->propina($matricula, $trimestral, 1); // Set–Nov/2026
        $trimestral->update(['estado' => Estado::INATIVO->value]);
        $this->plano($ano, 'Mensal');

        $linha = $this->gerar($turma)->toArray()['matriculas'][0];

        $this->assertSame(['Set/2026', 'Out/2026', 'Nov/2026'], array_column($linha['ignoradas'], 'periodo'));
        $this->assertCount(7, $linha['criadas']);
        $this->assertSame('Dez/2026', $linha['criadas'][0]['periodo']);
    }

    public function test_depois_de_cancelar_o_mesmo_periodo_volta_a_gerar(): void
    {
        ['turma' => $turma] = $this->cenarioPropinas();
        $this->gerar($turma);
        $this->comEstado(Propina::query()->where('periodo_inicio', '2026-10-01')->firstOrFail(), EstadoCobranca::CANCELADA);

        $segunda = $this->gerar($turma);

        $this->assertSame(['Out/2026'], array_column($segunda->toArray()['matriculas'][0]['criadas'], 'periodo'));
        $this->assertSame(11, Propina::query()->count());
        $this->assertSame(10, Propina::query()->activas()->count());
    }

    public function test_o_vencimento_e_a_tolerancia_vem_das_regras_de_cobranca(): void
    {
        RegraCobranca::doTenant()->update(['dia_vencimento' => 20, 'dias_tolerancia' => 3]);
        ['turma' => $turma] = $this->cenarioPropinas();

        $this->gerar($turma);

        $janeiro = Propina::query()->where('periodo_inicio', '2027-01-01')->firstOrFail();
        $this->assertSame('2027-01-20', $janeiro->data_vencimento->toDateString());
        $this->assertSame(3, $janeiro->dias_tolerancia);
        $this->assertSame('2027-01-23', $janeiro->data_limite->toDateString());
    }

    public function test_a_geracao_retroactiva_fica_em_atraso_de_imediato_com_origem_e_motivo(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-12-01 10:00:00', 'UTC'));
        ['turma' => $turma] = $this->cenarioPropinas();
        $tardia = $this->matriculaActiva($turma, '2026-11-15');
        $motivo = 'Regularização pedida pela direcção: Setembro e Outubro por cobrar.';

        $this->gerar($turma, [
            'ambito' => AmbitoGeracaoPropinas::MATRICULA,
            'alvo_id' => null,
            'matricula_numero' => $tardia->numero_registo_matricula,
            'incluir_periodos_anteriores' => true,
            'motivo_geracao' => $motivo,
        ]);

        $hoje = app(RelogioDoTenant::class)->hoje();
        $setembro = Propina::query()->where('matricula_id', $tardia->id)->where('periodo_inicio', '2026-09-01')->firstOrFail();
        $novembro = Propina::query()->where('matricula_id', $tardia->id)->where('periodo_inicio', '2026-11-01')->firstOrFail();
        $dezembro = Propina::query()->where('matricula_id', $tardia->id)->where('periodo_inicio', '2026-12-01')->firstOrFail();

        $this->assertSame(OrigemGeracaoPropina::RETROACTIVA, $setembro->origem_geracao);
        $this->assertSame($motivo, $setembro->motivo_geracao);
        $this->assertSame(EstadoCobranca::EM_ATRASO, $setembro->estadoResolvido($hoje));
        $this->assertSame(OrigemGeracaoPropina::MANUAL, $novembro->origem_geracao);
        $this->assertNull($novembro->motivo_geracao);
        $this->assertSame(EstadoCobranca::EM_ATRASO, $novembro->estadoResolvido($hoje));
        $this->assertSame(EstadoCobranca::EM_ABERTO, $dezembro->estadoResolvido($hoje));
    }

    public function test_mudar_plano_regras_e_cambio_depois_nao_altera_as_propinas_existentes(): void
    {
        ['turma' => $turma, 'plano' => $plano] = $this->cenarioPropinas();
        $this->gerar($turma);
        $antes = Propina::query()->orderBy('id')->get()->map(fn (Propina $p) => $p->getAttributes())->all();

        $plano->forceFill(['valor' => Dinheiro::deUnidadesMenores(9_000_000)])->save();
        RegraCobranca::doTenant()->update(['dia_vencimento' => 25, 'dias_tolerancia' => 0]);
        CambioPlataforma::create([
            'moeda_cotada' => 'AOA', 'moeda_base' => 'USD', 'data' => '2026-08-31',
            'taxa' => TaxaCambio::deDecimal('950')->micros(), 'fonte' => 'manual',
        ]);

        $this->assertSame(0, $this->gerar($turma)->totais()['criadas']);
        $this->assertSame($antes, Propina::query()->orderBy('id')->get()->map(fn (Propina $p) => $p->getAttributes())->all());
    }

    public function test_uma_violacao_de_unicidade_reverte_so_a_matricula_que_falhou(): void
    {
        ['turma' => $turma, 'plano' => $plano, 'matricula' => $primeira] = $this->cenarioPropinas();
        $segunda = $this->matriculaActiva($turma);
        $concorrente = false;
        Propina::creating(function (Propina $propina) use ($plano, $primeira, &$concorrente) {
            // Simula outra geração que gravou Outubro desta matrícula entre a leitura e a escrita.
            if ($concorrente || $propina->matricula_id !== $primeira->id || $propina->ordem !== 2) {
                return;
            }

            $concorrente = true;
            $this->propina($primeira, $plano, 2);
        });

        $resultado = $this->gerar($turma);

        $this->assertSame(10, $resultado->totais()['criadas']);
        $this->assertSame(1, $resultado->totais()['erros']);
        $linha = collect($resultado->toArray()['matriculas'])->firstWhere('matricula_id', $primeira->id);
        $this->assertSame([], $linha['criadas']);
        $this->assertStringContainsString('ao mesmo tempo', $linha['erros'][0]);
        $this->assertSame(0, Propina::query()->where('matricula_id', $primeira->id)->count(), 'Setembro e a «concorrente» foram revertidas.');
        $this->assertSame(10, Propina::query()->where('matricula_id', $segunda->id)->count());

        $this->assertSame(10, $this->gerar($turma)->totais()['criadas'], 'A execução seguinte recupera a matrícula que falhou.');
        $this->assertSame(20, Propina::query()->count());
        $this->assertPropinasCoerentes();
    }

    public function test_um_erro_inesperado_numa_matricula_nao_impede_as_outras(): void
    {
        ['turma' => $turma, 'matricula' => $primeira] = $this->cenarioPropinas();
        $segunda = $this->matriculaActiva($turma);
        $falhou = false;
        Propina::creating(function (Propina $propina) use ($primeira, &$falhou) {
            if (! $falhou && $propina->matricula_id === $primeira->id && $propina->ordem === 3) {
                $falhou = true;

                throw new RuntimeException('Falha simulada.');
            }
        });

        $resultado = $this->gerar($turma);

        $linha = collect($resultado->toArray()['matriculas'])->firstWhere('matricula_id', $primeira->id);
        $this->assertStringContainsString('Erro inesperado', $linha['erros'][0]);
        $this->assertSame(0, Propina::query()->where('matricula_id', $primeira->id)->count());
        $this->assertSame(10, Propina::query()->where('matricula_id', $segunda->id)->count());
    }

    public function test_so_gera_no_tenant_corrente(): void
    {
        ['turma' => $turma] = $this->cenarioPropinas();
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, fn () => $this->cenarioPropinas());

        $this->gerar($turma);

        $this->assertSame(10, Propina::query()->count());
        $this->assertSame(0, $this->noTenant($outro, fn () => Propina::query()->count()));
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/GerarPropinasTest.php`
Expected: FAIL (`Call to undefined method … GerarPropinasService::gerar()`).

- [ ] **Step 3: Implementar**

Em `Modules/Financeiro/app/Services/GerarPropinasService.php`, acrescentar aos `use`:
```php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Support\ViolacaoDeUnicidade;
use Throwable;
```

E, a seguir a `preVisualizar()`, os métodos:
```php
    /**
     * Grava as propinas em falta, matrícula a matrícula, cada uma na SUA transacção (spec §7.4): uma falha
     * reverte só essa matrícula e fica em `erros`; as outras ficam gravadas. Recalcula tudo sobre o estado
     * actual (a pré-visualização nunca é reutilizada, D4). Não chamar dentro de outra transacção: cada
     * matrícula tem de confirmar sozinha, e só assim os bloqueios são curtos.
     */
    public function gerar(PedidoGeracaoPropinasDTO $pedido): ResultadoGeracaoPropinasDTO
    {
        $contexto = $this->contexto();
        // Fora de transacção, só para escolher as matrículas do âmbito; cada matrícula volta a sincronizar com bloqueio.
        $contexto->resolucao->sincronizar($pedido->ano_lectivo_id, bloquear: false);
        $resultado = new ResultadoGeracaoPropinasDTO(previsualizacao: false);

        foreach ($this->idsDoAmbito($pedido, $contexto) as $id) {
            $resultado->juntar($this->gerarParaMatricula($id, $pedido, $contexto));
        }

        return $resultado;
    }

    private function gerarParaMatricula(int $id, PedidoGeracaoPropinasDTO $pedido, ContextoDeGeracao $contexto): ResultadoGeracaoMatriculaDTO
    {
        try {
            return DB::transaction(fn () => $this->gravarMatricula($id, $pedido, $contexto));
        } catch (Throwable $e) {
            // Já FORA da transacção, revertida por inteiro: nada desta matrícula ficou gravado. Lá dentro
            // nunca se apanha nada (o PostgreSQL abortaria a transacção).
            return $this->falhou($id, $pedido, $e);
        }
    }

    private function gravarMatricula(int $id, PedidoGeracaoPropinasDTO $pedido, ContextoDeGeracao $contexto): ResultadoGeracaoMatriculaDTO
    {
        // 1. A matrícula primeiro (D2): duas gerações da mesma matrícula fazem fila aqui, uma mudança de estado
        //    da matrícula espera por esta transacção, e inserir propinas dela noutra ligação também (FK → KEY SHARE).
        $matricula = Matricula::query()->whereKey($id)->lockForUpdate()->first();

        if ($matricula === null) {
            $resultado = new ResultadoGeracaoMatriculaDTO(matricula_id: $id);
            $resultado->ignorada('A matrícula já não existe.');

            return $resultado;
        }

        $matricula->load(['aluno.dadosPessoa', 'turma']);

        // 2. Os planos do ano em modo partilhado (L15): a divisão de um plano (F4) espera por nós; uma divisão já
        //    confirmada muda a impressão e a memória do resolvedor é esquecida.
        $contexto->resolucao->sincronizar((int) $matricula->ano_lectivo_id, bloquear: true);

        // 3. Calcular sobre o estado bloqueado e criar com Eloquent, uma a uma (L4: nada de inserções que ignorem
        //    conflitos; PertenceAoTenant e as invariantes do model correm). Os índices únicos parciais de F1 são a rede de segurança.
        $resultado = $this->calcular($matricula, $pedido, $contexto, $this->activas((int) $matricula->id));

        foreach ($resultado->criadas() as $indice => $item) {
            $propina = Propina::create([
                'matricula_id' => $matricula->id,
                'plano_propina_id' => $item['plano_propina_id'],
                'ano_lectivo_id' => $matricula->ano_lectivo_id,
                'ordem' => $item['ordem'],
                'periodo_inicio' => $item['periodo_inicio'],
                'periodo_fim' => $item['periodo_fim'],
                'valor' => Dinheiro::deUnidadesMenores($item['valor']),
                'moeda' => $contexto->moeda,
                'cambio_usd' => $contexto->cambio_usd,
                'data_vencimento' => $item['data_vencimento'],
                'dias_tolerancia' => $item['dias_tolerancia'],
                'origem_geracao' => OrigemGeracaoPropina::from($item['origem_geracao']),
                'motivo_geracao' => $item['motivo_geracao'],
            ]);

            $resultado->criada($indice, (int) $propina->id);
        }

        return $resultado;
    }

    private function falhou(int $id, PedidoGeracaoPropinasDTO $pedido, Throwable $e): ResultadoGeracaoMatriculaDTO
    {
        $concorrencia = ViolacaoDeUnicidade::e($e);

        if (! $concorrencia) {
            report($e);
        }

        // Log estruturado (critério 3 de §8.2): o comando diário volta a tentar esta matrícula.
        Log::warning('financeiro.gerar-propinas.matricula-falhou', [
            'tenant_id' => $this->tenant->id(),
            'matricula_id' => $id,
            'origem' => $pedido->origem->name,
            'excepcao' => $e::class,
        ]);

        $matricula = Matricula::query()->with(['aluno.dadosPessoa', 'turma'])->find($id);
        $resultado = $matricula !== null ? ResultadoGeracaoMatriculaDTO::de($matricula) : new ResultadoGeracaoMatriculaDTO(matricula_id: $id);
        $resultado->erro($concorrencia
            ? 'Outra geração gravou propinas desta matrícula ao mesmo tempo: nada foi gravado aqui. Volte a gerar para confirmar.'
            : 'Erro inesperado ao gerar as propinas desta matrícula: nada foi gravado. Consulte os registos.');

        return $resultado;
    }
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Feature/GerarPropinasTest.php Modules/Financeiro/tests/Feature/GerarPropinasPreVisualizacaoTest.php`
Expected: PASS (13 + 18 testes).

Run: `php artisan test Modules/Financeiro tests/Feature/Arquitectura`
Expected: PASS (F1 intacto; `EscritoresDePropinaTest` não vê `estado`/`valor_pago` no `Propina::create`).

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Services/GerarPropinasService.php Modules/Financeiro/tests/Feature/GerarPropinasTest.php
```

---

### Task 7: Lista de propinas (`PropinaConsultaService`, `PropinaController::index`, rota `GET`)

**Files:**
- Create: `Modules/Financeiro/app/Services/PropinaConsultaService.php`
- Create: `Modules/Financeiro/app/Http/Controllers/PropinaController.php`
- Modify: `Modules/Financeiro/routes/web.php`
- Test: `Modules/Financeiro/tests/Feature/PropinaListaTest.php`

**Interfaces:**
- Consumes: `Propina::scopeComEstadoResolvido(EstadoCobranca, CarbonInterface)`, `Propina::estadoResolvido()`, `Propina::saldo()`, `RelogioDoTenant::hoje()/fuso()`, `RotuloDePeriodo`, `PlanoPropinaConsultaService::{anoLectivoActualId, anosLectivos, turmas, niveis}`, `MoedaDoTenant::atual()->paraFrontend()`, `whereContem` (Core).
- Produces:
  - `PropinaConsultaService::listar(array $filtros): LengthAwarePaginator` (15 por página, `withQueryString`, ordem `data_vencimento, id`). Filtros (inválidos ignorados): `ano_lectivo_id` (numérico; `todos` = sem filtro), `estado` (1–7, **estado resolvido** pelo scope SQL), `plano_propina_id`, `matricula_id`, `origem_geracao` (1–4), `vencimento_de`/`vencimento_ate` (`Y-m-d`), `pesquisa` (nome do aluno, `alunos.numero_matricula`, `matriculas.numero_registo_matricula`). Cada linha: `id, matricula_id, matricula_numero, aluno_nome, aluno_numero, turma_nome, plano_propina_id, plano_nome, ordem, periodo_inicio, periodo_fim, periodo, data_vencimento, data_limite, valor, valor_pago, saldo, estado, estado_descricao, origem_geracao, origem_geracao_descricao, motivo_geracao, geracao_tardia` (sem `tenant_id`).
  - `planos(): Collection` (`id, nome, ano_lectivo_id, estado`), `estados(): list<{value,label}>` (7), `origens(): list<{value,label}>` (4).
  - Rota `GET /financeiro/propinas` → `financeiro.propinas.index` (`can:propina.ver`), página Inertia `Financeiro/Propinas/Index` com props `propinas, filtros, anosLectivos, planos, turmas, niveis, estados, origens, moeda`.

- [ ] **Step 1: Escrever o teste que falha**

`Modules/Financeiro/tests/Feature/PropinaListaTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\DTO\PedidoGeracaoPropinasDTO;
use Modules\Financeiro\Enums\AmbitoGeracaoPropinas;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\GerarPropinasService;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Turma\Models\Turma;
use Tests\TestCase;

class PropinaListaTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    private const ROTA = 'financeiro.propinas.index';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return list<array<string, mixed>> */
    private function linhas(TestResponse $resposta): array
    {
        return $resposta->viewData('page')['props']['propinas']['data'];
    }

    /** @return list<int> */
    private function ids(array $parametros = []): array
    {
        $ids = array_column($this->linhas($this->get(route(self::ROTA, $parametros))->assertOk()), 'id');
        sort($ids);

        return $ids;
    }

    private function matriculaDe(string $nome, Turma $turma): Matricula
    {
        return Matricula::create([
            'aluno_id' => $this->alunoFinanceiro($nome)->id,
            'turma_id' => $turma->id,
            'ano_lectivo_id' => $turma->ano_lectivo_id,
            'numero_registo_matricula' => 'MR-' . uniqid(),
            'data_matricula' => '2026-09-01',
            'estado' => EstadoMatriculaEnum::ACTIVA->value,
        ]);
    }

    public function test_lista_as_propinas_do_ano_activo_com_a_forma_esperada(): void
    {
        $estados = $this->cenarioDeEstados();

        $this->actingAs($this->adminEscola())
            ->get(route(self::ROTA))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Financeiro/Propinas/Index')
                ->has('propinas.data', 10)
                ->where('propinas.data.0.periodo', 'Set/2026')
                ->where('propinas.data.0.aluno_nome', 'Aluno de Teste')
                ->where('propinas.data.0.plano_nome', 'Propina Mensal')
                ->where('propinas.data.0.valor', 2_500_000)
                ->where('propinas.data.0.saldo', 2_500_000)
                ->where('propinas.data.0.estado', EstadoCobranca::EM_ATRASO->value)
                ->where('propinas.data.0.estado_descricao', 'Em Atraso')
                ->where('propinas.data.1.valor_pago', 1_000_000)
                ->where('propinas.data.1.estado', EstadoCobranca::EM_ATRASO->value)
                ->where('propinas.data.0.origem_geracao_descricao', 'Manual')
                ->missing('propinas.data.0.tenant_id')
                ->where('filtros.ano_lectivo_id', $estados['set_aberta']->ano_lectivo_id)
                ->has('estados', 7)
                ->has('origens', 4)
                ->has('planos', 1)
                ->where('moeda.codigo', 'AOA'));
    }

    public function test_o_filtro_de_estado_resolvido_coincide_com_o_php(): void
    {
        $this->cenarioDeEstados();
        $this->actingAs($this->adminEscola());
        $hoje = app(RelogioDoTenant::class)->hoje();

        foreach (EstadoCobranca::cases() as $estado) {
            $esperados = Propina::query()->get()
                ->filter(fn (Propina $p) => $p->estadoResolvido($hoje) === $estado)
                ->pluck('id')->sort()->values()->all();

            $this->assertSame($esperados, $this->ids(['estado' => $estado->value]), $estado->label());
        }
    }

    public function test_em_atraso_vira_a_meia_noite_da_escola(): void
    {
        // 15/09 23:30 UTC = 16/09 00:30 em Luanda: Setembro (limite 15/09) já está em atraso na escola.
        Carbon::setTestNow(CarbonImmutable::parse('2026-09-15 23:30:00', 'UTC'));
        $estados = $this->cenarioDeEstados();
        $this->actingAs($this->adminEscola());

        $this->assertContains($estados['set_aberta']->id, $this->ids(['estado' => EstadoCobranca::EM_ATRASO->value]));
    }

    public function test_filtros_de_plano_origem_vencimento_matricula_e_pesquisa(): void
    {
        ['ano' => $ano, 'turma' => $turma, 'plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $outroPlano = $this->plano($ano, 'Outro', ['mes_inicio' => 9, 'mes_fim' => 9]);
        $maria = $this->matriculaDe('Maria Kiala', $turma);
        $setembro = $this->propina($matricula, $plano, 1);
        $outubro = $this->propina($matricula, $plano, 2, ['origem_geracao' => OrigemGeracaoPropina::COMANDO]);
        $daMaria = $this->propina($maria, $outroPlano, 1);
        $this->actingAs($this->adminEscola());

        $this->assertSame([$daMaria->id], $this->ids(['plano_propina_id' => $outroPlano->id]));
        $this->assertSame([$outubro->id], $this->ids(['origem_geracao' => OrigemGeracaoPropina::COMANDO->value]));
        $this->assertSame([$outubro->id], $this->ids(['vencimento_de' => '2026-10-01', 'vencimento_ate' => '2026-10-31']));
        $this->assertSame([$daMaria->id], $this->ids(['matricula_id' => $maria->id]));
        $this->assertSame([$daMaria->id], $this->ids(['pesquisa' => 'kiala']));
        $this->assertSame([$daMaria->id], $this->ids(['pesquisa' => $maria->aluno->numero_matricula]));
        $this->assertSame([$setembro->id, $outubro->id], $this->ids(['pesquisa' => $matricula->numero_registo_matricula]));
        $this->assertSame([$setembro->id, $outubro->id, $daMaria->id], $this->ids(['vencimento_de' => 'lixo', 'estado' => 'abc', 'origem_geracao' => '9']));
    }

    public function test_o_ano_activo_e_o_filtro_por_omissao_e_todos_mostra_os_outros(): void
    {
        $antigo = $this->anoLectivo('2025/2026', '2025-09-01', '2026-07-31');
        $antigo->update(['estado' => EstadoAnoLectivo::ENCERRADO]);
        $turmaAntiga = $this->turma($antigo, $this->nivel('NA'));
        $planoAntigo = $this->plano($antigo, 'Propina 2025/2026');
        $deAntes = $this->propina($this->matriculaActiva($turmaAntiga, '2025-09-01'), $planoAntigo, 1);
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $actual = $this->propina($matricula, $plano, 1);
        $this->actingAs($this->adminEscola());

        $this->assertSame([$actual->id], $this->ids());
        $this->assertSame([$deAntes->id, $actual->id], $this->ids(['ano_lectivo_id' => 'todos']));
        $this->assertSame([$deAntes->id], $this->ids(['ano_lectivo_id' => $antigo->id]));
    }

    public function test_pagina_de_quinze_em_quinze(): void
    {
        ['turma' => $turma, 'plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $segunda = $this->matriculaActiva($turma);

        foreach ([$matricula, $segunda] as $m) {
            foreach (range(1, 10) as $ordem) {
                $this->propina($m, $plano, $ordem);
            }
        }

        $this->actingAs($this->adminEscola());

        $this->get(route(self::ROTA))->assertInertia(fn (Assert $page) => $page->has('propinas.data', 15)->where('propinas.total', 20));
        $this->get(route(self::ROTA, ['page' => 2]))->assertInertia(fn (Assert $page) => $page->has('propinas.data', 5));
    }

    public function test_sinaliza_a_geracao_tardia(): void
    {
        ['turma' => $turma] = $this->cenarioPropinas();
        // Hoje = 16/10/2026: Setembro e Outubro nascem depois do vencimento (dia 10); Novembro não.
        app(GerarPropinasService::class)->gerar(new PedidoGeracaoPropinasDTO(
            ano_lectivo_id: (int) $turma->ano_lectivo_id,
            ambito: AmbitoGeracaoPropinas::TURMA,
            origem: OrigemGeracaoPropina::MANUAL,
            alvo_id: (int) $turma->id,
        ));

        $linhas = $this->linhas($this->actingAs($this->adminEscola())->get(route(self::ROTA))->assertOk());
        $tardias = array_column($linhas, 'geracao_tardia', 'periodo');

        $this->assertTrue($tardias['Set/2026']);
        $this->assertTrue($tardias['Out/2026']);
        $this->assertFalse($tardias['Nov/2026']);
    }

    public function test_propinas_de_outro_tenant_nao_aparecem(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $nossa = $this->propina($matricula, $plano, 1);
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, function () {
            ['plano' => $p, 'matricula' => $m] = $this->cenarioPropinas();
            $this->propina($m, $p, 1);
        });
        $this->actingAs($this->adminEscola());

        $this->assertSame([$nossa->id], $this->ids(['ano_lectivo_id' => 'todos']));
    }

    public function test_sem_propina_ver_da_403(): void
    {
        $this->actingAs($this->professor())->get(route(self::ROTA))->assertForbidden();
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/PropinaListaTest.php`
Expected: FAIL (`Route [financeiro.propinas.index] not defined.`).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Services/PropinaConsultaService.php`:
```php
<?php

namespace Modules\Financeiro\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Support\RotuloDePeriodo;

/**
 * Leitura das propinas para a lista (spec §10). O estado mostrado e filtrado é o resolvido para o "hoje"
 * da escola (§4, §8.2b): o filtro usa o scope SQL de F1, a coluna usa EstadoCobranca::resolver.
 */
class PropinaConsultaService
{
    public const POR_PAGINA = 15;

    public function __construct(private RelogioDoTenant $relogio)
    {
    }

    public function listar(array $filtros = []): LengthAwarePaginator
    {
        $hoje = $this->relogio->hoje();
        $fuso = $this->relogio->fuso();
        $estado = is_numeric($filtros['estado'] ?? null) ? EstadoCobranca::tryFrom((int) $filtros['estado']) : null;
        $origem = is_numeric($filtros['origem_geracao'] ?? null) ? OrigemGeracaoPropina::tryFrom((int) $filtros['origem_geracao']) : null;
        $pesquisa = is_string($filtros['pesquisa'] ?? null) ? trim($filtros['pesquisa']) : '';

        return Propina::query()
            ->with(['matricula.aluno.dadosPessoa', 'matricula.turma', 'plano'])
            ->when(is_numeric($filtros['ano_lectivo_id'] ?? null), fn ($query) => $query->where('ano_lectivo_id', (int) $filtros['ano_lectivo_id']))
            ->when($estado !== null, fn ($query) => $query->comEstadoResolvido($estado, $hoje))
            ->when(is_numeric($filtros['plano_propina_id'] ?? null), fn ($query) => $query->where('plano_propina_id', (int) $filtros['plano_propina_id']))
            ->when(is_numeric($filtros['matricula_id'] ?? null), fn ($query) => $query->where('matricula_id', (int) $filtros['matricula_id']))
            ->when($origem !== null, fn ($query) => $query->where('origem_geracao', $origem->value))
            ->when($this->data($filtros['vencimento_de'] ?? null), fn ($query, string $dia) => $query->where('data_vencimento', '>=', $dia))
            ->when($this->data($filtros['vencimento_ate'] ?? null), fn ($query, string $dia) => $query->where('data_vencimento', '<=', $dia))
            ->when($pesquisa !== '', fn ($query) => $query->whereHas('matricula', fn ($matricula) => $matricula->where(fn ($grupo) => $grupo
                ->whereContem('numero_registo_matricula', $pesquisa)
                ->orWhereHas('aluno', fn ($aluno) => $aluno->where(fn ($subgrupo) => $subgrupo
                    ->whereContem('numero_matricula', $pesquisa)
                    ->orWhereHas('dadosPessoa', fn ($pessoa) => $pessoa->whereContem('nome_completo', $pesquisa)))))))
            ->orderBy('data_vencimento')
            ->orderBy('id')
            ->paginate(self::POR_PAGINA)
            ->withQueryString()
            ->through(fn (Propina $propina) => $this->linha($propina, $hoje, $fuso));
    }

    /**
     * @return Collection<int, PlanoPropina>
     */
    public function planos(): Collection
    {
        return PlanoPropina::query()->orderBy('nome')->get(['id', 'nome', 'ano_lectivo_id', 'estado']);
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public function estados(): array
    {
        return array_map(fn (EstadoCobranca $e) => ['value' => $e->value, 'label' => $e->label()], EstadoCobranca::cases());
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public function origens(): array
    {
        return array_map(fn (OrigemGeracaoPropina $o) => ['value' => $o->value, 'label' => $o->label()], OrigemGeracaoPropina::cases());
    }

    /**
     * @return array<string, mixed>
     */
    private function linha(Propina $propina, CarbonImmutable $hoje, string $fuso): array
    {
        $estado = $propina->estadoResolvido($hoje);
        $inicio = $propina->periodo_inicio->toDateString();
        $fim = $propina->periodo_fim->toDateString();
        $vencimento = $propina->data_vencimento->toDateString();
        $criadaNaEscola = $propina->created_at?->copy()->setTimezone($fuso)->toDateString();

        return [
            'id' => $propina->id,
            'matricula_id' => $propina->matricula_id,
            'matricula_numero' => $propina->matricula?->numero_registo_matricula,
            'aluno_nome' => $propina->matricula?->aluno?->dadosPessoa?->nome_completo,
            'aluno_numero' => $propina->matricula?->aluno?->numero_matricula,
            'turma_nome' => $propina->matricula?->turma?->nome,
            'plano_propina_id' => $propina->plano_propina_id,
            'plano_nome' => $propina->plano?->nome,
            'ordem' => $propina->ordem,
            'periodo_inicio' => $inicio,
            'periodo_fim' => $fim,
            'periodo' => RotuloDePeriodo::periodo($inicio, $fim),
            'data_vencimento' => $vencimento,
            'data_limite' => $propina->data_limite->toDateString(),
            'valor' => $propina->valor->unidadesMenores(),
            'valor_pago' => $propina->valor_pago->unidadesMenores(),
            'saldo' => $propina->saldo()->unidadesMenores(),
            'estado' => $estado->value,
            'estado_descricao' => $estado->label(),
            'origem_geracao' => $propina->origem_geracao->value,
            'origem_geracao_descricao' => $propina->origem_geracao_descricao,
            'motivo_geracao' => $propina->motivo_geracao,
            // §8.1/Q4: retroactiva, ou criada (dia da escola) depois do vencimento.
            'geracao_tardia' => $propina->origem_geracao === OrigemGeracaoPropina::RETROACTIVA
                || ($criadaNaEscola !== null && $criadaNaEscola > $vencimento),
        ];
    }

    private function data(mixed $valor): ?string
    {
        if (! is_string($valor) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $valor, $partes) !== 1) {
            return null;
        }

        return checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1]) ? $valor : null;
    }
}
```

`Modules/Financeiro/app/Http/Controllers/PropinaController.php`:
```php
<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Services\PlanoPropinaConsultaService;
use Modules\Financeiro\Services\PropinaConsultaService;

class PropinaController extends Controller
{
    public function __construct(
        private PropinaConsultaService $consulta,
        private PlanoPropinaConsultaService $planos,
        private MoedaDoTenant $moedaDoTenant,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('propina.ver');

        $filtros = $request->only([
            'ano_lectivo_id', 'estado', 'pesquisa', 'plano_propina_id', 'matricula_id', 'origem_geracao', 'vencimento_de', 'vencimento_ate',
        ]);

        if (! $request->has('ano_lectivo_id')) {
            $filtros['ano_lectivo_id'] = $this->planos->anoLectivoActualId();
        }

        return Inertia::render('Financeiro/Propinas/Index', [
            'propinas' => $this->consulta->listar($filtros),
            'filtros' => $filtros,
            'anosLectivos' => $this->planos->anosLectivos(),
            'planos' => $this->consulta->planos(),
            'turmas' => $this->planos->turmas(),
            'niveis' => $this->planos->niveis(),
            'estados' => $this->consulta->estados(),
            'origens' => $this->consulta->origens(),
            'moeda' => $this->moedaDoTenant->atual()->paraFrontend(),
        ]);
    }
}
```

Em `Modules/Financeiro/routes/web.php`, acrescentar o `use`:
```php
use Modules\Financeiro\Http\Controllers\PropinaController;
```
e, no fim do ficheiro, um grupo **separado** da configuração:
```php
// Operação (menu Financeiro), separada da configuração (menu Configurações → Financeiro).
Route::middleware(['auth'])->prefix('financeiro/propinas')->name('financeiro.propinas.')->group(function () {
    Route::get('/', [PropinaController::class, 'index'])->middleware('can:propina.ver')->name('index');
});
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Feature/PropinaListaTest.php`
Expected: PASS (9 testes).

Run: `php artisan test tests/Feature/RotasPermissaoReconhecidaTest.php tests/Feature/RotasEscritaAutorizadasTest.php`
Expected: PASS.

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Services/PropinaConsultaService.php Modules/Financeiro/app/Http/Controllers/PropinaController.php \
  Modules/Financeiro/routes/web.php Modules/Financeiro/tests/Feature/PropinaListaTest.php
```

---

### Task 8: Geração manual por HTTP (pré-visualizar JSON + confirmar), permissões e flash

**Files:**
- Create: `Modules/Financeiro/app/Http/Requests/Concerns/ValidaGeracaoPropinas.php`
- Create: `Modules/Financeiro/app/Http/Requests/PreVisualizarGeracaoPropinasRequest.php`
- Create: `Modules/Financeiro/app/Http/Requests/GerarPropinasRequest.php`
- Create: `Modules/Financeiro/app/Actions/GerarPropinasAction.php`
- Modify: `Modules/Financeiro/app/Http/Controllers/PropinaController.php`
- Modify: `Modules/Financeiro/routes/web.php`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Modify: `Modules/Financeiro/app/Services/GerarPropinasService.php` (P5: limite de 500 matrículas por pedido manual)
- Modify: `Modules/Permissao/app/Actions/SincronizarPerfisDeSistemaAction.php` (P1: `propina.ajustar` ao ADMIN_ESCOLA)
- Test: `Modules/Financeiro/tests/Feature/PropinaGeracaoHttpTest.php`
- Test (modificar): `Modules/Financeiro/tests/Feature/PermissoesPropinaTest.php` (P1)

**Interfaces:**
- Consumes: T3 (`PedidoGeracaoPropinasDTO::fromRequest`), T5/T6 (`preVisualizar`, `gerar`), `BaseRequest` (JSON 422 com `expectsJson`), `TenantContext::id()`.
- Produces:
  - `POST /financeiro/propinas/geracao/pre-visualizar` → `financeiro.propinas.geracao.pre-visualizar` (`can:propina.criar`) → `200 {"resultado": ResultadoGeracaoPropinasDTO::toArray()}`; nunca escreve.
  - `POST /financeiro/propinas/geracao` → `financeiro.propinas.geracao.store` (`can:propina.criar`) → `redirect()->back()` com `success` e `geracao_propinas` (o `toArray()` do resultado).
  - Regras: `ano_lectivo_id` (tenant, sem `deleted_at`); `ambito ∈ {matricula, turma, nivel, plano}`; `matricula_numero` (requerido em `matricula`; existe no tenant e no ano); `turma_id` (requerido em `turma`; tenant + ano); `nivel_academico_id` (requerido em `nivel`; tenant); `plano_propina_id` (requerido em `plano`; opcional nos outros; tenant + ano + **activo**); `incluir_periodos_anteriores` (boolean); `motivo_geracao` (requerido com o anterior, ≤ 500). `authorize()`: `propina.criar` e, com `incluir_periodos_anteriores`, também `propina.ajustar` (senão 403).
  - `GerarPropinasAction::executar(PedidoGeracaoPropinasDTO): ResultadoGeracaoPropinasDTO`.
  - Flash partilhado `flash.geracao_propinas`.
  - **P5:** `GerarPropinasService::LIMITE_MATRICULAS_POR_PEDIDO = 500`; `preVisualizar` e `gerar` com âmbito manual acima do limite lançam `ValidationException` no campo `ambito` ("… Restrinja por turma ou por nível académico.") antes de calcular ou gravar o que quer que seja (JSON 422 na pré-visualização; `back()->withErrors` na confirmação). O comando (`TODAS`) não tem limite.
  - **P1:** ADMIN_ESCOLA com `propina.{ver, listar, criar, cancelar, exportar, ajustar}`; os tenants existentes recebem `ajustar` com `financeiro:sincronizar` (já chama `SincronizarPerfisDeSistemaAction::executar()`, idempotente, invalida a cache de permissões).

- [ ] **Step 1: Escrever o teste que falha**

`Modules/Financeiro/tests/Feature/PropinaGeracaoHttpTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Modules\Core\Enums\Estado;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\GerarPropinasService;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Modulo;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo as ModuloRegistro;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class PropinaGeracaoHttpTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    private const PREVIA = 'financeiro.propinas.geracao.pre-visualizar';

    private const GERAR = 'financeiro.propinas.geracao.store';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
        Carbon::setTestNow(CarbonImmutable::parse('2026-09-01 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function porTurma(Turma $turma, array $extras = []): array
    {
        return array_merge(['ano_lectivo_id' => $turma->ano_lectivo_id, 'ambito' => 'turma', 'turma_id' => $turma->id], $extras);
    }

    /** Perfil personalizado só com `propina.ver` e `propina.criar` (sem `ajustar`, que o ADMIN_ESCOLA tem desde F2, P1). */
    private function tesoureiroSemAjustar(): User
    {
        $role = Role::create(['nome' => Role::PERFIL_PERSONALIZADO, 'descricao' => 'Propinas (ver e criar)']);
        $registo = ModuloRegistro::where('nome', Modulo::PROPINA->value)->firstOrFail();

        foreach (Acao::query()->whereIn('nome', ['ver', 'criar'])->get() as $acao) {
            RolePermissao::create(['role_id' => $role->id, 'modulo_id' => $registo->id, 'acao_id' => $acao->id]);
        }

        $user = User::create(['name' => 'Tesouraria', 'email' => 'tesouraria@example.com', 'password' => Hash::make('x')]);
        $user->roles()->attach($role->id);

        return $user;
    }

    public function test_pre_visualizar_devolve_o_resultado_e_nao_grava(): void
    {
        ['turma' => $turma] = $this->cenarioPropinas();

        $this->actingAs($this->adminEscola())
            ->postJson(route(self::PREVIA), $this->porTurma($turma))
            ->assertOk()
            ->assertJsonPath('resultado.previsualizacao', true)
            ->assertJsonPath('resultado.totais.criadas', 10)
            ->assertJsonPath('resultado.matriculas.0.criadas.0.periodo', 'Set/2026')
            ->assertJsonPath('resultado.detalhe_truncado', false);

        $this->assertSame(0, Propina::query()->count());
    }

    public function test_gerar_grava_e_devolve_o_resumo_em_flash(): void
    {
        ['turma' => $turma] = $this->cenarioPropinas();
        $this->actingAs($this->adminEscola());

        $this->from(route('financeiro.propinas.index'))
            ->post(route(self::GERAR), $this->porTurma($turma))
            ->assertRedirect(route('financeiro.propinas.index'))
            ->assertSessionHas('success', '10 propina(s) gerada(s), 0 período(s) ignorado(s).')
            ->assertSessionHas('geracao_propinas', fn (array $r) => $r['previsualizacao'] === false && $r['totais']['criadas'] === 10);

        $this->assertSame(10, Propina::query()->count());

        $this->post(route(self::GERAR), $this->porTurma($turma))
            ->assertSessionHas('geracao_propinas', fn (array $r) => $r['totais']['criadas'] === 0 && $r['totais']['ignoradas'] === 10);
        $this->assertSame(10, Propina::query()->count());
    }

    public function test_ambitos_matricula_nivel_e_plano(): void
    {
        ['ano' => $ano, 'turma' => $turma, 'plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->actingAs($this->adminEscola());

        $this->postJson(route(self::PREVIA), ['ano_lectivo_id' => $ano->id, 'ambito' => 'matricula', 'matricula_numero' => $matricula->numero_registo_matricula])
            ->assertOk()->assertJsonPath('resultado.totais.matriculas', 1);
        $this->postJson(route(self::PREVIA), ['ano_lectivo_id' => $ano->id, 'ambito' => 'nivel', 'nivel_academico_id' => $turma->nivel_academico_id])
            ->assertOk()->assertJsonPath('resultado.totais.criadas', 10);
        $this->postJson(route(self::PREVIA), ['ano_lectivo_id' => $ano->id, 'ambito' => 'plano', 'plano_propina_id' => $plano->id])
            ->assertOk()->assertJsonPath('resultado.totais.criadas', 10);
    }

    public function test_sem_propina_criar_da_403_e_nada_e_gravado(): void
    {
        ['turma' => $turma] = $this->cenarioPropinas();
        $this->actingAs($this->professor());

        $this->postJson(route(self::PREVIA), $this->porTurma($turma))->assertForbidden();
        $this->post(route(self::GERAR), $this->porTurma($turma))->assertForbidden();

        $this->assertSame(0, Propina::query()->count());
    }

    public function test_periodos_anteriores_exigem_propina_ajustar(): void
    {
        ['turma' => $turma] = $this->cenarioPropinas();
        $tardia = $this->matriculaActiva($turma, '2026-11-15');
        $pedido = [
            'ano_lectivo_id' => $turma->ano_lectivo_id,
            'ambito' => 'matricula',
            'matricula_numero' => $tardia->numero_registo_matricula,
            'incluir_periodos_anteriores' => true,
            'motivo_geracao' => 'Regularização de Setembro e Outubro.',
        ];

        $this->actingAs($this->tesoureiroSemAjustar())->postJson(route(self::PREVIA), $pedido)->assertForbidden();
        $this->post(route(self::GERAR), $pedido)->assertForbidden();
        $this->assertSame(0, Propina::query()->count());

        // P1: o ADMIN_ESCOLA tem `propina.ajustar` desde F2.
        $this->actingAs($this->adminEscola())
            ->postJson(route(self::PREVIA), $pedido)
            ->assertOk()
            ->assertJsonPath('resultado.totais.criadas', 10)
            ->assertJsonPath('resultado.matriculas.0.criadas.0.origem_geracao', 4)
            ->assertJsonPath('resultado.matriculas.0.criadas.0.motivo_geracao', 'Regularização de Setembro e Outubro.');
        $this->assertSame(0, Propina::query()->count(), 'A pré-visualização não grava.');

        $this->post(route(self::GERAR), $pedido)->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(
            ['2026-09-01' => OrigemGeracaoPropina::RETROACTIVA, '2026-10-01' => OrigemGeracaoPropina::RETROACTIVA, '2026-11-01' => OrigemGeracaoPropina::MANUAL],
            Propina::query()->where('matricula_id', $tardia->id)->whereIn('periodo_inicio', ['2026-09-01', '2026-10-01', '2026-11-01'])
                ->orderBy('periodo_inicio')->get()->mapWithKeys(fn (Propina $p) => [$p->periodo_inicio->toDateString() => $p->origem_geracao])->all(),
        );
        $this->assertSame(
            ['Regularização de Setembro e Outubro.'],
            Propina::query()->where('origem_geracao', OrigemGeracaoPropina::RETROACTIVA->value)->pluck('motivo_geracao')->unique()->values()->all(),
        );
        $this->assertSame(8, Propina::query()->where('origem_geracao', OrigemGeracaoPropina::MANUAL->value)->whereNull('motivo_geracao')->count());
    }

    public function test_acima_de_500_matriculas_pede_para_restringir_o_ambito_e_nada_e_calculado_nem_gravado(): void
    {
        // P5: a geração manual trata no máximo GerarPropinasService::LIMITE_MATRICULAS_POR_PEDIDO matrículas por pedido.
        ['ano' => $ano, 'turma' => $turma] = $this->cenarioPropinas();

        for ($i = 1; $i < GerarPropinasService::LIMITE_MATRICULAS_POR_PEDIDO; $i++) {
            $this->matriculaActiva($turma);
        }

        $this->actingAs($this->adminEscola());
        $this->postJson(route(self::PREVIA), $this->porTurma($turma))
            ->assertOk()
            ->assertJsonPath('resultado.totais.matriculas', 500);

        $this->matriculaActiva($turma);
        $mensagem = 'O âmbito escolhido tem 501 matrículas; a geração manual trata no máximo 500 por pedido. Restrinja por turma ou por nível académico.';

        $this->postJson(route(self::PREVIA), $this->porTurma($turma))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ambito' => $mensagem]);
        $this->postJson(route(self::PREVIA), ['ano_lectivo_id' => $ano->id, 'ambito' => 'nivel', 'nivel_academico_id' => $turma->nivel_academico_id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ambito');
        $this->from(route('financeiro.propinas.index'))
            ->post(route(self::GERAR), $this->porTurma($turma))
            ->assertRedirect(route('financeiro.propinas.index'))
            ->assertSessionHasErrors(['ambito' => $mensagem])
            ->assertSessionMissing('geracao_propinas');

        $this->assertSame(0, Propina::query()->count());
    }

    public function test_motivo_obrigatorio_com_periodos_anteriores(): void
    {
        ['turma' => $turma] = $this->cenarioPropinas();

        $this->actingAs($this->adminEscola())
            ->postJson(route(self::PREVIA), $this->porTurma($turma, ['incluir_periodos_anteriores' => true, 'motivo_geracao' => '   ']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('motivo_geracao');
    }

    public function test_ids_de_outro_tenant_de_outro_ano_ou_inactivos_dao_422(): void
    {
        ['ano' => $ano, 'turma' => $turma, 'plano' => $plano] = $this->cenarioPropinas();
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $turmaDoOutro = $this->noTenant($outro, fn () => $this->cenarioPropinas()['turma']);
        $outroAno = $this->anoLectivo('2027/2028', '2027-09-01', '2028-07-31');
        $planoDoOutroAno = $this->plano($outroAno, 'Futuro');
        $inactivo = $this->plano($ano, 'Inactivo', ['estado' => Estado::INATIVO->value]);
        $this->actingAs($this->adminEscola());

        $this->postJson(route(self::PREVIA), ['ano_lectivo_id' => $ano->id, 'ambito' => 'turma', 'turma_id' => $turmaDoOutro->id])
            ->assertStatus(422)->assertJsonValidationErrors('turma_id');
        $this->postJson(route(self::PREVIA), ['ano_lectivo_id' => $turmaDoOutro->ano_lectivo_id, 'ambito' => 'turma', 'turma_id' => $turma->id])
            ->assertStatus(422)->assertJsonValidationErrors(['ano_lectivo_id', 'turma_id']);
        $this->postJson(route(self::PREVIA), $this->porTurma($turma, ['plano_propina_id' => $planoDoOutroAno->id]))
            ->assertStatus(422)->assertJsonValidationErrors('plano_propina_id');
        $this->postJson(route(self::PREVIA), ['ano_lectivo_id' => $ano->id, 'ambito' => 'plano', 'plano_propina_id' => $inactivo->id])
            ->assertStatus(422)->assertJsonValidationErrors('plano_propina_id');
        $this->postJson(route(self::PREVIA), ['ano_lectivo_id' => $ano->id, 'ambito' => 'matricula', 'matricula_numero' => 'NAO-EXISTE'])
            ->assertStatus(422)->assertJsonValidationErrors('matricula_numero');
        $this->postJson(route(self::PREVIA), ['ano_lectivo_id' => $ano->id, 'ambito' => 'todas'])
            ->assertStatus(422)->assertJsonValidationErrors('ambito');
        $this->postJson(route(self::PREVIA), ['ano_lectivo_id' => $ano->id, 'ambito' => 'plano'])
            ->assertStatus(422)->assertJsonValidationErrors('plano_propina_id');
    }
}
```

**P1 — `Modules/Financeiro/tests/Feature/PermissoesPropinaTest.php`** (modificar): substituir `test_admin_escola_tem_so_as_acoes_de_f1` e a última asserção de `test_sincronizar_concede_as_permissoes_a_tenants_existentes_e_invalida_a_cache`, e acrescentar o teste do *backfill* de `ajustar` para tenants de F1:
```php
    public function test_admin_escola_tem_as_acoes_de_f1_e_ajustar(): void
    {
        $admin = $this->utilizadorCom(Perfil::ADMIN_ESCOLA);

        // P1 (F2): `ajustar` (geração retroactiva) entra já; `anular` continua para Pagamentos.
        foreach (['ver', 'listar', 'criar', 'cancelar', 'exportar', 'ajustar'] as $acao) {
            $this->assertTrue(Gate::forUser($admin)->allows("propina.{$acao}"), $acao);
        }

        foreach (['anular', 'editar', 'eliminar', 'negociar', 'isentar-multa'] as $acao) {
            $this->assertFalse(Gate::forUser($admin)->allows("propina.{$acao}"), $acao);
        }
    }
```
Em `test_sincronizar_concede_as_permissoes_a_tenants_existentes_e_invalida_a_cache`, a última linha passa a:
```php
        $this->assertTrue(Gate::forUser($admin)->allows('propina.ajustar'));
```
E, no fim da classe (acrescentar `use Modules\Permissao\Models\Acao;` aos `use`):
```php
    public function test_sincronizar_concede_ajustar_a_tenants_criados_em_f1(): void
    {
        $admin = $this->utilizadorCom(Perfil::ADMIN_ESCOLA);
        // Simula um tenant de F1: tem as acções de F1, mas não `ajustar`.
        RolePermissao::query()
            ->where('role_id', Role::where('nome', Perfil::ADMIN_ESCOLA->value)->value('id'))
            ->where('modulo_id', ModuloRegistro::where('nome', Modulo::PROPINA->value)->value('id'))
            ->where('acao_id', Acao::where('nome', 'ajustar')->value('id'))
            ->delete();
        $this->assertTrue(Gate::forUser($admin)->denies('propina.ajustar')); // aquece a cache
        $this->assertTrue(Gate::forUser($admin)->allows('propina.criar'));

        $this->artisan('financeiro:sincronizar', ['--tenant' => $this->tenant->codigo])->assertSuccessful();

        $this->assertTrue(Gate::forUser($admin)->allows('propina.ajustar'));
    }
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/PropinaGeracaoHttpTest.php`
Expected: FAIL (`Route [financeiro.propinas.geracao.pre-visualizar] not defined.`).

Run: `php artisan test Modules/Financeiro/tests/Feature/PermissoesPropinaTest.php`
Expected: FAIL (3 testes: o ADMIN_ESCOLA ainda não tem `propina.ajustar`).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Http/Requests/Concerns/ValidaGeracaoPropinas.php`:
```php
<?php

namespace Modules\Financeiro\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Modules\Core\Enums\Estado;
use Modules\Core\Tenancy\TenantContext;
use Modules\Financeiro\Enums\AmbitoGeracaoPropinas;

/**
 * Regras partilhadas pela pré-visualização e pela confirmação da geração manual (a confirmação valida
 * tudo de novo: nunca confia no que a pré-visualização mostrou).
 */
trait ValidaGeracaoPropinas
{
    public function authorize(): bool
    {
        $utilizador = $this->user();

        if ($utilizador === null || ! $utilizador->can('propina.criar')) {
            return false;
        }

        // Períodos anteriores ao mês da matrícula são excepcionais (spec §7.2, Q9): exigem `propina.ajustar`.
        return ! $this->boolean('incluir_periodos_anteriores') || $utilizador->can('propina.ajustar');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();
        $anoId = (int) $this->input('ano_lectivo_id');
        $ambito = $this->input('ambito');
        $doTenant = fn (string $tabela) => Rule::exists($tabela, 'id')
            ->where(fn ($query) => $query->where('tenant_id', $tenantId)->whereNull('deleted_at'));

        return [
            'ano_lectivo_id' => ['required', 'integer', $doTenant('ano_lectivos')],
            'ambito' => ['required', 'string', Rule::in(array_map(fn (AmbitoGeracaoPropinas $a) => $a->value, AmbitoGeracaoPropinas::manuais()))],
            'matricula_numero' => [
                Rule::requiredIf($ambito === AmbitoGeracaoPropinas::MATRICULA->value), 'nullable', 'string', 'max:50',
                Rule::exists('matriculas', 'numero_registo_matricula')
                    ->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('ano_lectivo_id', $anoId)->whereNull('deleted_at')),
            ],
            'turma_id' => [
                Rule::requiredIf($ambito === AmbitoGeracaoPropinas::TURMA->value), 'nullable', 'integer',
                Rule::exists('turmas', 'id')
                    ->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('ano_lectivo_id', $anoId)->whereNull('deleted_at')),
            ],
            'nivel_academico_id' => [
                Rule::requiredIf($ambito === AmbitoGeracaoPropinas::NIVEL->value), 'nullable', 'integer', $doTenant('niveis_academicos'),
            ],
            'plano_propina_id' => [
                Rule::requiredIf($ambito === AmbitoGeracaoPropinas::PLANO->value), 'nullable', 'integer',
                Rule::exists('planos_propina', 'id')
                    ->where(fn ($query) => $query->where('tenant_id', $tenantId)->where('ano_lectivo_id', $anoId)->where('estado', Estado::ATIVO->value)),
            ],
            'incluir_periodos_anteriores' => ['sometimes', 'boolean'],
            'motivo_geracao' => [Rule::requiredIf(fn () => $this->boolean('incluir_periodos_anteriores')), 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ano_lectivo_id.required' => 'O ano lectivo é obrigatório.',
            'ano_lectivo_id.exists' => 'O ano lectivo escolhido é inválido.',
            'ambito.required' => 'Escolha para quem gerar as propinas.',
            'ambito.in' => 'O âmbito escolhido é inválido.',
            'matricula_numero.required' => 'Indique o número de registo da matrícula.',
            'matricula_numero.exists' => 'Não há nenhuma matrícula com esse número neste ano lectivo.',
            'turma_id.required' => 'Escolha a turma.',
            'turma_id.exists' => 'A turma escolhida é inválida para este ano lectivo.',
            'nivel_academico_id.required' => 'Escolha o nível académico.',
            'nivel_academico_id.exists' => 'O nível académico escolhido é inválido.',
            'plano_propina_id.required' => 'Escolha o plano de propina.',
            'plano_propina_id.exists' => 'O plano escolhido é inválido, inactivo ou de outro ano lectivo.',
            'motivo_geracao.required' => 'Indique o motivo para gerar períodos anteriores à matrícula.',
            'motivo_geracao.max' => 'O motivo não pode ter mais de 500 caracteres.',
        ];
    }
}
```

`Modules/Financeiro/app/Http/Requests/PreVisualizarGeracaoPropinasRequest.php`:
```php
<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Modules\Financeiro\Http\Requests\Concerns\ValidaGeracaoPropinas;

class PreVisualizarGeracaoPropinasRequest extends BaseRequest
{
    use ValidaGeracaoPropinas;
}
```

`Modules/Financeiro/app/Http/Requests/GerarPropinasRequest.php`:
```php
<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Modules\Financeiro\Http\Requests\Concerns\ValidaGeracaoPropinas;

class GerarPropinasRequest extends BaseRequest
{
    use ValidaGeracaoPropinas;
}
```

`Modules/Financeiro/app/Actions/GerarPropinasAction.php`:
```php
<?php

namespace Modules\Financeiro\Actions;

use Modules\Financeiro\DTO\PedidoGeracaoPropinasDTO;
use Modules\Financeiro\DTO\ResultadoGeracaoPropinasDTO;
use Modules\Financeiro\Services\GerarPropinasService;

/**
 * Geração manual confirmada: recalcula tudo sobre o estado actual, matrícula a matrícula, numa
 * transacção por matrícula (a pré-visualização é só informativa e nunca é reutilizada, D4).
 */
class GerarPropinasAction
{
    public function __construct(private GerarPropinasService $geracao)
    {
    }

    public function executar(PedidoGeracaoPropinasDTO $pedido): ResultadoGeracaoPropinasDTO
    {
        return $this->geracao->gerar($pedido);
    }
}
```

**P5 — `Modules/Financeiro/app/Services/GerarPropinasService.php`:** acrescentar aos `use`
```php
use Illuminate\Validation\ValidationException;
```
a constante, logo a seguir a `class GerarPropinasService {`:
```php
    /** P5: máximo de matrículas por pedido de geração manual (pré-visualização ou confirmação). O comando não tem limite. */
    public const LIMITE_MATRICULAS_POR_PEDIDO = 500;
```
o método, a seguir a `idsDoAmbito()`:
```php
    /**
     * As matrículas do âmbito, recusando (antes de calcular ou gravar) um pedido manual grande demais para
     * um pedido HTTP (R-F2.4). Em `ambito` para o formulário mostrar a mensagem junto de "Para quem".
     *
     * @return list<int>
     */
    private function idsDoPedido(PedidoGeracaoPropinasDTO $pedido, ContextoDeGeracao $contexto): array
    {
        $ids = $this->idsDoAmbito($pedido, $contexto);

        if ($pedido->ambito !== AmbitoGeracaoPropinas::TODAS && count($ids) > self::LIMITE_MATRICULAS_POR_PEDIDO) {
            throw ValidationException::withMessages([
                'ambito' => 'O âmbito escolhido tem ' . count($ids) . ' matrículas; a geração manual trata no máximo '
                    . self::LIMITE_MATRICULAS_POR_PEDIDO . ' por pedido. Restrinja por turma ou por nível académico.',
            ]);
        }

        return $ids;
    }
```
e, em `preVisualizar()` e em `gerar()`, trocar `foreach ($this->idsDoAmbito($pedido, $contexto) as $id)` por `foreach ($this->idsDoPedido($pedido, $contexto) as $id)`. Na confirmação a excepção sai antes da primeira transacção (nada gravado) e o Laravel converte-a em `back()->withErrors(['ambito' => …])`; na pré-visualização (JSON) em 422.

**P1 — `Modules/Permissao/app/Actions/SincronizarPerfisDeSistemaAction.php`:** na entrada do ADMIN_ESCOLA, trocar
```php
            // F1 de Propinas; `ajustar` entra com a alteração de preço (F4), `anular` com Pagamentos.
            Modulo::PROPINA->value => ['ver', 'listar', 'criar', 'cancelar', 'exportar'],
```
por
```php
            // F1 de Propinas + `ajustar` (geração retroactiva, F2, P1; também a alteração de preço em F4). `anular` entra com Pagamentos.
            Modulo::PROPINA->value => ['ver', 'listar', 'criar', 'cancelar', 'exportar', 'ajustar'],
```
Os tenants novos recebem-no pelo provisionador; os existentes com `financeiro:sincronizar --tenant=…|--todos` (do dono).

`Modules/Financeiro/app/Http/Controllers/PropinaController.php` (ficheiro completo):
```php
<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Financeiro\Actions\GerarPropinasAction;
use Modules\Financeiro\DTO\PedidoGeracaoPropinasDTO;
use Modules\Financeiro\Http\Requests\GerarPropinasRequest;
use Modules\Financeiro\Http\Requests\PreVisualizarGeracaoPropinasRequest;
use Modules\Financeiro\Services\GerarPropinasService;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Services\PlanoPropinaConsultaService;
use Modules\Financeiro\Services\PropinaConsultaService;

class PropinaController extends Controller
{
    public function __construct(
        private PropinaConsultaService $consulta,
        private PlanoPropinaConsultaService $planos,
        private GerarPropinasService $geracao,
        private GerarPropinasAction $gerarAction,
        private MoedaDoTenant $moedaDoTenant,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('propina.ver');

        $filtros = $request->only([
            'ano_lectivo_id', 'estado', 'pesquisa', 'plano_propina_id', 'matricula_id', 'origem_geracao', 'vencimento_de', 'vencimento_ate',
        ]);

        if (! $request->has('ano_lectivo_id')) {
            $filtros['ano_lectivo_id'] = $this->planos->anoLectivoActualId();
        }

        return Inertia::render('Financeiro/Propinas/Index', [
            'propinas' => $this->consulta->listar($filtros),
            'filtros' => $filtros,
            'anosLectivos' => $this->planos->anosLectivos(),
            'planos' => $this->consulta->planos(),
            'turmas' => $this->planos->turmas(),
            'niveis' => $this->planos->niveis(),
            'estados' => $this->consulta->estados(),
            'origens' => $this->consulta->origens(),
            'moeda' => $this->moedaDoTenant->atual()->paraFrontend(),
        ]);
    }

    public function preVisualizarGeracao(PreVisualizarGeracaoPropinasRequest $request): JsonResponse
    {
        $this->authorize('propina.criar');

        return response()->json([
            'resultado' => $this->geracao->preVisualizar(PedidoGeracaoPropinasDTO::fromRequest($request))->toArray(),
        ]);
    }

    public function gerar(GerarPropinasRequest $request)
    {
        $this->authorize('propina.criar');

        $resultado = $this->gerarAction->executar(PedidoGeracaoPropinasDTO::fromRequest($request));
        $totais = $resultado->totais();
        $mensagem = "{$totais['criadas']} propina(s) gerada(s), {$totais['ignoradas']} período(s) ignorado(s).";

        if ($totais['conflitos'] + $totais['erros'] > 0) {
            $mensagem .= ' Há conflitos ou erros: veja o detalhe.';
        }

        return redirect()->back()
            ->with('success', $mensagem)
            ->with('geracao_propinas', $resultado->toArray());
    }
}
```

Em `Modules/Financeiro/routes/web.php`, o grupo de operação passa a:
```php
// Operação (menu Financeiro), separada da configuração (menu Configurações → Financeiro).
Route::middleware(['auth'])->prefix('financeiro/propinas')->name('financeiro.propinas.')->group(function () {
    Route::get('/', [PropinaController::class, 'index'])->middleware('can:propina.ver')->name('index');
    Route::post('/geracao/pre-visualizar', [PropinaController::class, 'preVisualizarGeracao'])->middleware('can:propina.criar')->name('geracao.pre-visualizar');
    Route::post('/geracao', [PropinaController::class, 'gerar'])->middleware('can:propina.criar')->name('geracao.store');
});
```

Em `app/Http/Middleware/HandleInertiaRequests.php`, dentro de `'flash' => [...]`, a seguir à linha de `copia_planos`:
```php
                // Resultado da geração manual de propinas (totais e detalhe por matrícula).
                'geracao_propinas' => fn () => $request->session()->get('geracao_propinas'),
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Feature/PropinaGeracaoHttpTest.php Modules/Financeiro/tests/Feature/PropinaListaTest.php`
Expected: PASS (8 + 9 testes).

Run: `php artisan test Modules/Financeiro/tests/Feature/PermissoesPropinaTest.php Modules/Permissao tests/Feature/Comandos`
Expected: PASS (5 testes em `PermissoesPropinaTest`; nenhuma contagem de permissões do ADMIN_ESCOLA quebra).

Run: `php artisan test tests/Feature/RotasPermissaoReconhecidaTest.php tests/Feature/RotasEscritaAutorizadasTest.php tests/Feature/Arquitectura`
Expected: PASS.

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Http/Requests/Concerns/ValidaGeracaoPropinas.php \
  Modules/Financeiro/app/Http/Requests/PreVisualizarGeracaoPropinasRequest.php Modules/Financeiro/app/Http/Requests/GerarPropinasRequest.php \
  Modules/Financeiro/app/Actions/GerarPropinasAction.php Modules/Financeiro/app/Http/Controllers/PropinaController.php \
  Modules/Financeiro/routes/web.php app/Http/Middleware/HandleInertiaRequests.php \
  Modules/Financeiro/app/Services/GerarPropinasService.php Modules/Permissao/app/Actions/SincronizarPerfisDeSistemaAction.php \
  Modules/Financeiro/tests/Feature/PropinaGeracaoHttpTest.php Modules/Financeiro/tests/Feature/PermissoesPropinaTest.php
```

---

### Task 9: Geração automática — `financeiro:gerar-propinas`, agendamento diário e recuperação

**Files:**
- Modify: `Modules/Financeiro/app/Services/GerarPropinasService.php` (+ `gerarAutomaticamente`)
- Create: `Modules/Financeiro/app/Console/GerarPropinasCommand.php`
- Modify: `Modules/Financeiro/app/Providers/FinanceiroServiceProvider.php`
- Modify: `routes/console.php`
- Test: `Modules/Financeiro/tests/Feature/GerarPropinasCommandTest.php`

**Interfaces:**
- Consumes: `ParaTodosOsTenants` (`--tenant=CODIGO` | `--todos`, falha por tenant registada), `TenantAtual::$codigo`, `RegraCobranca::gerar_automaticamente`, T6 (`gerar`), `PedidoGeracaoPropinasDTO::automatica`.
- Produces:
  - `GerarPropinasService::gerarAutomaticamente(): ?array<int, ResultadoGeracaoPropinasDTO>` (por ano lectivo; `null` se desligada).
  - Comando `financeiro:gerar-propinas` (uma linha `Log::info('financeiro.gerar-propinas', [tenant, activa, ano_lectivo_id, …totais])` por tenant e ano; tenant com erros → falhado, código de saída ≠ 0).
  - Agendamento `Schedule::command('financeiro:gerar-propinas --todos')->dailyAt('02:00')->withoutOverlapping()`.

- [ ] **Step 1: Escrever o teste que falha**

`Modules/Financeiro/tests/Feature/GerarPropinasCommandTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\PendingCommand;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use RuntimeException;
use Tests\TestCase;

class GerarPropinasCommandTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-09-01 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function ligar(): void
    {
        RegraCobranca::doTenant()->update(['gerar_automaticamente' => true]);
    }

    private function correr(array $opcoes = []): PendingCommand
    {
        return $this->artisan('financeiro:gerar-propinas', $opcoes ?: ['--tenant' => $this->tenant->codigo]);
    }

    public function test_com_a_geracao_automatica_desligada_nao_faz_nada(): void
    {
        $this->cenarioPropinas();

        $this->correr()->expectsOutputToContain('geração automática desligada')->assertSuccessful();

        $this->assertSame(0, Propina::query()->count());
    }

    public function test_ligada_gera_as_propinas_em_falta_com_origem_comando_e_regista_no_log(): void
    {
        Log::spy();
        $this->ligar();
        ['matricula' => $matricula] = $this->cenarioPropinas();

        $this->correr()->assertSuccessful();

        $this->assertSame(10, Propina::query()->where('matricula_id', $matricula->id)->count());
        $this->assertTrue(Propina::query()->get()->every(fn (Propina $p) => $p->origem_geracao === OrigemGeracaoPropina::COMANDO));
        $this->assertTrue(Propina::query()->get()->every(fn (Propina $p) => $p->criado_por === null));
        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $mensagem, array $contexto = []) => $mensagem === 'financeiro.gerar-propinas'
                && ($contexto['tenant'] ?? null) === $this->tenant->codigo
                && ($contexto['criadas'] ?? null) === 10
                && ($contexto['erros'] ?? null) === 0)
            ->once();
    }

    public function test_todos_os_tenants_cada_um_com_a_sua_regra(): void
    {
        $this->ligar();
        $this->cenarioPropinas();
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, fn () => $this->cenarioPropinas()); // a escola B fica com a geração desligada

        $this->correr(['--todos' => true])->assertSuccessful();

        $this->assertSame(10, Propina::query()->count());
        $this->assertSame(0, $this->noTenant($outro, fn () => Propina::query()->count()));
    }

    public function test_matriculas_activas_de_dois_anos_lectivos_geram_cada_uma_pelo_seu_plano(): void
    {
        $this->ligar();
        ['matricula' => $actual] = $this->cenarioPropinas();
        $anterior = $this->anoLectivo('2025/2026', '2025-09-01', '2026-07-31');
        $this->plano($anterior, 'Propina 2025/2026', ['valor' => Dinheiro::deUnidadesMenores(2_000_000)]);
        $antiga = $this->matriculaActiva($this->turma($anterior, $this->nivel('NA')), '2025-09-01');

        $this->correr()->assertSuccessful();

        $this->assertSame(10, Propina::query()->where('matricula_id', $actual->id)->count());
        $this->assertSame(
            [2_000_000],
            Propina::query()->where('matricula_id', $antiga->id)->get()->map(fn (Propina $p) => $p->valor->unidadesMenores())->unique()->values()->all(),
        );
    }

    public function test_uma_falha_fica_registada_e_a_execucao_seguinte_recupera_sem_duplicar(): void
    {
        // Critério 3 de §8.2: matrícula Activa que ficou sem propinas (falha depois da activação) → o comando gera-as.
        $this->ligar();
        ['turma' => $turma, 'matricula' => $primeira] = $this->cenarioPropinas();
        $segunda = $this->matriculaActiva($turma);
        $falhou = false;
        Propina::creating(function () use (&$falhou) {
            if (! $falhou) {
                $falhou = true;

                throw new RuntimeException('Falha simulada na primeira propina.');
            }
        });

        $this->correr()->assertFailed();
        $this->assertSame(0, Propina::query()->where('matricula_id', $primeira->id)->count());
        $this->assertSame(10, Propina::query()->where('matricula_id', $segunda->id)->count());

        $this->correr()->assertSuccessful();
        $this->assertSame(20, Propina::query()->count());

        $this->correr()->assertSuccessful();
        $this->assertSame(20, Propina::query()->count());
    }

    public function test_esta_agendado_diariamente_para_todos_os_tenants_sem_sobreposicao(): void
    {
        $eventos = collect(app(Schedule::class)->events())
            ->filter(fn ($evento) => str_contains((string) $evento->command, 'financeiro:gerar-propinas'));

        $this->assertCount(1, $eventos);
        $this->assertStringContainsString('--todos', $eventos->first()->command);
        $this->assertSame('0 2 * * *', $eventos->first()->expression);
        $this->assertTrue($eventos->first()->withoutOverlapping);
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/GerarPropinasCommandTest.php`
Expected: FAIL (comando `financeiro:gerar-propinas` inexistente; agendamento ausente).

- [ ] **Step 3: Implementar**

Em `Modules/Financeiro/app/Services/GerarPropinasService.php`, a seguir a `gerar()`:
```php
    /**
     * Geração automática (comando diário, spec §7.6): só se a escola ligou «gerar automaticamente»; todas as
     * matrículas Activas, ano lectivo a ano lectivo. Idempotente: o que falhou, ou ficou por gerar quando a
     * matrícula foi activada, é apanhado na execução seguinte (critério 3 de §8.2).
     *
     * @return array<int, ResultadoGeracaoPropinasDTO>|null  por ano lectivo; null = geração automática desligada
     */
    public function gerarAutomaticamente(): ?array
    {
        if (! (RegraCobranca::query()->first()?->gerar_automaticamente ?? false)) {
            return null;
        }

        $resultados = [];
        $anos = Matricula::query()
            ->where('estado', EstadoMatriculaEnum::ACTIVA->value)
            ->distinct()
            ->orderBy('ano_lectivo_id')
            ->pluck('ano_lectivo_id');

        foreach ($anos as $anoLectivoId) {
            $resultados[(int) $anoLectivoId] = $this->gerar(PedidoGeracaoPropinasDTO::automatica((int) $anoLectivoId));
        }

        return $resultados;
    }
```

`Modules/Financeiro/app/Console/GerarPropinasCommand.php`:
```php
<?php

namespace Modules\Financeiro\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Core\Tenancy\Console\ParaTodosOsTenants;
use Modules\Core\Tenancy\TenantAtual;
use Modules\Financeiro\Services\GerarPropinasService;
use RuntimeException;

/**
 * Geração automática de propinas (spec §7.6): em cada tenant com «gerar automaticamente» ligado, gera as
 * propinas em falta das matrículas Activas, pelo mesmo serviço da geração manual. Idempotente; agendado
 * diariamente com --todos. Sem lógica: delega em GerarPropinasService.
 */
class GerarPropinasCommand extends Command
{
    use ParaTodosOsTenants;

    protected $signature = 'financeiro:gerar-propinas';

    protected $description = 'Gera as propinas em falta das matrículas activas nas escolas com a geração automática ligada (--tenant=CODIGO ou --todos)';

    public function handle(GerarPropinasService $geracao): int
    {
        return $this->paraTenants(function (TenantAtual $tenant) use ($geracao): void {
            $resultados = $geracao->gerarAutomaticamente();

            if ($resultados === null) {
                Log::info('financeiro.gerar-propinas', ['tenant' => $tenant->codigo, 'activa' => false]);
                $this->line("[{$tenant->codigo}] geração automática desligada nas Regras de Cobrança: nada a fazer.");

                return;
            }

            $erros = 0;

            foreach ($resultados as $anoLectivoId => $resultado) {
                $totais = $resultado->totais();
                $erros += $totais['erros'];

                Log::info('financeiro.gerar-propinas', ['tenant' => $tenant->codigo, 'activa' => true, 'ano_lectivo_id' => $anoLectivoId] + $totais);
                $this->line("[{$tenant->codigo}] ano lectivo {$anoLectivoId}: {$totais['matriculas']} matrícula(s), {$totais['criadas']} propina(s) criada(s), "
                    . "{$totais['ignoradas']} ignorada(s), {$totais['conflitos']} conflito(s), {$totais['erros']} erro(s).");
            }

            // As matrículas que correram bem já estão gravadas. O tenant fica como falhado para o código de saída
            // e o agendador o assinalarem; a execução seguinte volta a tentar (idempotente).
            if ($erros > 0) {
                throw new RuntimeException("{$erros} matrícula(s) com erro na geração de propinas; consulte os registos.");
            }
        });
    }
}
```

Em `Modules/Financeiro/app/Providers/FinanceiroServiceProvider.php`: acrescentar `use Modules\Financeiro\Console\GerarPropinasCommand;` e a entrada em `$commands`:
```php
    protected array $commands = [
        CambioPlataformaCommand::class,
        GerarPropinasCommand::class,
        SincronizarFinanceiroCommand::class,
    ];
```

Em `routes/console.php`, depois do agendamento existente:
```php
// Propinas em falta das matrículas activas (só nas escolas com «gerar automaticamente» ligado nas Regras
// de Cobrança). 02:00 UTC = 03:00 em Luanda. Idempotente: apanha o que falhou na véspera.
Schedule::command('financeiro:gerar-propinas --todos')->dailyAt('02:00')->withoutOverlapping();
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Feature/GerarPropinasCommandTest.php`
Expected: PASS (6 testes).

Run: `php artisan financeiro:gerar-propinas`
Expected: erro "Indique --tenant=CODIGO ou --todos: não há tenant por omissão." (código ≠ 0). **Não** correr com `--todos` contra a base de desenvolvimento.

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Services/GerarPropinasService.php Modules/Financeiro/app/Console/GerarPropinasCommand.php \
  Modules/Financeiro/app/Providers/FinanceiroServiceProvider.php routes/console.php \
  Modules/Financeiro/tests/Feature/GerarPropinasCommandTest.php
```

---

### Task 10: Frontend — lista `Financeiro/Propinas/Index` e modal de geração com pré-visualização

**Files:**
- Create: `Modules/Financeiro/resources/js/Models/Propina.js`
- Modify: `Modules/Financeiro/resources/js/Support/periodos.js` (+ `dataCurta`)
- Create: `Modules/Financeiro/resources/js/Components/Shared/EstadoCobrancaBadge.vue`
- Create: `Modules/Financeiro/resources/js/Components/Propinas/ResultadoGeracao.vue`
- Create: `Modules/Financeiro/resources/js/Components/Propinas/GerarPropinasModal.vue`
- Create: `Modules/Financeiro/resources/js/Pages/Propinas/Index.vue`
- Modify: `Modules/Financeiro/resources/js/Pages/RegrasCobranca/Edit.vue` (P3: aviso junto de `gerar_automaticamente`)
- Test: `Modules/Financeiro/tests/Unit/AvisoGeracaoAutomaticaTest.php` (P3)

**Interfaces:**
- Consumes: props de T7 (`propinas, filtros, anosLectivos, planos, turmas, niveis, estados, origens, moeda`), `flash.geracao_propinas` e `flash.success` (T8), `POST /financeiro/propinas/geracao/pre-visualizar` (JSON) e `POST /financeiro/propinas/geracao` (Inertia), `can()` (`@/Composables/usePermissoes`), `SelectSolid`, `Pagination`, `formatarDinheiro`.
- Produces: página só de leitura por linha (sem acções por propina: detalhe e cancelar são F3); botão "Gerar propinas" só com `propina.criar`; caixa "Incluir períodos anteriores" só com `propina.ajustar`. Padrões copiados de `Pages/PlanosPropina/Index.vue` (filtros com debounce e `TODOS`), `CopiarPlanosModal.vue` (modal, `bg-body-secondary`) e `MatriculaFormModal.vue` (axios).

> Sem testes automáticos de frontend no projecto (não há Vitest). A verificação é o `npm run build` (Step 2) e a verificação visual (Task 14). O comportamento do servidor que a página consome está coberto pelas Tasks 7–8. O aviso P3 tem um teste estático (lê o `.vue`, como o `MenuFinanceiroTest`).

- [ ] **Step 0 (P3): teste do aviso que falha**

`Modules/Financeiro/tests/Unit/AvisoGeracaoAutomaticaTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * P3: a geração automática segue o spec §7.2 (desde o mês da matrícula, incluindo meses já passados, que
 * nascem Em Atraso). Junto do interruptor, Regras de Cobrança avisa e recomenda pré-visualizar e gerar
 * manualmente antes de ligar. Lê o ficheiro sem o executar (não há testes de frontend); ignora comentários.
 */
class AvisoGeracaoAutomaticaTest extends TestCase
{
    public function test_o_interruptor_tem_o_aviso_logo_a_seguir_e_no_mesmo_cartao(): void
    {
        $vue = preg_replace('/<!--.*?-->/s', '', file_get_contents(dirname(__DIR__, 2) . '/resources/js/Pages/RegrasCobranca/Edit.vue'));

        $interruptor = strpos($vue, 'id="gerar_automaticamente"');
        $aviso = strpos($vue, 'data-aviso="geracao-automatica"');
        $cartaoSeguinte = strpos($vue, 'Negociação de dívidas');

        $this->assertNotFalse($interruptor);
        $this->assertNotFalse($aviso, 'Falta o aviso junto do interruptor «Gerar cobranças automaticamente».');
        $this->assertTrue($interruptor < $aviso && $aviso < $cartaoSeguinte, 'O aviso fica logo a seguir ao interruptor, no cartão «Pagamentos e geração».');

        $texto = substr($vue, $aviso, 900);
        $this->assertStringContainsString('desde o mês da matrícula', $texto);
        $this->assertStringContainsString('Em Atraso', $texto);
        $this->assertStringContainsString('pré-visualizar', $texto);
        $this->assertStringContainsString('Financeiro → Propinas', $texto);
    }
}
```

Run: `php artisan test Modules/Financeiro/tests/Unit/AvisoGeracaoAutomaticaTest.php`
Expected: FAIL ("Falta o aviso junto do interruptor…").

Em `Modules/Financeiro/resources/js/Pages/RegrasCobranca/Edit.vue`, a seguir ao `<div class="form-check form-switch …">` do `gerar_automaticamente` (dentro do mesmo `card-body`), acrescentar:
```vue
                    <div class="alert alert-warning fs-7 py-3 mt-4 mb-0" data-aviso="geracao-automatica">
                        Ao ligar, o comando diário (de madrugada, 02:00 UTC) gera as propinas de cada matrícula activa <strong>desde o mês da matrícula</strong>,
                        incluindo os meses já passados, que ficam logo <strong>Em Atraso</strong>.
                        Recomendado antes de ligar: em <strong>Financeiro → Propinas → Gerar propinas</strong>, pré-visualizar e gerar manualmente
                        por turma, e só depois ligar esta opção. A partir daí o comando só cria o que faltar (não duplica).
                    </div>
```

Run: `php artisan test Modules/Financeiro/tests/Unit/AvisoGeracaoAutomaticaTest.php`
Expected: PASS (1 teste).

- [ ] **Step 1: Implementar**

`Modules/Financeiro/resources/js/Models/Propina.js`:
```js
/**
 * Espelha EstadoCobranca e OrigemGeracaoPropina (PHP). Só para apresentação; a autoridade é o backend
 * (o estado que chega à lista já vem resolvido para o "hoje" da escola).
 */
export const ESTADO_COBRANCA = Object.freeze({
    EM_ABERTO: 1,
    PARCIALMENTE_PAGA: 2,
    PAGA: 3,
    CANCELADA: 4,
    ANULADA: 5,
    PENDENTE: 6,
    EM_ATRASO: 7,
});

export const ORIGEM_GERACAO = Object.freeze({ MATRICULA: 1, COMANDO: 2, MANUAL: 3, RETROACTIVA: 4 });

export const estadoCobrancaBadgeClass = (estado) => {
    switch (estado) {
        case ESTADO_COBRANCA.EM_ABERTO: return 'badge-light-primary';
        case ESTADO_COBRANCA.PARCIALMENTE_PAGA: return 'badge-light-info';
        case ESTADO_COBRANCA.PAGA: return 'badge-light-success';
        case ESTADO_COBRANCA.CANCELADA: return 'badge-inativo';
        case ESTADO_COBRANCA.ANULADA: return 'badge-light-dark';
        case ESTADO_COBRANCA.EM_ATRASO: return 'badge-light-danger';
        default: return 'badge-light-secondary';
    }
};
```

Em `Modules/Financeiro/resources/js/Support/periodos.js`, acrescentar no fim:
```js
/** "2026-09-10" → "10/09/2026". Sem `Date`: uma data civil não tem fuso e não pode recuar um dia. */
export function dataCurta(ymd) {
    if (!ymd) return '—';

    const [ano, mes, dia] = String(ymd).slice(0, 10).split('-');

    return `${dia}/${mes}/${ano}`;
}
```

`Modules/Financeiro/resources/js/Components/Shared/EstadoCobrancaBadge.vue`:
```vue
<script setup>
import { estadoCobrancaBadgeClass } from '../../Models/Propina';

defineProps({
    estado: { type: Number, required: true },
    estadoDescricao: { type: String, required: true },
});
</script>

<template>
    <div class="badge fw-bold" :class="estadoCobrancaBadgeClass(estado)">
        {{ estadoDescricao }}
    </div>
</template>
```

`Modules/Financeiro/resources/js/Components/Propinas/ResultadoGeracao.vue`:
```vue
<script setup>
import { formatarDinheiro } from '../../Support/dinheiro';
import { dataCurta } from '../../Support/periodos';
import { ORIGEM_GERACAO } from '../../Models/Propina';

defineProps({
    // ResultadoGeracaoPropinasDTO::toArray(): { previsualizacao, totais, matriculas: [...], detalhe_truncado }
    resultado: { type: Object, required: true },
    moeda: { type: Object, required: true },
});
</script>

<template>
    <div>
        <div class="d-flex flex-wrap gap-2 mb-4">
            <span class="badge badge-light-primary fs-7">{{ resultado.totais.matriculas }} matrícula(s)</span>
            <span class="badge badge-light-success fs-7">
                {{ resultado.totais.criadas }} {{ resultado.previsualizacao ? 'a criar' : 'criada(s)' }}
            </span>
            <span class="badge badge-light-secondary fs-7">{{ resultado.totais.ignoradas }} ignorada(s)</span>
            <span v-if="resultado.totais.conflitos" class="badge badge-light-warning fs-7">{{ resultado.totais.conflitos }} conflito(s)</span>
            <span v-if="resultado.totais.sem_plano" class="badge badge-light-info fs-7">{{ resultado.totais.sem_plano }} mês(es) sem plano</span>
            <span v-if="resultado.totais.erros" class="badge badge-light-danger fs-7">{{ resultado.totais.erros }} erro(s)</span>
        </div>

        <div v-if="resultado.detalhe_truncado" class="alert alert-info py-3 fs-7 mb-4">
            O detalhe mostra só parte das matrículas (primeiro as com erros e conflitos). Os totais acima incluem todas.
        </div>

        <p v-if="resultado.matriculas.length === 0" class="text-muted mb-0">Nenhuma matrícula no âmbito escolhido.</p>

        <div class="overflow-auto" style="max-height: 420px">
            <div v-for="matricula in resultado.matriculas" :key="matricula.matricula_id" class="card bg-body-secondary mb-3">
                <div class="card-body py-3">
                    <div class="fw-bold text-gray-800">
                        {{ matricula.aluno_nome ?? `Matrícula #${matricula.matricula_id}` }}
                        <span class="text-muted fw-normal fs-7">
                            · nº {{ matricula.aluno_numero ?? '—' }} · matrícula {{ matricula.numero_registo ?? '—' }} · {{ matricula.turma_nome ?? 'sem turma' }}
                        </span>
                    </div>

                    <ul v-if="matricula.criadas.length" class="mt-2 mb-1 fs-7">
                        <li v-for="propina in matricula.criadas" :key="propina.periodo_inicio">
                            <strong>{{ propina.periodo }}</strong>
                            — {{ propina.plano }} · {{ formatarDinheiro(propina.valor, moeda) }} · vence {{ dataCurta(propina.data_vencimento) }}
                            <span v-if="propina.origem_geracao === ORIGEM_GERACAO.RETROACTIVA" class="badge badge-light-warning ms-1">Retroactiva</span>
                            <span v-else-if="propina.tardia" class="badge badge-light-warning ms-1" title="Nasce depois do vencimento: fica logo em atraso.">Geração tardia</span>
                        </li>
                    </ul>

                    <ul v-if="matricula.ignoradas.length" class="mt-2 mb-1 fs-7 text-muted">
                        <li v-for="(ignorada, indice) in matricula.ignoradas" :key="`i${indice}`">
                            <strong v-if="ignorada.periodo">{{ ignorada.periodo }}:</strong> {{ ignorada.motivo }}
                        </li>
                    </ul>

                    <ul v-if="matricula.conflitos.length" class="mt-2 mb-1 fs-7 text-warning">
                        <li v-for="(conflito, indice) in matricula.conflitos" :key="`c${indice}`">{{ conflito }}</li>
                    </ul>

                    <div v-if="matricula.sem_plano.length" class="mt-2 fs-7 text-muted">
                        Meses sem plano aplicável: {{ matricula.sem_plano.join(', ') }}
                    </div>

                    <ul v-if="matricula.erros.length" class="mt-2 mb-0 fs-7 text-danger">
                        <li v-for="(erro, indice) in matricula.erros" :key="`e${indice}`">{{ erro }}</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</template>
```

`Modules/Financeiro/resources/js/Components/Propinas/GerarPropinasModal.vue`:
```vue
<script setup>
import { computed, reactive, ref, watch } from 'vue';
import axios from 'axios';
import { toast } from 'vue-sonner';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import ResultadoGeracao from './ResultadoGeracao.vue';
import { ESTADO } from '../../Models/Estado';

const props = defineProps({
    show: { type: Boolean, default: false },
    anosLectivos: { type: Array, required: true },
    anoLectivoId: { type: [Number, String], default: null },
    turmas: { type: Array, required: true },
    niveis: { type: Array, required: true },
    planos: { type: Array, required: true },
    moeda: { type: Object, required: true },
    podeIncluirAnteriores: { type: Boolean, default: false },
    resultado: { type: Object, default: null }, // flash.geracao_propinas depois de confirmar
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['confirmar', 'cancelar']);

const PRE_VISUALIZAR = '/financeiro/propinas/geracao/pre-visualizar';
const AMBITOS = [
    { value: 'turma', label: 'Uma turma' },
    { value: 'nivel', label: 'Um nível académico' },
    { value: 'plano', label: 'Todas as turmas de um plano' },
    { value: 'matricula', label: 'Uma matrícula' },
];

const vazio = () => ({
    ano_lectivo_id: '',
    ambito: 'turma',
    turma_id: '',
    nivel_academico_id: '',
    plano_propina_id: '',
    matricula_numero: '',
    incluir_periodos_anteriores: false,
    motivo_geracao: '',
});

const form = reactive(vazio());
const previsao = ref(null);
const aCalcular = ref(false);
const errosPrevia = ref({});

watch(() => props.show, (show) => {
    if (!show) return;
    Object.assign(form, vazio(), { ano_lectivo_id: Number(props.anoLectivoId) || props.anosLectivos[0]?.id || '' });
    previsao.value = null;
    errosPrevia.value = {};
});

// Mudar um parâmetro invalida a pré-visualização: nunca se confirma algo diferente do que foi visto.
watch(form, () => {
    previsao.value = null;
}, { deep: true });

const opcoesAno = computed(() => props.anosLectivos.map((a) => ({ value: a.id, label: a.nome })));
const opcoesTurma = computed(() => props.turmas
    .filter((t) => t.ano_lectivo_id === form.ano_lectivo_id)
    .map((t) => ({ value: t.id, label: t.nome })));
const opcoesNivel = computed(() => props.niveis.map((n) => ({ value: n.id, label: n.nome })));
const opcoesPlano = computed(() => [
    ...(form.ambito === 'plano' ? [] : [{ value: '', label: 'Qualquer plano aplicável' }]),
    ...props.planos
        .filter((p) => p.ano_lectivo_id === form.ano_lectivo_id && p.estado === ESTADO.ATIVO)
        .map((p) => ({ value: p.id, label: p.nome })),
]);

const erro = (campo) => errosPrevia.value[campo] ?? props.errors[campo];

function payload() {
    const dados = { ano_lectivo_id: form.ano_lectivo_id, ambito: form.ambito };

    if (form.ambito === 'turma') dados.turma_id = form.turma_id;
    if (form.ambito === 'nivel') dados.nivel_academico_id = form.nivel_academico_id;
    if (form.ambito === 'matricula') dados.matricula_numero = form.matricula_numero.trim();
    if (form.plano_propina_id !== '') dados.plano_propina_id = form.plano_propina_id;

    if (form.incluir_periodos_anteriores) {
        dados.incluir_periodos_anteriores = true;
        dados.motivo_geracao = form.motivo_geracao;
    }

    return dados;
}

async function preVisualizar() {
    aCalcular.value = true;
    errosPrevia.value = {};

    try {
        const { data } = await axios.post(PRE_VISUALIZAR, payload());
        previsao.value = data.resultado;
    } catch (falha) {
        const estado = falha.response?.status;

        if (estado === 422) {
            errosPrevia.value = Object.fromEntries(Object.entries(falha.response.data.errors ?? {}).map(([campo, mensagens]) => [campo, mensagens[0]]));
            toast.error(Object.values(errosPrevia.value)[0] ?? 'Verifique os dados.');
        } else if (estado === 403) {
            toast.error('Não tem permissão para esta geração.');
        } else {
            toast.error('Não foi possível pré-visualizar. Tente de novo.');
        }
    } finally {
        aCalcular.value = false;
    }
}

function confirmar() {
    emit('confirmar', payload());
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5); overflow-y: auto;" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content p-6">
                <h3 class="mb-5">Gerar propinas</h3>

                <template v-if="resultado">
                    <ResultadoGeracao :resultado="resultado" :moeda="moeda" />
                    <div class="text-end mt-5">
                        <button type="button" class="btn btn-primary" @click="emit('cancelar')">Fechar</button>
                    </div>
                </template>

                <template v-else-if="previsao">
                    <div class="alert alert-warning py-3 fs-7 mb-5">
                        Pré-visualização: <strong>nada foi gravado</strong>. Ao confirmar, tudo é recalculado e gravado matrícula a matrícula;
                        o resultado final pode diferir se alguém alterar matrículas ou planos entretanto.
                    </div>
                    <ResultadoGeracao :resultado="previsao" :moeda="moeda" />
                    <div class="text-end mt-5">
                        <button type="button" class="btn btn-light me-2" :disabled="processing" @click="previsao = null">Voltar</button>
                        <button type="button" class="btn btn-primary" :disabled="processing || previsao.totais.criadas === 0" @click="confirmar">
                            {{ processing ? 'A gerar…' : `Confirmar e gerar ${previsao.totais.criadas} propina(s)` }}
                        </button>
                    </div>
                </template>

                <form v-else @submit.prevent="preVisualizar">
                    <p class="text-muted fs-6">
                        Gera, para as matrículas <strong>activas</strong> do âmbito, as propinas em falta pelo plano que se aplica a cada mês.
                        Os períodos já cobertos são ignorados. Nada é gravado antes de confirmar.
                    </p>

                    <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Ano lectivo</label>
                            <SelectSolid v-model="form.ano_lectivo_id" :options="opcoesAno" />
                            <div v-if="erro('ano_lectivo_id')" class="text-danger fs-7 mt-1">{{ erro('ano_lectivo_id') }}</div>
                        </div>
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Para quem</label>
                            <SelectSolid v-model="form.ambito" :options="AMBITOS" />
                            <div v-if="erro('ambito')" class="text-danger fs-7 mt-1">{{ erro('ambito') }}</div>
                        </div>

                        <div v-if="form.ambito === 'turma'" class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Turma</label>
                            <SelectSolid v-model="form.turma_id" :options="opcoesTurma" searchable />
                            <div v-if="erro('turma_id')" class="text-danger fs-7 mt-1">{{ erro('turma_id') }}</div>
                        </div>
                        <div v-if="form.ambito === 'nivel'" class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Nível académico</label>
                            <SelectSolid v-model="form.nivel_academico_id" :options="opcoesNivel" searchable />
                            <div v-if="erro('nivel_academico_id')" class="text-danger fs-7 mt-1">{{ erro('nivel_academico_id') }}</div>
                        </div>
                        <div v-if="form.ambito === 'matricula'" class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Nº de registo da matrícula</label>
                            <input v-model="form.matricula_numero" type="text" class="form-control form-control-solid" maxlength="50" />
                            <div v-if="erro('matricula_numero')" class="text-danger fs-7 mt-1">{{ erro('matricula_numero') }}</div>
                        </div>

                        <div class="col-md-6 fv-row mb-7">
                            <label :class="['fw-semibold fs-6 mb-2', { required: form.ambito === 'plano' }]">Plano de propina</label>
                            <SelectSolid v-model="form.plano_propina_id" :options="opcoesPlano" />
                            <div class="text-muted fs-7 mt-1">
                                {{ form.ambito === 'plano' ? 'As turmas a que este plano se aplica em algum mês.' : 'Opcional: com um plano escolhido, os períodos de outros planos ficam de fora.' }}
                            </div>
                            <div v-if="erro('plano_propina_id')" class="text-danger fs-7 mt-1">{{ erro('plano_propina_id') }}</div>
                        </div>
                    </div>

                    <div v-if="podeIncluirAnteriores" class="mb-7">
                        <label class="form-check form-check-custom form-check-solid">
                            <input v-model="form.incluir_periodos_anteriores" class="form-check-input" type="checkbox" />
                            <span class="form-check-label">Incluir períodos anteriores ao mês da matrícula (geração retroactiva)</span>
                        </label>
                        <template v-if="form.incluir_periodos_anteriores">
                            <textarea v-model="form.motivo_geracao" class="form-control form-control-solid mt-3" rows="2" maxlength="500" placeholder="Motivo (obrigatório)"></textarea>
                            <div v-if="erro('motivo_geracao')" class="text-danger fs-7 mt-1">{{ erro('motivo_geracao') }}</div>
                        </template>
                    </div>

                    <div class="text-end">
                        <button type="button" class="btn btn-light-danger me-2" :disabled="aCalcular" @click="emit('cancelar')">Cancelar</button>
                        <button type="submit" class="btn btn-primary" :disabled="aCalcular">{{ aCalcular ? 'A calcular…' : 'Pré-visualizar' }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
```

`Modules/Financeiro/resources/js/Pages/Propinas/Index.vue`:
```vue
<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import { can } from '@/Composables/usePermissoes';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import EstadoCobrancaBadge from '../../Components/Shared/EstadoCobrancaBadge.vue';
import GerarPropinasModal from '../../Components/Propinas/GerarPropinasModal.vue';
import { ORIGEM_GERACAO } from '../../Models/Propina';
import { formatarDinheiro } from '../../Support/dinheiro';
import { dataCurta } from '../../Support/periodos';

const BASE = '/financeiro/propinas';
// 'todos' distingue "sem filtro" de "filtro ausente" (que aplica o ano lectivo activo no servidor).
const TODOS = 'todos';

const props = defineProps({
    propinas: { type: Object, required: true }, // paginador: { data, links, total, ... }
    filtros: { type: Object, default: () => ({}) },
    anosLectivos: { type: Array, required: true },
    planos: { type: Array, required: true },
    turmas: { type: Array, required: true },
    niveis: { type: Array, required: true },
    estados: { type: Array, required: true },
    origens: { type: Array, required: true },
    moeda: { type: Object, required: true },
});
defineOptions({ layout: AppLayout });

const numeroOuVazio = (valor) => (valor !== undefined && valor !== null && valor !== '' && Number.isFinite(Number(valor)) ? Number(valor) : '');

const filtros = reactive({
    pesquisa: props.filtros.pesquisa ?? '',
    ano_lectivo_id: numeroOuVazio(props.filtros.ano_lectivo_id) || TODOS,
    estado: numeroOuVazio(props.filtros.estado),
    plano_propina_id: numeroOuVazio(props.filtros.plano_propina_id),
    origem_geracao: numeroOuVazio(props.filtros.origem_geracao),
    vencimento_de: props.filtros.vencimento_de ?? '',
    vencimento_ate: props.filtros.vencimento_ate ?? '',
});

const opcoesAno = computed(() => [{ value: TODOS, label: 'Todos os anos lectivos' }, ...props.anosLectivos.map((a) => ({ value: a.id, label: a.nome }))]);
const opcoesEstado = computed(() => [{ value: '', label: 'Todos os estados' }, ...props.estados]);
const opcoesOrigem = computed(() => [{ value: '', label: 'Todas as origens' }, ...props.origens]);
const opcoesPlano = computed(() => [
    { value: '', label: 'Todos os planos' },
    ...props.planos
        .filter((p) => filtros.ano_lectivo_id === TODOS || p.ano_lectivo_id === filtros.ano_lectivo_id)
        .map((p) => ({ value: p.id, label: p.nome })),
]);

let debounceId = null;
watch(filtros, (valor) => {
    clearTimeout(debounceId);
    debounceId = setTimeout(() => {
        router.get(BASE, valor, { preserveState: true, preserveScroll: true, replace: true });
    }, 300);
});

const page = usePage();
const geracaoAberta = ref(false);
const geracaoProcessing = ref(false);
const geracaoErrors = ref({});
const resultadoGeracao = ref(null);
const anoParaGerar = computed(() => (filtros.ano_lectivo_id === TODOS ? props.anosLectivos[0]?.id ?? null : filtros.ano_lectivo_id));

function abrirGeracao() {
    geracaoErrors.value = {};
    resultadoGeracao.value = null;
    geracaoAberta.value = true;
}

function gerar(payload) {
    geracaoProcessing.value = true;
    geracaoErrors.value = {};

    router.post(`${BASE}/geracao`, payload, {
        preserveScroll: true,
        onSuccess: () => {
            resultadoGeracao.value = page.props.flash?.geracao_propinas ?? null;
            toast.success(page.props.flash?.success ?? 'Propinas geradas.');
        },
        onError: (erros) => {
            geracaoErrors.value = erros;
            toast.error(Object.values(erros)[0]);
        },
        onFinish: () => {
            geracaoProcessing.value = false;
        },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="d-flex justify-content-between align-items-center mb-6">
            <div>
                <h1 class="fs-2 fw-bold mb-1">Propinas</h1>
                <p class="text-muted fs-6 mb-0" style="max-width: 720px">
                    Cobranças de propina das matrículas, geradas a partir dos planos de propina. O estado é calculado para o dia de hoje
                    na escola: Pendente antes do período começar, Em Atraso depois do vencimento e da tolerância.
                </p>
            </div>
            <div v-if="can('propina.criar')">
                <button class="btn btn-primary" @click="abrirGeracao">Gerar propinas</button>
            </div>
        </div>

        <div class="card mb-6">
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-12 col-md-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">Pesquisa</label>
                        <input v-model="filtros.pesquisa" type="text" class="form-control form-control-solid" placeholder="Aluno, nº do aluno ou nº da matrícula" />
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">Ano Lectivo</label>
                        <SelectSolid v-model="filtros.ano_lectivo_id" :options="opcoesAno" />
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">Estado</label>
                        <SelectSolid v-model="filtros.estado" :options="opcoesEstado" />
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">Plano</label>
                        <SelectSolid v-model="filtros.plano_propina_id" :options="opcoesPlano" searchable />
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">Origem</label>
                        <SelectSolid v-model="filtros.origem_geracao" :options="opcoesOrigem" />
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="fw-semibold fs-7 text-muted mb-1">Vencimento de</label>
                        <input v-model="filtros.vencimento_de" type="date" class="form-control form-control-solid" />
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="fw-semibold fs-7 text-muted mb-1">Vencimento até</label>
                        <input v-model="filtros.vencimento_ate" type="date" class="form-control form-control-solid" />
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle table-row-dashed table-hover fs-6 gy-5 mb-0">
                        <thead>
                            <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                                <th class="min-w-175px">Aluno</th>
                                <th class="min-w-100px">Matrícula</th>
                                <th class="min-w-100px">Turma</th>
                                <th class="min-w-125px">Plano</th>
                                <th class="min-w-100px">Período</th>
                                <th class="min-w-100px">Vencimento</th>
                                <th class="text-end min-w-100px">Valor</th>
                                <th class="text-end min-w-100px">Pago</th>
                                <th class="text-end min-w-100px">Saldo</th>
                                <th class="min-w-125px">Estado</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-600 fw-semibold">
                            <tr v-if="propinas.data.length === 0">
                                <td colspan="10" class="text-center text-muted py-6">Nenhuma propina encontrada.</td>
                            </tr>
                            <tr v-for="propina in propinas.data" :key="propina.id">
                                <td class="text-gray-800">
                                    {{ propina.aluno_nome ?? '—' }}
                                    <div class="text-muted fs-7">nº {{ propina.aluno_numero ?? '—' }}</div>
                                </td>
                                <td>{{ propina.matricula_numero ?? '—' }}</td>
                                <td>{{ propina.turma_nome ?? '—' }}</td>
                                <td>{{ propina.plano_nome ?? '—' }}</td>
                                <td>{{ propina.periodo }}</td>
                                <td>
                                    {{ dataCurta(propina.data_vencimento) }}
                                    <div class="text-muted fs-8">limite {{ dataCurta(propina.data_limite) }}</div>
                                </td>
                                <td class="text-end">{{ formatarDinheiro(propina.valor, moeda) }}</td>
                                <td class="text-end">{{ formatarDinheiro(propina.valor_pago, moeda) }}</td>
                                <td class="text-end">{{ formatarDinheiro(propina.saldo, moeda) }}</td>
                                <td>
                                    <EstadoCobrancaBadge :estado="propina.estado" :estado-descricao="propina.estado_descricao" />
                                    <div class="mt-1">
                                        <span v-if="propina.origem_geracao === ORIGEM_GERACAO.RETROACTIVA" class="badge badge-light-warning" :title="propina.motivo_geracao ?? ''">Retroactiva</span>
                                        <span v-else-if="propina.geracao_tardia" class="badge badge-light-warning" title="Gerada depois do vencimento.">Geração tardia</span>
                                        <span v-else class="badge badge-light fs-8">{{ propina.origem_geracao_descricao }}</span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div v-if="propinas.data.length" class="card-footer d-flex justify-content-end">
                <Pagination :links="propinas.links" />
            </div>
        </div>

        <GerarPropinasModal
            :show="geracaoAberta"
            :anos-lectivos="anosLectivos"
            :ano-lectivo-id="anoParaGerar"
            :turmas="turmas"
            :niveis="niveis"
            :planos="planos"
            :moeda="moeda"
            :pode-incluir-anteriores="can('propina.ajustar')"
            :resultado="resultadoGeracao"
            :processing="geracaoProcessing"
            :errors="geracaoErrors"
            @confirmar="gerar"
            @cancelar="geracaoAberta = false"
        />
    </div>
</template>
```

- [ ] **Step 2: Build**

Run: `npm run build`
Expected: sucesso, sem avisos de import em falta. **Não** fazer `git add` de `public/build`.

- [ ] **Step 3: Stage**

```bash
git add Modules/Financeiro/resources/js/Models/Propina.js Modules/Financeiro/resources/js/Support/periodos.js \
  Modules/Financeiro/resources/js/Components/Shared/EstadoCobrancaBadge.vue \
  Modules/Financeiro/resources/js/Components/Propinas/ResultadoGeracao.vue \
  Modules/Financeiro/resources/js/Components/Propinas/GerarPropinasModal.vue \
  Modules/Financeiro/resources/js/Pages/Propinas/Index.vue \
  Modules/Financeiro/resources/js/Pages/RegrasCobranca/Edit.vue Modules/Financeiro/tests/Unit/AvisoGeracaoAutomaticaTest.php
```

---

### Task 11: Menu Financeiro (operação) na sidebar e no header, com paridade

**Files:**
- Modify: `resources/js/Components/Layout/SidebarMenuWrapper.vue`
- Create: `resources/js/Composables/useFinanceiroMenu.js`
- Create: `resources/js/Components/Layout/menus/FinanceiroMenu.vue`
- Modify: `resources/js/Components/Layout/HeaderMenu.vue`
- Test: `tests/Feature/Arquitectura/MenuFinanceiroTest.php`

**Interfaces:**
- Consumes: `SidebarAccordion` (filtra por `permissao` com `podeVer`), `podeVer` (`@/Composables/usePermissoes`), `useActiveMenu` (prefixo `/financeiro/propinas` já cobre o detalhe de F3).
- Produces: secção "Financeiro" (ícone `ki-dollar`, 3 caminhos) com **um** link activo `{ href: '/financeiro/propinas', title|label: 'Propinas', permissao: 'propina.ver' }` nos dois menus; os restantes itens do placeholder ficam comentados. O grupo "Financeiro" de **Configurações** (`configuracoesMenu` e `useConfiguracoesMenu.js`) não muda.

- [ ] **Step 1: Escrever o teste que falha**

`tests/Feature/Arquitectura/MenuFinanceiroTest.php`:
```php
<?php

namespace Tests\Feature\Arquitectura;

use PHPUnit\Framework\TestCase;

/**
 * Paridade header/sidebar do menu Financeiro (operação): o mesmo link, com a mesma permissão, nos dois
 * sítios (Q13 do plano de fases), e só "Propinas" activo até as outras áreas existirem. Lê os ficheiros
 * sem os executar (não há testes de frontend no projecto); ignora o que está comentado.
 */
class MenuFinanceiroTest extends TestCase
{
    private function ficheiro(string $relativo): string
    {
        return file_get_contents(dirname(__DIR__, 3) . '/' . $relativo);
    }

    private function semComentariosHtml(string $codigo): string
    {
        return preg_replace('/<!--.*?-->/s', '', $codigo);
    }

    private function semComentariosJs(string $codigo): string
    {
        return preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $codigo);
    }

    /** @return array{0: string, 1: string} template e script da sidebar */
    private function sidebar(): array
    {
        [$template, $script] = explode('<script setup>', $this->ficheiro('resources/js/Components/Layout/SidebarMenuWrapper.vue'), 2);

        return [$this->semComentariosHtml($template), $this->semComentariosJs($script)];
    }

    public function test_a_sidebar_mostra_a_seccao_financeiro_so_com_propinas(): void
    {
        [$template, $script] = $this->sidebar();

        $this->assertStringContainsString('<SidebarAccordion :item="financeiro" heading="Financeiro" />', $template);
        $this->assertSame(1, preg_match("#const financeiro = \{(.*?)\n\}#s", $script, $bloco), 'const financeiro tem de estar activo.');
        $this->assertStringContainsString("{ href: '/financeiro/propinas', title: 'Propinas', permissao: 'propina.ver' }", $bloco[1]);
        $this->assertSame(1, preg_match_all('/href:/', $bloco[1]), 'Só "Propinas" fica activo; os outros itens continuam comentados.');
    }

    public function test_o_header_tem_o_mesmo_link_com_a_mesma_permissao(): void
    {
        $composable = $this->semComentariosJs($this->ficheiro('resources/js/Composables/useFinanceiroMenu.js'));
        $header = $this->ficheiro('resources/js/Components/Layout/HeaderMenu.vue');

        $this->assertStringContainsString("{ href: '/financeiro/propinas', label: 'Propinas', permissao: 'propina.ver' }", $composable);
        $this->assertSame(1, preg_match_all('/href:/', $composable));
        $this->assertStringContainsString("import FinanceiroMenu from './menus/FinanceiroMenu.vue'", $header);
        $this->assertStringContainsString("import { useFinanceiroMenu } from '@/Composables/useFinanceiroMenu'", $header);
        $this->assertStringContainsString('<FinanceiroMenu />', $this->semComentariosHtml($header));
    }

    public function test_a_configuracao_financeira_continua_em_configuracoes(): void
    {
        [, $script] = $this->sidebar();

        $this->assertStringContainsString("href: '/financeiro/configuracao/planos-propina'", $script);
        $this->assertStringContainsString("href: '/financeiro/configuracao/planos-propina'", $this->ficheiro('resources/js/Composables/useConfiguracoesMenu.js'));
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test tests/Feature/Arquitectura/MenuFinanceiroTest.php`
Expected: FAIL (secção comentada; `useFinanceiroMenu.js` inexistente).

- [ ] **Step 3: Implementar**

Em `resources/js/Components/Layout/SidebarMenuWrapper.vue`, no template, substituir:
```vue
            <!-- ── Sections por implementar: Frequência, Pessoal, Financeiro,
                 Comunicação, Documentos, Biblioteca, Relatórios.
                 Descomentar uma a uma, sequencialmente, à medida que cada
                 módulo for implementado — cabeçalho e acordeão já vêm juntos
                 num só <SidebarAccordion>, controlados pelo mesmo v-if. -->
            <!--
            <SidebarAccordion :item="frequencia" heading="Frequência" />
            <SidebarAccordion :item="pessoal" heading="Pessoal" />
            <SidebarAccordion :item="financeiro" heading="Financeiro" />
            <SidebarAccordion :item="comunicacao" heading="Comunicação" />
```
por:
```vue
            <!-- ── Section: Financeiro (operação; a configuração fica em Configurações → Financeiro) ── -->
            <SidebarAccordion :item="financeiro" heading="Financeiro" />

            <!-- ── Sections por implementar: Frequência, Pessoal,
                 Comunicação, Documentos, Biblioteca, Relatórios.
                 Descomentar uma a uma, sequencialmente, à medida que cada
                 módulo for implementado — cabeçalho e acordeão já vêm juntos
                 num só <SidebarAccordion>, controlados pelo mesmo v-if. -->
            <!--
            <SidebarAccordion :item="frequencia" heading="Frequência" />
            <SidebarAccordion :item="pessoal" heading="Pessoal" />
            <SidebarAccordion :item="comunicacao" heading="Comunicação" />
```

E, no `<script setup>`, substituir o bloco comentado:
```js
// const financeiro = {
//     title: 'Financeiro',
//     icon: 'ki-dollar',
//     paths: 2,
//     items: [
//         { href: '#', title: 'Propinas' },
//         { href: '#', title: 'Pagamentos' },
//         { href: '#', title: 'Dívidas' },
//         { href: '#', title: 'Descontos / Bolsas' },
//         { href: '#', title: 'Recibos' },
//         { href: '#', title: 'Relatórios Financeiros' },
//     ],
// }
//
```
por:
```js
// ── Financeiro (operação) — os restantes itens entram com as respectivas fases ──
const financeiro = {
    title: 'Financeiro',
    icon: 'ki-dollar',
    paths: 3,
    items: [
        { href: '/financeiro/propinas', title: 'Propinas', permissao: 'propina.ver' },
        // { href: '#', title: 'Pagamentos' },
        // { href: '#', title: 'Dívidas' },
        // { href: '#', title: 'Descontos / Bolsas' },
        // { href: '#', title: 'Recibos' },
        // { href: '#', title: 'Relatórios Financeiros' },
    ],
}

```

`resources/js/Composables/useFinanceiroMenu.js`:
```js
import { computed } from 'vue';
import { podeVer } from './usePermissoes';

// Fonte única do conteúdo do dropdown "Financeiro" (operação) — usada tanto pelo próprio conteúdo
// (FinanceiroMenu.vue) como pelo item do Header que o envolve (HeaderMenu.vue), para os dois
// concordarem sobre quando não há nada a mostrar (mesmo padrão do useAcademicoMenu.js). A configuração
// financeira continua em useConfiguracoesMenu.js. Manter igual à `const financeiro` da sidebar.
const seccoesFinanceiro = [
    {
        title: 'Financeiro',
        links: [
            { href: '/financeiro/propinas', label: 'Propinas', permissao: 'propina.ver' },
            // { href: '#', label: 'Pagamentos' },
            // { href: '#', label: 'Dívidas' },
        ],
    },
];

export function useFinanceiroMenu() {
    const seccoesVisiveis = computed(() =>
        seccoesFinanceiro
            .map((s) => ({ ...s, links: s.links.filter(podeVer) }))
            .filter((s) => s.links.length > 0)
    );

    return { seccoesVisiveis };
}
```

`resources/js/Components/Layout/menus/FinanceiroMenu.vue`:
```vue
<template>
    <div class="menu-active-bg px-4 px-lg-0">
        <div class="tab-content py-4 py-lg-8 px-lg-7">
            <div class="tab-pane active" id="kt_app_header_menu_financeiro">
                <div class="d-flex">
                    <div v-for="section in seccoesVisiveis" :key="section.title" class="me-10">
                        <h4 class="fs-6 fs-lg-4 fw-bold mb-3">
                            {{ section.title }}
                        </h4>

                        <div v-for="link in section.links" :key="link.href" class="menu-item p-0 m-0">
                            <a :href="link.href" :class="['menu-link', { active: isActive(link.href) }]">
                                <span class="menu-title">
                                    {{ link.label }}
                                </span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { useFinanceiroMenu } from '@/Composables/useFinanceiroMenu'
import { useActiveMenu } from '@/composables/useActiveMenu'

const { seccoesVisiveis } = useFinanceiroMenu()
const { isActive } = useActiveMenu()
</script>
```

Em `resources/js/Components/Layout/HeaderMenu.vue`, antes de `<!-- Configurações -->`, inserir:
```vue
            <!-- Financeiro (operação) -->
            <div v-if="financeiroSeccoesVisiveis.length" data-kt-menu-trigger="{default: 'click', lg: 'hover'}" data-kt-menu-placement="bottom-start"
                :class="['menu-item', 'menu-lg-down-accordion', 'me-0', 'me-lg-2', { here: financeiroActivo, show: financeiroActivo }]">
                <span class="menu-link">
                    <span class="menu-icon">
                        <i class="ki-duotone ki-dollar fs-2">
                            <span class="path1"></span>
                            <span class="path2"></span>
                            <span class="path3"></span>
                        </i>
                    </span>

                    <span class="menu-title">Financeiro</span>

                    <span class="menu-arrow d-lg-none"></span>
                </span>

                <div class="menu-sub menu-sub-lg-down-accordion menu-sub-lg-dropdown p-0">
                    <FinanceiroMenu />
                </div>
            </div>

```
E no `<script setup>` do mesmo ficheiro: acrescentar os imports
```js
import FinanceiroMenu from './menus/FinanceiroMenu.vue'
import { useFinanceiroMenu } from '@/Composables/useFinanceiroMenu'
```
e, junto das outras chamadas e `computed`:
```js
const { seccoesVisiveis: financeiroSeccoesVisiveis } = useFinanceiroMenu()
const financeiroActivo = computed(() => isGroupActive(financeiroSeccoesVisiveis.value.flatMap((s) => s.links)))
```

- [ ] **Step 4: Correr e ver passar; build**

Run: `php artisan test tests/Feature/Arquitectura/MenuFinanceiroTest.php`
Expected: PASS (3 testes).

Run: `npm run build`
Expected: sucesso.

- [ ] **Step 5: Stage**

```bash
git add resources/js/Components/Layout/SidebarMenuWrapper.vue resources/js/Components/Layout/HeaderMenu.vue \
  resources/js/Components/Layout/menus/FinanceiroMenu.vue resources/js/Composables/useFinanceiroMenu.js \
  tests/Feature/Arquitectura/MenuFinanceiroTest.php
```

---

### Task 12: Arquitectura — só o `GerarPropinasService` cria propinas

**Files:**
- Test: `tests/Feature/Arquitectura/CriadoresDePropinaTest.php`

**Interfaces:**
- Consumes: a mesma árvore varrida por `EscritoresDePropinaTest` (`app`, `routes`, `database/seeders` da raiz e de cada módulo).
- Produces: regra "criação de `Propina` (`Propina::create|forceCreate|firstOrCreate|updateOrCreate|firstOrNew|make|insert*|upsert`, `Propina::query()->create…`, `new Propina(…)`, `->propinas()->create|save…`) só em `Modules/Financeiro/app/Services/GerarPropinasService.php`" (D12). F3/F4 não criam propinas (cancelar e reassociar mudam linhas existentes).

- [ ] **Step 1: Escrever o teste**

`tests/Feature/Arquitectura/CriadoresDePropinaTest.php`:
```php
<?php

namespace Tests\Feature\Arquitectura;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Ponto único de criação de propinas (§1.2 do plano de fases): só o GerarPropinasService cria `Propina`.
 * É lá que vivem a elegibilidade, a resolução por competência, o bloqueio da matrícula e dos planos e a
 * verificação de sobreposição; um `Propina::create` noutro sítio contornaria tudo isso. Varre `app`, `routes`
 * e os seeders (raiz e módulos), sem comentários. Não apanha criação por nome de classe dinâmico nem
 * `replicate()`; é uma rede de segurança, não uma prova formal.
 */
class CriadoresDePropinaTest extends TestCase
{
    private const CRIADORES_PERMITIDOS = ['Modules/Financeiro/app/Services/GerarPropinasService.php'];

    private const PADROES = [
        '/\bPropina::(?:query\(\)\s*->\s*)?(?:create\w*|forceCreate\w*|firstOrCreate|updateOrCreate|firstOrNew|make\w*|insert\w*|upsert)\s*\(/',
        '/\bnew\s+\\\\?(?:Modules\\\\Financeiro\\\\Models\\\\)?Propina\s*\(/',
        '/->\s*propinas\s*\(\s*\)\s*->\s*(?:create\w*|forceCreate\w*|firstOrCreate|updateOrCreate|firstOrNew|make\w*|save\w*)\s*\(/',
    ];

    private function raiz(): string
    {
        return dirname(__DIR__, 3);
    }

    private function semComentarios(string $codigo): string
    {
        $saida = '';

        foreach (token_get_all($codigo) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $saida .= is_array($token) ? $token[1] : $token;
        }

        return $saida;
    }

    /** @return list<string> */
    private function criacoes(string $codigo): array
    {
        $achados = [];
        $limpo = $this->semComentarios($codigo);

        foreach (self::PADROES as $padrao) {
            if (preg_match_all($padrao, $limpo, $encontrados) > 0) {
                array_push($achados, ...$encontrados[0]);
            }
        }

        return $achados;
    }

    /** @return array<string, string> caminho relativo => conteúdo */
    private function codigoVarrido(): array
    {
        $pastas = array_filter([
            $this->raiz() . '/app',
            $this->raiz() . '/routes',
            $this->raiz() . '/database/seeders',
            ...glob($this->raiz() . '/Modules/*/database/seeders'),
            ...glob($this->raiz() . '/Modules/*/app'),
            ...glob($this->raiz() . '/Modules/*/routes'),
        ], 'is_dir');
        $ficheiros = [];

        foreach ($pastas as $pasta) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pasta, FilesystemIterator::SKIP_DOTS)) as $ficheiro) {
                if ($ficheiro->isFile() && $ficheiro->getExtension() === 'php') {
                    $ficheiros[str_replace($this->raiz() . '/', '', $ficheiro->getPathname())] = file_get_contents($ficheiro->getPathname());
                }
            }
        }

        return $ficheiros;
    }

    public function test_so_o_gerador_cria_propinas(): void
    {
        $violacoes = [];

        foreach ($this->codigoVarrido() as $relativo => $codigo) {
            if (in_array($relativo, self::CRIADORES_PERMITIDOS, true)) {
                continue;
            }

            foreach ($this->criacoes($codigo) as $excerto) {
                $violacoes[] = "{$relativo}: {$excerto}";
            }
        }

        $this->assertSame([], $violacoes, "Só GerarPropinasService cria propinas:\n" . implode("\n", $violacoes));
    }

    public function test_o_criador_permitido_existe_e_e_apanhado_pelo_varrimento(): void
    {
        $caminho = $this->raiz() . '/' . self::CRIADORES_PERMITIDOS[0];

        $this->assertFileExists($caminho);
        $this->assertNotSame([], $this->criacoes(file_get_contents($caminho)));
    }

    #[DataProvider('exemplos')]
    public function test_o_varrimento_apanha_o_que_deve(string $codigo, bool $criacao): void
    {
        $this->assertSame($criacao, $this->criacoes('<?php ' . $codigo) !== [], $codigo);
    }

    public static function exemplos(): array
    {
        return [
            'create' => ["Propina::create(['matricula_id' => 1]);", true],
            'query()->create' => ['Propina::query()->create([]);', true],
            'forceCreate' => ['Propina::forceCreate([]);', true],
            'firstOrCreate' => ['Propina::firstOrCreate([]);', true],
            'insert' => ['Propina::insert([]);', true],
            'new Propina' => ['$p = new Propina();', true],
            'new Propina com FQN' => ['$p = new \Modules\Financeiro\Models\Propina([]);', true],
            'relação propinas()->create' => ['$plano->propinas()->create([]);', true],
            'relação propinas()->save' => ['$m->propinas()->save($p);', true],
            'leitura' => ["Propina::query()->where('id', 1)->first();", false],
            'PlanoPropina::create' => ['PlanoPropina::create([]);', false],
            'contar propinas do plano' => ['$plano->propinas()->count();', false],
            'new PropinaAjuste' => ['$a = new PropinaAjuste();', false],
            'comentário' => ["// Propina::create([]);\n/* new Propina(); */", false],
        ];
    }
}
```

- [ ] **Step 2: Correr**

Run: `php artisan test tests/Feature/Arquitectura/CriadoresDePropinaTest.php`
Expected: PASS (2 + 14 casos). Se `test_so_o_gerador_cria_propinas` falhar, há um criador fora do gerador: remover (nunca alargar a lista sem decisão do dono).

- [ ] **Step 3: Stage**

```bash
git add tests/Feature/Arquitectura/CriadoresDePropinaTest.php
```

---

### Task 13: Concorrência em PostgreSQL (duas ligações, dados confirmados)

**Files:**
- Create: `tests/PgsqlConcorrenciaTestCase.php`
- Test: `Modules/Financeiro/tests/Feature/Pgsql/GeracaoPropinasPgsqlTest.php`

**Interfaces:**
- Consumes: `Tests\PgsqlTestCase::{baseDeTestePermitida, urlPermitida, motivoParaIgnorar, baseRealPermitida}` (F1, estáticos públicos), `Illuminate\Foundation\Testing\DatabaseTruncation` (`beforeTruncatingDatabase`, `truncateTablesForAllConnections`), T6 (`gerar`).
- Produces: `Tests\PgsqlConcorrenciaTestCase` (abstracta): `DatabaseTruncation`; mesmas guardas do `PgsqlTestCase` (só `mositec_escola_test`, localhost, sem `DB_URL`, grupo `pgsql` obrigatório, ignorado sem as variáveis); ligação `pgsql_concorrente` (cópia da principal) configurada só depois de verificada a base; `outraLigacao(int $esperaMs = 300): Connection` com `SET lock_timeout`; trunca de novo no `tearDown`.
- **Comando:** `DB_CONNECTION=pgsql DB_DATABASE=mositec_escola_test vendor/bin/phpunit --group=pgsql` (D13 de F1).

- [ ] **Step 1: Escrever a base e os testes**

`tests/PgsqlConcorrenciaTestCase.php`:
```php
<?php

namespace Tests;

use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use Throwable;

/**
 * Base dos testes PostgreSQL de CONCORRÊNCIA. Precisam de dados CONFIRMADOS (visíveis a uma segunda
 * ligação), por isso não usam a transacção do RefreshDatabase: usam DatabaseTruncation (migrate:fresh na
 * primeira vez, TRUNCATE nas seguintes) e truncam de novo no fim de cada teste, para não deixar dados aos
 * testes com RefreshDatabase que corram a seguir (que partem de uma base vazia).
 *
 * Mesmas guardas de PgsqlTestCase: só a base `mositec_escola_test`, num servidor local, sem DB_URL;
 * ignorado (skipped) sem as variáveis; grupo `pgsql` obrigatório. Como correr (NÃO `php artisan test`):
 *
 *     DB_CONNECTION=pgsql DB_DATABASE=mositec_escola_test vendor/bin/phpunit --group=pgsql
 */
abstract class PgsqlConcorrenciaTestCase extends TestCase
{
    use DatabaseTruncation;

    public const LIGACAO_CONCORRENTE = 'pgsql_concorrente';

    private bool $baseVerificada = false;

    protected function setUp(): void
    {
        $this->exigirGrupoPgsql();

        if (! PgsqlTestCase::baseDeTestePermitida(getenv('DB_CONNECTION') ?: null, getenv('DB_DATABASE') ?: null)) {
            $this->markTestSkipped('Teste PostgreSQL: corra com DB_CONNECTION=pgsql DB_DATABASE=' . PgsqlTestCase::BASE_DE_TESTE . ' vendor/bin/phpunit --group=pgsql.');
        }

        if (! PgsqlTestCase::urlPermitida(getenv('DB_URL') ?: null)) {
            $this->markTestSkipped('Teste PostgreSQL: DB_URL definido no ambiente; desactive-o (a base efectiva seria a do URL).');
        }

        parent::setUp();
    }

    /**
     * Gancho do DatabaseTruncation, chamado antes do migrate:fresh ou do TRUNCATE: verifica a configuração já
     * carregada e a base REAL a que o PDO se ligou; só então cria a segunda ligação.
     */
    protected function beforeTruncatingDatabase(): void
    {
        $ligacao = config('database.default');
        $motivo = PgsqlTestCase::motivoParaIgnorar(
            $ligacao,
            config("database.connections.{$ligacao}.database"),
            config("database.connections.{$ligacao}.url"),
            config("database.connections.{$ligacao}.host"),
        );

        if ($motivo !== null) {
            $this->markTestSkipped("Teste PostgreSQL: {$motivo}");
        }

        try {
            $baseReal = DB::connection()->selectOne('select current_database() as base')->base ?? null;
        } catch (Throwable $e) {
            $this->markTestSkipped('Teste PostgreSQL: a base ' . PgsqlTestCase::BASE_DE_TESTE . " não responde ({$e->getMessage()}).");
        }

        if (! PgsqlTestCase::baseRealPermitida($baseReal)) {
            DB::disconnect();
            $this->markTestSkipped("Teste PostgreSQL: a base real ligada ({$baseReal}) não é " . PgsqlTestCase::BASE_DE_TESTE . '.');
        }

        config(['database.connections.' . self::LIGACAO_CONCORRENTE => config("database.connections.{$ligacao}")]);
        $this->baseVerificada = true;
    }

    protected function tearDown(): void
    {
        if ($this->baseVerificada && isset($this->app)) {
            DB::purge(self::LIGACAO_CONCORRENTE);
            $this->truncateTablesForAllConnections();
        }

        parent::tearDown();
    }

    /**
     * Uma segunda ligação, independente da principal (outra sessão do PostgreSQL), que desiste de esperar por
     * um bloqueio ao fim de $esperaMs (SQLSTATE 55P03 lock_not_available).
     */
    protected function outraLigacao(int $esperaMs = 300): Connection
    {
        if (! $this->baseVerificada) {
            throw new LogicException('A segunda ligação só existe depois de verificada a base de teste.');
        }

        $ligacao = DB::connection(self::LIGACAO_CONCORRENTE);

        if (! PgsqlTestCase::baseRealPermitida($ligacao->selectOne('select current_database() as base')->base ?? null)) {
            throw new LogicException('A segunda ligação não está na base de teste.');
        }

        $ligacao->statement("SET lock_timeout = '{$esperaMs}ms'");

        return $ligacao;
    }

    private function exigirGrupoPgsql(): void
    {
        foreach ((new ReflectionClass(static::class))->getAttributes(Group::class) as $atributo) {
            if (($atributo->getArguments()[0] ?? null) === 'pgsql') {
                return;
            }
        }

        $this->fail(static::class . " estende PgsqlConcorrenciaTestCase e tem de declarar #[Group('pgsql')].");
    }
}
```

`Modules/Financeiro/tests/Feature/Pgsql/GeracaoPropinasPgsqlTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature\Pgsql;

use Closure;
use Illuminate\Database\QueryException;
use Modules\Financeiro\DTO\PedidoGeracaoPropinasDTO;
use Modules\Financeiro\DTO\ResultadoGeracaoPropinasDTO;
use Modules\Financeiro\Enums\AmbitoGeracaoPropinas;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\GerarPropinasService;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Turma\Models\Turma;
use PHPUnit\Framework\Attributes\Group;
use Tests\PgsqlConcorrenciaTestCase;

/**
 * O que só o PostgreSQL prova na geração (D2, L4, L15): os bloqueios da matrícula e dos planos são
 * mesmo tomados e seguram outra sessão até ao commit; os índices parciais recusam duplicados vindos de
 * outra ligação; uma violação reverte só a matrícula que falhou e a ligação continua utilizável.
 */
#[Group('pgsql')]
class GeracaoPropinasPgsqlTest extends PgsqlConcorrenciaTestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;

    private function gerar(Turma $turma): ResultadoGeracaoPropinasDTO
    {
        return app(GerarPropinasService::class)->gerar(new PedidoGeracaoPropinasDTO(
            ano_lectivo_id: (int) $turma->ano_lectivo_id,
            ambito: AmbitoGeracaoPropinas::TURMA,
            origem: OrigemGeracaoPropina::MANUAL,
            alvo_id: (int) $turma->id,
        ));
    }

    /** SQLSTATE da falha de uma consulta na outra ligação; null se correu. */
    private function sqlstateNaOutraLigacao(string $sql, array $valores): ?string
    {
        try {
            $this->outraLigacao()->select($sql, $valores);

            return null;
        } catch (QueryException $e) {
            return $e->errorInfo[0] ?? (string) $e->getCode();
        }
    }

    /** Corre $verificacao uma vez, quando a geração vai gravar a primeira propina (transacção aberta, bloqueios tomados). */
    private function aoGravarAPrimeira(Closure $verificacao): void
    {
        $feito = false;

        Propina::creating(function () use (&$feito, $verificacao) {
            if ($feito) {
                return;
            }

            $feito = true;
            $verificacao();
        });
    }

    private function contarNaOutraLigacao(int $matriculaId): int
    {
        return (int) $this->outraLigacao()->selectOne('select count(*) as n from propinas where matricula_id = ?', [$matriculaId])->n;
    }

    public function test_a_geracao_bloqueia_a_matricula_ate_ao_commit(): void
    {
        ['turma' => $turma, 'matricula' => $matricula] = $this->cenarioPropinas();
        $bloqueioDaMatricula = 'nao-verificado';
        $this->aoGravarAPrimeira(function () use ($matricula, &$bloqueioDaMatricula) {
            $bloqueioDaMatricula = $this->sqlstateNaOutraLigacao('select id from matriculas where id = ? for update', [$matricula->id]);
        });

        $this->assertSame(10, $this->gerar($turma)->totais()['criadas']);

        $this->assertSame('55P03', $bloqueioDaMatricula, 'Outra sessão devia esperar pelo FOR UPDATE da matrícula (lock_timeout).');
        $this->assertNull($this->sqlstateNaOutraLigacao('select id from matriculas where id = ? for update', [$matricula->id]), 'Depois do commit o bloqueio é libertado.');
        $this->assertSame(10, $this->contarNaOutraLigacao($matricula->id));
    }

    public function test_os_planos_ficam_em_modo_partilhado_a_divisao_espera_outra_geracao_nao(): void
    {
        ['turma' => $turma, 'plano' => $plano] = $this->cenarioPropinas();
        $exclusivo = 'nao-verificado';
        $partilhado = 'nao-verificado';
        $this->aoGravarAPrimeira(function () use ($plano, &$exclusivo, &$partilhado) {
            $exclusivo = $this->sqlstateNaOutraLigacao('select id from planos_propina where id = ? for update', [$plano->id]);
            $partilhado = $this->sqlstateNaOutraLigacao('select id from planos_propina where id = ? for share', [$plano->id]);
        });

        $this->gerar($turma);

        $this->assertSame('55P03', $exclusivo, 'A divisão de um plano (FOR UPDATE, F4) tem de esperar pela geração (L15).');
        $this->assertNull($partilhado, 'Outra geração (FOR SHARE) não fica bloqueada nos planos.');
    }

    public function test_duplicados_de_outra_ligacao_sao_recusados_e_a_segunda_execucao_nao_cria_nada(): void
    {
        ['turma' => $turma, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->gerar($turma);
        $linha = (array) $this->outraLigacao()->selectOne('select * from propinas where matricula_id = ? order by periodo_inicio limit 1', [$matricula->id]);
        unset($linha['id']);

        try {
            $this->outraLigacao()->table('propinas')->insert($linha);
            $this->fail('O índice único parcial devia recusar a segunda propina activa do mesmo período.');
        } catch (QueryException $e) {
            $this->assertSame('23505', $e->errorInfo[0] ?? null);
        }

        $segunda = $this->gerar($turma);

        $this->assertSame(0, $segunda->totais()['criadas']);
        $this->assertSame(10, $segunda->totais()['ignoradas']);
        $this->assertSame(10, $this->contarNaOutraLigacao($matricula->id));
    }

    public function test_uma_violacao_reverte_so_a_matricula_que_falhou_e_a_ligacao_continua_utilizavel(): void
    {
        ['turma' => $turma, 'plano' => $plano, 'matricula' => $primeira] = $this->cenarioPropinas();
        $segunda = $this->matriculaActiva($turma);
        $concorrente = false;
        Propina::creating(function (Propina $propina) use ($plano, $primeira, &$concorrente) {
            if ($concorrente || $propina->matricula_id !== $primeira->id || $propina->ordem !== 2) {
                return;
            }

            $concorrente = true;
            $this->propina($primeira, $plano, 2); // mesma sessão: a geração colide com ela no índice parcial
        });

        $resultado = $this->gerar($turma);

        $this->assertSame(1, $resultado->totais()['erros']);
        $this->assertSame(0, $this->contarNaOutraLigacao($primeira->id), 'Nada da matrícula que falhou ficou confirmado.');
        $this->assertSame(10, $this->contarNaOutraLigacao($segunda->id), 'A matrícula seguinte correu numa transacção nova e confirmou.');

        $this->assertSame(10, $this->gerar($turma)->totais()['criadas']);
        $this->assertSame(10, $this->contarNaOutraLigacao($primeira->id));
    }
}
```

- [ ] **Step 2: Correr sem base PostgreSQL**

Run: `vendor/bin/phpunit --group=pgsql`
Expected: os testes de `GeracaoPropinasPgsqlTest` (e os de F1) **skipped** com "Teste PostgreSQL: corra com …". **Nunca** correr com `DB_DATABASE=mositec_escola`.

Run: `php artisan test Modules/Financeiro/tests/Feature/Pgsql`
Expected: 0 testes executados (grupo excluído por omissão).

- [ ] **Step 3: (Dono, ou executor com a base de teste disponível) correr em PostgreSQL**

Run: `DB_CONNECTION=pgsql DB_DATABASE=mositec_escola_test vendor/bin/phpunit --group=pgsql`
Expected: `PropinaPgsqlTest` (F1) e `GeracaoPropinasPgsqlTest` verdes; a seguir, `DB_CONNECTION=pgsql DB_DATABASE=mositec_escola_test vendor/bin/phpunit --group=pgsql --filter=PropinaPgsqlTest` continua verde (prova de que a truncagem no `tearDown` não deixou dados).

- [ ] **Step 4: Stage**

```bash
git add tests/PgsqlConcorrenciaTestCase.php Modules/Financeiro/tests/Feature/Pgsql/GeracaoPropinasPgsqlTest.php
```

---

### Task 14: Verificação final

- [ ] **Step 1: Suite completa**

Run: `php artisan test` (timeout alargado, ~180 s)
Expected: 0 falhas (o grupo `pgsql` não aparece). Falhas fora do módulo são regressões deste plano (em especial `TenancyArquitecturaTest`, `EscritoresDePropinaTest`, `RelogioDoTenantArquitecturaTest`, `FronteiraMatriculaFinanceiroTest`, `RotasPermissaoReconhecidaTest`, `RotasEscritaAutorizadasTest`, `MatrizIsolamentoTest`): corrigir seguindo a convenção, sem enfraquecer nenhum teste nem alargar listas de excepções, e documentar quais e porquê.

- [ ] **Step 2: Limpeza**

Run:
```bash
grep -rnE "insertOrIgnore|->insert\(|upsert\(|DB::raw|DB::statement|DB::table|withoutEvents|Quietly\(" Modules/Financeiro/app
grep -rnE "\bnow\(\)|\btoday\(\)|Carbon(Immutable)?::(now|today)|new \\\\?DateTime(Immutable)?\(\)" Modules/Financeiro/app
grep -rnE "CancelarPropinaAction|AnularPropinaAction|MatriculaEstadoAlterado|DependenciasDaMatricula|AlterarPrecoPlanoAction|GerarPropinasDoTenantJob" Modules/Financeiro/app Modules/Matricula/app
grep -rn "Modules\\\\Financeiro" Modules/Matricula/app Modules/Matricula/routes
grep -nE "Route::(get|post|put|patch|delete)" Modules/Financeiro/routes/web.php | grep "\[PropinaController::class"
git diff --cached --name-only | grep -E "^Modules/(Matricula|Permissao)/|database/migrations|database/seeders" || true
```
Expected: os quatro primeiros não devolvem nada (sem SQL cru, sem "hoje" fora do relógio, sem peças de F3/F4, Matrícula sem Financeiro); o quinto mostra exactamente 3 rotas (`GET /`, `POST /geracao/pre-visualizar`, `POST /geracao`); o último mostra só `Modules/Permissao/app/Actions/SincronizarPerfisDeSistemaAction.php` (P1) — mais o que **já** estava em stage antes de F2 começar (em 2026-10-11 o trabalho do fuso horário tinha `Modules/Matricula/app/Actions/*` em stage; comparar com a lista tirada antes da Task 1). F2 não mexe em Matricula, migrações nem seeders.

- [ ] **Step 3: Build**

Run: `npm run build`
Expected: sucesso.

- [ ] **Step 4: Estado do git**

Run: `git status --short` e `git diff --cached --stat`
Expected: só ficheiros deste plano (mais os que já estavam em stage antes de começar) em stage, nada gerado (`public/build`), **nenhum commit**.

- [ ] **Step 5: Informar o dono do que lhe cabe**

1. **Nada a migrar nem a semear.** Correr `php artisan financeiro:sincronizar --todos` (P1: concede `propina.ajustar` ao ADMIN_ESCOLA dos tenants existentes e invalida a cache de permissões; idempotente). As restantes permissões de Propinas vêm de F1.
2. **Não usar F2 com dados reais antes de F3** e manter `gerar_automaticamente` **desligado** em produção até lá (ver o aviso no topo).
3. Correr o grupo PostgreSQL: `DB_CONNECTION=pgsql DB_DATABASE=mositec_escola_test vendor/bin/phpunit --group=pgsql` (critério de validação de F2, §8 do plano de fases).
4. Confirmar que o agendador central (`schedule:work` ou cron `schedule:run`) está a correr no ambiente onde a geração automática vier a ser ligada.
5. Verificação visual (login local `admin@mositec.gmail.com`): menu **Financeiro → Propinas** na sidebar e no header (e só com `propina.ver`); Configurações → Financeiro inalterado; gerar para uma turma → pré-visualizar → confirmar → ver a lista e o resumo; gerar de novo → "0 criadas"; filtros por estado, plano, origem, vencimento e pesquisa; badges "Geração tardia"/"Retroactiva".
6. Decisões P1–P5 aprovadas (ver abaixo); confirmar o aviso de Regras de Cobrança (P3) na verificação visual.

---

## Riscos (a ler antes de executar e antes de ligar em produção)

| # | Risco | Mitigação / estado |
|---|---|---|
| R-F2.1 | **Usar F2 antes de F3.** Matrículas canceladas/transferidas/concluídas mantêm as propinas futuras; mudar turma/ano/data de uma matrícula com propinas deixa propinas de outra turma/plano/ano. | Aviso no topo; flag automática desligada por omissão; F3 (evento + `DependenciasDaMatricula`) antes de dados reais. **Aplicado (interruptor de segurança):** `config('financeiro.propinas.geracao_activa')`, alimentado por `FINANCEIRO_GERACAO_PROPINAS` (omissão: `false`). Desligado: `GerarPropinasService::gerar()` recusa (`GeracaoDePropinasDesactivada`), pré-visualização e confirmação HTTP dão 403, o comando fica inerte (aviso + log, código de saída 0 para o agendador diário não falhar à toa), `gerar_automaticamente = true` é rejeitado nas Regras de Cobrança e o frontend esconde «Gerar propinas» com um aviso. Para testar: `FINANCEIRO_GERACAO_PROPINAS=true` no `.env` local e `php artisan config:clear` se a configuração estiver em cache (a suite de testes liga-o em `phpunit.xml`). |
| R-F2.2 | **Geração de todas as competências de uma vez (Q14, L7).** Combinada com §14 "Manter as já geradas" (F4), alunos já matriculados ficam ao preço antigo e novos pagam o novo no mesmo mês e turma. | Decisão do dono (Q14: manter). F4 traz os avisos M1–M4. Aqui só fica registado. |
| R-F2.3 | **Ligar a geração automática a meio do ano** (ou activar uma matrícula com `data_matricula` antiga) cria de uma vez as propinas dos meses já passados, logo **Em Atraso** (spec §5, §7.2). | Comportamento do spec (P3 aprovado). Aviso em Regras de Cobrança junto do interruptor (D18) a recomendar pré-visualizar e gerar manualmente por turma antes de ligar. |
| R-F2.4 | **Desempenho em escolas grandes.** Por matrícula: ~6 consultas (bloqueio, relações, impressão dos planos, propinas activas) + 3 por propina criada (`creating` de F1 lê matrícula e plano). 2 000 matrículas × 10 períodos ≈ 60 000 consultas: aceitável no comando, mas a geração manual de "todas as do plano" pode passar o tempo de um pedido HTTP. | `ResolucaoDePlanosMemorizada` evita o N+1 do resolvedor; detalhe limitado a 200. **P5 aprovado:** limite de 500 matrículas por pedido manual (D17); o comando não tem limite. Se for preciso mais: passar o "gerar" manual a job com notificação (fora de V1). |
| R-F2.5 | **Concorrência não demonstrável em SQLite** (`lockForUpdate`, `sharedLock`, violação vinda de outra sessão). | Task 13 (grupo pgsql com duas ligações). Até o dono a correr, risco aceite como em F1. |
| R-F2.6 | Um plano **novo** criado durante uma geração (não uma divisão) não é bloqueado pelo `sharedLock` (só linhas existentes); a geração pode usar o plano anterior nesse instante. | Idêntico a gerar um segundo antes; a colisão de alvos é validada na criação do plano. Documentado. |
| R-F2.7 | A impressão dos planos não inclui a eliminação (soft delete) de um nível/turno/turma usado num alvo durante a execução. | Efeito limitado a uma execução; a seguinte resolve com o estado novo. |
| R-F2.8 | A regra D1 ("período inteiro do mesmo plano") pode reportar como conflito configurações que "pareciam" funcionar com a leitura "1.º mês" do plano de fases. | É o comportamento seguro (sem cobrança dupla nem cortes); a mensagem diz que período e plano rever. **P4 aprovado.** |
| R-F2.9 | O comando sai com código ≠ 0 se uma única matrícula falhar (mesmo com as outras gravadas). | Intencional (monitorização); a execução seguinte recupera. |

## Decisões/perguntas ainda abertas para o dono

Todas **aprovadas pelo dono** (2026-10-11) e incorporadas no plano:

- **P1** — `propina.ajustar` ao ADMIN_ESCOLA já em F2: `SincronizarPerfisDeSistemaAction` + `PermissoesPropinaTest` + `financeiro:sincronizar --todos` (Task 8, Task 14 Step 5).
- **P2** — Comando síncrono por tenant (D5), sem job.
- **P3** — Geração automática segue o spec §7.2 (desde o mês da matrícula); aviso em Regras de Cobrança junto do interruptor a recomendar pré-visualizar/gerar manualmente primeiro (D18, Task 10 Step 0).
- **P4** — Período com meses de outro plano = conflito, não gerado, motivo no resumo (D1).
- **P5** — Geração manual limitada a 500 matrículas por pedido; acima disso 422 em `ambito` a pedir que se restrinja por turma ou nível (D17, Task 8).

Sem decisões abertas para executar F2.

---

## Tabelas e relações (no fim de F2)

Nenhuma tabela nem coluna nova. Relações usadas pela geração e pela lista:

| Tabela | Estado | Relações / uso em F2 |
|---|---|---|
| `propinas` | existe (F1); passa a ter linhas | N:1 `matriculas` (restrict), N:1 `planos_propina` (restrict), N:1 `ano_lectivos` (snapshot = ano do plano = ano da matrícula), N:1 `tenants`, N:1 `users` (`criado_por`; nulo no comando). Criada **só** por `GerarPropinasService`, com `origem_geracao` MANUAL / COMANDO / RETROACTIVA (+ `motivo_geracao` na retroactiva). Únicos parciais de F1 (estado 1–3): `(tenant_id, matricula_id, plano_propina_id, ordem)` e `(tenant_id, matricula_id, periodo_inicio)` = rede de segurança contra cobrança dupla |
| `matriculas` | existe (Matricula) | 1:N `propinas`; bloqueada `FOR UPDATE` durante a geração de cada matrícula; filtrada por `estado = Activa`, `ano_lectivo_id`, `turma_id`, `numero_registo_matricula`; não conhece o Financeiro |
| `turmas` | existe | N:1 `ano_lectivos`, `niveis_academicos`, `cursos`, `turnos`; decide o plano por competência (`ResolvePlanoAplicavel`) |
| `planos_propina` + `plano_propina_alvos` | existem | 1:N `propinas`; lidos (e as linhas do ano bloqueadas `FOR SHARE`) para resolver o plano mês a mês; impressão (planos + alvos) invalida a memória do resolvedor |
| `regras_cobranca` | existe | `dia_vencimento`, `dias_tolerancia` copiados para cada propina (snapshot); `gerar_automaticamente` liga o comando; nunca referenciada por FK |
| `configuracoes_monetarias`, `cambios`, `cambios_plataforma` | existem | `moeda` e `cambio_usd` do dia da escola copiados (snapshot), via `SnapshotMonetario` |
| `alunos`, `dados_pessoas` | existem | só leitura (nome e nº do aluno na lista, na pré-visualização e na pesquisa) |
| `ano_lectivos` | existe | ano explícito na geração; ano activo como filtro por omissão da lista |

## Decisão: anos lectivos encerrados (aprovada pelo dono)

Só se geram propinas para anos lectivos cujo estado não é `ENCERRADO`: o comando/geração automática ignora as matrículas de anos encerrados; a pré-visualização e a confirmação manuais rejeitam-nos em `ano_lectivo_id` («Não é possível gerar propinas para um ano lectivo encerrado.»); `GerarPropinasService::gerar()` volta a verificar (defesa em profundidade). Anos Activos e Planeados são aceites.
