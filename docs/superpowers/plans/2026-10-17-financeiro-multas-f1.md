# Financeiro → Fase 1: Multas por atraso (aplicação, imputação, visualização)

**Data:** 2026-10-17
**Branch:** `feat/modulo-financeiro`
**Fonte da verdade:** `docs/superpowers/specs/2026-10-08-financeiro-propinas-design.md` §15 (e §4, §6, §8) e `2026-10-08-financeiro-pagamentos-design.md` (§3 `pagamento_multas`, §4 imputação, §5 anulação, §6 crédito a multa).
**Estado actual:** só existe a CONFIGURAÇÃO (`regras_cobranca.multa_activa`, `escaloes_multa`, `Enums/TipoMulta`, `Support/{Multa,PrecosDasMultas}`, `Models/EscalaoMulta`, página RegrasCobranca/Edit). Falta tudo o resto.

## Decisões fechadas (não reabrir)

1. Uma única multa por propina (`unique(tenant_id, propina_id)`), base de capital congelada em L₁, `max()` nunca baixa, estado da propina depende só do capital.
2. Imputação: crédito primeiro, depois dinheiro; dentro de cada propina, capital primeiro e só depois a multa.
3. Aplicação automática por comando diário idempotente; isenção/redução manual com permissão `propina.isentar-multa`.
4. Dinheiro em unidades menores; percentagem em pontos-base (`base × pontos_base / 10000`, arredondar para baixo).

## Interpretações (a confirmar — ver "Perguntas em aberto")

- **I1 — `RecalcularMulta` escreve só `valor_pago` da multa.** O `capital_liquidado_em` continua a ser escrito exclusivamente por `RecalcularPropina` (que o já faz). A referência do enunciado a "valor_pago e capital_liquidado_em" é lida como "o recálculo da multa corre ao lado do recálculo do capital (que refaz o `capital_liquidado_em`)".
- **I2 — `RecalcularPropina` delega em `RecalcularMulta`** (como o §6 manda: "se existir multa, delega em `RecalcularMulta`"). Assim `RegistarPagamentoAction`/`AnularPagamentoAction`/`AplicarCreditoAction`/`AnularUtilizacaoCreditoAction` só precisam de garantir que a propina da multa está no conjunto recalculado; o `AnularPagamentoAction` passa a incluir as propinas das multas distribuídas (`pagamento_multas → propina_multa → propina`) nesse conjunto.
- **I3 — distribuição explícita continua por propina** (`[(propina_id, valor)]`); a divisão capital→multa é sempre automática dentro de cada linha. Pagar uma multa "explicitamente" é dar uma linha cujo valor excede o saldo de capital (o excesso paga a multa, depois de o capital ficar a zero). Não há novo tipo de linha.
- **I4 — sem `permite_pagamento_parcial`, a propina liquida-se por inteiro (capital + multa)** ou não se toca (`break`), nunca se salta uma propina mais antiga. Com parcial, capital primeiro e depois multa (parcial de multa permitido).
- **I5 — `escalao_ordem` avança sempre que um escalão se aplica** (mesmo que um VALOR_FIXO menor não suba o `valor`); a revisão automática regista a transição real (pode ter `valor_anterior == valor_novo`). `tipo`/`valor_regra` guardam o snapshot do escalão em `escalao_ordem`.
- **I6 — sem *lock* por tenant via cache**: `ParaTodosOsTenants` é síncrono (um processo, tenants em série) e o `withoutOverlapping()` do agendador impede dois agendamentos simultâneos; a concorrência intra-tenant é resolvida pelas 4 camadas de §15.5 (índices + `insertOrIgnore`/UPDATE condicional + `lockForUpdate` por propina + idempotência).

---

## Tabelas e relações

### `propina_multas` — colunas EXACTAS do spec §15.2 (sem colunas inventadas)

| Coluna | Tipo / regra |
|---|---|
| `id` | bigint auto |
| `tenant_id` | FK `tenants` (via trait) |
| `propina_id` | FK `propinas`; **`unique(tenant_id, propina_id)`** |
| `escalao_ordem` | tinyint, último escalão aplicado |
| `tipo` | tinyint (TipoMulta: 0=PERCENTAGEM, 1=VALOR_FIXO), snapshot do escalão |
| `valor_regra` | bigint, snapshot do escalão (pontos-base ou unidades menores) |
| `base_capital` | bigint, congelada em L₁ |
| `valor` | bigint, multa vigente |
| `valor_pago` | bigint default 0, cache (`0 ≤ valor_pago ≤ valor`) |
| `estado` | tinyint default 1 (EstadoMulta: 1=ACTIVA, 2=REDUZIDA, 3=ISENTA, 4=ANULADA) |
| `estado_descricao` | string |
| `bloqueada_automatico` | boolean default false |
| `criado_por`, `editado_por` | FK `users` nullable (nulos quando criada pelo comando) |
| timestamps | `created_at`, `updated_at` |

Sem FK para `escaloes_multa` (snapshot). Sem `origem` nem `data_referencia` aqui (esses vivem nas revisões).

### `propina_multa_revisoes` — colunas EXACTAS do spec §15.2 (sem colunas inventadas)

| Coluna | Tipo / regra |
|---|---|
| `id` | bigint auto |
| `tenant_id` | FK `tenants` (via trait) |
| `propina_multa_id` | FK `propina_multas` |
| `origem` | tinyint (0=AUTOMATICA, 1=MANUAL) — **SEM `origem_descricao`** (o spec não a lista) |
| `escalao_ordem` | tinyint nullable (nulo se manual) |
| `valor_anterior` | bigint |
| `valor_novo` | bigint |
| `data_referencia` | date nullable (L_k, para as automáticas) |
| `motivo` | text nullable (obrigatório se manual) |
| `criado_por` | FK `users` nullable (nulo se automática) |
| `created_at` | timestamp — **sem `updated_at`** |

`unique(propina_multa_id, escalao_ordem) WHERE origem = AUTOMATICA` (índice parcial, SQL cru). Só acrescenta (model recusa `update`/`delete`).

### `pagamento_multas`

| Coluna | Tipo / regra |
|---|---|
| `id` | bigint auto |
| `tenant_id` | FK `tenants` (via trait) |
| `pagamento_id` | FK `pagamentos` |
| `propina_multa_id` | FK `propina_multas` |
| `valor` | bigint (> 0) |
| `created_at` | timestamp — sem `updated_at` |

`unique(pagamento_id, propina_multa_id)`; `index(tenant_id, propina_multa_id)`. Imutável (só `create`).

### Alterações a tabelas existentes

| Tabela | Alteração |
|---|---|
| `credito_utilizacoes` (Grupo 2a) | ganha `propina_multa_id` nullable FK → `propina_multas`; `propina_id` passa a nullable; CHECK `propina_id` XOR `propina_multa_id` (pgsql) |
| `propinas` | ganha só a relação `multa()` (sem coluna) |
| `pagamentos` | ganha só a relação `multas()` (sem coluna) |

Invariantes novas:
- `pagamento.valor = Σ pagamento_propinas + Σ pagamento_multas + crédito gerado`.
- `propina_multa.valor_pago = Σ pagamento_multas (Confirmados) + Σ credito_utilizacoes (Activas, propina_multa_id)`; `0 ≤ valor_pago ≤ valor`.
- `propina_multa.propina.matricula_id == pagamento.matricula_id`; mesmo tenant.
- multa só recebe dinheiro com o capital da propina a zero (garantido pelo motor).

---

## Tarefas (ordem de implementação)

### Grupo 0 — Fundação: enums, migrações, models

- **T0.1** `Enums/EstadoMulta.php` — `ACTIVA=1, REDUZIDA=2, ISENTA=3, ANULADA=4`, `label()`, `opcoes()`.
- **T0.2** `Enums/OrigemRevisaoMulta.php` — `AUTOMATICA=0, MANUAL=1`, `label()`, `opcoes()`.
- **T0.3** Migrações (nesta ordem; pgsql: CHECK + triggers de imutabilidade como nas tabelas irmãs):
  1. `2026_10_17_100500_create_propina_multas_table.php`
  2. `2026_10_17_100600_create_propina_multa_revisoes_table.php`
  3. `2026_10_17_100700_create_pagamento_multas_table.php`
  *(a alteração de `credito_utilizacoes` fica no Grupo 2a — condição b)*
- **T0.4** `Models/PropinaMulta.php` — `PertenceAoTenant` + `RegistaAutoria`; `$casts` (DinheiroCast/DataCast); relações `propina()`, `revisoes()`, `distribuicoes()`; `pendente() = valor − valor_pago`; hook `saving` próprio para `estado_descricao` (decisão 1); validação de coerência (CHECK parity, ver D1 abaixo).
- **T0.5** `Models/PropinaMultaRevisao.php` — `PertenceAoTenant`; só `create` (recusa `update`/`delete`, como `PropinaAjuste`); relações `multa()`, `criadoPor()`; sem coluna `origem_descricao` (condição a).
- **T0.6** `Models/PagamentoMulta.php` — `PertenceAoTenant`; imutável (como `PagamentoPropina`); relações `pagamento()`, `multa()`; validação de criação (matrícula coincidente, valor > 0).
- **T0.7** Alterar `Models/Propina.php`: relação `multa(): HasOne` (PropinaMulta) e helper `multaPendente(): int`.
- **T0.8** Alterar `Models/Pagamento.php`: relação `multas(): HasMany` (PagamentoMulta).
- **Testes (TDD primeiro):** `tests/Feature/PropinaMultaModelTest.php`, `PropinaMultaRevisaoModelTest.php`, `PagamentoMultaModelTest.php`, `tests/Unit/EstadoMultaTest.php`, `tests/Unit/OrigemRevisaoMultaTest.php`.

**D1 — estado_descricao (a resolver):** o enunciado pede "trait `SincronizaEstadoDescricao`/`RegistaAutoria` do Core". `SincronizaEstadoDescricao` está acoplado a `Modules\Core\Enums\Estado` (ATIVO/INATIVO) e **não serve** para um enum próprio (`EstadoMulta`/`OrigemRevisaoMulta`). O padrão real do módulo é o *hook* `saving` null-safe por model (ver `Propina`, `Pagamento`, `EscalaoMulta`). **Recomendação:** seguir o padrão do módulo (hook `saving` próprio no model, com `?->label()`), e usar `RegistaAutoria` (reaproveitada) para autoria. Isto é uma divergência deliberada do enunciado — ver "Perguntas em aberto".

### Grupo 1 — `RecalcularMulta` (ponto único do `valor_pago` da multa)

- **T1.1** `Services/RecalcularMulta.php`, análogo a `RecalcularCredito`: `executar(PropinaMulta $multa)` bloqueia a multa (`lockForUpdate`), soma `PagamentoMulta` de pagamentos Confirmados + `CreditoUtilizacao` Activas (`propina_multa_id`), valida `0 ≤ valor_pago ≤ valor`, grava. Não altera `estado` (ACTIVA/REDUZIDA/ISENTA não dependem do pago).
- **T1.2** Alterar `Services/RecalcularPropina.php`: no fim de `recalcular()` (ponto `F5`), se `$bloqueada->multa` existe, delegar em `RecalcularMulta`. Injetar `RecalcularMulta`.
- **Testes:** `tests/Feature/RecalcularMultaTest.php` (valor_pago = Σ fontes; anular pagamento reabre; CHECK; multa de propina cancelada/anulada).

### Grupo 2a — `credito_utilizacoes` com XOR (migração + model; suíte existente toda verde)

- **T2a.1** Migração `2026_10_17_100800_add_propina_multa_id_to_credito_utilizacoes_table.php`: acrescenta `propina_multa_id` nullable FK → `propina_multas`; `propina_id` passa a nullable (drop da FK existente e recria nullable); CHECK pgsql `(propina_id IS NOT NULL) <> (propina_multa_id IS NOT NULL)`; index `(tenant_id, propina_multa_id)`.
- **T2a.2** Alterar `Models/CreditoUtilizacao.php`: `propina_multa_id` (cast/fillable), validação XOR na criação, relação `multa()`.
- **T2a.3** Ajustar `Support/PagamentosDaPropina.php` para continuar a contar **só** utilizações com `propina_id` (capital) — garante que o `valor_pago` de capital não é afectado.
- **T2a.4** Actualizar `RecalcularMulta` (do Grupo 1) para somar também `CreditoUtilizacao` Activas com `propina_multa_id` (contribuição de crédito à multa).
- **Testes:** `tests/Feature/CreditoUtilizacaoMultaModelTest.php` (XOR, imutável, relação); correr a suíte completa e garantir que nada regrediu (o `PagamentosDaPropina` é o ponto sensível).

### Grupo 2b — Motor de distribuição (capital → multa) + Actions

- **T2b.1** Estender `DTO/PlanoDePagamentoDTO.php`: cada linha passa a levar `capital_saldo_antes`, `multa_pendente_antes`, e a divisão `credito`/`dinheiro` por capital e por multa; novos métodos `distribuicoesMulta()` (para `pagamento_multas`) e `utilizacoes` com alvo `propina_id` XOR `propina_multa_id`; invariante `dinheiro = Σ capital+multa (dinheiro) + excedente`.
- **T2b.2** Alterar `Services/DistribuicaoDePagamentoService.php`:
  - `propinasPagaveis()` passa a incluir propinas com multa pendente (mesmo `PAGA`), mantendo a exclusão de Cancelada/Anulada.
  - `calcular()` ordena por período/ordem; crédito primeiro; por propina: cobrir capital, depois (capital a zero) multa; monta `linhas`, `distribuicoes`, `distribuicoesMulta`, `utilizacoes` (capital e multa).
  - `automatica()` e `explicita()` respeitam I3/I4 (sem parcial = capital+multa inteiros; com parcial = capital primeiro, depois multa; multa só depois de o capital estar a zero; nunca saltar propina mais antiga).
  - Validações da `explicita()`: `valor ≤ capital_saldo + multa_pendente`; sem parcial `valor === capital+multa`.
- **T2b.3** Alterar `Actions/RegistarPagamentoAction.php`: gravar `PagamentoMulta` (das `distribuicoesMulta`) e `CreditoUtilizacao` com `propina_multa_id` (quando o alvo for multa); manter a ordem de bloqueio (matrícula → propinas → créditos); o `RecalcularPropina` (via delegação) fecha as caches.
- **T2b.4** Alterar `Actions/AplicarCreditoAction.php`: escrever utilizações com `propina_multa_id` quando o alvo for multa.
- **T2b.5** Alterar `Actions/AnularPagamentoAction.php`: o conjunto de propinas a recalcular inclui as propinas das multas distribuídas; (a delegação de `RecalcularPropina` trata do `valor_pago` da multa). Bloquear também as multas (via propina já bloqueada).
- **T2b.6** Alterar `Actions/AnularUtilizacaoCreditoAction.php`: quando a utilização aponta a multa, recalcular a multa da propina correspondente (a propina continua a ser recalculada).
- **Testes:** `tests/Feature/DistribuicaoDePagamentoMultaTest.php`; atualizar `DistribuicaoDePagamentoTest.php`, `RegistarPagamentoTest.php`, `PagamentosIntegracaoPropinasTest.php`, `AplicarCreditoTest.php`, `AnularPagamentoTest.php`, e `ComPagamentosFinanceiro::assertPagamentosCoerentes()` (passa a reconciliar `pagamento_multas` e `propina_multas.valor_pago`).

### Grupo 3 — Aplicação automática (comando idempotente)

- **T3.1** `Services/AplicarMultasService.php` — `executar(): array{criadas, escaloes_subidos, ignoradas}`. Por propina candidata (não Cancelada/Anulada, com `data_limite + min(dias_atraso) ≤ hoje`), **uma transacção por propina** com `lockForUpdate` (ordem do `BloqueioDePropinas`): calcula `L_k = data_limite + dias_atraso_k`, `saldo_em(d) = valor − Σ contribuições (Confirmadas/Activas) com data < d` (via `FontesDePagamentoDePropina::contribuicoes`), aplica escalões (parar no primeiro `saldo_em(L_k) = 0`), `base_capital = saldo_em(L₁)` na criação, `valor_k` por `Dinheiro::pontosBase` (percentagem) ou `valor_regra` (fixo), `valor = max(valor_atual, valor_k)`.
  - Criação: `insertOrIgnore` (com `estado_descricao` explícito; `criado_por`/`editado_por` nulos), só se há escalão aplicável; revisões AUTOMATICA por cada escalão aplicado (também `insertOrIgnore`).
  - Subida: `UPDATE propina_multas SET escalao_ordem = k, valor = ..., tipo = ..., valor_regra = ... WHERE id = ? AND escalao_ordem < k AND NOT bloqueada_automatico`; só se `affected = 1` escreve a revisão AUTOMATICA.
- **T3.2** `Support/Dinheiro.php`: acrescentar `pontosBase(int $pontosBase): self` (`intdiv(unidades × pontosBase, 10000)`), precedente de `percentagem()`.
- **T3.3** `Console/AplicarMultasCommand.php` (`ParaTodosOsTenants`, assinatura `financeiro:aplicar-multas`), delega no serviço, regista `Log::info` + `line` (padrão do `GerarPropinasCommand`). Sem `multa_activa` ou sem escalões → nada a fazer.
- **T3.4** Registar o comando em `Providers/FinanceiroServiceProvider.php::$commands` e agendar em `routes/console.php`: `Schedule::command('financeiro:aplicar-multas --todos')->dailyAt('02:30')->withoutOverlapping();` (depois do `gerar-propinas` das 02:00).
- **Testes:** `tests/Feature/AplicarMultasTest.php` (percentagem, fixo, arredondamento, base congelada, max não baixa, capital pago antes/depois de L₂, catch-up, diferença com parcial, revisões imutáveis, regras alteradas não mexem, `multa_activa` desligada, isolamento tenant), `tests/Feature/AplicarMultasCommandTest.php` (2× idempotente), `tests/Unit/DinheiroPontosBaseTest.php`. Nova `tests/Concerns/ComMultasFinanceiro.php`.

### Grupo 4 — Cancelar/anular propina com multa (pontos F5)

- **T4.1** `Support/CancelamentoDePropinas.php`: em `motivoParaNaoCancelar`, bloquear se a propina tem multa com pagamentos de multa Confirmados (ou utilizações de crédito Activas na multa); em `cancelar`, se existir multa sem pagamentos, marcá-la `ANULADA` (mesma transacção).
- **T4.2** `Support/AnulacaoDePropinas.php`: idem em `motivoParaNaoAnular` e `anular`.
- **Testes:** `tests/Feature/CancelarAnularPropinaComMultaTest.php`.

### Grupo 5 — Isenção/redução manual

- **T5.1** `DTO/IsentarMultaDTO.php` (`novo_valor` em unidades menores, `motivo` obrigatório; `novo_valor == valor_pago` ⇒ ISENTA, senão REDUZIDA; `valor_pago ≤ novo_valor < valor_atual`).
- **T5.2** `Actions/IsentarReduzirMultaAction.php`: bloqueia a propina e a multa; valida; escreve `valor`, `estado` (REDUZIDA/ISENTA), `bloqueada_automatico = true`; grava `PropinaMultaRevisao` MANUAL (motivo, `criado_por`).
- **T5.3** `Http/Requests/IsentarReduzirMultaRequest.php` (com `ValorMonetario`/motivo) e endpoints no `PropinaController` (`patch /financeiro/propinas/{propina}/multa`) com `can:propina.isentar-multa`; rota em `routes/web.php`.
- **T5.4** Permissão: acrescentar `'isentar-multa'` em `Modules/Permissao/app/Enums/Modulo.php::acoesAplicaveis()` (PROPINA) **e** em `Modules/Permissao/app/Actions/SincronizarPerfisDeSistemaAction.php::PERMISSOES_POR_PERFIL[ADMIN_ESCOLA][PROPINA]` (a `Acao` `isentar-multa` nº 11 já existe no `AcaoSeeder`).
- **Testes:** `tests/Feature/IsentarReduzirMultaHttpTest.php`; atualizar `PermissoesPropinaTest.php` e `RolePermissaoSeederTest.php`.

### Grupo 6 — Leitura + Vue

- **T6.1** `Services/PropinaConsultaService.php`: `linha()` e `detalhe()` devolvem, por propina, `multa` (`valor`, `valor_pago`, `pendente`, `estado`, `bloqueada_automatico`) e `total_em_divida = saldo + multa_pendente`; `saldo` mantém-se = capital.
- **T6.2** `Services/PagamentoConsultaService.php`: `situacao()` devolve `multa_pendente`/`total` por propina; `detalhe()` inclui `pagamento.multas` (distribuição a multas).
- **T6.3** Vue (reaproveitar `bg-body-secondary`, `EstadoBadge`/`EstadoCobrancaBadge`, `formatarDinheiro`):
  - `Pages/Propinas/Index.vue`: coluna/badge "Multa" (pendente) no saldo.
  - `Pages/Propinas/Show.vue`: cartão "Multa" (vigente, pago, pendente, estado, revisões) + botão/modal `IsentarMultaModal.vue` (novo, `Components/Propinas/`).
  - `Pages/Pagamentos/Create.vue` + `Components/Pagamentos/DistribuicaoResumo.vue`: mostrar capital, multa e total por propina.
  - `Pages/Pagamentos/Show.vue`: secção "Distribuição por multas".
- **Testes:** atualizar `Unit/DetalhePropinaVueTest.php`, `Unit/PagamentosVueTest.php`, `PagamentoConsultaTest.php`, `PropinaListaTest.php`, `PropinaDetalheTest.php`.

### Grupo 7 — Concorrência PostgreSQL

- **T7.1** `tests/Feature/Pgsql/MultaConcorrenciaPgsqlTest.php` (grupo `pgsql`, `PgsqlConcorrenciaTestCase` + `ComProcessosConcorrentes`, com nova operação filho `aplicar-multas`): duas execuções simultâneas de `AplicarMultasService` sobre a mesma propina não criam multa duplicada nem revisão duplicada (valida `insertOrIgnore` + UPDATE condicional); `assertCoerente` por SQL directo.
- **Testes:** reutilizar/estender `ComProcessosConcorrentes.php` (nova operação) e o padrão de `PagamentoConcorrenciaPgsqlTest`.

---

## Ordem de execução (grupos com `git add` no fim de cada)

1. Grupo 0 (fundação) + Grupo 1 (RecalcularMulta) + testes → `git add` (ESTA entrega)
2. Grupo 2a (credito_utilizacoes XOR) + testes → `git add`
3. Grupo 2b (motor + Actions) + testes → `git add`
4. Grupo 3 (comando) + testes → `git add`
5. Grupo 4 (F5) + Grupo 5 (isenção) + testes → `git add`
6. Grupo 6 (leitura/Vue) + testes → `git add`
7. Grupo 7 (pgsql) → `git add`

Cada relatório lista: ficheiros tocados, testes a passar, desvios ao spec e, no fim, as tabelas envolvidas e relações.

## Riscos

- **Alteração do `credito_utilizacoes`** (`propina_id` → nullable + XOR) é a mudança mais invasiva: toca em model, migração, `PagamentosDaPropina` (que lê `credito_utilizacoes.propina_id`), `AnularUtilizacaoCreditoAction`, `PagamentoConsultaService`, e pode partir testes existentes. Requer cuidado na ordem e cobertura.
- **`SincronizaEstadoDescricao` do Core não serve** para enums próprios (acoplado a `Estado` ATIVO/INATIVO) — ver D1.
- **Suíte em SQLite ignora `lockForUpdate`** e os CHECK/triggers pgsql: a paridade é repetida nos models; concorrência real só no grupo 7.
- **Datas:** `L_k`/`saldo_em` usam comparação por `toDateString()` (dias civis), consistente com `DataCast`/`RelogioDoTenant`.
- **Evolução do `PlanoDePagamentoDTO`** muda o contrato da pré-visualização: atualizar `DistribuicaoResumo.vue`, `Create.vue` e os testes Vue no mesmo grupo.

## Perguntas em aberto (para aprovar antes de implementar)

1. **D1 / estado_descricao:** confirmar que sigo o padrão do módulo (hook `saving` próprio + `?->label()`) em vez da trait `SincronizaEstadoDescricao` do Core (que está acoplada a `Estado` ATIVO/INATIVO e não se aplica a `EstadoMulta`/`OrigemRevisaoMulta`). Alternativa: generalizar a trait do Core (fora do âmbito do módulo).
2. **I4 (sem parcial):** confirmar que uma propina se liquida **por inteiro (capital + multa)** ou não se toca (`break`), em vez de "pagar só o capital e deixar a multa".
3. **I5 (revisão com valor inalterado):** confirmar que um escalão que não sobe o `valor` (ex.: VALOR_FIXO menor que o vigente) ainda avança `escalao_ordem` e grava uma revisão AUTOMATICA (com `valor_anterior == valor_novo`).
4. **Migração de `credito_utilizacoes`:** nova migração ALTER (recomendado, não mexe nas já criadas) vs editar a `2026_10_17_100400` existente na branch.
5. **I6 / *lock* por tenant:** confirmar que não é preciso *lock* de cache por tenant (bastam `withoutOverlapping()` global + camadas de §15.5). O plano antigo (fases) mencionava um `AplicarMultasDoTenantJob` com `UnicoPorTenant`, que a decisão D5 do F2 tornou obsoleto (comandos síncronos por tenant).
6. **Ponto único da escrita do `valor` da multa:** confirmar que `RecalcularMulta` escreve só `valor_pago`, e o `valor`/`estado`/`bloqueada_automatico` são escritos apenas pelo comando (AUTOMATICA) e pela `IsentarReduzirMultaAction` (MANUAL) — o `RecalcularMulta` nunca sobe/desce o `valor`.
7. **Valor 0 nos pontos-base:** confirmar `intdiv` (arredondar para baixo) e, se um escalão percentual resultar em 0 com base pequena, a multa é criada com `valor = 0`? (o CHECK `0 ≤ valor_pago ≤ valor` permite 0, mas a criação só deve ocorrer se há escalão aplicável com `saldo_em(L₁) > 0`). Recomendo: criar só se `valor > 0`; se `valor_k = 0`, ignorar esse escalão (não criar multa a 0).
