# Financeiro → Propinas — F3: Detalhe, cancelar e protecção das matrículas — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> ⚠️ **O interruptor de segurança `financeiro.propinas.geracao_activa` (R-F2.1) CONTINUA LIGADO À PROTECÇÃO durante todo este plano.** As Tasks 1–15 completam a protecção das matrículas; a **Task 16** remove o interruptor e **só se executa com autorização expressa do dono**, depois de ele validar F3 na app e em PostgreSQL. Nenhum executor (humano ou agente) a corre por iniciativa própria, nem como parte de "verificação final".

**Goal:** Ver uma propina em detalhe; cancelá-la com motivo (só Em Aberto, sem pagamentos nem histórico); fazer a Matrícula emitir `MatriculaEstadoAlterado` e consultar `DependenciasDaMatricula` **sem depender do Financeiro**; cancelar automaticamente, na mesma transacção, as propinas futuras sem pagamentos quando a matrícula passa a Cancelada, Transferida ou Concluída (Q5, Q6); bloquear a mudança directa de turma, ano lectivo ou mês da data de matrícula de uma matrícula com propinas (Q8); gerar as propinas ao activar a matrícula, depois do commit e sem nunca a bloquear (Q7); e uma rede de segurança diária que reconcilia o que um listener tenha deixado por fazer.

**Architecture:** A escrita de "Cancelada" tem um **ponto único**, `Support\CancelamentoDePropinas` (pré-condições §8 + escrita), usado pelo cancelamento manual (`CancelarPropinaAction`), pelo cancelamento por estado da matrícula (`CancelarPropinasFuturasDaMatricula`) e pela reconciliação diária; um teste de arquitectura novo (`CanceladoresDePropinaTest`) garante que mais ninguém escreve `cancelado_*`/estado 4. A Matrícula ganha um evento de factos com primitivos (`Events\MatriculaEstadoAlterado`), **despachado dentro** da transacção de `AlterarEstadoMatriculaAction` (agora transaccional, com `lockForUpdate` e validação sobre a linha bloqueada) e de `CriarMatriculaAction`; e um contrato próprio (`Contracts\DependenciasDaMatricula`, etiqueta `matricula.dependencias`) consultado por `AtualizarMatriculaAction` sob bloqueio. O Financeiro regista em `FinanceiroServiceProvider::boot()` dois listeners: `CancelarPropinasAoTerminarMatricula` (**síncrono**, corre na transacção da matrícula — Q5: uma falha reverte a mudança de estado) e `GerarPropinasAoActivarMatricula` (`ShouldHandleEventsAfterCommit` — Q7: depois do commit, falhas registadas e nunca propagadas); e implementa o contrato com `Support\PropinasDaMatricula`. A geração automática (comando e activação) deixa de recriar períodos cancelados; o comando diário passa a reconciliar sempre os cancelamentos em falta antes de gerar. Ordem de bloqueio única: **matrícula → propinas por id crescente**; o cancelamento manual só bloqueia a propina (sem ciclo). Datas de negócio sempre de `RelogioDoTenant::hoje()` (§8.2b).

**Tech Stack:** Laravel 12.69 + nwidart/laravel-modules, Inertia + Vue 3, PHPUnit 11.5 com SQLite em memória (produção PostgreSQL), Metronic/Bootstrap.

**Fontes de verdade:** `docs/superpowers/plans/2026-10-12-financeiro-propinas-fases.md` (F3; §1.1–§1.3; §3 Q5, Q6, Q7, Q8, Q10 — todas **aprovadas**; §4 L10, L11, L12; §8 e §8.1; §8.2 critérios 2 "cancelar ≠ anular" e 3 "geração recuperável"; §8.2b), `docs/superpowers/plans/2026-10-13-financeiro-propinas-f1.md` (D4: `HistoricoDePagamentosDaPropina` fica para F3), `docs/superpowers/plans/2026-10-14-financeiro-propinas-f2.md` (R-F2.1 e o interruptor de segurança), `docs/superpowers/plans/2026-10-12-trancamento-matricula-plano.md` (D1, D14: o evento tem de tolerar estados futuros), `docs/superpowers/specs/2026-10-08-financeiro-propinas-design.md` (§3, §8, §9, §10, §11, §12) e o **código real** (`Propina`, `RecalcularPropina`, `BloqueioDePropinas`, `FontesDePagamentoDePropina`, `GerarPropinasService`, `PedidoGeracaoPropinasDTO`, `PropinaConsultaService`, `PropinaController`, `GerarPropinasCommand`, `GeracaoDePropinasDesactivada`, `config/config.php`, `ComPropinasFinanceiro`, `EscritoresDePropinaTest`, `CriadoresDePropinaTest`, `FronteiraMatriculaFinanceiroTest`, `RelogioDoTenantArquitecturaTest`, `MenuFinanceiroTest`, `PgsqlConcorrenciaTestCase`; `Matricula`, `EstadoMatriculaEnum`, `AlterarEstadoMatriculaAction`, `CriarMatriculaAction`, `AtualizarMatriculaAction`, `RenovarMatriculaAction`, `RenovarMatriculasEmMassaAction`, `GestaoMatriculaService`, `MatriculaActionTest`; `AnoLectivo\Contracts\DependenciasDoAnoLectivo` + `DependenciasRegistadasDoAnoLectivo` como padrão do contrato; `vendor/laravel/framework/src/Illuminate/Events/Dispatcher.php` linhas 290 e 600–622 para o comportamento de `ShouldHandleEventsAfterCommit`).

## Restrições globais

- Texto de UI, mensagens e comentários em PT-PT com acentos; identificadores, rotas, tabelas e variáveis sem acento.
- Camada fina: Controller → Service (leitura) / Action (escrita), com DTO e FormRequest. `use` no topo, nunca FQN inline (também em testes).
- Dinheiro em unidades menores (`Dinheiro`, `DinheiroCast`); a propina nunca é recalculada em leitura a partir do plano.
- Tenancy: nenhum model nem tabela novos; tudo passa por models com `PertenceAoTenant`; `tenant_id` nunca vem do cliente; propina/matrícula de outro tenant → 404 (route model binding com `TenantScope`).
- **Proibido** em `Modules/*/app` (`TenancyArquitecturaTest`): `insert`, `insertOrIgnore`, `insertGetId`, `upsert`, `DB::table|raw|statement|…`, `*Quietly`, `withoutEvents`, `withoutGlobalScope(s)`. `DB::transaction` e `DB::transactionLevel` são permitidos (F2 já os usa).
- **"Hoje" de negócio só por `RelogioDoTenant::hoje()`** (e `agora()` para instantes) em `Modules/Financeiro/app` e nos ficheiros de Matrícula já vigiados por `RelogioDoTenantArquitecturaTest` (`CriarMatriculaAction`, `AlterarEstadoMatriculaAction`, `RenovarMatriculaAction`, …). Lista de excepções vazia — não a alargar.
- `valor_pago`, `capital_liquidado_em` e `estado` 2/3 continuam só no `RecalcularPropina` (`EscritoresDePropinaTest`); a criação continua só no `GerarPropinasService` (`CriadoresDePropinaTest`). **Novo:** `estado` 4 (Cancelada), `motivo_cancelamento`, `cancelado_por` e `cancelado_em` só no `CancelamentoDePropinas` (`CanceladoresDePropinaTest`, Task 5).
- **A Matrícula nunca importa `Modules\Financeiro\`** (`FronteiraMatriculaFinanceiroTest`). Matricula só conhece o seu evento e o seu contrato; sem o Financeiro funciona igual.
- Dentro de `DB::transaction` não se apanham excepções para continuar (PostgreSQL aborta a transacção). Conversões de erro só **à volta** da transacção. Efeitos fora da BD (geração ao activar) só depois do commit.
- `migrate`, `db:seed --force`, `financeiro:sincronizar --todos`, a base `mositec_escola_test` e o `schedule:work`/cron são do dono. **Nunca correr nada contra a base de desenvolvimento `mositec_escola`.**
- **Git: só `git add`. Nunca `git commit`.**

## Decisões e pressupostos desta fase (ler antes de implementar)

| # | Decisão | Porquê |
|---|---|---|
| D1 | **Mecanismo de Q5 (dentro da transacção) sem acoplar a Matrícula:** `Modules\Matricula\Events\MatriculaEstadoAlterado` (primitivos: `matriculaId`, `estadoAnterior ?int`, `estadoNovo int`, `dataEfeito Y-m-d`, `utilizadorId ?int`) é despachado **dentro** da transacção da Matrícula e **não** implementa `ShouldDispatchAfterCommit`. O Financeiro regista **explicitamente** em `FinanceiroServiceProvider::boot()` (`Event::listen`) dois listeners: `CancelarPropinasAoTerminarMatricula` (síncrono → corre na transacção; qualquer excepção reverte a mudança de estado) e `GerarPropinasAoActivarMatricula` (`ShouldHandleEventsAfterCommit` → o `Dispatcher` embrulha-o em `TransactionManager::addCallback`, verificado em `Dispatcher.php:600–622`; corre depois do commit da transacção mais exterior, também com `RefreshDatabase`). | O spec §9 fixa a fronteira por evento e o plano de fases (F3) manda o cancelamento correr na transacção (Q5 aprovado). Um contrato etiquetado à parte duplicaria o evento. O evento não é enfileirado: os listeners correm no mesmo pedido, com o contexto de tenant já resolvido (por isso não leva `tenant_id`; se um dia for enfileirado, acrescenta-se). Risco: um teste que faça `Event::fake()` sem lista desliga também o cancelamento — `ListenersDaMatriculaTest` (Task 8) prova o registo e as tarefas usam sempre `Event::fake([...])` com lista ou nenhum fake. |
| D2 | **Ponto único de cancelamento:** `Support\CancelamentoDePropinas::motivoParaNaoCancelar(Propina): ?string` e `cancelar(Propina $bloqueada, string $motivo, ?int $utilizadorId): Propina` (exige transacção aberta e a propina bloqueada pelo chamador). Novo `CanceladoresDePropinaTest`. `EscritoresDePropinaTest` e `CriadoresDePropinaTest` **não mudam** (o cancelamento escreve `estado` 4, que não é cache, e não cria propinas) — a Task 5 acrescenta-lhes só casos ao *data provider* que o provam. | §1.2 do plano de fases (pontos únicos). Três chamadores (manual, evento, reconciliação) com as mesmas regras e a mesma escrita. |
| D3 | **Cancelável** (§8, §8.2/2 "cancelar ≠ anular") = estado **persistido** Em Aberto (inclui os que se resolvem Pendente ou Em Atraso), `valor_pago = 0`, **sem contribuições** (`FontesDePagamentoDePropina`) e **sem histórico de pagamentos** (`HistoricosDePagamentoDaPropina`, D4). Motivo obrigatório, 5–500 caracteres depois de `trim`. Mensagens distintas por razão (Parcialmente Paga/Paga: "anule primeiro os pagamentos"; histórico: "use Anular (Pagamentos)"; já Cancelada/Anulada: terminal). | §8 e §8.2/2. Anular continua fora (Pagamentos-A, L12). |
| D4 | **`Contracts\HistoricoDePagamentosDaPropina::temHistorico(Propina): bool`** (qualquer pagamento ou utilização de crédito que alguma vez tocou a propina, em qualquer estado) + agregador `Support\HistoricosDePagamentoDaPropina` (etiqueta `financeiro.historicos-pagamento-propina`). Sem implementações até Pagamentos-A (nada etiquetado → `false`). Testes usam `HistoricoPagamentoFalso`. | F1 D4 adiou-o para F3, que é o primeiro consumidor. |
| D5 | **Ordem de bloqueio:** matrícula (`lockForUpdate`) → propinas por id crescente (`BloqueioDePropinas`). A mudança de estado, a reconciliação e a geração bloqueiam a matrícula primeiro; o **cancelamento manual bloqueia só a propina** (nunca a matrícula), portanto não há ciclo. Depois do bloqueio, tudo é relido e reavaliado (um cancelamento concorrente já confirmado é ignorado). | Spec §3/§6; F2 D2; trancamento D13. |
| D6 | **`AlterarEstadoMatriculaAction` transaccional:** `DB::transaction` → relê a matrícula com `lockForUpdate` → valida a transição **sobre a linha bloqueada** (um model desactualizado não passa) → grava → histórico → evento. `dataEfeito` = `data_fim` gravada (terminais) ou hoje da escola (restantes). **O1 (aprovado pelo dono):** `data_fim` validada sobre a linha bloqueada — entre `data_matricula` e `RelogioDoTenant::hoje()`; Concluída aceita futura até ao fim do ano lectivo; fora disso `ValidationException` em `data_fim` (Task 6). | L10. A transacção é exigida por Q5 (estado + histórico + cancelamentos: tudo ou nada). |
| D7 | **`CriarMatriculaAction`** despacha o evento sempre que cria uma matrícula (`estadoAnterior = null`, `dataEfeito = data_matricula`), dentro da sua transacção (é o mesmo facto que o histórico regista). Só `ACTIVA` interessa ao Financeiro (Q7). | Fases F3: "evento também na criação". |
| D8 | **Q6 aprovado:** Cancelada, Transferida **e Concluída** cancelam as propinas da matrícula com `periodo_inicio > dataEfeito` canceláveis (D3); as restantes ficam como dívida. Motivo: "Matrícula {cancelada \| transferida \| concluída} em dd/mm/aaaa (estado da matrícula)."; `cancelado_por = utilizadorId` (nulo em comandos). Idempotente (a 2.ª execução não encontra nada). Estados desconhecidos (ex.: Trancada, futura) são ignorados. | Spec §9, §8.1/Q6, trancamento D14/§189. |
| D9 | **Q7 aprovado:** ao passar a Activa (criação já Activa ou Pendente → Activa), **depois do commit**, só se o interruptor de segurança estiver ligado e `regras_cobranca.gerar_automaticamente = true` (lido com `RegraCobranca::query()->first()`, nunca `doTenant()`), gera com `GerarPropinasService::gerar(PedidoGeracaoPropinasDTO::daMatricula(...))`, origem `MATRICULA`. Qualquer `Throwable` é apanhado (já fora da transacção), `report()` + `Log::warning('financeiro.gerar-propinas.activacao-falhou', …)`, e **nunca** propaga: a matrícula fica Activa; o comando diário (idempotente) gera o que faltar. | §8.2/3 "geração recuperável". |
| D10 | **A geração automática (origens `COMANDO` e `MATRICULA`) não recria um período com uma propina Cancelada da matrícula** que se sobreponha: fica ignorada ("Período cancelado (…): a geração automática não o recria…"). A geração **manual** continua a poder regenerar, mas cada item a criar que recobra um período cancelado traz `regenera_cancelada` ("Este período tinha sido cancelado em dd/mm/aaaa («motivo»): confirmar volta a cobrá-lo."), mostrado na pré-visualização (**O2 aprovado**, Tasks 10 e 12). | Sem esta regra, um cancelamento manual seria desfeito pelo comando da noite seguinte. Com a matrícula terminada, nada se gera (só Activas), por isso os cancelamentos de §9 nunca são desfeitos. |
| D11 | **Q8 aprovado — `Modules\Matricula\Contracts\DependenciasDaMatricula::bloqueiaAlteracao(Matricula $matricula, int $turmaNovaId, int $anoLectivoNovoId, string $dataMatriculaNova): ?string`** + `Support\DependenciasRegistadasDaMatricula` (etiqueta `matricula.dependencias`, padrão de `DependenciasRegistadasDoAnoLectivo`). `AtualizarMatriculaAction` bloqueia a matrícula (`lockForUpdate`) e consulta-as **dentro** da transacção, antes de gravar; o motivo vai para `turma_id` (se a turma ou o ano mudam) ou `data_matricula`. O Financeiro implementa com `Support\PropinasDaMatricula`: com propinas **activas** (estados 1–3) bloqueia mudar turma e ano lectivo, e mudar o **mês/ano** de `data_matricula` (o dia dentro do mesmo mês continua livre); só canceladas/anuladas → livre. A verificação fica na Action (ponto único de escrita), não no FormRequest: um 2.º ponto seria contornável pelo `RenovarMatriculaAction` e não estaria sob bloqueio. | Q8 e §8.1/Q8: a mensagem indica o fluxo correcto (Transferida + nova matrícula, ou cancelar as propinas). |
| D12 | **Reconciliação diária (rede de segurança):** `Services\ReconciliarCancelamentosDePropinas::executar(): array{matriculas: int, canceladas: int, erros: int}` (`matriculas` = só as que tiveram propinas canceladas) corre em `financeiro:gerar-propinas` **antes** da geração e **sempre** — mesmo com `gerar_automaticamente = false` e mesmo com o interruptor de segurança desligado (cancelar é protector). Candidatas: matrículas Cancelada/Transferida/Concluída com `data_fim` e com propinas Em Aberto de `periodo_inicio > data_fim`; cada matrícula na sua transacção, bloqueada, pelo mesmo serviço do listener (D8), com `utilizadorId = null`. | Q5 ("reconciliação diária como rede de segurança em qualquer caso"), trancamento D14. |
| D13 | **Detalhe:** `GET /financeiro/propinas/{propina}` (`can:propina.ver` + `authorize`), `Financeiro/Propinas/Show`, dados de `PropinaConsultaService::detalhe(Propina)`: estado resolvido e persistido, valores (original, actual, pago, saldo), período/ordem, snapshot (vencimento, tolerância, limite, moeda, câmbio, origem, motivo da geração), plano de origem, matrícula (aluno, nº, turma, ano lectivo, estado, datas), cancelamento/anulação, histórico simples **derivado das colunas** (Gerada, Cancelada, Anulada — nenhuma tabela nova), `pagamentos: []` e `ajustes: []` (placeholders até Pagamentos-A/F4) e `cancelamento_bloqueado` (motivo de D3 ou nulo). `pode_cancelar` = `propina.cancelar` **e** cancelável, decidido no servidor. | Spec §10; fases F3. |
| D14 | **Cancelar:** `PATCH /financeiro/propinas/{propina}/cancelar` (`can:propina.cancelar` + `authorize()` do FormRequest + `authorize` no controller); `DTO\CancelarPropinaDTO`; `Actions\CancelarPropinaAction` (transacção, `BloqueioDePropinas`, D3 sobre a linha bloqueada, `ValidationException` em `propina`). Sem rota de eliminar (DELETE → 405). | §8, §11. |
| D15 | **Sem tabela, coluna, migração, seeder nem permissão nova.** `propina.cancelar` já foi concedida ao ADMIN_ESCOLA em F1 (`PermissoesPropinaTest`). O menu não muda (`/financeiro/propinas/{id}` fica sob o mesmo prefixo, já activo em `useActiveMenu`). | Nada a correr pelo dono além de validar. |
| D16 | `cancelado_em` (cast `datetime`) grava o instante de `RelogioDoTenant::agora()` convertido para `config('app.timezone')`, para ficar na mesma zona que `created_at`/`updated_at` (o Eloquent formata sem converter a zona). | `now()` é proibido em `Modules/Financeiro/app`. |
| D17 | **O interruptor de segurança fica durante F3** (os testes correm com ele ligado em `phpunit.xml`). A reconciliação (D12) corre mesmo desligado; a geração ao activar (D9) respeita-o. A Task 16 remove-o **só com autorização expressa do dono**. | R-F2.1 e pedido do dono. |

**Não provado em SQLite (só no grupo pgsql, Task 14):** `lockForUpdate` da propina no cancelamento manual a segurar outra sessão; `FOR UPDATE` da matrícula durante a mudança de estado e durante a edição (a impedir `KEY SHARE`, isto é, inserir propinas dessa matrícula noutra sessão); o cancelamento manual não esperar pela geração (sem ciclo de bloqueios); propinas canceladas pelo listener bloqueadas até ao commit.

## Notas pré-voo (verificadas no código em 2026-10-14)

- `AlterarEstadoMatriculaAction` **não** tem transacção nem bloqueio e valida a transição sobre o model recebido; `RenovarMatriculaAction` chama-a dentro da sua transacção (aninhada → *savepoint*; os listeners síncronos correm aí; o *after commit* espera pela transacção exterior).
- `CriarMatriculaAction` já usa `DB::transaction` e regista o histórico com `estadoAnterior = null`; aceita `dto->estado` (pode nascer Activa).
- `AtualizarMatriculaAction` já usa `DB::transaction`, recusa terminais e muda `turma_id`, `ano_lectivo_id` e `data_matricula` sem olhar para propinas (L10).
- Não existe fluxo próprio de transferência: "Transferida" é uma mudança de estado (`PATCH alunos/{aluno}/matriculas/{matricula}/estado`); a nova matrícula cria-se à parte (`validarMatriculaNaoDuplicada` só olha para Pendente/Activa, por isso a transferência para outra turma funciona).
- `Matricula` tem cast `date` em `data_matricula` e `data_fim`; o `EventServiceProvider` da Matrícula está vazio (descoberta activa, só em `app/Listeners` da raiz). O Financeiro não tem `EventServiceProvider`: os listeners registam-se em `FinanceiroServiceProvider::boot()` (o `boot()` do `ModuleServiceProvider` tem de ser chamado com `parent::boot()`).
- `Propina`: `estado` 4 exige `cancelado_em` e `motivo_cancelamento`; só a Cancelada tem `cancelado_por`; `EM_ABERTO → CANCELADA` é transição permitida; `deleting` lança; `updating` mantém `editado_por` sem utilizador autenticado.
- `GerarPropinasService::gerar()` não pode correr dentro de outra transacção de negócio (cada matrícula confirma sozinha) e lança `GeracaoDePropinasDesactivada` (interruptor) e `ValidationException` (ano encerrado) — o listener D9 apanha tudo.
- `PedidoGeracaoPropinasDTO` com âmbito `MATRICULA` identifica a matrícula pelo `numero_registo_matricula` e exige `ano_lectivo_id`.
- **Q10 já está em vigor** (`AtualizarPlanoPropinaAction::MENSAGEM_CALENDARIO`/`MENSAGEM_VALOR`, `PlanoPropinaComPropinasTest`): com qualquer propina do plano, **mesmo Cancelada**, valor e calendário ficam bloqueados (L18). F3 não muda isto: cancelar propinas não liberta a edição do plano.
- `test_renova_em_massa_carrega_as_matriculas_numa_unica_query` conta consultas a `"matriculas"` com `"id" in (`: o `lockForUpdate` novo usa `whereKey($id)` (`"id" = ?`), por isso não o afecta.
- **Testes sensíveis à hora (pré-existentes):** entre as 23:00 e as 24:00 UTC, 5 testes falham (ver F2, notas pré-voo). Não correr a suite nessa janela.
- **Datas e O1:** testes de F3 sem relógio congelado usam só datas de fim passadas e ≥ `data_matricula` (ex.: 15/09/2026, 01/10/2026); uma data de fim futura numa Cancelada/Transferida é recusada.
- **Pré-voo F3 (2026-10-14, cópia descartável):** Tasks 1–15 executadas literalmente; suite 2814 ✓ (baseline 2711); grupo pgsql 16/16 ✓ em PostgreSQL 16 descartável; correcções já incorporadas neste documento (ver `.superpowers/sdd/2026-10-13-financeiro-propinas-f1/f3-preflight-report.md`). `GerarPropinasTest::test_o_dia_de_vencimento_corrompido_e_limitado_a_28` (F2) usa `strftime` (só SQLite): não correr esse ficheiro em PostgreSQL.
- **Antes da Task 1:** guardar o que já está em stage: `git diff --cached --name-only > /tmp/f3-stage-antes.txt` (para a verificação da Task 15).

## Fora de âmbito (fases seguintes)

Anular propina (Pagamentos-A), distribuições/pagamentos reais no detalhe, ajustes e alteração de preço (F4), multas (F5), trancamento, exportação. (`data_fim` passa a ser validada — O1 aprovado, Task 6.)

## Review Focus

1. Cancelar ≠ anular: pré-condições de D3 sobre a linha bloqueada; mensagens distintas; regerar depois de cancelar (manual) — Tasks 2–3.
2. Q5: o cancelamento por estado corre na transacção da matrícula; uma falha no listener reverte o estado e o histórico — Tasks 6 e 8.
3. Q6: Cancelada/Transferida/Concluída; só `periodo_inicio > dataEfeito`, só canceláveis; idempotente; `data_fim` retroactiva — Task 8. O1: `data_fim` entre `data_matricula` e hoje (Concluída até ao fim do ano lectivo) — Task 6.
4. Q7: geração depois do commit; falha nunca bloqueia; recuperação pelo comando — Task 10.
5. Q8: turma/ano/mês bloqueados com propinas activas; mensagem com o fluxo certo; livre sem propinas ou só com canceladas — Tasks 7 e 9.
6. D10: o comando não desfaz cancelamentos manuais; O2: a pré-visualização manual assinala a regeneração de um período cancelado — Tasks 10 e 12.
7. Reconciliação diária sempre activa e idempotente — Task 11.
8. Fronteira: Matrícula sem Financeiro; listeners registados uma só vez — Tasks 6–9 e 13.
9. Concorrência em PostgreSQL — Task 14.
10. Task 16 **não** executada sem autorização expressa.

## Mapa de ficheiros

```
Modules/Financeiro/
  app/Contracts/HistoricoDePagamentosDaPropina.php                         (T1, novo)
  app/Support/HistoricosDePagamentoDaPropina.php                           (T1, novo)
  app/Support/CancelamentoDePropinas.php                                    (T2, novo)
  app/DTO/CancelarPropinaDTO.php                                            (T3, novo)
  app/Actions/CancelarPropinaAction.php                                     (T3, novo)
  app/Http/Requests/CancelarPropinaRequest.php                              (T3, novo)
  app/Http/Controllers/PropinaController.php                                (T3 + cancelar; T4 + show)
  app/Services/PropinaConsultaService.php                                   (T4, + detalhe)
  app/Services/CancelarPropinasFuturasDaMatricula.php                       (T8, novo)
  app/Listeners/CancelarPropinasAoTerminarMatricula.php                     (T8, novo)
  app/Support/PropinasDaMatricula.php                                       (T9, novo)
  app/Enums/OrigemGeracaoPropina.php                                        (T10, + automatica())
  app/DTO/PedidoGeracaoPropinasDTO.php                                      (T10, + daMatricula())
  app/Services/GerarPropinasService.php                                     (T10, D10)
  app/Listeners/GerarPropinasAoActivarMatricula.php                         (T10, novo)
  app/Services/ReconciliarCancelamentosDePropinas.php                       (T11, novo)
  app/Console/GerarPropinasCommand.php                                      (T11, reconciliação)
  app/Providers/FinanceiroServiceProvider.php                               (T8 boot + listener; T9 etiqueta do contrato; T10 listener)
  routes/web.php                                                            (T3 PATCH; T4 GET)
  resources/js/Pages/Propinas/Show.vue                                      (T12, novo)
  resources/js/Components/Propinas/CancelarPropinaModal.vue                 (T12, novo)
  resources/js/Pages/Propinas/Index.vue                                     (T12, link para o detalhe)
  tests/Concerns/HistoricoPagamentoFalso.php                                (T1, novo)
  tests/Concerns/ComPropinasFinanceiro.php                                  (T1, + historicoPagamentoFalso())
  tests/Feature/{HistoricosDePagamentoDaPropinaTest,CancelamentoDePropinasTest,CancelarPropinaTest,
                 PropinaDetalheTest,CancelarPropinasFuturasDaMatriculaTest,PropinasDaMatriculaTest,
                 GerarPropinasAoActivarMatriculaTest,ReconciliarCancelamentosDePropinasTest,
                 ListenersDaMatriculaTest}.php                              (novos)
  tests/Unit/{OrigemGeracaoPropinaTest,PedidoGeracaoPropinasDTOTest}.php    (T10, + casos)
  tests/Unit/DetalhePropinaVueTest.php                                      (T12, novo)
  tests/Feature/{PermissoesPropinaTest,FinanceiroTenancyTest}.php           (T13, + casos)
  tests/Feature/Pgsql/CancelamentoPropinasPgsqlTest.php                     (T14, novo)
Modules/Matricula/
  app/Events/MatriculaEstadoAlterado.php                                    (T6, novo)
  app/Actions/AlterarEstadoMatriculaAction.php                              (T6)
  app/Actions/CriarMatriculaAction.php                                      (T6)
  app/Contracts/DependenciasDaMatricula.php                                 (T7, novo)
  app/Support/DependenciasRegistadasDaMatricula.php                         (T7, novo)
  app/Actions/AtualizarMatriculaAction.php                                  (T7)
  tests/Feature/{MatriculaEstadoAlteradoTest,DependenciasDaMatriculaTest}.php (T6, T7, novos)
tests/Feature/Arquitectura/CanceladoresDePropinaTest.php                    (T5, novo)
tests/Feature/Arquitectura/{EscritoresDePropinaTest,CriadoresDePropinaTest}.php (T5, + casos)
tests/Feature/Arquitectura/FronteiraMatriculaFinanceiroTest.php             (T13, + evento só com primitivos)
--- Task 16 (só com autorização expressa do dono) ---
Modules/Financeiro/config/config.php, app/Exceptions/GeracaoDePropinasDesactivada.php (removido),
app/Services/GerarPropinasService.php, app/Console/GerarPropinasCommand.php,
app/Http/Requests/{Concerns/ValidaGeracaoPropinas,AtualizarRegraCobrancaRequest}.php,
app/Listeners/GerarPropinasAoActivarMatricula.php, resources/js/Pages/{Propinas/Index,RegrasCobranca/Edit}.vue,
tests/Feature/GeracaoPropinasInterruptorTest.php, app/Http/Middleware/HandleInertiaRequests.php, phpunit.xml, .env.example
```

---

### Task 1: `HistoricoDePagamentosDaPropina` — contrato e agregador (D4)

**Files:**
- Create: `Modules/Financeiro/app/Contracts/HistoricoDePagamentosDaPropina.php`
- Create: `Modules/Financeiro/app/Support/HistoricosDePagamentoDaPropina.php`
- Create: `Modules/Financeiro/tests/Concerns/HistoricoPagamentoFalso.php`
- Modify: `Modules/Financeiro/tests/Concerns/ComPropinasFinanceiro.php` (+ `historicoPagamentoFalso()`)
- Test: `Modules/Financeiro/tests/Feature/HistoricosDePagamentoDaPropinaTest.php`

**Interfaces:**
- Consumes: padrão de `Support\FontesDePagamentoDePropina` (etiqueta + verificação do tipo), `Propina`.
- Produces:
  - `interface HistoricoDePagamentosDaPropina { public function temHistorico(Propina $propina): bool; }` — `true` se algum pagamento ou utilização de crédito (em qualquer estado, incluindo anulado) alguma vez tocou a propina.
  - `HistoricosDePagamentoDaPropina::ETIQUETA = 'financeiro.historicos-pagamento-propina'`; `temHistorico(Propina): bool` (`true` se **alguma** fonte etiquetada disser que sim; sem fontes → `false`; serviço etiquetado que não implementa o contrato → `LogicException`).
  - Testes: `HistoricoPagamentoFalso::marcar(Propina)`; `ComPropinasFinanceiro::historicoPagamentoFalso(string $chave = 'historico.pagamento.falso'): HistoricoPagamentoFalso`.

- [ ] **Step 1: Escrever o teste que falha**

`Modules/Financeiro/tests/Feature/HistoricosDePagamentoDaPropinaTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Modules\Financeiro\Support\HistoricosDePagamentoDaPropina;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use stdClass;
use Tests\TestCase;

class HistoricosDePagamentoDaPropinaTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    public function test_sem_fontes_registadas_nenhuma_propina_tem_historico(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

        $this->assertFalse(app(HistoricosDePagamentoDaPropina::class)->temHistorico($this->propina($matricula, $plano)));
    }

    public function test_basta_uma_fonte_dizer_que_sim_e_so_para_a_propina_marcada(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $marcada = $this->propina($matricula, $plano, 1);
        $outra = $this->propina($matricula, $plano, 2);
        $this->historicoPagamentoFalso('historico.a');
        $this->historicoPagamentoFalso('historico.b')->marcar($marcada);

        $historicos = app(HistoricosDePagamentoDaPropina::class);

        $this->assertTrue($historicos->temHistorico($marcada));
        $this->assertFalse($historicos->temHistorico($outra));
    }

    public function test_um_servico_etiquetado_que_nao_implementa_o_contrato_e_um_erro_de_configuracao(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->app->instance('historico.invalido', new stdClass());
        $this->app->tag(['historico.invalido'], HistoricosDePagamentoDaPropina::ETIQUETA);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('tem de implementar');

        app(HistoricosDePagamentoDaPropina::class)->temHistorico($this->propina($matricula, $plano));
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/HistoricosDePagamentoDaPropinaTest.php`
Expected: FAIL (classe `HistoricosDePagamentoDaPropina` e helper `historicoPagamentoFalso` inexistentes).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Contracts/HistoricoDePagamentosDaPropina.php`:
```php
<?php

namespace Modules\Financeiro\Contracts;

use Modules\Financeiro\Models\Propina;

/**
 * Se uma propina alguma vez recebeu dinheiro (spec §8; "cancelar ≠ anular", §8.2/2 do plano de fases):
 * qualquer distribuição de pagamento ou utilização de crédito que a tenha tocado, em QUALQUER estado
 * (confirmada, anulada, revertida). Com histórico, uma propina já não se cancela: anula-se (Pagamentos-A).
 * Implementado por Pagamentos; até lá não há implementações e nenhuma propina tem histórico.
 * Registo: $this->app->tag([Classe::class], HistoricosDePagamentoDaPropina::ETIQUETA).
 */
interface HistoricoDePagamentosDaPropina
{
    public function temHistorico(Propina $propina): bool;
}
```

`Modules/Financeiro/app/Support/HistoricosDePagamentoDaPropina.php`:
```php
<?php

namespace Modules\Financeiro\Support;

use Illuminate\Contracts\Container\Container;
use LogicException;
use Modules\Financeiro\Contracts\HistoricoDePagamentosDaPropina;
use Modules\Financeiro\Models\Propina;

/**
 * Agrega as fontes de histórico de pagamentos etiquetadas: uma propina tem histórico se alguma disser
 * que sim. Sem fontes (antes de Pagamentos-A), nenhuma tem.
 */
class HistoricosDePagamentoDaPropina
{
    public const ETIQUETA = 'financeiro.historicos-pagamento-propina';

    public function __construct(private Container $container)
    {
    }

    public function temHistorico(Propina $propina): bool
    {
        foreach ($this->container->tagged(self::ETIQUETA) as $fonte) {
            if (! $fonte instanceof HistoricoDePagamentosDaPropina) {
                throw new LogicException('O serviço registado na etiqueta ' . self::ETIQUETA . ' (' . get_debug_type($fonte) . ') tem de implementar ' . HistoricoDePagamentosDaPropina::class . '.');
            }

            if ($fonte->temHistorico($propina)) {
                return true;
            }
        }

        return false;
    }
}
```

`Modules/Financeiro/tests/Concerns/HistoricoPagamentoFalso.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Concerns;

use Modules\Financeiro\Contracts\HistoricoDePagamentosDaPropina;
use Modules\Financeiro\Models\Propina;

/**
 * Substituto de Pagamentos nos testes: propinas marcadas à mão como "já tiveram pagamentos".
 */
final class HistoricoPagamentoFalso implements HistoricoDePagamentosDaPropina
{
    /** @var array<int, true> */
    private array $marcadas = [];

    public function marcar(Propina $propina): void
    {
        $this->marcadas[(int) $propina->id] = true;
    }

    public function temHistorico(Propina $propina): bool
    {
        return isset($this->marcadas[(int) $propina->id]);
    }
}
```

Em `Modules/Financeiro/tests/Concerns/ComPropinasFinanceiro.php`, acrescentar o `use` (por ordem alfabética, a seguir a `use Modules\Financeiro\Support\FontesDePagamentoDePropina;`):
```php
use Modules\Financeiro\Support\HistoricosDePagamentoDaPropina;
```
e, a seguir ao método `fontePagamentoFalsa()`, antes do `}` final do trait:
```php

    /**
     * Regista um histórico de pagamentos falso (substituto de Pagamentos-A para "já recebeu dinheiro").
     */
    protected function historicoPagamentoFalso(string $chave = 'historico.pagamento.falso'): HistoricoPagamentoFalso
    {
        $historico = new HistoricoPagamentoFalso();
        $this->app->instance($chave, $historico);
        $this->app->tag([$chave], HistoricosDePagamentoDaPropina::ETIQUETA);

        return $historico;
    }
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Feature/HistoricosDePagamentoDaPropinaTest.php`
Expected: PASS (3 testes).

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Contracts/HistoricoDePagamentosDaPropina.php Modules/Financeiro/app/Support/HistoricosDePagamentoDaPropina.php Modules/Financeiro/tests/Concerns/HistoricoPagamentoFalso.php Modules/Financeiro/tests/Concerns/ComPropinasFinanceiro.php Modules/Financeiro/tests/Feature/HistoricosDePagamentoDaPropinaTest.php
```

---

### Task 2: `CancelamentoDePropinas` — ponto único de "Cancelada" (D2, D3, D16)

**Files:**
- Create: `Modules/Financeiro/app/Support/CancelamentoDePropinas.php`
- Test: `Modules/Financeiro/tests/Feature/CancelamentoDePropinasTest.php`

**Interfaces:**
- Consumes: T1 (`HistoricosDePagamentoDaPropina`), `FontesDePagamentoDePropina::contribuicoes(Propina)`, `RelogioDoTenant::agora()`, `EstadoCobranca`, invariantes do model `Propina` (Cancelada exige `cancelado_em` + motivo; só ela tem `cancelado_por`).
- Produces:
  - `CancelamentoDePropinas::MOTIVO_MINIMO = 5`, `MOTIVO_MAXIMO = 500`.
  - `motivoParaNaoCancelar(Propina $propina): ?string` — `null` = cancelável (D3); senão a razão em PT-PT. Só leitura.
  - `cancelar(Propina $bloqueada, string $motivo, ?int $utilizadorId): Propina` — exige transacção aberta (`LogicException`), a propina **já bloqueada** pelo chamador, motivo 5–500 depois de `trim` (`InvalidArgumentException`) e cancelável (`LogicException` com a razão: defesa em profundidade; os chamadores verificam antes). Grava `estado = CANCELADA`, `motivo_cancelamento`, `cancelado_por`, `cancelado_em` (D16) e devolve a mesma instância.

- [ ] **Step 1: Escrever o teste que falha**

`Modules/Financeiro/tests/Feature/CancelamentoDePropinasTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Support\CancelamentoDePropinas;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

class CancelamentoDePropinasTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

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

    private function cancelamento(): CancelamentoDePropinas
    {
        return app(CancelamentoDePropinas::class);
    }

    public function test_em_aberto_pendente_e_em_atraso_sem_pagamentos_sao_cancelaveis(): void
    {
        $estados = $this->cenarioDeEstados();

        // set_aberta resolve-se Em Atraso (limite 15/09) e jun_aberta Pendente: ambas persistidas Em Aberto.
        foreach (['set_aberta', 'fev_aberta', 'mai_aberta', 'jun_aberta'] as $chave) {
            $this->assertNull($this->cancelamento()->motivoParaNaoCancelar($estados[$chave]), $chave);
        }

        $this->assertSame(EstadoCobranca::EM_ATRASO, $estados['set_aberta']->estadoResolvido(CarbonImmutable::parse('2026-10-16')));
        $this->assertSame(EstadoCobranca::PENDENTE, $estados['jun_aberta']->estadoResolvido(CarbonImmutable::parse('2026-10-16')));
    }

    public function test_parcial_paga_cancelada_e_anulada_nao_sao_cancelaveis_e_cada_uma_diz_porque(): void
    {
        $estados = $this->cenarioDeEstados();

        $this->assertStringContainsString('Anule primeiro os pagamentos', $this->cancelamento()->motivoParaNaoCancelar($estados['out_parcial']));
        $this->assertStringContainsString('Anule primeiro os pagamentos', $this->cancelamento()->motivoParaNaoCancelar($estados['nov_paga']));
        $this->assertSame('A propina já está cancelada.', $this->cancelamento()->motivoParaNaoCancelar($estados['dez_cancelada']));
        $this->assertSame('Uma propina anulada não pode ser cancelada.', $this->cancelamento()->motivoParaNaoCancelar($estados['jan_anulada']));
    }

    public function test_com_historico_de_pagamentos_ja_nao_se_cancela_anula_se(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);
        $this->historicoPagamentoFalso()->marcar($propina);

        $this->assertStringContainsString('anula-se', $this->cancelamento()->motivoParaNaoCancelar($propina));
    }

    public function test_com_contribuicoes_activas_nao_se_cancela(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);
        $this->fontePagamentoFalsa()->definir($propina, [[1_000_000, '2026-09-05']]);

        $this->assertStringContainsString('pagamentos activos', $this->cancelamento()->motivoParaNaoCancelar($propina));
    }

    public function test_cancelar_grava_estado_motivo_autor_e_instante(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano, 3);
        $admin = $this->adminEscola();

        DB::transaction(fn () => $this->cancelamento()->cancelar($propina, '  Isenção decidida pela direcção  ', $admin->id));

        $propina->refresh();
        $this->assertSame(EstadoCobranca::CANCELADA, $propina->estado);
        $this->assertSame('Cancelada', $propina->estado_descricao);
        $this->assertSame('Isenção decidida pela direcção', $propina->motivo_cancelamento);
        $this->assertSame($admin->id, (int) $propina->cancelado_por);
        // D16: instante da escola guardado na zona da aplicação (como created_at).
        $this->assertSame('2026-10-16 10:00:00', $propina->cancelado_em->setTimezone(config('app.timezone'))->toDateTimeString());
        $this->assertSame(0, $propina->valor_pago->unidadesMenores());
        $this->assertPropinasCoerentes();
    }

    public function test_cancelar_sem_utilizador_deixa_o_autor_nulo(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);

        DB::transaction(fn () => $this->cancelamento()->cancelar($propina, 'Matrícula cancelada', null));

        $this->assertNull($propina->refresh()->cancelado_por);
        $this->assertSame(EstadoCobranca::CANCELADA, $propina->estado);
    }

    public function test_cancelar_fora_de_transaccao_lanca(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);

        // Sai da transacção do RefreshDatabase (padrão de RecalcularPropinaTest): sem isto o nível é 1 e a guarda
        // nunca dispararia. O serviço tem de lançar antes de ler a base.
        DB::rollBack();
        $this->assertSame(0, DB::transactionLevel());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('transacção');

        $this->cancelamento()->cancelar($propina, 'Motivo válido', null);
    }

    public function test_cancelar_recusa_motivo_curto_ou_longo(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);

        foreach (['   ab  ', str_repeat('x', 501)] as $motivo) {
            try {
                DB::transaction(fn () => $this->cancelamento()->cancelar($propina, $motivo, null));
                $this->fail('Devia recusar o motivo.');
            } catch (InvalidArgumentException) {
                $this->assertSame(EstadoCobranca::EM_ABERTO, $propina->refresh()->estado);
            }
        }
    }

    public function test_cancelar_recusa_uma_propina_nao_cancelavel_mesmo_que_o_chamador_nao_verifique(): void
    {
        $estados = $this->cenarioDeEstados();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Anule primeiro os pagamentos');

        DB::transaction(fn () => $this->cancelamento()->cancelar($estados['out_parcial'], 'Motivo válido', null));
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/CancelamentoDePropinasTest.php`
Expected: FAIL (classe `CancelamentoDePropinas` inexistente).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Support/CancelamentoDePropinas.php`:
```php
<?php

namespace Modules\Financeiro\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\Propina;

/**
 * Ponto ÚNICO de escrita do estado Cancelada e de `motivo_cancelamento`, `cancelado_por` e `cancelado_em`
 * (CanceladoresDePropinaTest). Usado pelo cancelamento manual (CancelarPropinaAction), pelo cancelamento por
 * estado da matrícula (§9) e pela reconciliação diária.
 *
 * Cancelar ≠ anular (spec §8; §8.2/2 do plano de fases): cancela-se só uma propina persistida Em Aberto,
 * sem valor pago, sem contribuições activas e sem NENHUM histórico de pagamentos. Uma propina que já recebeu
 * dinheiro, mesmo que entretanto devolvido/anulado, anula-se (Pagamentos-A).
 */
class CancelamentoDePropinas
{
    public const MOTIVO_MINIMO = 5;

    public const MOTIVO_MAXIMO = 500;

    public function __construct(
        private FontesDePagamentoDePropina $fontes,
        private HistoricosDePagamentoDaPropina $historicos,
        private RelogioDoTenant $relogio,
    ) {
    }

    /**
     * null = cancelável; senão, a razão (mostrada ao utilizador). Só leitura: com a propina bloqueada pelo
     * chamador a resposta é definitiva; sem bloqueio é só indicativa (ex.: botão no detalhe).
     */
    public function motivoParaNaoCancelar(Propina $propina): ?string
    {
        return match (true) {
            $propina->estado === EstadoCobranca::CANCELADA => 'A propina já está cancelada.',
            $propina->estado === EstadoCobranca::ANULADA => 'Uma propina anulada não pode ser cancelada.',
            $propina->estado !== EstadoCobranca::EM_ABERTO,
            $propina->valorRecebido()->unidadesMenores() > 0 => 'A propina tem pagamentos: só se cancela uma propina Em Aberto sem pagamentos. Anule primeiro os pagamentos.',
            $this->fontes->contribuicoes($propina) !== [] => 'A propina tem pagamentos activos: anule-os antes de cancelar.',
            $this->historicos->temHistorico($propina) => 'A propina já recebeu pagamentos (entretanto anulados): não se cancela, anula-se (disponível com o módulo de Pagamentos).',
            default => null,
        };
    }

    /**
     * Cancela uma propina JÁ BLOQUEADA pelo chamador (BloqueioDePropinas, dentro da transacção dele). Lança se
     * não for cancelável: os chamadores verificam antes com motivoParaNaoCancelar() (esta verificação é a
     * defesa em profundidade). Nunca apanhar a excepção dentro da transacção.
     */
    public function cancelar(Propina $bloqueada, string $motivo, ?int $utilizadorId): Propina
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Cancelar uma propina exige uma transacção aberta e a propina bloqueada pelo chamador.');
        }

        $motivo = trim($motivo);
        $tamanho = mb_strlen($motivo);

        if ($tamanho < self::MOTIVO_MINIMO || $tamanho > self::MOTIVO_MAXIMO) {
            throw new InvalidArgumentException('O motivo do cancelamento tem de ter entre ' . self::MOTIVO_MINIMO . ' e ' . self::MOTIVO_MAXIMO . ' caracteres.');
        }

        if (($razao = $this->motivoParaNaoCancelar($bloqueada)) !== null) {
            throw new LogicException($razao);
        }

        $bloqueada->forceFill([
            'estado' => EstadoCobranca::CANCELADA,
            'motivo_cancelamento' => $motivo,
            'cancelado_por' => $utilizadorId,
            // D16: o Eloquent formata sem converter a zona; guarda-se na zona da aplicação, como created_at.
            'cancelado_em' => $this->relogio->agora()->setTimezone(config('app.timezone')),
        ])->save();

        // F5: a multa da propina (se existir e não tiver pagamentos) passa a Anulada aqui, na mesma transacção.

        return $bloqueada;
    }
}
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Feature/CancelamentoDePropinasTest.php`
Expected: PASS (9 testes).

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Support/CancelamentoDePropinas.php Modules/Financeiro/tests/Feature/CancelamentoDePropinasTest.php
```

---

### Task 3: Cancelar por HTTP — `CancelarPropinaAction`, request, rota `PATCH` (D14)

**Files:**
- Create: `Modules/Financeiro/app/DTO/CancelarPropinaDTO.php`
- Create: `Modules/Financeiro/app/Actions/CancelarPropinaAction.php`
- Create: `Modules/Financeiro/app/Http/Requests/CancelarPropinaRequest.php`
- Modify: `Modules/Financeiro/app/Http/Controllers/PropinaController.php` (+ `cancelar`)
- Modify: `Modules/Financeiro/routes/web.php` (+ `PATCH /{propina}/cancelar`)
- Test: `Modules/Financeiro/tests/Feature/CancelarPropinaTest.php`

**Interfaces:**
- Consumes: T2 (`CancelamentoDePropinas`), `BloqueioDePropinas::bloquear(array $ids)`, `App\Http\Requests\BaseRequest`, `GerarPropinasService::gerar` (teste de regeneração manual).
- Produces:
  - `CancelarPropinaDTO(string $motivo)` (motivo `trim`, 5–500; `fromRequest(FormRequest)`).
  - `CancelarPropinaAction::executar(Propina $propina, CancelarPropinaDTO $dto, ?int $utilizadorId): Propina` — transacção própria; bloqueia e relê; não cancelável → `ValidationException` em `propina` com a razão de D3.
  - Rota `financeiro.propinas.cancelar`: `PATCH /financeiro/propinas/{propina}/cancelar` (`can:propina.cancelar`, `{propina}` numérico) → `redirect()->back()` com `success` "Propina cancelada.".

- [ ] **Step 1: Escrever o teste que falha**

`Modules/Financeiro/tests/Feature/CancelarPropinaTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Actions\CancelarPropinaAction;
use Modules\Financeiro\DTO\CancelarPropinaDTO;
use Modules\Financeiro\DTO\PedidoGeracaoPropinasDTO;
use Modules\Financeiro\Enums\AmbitoGeracaoPropinas;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\GerarPropinasService;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

class CancelarPropinaTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    private const ROTA = 'financeiro.propinas.cancelar';

    private const LISTA = '/financeiro/propinas';

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

    private function cancelar(Propina $propina, array $dados = ['motivo' => 'Isenção decidida pela direcção'])
    {
        return $this->from(self::LISTA)->patch(route(self::ROTA, $propina->id), $dados);
    }

    public function test_cancela_uma_propina_em_aberto_sem_pagamentos(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano, 2);
        $admin = $this->adminEscola();
        $this->actingAs($admin);

        $this->cancelar($propina)->assertRedirect(self::LISTA)->assertSessionHas('success', 'Propina cancelada.');

        $propina->refresh();
        $this->assertSame(EstadoCobranca::CANCELADA, $propina->estado);
        $this->assertSame('Isenção decidida pela direcção', $propina->motivo_cancelamento);
        $this->assertSame($admin->id, (int) $propina->cancelado_por);
        $this->assertNotNull($propina->cancelado_em);
        $this->assertPropinasCoerentes();
    }

    public function test_cancela_tambem_as_que_se_resolvem_pendente_ou_em_atraso(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $emAtraso = $this->propina($matricula, $plano, 1);
        $pendente = $this->propina($matricula, $plano, 10);
        $this->actingAs($this->adminEscola());

        $this->cancelar($emAtraso)->assertSessionHasNoErrors();
        $this->cancelar($pendente)->assertSessionHasNoErrors();

        $this->assertSame(EstadoCobranca::CANCELADA, $emAtraso->refresh()->estado);
        $this->assertSame(EstadoCobranca::CANCELADA, $pendente->refresh()->estado);
    }

    public function test_parcial_paga_cancelada_e_anulada_dao_erro_e_nada_muda(): void
    {
        $estados = $this->cenarioDeEstados();
        $this->actingAs($this->adminEscola());

        foreach (['out_parcial', 'nov_paga', 'dez_cancelada', 'jan_anulada'] as $chave) {
            $antes = $estados[$chave]->refresh()->only(['estado', 'motivo_cancelamento', 'cancelado_em', 'valor_pago']);

            $this->cancelar($estados[$chave])->assertSessionHasErrors('propina');

            $this->assertEquals($antes, $estados[$chave]->refresh()->only(['estado', 'motivo_cancelamento', 'cancelado_em', 'valor_pago']), $chave);
        }

        $this->assertPropinasCoerentes();
    }

    public function test_com_historico_de_pagamentos_da_erro_e_aponta_para_anular(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);
        $this->historicoPagamentoFalso()->marcar($propina);
        $this->actingAs($this->adminEscola());

        $this->cancelar($propina)->assertSessionHasErrors(['propina' => 'A propina já recebeu pagamentos (entretanto anulados): não se cancela, anula-se (disponível com o módulo de Pagamentos).']);
        $this->assertSame(EstadoCobranca::EM_ABERTO, $propina->refresh()->estado);
    }

    public function test_motivo_obrigatorio_com_limites(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);
        $this->actingAs($this->adminEscola());

        $this->cancelar($propina, [])->assertSessionHasErrors('motivo');
        $this->cancelar($propina, ['motivo' => '   '])->assertSessionHasErrors('motivo');
        $this->cancelar($propina, ['motivo' => 'abc'])->assertSessionHasErrors('motivo');
        $this->cancelar($propina, ['motivo' => str_repeat('x', 501)])->assertSessionHasErrors('motivo');

        $this->assertSame(EstadoCobranca::EM_ABERTO, $propina->refresh()->estado);
    }

    public function test_sem_permissao_de_cancelar_da_403(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);
        $this->actingAs($this->professor());

        $this->cancelar($propina)->assertForbidden();
        $this->assertSame(EstadoCobranca::EM_ABERTO, $propina->refresh()->estado);
    }

    public function test_propina_de_outra_escola_da_404(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $dela = $this->noTenant($outro, function () {
            ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

            return $this->propina($matricula, $plano)->id;
        });
        $this->actingAs($this->adminEscola());

        $this->from(self::LISTA)->patch(route(self::ROTA, $dela), ['motivo' => 'Motivo válido'])->assertNotFound();
        $this->assertSame(EstadoCobranca::EM_ABERTO, $this->noTenant($outro, fn () => Propina::query()->find($dela)->estado));
    }

    public function test_decide_sobre_a_linha_bloqueada_e_nao_sobre_o_model_recebido(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $desactualizada = $this->propina($matricula, $plano);
        $this->comEstado(Propina::query()->find($desactualizada->id), EstadoCobranca::PAGA);

        try {
            app(CancelarPropinaAction::class)->executar($desactualizada, new CancelarPropinaDTO('Motivo válido'), null);
            $this->fail('Devia recusar: a propina já está paga na base de dados.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('propina', $e->errors());
        }

        $this->assertSame(EstadoCobranca::PAGA, $desactualizada->refresh()->estado);
    }

    public function test_depois_de_cancelar_a_geracao_manual_volta_a_gerar_o_periodo(): void
    {
        ['ano' => $ano, 'turma' => $turma, 'plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $setembro = $this->propina($matricula, $plano, 1);
        app(CancelarPropinaAction::class)->executar($setembro, new CancelarPropinaDTO('Lançada por engano'), null);

        $resultado = app(GerarPropinasService::class)->gerar(new PedidoGeracaoPropinasDTO(
            ano_lectivo_id: $ano->id,
            ambito: AmbitoGeracaoPropinas::TURMA,
            origem: OrigemGeracaoPropina::MANUAL,
            alvo_id: $turma->id,
        ));

        $this->assertSame(10, $resultado->totais()['criadas']);
        $this->assertSame(
            [EstadoCobranca::CANCELADA, EstadoCobranca::EM_ABERTO],
            Propina::query()->where('matricula_id', $matricula->id)->where('ordem', 1)->orderBy('id')->get()->pluck('estado')->all(),
        );
        $this->assertPropinasCoerentes();
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/CancelarPropinaTest.php`
Expected: FAIL (rota `financeiro.propinas.cancelar` e classes inexistentes).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/DTO/CancelarPropinaDTO.php`:
```php
<?php

namespace Modules\Financeiro\DTO;

use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;
use Modules\Financeiro\Support\CancelamentoDePropinas;

final class CancelarPropinaDTO
{
    public readonly string $motivo;

    public function __construct(string $motivo)
    {
        $motivo = trim($motivo);
        $tamanho = mb_strlen($motivo);

        if ($tamanho < CancelamentoDePropinas::MOTIVO_MINIMO || $tamanho > CancelamentoDePropinas::MOTIVO_MAXIMO) {
            throw new InvalidArgumentException('O motivo do cancelamento tem de ter entre '
                . CancelamentoDePropinas::MOTIVO_MINIMO . ' e ' . CancelamentoDePropinas::MOTIVO_MAXIMO . ' caracteres.');
        }

        $this->motivo = $motivo;
    }

    public static function fromRequest(FormRequest $request): self
    {
        return new self((string) $request->validated('motivo'));
    }
}
```

`Modules/Financeiro/app/Http/Requests/CancelarPropinaRequest.php`:
```php
<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Modules\Financeiro\Support\CancelamentoDePropinas;

class CancelarPropinaRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('propina.cancelar') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('motivo'))) {
            $this->merge(['motivo' => trim($this->input('motivo'))]);
        }
    }

    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'min:' . CancelamentoDePropinas::MOTIVO_MINIMO, 'max:' . CancelamentoDePropinas::MOTIVO_MAXIMO],
        ];
    }

    public function messages(): array
    {
        return [
            'motivo.required' => 'Indique o motivo do cancelamento.',
            'motivo.min' => 'O motivo tem de ter pelo menos ' . CancelamentoDePropinas::MOTIVO_MINIMO . ' caracteres.',
            'motivo.max' => 'O motivo não pode ter mais de ' . CancelamentoDePropinas::MOTIVO_MAXIMO . ' caracteres.',
        ];
    }
}
```

`Modules/Financeiro/app/Actions/CancelarPropinaAction.php`:
```php
<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Financeiro\DTO\CancelarPropinaDTO;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Support\BloqueioDePropinas;
use Modules\Financeiro\Support\CancelamentoDePropinas;

/**
 * Cancelamento manual de uma propina (spec §8, permissão `propina.cancelar`, motivo obrigatório). Bloqueia
 * SÓ a propina (D5: nunca a matrícula, por isso não há ciclo com a mudança de estado nem com a geração) e
 * decide sobre a linha relida e bloqueada.
 */
class CancelarPropinaAction
{
    public function __construct(
        private BloqueioDePropinas $bloqueio,
        private CancelamentoDePropinas $cancelamento,
    ) {
    }

    public function executar(Propina $propina, CancelarPropinaDTO $dto, ?int $utilizadorId): Propina
    {
        return DB::transaction(function () use ($propina, $dto, $utilizadorId) {
            $bloqueada = $this->bloqueio->bloquear([(int) $propina->getKey()])->first()
                ?? throw new LogicException('A propina a cancelar não existe no tenant corrente.');

            if (($razao = $this->cancelamento->motivoParaNaoCancelar($bloqueada)) !== null) {
                // Lançada (não apanhada) dentro da transacção: reverte e chega ao utilizador como erro de validação.
                throw ValidationException::withMessages(['propina' => $razao]);
            }

            return $this->cancelamento->cancelar($bloqueada, $dto->motivo, $utilizadorId);
        });
    }
}
```

Em `Modules/Financeiro/app/Http/Controllers/PropinaController.php`:

1. Acrescentar os `use` (por ordem alfabética, junto dos existentes):
```php
use Modules\Financeiro\Actions\CancelarPropinaAction;
use Modules\Financeiro\DTO\CancelarPropinaDTO;
use Modules\Financeiro\Http\Requests\CancelarPropinaRequest;
use Modules\Financeiro\Models\Propina;
```
2. No construtor, a seguir a `private GerarPropinasAction $gerarAction,`:
```php
        private CancelarPropinaAction $cancelarAction,
```
3. Novo método, depois de `gerar()`:
```php

    public function cancelar(CancelarPropinaRequest $request, Propina $propina)
    {
        $this->authorize('propina.cancelar');

        $this->cancelarAction->executar($propina, CancelarPropinaDTO::fromRequest($request), $request->user()?->id);

        return redirect()->back()->with('success', 'Propina cancelada.');
    }
```

Em `Modules/Financeiro/routes/web.php`, no grupo `financeiro/propinas`, depois da rota `geracao.store`:
```php
    Route::patch('/{propina}/cancelar', [PropinaController::class, 'cancelar'])->whereNumber('propina')->middleware('can:propina.cancelar')->name('cancelar');
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Feature/CancelarPropinaTest.php Modules/Financeiro/tests/Feature/PropinaListaTest.php Modules/Financeiro/tests/Feature/PropinaGeracaoHttpTest.php tests/Feature/RotasEscritaAutorizadasTest.php tests/Feature/RotasPermissaoReconhecidaTest.php`
Expected: PASS (9 testes novos; os restantes continuam verdes — a rota nova tem `can:` com uma ability reconhecida).

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/DTO/CancelarPropinaDTO.php Modules/Financeiro/app/Actions/CancelarPropinaAction.php Modules/Financeiro/app/Http/Requests/CancelarPropinaRequest.php Modules/Financeiro/app/Http/Controllers/PropinaController.php Modules/Financeiro/routes/web.php Modules/Financeiro/tests/Feature/CancelarPropinaTest.php
```

---

### Task 4: Detalhe da propina — `PropinaConsultaService::detalhe`, `GET /{propina}` (D13)

**Files:**
- Modify: `Modules/Financeiro/app/Models/Propina.php` (+ relação `criadoPor()`)
- Modify: `Modules/Financeiro/app/Services/PropinaConsultaService.php` (+ `detalhe`, `geracaoTardia`, `historico`, `instante`; construtor)
- Modify: `Modules/Financeiro/app/Http/Controllers/PropinaController.php` (+ `show`)
- Modify: `Modules/Financeiro/routes/web.php` (+ `GET /{propina}`)
- Test: `Modules/Financeiro/tests/Feature/PropinaDetalheTest.php`

**Interfaces:**
- Consumes: T2 (`CancelamentoDePropinas::motivoParaNaoCancelar`), `RelogioDoTenant::{hoje,fuso}`, `RotuloDePeriodo::periodo`, `Propina::{estadoResolvido,saldo,taxaCambio}`, `Moeda::de()->paraFrontend()`, `PermissaoCache::invalidarTudo()` (teste).
- Produces:
  - `Propina::criadoPor(): BelongsTo` (`User`, `criado_por`).
  - `PropinaConsultaService::detalhe(Propina $propina): array` com as chaves `id, estado, estado_descricao, estado_persistido, estado_persistido_descricao, ordem, periodo, periodo_inicio, periodo_fim, valor_original, valor, valor_pago, saldo, data_vencimento, dias_tolerancia, data_limite, moeda, cambio_usd (string formatada ou null), origem_geracao, origem_geracao_descricao, motivo_geracao, geracao_tardia, plano{id,nome,periodicidade,valor_actual}|null, matricula{id,numero,estado_descricao,data_matricula,data_fim,aluno_nome,aluno_numero,turma_nome,ano_lectivo_nome}|null, cancelamento{motivo,por,em}|null, anulacao{motivo,por,em}|null, historico[{evento,em,por,detalhe}], pagamentos [], ajustes [], cancelamento_bloqueado ?string`. Instantes em `Y-m-d H:i` no fuso da escola; datas em `Y-m-d`; dinheiro em unidades menores.
  - Rota `financeiro.propinas.show`: `GET /financeiro/propinas/{propina}` (`can:propina.ver`, numérico) → `Financeiro/Propinas/Show` com props `propina`, `moeda`, `podeCancelar` (D13).

- [ ] **Step 1: Escrever o teste que falha**

`Modules/Financeiro/tests/Feature/PropinaDetalheTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Financeiro\Actions\CancelarPropinaAction;
use Modules\Financeiro\DTO\CancelarPropinaDTO;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Modulo;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo as ModuloRegistro;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Modules\Permissao\Support\PermissaoCache;
use Tests\TestCase;

class PropinaDetalheTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    private const ROTA = 'financeiro.propinas.show';

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

    private function detalhe(int $id): TestResponse
    {
        return $this->get(route(self::ROTA, $id));
    }

    public function test_mostra_o_detalhe_com_a_forma_esperada(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano, 1);
        $this->actingAs($this->adminEscola());

        $this->detalhe($propina->id)->assertOk()->assertInertia(fn (Assert $pagina) => $pagina
            ->component('Financeiro/Propinas/Show')
            ->where('propina.id', $propina->id)
            // Set/2026: limite 15/09, hoje 16/10 → Em Atraso (resolvido), persistido Em Aberto.
            ->where('propina.estado', EstadoCobranca::EM_ATRASO->value)
            ->where('propina.estado_descricao', 'Em Atraso')
            ->where('propina.estado_persistido', EstadoCobranca::EM_ABERTO->value)
            ->where('propina.periodo', 'Set/2026')
            ->where('propina.ordem', 1)
            ->where('propina.valor_original', $plano->valor->unidadesMenores())
            ->where('propina.valor', $plano->valor->unidadesMenores())
            ->where('propina.valor_pago', 0)
            ->where('propina.saldo', $plano->valor->unidadesMenores())
            ->where('propina.data_vencimento', '2026-09-10')
            ->where('propina.dias_tolerancia', 5)
            ->where('propina.data_limite', '2026-09-15')
            ->where('propina.cambio_usd', null)
            ->where('propina.origem_geracao_descricao', 'Manual')
            ->where('propina.plano.id', $plano->id)
            ->where('propina.plano.nome', 'Propina Mensal')
            ->where('propina.matricula.id', $matricula->id)
            ->where('propina.matricula.numero', $matricula->numero_registo_matricula)
            ->where('propina.matricula.estado_descricao', 'Activa')
            ->where('propina.matricula.ano_lectivo_nome', '2026/2027')
            ->where('propina.matricula.aluno_nome', 'Aluno de Teste')
            ->where('propina.cancelamento', null)
            ->where('propina.anulacao', null)
            ->has('propina.historico', 1)
            ->where('propina.historico.0.evento', 'Gerada')
            ->where('propina.pagamentos', [])
            ->where('propina.ajustes', [])
            ->where('propina.cancelamento_bloqueado', null)
            ->where('podeCancelar', true)
            ->has('moeda.codigo'));
    }

    public function test_uma_propina_cancelada_mostra_o_cancelamento_e_o_historico(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano, 2);
        $admin = $this->adminEscola();
        app(CancelarPropinaAction::class)->executar($propina, new CancelarPropinaDTO('Lançada por engano'), $admin->id);
        $this->actingAs($admin);

        $this->detalhe($propina->id)->assertOk()->assertInertia(fn (Assert $pagina) => $pagina
            ->where('propina.estado', EstadoCobranca::CANCELADA->value)
            ->where('propina.cancelamento.motivo', 'Lançada por engano')
            ->where('propina.cancelamento.por', $admin->name)
            // 10:00 UTC = 11:00 em Luanda (fuso da escola).
            ->where('propina.cancelamento.em', '2026-10-16 11:00')
            ->has('propina.historico', 2)
            ->where('propina.historico.1.evento', 'Cancelada')
            ->where('propina.historico.1.detalhe', 'Lançada por engano')
            ->where('propina.cancelamento_bloqueado', 'A propina já está cancelada.')
            ->where('podeCancelar', false));
    }

    public function test_uma_propina_com_pagamentos_nao_pode_ser_cancelada_e_diz_porque(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->comEstado($this->propina($matricula, $plano), EstadoCobranca::PARCIALMENTE_PAGA, 1_000_000);
        $this->actingAs($this->adminEscola());

        $this->detalhe($propina->id)->assertOk()->assertInertia(fn (Assert $pagina) => $pagina
            ->where('propina.valor_pago', 1_000_000)
            ->where('podeCancelar', false)
            ->where('propina.cancelamento_bloqueado', fn (string $razao) => str_contains($razao, 'Anule primeiro os pagamentos')));
    }

    public function test_sem_propina_cancelar_ve_o_detalhe_mas_nao_pode_cancelar(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);
        RolePermissao::query()
            ->where('role_id', Role::where('nome', Perfil::ADMIN_ESCOLA->value)->value('id'))
            ->where('modulo_id', ModuloRegistro::where('nome', Modulo::PROPINA->value)->value('id'))
            ->where('acao_id', Acao::where('nome', 'cancelar')->value('id'))
            ->delete();
        app(PermissaoCache::class)->invalidarTudo();
        $this->actingAs($this->adminEscola());

        $this->detalhe($propina->id)->assertOk()->assertInertia(fn (Assert $pagina) => $pagina
            ->where('propina.cancelamento_bloqueado', null)
            ->where('podeCancelar', false));
    }

    public function test_sem_propina_ver_da_403(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->actingAs($this->professor());

        $this->detalhe($this->propina($matricula, $plano)->id)->assertForbidden();
    }

    public function test_propina_de_outra_escola_da_404(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $dela = $this->noTenant($outro, function () {
            ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

            return $this->propina($matricula, $plano)->id;
        });
        $this->actingAs($this->adminEscola());

        $this->detalhe($dela)->assertNotFound();
    }

    public function test_nao_existe_eliminacao_nem_ids_nao_numericos(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano);
        $this->actingAs($this->adminEscola());

        $this->delete('/financeiro/propinas/' . $propina->id)->assertStatus(405);
        $this->get('/financeiro/propinas/abc')->assertNotFound();
        $this->assertSame(1, Propina::query()->count());
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/PropinaDetalheTest.php`
Expected: FAIL (rota `financeiro.propinas.show` inexistente).

- [ ] **Step 3: Implementar**

Em `Modules/Financeiro/app/Models/Propina.php`, a seguir ao método `anuladoPor()`:
```php

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }
```

Em `Modules/Financeiro/app/Services/PropinaConsultaService.php`:

1. `use` novos (ordem alfabética):
```php
use Carbon\CarbonInterface;
use Modules\Financeiro\Support\CancelamentoDePropinas;
```
2. Construtor — substituir
```php
    public function __construct(private RelogioDoTenant $relogio)
    {
    }
```
por
```php
    public function __construct(
        private RelogioDoTenant $relogio,
        private CancelamentoDePropinas $cancelamento,
    ) {
    }
```
3. Em `linha()`, substituir
```php
        $vencimento = $propina->data_vencimento->toDateString();
        $criadaNaEscola = $propina->created_at?->copy()->setTimezone($fuso)->toDateString();
```
por
```php
        $vencimento = $propina->data_vencimento->toDateString();
```
e substituir
```php
            // §8.1/Q4: retroactiva, ou criada (dia da escola) depois do vencimento.
            'geracao_tardia' => $propina->origem_geracao === OrigemGeracaoPropina::RETROACTIVA
                || ($criadaNaEscola !== null && $criadaNaEscola > $vencimento),
```
por
```php
            'geracao_tardia' => $this->geracaoTardia($propina, $fuso),
```
4. Novos métodos, a seguir a `origens()`:
```php

    /**
     * Detalhe de uma propina (spec §10): estado resolvido para o "hoje" da escola, snapshot, plano de origem,
     * matrícula, cancelamento/anulação e um histórico simples derivado das colunas (nenhuma tabela nova).
     * Pagamentos (Pagamentos-A) e ajustes (F4) ainda não existem: listas vazias.
     *
     * @return array<string, mixed>
     */
    public function detalhe(Propina $propina): array
    {
        $propina->loadMissing([
            'matricula.aluno.dadosPessoa', 'matricula.turma', 'matricula.anoLectivo', 'plano', 'criadoPor', 'canceladoPor', 'anuladoPor',
        ]);

        $hoje = $this->relogio->hoje();
        $fuso = $this->relogio->fuso();
        $estado = $propina->estadoResolvido($hoje);
        $inicio = $propina->periodo_inicio->toDateString();
        $fim = $propina->periodo_fim->toDateString();
        $matricula = $propina->matricula;
        $plano = $propina->plano;

        return [
            'id' => $propina->id,
            'estado' => $estado->value,
            'estado_descricao' => $estado->label(),
            'estado_persistido' => $propina->estado->value,
            'estado_persistido_descricao' => $propina->estado->label(),
            'ordem' => $propina->ordem,
            'periodo' => RotuloDePeriodo::periodo($inicio, $fim),
            'periodo_inicio' => $inicio,
            'periodo_fim' => $fim,
            'valor_original' => $propina->valor_original->unidadesMenores(),
            'valor' => $propina->valor->unidadesMenores(),
            'valor_pago' => $propina->valor_pago->unidadesMenores(),
            'saldo' => $propina->saldo()->unidadesMenores(),
            'data_vencimento' => $propina->data_vencimento->toDateString(),
            'dias_tolerancia' => $propina->dias_tolerancia,
            'data_limite' => $propina->data_limite->toDateString(),
            'moeda' => $propina->moeda,
            'cambio_usd' => $propina->taxaCambio()?->formatar(),
            'origem_geracao' => $propina->origem_geracao->value,
            'origem_geracao_descricao' => $propina->origem_geracao->label(),
            'motivo_geracao' => $propina->motivo_geracao,
            'geracao_tardia' => $this->geracaoTardia($propina, $fuso),
            'plano' => $plano === null ? null : [
                'id' => $plano->id,
                'nome' => $plano->nome,
                'periodicidade' => $plano->periodicidade?->label(),
                'valor_actual' => $plano->valor->unidadesMenores(),
            ],
            'matricula' => $matricula === null ? null : [
                'id' => $matricula->id,
                'numero' => $matricula->numero_registo_matricula,
                'estado_descricao' => $matricula->estado?->label(),
                'data_matricula' => $matricula->data_matricula?->toDateString(),
                'data_fim' => $matricula->data_fim?->toDateString(),
                'aluno_nome' => $matricula->aluno?->dadosPessoa?->nome_completo,
                'aluno_numero' => $matricula->aluno?->numero_matricula,
                'turma_nome' => $matricula->turma?->nome,
                'ano_lectivo_nome' => $matricula->anoLectivo?->nome,
            ],
            'cancelamento' => $propina->estado === EstadoCobranca::CANCELADA ? [
                'motivo' => $propina->motivo_cancelamento,
                'por' => $propina->canceladoPor?->name,
                'em' => $this->instante($propina->cancelado_em, $fuso),
            ] : null,
            'anulacao' => $propina->estado === EstadoCobranca::ANULADA ? [
                'motivo' => $propina->motivo_anulacao,
                'por' => $propina->anuladoPor?->name,
                'em' => $this->instante($propina->anulado_em, $fuso),
            ] : null,
            'historico' => $this->historico($propina, $fuso),
            'pagamentos' => [],
            'ajustes' => [],
            'cancelamento_bloqueado' => $this->cancelamento->motivoParaNaoCancelar($propina),
        ];
    }
```
5. Novos métodos privados, antes de `data()`:
```php

    /** §8.1/Q4: retroactiva, ou criada (dia da escola) depois do vencimento. */
    private function geracaoTardia(Propina $propina, string $fuso): bool
    {
        $criadaNaEscola = $propina->created_at?->copy()->setTimezone($fuso)->toDateString();

        return $propina->origem_geracao === OrigemGeracaoPropina::RETROACTIVA
            || ($criadaNaEscola !== null && $criadaNaEscola > $propina->data_vencimento->toDateString());
    }

    /**
     * Histórico simples, derivado das colunas da própria propina (D13). `por` nulo = sistema (comando/evento).
     *
     * @return list<array{evento: string, em: ?string, por: ?string, detalhe: ?string}>
     */
    private function historico(Propina $propina, string $fuso): array
    {
        $eventos = [[
            'evento' => 'Gerada',
            'em' => $this->instante($propina->created_at, $fuso),
            'por' => $propina->criadoPor?->name,
            'detalhe' => $propina->origem_geracao->label() . ($propina->motivo_geracao ? " — {$propina->motivo_geracao}" : ''),
        ]];

        if ($propina->cancelado_em !== null) {
            $eventos[] = [
                'evento' => 'Cancelada',
                'em' => $this->instante($propina->cancelado_em, $fuso),
                'por' => $propina->canceladoPor?->name,
                'detalhe' => $propina->motivo_cancelamento,
            ];
        }

        if ($propina->anulado_em !== null) {
            $eventos[] = [
                'evento' => 'Anulada',
                'em' => $this->instante($propina->anulado_em, $fuso),
                'por' => $propina->anuladoPor?->name,
                'detalhe' => $propina->motivo_anulacao,
            ];
        }

        return $eventos;
    }

    /** Um instante guardado na zona da aplicação, mostrado no fuso da escola. */
    private function instante(?CarbonInterface $instante, string $fuso): ?string
    {
        return $instante === null ? null : $instante->copy()->setTimezone($fuso)->format('Y-m-d H:i');
    }
```

Em `Modules/Financeiro/app/Http/Controllers/PropinaController.php`:

1. `use` novo: `use Modules\Financeiro\Support\Moeda;`
2. Novo método, a seguir a `index()`:
```php

    public function show(Request $request, Propina $propina)
    {
        $this->authorize('propina.ver');

        $detalhe = $this->consulta->detalhe($propina);

        return Inertia::render('Financeiro/Propinas/Show', [
            'propina' => $detalhe,
            'moeda' => Moeda::de($propina->moeda)->paraFrontend(),
            // D13: decide o servidor — permissão E propina cancelável (o botão nunca aparece para um 422 certo).
            'podeCancelar' => ($request->user()?->can('propina.cancelar') ?? false) && $detalhe['cancelamento_bloqueado'] === null,
        ]);
    }
```

Em `Modules/Financeiro/routes/web.php`, no grupo `financeiro/propinas`, logo a seguir à rota `index`:
```php
    Route::get('/{propina}', [PropinaController::class, 'show'])->whereNumber('propina')->middleware('can:propina.ver')->name('show');
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Feature/PropinaDetalheTest.php Modules/Financeiro/tests/Feature/PropinaListaTest.php Modules/Financeiro/tests/Feature/CancelarPropinaTest.php`
Expected: PASS (7 testes novos; a lista continua igual — `geracao_tardia` só mudou de sítio).

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Models/Propina.php Modules/Financeiro/app/Services/PropinaConsultaService.php Modules/Financeiro/app/Http/Controllers/PropinaController.php Modules/Financeiro/routes/web.php Modules/Financeiro/tests/Feature/PropinaDetalheTest.php
```

---

### Task 5: Arquitectura — só o `CancelamentoDePropinas` cancela (D2)

**Files:**
- Test: `tests/Feature/Arquitectura/CanceladoresDePropinaTest.php` (novo)
- Modify: `tests/Feature/Arquitectura/EscritoresDePropinaTest.php` (+ 1 caso no *data provider*)
- Modify: `tests/Feature/Arquitectura/CriadoresDePropinaTest.php` (+ 1 caso no *data provider*)

**Interfaces:**
- Consumes: a mesma árvore e a mesma técnica de `EscritoresDePropinaTest` (tokens, argumentos lidos até ao parêntese que fecha).
- Produces: regra "`motivo_cancelamento`, `cancelado_por`, `cancelado_em` e `estado` = 4 / `EstadoCobranca::CANCELADA` (em ficheiros que referenciam `Propina`, `->propina` ou a tabela `'propinas'`) só se escrevem em `Modules/Financeiro/app/Support/CancelamentoDePropinas.php`". Provas de que `EscritoresDePropinaTest` e `CriadoresDePropinaTest` não confundem o cancelamento com cache nem com criação.

- [ ] **Step 1: Escrever o teste**

`tests/Feature/Arquitectura/CanceladoresDePropinaTest.php`:
```php
<?php

namespace Tests\Feature\Arquitectura;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Ponto único de cancelamento (D2 do plano F3): o estado Cancelada e `motivo_cancelamento`, `cancelado_por` e
 * `cancelado_em` só se escrevem em CancelamentoDePropinas, onde vivem as pré-condições de §8 (Em Aberto, sem
 * pagamentos, sem histórico). Mesma técnica e mesmos limites de EscritoresDePropinaTest (tokens; não apanha chaves
 * dinâmicas, SQL cru em strings nem `estado` vindo de variáveis). O helper de testes `comEstado` está em `tests/`.
 */
class CanceladoresDePropinaTest extends TestCase
{
    private const CANCELADORES_PERMITIDOS = ['Modules/Financeiro/app/Support/CancelamentoDePropinas.php'];

    private const CAMPOS_DE_CANCELAMENTO = ['motivo_cancelamento', 'cancelado_por', 'cancelado_em'];

    private const METODOS_DE_ESCRITA = [
        'fill', 'forceFill', 'update', 'updateQuietly', 'create', 'forceCreate', 'make', 'firstOrCreate', 'firstOrNew',
        'updateOrCreate', 'updateOrInsert', 'setRawAttributes', 'insert', 'insertOrIgnore', 'insertGetId', 'upsert', 'setAttribute',
    ];

    private const ATRIBUICOES = ['=', T_COALESCE_EQUAL, T_CONCAT_EQUAL];

    /** @return list<array{0: int|string, 1: string}> */
    private function tokens(string $codigo): array
    {
        $saida = [];

        foreach (token_get_all($codigo) as $token) {
            $token = is_array($token) ? [$token[0], $token[1]] : [$token, $token];

            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $saida[] = $token;
        }

        return $saida;
    }

    private function texto(array $tokens, int $de, int $ate): string
    {
        return implode(' ', array_map(fn (array $t): string => $t[1], array_slice($tokens, $de, $ate - $de + 1)));
    }

    private function eString(array $token, array $nomes): bool
    {
        return $token[0] === T_CONSTANT_ENCAPSED_STRING && in_array(trim($token[1], '\'"'), $nomes, true);
    }

    /** O valor que começa em $i é 4 ou EstadoCobranca::CANCELADA? (EstadoMatriculaEnum::CANCELADA não conta.) */
    private function eCancelada(array $tokens, int $i): bool
    {
        $valor = [];

        for ($j = $i; $j < count($tokens) && ! in_array($tokens[$j][0], [',', ')', ']', ';'], true) && count($valor) < 5; $j++) {
            $valor[] = $tokens[$j];
        }

        if (count($valor) === 1 && $valor[0][0] === T_LNUMBER && $valor[0][1] === '4') {
            return true;
        }

        for ($k = 0; $k + 2 < count($valor); $k++) {
            if (str_ends_with($valor[$k][1], 'EstadoCobranca') && $valor[$k + 1][0] === T_DOUBLE_COLON && $valor[$k + 2][1] === 'CANCELADA') {
                return true;
            }
        }

        return false;
    }

    private function referePropina(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && ($token[1] === 'Propina' || str_ends_with($token[1], '\\Propina') || $token[1] === 'propina')) {
                return true;
            }

            if ($this->eString($token, ['propinas'])) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> excertos que cancelam */
    private function cancelamentos(string $codigo): array
    {
        $tokens = $this->tokens($codigo);
        $total = count($tokens);
        $referePropina = $this->referePropina($tokens);
        $achados = [];

        for ($i = 0; $i < $total; $i++) {
            // 1. Atribuição a uma propriedade: $p->cancelado_em = ..., $p->estado = EstadoCobranca::CANCELADA
            if (in_array($tokens[$i][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                && isset($tokens[$i + 2])
                && $tokens[$i + 1][0] === T_STRING
                && in_array($tokens[$i + 2][0], self::ATRIBUICOES, true)) {
                $campo = $tokens[$i + 1][1];

                if (in_array($campo, self::CAMPOS_DE_CANCELAMENTO, true)
                    || ($campo === 'estado' && $referePropina && $this->eCancelada($tokens, $i + 3))) {
                    $achados[] = $this->texto($tokens, $i, min($i + 5, $total - 1));
                }

                continue;
            }

            // 2. Chamadas que gravam: lê os argumentos até ao parêntese que fecha.
            if ($tokens[$i][0] !== T_STRING
                || ! in_array($tokens[$i][1], self::METODOS_DE_ESCRITA, true)
                || ($tokens[$i + 1][0] ?? null) !== '('
                || ! in_array($tokens[$i - 1][0] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                continue;
            }

            $metodo = $tokens[$i][1];
            $nivel = 0;

            for ($j = $i + 1; $j < $total; $j++) {
                if (in_array($tokens[$j][0], ['(', '[', T_CURLY_OPEN, '{'], true)) {
                    $nivel++;
                } elseif (in_array($tokens[$j][0], [')', ']', '}'], true)) {
                    $nivel--;

                    if ($nivel === 0) {
                        break;
                    }
                }

                $campoCancelamento = $this->eString($tokens[$j], self::CAMPOS_DE_CANCELAMENTO);
                $campoEstado = $referePropina && $this->eString($tokens[$j], ['estado']);

                if (! $campoCancelamento && ! $campoEstado) {
                    continue;
                }

                $chave = ($tokens[$j + 1][0] ?? null) === T_DOUBLE_ARROW;
                $argumento = $metodo === 'setAttribute' && $tokens[$j - 1][0] === '(';

                if (! $chave && ! $argumento) {
                    continue;
                }

                if ($campoEstado && ! $this->eCancelada($tokens, $j + 2)) {
                    continue;
                }

                $achados[] = $this->texto($tokens, $j, min($j + 3, $total - 1));
            }
        }

        return $achados;
    }

    private function raiz(): string
    {
        return dirname(__DIR__, 3);
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

    public function test_so_o_cancelamento_de_propinas_cancela(): void
    {
        $violacoes = [];

        foreach ($this->codigoVarrido() as $relativo => $codigo) {
            if (in_array($relativo, self::CANCELADORES_PERMITIDOS, true)) {
                continue;
            }

            foreach ($this->cancelamentos($codigo) as $excerto) {
                $violacoes[] = "{$relativo}: {$excerto}";
            }
        }

        $this->assertSame([], $violacoes, "Só CancelamentoDePropinas escreve o estado Cancelada e os campos de cancelamento:\n" . implode("\n", $violacoes));
    }

    public function test_o_cancelador_permitido_existe_e_e_apanhado_pelo_varrimento(): void
    {
        $caminho = $this->raiz() . '/' . self::CANCELADORES_PERMITIDOS[0];

        $this->assertFileExists($caminho);
        $this->assertNotSame([], $this->cancelamentos(file_get_contents($caminho)));
    }

    #[DataProvider('exemplos')]
    public function test_o_varrimento_apanha_o_que_deve(string $codigo, bool $cancelamento): void
    {
        $this->assertSame($cancelamento, $this->cancelamentos('<?php ' . $codigo) !== [], $codigo);
    }

    public static function exemplos(): array
    {
        return [
            'forceFill com motivo' => ["\$p->forceFill(['motivo_cancelamento' => 'x'])->save();", true],
            'update com cancelado_em' => ["Propina::query()->whereKey(1)->update(['cancelado_em' => \$agora]);", true],
            'atribuição de cancelado_por' => ['$p->cancelado_por = 1;', true],
            'atribuição via relação propina' => ["\$pagamento->propina->motivo_cancelamento = 'x';", true],
            'setAttribute de cancelado_em' => ["\$p->setAttribute('cancelado_em', \$agora);", true],
            'estado Cancelada por enum em query de Propina' => ["Propina::query()->update(['estado' => EstadoCobranca::CANCELADA]);", true],
            'estado 4 literal em query de Propina' => ["Propina::query()->update(['estado' => 4]);", true],
            'estado Cancelada atribuído a uma propina' => ['$p = Propina::find(1); $p->estado = EstadoCobranca::CANCELADA;', true],
            'estado Cancelada na tabela literal' => ["DB::table('propinas')->update(['estado' => 4]);", true],
            'matrícula cancelada não é propina' => ["use Modules\\Financeiro\\Models\\Propina; \$m->update(['estado' => EstadoMatriculaEnum::CANCELADA]);", false],
            'estado 4 noutro model' => ["Turma::query()->update(['estado' => 4]);", false],
            'estado Paga não é cancelamento' => ["Propina::query()->update(['estado' => EstadoCobranca::PAGA]);", false],
            'leitura' => ['$x = $p->motivo_cancelamento;', false],
            'comparação' => ['if ($p->estado === EstadoCobranca::CANCELADA) {}', false],
            'filtro' => ["Propina::query()->where('estado', EstadoCobranca::CANCELADA)->get();", false],
            'array de saída' => ["\$linha = ['motivo_cancelamento' => \$p->motivo_cancelamento, 'cancelado_em' => \$p->cancelado_em];", false],
            'casts do model' => ["protected \$casts = ['cancelado_em' => 'datetime'];", false],
            'comentário' => ["// \$p->cancelado_em = 1;\n/* \$p->update(['motivo_cancelamento' => 'x']); */", false],
        ];
    }
}
```

Em `tests/Feature/Arquitectura/EscritoresDePropinaTest.php`, no array devolvido por `exemplos()`, antes da linha `'comentário' => …`:
```php
            'cancelamento (estado 4 e cancelado_*) não é cache' => ["\$p->forceFill(['estado' => EstadoCobranca::CANCELADA, 'motivo_cancelamento' => 'x', 'cancelado_em' => \$agora])->save();", false],
```

Em `tests/Feature/Arquitectura/CriadoresDePropinaTest.php`, no array devolvido por `exemplos()`, antes da linha `'comentário' => …`:
```php
            'cancelamento não é criação' => ["\$p->forceFill(['estado' => EstadoCobranca::CANCELADA])->save();", false],
```

- [ ] **Step 2: Correr**

Run: `php artisan test tests/Feature/Arquitectura/CanceladoresDePropinaTest.php tests/Feature/Arquitectura/EscritoresDePropinaTest.php tests/Feature/Arquitectura/CriadoresDePropinaTest.php`
Expected: PASS (`CanceladoresDePropinaTest`: 2 + 18 casos; os outros dois com um caso a mais cada). Se `test_so_o_cancelamento_de_propinas_cancela` falhar, há um cancelador fora do ponto único: mover a escrita para `CancelamentoDePropinas` (nunca alargar a lista sem decisão do dono).

- [ ] **Step 3: Stage**

```bash
git add tests/Feature/Arquitectura/CanceladoresDePropinaTest.php tests/Feature/Arquitectura/EscritoresDePropinaTest.php tests/Feature/Arquitectura/CriadoresDePropinaTest.php
```

---

### Task 6: Matrícula — evento `MatriculaEstadoAlterado`, mudança de estado transaccional e validação de `data_fim` (D1, D6, D7, O1)

**Files:**
- Create: `Modules/Matricula/app/Events/MatriculaEstadoAlterado.php`
- Modify: `Modules/Matricula/app/Actions/AlterarEstadoMatriculaAction.php`
- Modify: `Modules/Matricula/app/Actions/CriarMatriculaAction.php`
- Modify: `Modules/Matricula/tests/Feature/MatriculaActionTest.php` (O1: `test_permite_indicar_data_fim_explicita_ao_alterar_estado` passa a ter uma data de matrícula anterior à data de fim)
- Test: `Modules/Matricula/tests/Feature/MatriculaEstadoAlteradoTest.php`, `Modules/Matricula/tests/Feature/DataFimMatriculaTest.php` (O1)

**Interfaces:**
- Consumes: `RegistarHistoricoMatriculaAction`, `RelogioDoTenant::hoje()`, `EstadoMatriculaEnum::{podeTransitarPara,eTerminal}`, `Illuminate\Foundation\Events\Dispatchable`.
- Produces:
  - `final class MatriculaEstadoAlterado` (`Dispatchable`) com `public readonly int $matriculaId, ?int $estadoAnterior, int $estadoNovo, string $dataEfeito (Y-m-d), ?int $utilizadorId`; `InvalidArgumentException` se `dataEfeito` não for `Y-m-d`. **Não** implementa `ShouldDispatchAfterCommit` (D1).
  - `AlterarEstadoMatriculaAction::executar(...)` (assinatura igual): numa `DB::transaction`, relê com `lockForUpdate`, valida sobre a linha bloqueada, grava, histórico, despacha o evento (`dataEfeito` = `data_fim` nos terminais, hoje da escola nos restantes) e devolve `fresh()`.
  - **O1 (aprovado pelo dono):** nos estados terminais, `data_fim` (omissão: hoje da escola) tem de estar entre `data_matricula` e `RelogioDoTenant::hoje()`; Concluída aceita uma data futura até ao `data_fim` do ano lectivo. Fora do intervalo → `ValidationException` em `data_fim` (PT-PT), nada muda e o evento não sai (uma data retroactiva não pode cancelar em silêncio propinas de períodos já vencidos). Se a matrícula ainda não começou (`data_matricula` > hoje), o mínimo é hoje (o intervalo estaria vazio).
  - `CriarMatriculaAction::executar(...)` despacha o evento no fim da sua transacção (`estadoAnterior = null`, `dataEfeito = data_matricula`).
- Nota: nada aqui importa `Modules\Financeiro\` (`FronteiraMatriculaFinanceiroTest`). Os listeners do Financeiro só chegam na Task 8; até lá o evento não tem efeito (os testes existentes de Matrícula continuam verdes).

- [ ] **Step 1: Escrever o teste que falha**

`Modules/Matricula/tests/Feature/MatriculaEstadoAlteradoTest.php`:
```php
<?php

namespace Modules\Matricula\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Aluno\Actions\CriarEnquadramentoAcademicoAlunoAction;
use Modules\Aluno\Models\Aluno;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Matricula\Actions\AlterarEstadoMatriculaAction;
use Modules\Matricula\Actions\CriarMatriculaAction;
use Modules\Matricula\DTO\MatriculaDTO;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Events\MatriculaEstadoAlterado;
use Modules\Matricula\Models\Matricula;
use Modules\Matricula\Models\MatriculaHistorico;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularAnoLectivo;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\DadosPessoa;
use RuntimeException;
use Tests\TestCase;

/**
 * F3 (D1, D6, D7): a Matrícula emite um facto com primitivos, dentro da sua transacção; uma falha de quem
 * escuta reverte tudo (Q5). Sem dependência do Financeiro: os listeners aqui são de teste.
 */
class MatriculaEstadoAlteradoTest extends TestCase
{
    use RefreshDatabase;

    private function novaMatricula(?EstadoMatriculaEnum $estado = null): Matricula
    {
        $estabelecimento = $this->estabelecimentoDeTeste();
        $ano = AnoLectivo::create([
            'estabelecimento_id' => $estabelecimento->id,
            'nome' => '2026',
            'data_inicio' => '2026-01-01',
            'data_fim' => '2026-12-31',
            'estado' => EstadoAnoLectivo::ATIVO,
        ]);
        $nivel = NivelAcademico::create([
            'estabelecimento_id' => $estabelecimento->id,
            'codigo' => '1C',
            'nome' => 'Nível 1C',
            'ordem' => 1,
            'etapa_ensino' => 1,
        ]);
        $turma = Turma::create([
            'ano_lectivo_id' => $ano->id,
            'nivel_academico_id' => $nivel->id,
            'codigo' => 'T' . random_int(1000, 9999),
            'nome' => 'Turma Teste',
            'estado' => 1,
        ]);
        $pessoa = DadosPessoa::create([
            'nome_completo' => 'Aluno Teste',
            'numero_identificacao' => 'BI' . random_int(100000, 999999),
            'tipo_pessoa' => DadosPessoa::TIPO_ALUNO,
        ]);
        $aluno = Aluno::create([
            'estabelecimento_id' => $estabelecimento->id,
            'dados_pessoa_id' => $pessoa->id,
            'numero_matricula' => '2026-' . random_int(1000, 9999),
        ]);
        (new CriarEnquadramentoAcademicoAlunoAction())->executar($aluno, null, $nivel->id);
        $plano = PlanoCurricular::create([
            'estabelecimento_id' => $estabelecimento->id,
            'curso_id' => null,
            'nivel_academico_id' => $nivel->id,
            'codigo' => 'PL' . random_int(100000, 999999),
            'nome' => 'Plano Curricular Teste',
        ]);
        PlanoCurricularAnoLectivo::create(['plano_curricular_id' => $plano->id, 'ano_lectivo_id' => $ano->id]);

        return app(CriarMatriculaAction::class)->executar($aluno, new MatriculaDTO(
            turmaId: $turma->id,
            anoLectivoId: $ano->id,
            dataMatricula: '2026-02-01',
            estado: $estado?->value,
            observacoes: null,
        ));
    }

    public function test_a_criacao_despacha_o_evento_com_estado_anterior_nulo(): void
    {
        Event::fake([MatriculaEstadoAlterado::class]);

        $matricula = $this->novaMatricula(EstadoMatriculaEnum::ACTIVA);

        Event::assertDispatchedTimes(MatriculaEstadoAlterado::class, 1);
        Event::assertDispatched(MatriculaEstadoAlterado::class, fn (MatriculaEstadoAlterado $e) => $e->matriculaId === $matricula->id
            && $e->estadoAnterior === null
            && $e->estadoNovo === EstadoMatriculaEnum::ACTIVA->value
            && $e->dataEfeito === '2026-02-01'
            && $e->utilizadorId === null);
    }

    public function test_cada_mudanca_de_estado_despacha_o_evento_com_a_data_de_efeito(): void
    {
        Event::fake([MatriculaEstadoAlterado::class]);
        $matricula = $this->novaMatricula();
        $hoje = app(RelogioDoTenant::class)->hoje()->toDateString();

        $matricula = app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::ACTIVA);
        app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::CANCELADA, dataFim: '2026-03-15');

        Event::assertDispatchedTimes(MatriculaEstadoAlterado::class, 3);
        Event::assertDispatched(MatriculaEstadoAlterado::class, fn (MatriculaEstadoAlterado $e) => $e->estadoAnterior === EstadoMatriculaEnum::PENDENTE->value
            && $e->estadoNovo === EstadoMatriculaEnum::ACTIVA->value
            && $e->dataEfeito === $hoje);
        Event::assertDispatched(MatriculaEstadoAlterado::class, fn (MatriculaEstadoAlterado $e) => $e->estadoAnterior === EstadoMatriculaEnum::ACTIVA->value
            && $e->estadoNovo === EstadoMatriculaEnum::CANCELADA->value
            && $e->dataEfeito === '2026-03-15');
    }

    public function test_o_evento_e_despachado_dentro_da_transaccao_da_mudanca_de_estado(): void
    {
        $matricula = $this->novaMatricula(EstadoMatriculaEnum::ACTIVA);
        $fora = DB::transactionLevel();
        $dentro = null;
        Event::listen(MatriculaEstadoAlterado::class, function () use (&$dentro) {
            $dentro = DB::transactionLevel();
        });

        app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::TRANSFERIDA);

        $this->assertGreaterThan($fora, $dentro);
    }

    public function test_uma_falha_de_quem_escuta_reverte_o_estado_a_data_fim_e_o_historico(): void
    {
        $matricula = $this->novaMatricula(EstadoMatriculaEnum::ACTIVA);
        $historicoAntes = MatriculaHistorico::query()->where('matricula_id', $matricula->id)->count();
        Event::listen(MatriculaEstadoAlterado::class, function () {
            throw new RuntimeException('Falha simulada de um módulo que escuta.');
        });

        try {
            app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::CANCELADA);
            $this->fail('A falha devia propagar.');
        } catch (RuntimeException $e) {
            $this->assertSame('Falha simulada de um módulo que escuta.', $e->getMessage());
        }

        $matricula->refresh();
        $this->assertSame(EstadoMatriculaEnum::ACTIVA, $matricula->estado);
        $this->assertNull($matricula->data_fim);
        $this->assertSame($historicoAntes, MatriculaHistorico::query()->where('matricula_id', $matricula->id)->count());
    }

    public function test_decide_sobre_a_linha_bloqueada_e_nao_sobre_o_model_recebido(): void
    {
        Event::fake([MatriculaEstadoAlterado::class]);
        $desactualizada = $this->novaMatricula(EstadoMatriculaEnum::ACTIVA);
        app(AlterarEstadoMatriculaAction::class)->executar(Matricula::query()->find($desactualizada->id), EstadoMatriculaEnum::CANCELADA);

        try {
            app(AlterarEstadoMatriculaAction::class)->executar($desactualizada, EstadoMatriculaEnum::TRANSFERIDA);
            $this->fail('A matrícula já está Cancelada na base de dados.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('de Cancelada para Transferida', $e->errors()['estado'][0]);
        }

        $this->assertSame(EstadoMatriculaEnum::CANCELADA, $desactualizada->refresh()->estado);
        Event::assertNotDispatched(MatriculaEstadoAlterado::class, fn (MatriculaEstadoAlterado $e) => $e->estadoNovo === EstadoMatriculaEnum::TRANSFERIDA->value);
    }

    public function test_a_data_de_efeito_tem_de_ser_uma_data(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MatriculaEstadoAlterado(1, null, EstadoMatriculaEnum::ACTIVA->value, '15/03/2026', null);
    }
}
```

`Modules/Matricula/tests/Feature/DataFimMatriculaTest.php` (O1):
```php
<?php

namespace Modules\Matricula\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Aluno\Actions\CriarEnquadramentoAcademicoAlunoAction;
use Modules\Aluno\Models\Aluno;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Matricula\Actions\AlterarEstadoMatriculaAction;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Events\MatriculaEstadoAlterado;
use Modules\Matricula\Models\Matricula;
use Modules\Matricula\Models\MatriculaHistorico;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Perfil;
use Modules\Permissao\Models\Role;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\DadosPessoa;
use Modules\Usuario\Models\User;
use Tests\TestCase;

/**
 * O1 (aprovado pelo dono em F3): ao terminar uma matrícula, a data de fim fica entre a data da matrícula e hoje
 * (dia da escola); Concluída aceita uma data futura até ao fim do ano lectivo. Uma data retroactiva anterior à
 * matrícula cancelaria em silêncio propinas de períodos já vencidos (§9): recusa-se com erro em data_fim.
 */
class DataFimMatriculaTest extends TestCase
{
    use RefreshDatabase;

    private ?Turma $turma = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Ano 2026/2027 (01/09/2026–31/07/2027), uma turma partilhada e uma matrícula Activa nova por chamada. */
    private function matricula(string $dataMatricula = '2026-09-01'): Matricula
    {
        $estabelecimento = $this->estabelecimentoDeTeste();

        if ($this->turma === null) {
            $ano = AnoLectivo::create([
                'estabelecimento_id' => $estabelecimento->id,
                'nome' => '2026/2027',
                'data_inicio' => '2026-09-01',
                'data_fim' => '2027-07-31',
                'estado' => EstadoAnoLectivo::ATIVO,
            ]);
            $nivel = NivelAcademico::create([
                'estabelecimento_id' => $estabelecimento->id,
                'codigo' => '1C',
                'nome' => 'Nível 1C',
                'ordem' => 1,
                'etapa_ensino' => 1,
            ]);
            $this->turma = Turma::create([
                'ano_lectivo_id' => $ano->id,
                'nivel_academico_id' => $nivel->id,
                'codigo' => 'T1',
                'nome' => 'Turma Teste',
                'estado' => 1,
            ]);
        }

        $pessoa = DadosPessoa::create([
            'nome_completo' => 'Aluno Teste',
            'numero_identificacao' => 'BI-' . uniqid(),
            'tipo_pessoa' => DadosPessoa::TIPO_ALUNO,
        ]);
        $aluno = Aluno::create([
            'estabelecimento_id' => $estabelecimento->id,
            'dados_pessoa_id' => $pessoa->id,
            'numero_matricula' => 'AL-' . uniqid(),
        ]);
        (new CriarEnquadramentoAcademicoAlunoAction())->executar($aluno, null, $this->turma->nivel_academico_id);

        return Matricula::create([
            'aluno_id' => $aluno->id,
            'turma_id' => $this->turma->id,
            'ano_lectivo_id' => $this->turma->ano_lectivo_id,
            'numero_registo_matricula' => 'MR-' . uniqid(),
            'data_matricula' => $dataMatricula,
            'estado' => EstadoMatriculaEnum::ACTIVA->value,
        ]);
    }

    private function terminar(Matricula $matricula, EstadoMatriculaEnum $estado, ?string $dataFim): Matricula
    {
        return app(AlterarEstadoMatriculaAction::class)->executar($matricula, $estado, null, $dataFim);
    }

    /** Recusa com erro em data_fim: nada muda (estado, data de fim, histórico) e o evento não sai. */
    private function assertRecusa(Matricula $matricula, EstadoMatriculaEnum $estado, string $dataFim, string $mensagem): void
    {
        Event::fake([MatriculaEstadoAlterado::class]);
        $historicoAntes = MatriculaHistorico::query()->where('matricula_id', $matricula->id)->count();

        try {
            $this->terminar($matricula, $estado, $dataFim);
            $this->fail("Devia recusar {$dataFim} para {$estado->label()}.");
        } catch (ValidationException $e) {
            $this->assertSame(['data_fim' => [$mensagem]], $e->errors());
        }

        $matricula->refresh();
        $this->assertSame(EstadoMatriculaEnum::ACTIVA, $matricula->estado);
        $this->assertNull($matricula->data_fim);
        $this->assertSame($historicoAntes, MatriculaHistorico::query()->where('matricula_id', $matricula->id)->count());
        Event::assertNotDispatched(MatriculaEstadoAlterado::class);
    }

    public function test_aceita_datas_entre_a_data_da_matricula_e_hoje(): void
    {
        $casos = [
            [EstadoMatriculaEnum::CANCELADA, '2026-09-01'],
            [EstadoMatriculaEnum::TRANSFERIDA, '2026-10-16'],
            [EstadoMatriculaEnum::CONCLUIDA, '2026-09-20'],
        ];

        foreach ($casos as [$estado, $data]) {
            $this->assertSame($data, $this->terminar($this->matricula(), $estado, $data)->data_fim->toDateString(), $estado->label());
        }

        // Sem data: hoje da escola (comportamento anterior).
        $this->assertSame('2026-10-16', $this->terminar($this->matricula(), EstadoMatriculaEnum::CANCELADA, null)->data_fim->toDateString());
    }

    public function test_recusa_uma_data_anterior_a_matricula(): void
    {
        $this->assertRecusa($this->matricula(), EstadoMatriculaEnum::CANCELADA, '2026-08-31', 'A data de fim não pode ser anterior à data da matrícula (01/09/2026).');
    }

    public function test_cancelada_e_transferida_nao_aceitam_uma_data_futura(): void
    {
        foreach ([EstadoMatriculaEnum::CANCELADA, EstadoMatriculaEnum::TRANSFERIDA] as $estado) {
            $this->assertRecusa($this->matricula(), $estado, '2026-10-17', 'A data de fim não pode ser posterior a hoje (16/10/2026).');
        }
    }

    public function test_concluida_aceita_uma_data_futura_ate_ao_fim_do_ano_lectivo(): void
    {
        $this->assertSame('2027-07-31', $this->terminar($this->matricula(), EstadoMatriculaEnum::CONCLUIDA, '2027-07-31')->data_fim->toDateString());

        $this->assertRecusa($this->matricula(), EstadoMatriculaEnum::CONCLUIDA, '2027-08-01', 'A data de fim de uma matrícula concluída não pode ser posterior ao fim do ano lectivo (31/07/2027).');
    }

    public function test_uma_matricula_que_ainda_nao_comecou_termina_hoje(): void
    {
        $this->assertSame('2026-10-16', $this->terminar($this->matricula('2026-11-02'), EstadoMatriculaEnum::CANCELADA, null)->data_fim->toDateString());

        $this->assertRecusa($this->matricula('2026-11-02'), EstadoMatriculaEnum::CANCELADA, '2026-10-15', 'A data de fim não pode ser anterior a hoje (16/10/2026).');
    }

    public function test_por_http_o_erro_chega_ao_campo_data_fim(): void
    {
        $this->seed(PermissaoDatabaseSeeder::class);
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.com', 'password' => Hash::make('segredo123')]);
        $staff->roles()->attach(Role::where('nome', Perfil::ADMIN_ESCOLA->value)->first()->id);
        $matricula = $this->matricula();

        $this->actingAs($staff)
            ->from('/matriculas')
            ->patch(route('matriculas.alterar-estado', [$matricula->aluno_id, $matricula->id]), [
                'estado' => EstadoMatriculaEnum::CANCELADA->value,
                'data_fim' => '2026-08-01',
            ])
            ->assertSessionHasErrors(['data_fim' => 'A data de fim não pode ser anterior à data da matrícula (01/09/2026).']);

        $this->assertSame(EstadoMatriculaEnum::ACTIVA, $matricula->refresh()->estado);
    }
}
```

Em `Modules/Matricula/tests/Feature/MatriculaActionTest.php`, em `test_permite_indicar_data_fim_explicita_ao_alterar_estado()` (O1: a matrícula nasce com a data de hoje, por isso 15/03/2026 seria anterior), substituir
```php
    public function test_permite_indicar_data_fim_explicita_ao_alterar_estado(): void
    {
        $matricula = $this->criarMatriculaPendente();
```
por
```php
    public function test_permite_indicar_data_fim_explicita_ao_alterar_estado(): void
    {
        $matricula = $this->criarMatriculaPendente();
        // O1 (F3): a data de fim não pode ser anterior à data da matrícula.
        $matricula->forceFill(['data_matricula' => '2026-02-01'])->save();
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Matricula/tests/Feature/MatriculaEstadoAlteradoTest.php Modules/Matricula/tests/Feature/DataFimMatriculaTest.php`
Expected: FAIL — 11 de 12: o evento não existe e a data de fim ainda não é validada (`test_aceita_datas_entre_a_data_da_matricula_e_hoje` já passa: é a guarda de não-regressão).

- [ ] **Step 3: Implementar**

`Modules/Matricula/app/Events/MatriculaEstadoAlterado.php`:
```php
<?php

namespace Modules\Matricula\Events;

use Illuminate\Foundation\Events\Dispatchable;
use InvalidArgumentException;

/**
 * Facto: uma matrícula mudou de estado (ou nasceu: estadoAnterior = null). Só primitivos (fronteira §1.1 do
 * plano de fases): quem escuta relê o que precisar. Despachado DENTRO da transacção de quem muda o estado
 * (não implementa ShouldDispatchAfterCommit): um listener síncrono corre nessa transacção e uma excepção dele
 * reverte a mudança (Q5); quem só deve reagir depois do commit implementa ShouldHandleEventsAfterCommit.
 * Quem escuta tem de ignorar estados que não conhece (ex.: Trancada, plano de trancamento).
 */
final class MatriculaEstadoAlterado
{
    use Dispatchable;

    public function __construct(
        public readonly int $matriculaId,
        public readonly ?int $estadoAnterior,
        public readonly int $estadoNovo,
        /** Y-m-d: data_fim nos estados terminais; data da matrícula na criação; hoje da escola nos restantes. */
        public readonly string $dataEfeito,
        public readonly ?int $utilizadorId,
    ) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dataEfeito) !== 1) {
            throw new InvalidArgumentException("A data de efeito tem de estar no formato Y-m-d ({$dataEfeito}).");
        }
    }
}
```

`Modules/Matricula/app/Actions/AlterarEstadoMatriculaAction.php` (substituir o ficheiro inteiro):
```php
<?php

namespace Modules\Matricula\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Events\MatriculaEstadoAlterado;
use Modules\Matricula\Models\Matricula;

class AlterarEstadoMatriculaAction
{
    public function __construct(
        private RegistarHistoricoMatriculaAction $registarHistorico,
        private RelogioDoTenant $relogio,
    ) {
    }

    /**
     * Estado, data de fim, histórico e reacções síncronas de outros módulos (ex.: cancelar propinas futuras)
     * numa só transacção: ou muda tudo ou nada (Q5 do plano de fases do Financeiro).
     */
    public function executar(
        Matricula $matricula,
        EstadoMatriculaEnum $novoEstado,
        ?int $utilizadorId = null,
        ?string $dataFim = null,
    ): Matricula {
        return DB::transaction(function () use ($matricula, $novoEstado, $utilizadorId, $dataFim) {
            // Relida e bloqueada: duas mudanças concorrentes fazem fila aqui e a segunda decide sobre o estado
            // já gravado pela primeira (um model desactualizado nunca passa a validação).
            $bloqueada = Matricula::query()->whereKey($matricula->getKey())->lockForUpdate()->firstOrFail();
            $estadoActual = $bloqueada->estado;

            if (! $estadoActual->podeTransitarPara($novoEstado)) {
                throw ValidationException::withMessages([
                    'estado' => "Não é possível alterar o estado de {$estadoActual->label()} para {$novoEstado->label()}.",
                ]);
            }

            $bloqueada->estado = $novoEstado;
            $bloqueada->editado_por = $utilizadorId;

            if ($novoEstado->eTerminal()) {
                $bloqueada->data_fim = $this->dataFimValidada($bloqueada, $novoEstado, $dataFim);
            }

            $bloqueada->save();

            $this->registarHistorico->executar($bloqueada, $estadoActual, $novoEstado, $utilizadorId);

            MatriculaEstadoAlterado::dispatch(
                (int) $bloqueada->id,
                $estadoActual->value,
                $novoEstado->value,
                $novoEstado->eTerminal() ? $bloqueada->data_fim->toDateString() : $this->relogio->hoje()->toDateString(),
                $utilizadorId,
            );

            return $bloqueada->fresh();
        });
    }

    /**
     * O1 (aprovado pelo dono em F3): a data de fim (omissão: hoje da escola) fica entre a data da matrícula e hoje.
     * Uma data retroactiva anterior à matrícula cancelaria em silêncio propinas de períodos já vencidos; uma futura
     * terminaria a matrícula antes do tempo. Concluída aceita uma data futura até ao fim do ano lectivo. Se a
     * matrícula ainda não começou, o mínimo é hoje (o intervalo [data da matrícula, hoje] estaria vazio).
     */
    private function dataFimValidada(Matricula $matricula, EstadoMatriculaEnum $novoEstado, ?string $pedida): string
    {
        $hoje = $this->relogio->hoje()->toDateString();
        $data = $pedida === null ? $hoje : CarbonImmutable::parse($pedida)->toDateString();
        $inicio = $matricula->data_matricula?->toDateString() ?? $hoje;
        $minimo = min($inicio, $hoje);
        $fimDoAno = $novoEstado === EstadoMatriculaEnum::CONCLUIDA ? $matricula->anoLectivo?->data_fim?->toDateString() : null;
        $maximo = max($hoje, $fimDoAno ?? $hoje);

        if ($data < $minimo) {
            throw ValidationException::withMessages(['data_fim' => $minimo === $inicio
                ? 'A data de fim não pode ser anterior à data da matrícula (' . $this->formatar($inicio) . ').'
                : 'A data de fim não pode ser anterior a hoje (' . $this->formatar($hoje) . ').']);
        }

        if ($data > $maximo) {
            throw ValidationException::withMessages(['data_fim' => $maximo === $hoje
                ? 'A data de fim não pode ser posterior a hoje (' . $this->formatar($hoje) . ').'
                : 'A data de fim de uma matrícula concluída não pode ser posterior ao fim do ano lectivo (' . $this->formatar($maximo) . ').']);
        }

        return $data;
    }

    private function formatar(string $data): string
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $data)->format('d/m/Y');
    }
}
```

Em `Modules/Matricula/app/Actions/CriarMatriculaAction.php`:
1. `use` novo: `use Modules\Matricula\Events\MatriculaEstadoAlterado;`
2. Substituir
```php
            $this->inscreverDisciplinas->executar($matricula, $utilizadorId);

            return $matricula;
```
por
```php
            $this->inscreverDisciplinas->executar($matricula, $utilizadorId);

            // O mesmo facto que o histórico regista (estado anterior nulo), na mesma transacção.
            MatriculaEstadoAlterado::dispatch(
                (int) $matricula->id,
                null,
                $matricula->estado->value,
                $matricula->data_matricula->toDateString(),
                $utilizadorId,
            );

            return $matricula;
```

- [ ] **Step 4: Correr e ver passar (e não partir a Matrícula)**

Run: `php artisan test Modules/Matricula tests/Feature/Arquitectura/FronteiraMatriculaFinanceiroTest.php tests/Feature/Arquitectura/RelogioDoTenantArquitecturaTest.php`
Expected: PASS (12 testes novos — 6 do evento + 6 de O1; todos os testes existentes de Matrícula, incluindo `test_renova_em_massa_carrega_as_matriculas_numa_unica_query` e `FusoHorarioMatriculaTest`, continuam verdes).

- [ ] **Step 5: Stage**

```bash
git add Modules/Matricula/app/Events/MatriculaEstadoAlterado.php Modules/Matricula/app/Actions/AlterarEstadoMatriculaAction.php Modules/Matricula/app/Actions/CriarMatriculaAction.php Modules/Matricula/tests/Feature/MatriculaEstadoAlteradoTest.php Modules/Matricula/tests/Feature/DataFimMatriculaTest.php Modules/Matricula/tests/Feature/MatriculaActionTest.php
```

---

### Task 7: Matrícula — contrato `DependenciasDaMatricula` consultado sob bloqueio (D11)

**Files:**
- Create: `Modules/Matricula/app/Contracts/DependenciasDaMatricula.php`
- Create: `Modules/Matricula/app/Support/DependenciasRegistadasDaMatricula.php`
- Modify: `Modules/Matricula/app/Actions/AtualizarMatriculaAction.php`
- Test: `Modules/Matricula/tests/Feature/DependenciasDaMatriculaTest.php`

**Interfaces:**
- Consumes: padrão `AnoLectivo\Contracts\DependenciasDoAnoLectivo` + `DependenciasRegistadasDoAnoLectivo`.
- Produces:
  - `interface DependenciasDaMatricula { public function bloqueiaAlteracao(Matricula $matricula, int $turmaNovaId, int $anoLectivoNovoId, string $dataMatriculaNova): ?string; }` — `$matricula` é a linha **bloqueada** e ainda com os valores actuais; `$dataMatriculaNova` em `Y-m-d`.
  - `DependenciasRegistadasDaMatricula::ETIQUETA = 'matricula.dependencias'`; `bloqueiaAlteracao(...)`: primeiro motivo não nulo; serviço etiquetado que não implementa → `LogicException`.
  - `AtualizarMatriculaAction`: `lockForUpdate` + releitura **antes** de tudo; consulta as dependências antes de gravar; motivo → `ValidationException` em `turma_id` (turma ou ano mudam) ou `data_matricula`.

- [ ] **Step 1: Escrever o teste que falha**

`Modules/Matricula/tests/Feature/DependenciasDaMatriculaTest.php`:
```php
<?php

namespace Modules\Matricula\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Aluno\Actions\CriarEnquadramentoAcademicoAlunoAction;
use Modules\Aluno\Models\Aluno;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Matricula\Actions\AtualizarMatriculaAction;
use Modules\Matricula\Contracts\DependenciasDaMatricula;
use Modules\Matricula\DTO\MatriculaDTO;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Matricula\Support\DependenciasRegistadasDaMatricula;
use Modules\Turma\Models\NivelAcademico;
use Modules\Turma\Models\Turma;
use Modules\Usuario\Models\DadosPessoa;
use stdClass;
use Tests\TestCase;

/**
 * F3 (D11): a Matrícula consulta dependências registadas por outros módulos antes de mudar turma, ano lectivo
 * ou data — sem os conhecer. Aqui as dependências são de teste.
 */
class DependenciasDaMatriculaTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Turma> */
    private array $turmas = [];

    private function matriculaActiva(): Matricula
    {
        $estabelecimento = $this->estabelecimentoDeTeste();
        $ano = AnoLectivo::create([
            'estabelecimento_id' => $estabelecimento->id,
            'nome' => '2026',
            'data_inicio' => '2026-01-01',
            'data_fim' => '2026-12-31',
            'estado' => EstadoAnoLectivo::ATIVO,
        ]);
        $nivel = NivelAcademico::create([
            'estabelecimento_id' => $estabelecimento->id,
            'codigo' => '1C',
            'nome' => 'Nível 1C',
            'ordem' => 1,
            'etapa_ensino' => 1,
        ]);

        foreach (['original', 'nova'] as $chave) {
            $this->turmas[$chave] = Turma::create([
                'ano_lectivo_id' => $ano->id,
                'nivel_academico_id' => $nivel->id,
                'codigo' => 'T' . random_int(1000, 9999),
                'nome' => "Turma {$chave}",
                'estado' => 1,
            ]);
        }

        $pessoa = DadosPessoa::create([
            'nome_completo' => 'Aluno Teste',
            'numero_identificacao' => 'BI' . random_int(100000, 999999),
            'tipo_pessoa' => DadosPessoa::TIPO_ALUNO,
        ]);
        $aluno = Aluno::create([
            'estabelecimento_id' => $estabelecimento->id,
            'dados_pessoa_id' => $pessoa->id,
            'numero_matricula' => '2026-' . random_int(1000, 9999),
        ]);
        (new CriarEnquadramentoAcademicoAlunoAction())->executar($aluno, null, $nivel->id);

        return Matricula::create([
            'aluno_id' => $aluno->id,
            'turma_id' => $this->turmas['original']->id,
            'ano_lectivo_id' => $ano->id,
            'numero_registo_matricula' => 'MR-' . uniqid(),
            'data_matricula' => '2026-02-10',
            'estado' => EstadoMatriculaEnum::ACTIVA->value,
        ]);
    }

    private function dto(Matricula $matricula, string $turma, string $data): MatriculaDTO
    {
        return new MatriculaDTO(turmaId: $this->turmas[$turma]->id, anoLectivoId: $matricula->ano_lectivo_id, dataMatricula: $data, estado: null, observacoes: null);
    }

    /** Regista uma dependência de teste que regista as chamadas e bloqueia quando $motivo não é nulo. */
    private function dependencia(?string $motivo): object
    {
        $dependencia = new class ($motivo) implements DependenciasDaMatricula {
            /** @var list<array{0: int, 1: int, 2: int, 3: string, 4: int}> */
            public array $chamadas = [];

            public function __construct(private ?string $motivo)
            {
            }

            public function bloqueiaAlteracao(Matricula $matricula, int $turmaNovaId, int $anoLectivoNovoId, string $dataMatriculaNova): ?string
            {
                // turma_id ainda é o actual: a decisão é tomada antes de gravar.
                $this->chamadas[] = [(int) $matricula->id, $turmaNovaId, $anoLectivoNovoId, $dataMatriculaNova, (int) $matricula->turma_id];

                return $this->motivo;
            }
        };

        $this->app->instance('dependencia.teste', $dependencia);
        $this->app->tag(['dependencia.teste'], DependenciasRegistadasDaMatricula::ETIQUETA);

        return $dependencia;
    }

    public function test_sem_bloqueio_actualiza_e_a_dependencia_ve_os_valores_actuais_e_os_novos(): void
    {
        $matricula = $this->matriculaActiva();
        $dependencia = $this->dependencia(null);

        $actualizada = app(AtualizarMatriculaAction::class)->executar($matricula, $this->dto($matricula, 'nova', '2026-02-20'));

        $this->assertSame($this->turmas['nova']->id, $actualizada->turma_id);
        $this->assertSame(
            [[$matricula->id, $this->turmas['nova']->id, $matricula->ano_lectivo_id, '2026-02-20', $this->turmas['original']->id]],
            $dependencia->chamadas,
        );
    }

    public function test_mudar_a_turma_bloqueado_da_erro_em_turma_e_nada_muda(): void
    {
        $matricula = $this->matriculaActiva();
        $this->dependencia('Esta matrícula tem dependências.');

        try {
            app(AtualizarMatriculaAction::class)->executar($matricula, $this->dto($matricula, 'nova', '2026-02-10'));
            $this->fail('Devia bloquear.');
        } catch (ValidationException $e) {
            $this->assertSame(['turma_id' => ['Esta matrícula tem dependências.']], $e->errors());
        }

        $this->assertSame($this->turmas['original']->id, $matricula->refresh()->turma_id);
    }

    public function test_mudar_so_a_data_bloqueado_da_erro_em_data_matricula(): void
    {
        $matricula = $this->matriculaActiva();
        $this->dependencia('A data não pode mudar.');

        try {
            app(AtualizarMatriculaAction::class)->executar($matricula, $this->dto($matricula, 'original', '2026-04-01'));
            $this->fail('Devia bloquear.');
        } catch (ValidationException $e) {
            $this->assertSame(['data_matricula' => ['A data não pode mudar.']], $e->errors());
        }

        $this->assertSame('2026-02-10', $matricula->refresh()->data_matricula->toDateString());
    }

    public function test_um_servico_etiquetado_que_nao_implementa_o_contrato_e_um_erro_de_configuracao(): void
    {
        $matricula = $this->matriculaActiva();
        $this->app->instance('dependencia.invalida', new stdClass());
        $this->app->tag(['dependencia.invalida'], DependenciasRegistadasDaMatricula::ETIQUETA);

        $this->expectException(LogicException::class);

        app(AtualizarMatriculaAction::class)->executar($matricula, $this->dto($matricula, 'nova', '2026-02-10'));
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Matricula/tests/Feature/DependenciasDaMatriculaTest.php`
Expected: FAIL (contrato e registo inexistentes).

- [ ] **Step 3: Implementar**

`Modules/Matricula/app/Contracts/DependenciasDaMatricula.php`:
```php
<?php

namespace Modules\Matricula\Contracts;

use Modules\Matricula\Models\Matricula;

/**
 * Implementada por módulos que dependem dos dados académicos de uma matrícula (ex.: propinas geradas para a
 * turma, o ano lectivo e o mês da matrícula) sem que a Matrícula os importe. Devolve o motivo em PT-PT quando a
 * edição deve ser bloqueada (indicando o fluxo correcto), ou null. A matrícula chega bloqueada e ainda com os
 * valores actuais. Registo: $this->app->tag([Classe::class], DependenciasRegistadasDaMatricula::ETIQUETA).
 */
interface DependenciasDaMatricula
{
    public function bloqueiaAlteracao(Matricula $matricula, int $turmaNovaId, int $anoLectivoNovoId, string $dataMatriculaNova): ?string;
}
```

`Modules/Matricula/app/Support/DependenciasRegistadasDaMatricula.php`:
```php
<?php

namespace Modules\Matricula\Support;

use Illuminate\Contracts\Container\Container;
use LogicException;
use Modules\Matricula\Contracts\DependenciasDaMatricula;
use Modules\Matricula\Models\Matricula;

/**
 * Consulta todas as DependenciasDaMatricula registadas na etiqueta e devolve o primeiro motivo.
 * Sem nenhuma registada (outro módulo desactivado), nada bloqueia.
 */
class DependenciasRegistadasDaMatricula
{
    public const ETIQUETA = 'matricula.dependencias';

    public function __construct(private Container $container)
    {
    }

    public function bloqueiaAlteracao(Matricula $matricula, int $turmaNovaId, int $anoLectivoNovoId, string $dataMatriculaNova): ?string
    {
        foreach ($this->container->tagged(self::ETIQUETA) as $dependencia) {
            if (! $dependencia instanceof DependenciasDaMatricula) {
                throw new LogicException('O serviço registado na etiqueta ' . self::ETIQUETA . ' (' . get_debug_type($dependencia) . ') tem de implementar ' . DependenciasDaMatricula::class . '.');
            }

            if (($motivo = $dependencia->bloqueiaAlteracao($matricula, $turmaNovaId, $anoLectivoNovoId, $dataMatriculaNova)) !== null) {
                return $motivo;
            }
        }

        return null;
    }
}
```

`Modules/Matricula/app/Actions/AtualizarMatriculaAction.php` (substituir o ficheiro inteiro):
```php
<?php

namespace Modules\Matricula\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Matricula\DTO\MatriculaDTO;
use Modules\Matricula\Models\Matricula;
use Modules\Matricula\Services\ValidadorMatriculaService;
use Modules\Matricula\Support\DependenciasRegistadasDaMatricula;
use Modules\Turma\Models\Turma;

class AtualizarMatriculaAction
{
    public function __construct(
        private ValidadorMatriculaService $validador,
        private DependenciasRegistadasDaMatricula $dependencias,
    ) {
    }

    public function executar(
        Matricula $matricula,
        MatriculaDTO $dto,
        ?int $utilizadorId = null,
    ): Matricula {
        return DB::transaction(function () use ($matricula, $dto, $utilizadorId) {
            // Bloqueada e relida antes de tudo: quem cria registos dependentes desta matrícula (ex.: propinas)
            // bloqueia-a também, por isso a verificação das dependências e a gravação não podem ser intercaladas.
            $matricula = Matricula::query()->whereKey($matricula->getKey())->lockForUpdate()->firstOrFail();

            if ($matricula->estado->eTerminal()) {
                throw ValidationException::withMessages([
                    'estado' => 'Não é possível editar uma matrícula já concluída, cancelada ou transferida.',
                ]);
            }

            $turma = Turma::query()
                ->with(['curso', 'anoLectivo'])
                ->findOrFail($dto->turmaId);

            $this->validador->validarTurma($turma, $dto->anoLectivoId);

            $aluno = $matricula->aluno;

            $this->validador->garantirEnquadramentoAcademico($aluno, $turma, $utilizadorId);
            $this->validador->validarMatriculaNaoDuplicada($aluno, $turma, $matricula->id);

            $dataNova = CarbonImmutable::parse($dto->dataMatricula)->toDateString();
            $motivo = $this->dependencias->bloqueiaAlteracao($matricula, (int) $turma->id, (int) $turma->ano_lectivo_id, $dataNova);

            if ($motivo !== null) {
                $mudaTurmaOuAno = (int) $turma->id !== (int) $matricula->turma_id
                    || (int) $turma->ano_lectivo_id !== (int) $matricula->ano_lectivo_id;

                throw ValidationException::withMessages([$mudaTurmaOuAno ? 'turma_id' : 'data_matricula' => $motivo]);
            }

            $matricula->update([
                'turma_id' => $turma->id,
                'ano_lectivo_id' => $turma->ano_lectivo_id,
                'data_matricula' => $dto->dataMatricula,
                'observacoes' => $dto->observacoes,
                'editado_por' => $utilizadorId,
            ]);

            return $matricula->fresh();
        });
    }
}
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Matricula tests/Feature/Arquitectura/FronteiraMatriculaFinanceiroTest.php`
Expected: PASS (4 testes novos; `MatriculaActionTest`/`MatriculaHttpTest` de actualização continuam verdes — sem dependências registadas nada bloqueia).

- [ ] **Step 5: Stage**

```bash
git add Modules/Matricula/app/Contracts/DependenciasDaMatricula.php Modules/Matricula/app/Support/DependenciasRegistadasDaMatricula.php Modules/Matricula/app/Actions/AtualizarMatriculaAction.php Modules/Matricula/tests/Feature/DependenciasDaMatriculaTest.php
```

---

### Task 8: Financeiro — cancelar as propinas futuras na transacção da matrícula (Q5, Q6; D1, D5, D8)

**Files:**
- Create: `Modules/Financeiro/app/Services/CancelarPropinasFuturasDaMatricula.php`
- Create: `Modules/Financeiro/app/Listeners/CancelarPropinasAoTerminarMatricula.php`
- Modify: `Modules/Financeiro/app/Providers/FinanceiroServiceProvider.php` (+ `boot()` com `Event::listen`)
- Test: `Modules/Financeiro/tests/Feature/CancelarPropinasFuturasDaMatriculaTest.php`, `Modules/Financeiro/tests/Feature/ListenersDaMatriculaTest.php`

**Interfaces:**
- Consumes: T2 (`CancelamentoDePropinas`), T6 (`MatriculaEstadoAlterado`, `AlterarEstadoMatriculaAction` transaccional), `BloqueioDePropinas`, `EstadoMatriculaEnum`.
- Produces:
  - `CancelarPropinasFuturasDaMatricula::ESTADOS_TERMINAIS` = `[CANCELADA, TRANSFERIDA, CONCLUIDA]` (`EstadoMatriculaEnum`).
  - `CancelarPropinasFuturasDaMatricula::executar(Matricula $matricula, string $dataEfeito, ?int $utilizadorId, bool $reconciliacao = false): list<int>` — exige transacção aberta e a matrícula **já bloqueada** pelo chamador; se a matrícula não estiver num estado terminal devolve `[]`; cancela (D3, relidas sob `BloqueioDePropinas`) as propinas com `estado = Em Aberto` e `periodo_inicio > $dataEfeito`; devolve os ids cancelados. Idempotente.
  - `CancelarPropinasAoTerminarMatricula::handle(MatriculaEstadoAlterado $evento): void` — síncrono; ignora estados não terminais e desconhecidos; abre `DB::transaction` (aninhada na da Matrícula → *savepoint*; sozinha se alguém despachar fora de transacção); relê a matrícula com `lockForUpdate` e chama o serviço. Nunca apanha excepções.
  - `FinanceiroServiceProvider::boot()` regista o listener (e, na Task 10, o da geração).

- [ ] **Step 1: Escrever os testes que falham**

`Modules/Financeiro/tests/Feature/CancelarPropinasFuturasDaMatriculaTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\CancelarPropinasFuturasDaMatricula;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Matricula\Actions\AlterarEstadoMatriculaAction;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Events\MatriculaEstadoAlterado;
use Modules\Matricula\Models\Matricula;
use Modules\Matricula\Models\MatriculaHistorico;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use RuntimeException;
use Tests\TestCase;

/**
 * Spec §9 + Q5/Q6: quando a matrícula passa a Cancelada, Transferida ou Concluída, as propinas com início depois
 * da data de efeito, sem pagamentos, são canceladas na MESMA transacção; dívidas e pagamentos ficam.
 */
class CancelarPropinasFuturasDaMatriculaTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

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

    /**
     * Matrícula Activa de 01/09/2026 com as 10 propinas mensais (Set/2026 … Jun/2027), todas Em Aberto.
     *
     * @return array{0: Matricula, 1: array<int, Propina>, 2: PlanoPropina}
     */
    private function matriculaComDezPropinas(): array
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propinas = [];

        foreach (range(1, 10) as $ordem) {
            $propinas[$ordem] = $this->propina($matricula, $plano, $ordem);
        }

        return [$matricula, $propinas, $plano];
    }

    /** @return list<int> ordens das propinas Canceladas da matrícula */
    private function ordensCanceladas(Matricula $matricula): array
    {
        return Propina::query()->where('matricula_id', $matricula->id)->where('estado', EstadoCobranca::CANCELADA->value)
            ->orderBy('ordem')->pluck('ordem')->map(fn ($ordem) => (int) $ordem)->all();
    }

    private function alterar(Matricula $matricula, EstadoMatriculaEnum $estado, ?string $dataFim = null, ?int $utilizadorId = null): Matricula
    {
        return app(AlterarEstadoMatriculaAction::class)->executar($matricula, $estado, $utilizadorId, $dataFim);
    }

    public function test_cancelar_a_matricula_cancela_so_as_futuras_sem_pagamentos(): void
    {
        [$matricula, $propinas] = $this->matriculaComDezPropinas();
        $this->comEstado($propinas[4], EstadoCobranca::PARCIALMENTE_PAGA, 500_000); // Dez: pagamento antecipado
        $this->historicoPagamentoFalso()->marcar($propinas[7]);                     // Mar: já teve pagamentos
        $admin = $this->adminEscola();

        $this->alterar($matricula, EstadoMatriculaEnum::CANCELADA, null, $admin->id);

        // data_fim = hoje (16/10/2026): Set e Out (já começaram) ficam como dívida; Dez e Mar ficam (pagamentos).
        $this->assertSame([3, 5, 6, 8, 9, 10], $this->ordensCanceladas($matricula));
        $novembro = $propinas[3]->refresh();
        $this->assertSame('Matrícula cancelada em 16/10/2026 (estado da matrícula).', $novembro->motivo_cancelamento);
        $this->assertSame($admin->id, (int) $novembro->cancelado_por);
        $this->assertSame(EstadoCobranca::EM_ABERTO, $propinas[1]->refresh()->estado);
        $this->assertSame(EstadoCobranca::EM_ABERTO, $propinas[2]->refresh()->estado);
        $this->assertSame(EstadoCobranca::PARCIALMENTE_PAGA, $propinas[4]->refresh()->estado);
        $this->assertSame(EstadoCobranca::EM_ABERTO, $propinas[7]->refresh()->estado);
        $this->assertPropinasCoerentes();
    }

    public function test_transferida_com_data_de_fim_retroactiva_usa_essa_data(): void
    {
        [$matricula] = $this->matriculaComDezPropinas();

        $this->alterar($matricula, EstadoMatriculaEnum::TRANSFERIDA, '2026-09-20');

        $this->assertSame([2, 3, 4, 5, 6, 7, 8, 9, 10], $this->ordensCanceladas($matricula));
        $this->assertSame(
            'Matrícula transferida em 20/09/2026 (estado da matrícula).',
            Propina::query()->where('matricula_id', $matricula->id)->where('ordem', 2)->value('motivo_cancelamento'),
        );
    }

    public function test_concluida_tambem_cancela_as_futuras_q6(): void
    {
        [$matricula] = $this->matriculaComDezPropinas();

        $this->alterar($matricula, EstadoMatriculaEnum::CONCLUIDA, '2027-03-31');

        $this->assertSame([8, 9, 10], $this->ordensCanceladas($matricula));
        $this->assertStringStartsWith('Matrícula concluída em 31/03/2027', Propina::query()->where('matricula_id', $matricula->id)->where('ordem', 8)->value('motivo_cancelamento'));
    }

    public function test_o_periodo_que_comeca_na_data_de_efeito_fica(): void
    {
        [$matricula] = $this->matriculaComDezPropinas();

        // O1: a data de fim de uma Cancelada não pode ser futura; Out/2026 começa a 01/10 e fica (não é "depois").
        $this->alterar($matricula, EstadoMatriculaEnum::CANCELADA, '2026-10-01');

        $this->assertSame([3, 4, 5, 6, 7, 8, 9, 10], $this->ordensCanceladas($matricula));
    }

    public function test_nao_toca_nas_propinas_de_outras_matriculas(): void
    {
        [$matricula, , $plano] = $this->matriculaComDezPropinas();
        $outra = $this->matriculaActiva($matricula->turma);
        $daOutra = $this->propina($outra, $plano, 10);

        $this->alterar($matricula, EstadoMatriculaEnum::CANCELADA);

        $this->assertSame(EstadoCobranca::EM_ABERTO, $daOutra->refresh()->estado);
    }

    public function test_uma_falha_no_cancelamento_reverte_a_mudanca_de_estado_q5(): void
    {
        [$matricula] = $this->matriculaComDezPropinas();
        $historicoAntes = MatriculaHistorico::query()->where('matricula_id', $matricula->id)->count();
        Propina::updating(function () {
            throw new RuntimeException('Falha simulada ao cancelar.');
        });

        try {
            $this->alterar($matricula, EstadoMatriculaEnum::CANCELADA);
            $this->fail('A falha devia propagar.');
        } catch (RuntimeException $e) {
            $this->assertSame('Falha simulada ao cancelar.', $e->getMessage());
        }

        $this->assertSame(EstadoMatriculaEnum::ACTIVA, $matricula->refresh()->estado);
        $this->assertNull($matricula->data_fim);
        $this->assertSame($historicoAntes, MatriculaHistorico::query()->where('matricula_id', $matricula->id)->count());
        $this->assertSame([], $this->ordensCanceladas($matricula));
    }

    public function test_o_servico_e_idempotente_e_ignora_matriculas_nao_terminadas(): void
    {
        [$matricula] = $this->matriculaComDezPropinas();
        $servico = app(CancelarPropinasFuturasDaMatricula::class);

        $this->assertSame([], DB::transaction(fn () => $servico->executar($matricula, '2026-10-16', null)), 'Activa: nada a cancelar.');

        $matricula->forceFill(['estado' => EstadoMatriculaEnum::CANCELADA, 'data_fim' => '2026-10-16'])->save();
        $primeira = DB::transaction(fn () => $servico->executar($matricula->refresh(), '2026-10-16', null));
        $segunda = DB::transaction(fn () => $servico->executar($matricula->refresh(), '2026-10-16', null));

        $this->assertCount(8, $primeira);
        $this->assertSame([], $segunda);
    }

    public function test_estados_que_o_financeiro_nao_conhece_sao_ignorados(): void
    {
        [$matricula] = $this->matriculaComDezPropinas();

        // Ex.: um futuro estado "Trancada" (plano de trancamento): o listener não pode falhar nem cancelar nada.
        DB::transaction(fn () => Event::dispatch(new MatriculaEstadoAlterado($matricula->id, EstadoMatriculaEnum::ACTIVA->value, 99, '2026-10-16', null)));

        $this->assertSame([], $this->ordensCanceladas($matricula));
    }
}
```

`Modules/Financeiro/tests/Feature/ListenersDaMatriculaTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Financeiro\Listeners\CancelarPropinasAoTerminarMatricula;
use Modules\Matricula\Events\MatriculaEstadoAlterado;
use Tests\TestCase;

/**
 * D1: o Financeiro escuta o evento da Matrícula com registo explícito (FinanceiroServiceProvider::boot), uma só vez.
 * O cancelamento é síncrono (corre na transacção da matrícula, Q5). NUNCA usar Event::fake sem lista de eventos em testes
 * que dependam destes listeners: desliga-os.
 */
class ListenersDaMatriculaTest extends TestCase
{
    // O TestCase cria o tenant de teste: sem RefreshDatabase, o 2.º teste colide numa base persistente (PostgreSQL).
    use RefreshDatabase;

    /** @return list<string> */
    private function listenersRegistados(): array
    {
        return array_values(array_filter(
            Event::getRawListeners()[MatriculaEstadoAlterado::class] ?? [],
            'is_string',
        ));
    }

    public function test_o_cancelamento_esta_registado_uma_so_vez_e_e_sincrono(): void
    {
        $this->assertSame(1, count(array_keys($this->listenersRegistados(), CancelarPropinasAoTerminarMatricula::class, true)));
        $this->assertFalse(is_subclass_of(CancelarPropinasAoTerminarMatricula::class, ShouldHandleEventsAfterCommit::class));
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/CancelarPropinasFuturasDaMatriculaTest.php Modules/Financeiro/tests/Feature/ListenersDaMatriculaTest.php`
Expected: FAIL (serviço e listener inexistentes; nada é cancelado).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Services/CancelarPropinasFuturasDaMatricula.php`:
```php
<?php

namespace Modules\Financeiro\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Support\BloqueioDePropinas;
use Modules\Financeiro\Support\CancelamentoDePropinas;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;

/**
 * Spec §9 + Q6: quando a matrícula termina (Cancelada, Transferida ou Concluída), cancela as propinas que começam
 * DEPOIS da data de efeito e que ainda se podem cancelar (Em Aberto, sem pagamentos nem histórico — D3). As outras
 * (períodos já começados, pagas, parciais, com histórico) ficam: são dívida e continuam pagáveis. Idempotente.
 * Usado pelo listener (na transacção da mudança de estado, Q5) e pela reconciliação diária.
 */
class CancelarPropinasFuturasDaMatricula
{
    /** @var list<EstadoMatriculaEnum> */
    public const ESTADOS_TERMINAIS = [EstadoMatriculaEnum::CANCELADA, EstadoMatriculaEnum::TRANSFERIDA, EstadoMatriculaEnum::CONCLUIDA];

    public function __construct(
        private BloqueioDePropinas $bloqueio,
        private CancelamentoDePropinas $cancelamento,
    ) {
    }

    /**
     * Na transacção do chamador e com a matrícula JÁ bloqueada por ele (D5: matrícula → propinas por id crescente).
     *
     * @return list<int> ids das propinas canceladas agora
     */
    public function executar(Matricula $matricula, string $dataEfeito, ?int $utilizadorId, bool $reconciliacao = false): array
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Cancelar as propinas futuras de uma matrícula exige uma transacção aberta, com a matrícula bloqueada.');
        }

        if (! in_array($matricula->estado, self::ESTADOS_TERMINAIS, true)) {
            return [];
        }

        $ids = Propina::query()
            ->where('matricula_id', $matricula->id)
            ->where('estado', EstadoCobranca::EM_ABERTO->value)
            ->where('periodo_inicio', '>', $dataEfeito)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($ids === []) {
            return [];
        }

        $motivo = $this->motivo($matricula->estado, $dataEfeito, $reconciliacao);
        $canceladas = [];

        foreach ($this->bloqueio->bloquear($ids) as $propina) {
            // Relida sob bloqueio: um pagamento ou um cancelamento manual concorrente, já confirmado, é respeitado.
            if ($propina->periodo_inicio->toDateString() <= $dataEfeito || $this->cancelamento->motivoParaNaoCancelar($propina) !== null) {
                continue;
            }

            $this->cancelamento->cancelar($propina, $motivo, $utilizadorId);
            $canceladas[] = (int) $propina->id;
        }

        return $canceladas;
    }

    private function motivo(EstadoMatriculaEnum $estado, string $dataEfeito, bool $reconciliacao): string
    {
        $accao = match ($estado) {
            EstadoMatriculaEnum::CANCELADA => 'cancelada',
            EstadoMatriculaEnum::TRANSFERIDA => 'transferida',
            EstadoMatriculaEnum::CONCLUIDA => 'concluída',
            default => throw new LogicException("A matrícula não está num estado terminal ({$estado->label()})."),
        };
        $data = CarbonImmutable::createFromFormat('!Y-m-d', $dataEfeito)->format('d/m/Y');

        return "Matrícula {$accao} em {$data} (estado da matrícula" . ($reconciliacao ? ', reconciliação diária' : '') . ').';
    }
}
```

`Modules/Financeiro/app/Listeners/CancelarPropinasAoTerminarMatricula.php`:
```php
<?php

namespace Modules\Financeiro\Listeners;

use Illuminate\Support\Facades\DB;
use Modules\Financeiro\Services\CancelarPropinasFuturasDaMatricula;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Events\MatriculaEstadoAlterado;
use Modules\Matricula\Models\Matricula;

/**
 * Q5: SÍNCRONO, corre dentro da transacção da mudança de estado da matrícula (o evento é despachado lá dentro).
 * Uma excepção aqui reverte o estado, a data de fim e o histórico da matrícula: nunca se apanha. Se alguém
 * despachar o evento fora de uma transacção, esta abre a sua. A reconciliação diária é a rede de segurança.
 */
class CancelarPropinasAoTerminarMatricula
{
    public function __construct(private CancelarPropinasFuturasDaMatricula $cancelar)
    {
    }

    public function handle(MatriculaEstadoAlterado $evento): void
    {
        $estado = EstadoMatriculaEnum::tryFrom($evento->estadoNovo);

        // Activa, Pendente e estados que o Financeiro não conhece (ex.: Trancada): nada a fazer aqui.
        if ($estado === null || ! in_array($estado, CancelarPropinasFuturasDaMatricula::ESTADOS_TERMINAIS, true)) {
            return;
        }

        DB::transaction(function () use ($evento) {
            // A Matrícula já a bloqueou nesta transacção; relê-se para usar o estado acabado de gravar.
            $matricula = Matricula::query()->whereKey($evento->matriculaId)->lockForUpdate()->first();

            if ($matricula === null) {
                return;
            }

            $this->cancelar->executar($matricula, $evento->dataEfeito, $evento->utilizadorId);
        });
    }
}
```

Em `Modules/Financeiro/app/Providers/FinanceiroServiceProvider.php`:
1. `use` novos (ordem alfabética):
```php
use Illuminate\Support\Facades\Event;
use Modules\Financeiro\Listeners\CancelarPropinasAoTerminarMatricula;
use Modules\Matricula\Events\MatriculaEstadoAlterado;
```
2. Novo método, a seguir a `register()`:
```php

    public function boot(): void
    {
        parent::boot();

        // D1 (plano F3): registo explícito (o Financeiro não tem EventServiceProvider nem descoberta de eventos).
        // O cancelamento é síncrono e corre na transacção da mudança de estado da matrícula (Q5).
        Event::listen(MatriculaEstadoAlterado::class, CancelarPropinasAoTerminarMatricula::class);
    }
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Feature/CancelarPropinasFuturasDaMatriculaTest.php Modules/Financeiro/tests/Feature/ListenersDaMatriculaTest.php Modules/Matricula tests/Feature/Arquitectura`
Expected: PASS (8 + 1 testes novos; Matrícula e arquitectura verdes — `CanceladoresDePropinaTest` incluído: o serviço e o listener não escrevem campos de cancelamento, chamam o ponto único).

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Services/CancelarPropinasFuturasDaMatricula.php Modules/Financeiro/app/Listeners/CancelarPropinasAoTerminarMatricula.php Modules/Financeiro/app/Providers/FinanceiroServiceProvider.php Modules/Financeiro/tests/Feature/CancelarPropinasFuturasDaMatriculaTest.php Modules/Financeiro/tests/Feature/ListenersDaMatriculaTest.php
```

---

### Task 9: Financeiro — `PropinasDaMatricula` bloqueia turma, ano e mês com propinas activas (Q8, D11)

**Files:**
- Create: `Modules/Financeiro/app/Support/PropinasDaMatricula.php`
- Modify: `Modules/Financeiro/app/Providers/FinanceiroServiceProvider.php` (etiqueta `matricula.dependencias`)
- Test: `Modules/Financeiro/tests/Feature/PropinasDaMatriculaTest.php`

**Interfaces:**
- Consumes: T7 (`DependenciasDaMatricula`, `DependenciasRegistadasDaMatricula::ETIQUETA`, `AtualizarMatriculaAction`), `Propina::scopeActivas` (estados 1–3), rota `matriculas.update` (`PUT alunos/{aluno}/matriculas/{matricula}`, `can:matricula.editar`).
- Produces: `PropinasDaMatricula implements DependenciasDaMatricula`, etiquetada. Com ≥ 1 propina activa: muda turma ou ano → motivo com o fluxo (Transferida + nova matrícula, ou cancelar as propinas); muda o **mês/ano** de `data_matricula` → motivo "só pode corrigir o dia dentro do mesmo mês". Sem propinas activas (nenhuma, ou só Canceladas/Anuladas) → `null`. Nada muda → `null` sem consultar a BD.

- [ ] **Step 1: Escrever o teste que falha**

`Modules/Financeiro/tests/Feature/PropinasDaMatriculaTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Matricula\Actions\AtualizarMatriculaAction;
use Modules\Matricula\DTO\MatriculaDTO;
use Modules\Matricula\Models\Matricula;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Turma\Models\Turma;
use Tests\TestCase;

/**
 * Q8 (§8.1): com propinas activas, a matrícula não muda directamente de turma nem de ano lectivo, nem de mês de
 * matrícula (as propinas foram geradas para essa turma/plano/ano e a partir desse mês). O dia, dentro do mesmo
 * mês, continua livre. A mensagem indica o fluxo correcto.
 */
class PropinasDaMatriculaTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

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

    /** @return array{0: Matricula, 1: Turma, 2: PlanoPropina} */
    private function cenario(): array
    {
        ['ano' => $ano, 'turma' => $turma, 'plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

        return [$matricula, $this->turma($ano, $turma->nivelAcademico), $plano];
    }

    private function actualizar(Matricula $matricula, Turma $turma, string $data): Matricula
    {
        return app(AtualizarMatriculaAction::class)->executar($matricula, new MatriculaDTO(
            turmaId: $turma->id,
            anoLectivoId: $turma->ano_lectivo_id,
            dataMatricula: $data,
            estado: null,
            observacoes: null,
        ));
    }

    public function test_com_propinas_activas_nao_muda_de_turma_e_a_mensagem_indica_a_transferencia(): void
    {
        [$matricula, $outraTurma, $plano] = $this->cenario();
        $this->propina($matricula, $plano, 1);

        try {
            $this->actualizar($matricula, $outraTurma, '2026-09-01');
            $this->fail('Devia bloquear.');
        } catch (ValidationException $e) {
            $mensagem = $e->errors()['turma_id'][0];
            $this->assertStringContainsString('1 propina(s) activa(s)', $mensagem);
            $this->assertStringContainsString('Transferida', $mensagem);
            $this->assertStringContainsString('Financeiro → Propinas', $mensagem);
        }

        $this->assertSame($matricula->turma_id, $matricula->refresh()->turma_id);
    }

    public function test_uma_propina_paga_tambem_conta_como_activa(): void
    {
        [$matricula, $outraTurma, $plano] = $this->cenario();
        $this->comEstado($this->propina($matricula, $plano, 1), EstadoCobranca::PAGA);

        $this->expectException(ValidationException::class);

        $this->actualizar($matricula, $outraTurma, '2026-09-01');
    }

    public function test_com_propinas_activas_nao_muda_o_mes_mas_muda_o_dia(): void
    {
        [$matricula, , $plano] = $this->cenario();
        $this->propina($matricula, $plano, 1);
        $turma = $matricula->turma;

        try {
            $this->actualizar($matricula, $turma, '2026-10-01');
            $this->fail('Mudar o mês devia ser bloqueado.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('mesmo mês', $e->errors()['data_matricula'][0]);
        }

        $this->assertSame('2026-09-15', $this->actualizar($matricula, $turma, '2026-09-15')->data_matricula->toDateString());
    }

    public function test_sem_propinas_ou_so_com_canceladas_muda_livremente(): void
    {
        [$matricula, $outraTurma, $plano] = $this->cenario();
        $turmaOriginal = $matricula->turma;

        // Sem propinas: muda de turma e de mês.
        $this->assertSame($outraTurma->id, $this->actualizar($matricula, $outraTurma, '2026-10-01')->turma_id);

        // Só com uma propina cancelada: volta à turma original e muda outra vez de mês.
        $this->comEstado($this->propina($matricula->refresh(), $plano, 1), EstadoCobranca::CANCELADA);
        $voltou = $this->actualizar($matricula->refresh(), $turmaOriginal, '2026-10-01');
        $this->assertSame($turmaOriginal->id, $voltou->turma_id);
        $this->assertSame('2026-11-03', $this->actualizar($voltou, $turmaOriginal, '2026-11-03')->data_matricula->toDateString());
    }

    public function test_por_http_o_erro_chega_ao_campo_turma(): void
    {
        [$matricula, $outraTurma, $plano] = $this->cenario();
        $this->propina($matricula, $plano, 1);
        $this->actingAs($this->adminEscola());

        $this->from('/matriculas')
            ->put(route('matriculas.update', [$matricula->aluno_id, $matricula->id]), [
                'turma_id' => $outraTurma->id,
                'ano_lectivo_id' => $outraTurma->ano_lectivo_id,
                'data_matricula' => '2026-09-01',
            ])
            ->assertSessionHasErrors('turma_id');

        $this->assertSame($matricula->turma_id, $matricula->refresh()->turma_id);
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/PropinasDaMatriculaTest.php`
Expected: FAIL (nada bloqueia: `PropinasDaMatricula` inexistente).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Support/PropinasDaMatricula.php`:
```php
<?php

namespace Modules\Financeiro\Support;

use Modules\Financeiro\Models\Propina;
use Modules\Matricula\Contracts\DependenciasDaMatricula;
use Modules\Matricula\Models\Matricula;

/**
 * Q8 (§8.1 do plano de fases): as propinas são geradas para a turma (plano aplicável), o ano lectivo e a partir do
 * mês da matrícula. Com propinas activas (Em Aberto, Parcialmente Paga ou Paga), mudar turma ou ano lectivo
 * directamente deixaria propinas de outra turma/plano/ano (L10), e mudar o mês tornaria a elegibilidade falsa.
 * A transferência segue o fluxo próprio: estado Transferida (cancela as futuras sem pagamentos, mantém dívidas) +
 * nova matrícula na turma de destino, que gera pelo seu plano.
 */
class PropinasDaMatricula implements DependenciasDaMatricula
{
    public function bloqueiaAlteracao(Matricula $matricula, int $turmaNovaId, int $anoLectivoNovoId, string $dataMatriculaNova): ?string
    {
        $mudaTurmaOuAno = $turmaNovaId !== (int) $matricula->turma_id || $anoLectivoNovoId !== (int) $matricula->ano_lectivo_id;
        $mudaMes = substr($dataMatriculaNova, 0, 7) !== $matricula->data_matricula?->format('Y-m');

        if (! $mudaTurmaOuAno && ! $mudaMes) {
            return null;
        }

        $activas = Propina::query()->activas()->where('matricula_id', $matricula->id)->count();

        if ($activas === 0) {
            return null;
        }

        if ($mudaTurmaOuAno) {
            return "Esta matrícula já tem {$activas} propina(s) activa(s) geradas para a turma e o ano lectivo actuais: não é possível mudá-los directamente. "
                . 'Para mudar de turma, altere o estado da matrícula para Transferida (as propinas futuras sem pagamentos são canceladas e as dívidas mantêm-se) '
                . 'e crie uma nova matrícula na turma de destino. Se as propinas foram geradas por engano, cancele-as primeiro em Financeiro → Propinas.';
        }

        return "Esta matrícula já tem {$activas} propina(s) activa(s) calculadas a partir do mês da matrícula: só pode corrigir o dia dentro do mesmo mês. "
            . 'Para mudar o mês, cancele primeiro as propinas desta matrícula em Financeiro → Propinas e gere-as de novo depois da correcção.';
    }
}
```

Em `Modules/Financeiro/app/Providers/FinanceiroServiceProvider.php`:
1. `use` novos (ordem alfabética):
```php
use Modules\Financeiro\Support\PropinasDaMatricula;
use Modules\Matricula\Support\DependenciasRegistadasDaMatricula;
```
2. Em `register()`, a seguir à linha `$this->app->tag([DependenciasDePlanosPropina::class], DependenciasRegistadasDoAnoLectivo::ETIQUETA);`:
```php
        $this->app->tag([PropinasDaMatricula::class], DependenciasRegistadasDaMatricula::ETIQUETA);
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Feature/PropinasDaMatriculaTest.php Modules/Matricula`
Expected: PASS (5 testes novos; nos testes de Matrícula não há propinas, por isso nada bloqueia).

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Support/PropinasDaMatricula.php Modules/Financeiro/app/Providers/FinanceiroServiceProvider.php Modules/Financeiro/tests/Feature/PropinasDaMatriculaTest.php
```

---

### Task 10: Geração ao activar (Q7, D9) e a geração automática não desfaz cancelamentos (D10)

**Files:**
- Modify: `Modules/Financeiro/app/Enums/OrigemGeracaoPropina.php` (+ `automatica()`)
- Modify: `Modules/Financeiro/app/DTO/PedidoGeracaoPropinasDTO.php` (+ `daMatricula()`)
- Modify: `Modules/Financeiro/app/Services/GerarPropinasService.php` (D10)
- Create: `Modules/Financeiro/app/Listeners/GerarPropinasAoActivarMatricula.php`
- Modify: `Modules/Financeiro/app/Providers/FinanceiroServiceProvider.php` (+ `Event::listen`)
- Test: `Modules/Financeiro/tests/Unit/OrigemGeracaoPropinaTest.php`, `Modules/Financeiro/tests/Unit/PedidoGeracaoPropinasDTOTest.php` (+ casos), `Modules/Financeiro/tests/Feature/GerarPropinasAoActivarMatriculaTest.php` (novo), `Modules/Financeiro/tests/Feature/ListenersDaMatriculaTest.php` (+ caso), `Modules/Financeiro/tests/Feature/GerarPropinasPreVisualizacaoTest.php` (O2: o item a criar ganha a chave `regenera_cancelada`)

**Interfaces:**
- Consumes: T6 (evento), T8 (registo em `boot()`), `GerarPropinasService::gerar`, `GeracaoDePropinasDesactivada::activa()`, `RegraCobranca`, `TenantContext::{temTenant,id}`, `Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit`.
- Produces:
  - `OrigemGeracaoPropina::automatica(): bool` (`COMANDO`, `MATRICULA`).
  - `PedidoGeracaoPropinasDTO::daMatricula(int $anoLectivoId, string $numeroRegistoMatricula): self` (âmbito `MATRICULA`, origem `MATRICULA`).
  - `GerarPropinasService`: com origem automática, um período sobreposto a uma propina **Cancelada** da matrícula fica ignorado ("Período cancelado (…): a geração automática não o recria; gere-o manualmente se for preciso."). A manual continua a regenerar, mas cada item a criar traz `regenera_cancelada` (`null` ou o aviso "Este período tinha sido cancelado em dd/mm/aaaa («motivo»): confirmar volta a cobrá-lo.") — **O2 aprovado**; a UI mostra-o na Task 12.
  - `GerarPropinasAoActivarMatricula implements ShouldHandleEventsAfterCommit`; `handle(MatriculaEstadoAlterado)`: só `estadoNovo = ACTIVA`, interruptor ligado e `gerar_automaticamente`; apanha **todos** os `Throwable` (`report()` excepto `ValidationException` + `Log::warning('financeiro.gerar-propinas.activacao-falhou', {tenant_id, matricula_id, excepcao, mensagem})`); com sucesso `Log::info('financeiro.gerar-propinas.activacao', {tenant_id, matricula_id} + totais)`.

- [ ] **Step 1: Escrever os testes que falham**

Em `Modules/Financeiro/tests/Unit/OrigemGeracaoPropinaTest.php`, novo método:
```php

    public function test_so_o_comando_e_a_activacao_da_matricula_sao_automaticas(): void
    {
        $this->assertSame(
            [OrigemGeracaoPropina::MATRICULA, OrigemGeracaoPropina::COMANDO],
            array_values(array_filter(OrigemGeracaoPropina::cases(), fn (OrigemGeracaoPropina $o) => $o->automatica())),
        );
    }
```

Em `Modules/Financeiro/tests/Unit/PedidoGeracaoPropinasDTOTest.php`, novo método (a seguir ao primeiro teste):
```php

    public function test_o_pedido_da_activacao_e_de_uma_matricula_com_origem_matricula(): void
    {
        $pedido = PedidoGeracaoPropinasDTO::daMatricula(7, 'MR-0001');

        $this->assertSame(7, $pedido->ano_lectivo_id);
        $this->assertSame(AmbitoGeracaoPropinas::MATRICULA, $pedido->ambito);
        $this->assertSame(OrigemGeracaoPropina::MATRICULA, $pedido->origem);
        $this->assertSame('MR-0001', $pedido->matricula_numero);
        $this->assertFalse($pedido->incluir_periodos_anteriores);
    }
```

Em `Modules/Financeiro/tests/Feature/ListenersDaMatriculaTest.php`, acrescentar o `use Modules\Financeiro\Listeners\GerarPropinasAoActivarMatricula;` e o método:
```php

    public function test_a_geracao_esta_registada_uma_so_vez_e_corre_depois_do_commit(): void
    {
        $this->assertSame(1, count(array_keys($this->listenersRegistados(), GerarPropinasAoActivarMatricula::class, true)));
        $this->assertTrue(is_subclass_of(GerarPropinasAoActivarMatricula::class, ShouldHandleEventsAfterCommit::class));
    }
```

`Modules/Financeiro/tests/Feature/GerarPropinasAoActivarMatriculaTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Mockery;
use Modules\Financeiro\Actions\CancelarPropinaAction;
use Modules\Financeiro\DTO\CancelarPropinaDTO;
use Modules\Financeiro\DTO\PedidoGeracaoPropinasDTO;
use Modules\Financeiro\Enums\AmbitoGeracaoPropinas;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Services\GerarPropinasService;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Matricula\Actions\AlterarEstadoMatriculaAction;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Events\MatriculaEstadoAlterado;
use Modules\Matricula\Models\Matricula;
use RuntimeException;
use Tests\TestCase;

/**
 * Q7 + §8.2/3: ao activar uma matrícula, as propinas geram-se DEPOIS do commit, só com «gerar automaticamente»
 * (e o interruptor de segurança ligado); uma falha nunca impede nem desfaz a activação e o comando diário recupera.
 * D10: a geração automática nunca recria um período cancelado.
 */
class GerarPropinasAoActivarMatriculaTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-09-15 10:00:00', 'UTC'));
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

    private function pendente(): Matricula
    {
        ['turma' => $turma] = $this->cenarioPropinas();

        return $this->matriculaActiva($turma, '2026-09-01', EstadoMatriculaEnum::PENDENTE);
    }

    private function activar(Matricula $matricula): Matricula
    {
        return app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::ACTIVA);
    }

    public function test_activar_gera_as_propinas_depois_do_commit_com_origem_matricula(): void
    {
        $this->ligar();
        $matricula = $this->pendente();
        $base = DB::transactionLevel();
        $nivelAoGravar = null;
        $estadoGravado = null;
        Propina::creating(function (Propina $propina) use (&$nivelAoGravar, &$estadoGravado) {
            $nivelAoGravar ??= DB::transactionLevel();
            $estadoGravado ??= Matricula::query()->find($propina->matricula_id)?->estado;
        });

        $this->activar($matricula);

        $this->assertSame(10, Propina::query()->where('matricula_id', $matricula->id)->count());
        $this->assertTrue(Propina::query()->where('matricula_id', $matricula->id)->get()->every(fn (Propina $p) => $p->origem_geracao === OrigemGeracaoPropina::MATRICULA));
        // Só a transacção da própria geração (por matrícula): a da mudança de estado já foi confirmada.
        $this->assertSame($base + 1, $nivelAoGravar);
        $this->assertSame(EstadoMatriculaEnum::ACTIVA, $estadoGravado);
        $this->assertPropinasCoerentes();
    }

    public function test_sem_gerar_automaticamente_nao_gera(): void
    {
        $matricula = $this->pendente();

        $this->activar($matricula);

        $this->assertSame(0, Propina::query()->count());
    }

    public function test_com_o_interruptor_de_seguranca_desligado_nao_gera_mas_activa(): void
    {
        $this->ligar();
        $matricula = $this->pendente();
        config()->set('financeiro.propinas.geracao_activa', false);

        $this->assertSame(EstadoMatriculaEnum::ACTIVA, $this->activar($matricula)->estado);
        $this->assertSame(0, Propina::query()->count());
    }

    public function test_so_reage_a_activacao(): void
    {
        $this->ligar();
        ['matricula' => $activa] = $this->cenarioPropinas();

        app(AlterarEstadoMatriculaAction::class)->executar($activa, EstadoMatriculaEnum::TRANSFERIDA);

        $this->assertSame(0, Propina::query()->count());
    }

    public function test_a_matricula_criada_ja_activa_tambem_gera(): void
    {
        // O despacho na criação está provado em MatriculaEstadoAlteradoTest; aqui, a reacção ao evento com estado anterior nulo.
        $this->ligar();
        ['matricula' => $matricula] = $this->cenarioPropinas();

        Event::dispatch(new MatriculaEstadoAlterado($matricula->id, null, EstadoMatriculaEnum::ACTIVA->value, '2026-09-01', null));

        $this->assertSame(10, Propina::query()->where('matricula_id', $matricula->id)->count());
    }

    public function test_uma_falha_na_geracao_nunca_impede_activar_e_o_comando_recupera(): void
    {
        Log::spy();
        $this->ligar();
        $matricula = $this->pendente();
        $falha = Mockery::mock(GerarPropinasService::class);
        $falha->shouldReceive('gerar')->andThrow(new RuntimeException('Falha simulada da geração.'));
        $this->app->instance(GerarPropinasService::class, $falha);

        $activada = $this->activar($matricula);

        $this->assertSame(EstadoMatriculaEnum::ACTIVA, $activada->estado);
        $this->assertSame(EstadoMatriculaEnum::ACTIVA, $matricula->refresh()->estado);
        $this->assertSame(0, Propina::query()->count());
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $mensagem, array $contexto = []) => $mensagem === 'financeiro.gerar-propinas.activacao-falhou'
                && ($contexto['matricula_id'] ?? null) === $matricula->id
                && ($contexto['mensagem'] ?? null) === 'Falha simulada da geração.')
            ->once();

        $this->app->forgetInstance(GerarPropinasService::class);
        $this->artisan('financeiro:gerar-propinas', ['--tenant' => $this->tenant->codigo])->assertSuccessful();
        $this->assertSame(10, Propina::query()->where('matricula_id', $matricula->id)->count());

        $this->artisan('financeiro:gerar-propinas', ['--tenant' => $this->tenant->codigo])->assertSuccessful();
        $this->assertSame(10, Propina::query()->where('matricula_id', $matricula->id)->count());
    }

    public function test_a_geracao_automatica_nao_recria_um_periodo_cancelado_mas_a_manual_sim(): void
    {
        $this->ligar();
        ['ano' => $ano, 'turma' => $turma, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->artisan('financeiro:gerar-propinas', ['--tenant' => $this->tenant->codigo])->assertSuccessful();
        $outubro = Propina::query()->where('matricula_id', $matricula->id)->where('periodo_inicio', '2026-10-01')->firstOrFail();
        app(CancelarPropinaAction::class)->executar($outubro, new CancelarPropinaDTO('Isenção de Outubro'), null);

        $this->artisan('financeiro:gerar-propinas', ['--tenant' => $this->tenant->codigo])->assertSuccessful();
        $this->assertSame(9, Propina::query()->activas()->where('matricula_id', $matricula->id)->count(), 'O comando não desfaz o cancelamento.');

        $previa = app(GerarPropinasService::class)->preVisualizar(PedidoGeracaoPropinasDTO::automatica($ano->id))->toArray()['matriculas'][0];
        $this->assertContains('Out/2026', array_column($previa['ignoradas'], 'periodo'));

        $manual = app(GerarPropinasService::class)->gerar(new PedidoGeracaoPropinasDTO(
            ano_lectivo_id: $ano->id,
            ambito: AmbitoGeracaoPropinas::TURMA,
            origem: OrigemGeracaoPropina::MANUAL,
            alvo_id: $turma->id,
        ));
        $this->assertSame(['Out/2026'], array_column($manual->toArray()['matriculas'][0]['criadas'], 'periodo'));
        $this->assertSame(
            [EstadoCobranca::CANCELADA, EstadoCobranca::EM_ABERTO],
            Propina::query()->where('matricula_id', $matricula->id)->where('periodo_inicio', '2026-10-01')->orderBy('id')->get()->pluck('estado')->all(),
        );
    }

    public function test_a_pre_visualizacao_manual_assinala_um_periodo_cancelado_que_volta_a_gerar_o2(): void
    {
        ['ano' => $ano, 'turma' => $turma, 'plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $outubro = $this->propina($matricula, $plano, 2);
        app(CancelarPropinaAction::class)->executar($outubro, new CancelarPropinaDTO('Isenção de Outubro'), null);

        $previa = app(GerarPropinasService::class)->preVisualizar(new PedidoGeracaoPropinasDTO(
            ano_lectivo_id: $ano->id,
            ambito: AmbitoGeracaoPropinas::TURMA,
            origem: OrigemGeracaoPropina::MANUAL,
            alvo_id: $turma->id,
        ))->toArray()['matriculas'][0];
        $avisos = array_column($previa['criadas'], 'regenera_cancelada', 'periodo');

        // 10:00 UTC = 11:00 em Luanda: cancelada a 15/09/2026 no dia da escola.
        $this->assertSame('Este período tinha sido cancelado em 15/09/2026 («Isenção de Outubro»): confirmar volta a cobrá-lo.', $avisos['Out/2026']);
        $this->assertNull($avisos['Set/2026']);
        $this->assertNull($avisos['Nov/2026']);
    }
}
```

Em `Modules/Financeiro/tests/Feature/GerarPropinasPreVisualizacaoTest.php`, em `test_mensal_set_a_jun_da_dez_propinas_com_o_snapshot_das_regras()`, substituir
```php
            'tardia' => false,
        ], $linha['criadas'][0]);
```
por
```php
            'tardia' => false,
            'regenera_cancelada' => null,
        ], $linha['criadas'][0]);
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Unit/OrigemGeracaoPropinaTest.php Modules/Financeiro/tests/Unit/PedidoGeracaoPropinasDTOTest.php Modules/Financeiro/tests/Feature/GerarPropinasAoActivarMatriculaTest.php Modules/Financeiro/tests/Feature/ListenersDaMatriculaTest.php`
Expected: FAIL (`automatica()`, `daMatricula()` e o listener inexistentes; o comando recria Outubro).

- [ ] **Step 3: Implementar**

Em `Modules/Financeiro/app/Enums/OrigemGeracaoPropina.php`, a seguir a `exigeMotivo()`:
```php

    /** Geração sem decisão humana no momento (D10 do plano F3): nunca recria um período cancelado. */
    public function automatica(): bool
    {
        return $this === self::COMANDO || $this === self::MATRICULA;
    }
```

Em `Modules/Financeiro/app/DTO/PedidoGeracaoPropinasDTO.php`, a seguir a `automatica()`:
```php

    /** Q7 (F3): geração ao activar uma matrícula, identificada como na geração manual (número de registo + ano). */
    public static function daMatricula(int $anoLectivoId, string $numeroRegistoMatricula): self
    {
        return new self(
            ano_lectivo_id: $anoLectivoId,
            ambito: AmbitoGeracaoPropinas::MATRICULA,
            origem: OrigemGeracaoPropina::MATRICULA,
            matricula_numero: $numeroRegistoMatricula,
        );
    }
```

Em `Modules/Financeiro/app/Services/GerarPropinasService.php`:
1. `use` novo (ordem alfabética): `use Modules\Financeiro\Enums\EstadoCobranca;`
2. No docblock da classe, a seguir ao ponto 7, acrescentar:
```php
 * 8. A geração automática (comando, activação da matrícula) nunca recria um período cancelado; a manual pode, e a
 *    pré-visualização assinala-o em `regenera_cancelada` (D10 e O2 de F3).
```
3. Em `preVisualizar()`, substituir
```php
                $resultado->juntar($this->calcular($matricula, $pedido, $contexto, $this->activas((int) $matricula->id)));
```
por
```php
                $resultado->juntar($this->calcular($matricula, $pedido, $contexto, $this->activas((int) $matricula->id), $this->canceladas((int) $matricula->id)));
```
4. Em `gravarMatricula()`, substituir
```php
        $resultado = $this->calcular($matricula, $pedido, $contexto, $this->activas((int) $matricula->id));
```
por
```php
        $resultado = $this->calcular($matricula, $pedido, $contexto, $this->activas((int) $matricula->id), $this->canceladas((int) $matricula->id));
```
5. A seguir ao método `activas()`, novos métodos:
```php

    /**
     * D10 (F3): a geração automática não recria um período cancelado — sem isto, o comando da noite seguinte
     * desfaria um cancelamento manual. A geração manual é uma decisão humana explícita e pode regenerar, mas a
     * pré-visualização assinala-o (O2, aprovado pelo dono).
     *
     * @return Collection<int, Propina>
     */
    private function canceladas(int $matriculaId): Collection
    {
        return Propina::query()
            ->where('matricula_id', $matriculaId)
            ->where('estado', EstadoCobranca::CANCELADA->value)
            ->orderBy('periodo_inicio')
            ->orderBy('id')
            ->get();
    }

    /** O2 (F3): o aviso mostrado na pré-visualização manual quando um período cancelado volta a ser cobrado. */
    private function avisoDeRegeneracao(Propina $cancelada): string
    {
        $em = $cancelada->cancelado_em?->copy()->setTimezone($this->relogio->fuso())->format('d/m/Y');

        return 'Este período tinha sido cancelado' . ($em !== null ? " em {$em}" : '')
            . " («{$cancelada->motivo_cancelamento}»): confirmar volta a cobrá-lo.";
    }
```
6. Em `calcular()`, substituir a assinatura
```php
    private function calcular(Matricula $matricula, PedidoGeracaoPropinasDTO $pedido, ContextoDeGeracao $contexto, Collection $activas): ResultadoGeracaoMatriculaDTO
```
por
```php
    private function calcular(Matricula $matricula, PedidoGeracaoPropinasDTO $pedido, ContextoDeGeracao $contexto, Collection $activas, Collection $canceladas): ResultadoGeracaoMatriculaDTO
```
(e no docblock, a seguir a `@param  Collection<int, Propina>  $activas …`, acrescentar ` * @param  Collection<int, Propina>  $canceladas  propinas canceladas da matrícula (D10 na automática; O2 na manual)`), e substituir
```php
            $this->avaliarPeriodo($matricula, $candidato['plano'], $candidato['periodo'], $pedido, $contexto, $activas, $resultado);
```
por
```php
            $this->avaliarPeriodo($matricula, $candidato['plano'], $candidato['periodo'], $pedido, $contexto, $activas, $canceladas, $resultado);
```
7. Em `avaliarPeriodo()`, substituir
```php
        Collection $activas,
        ResultadoGeracaoMatriculaDTO $resultado,
    ): void {
```
por
```php
        Collection $activas,
        Collection $canceladas,
        ResultadoGeracaoMatriculaDTO $resultado,
    ): void {
```
e, logo a seguir ao bloco `if ($cobertura !== null) { … return; }`, inserir:
```php

        // D10 (F3): a geração automática não desfaz um cancelamento (manual ou por estado da matrícula). A manual
        // pode regenerar, mas o item fica assinalado (O2). A mais recente, se o período foi cancelado mais de uma vez.
        $cancelada = $canceladas->last(fn (Propina $propina) => $propina->periodo_inicio->toDateString() <= $periodo['fim']
            && $propina->periodo_fim->toDateString() >= $periodo['inicio']);

        if ($cancelada !== null && $pedido->origem->automatica()) {
            $resultado->ignorada(
                'Período cancelado (' . RotuloDePeriodo::periodo($cancelada->periodo_inicio->toDateString(), $cancelada->periodo_fim->toDateString())
                    . '): a geração automática não o recria; gere-o manualmente se for preciso.',
                $rotulo,
                $plano->nome,
            );

            return;
        }
```
e, no array passado a `$resultado->aCriar([...])`, a seguir à linha `'tardia' => …,`:
```php
            // O2 (F3): a geração manual volta a cobrar um período que tinha sido cancelado — a pré-visualização assinala-o.
            'regenera_cancelada' => $cancelada === null ? null : $this->avisoDeRegeneracao($cancelada),
```

`Modules/Financeiro/app/Listeners/GerarPropinasAoActivarMatricula.php`:
```php
<?php

namespace Modules\Financeiro\Listeners;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Core\Tenancy\TenantContext;
use Modules\Financeiro\DTO\PedidoGeracaoPropinasDTO;
use Modules\Financeiro\Exceptions\GeracaoDePropinasDesactivada;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Services\GerarPropinasService;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Events\MatriculaEstadoAlterado;
use Modules\Matricula\Models\Matricula;
use Throwable;

/**
 * Q7 (+ §8.2/3 "geração recuperável"): ao passar a Activa, gera as propinas da matrícula DEPOIS do commit
 * (ShouldHandleEventsAfterCommit: o Dispatcher adia-o até a transacção mais exterior confirmar). Só com «gerar
 * automaticamente» nas Regras de Cobrança (e, até à Task 16, o interruptor de segurança ligado). Qualquer falha
 * é registada e NUNCA propaga: a matrícula já está Activa e o comando diário (idempotente) gera o que faltar.
 */
class GerarPropinasAoActivarMatricula implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private GerarPropinasService $geracao,
        private TenantContext $tenant,
    ) {
    }

    public function handle(MatriculaEstadoAlterado $evento): void
    {
        if ($evento->estadoNovo !== EstadoMatriculaEnum::ACTIVA->value) {
            return;
        }

        try {
            $this->gerar($evento);
        } catch (Throwable $e) {
            // Já depois do commit e fora de qualquer transacção de negócio: apanhar aqui é seguro.
            if (! $e instanceof ValidationException) {
                report($e);
            }

            Log::warning('financeiro.gerar-propinas.activacao-falhou', [
                'tenant_id' => $this->tenant->temTenant() ? $this->tenant->id() : null,
                'matricula_id' => $evento->matriculaId,
                'excepcao' => $e::class,
                'mensagem' => $e->getMessage(),
            ]);
        }
    }

    private function gerar(MatriculaEstadoAlterado $evento): void
    {
        // Interruptor de segurança (R-F2.1, removido na Task 16) e opção da escola (Q7). Leitura pura: sem firstOrCreate.
        if (! GeracaoDePropinasDesactivada::activa() || ! (RegraCobranca::query()->first()?->gerar_automaticamente ?? false)) {
            return;
        }

        $matricula = Matricula::query()->find($evento->matriculaId);

        // Pode ter mudado outra vez entre o commit e agora: decide o estado actual.
        if ($matricula === null || $matricula->estado !== EstadoMatriculaEnum::ACTIVA) {
            return;
        }

        $totais = $this->geracao->gerar(PedidoGeracaoPropinasDTO::daMatricula((int) $matricula->ano_lectivo_id, (string) $matricula->numero_registo_matricula))->totais();

        Log::info('financeiro.gerar-propinas.activacao', [
            'tenant_id' => $this->tenant->temTenant() ? $this->tenant->id() : null,
            'matricula_id' => $matricula->id,
        ] + $totais);
    }
}
```

Em `Modules/Financeiro/app/Providers/FinanceiroServiceProvider.php`:
1. `use` novo: `use Modules\Financeiro\Listeners\GerarPropinasAoActivarMatricula;`
2. Em `boot()`, a seguir ao `Event::listen(...CancelarPropinasAoTerminarMatricula::class);`:
```php
        // Q7: depois do commit (ShouldHandleEventsAfterCommit); falhas registadas, nunca propagadas.
        Event::listen(MatriculaEstadoAlterado::class, GerarPropinasAoActivarMatricula::class);
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro Modules/Matricula`
Expected: PASS (11 testes novos — inclui O2 — + os de F2 verdes — em especial `GerarPropinasTest::test_depois_de_cancelar_o_mesmo_periodo_volta_a_gerar` (manual) e `GerarPropinasCommandTest`).

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Enums/OrigemGeracaoPropina.php Modules/Financeiro/app/DTO/PedidoGeracaoPropinasDTO.php Modules/Financeiro/app/Services/GerarPropinasService.php Modules/Financeiro/app/Listeners/GerarPropinasAoActivarMatricula.php Modules/Financeiro/app/Providers/FinanceiroServiceProvider.php Modules/Financeiro/tests/Unit/OrigemGeracaoPropinaTest.php Modules/Financeiro/tests/Unit/PedidoGeracaoPropinasDTOTest.php Modules/Financeiro/tests/Feature/GerarPropinasAoActivarMatriculaTest.php Modules/Financeiro/tests/Feature/ListenersDaMatriculaTest.php Modules/Financeiro/tests/Feature/GerarPropinasPreVisualizacaoTest.php
```

---

### Task 11: Reconciliação diária dos cancelamentos no comando (D12)

**Files:**
- Create: `Modules/Financeiro/app/Services/ReconciliarCancelamentosDePropinas.php`
- Modify: `Modules/Financeiro/app/Console/GerarPropinasCommand.php` (substituir o ficheiro inteiro)
- Test: `Modules/Financeiro/tests/Feature/ReconciliarCancelamentosDePropinasTest.php`

**Interfaces:**
- Consumes: T8 (`CancelarPropinasFuturasDaMatricula::{executar,ESTADOS_TERMINAIS}`), `ParaTodosOsTenants`, `TenantContext`.
- Produces:
  - `ReconciliarCancelamentosDePropinas::executar(): array{matriculas: int, canceladas: int, erros: int}` — candidatas: propinas Em Aberto de matrículas terminadas com `data_fim` e `periodo_inicio > data_fim` (`whereColumn`, portável: em SQLite `'2026-11-01' > '2026-10-16 00:00:00'` compara bem e a igualdade de dia não passa); cada matrícula na **sua** transacção, bloqueada, pelo serviço de T8 (`reconciliacao: true`, `utilizadorId = null`); uma falha é apanhada **fora** da transacção dessa matrícula (`report` + `Log::warning('financeiro.reconciliar-cancelamentos.matricula-falhou')`), as outras seguem.
  - `financeiro:gerar-propinas`: por tenant, **primeiro** reconcilia (sempre — com `gerar_automaticamente` desligado e com o interruptor de segurança desligado), regista `Log::info('financeiro.reconciliar-cancelamentos', …)`, e só depois gera (se o interruptor e a escola o permitirem). Erros da reconciliação ou da geração deixam o tenant "falhado" (código ≠ 0) depois de tudo o resto gravado. Com o interruptor desligado, a saída continua a conter "desactivada" e o código é 0 se a reconciliação não falhar.

- [ ] **Step 1: Escrever o teste que falha**

`Modules/Financeiro/tests/Feature/ReconciliarCancelamentosDePropinasTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\PendingCommand;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\ReconciliarCancelamentosDePropinas;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Turma\Models\Turma;
use RuntimeException;
use Tests\TestCase;

/**
 * D12 (Q5: "reconciliação diária como rede de segurança em qualquer caso"; trancamento D14): se o cancelamento por
 * estado da matrícula não aconteceu (listener perdido, dados antigos, estado mudado fora da Action), o comando
 * diário cancela as propinas futuras canceláveis das matrículas terminadas. Idempotente.
 */
class ReconciliarCancelamentosDePropinasTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function correr(): PendingCommand
    {
        return $this->artisan('financeiro:gerar-propinas', ['--tenant' => $this->tenant->codigo]);
    }

    /** Matrícula com as 10 propinas mensais, depois terminada "à margem" (sem a Action: o listener nunca correu). */
    private function terminadaSemCancelamento(Turma $turma, PlanoPropina $plano, EstadoMatriculaEnum $estado, string $dataFim): Matricula
    {
        $matricula = $this->matriculaActiva($turma);

        foreach (range(1, 10) as $ordem) {
            $this->propina($matricula, $plano, $ordem);
        }

        $matricula->forceFill(['estado' => $estado, 'data_fim' => $dataFim])->save();

        return $matricula;
    }

    /** @return list<int> */
    private function ordensCanceladas(Matricula $matricula): array
    {
        return Propina::query()->where('matricula_id', $matricula->id)->where('estado', EstadoCobranca::CANCELADA->value)
            ->orderBy('ordem')->pluck('ordem')->map(fn ($ordem) => (int) $ordem)->all();
    }

    public function test_o_comando_corrige_um_cancelamento_que_ficou_por_fazer_e_e_idempotente(): void
    {
        ['turma' => $turma, 'plano' => $plano] = $this->cenarioPropinas();
        $matricula = $this->terminadaSemCancelamento($turma, $plano, EstadoMatriculaEnum::CANCELADA, '2026-10-16');

        // gerar_automaticamente está desligado (omissão): a reconciliação corre na mesma.
        $this->correr()->expectsOutputToContain('reconciliação: 8 propina(s) cancelada(s)')->assertSuccessful();

        $this->assertSame([3, 4, 5, 6, 7, 8, 9, 10], $this->ordensCanceladas($matricula));
        $novembro = Propina::query()->where('matricula_id', $matricula->id)->where('ordem', 3)->firstOrFail();
        $this->assertSame('Matrícula cancelada em 16/10/2026 (estado da matrícula, reconciliação diária).', $novembro->motivo_cancelamento);
        $this->assertNull($novembro->cancelado_por);

        $this->correr()->assertSuccessful();
        $this->assertSame([3, 4, 5, 6, 7, 8, 9, 10], $this->ordensCanceladas($matricula));
        $this->assertPropinasCoerentes();
    }

    public function test_corre_mesmo_com_o_interruptor_de_seguranca_desligado(): void
    {
        ['turma' => $turma, 'plano' => $plano] = $this->cenarioPropinas();
        $matricula = $this->terminadaSemCancelamento($turma, $plano, EstadoMatriculaEnum::TRANSFERIDA, '2027-01-31');
        config()->set('financeiro.propinas.geracao_activa', false);

        $this->correr()->expectsOutputToContain('desactivada')->assertSuccessful();

        // Fev/2027 (ordem 6) em diante: começam depois de 31/01/2027.
        $this->assertSame([6, 7, 8, 9, 10], $this->ordensCanceladas($matricula));
    }

    public function test_nao_toca_em_matriculas_activas_nem_em_propinas_com_pagamentos(): void
    {
        ['turma' => $turma, 'plano' => $plano, 'matricula' => $activa] = $this->cenarioPropinas();
        $activaPropina = $this->propina($activa, $plano, 10);
        $terminada = $this->terminadaSemCancelamento($turma, $plano, EstadoMatriculaEnum::CONCLUIDA, '2027-03-31');
        $abril = Propina::query()->where('matricula_id', $terminada->id)->where('ordem', 8)->firstOrFail();
        $this->comEstado($abril, EstadoCobranca::PARCIALMENTE_PAGA, 100_000);

        $resultado = app(ReconciliarCancelamentosDePropinas::class)->executar();

        $this->assertSame(['matriculas' => 1, 'canceladas' => 2, 'erros' => 0], $resultado);
        $this->assertSame([9, 10], $this->ordensCanceladas($terminada));
        $this->assertSame(EstadoCobranca::EM_ABERTO, $activaPropina->refresh()->estado);
        $this->assertSame(EstadoCobranca::PARCIALMENTE_PAGA, $abril->refresh()->estado);
    }

    public function test_uma_falha_numa_matricula_nao_impede_as_outras_e_o_tenant_fica_falhado(): void
    {
        ['turma' => $turma, 'plano' => $plano] = $this->cenarioPropinas();
        $primeira = $this->terminadaSemCancelamento($turma, $plano, EstadoMatriculaEnum::CANCELADA, '2026-10-16');
        $segunda = $this->terminadaSemCancelamento($turma, $plano, EstadoMatriculaEnum::CANCELADA, '2026-10-16');
        Propina::updating(function (Propina $propina) use ($primeira) {
            if ($propina->matricula_id === $primeira->id) {
                throw new RuntimeException('Falha simulada na reconciliação.');
            }
        });

        $this->correr()->assertFailed();

        $this->assertSame([], $this->ordensCanceladas($primeira), 'A transacção da primeira reverteu por inteiro.');
        $this->assertSame([3, 4, 5, 6, 7, 8, 9, 10], $this->ordensCanceladas($segunda));
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/ReconciliarCancelamentosDePropinasTest.php`
Expected: FAIL (serviço inexistente; o comando não reconcilia).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Services/ReconciliarCancelamentosDePropinas.php`:
```php
<?php

namespace Modules\Financeiro\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Core\Tenancy\TenantContext;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\Propina;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Throwable;

/**
 * Rede de segurança diária (D12; Q5; trancamento D14): cancela as propinas futuras canceláveis das matrículas
 * terminadas cujo cancelamento por estado não aconteceu. Mesmas regras e mesma escrita do listener (serviço
 * partilhado). Uma transacção por matrícula, com a matrícula bloqueada primeiro (D5). Idempotente.
 */
class ReconciliarCancelamentosDePropinas
{
    public function __construct(
        private CancelarPropinasFuturasDaMatricula $cancelar,
        private TenantContext $tenant,
    ) {
    }

    /**
     * @return array{matriculas: int, canceladas: int, erros: int}
     */
    public function executar(): array
    {
        $terminais = array_map(fn (EstadoMatriculaEnum $estado) => $estado->value, CancelarPropinasFuturasDaMatricula::ESTADOS_TERMINAIS);

        // Só para escolher candidatas (o serviço volta a filtrar, já com a matrícula bloqueada). Em SQLite a
        // data_fim fica "Y-m-d 00:00:00" e periodo_inicio "Y-m-d": a comparação de texto continua correcta para ">".
        $ids = Propina::query()
            ->where('estado', EstadoCobranca::EM_ABERTO->value)
            ->whereHas('matricula', fn ($matricula) => $matricula
                ->whereIn('estado', $terminais)
                ->whereNotNull('data_fim')
                ->whereColumn('propinas.periodo_inicio', '>', 'matriculas.data_fim'))
            ->distinct()
            ->orderBy('matricula_id')
            ->pluck('matricula_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $canceladas = 0;
        $erros = 0;

        foreach ($ids as $id) {
            try {
                $canceladas += count(DB::transaction(function () use ($id) {
                    $matricula = Matricula::query()->whereKey($id)->lockForUpdate()->first();

                    if ($matricula === null || $matricula->data_fim === null) {
                        return [];
                    }

                    return $this->cancelar->executar($matricula, $matricula->data_fim->toDateString(), null, reconciliacao: true);
                }));
            } catch (Throwable $e) {
                // Já FORA da transacção desta matrícula, revertida por inteiro; as outras seguem.
                report($e);
                Log::warning('financeiro.reconciliar-cancelamentos.matricula-falhou', [
                    'tenant_id' => $this->tenant->id(),
                    'matricula_id' => $id,
                    'excepcao' => $e::class,
                    'mensagem' => $e->getMessage(),
                ]);
                $erros++;
            }
        }

        return ['matriculas' => count($ids), 'canceladas' => $canceladas, 'erros' => $erros];
    }
}
```

`Modules/Financeiro/app/Console/GerarPropinasCommand.php` (substituir o ficheiro inteiro):
```php
<?php

namespace Modules\Financeiro\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Core\Tenancy\Console\ParaTodosOsTenants;
use Modules\Core\Tenancy\TenantAtual;
use Modules\Financeiro\Exceptions\GeracaoDePropinasDesactivada;
use Modules\Financeiro\Services\GerarPropinasService;
use Modules\Financeiro\Services\ReconciliarCancelamentosDePropinas;
use RuntimeException;

/**
 * Comando diário de propinas, por tenant:
 * 1. Reconciliação dos cancelamentos por estado da matrícula (D12 de F3): SEMPRE, mesmo com a geração automática
 *    ou o interruptor de segurança desligados — cancelar é protector.
 * 2. Geração automática (spec §7.6): só com «gerar automaticamente» ligado (e, até à Task 16 de F3, o interruptor
 *    de segurança). Idempotente; recupera o que a geração ao activar uma matrícula não fez (§8.2/3).
 * Sem lógica de negócio: delega nos serviços.
 */
class GerarPropinasCommand extends Command
{
    use ParaTodosOsTenants;

    protected $signature = 'financeiro:gerar-propinas';

    protected $description = 'Reconcilia os cancelamentos e gera as propinas em falta das matrículas activas nas escolas com a geração automática ligada (--tenant=CODIGO ou --todos)';

    public function handle(GerarPropinasService $geracao, ReconciliarCancelamentosDePropinas $reconciliacao): int
    {
        $geracaoActiva = GeracaoDePropinasDesactivada::activa();

        // Interruptor de segurança (R-F2.1): a geração fica inerte (código 0 de propósito — o comando está agendado
        // todos os dias); a reconciliação dos cancelamentos corre na mesma.
        if (! $geracaoActiva) {
            Log::warning('financeiro.gerar-propinas.desactivada');
            $this->warn(GeracaoDePropinasDesactivada::MENSAGEM . ' Nada foi gerado; só corre a reconciliação dos cancelamentos.');
        }

        return $this->paraTenants(function (TenantAtual $tenant) use ($geracao, $reconciliacao, $geracaoActiva): void {
            $reconciliados = $reconciliacao->executar();
            Log::info('financeiro.reconciliar-cancelamentos', ['tenant' => $tenant->codigo] + $reconciliados);

            if ($reconciliados['canceladas'] > 0 || $reconciliados['erros'] > 0) {
                $this->line("[{$tenant->codigo}] reconciliação: {$reconciliados['canceladas']} propina(s) cancelada(s) em "
                    . "{$reconciliados['matriculas']} matrícula(s) terminada(s), {$reconciliados['erros']} erro(s).");
            }

            $erros = $reconciliados['erros'];

            if ($geracaoActiva) {
                $erros += $this->gerar($geracao, $tenant);
            }

            // Tudo o que correu bem já está gravado. O tenant fica como falhado para o código de saída e o agendador
            // o assinalarem; a execução seguinte volta a tentar (idempotente).
            if ($erros > 0) {
                throw new RuntimeException("{$erros} matrícula(s) com erro nas propinas; consulte os registos.");
            }
        });
    }

    /** @return int matrículas com erro */
    private function gerar(GerarPropinasService $geracao, TenantAtual $tenant): int
    {
        $resultados = $geracao->gerarAutomaticamente();

        if ($resultados === null) {
            Log::info('financeiro.gerar-propinas', ['tenant' => $tenant->codigo, 'activa' => false]);
            $this->line("[{$tenant->codigo}] geração automática desligada nas Regras de Cobrança: nada a fazer.");

            return 0;
        }

        $erros = 0;

        foreach ($resultados as $anoLectivoId => $resultado) {
            $totais = $resultado->totais();
            $erros += $totais['erros'];

            Log::info('financeiro.gerar-propinas', ['tenant' => $tenant->codigo, 'activa' => true, 'ano_lectivo_id' => $anoLectivoId] + $totais);
            $this->line("[{$tenant->codigo}] ano lectivo {$anoLectivoId}: {$totais['matriculas']} matrícula(s), {$totais['criadas']} propina(s) criada(s), "
                . "{$totais['ignoradas']} ignorada(s), {$totais['conflitos']} conflito(s), {$totais['erros']} erro(s).");
        }

        return $erros;
    }
}
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Feature/ReconciliarCancelamentosDePropinasTest.php Modules/Financeiro/tests/Feature/GerarPropinasCommandTest.php Modules/Financeiro/tests/Feature/GeracaoPropinasInterruptorTest.php`
Expected: PASS (4 testes novos; os de F2 continuam verdes — o `Log::info('financeiro.gerar-propinas', …)->once()` filtra pela mensagem e a reconciliação usa outra).

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Services/ReconciliarCancelamentosDePropinas.php Modules/Financeiro/app/Console/GerarPropinasCommand.php Modules/Financeiro/tests/Feature/ReconciliarCancelamentosDePropinasTest.php
```

---

### Task 12: Frontend — `Propinas/Show`, modal de cancelamento e link na lista

**Files:**
- Create: `Modules/Financeiro/resources/js/Pages/Propinas/Show.vue`
- Create: `Modules/Financeiro/resources/js/Components/Propinas/CancelarPropinaModal.vue`
- Modify: `Modules/Financeiro/resources/js/Pages/Propinas/Index.vue` (coluna "Acções" com "Ver")
- Modify: `Modules/Financeiro/resources/js/Components/Propinas/ResultadoGeracao.vue` (O2: assinala o período cancelado que a geração manual volta a cobrar)
- Test: `Modules/Financeiro/tests/Unit/DetalhePropinaVueTest.php`

**Interfaces:**
- Consumes: props de T4 (`propina`, `moeda`, `podeCancelar`), rota de T3 (`PATCH /financeiro/propinas/{id}/cancelar`), `AppLayout`, `BotaoVoltar`, `AcaoIcone` (`visualizar`), `EstadoCobrancaBadge`, `formatarDinheiro`, `dataCurta`, `ORIGEM_GERACAO`, `vue-sonner`.
- Produces: página só de leitura com cartões Propina, Snapshot da geração, Matrícula, Plano de origem, Cancelamento/Anulação (se houver), Histórico, Pagamentos (vazio) e Ajustes (vazio); botão "Cancelar propina" **só** com `podeCancelar` (decidido no servidor, D13); sem `podeCancelar` e com `can('propina.cancelar')`, mostra a razão (`cancelamento_bloqueado`). Modal com motivo obrigatório (5–500, contador), aviso de que não se desfaz, erros `motivo`/`propina`.
- Padrões: `Pages/PlanosPropina/Index.vue` (cartões `card mb-6`), `CopiarPlanosModal.vue` (modal `d-block`, `bg-body-secondary` nos blocos internos — memória "cartões internos"), `Pages/Propinas/Index.vue` (`router`, `toast`).

- [ ] **Step 1: Escrever o teste estático que falha**

`Modules/Financeiro/tests/Unit/DetalhePropinaVueTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Não há testes de frontend: lê os ficheiros Vue (sem comentários) e prova as decisões que importam — o botão de
 * cancelar depende só do que o servidor decidiu (D13), o cancelamento vai pela rota PATCH com motivo, e a lista
 * liga ao detalhe.
 */
class DetalhePropinaVueTest extends TestCase
{
    private function vue(string $relativo): string
    {
        return preg_replace('/<!--.*?-->/s', '', file_get_contents(dirname(__DIR__, 2) . '/resources/js/' . $relativo));
    }

    public function test_o_botao_de_cancelar_so_aparece_quando_o_servidor_o_permite(): void
    {
        $show = $this->vue('Pages/Propinas/Show.vue');

        $this->assertStringContainsString('podeCancelar: { type: Boolean, required: true }', $show);
        $this->assertSame(1, preg_match('/<button[^>]*v-if="podeCancelar"[^>]*>\s*Cancelar propina/s', $show), 'O botão depende só de podeCancelar.');
        $this->assertStringContainsString("router.patch(`/financeiro/propinas/\${props.propina.id}/cancelar`, { motivo }", $show);
        $this->assertStringContainsString('<CancelarPropinaModal', $show);
    }

    public function test_o_modal_exige_motivo_com_limites(): void
    {
        $modal = $this->vue('Components/Propinas/CancelarPropinaModal.vue');

        $this->assertStringContainsString('maxlength="500"', $modal);
        $this->assertStringContainsString('motivo.value.trim().length < 5', $modal);
        $this->assertStringContainsString('errors.motivo', $modal);
        $this->assertStringContainsString('errors.propina', $modal);
    }

    public function test_a_lista_liga_ao_detalhe(): void
    {
        $this->assertStringContainsString(':href="`${BASE}/${propina.id}`"', $this->vue('Pages/Propinas/Index.vue'));
    }

    public function test_a_pre_visualizacao_assinala_um_periodo_cancelado_que_volta_a_ser_cobrado_o2(): void
    {
        $resultado = $this->vue('Components/Propinas/ResultadoGeracao.vue');

        $this->assertStringContainsString('v-if="propina.regenera_cancelada"', $resultado);
        $this->assertStringContainsString('{{ propina.regenera_cancelada }}', $resultado);
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Unit/DetalhePropinaVueTest.php`
Expected: FAIL (ficheiros inexistentes / sem link).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/resources/js/Components/Propinas/CancelarPropinaModal.vue`:
```vue
<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    propina: { type: Object, required: true }, // detalhe (periodo, matricula.aluno_nome, …)
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['confirmar', 'cancelar']);

const MAXIMO = 500;
const motivo = ref('');
const invalido = computed(() => motivo.value.trim().length < 5 || motivo.value.length > MAXIMO);

watch(() => props.show, (show) => {
    if (show) motivo.value = '';
});
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5); overflow-y: auto;" @click.self="emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-2">Cancelar propina</h3>
                <p class="text-muted fs-6 mb-5">
                    {{ propina.periodo }} — {{ propina.matricula?.aluno_nome ?? 'Aluno' }}
                </p>

                <div class="card bg-body-secondary mb-5">
                    <div class="card-body py-4 fs-7">
                        A propina passa a <strong>Cancelada</strong> e deixa de ser cobrada. A operação não se desfaz:
                        se for preciso, gere de novo o período em Financeiro → Propinas. Uma propina que já recebeu pagamentos
                        não se cancela (anula-se, com o módulo de Pagamentos).
                    </div>
                </div>

                <div v-if="errors.propina" class="alert alert-danger fs-7 py-3 mb-5">{{ errors.propina }}</div>

                <label for="motivo-cancelamento" class="form-label required fw-semibold">Motivo</label>
                <textarea
                    id="motivo-cancelamento"
                    v-model="motivo"
                    class="form-control form-control-solid"
                    :class="{ 'is-invalid': errors.motivo }"
                    rows="3"
                    maxlength="500"
                    placeholder="Ex.: lançada por engano; isenção decidida pela direcção"
                ></textarea>
                <div class="d-flex justify-content-between mt-1">
                    <div class="text-danger fs-7">{{ errors.motivo }}</div>
                    <div class="text-muted fs-8">{{ motivo.length }}/{{ MAXIMO }}</div>
                </div>

                <div class="d-flex justify-content-end gap-3 mt-6">
                    <button type="button" class="btn btn-light" :disabled="processing" @click="emit('cancelar')">Voltar</button>
                    <button type="button" class="btn btn-danger" :disabled="processing || invalido" @click="emit('confirmar', motivo.trim())">
                        {{ processing ? 'A cancelar…' : 'Cancelar propina' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
```

`Modules/Financeiro/resources/js/Pages/Propinas/Show.vue`:
```vue
<script setup>
import { ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotaoVoltar from '@/Components/Shared/BotaoVoltar.vue';
import { can } from '@/Composables/usePermissoes';
import EstadoCobrancaBadge from '../../Components/Shared/EstadoCobrancaBadge.vue';
import CancelarPropinaModal from '../../Components/Propinas/CancelarPropinaModal.vue';
import { ORIGEM_GERACAO } from '../../Models/Propina';
import { formatarDinheiro } from '../../Support/dinheiro';
import { dataCurta } from '../../Support/periodos';

const props = defineProps({
    propina: { type: Object, required: true },
    moeda: { type: Object, required: true },
    podeCancelar: { type: Boolean, required: true },
});
defineOptions({ layout: AppLayout });

const page = usePage();
const cancelarAberto = ref(false);
const processing = ref(false);
const errors = ref({});

/** "2026-10-16 11:00" (fuso da escola) → "16/10/2026 11:00". */
const instante = (valor) => (valor ? `${dataCurta(valor.slice(0, 10))} ${valor.slice(11, 16)}` : '—');

function abrirCancelamento() {
    errors.value = {};
    cancelarAberto.value = true;
}

function cancelar(motivo) {
    processing.value = true;
    errors.value = {};

    router.patch(`/financeiro/propinas/${props.propina.id}/cancelar`, { motivo }, {
        preserveScroll: true,
        onSuccess: () => {
            cancelarAberto.value = false;
            toast.success(page.props.flash?.success ?? 'Propina cancelada.');
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
        <BotaoVoltar href="/financeiro/propinas" />

        <div class="d-flex flex-wrap justify-content-between align-items-start gap-4 mb-6">
            <div>
                <h1 class="fs-2 fw-bold mb-1">Propina {{ propina.periodo }}</h1>
                <p class="text-muted fs-6 mb-2">
                    {{ propina.matricula?.aluno_nome ?? '—' }} · matrícula nº {{ propina.matricula?.numero ?? '—' }}
                </p>
                <EstadoCobrancaBadge :estado="propina.estado" :estado-descricao="propina.estado_descricao" />
                <span v-if="propina.origem_geracao === ORIGEM_GERACAO.RETROACTIVA" class="badge badge-light-warning ms-2">Retroactiva</span>
                <span v-else-if="propina.geracao_tardia" class="badge badge-light-warning ms-2">Geração tardia</span>
            </div>
            <div class="text-end">
                <button v-if="podeCancelar" type="button" class="btn btn-light-danger" @click="abrirCancelamento">
                    Cancelar propina
                </button>
                <div v-else-if="can('propina.cancelar') && propina.cancelamento_bloqueado" class="text-muted fs-7" style="max-width: 360px">
                    {{ propina.cancelamento_bloqueado }}
                </div>
            </div>
        </div>

        <div class="row g-6 mb-6">
            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Propina</h3></div>
                    <div class="card-body pt-0">
                        <div class="bg-body-secondary rounded p-4">
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Período</span><span>{{ propina.periodo }} (período {{ propina.ordem }})</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Valor original</span><span>{{ formatarDinheiro(propina.valor_original, moeda) }}</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Valor actual</span><span>{{ formatarDinheiro(propina.valor, moeda) }}</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Pago</span><span>{{ formatarDinheiro(propina.valor_pago, moeda) }}</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Saldo</span><span class="fw-bold">{{ formatarDinheiro(propina.saldo, moeda) }}</span></div>
                            <div class="d-flex justify-content-between"><span class="text-muted">Estado registado</span><span>{{ propina.estado_persistido_descricao }}</span></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Snapshot da geração</h3></div>
                    <div class="card-body pt-0">
                        <div class="bg-body-secondary rounded p-4">
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Vencimento</span><span>{{ dataCurta(propina.data_vencimento) }}</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Tolerância</span><span>{{ propina.dias_tolerancia }} dia(s)</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Em atraso depois de</span><span>{{ dataCurta(propina.data_limite) }}</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Moeda</span><span>{{ propina.moeda }}</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Câmbio (1 USD)</span><span>{{ propina.cambio_usd ?? '—' }}</span></div>
                            <div class="d-flex justify-content-between"><span class="text-muted">Origem</span><span>{{ propina.origem_geracao_descricao }}</span></div>
                            <div v-if="propina.motivo_geracao" class="text-muted fs-7 mt-2">Motivo: {{ propina.motivo_geracao }}</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Matrícula</h3></div>
                    <div class="card-body pt-0">
                        <div v-if="propina.matricula" class="bg-body-secondary rounded p-4">
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Aluno</span><span>{{ propina.matricula.aluno_nome ?? '—' }} (nº {{ propina.matricula.aluno_numero ?? '—' }})</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Matrícula nº</span><span>{{ propina.matricula.numero }}</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Turma</span><span>{{ propina.matricula.turma_nome ?? '—' }}</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Ano lectivo</span><span>{{ propina.matricula.ano_lectivo_nome ?? '—' }}</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Estado</span><span>{{ propina.matricula.estado_descricao }}</span></div>
                            <div class="d-flex justify-content-between"><span class="text-muted">Datas</span><span>{{ dataCurta(propina.matricula.data_matricula) }} → {{ propina.matricula.data_fim ? dataCurta(propina.matricula.data_fim) : '…' }}</span></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Plano de origem</h3></div>
                    <div class="card-body pt-0">
                        <div v-if="propina.plano" class="bg-body-secondary rounded p-4">
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Plano</span><span>{{ propina.plano.nome }}</span></div>
                            <div class="d-flex justify-content-between mb-2"><span class="text-muted">Periodicidade</span><span>{{ propina.plano.periodicidade ?? '—' }}</span></div>
                            <div class="d-flex justify-content-between"><span class="text-muted">Valor actual do plano</span><span>{{ formatarDinheiro(propina.plano.valor_actual, moeda) }}</span></div>
                            <div class="text-muted fs-8 mt-2">A propina guarda o valor do momento em que foi gerada; mudar o plano não a altera.</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="propina.cancelamento || propina.anulacao" class="card mb-6">
            <div class="card-header">
                <h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">{{ propina.cancelamento ? 'Cancelamento' : 'Anulação' }}</h3>
            </div>
            <div class="card-body pt-0">
                <div class="bg-body-secondary rounded p-4">
                    <div class="mb-2">{{ (propina.cancelamento ?? propina.anulacao).motivo }}</div>
                    <div class="text-muted fs-7">
                        {{ instante((propina.cancelamento ?? propina.anulacao).em) }} · {{ (propina.cancelamento ?? propina.anulacao).por ?? 'Sistema' }}
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-6">
            <div class="card-header"><h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Histórico</h3></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table align-middle table-row-dashed fs-6 gy-4 mb-0">
                        <thead>
                            <tr class="text-start text-muted fw-bold fs-7 text-uppercase gs-0">
                                <th class="min-w-125px">Quando</th>
                                <th class="min-w-100px">Evento</th>
                                <th class="min-w-125px">Por</th>
                                <th>Detalhe</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-600 fw-semibold">
                            <tr v-for="(evento, indice) in propina.historico" :key="indice">
                                <td>{{ instante(evento.em) }}</td>
                                <td class="text-gray-800">{{ evento.evento }}</td>
                                <td>{{ evento.por ?? 'Sistema' }}</td>
                                <td>{{ evento.detalhe ?? '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="row g-6">
            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Pagamentos</h3></div>
                    <div class="card-body pt-0">
                        <p v-if="propina.pagamentos.length === 0" class="text-muted mb-0">Sem pagamentos. Os pagamentos aparecem aqui quando o módulo de Pagamentos estiver disponível.</p>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Ajustes de preço</h3></div>
                    <div class="card-body pt-0">
                        <p v-if="propina.ajustes.length === 0" class="text-muted mb-0">Sem ajustes de preço.</p>
                    </div>
                </div>
            </div>
        </div>

        <CancelarPropinaModal
            :show="cancelarAberto"
            :propina="propina"
            :processing="processing"
            :errors="errors"
            @confirmar="cancelar"
            @cancelar="cancelarAberto = false"
        />
    </div>
</template>
```

Em `Modules/Financeiro/resources/js/Pages/Propinas/Index.vue`:
1. Substituir `import { router, usePage } from '@inertiajs/vue3';` por `import { Link, router, usePage } from '@inertiajs/vue3';` e acrescentar, a seguir ao import de `Pagination`: `import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';`
2. No `<thead>`, a seguir a `<th class="min-w-125px">Estado</th>`: `<th class="text-end min-w-75px">Acções</th>`
3. Substituir `<td colspan="10" class="text-center text-muted py-6">Nenhuma propina encontrada.</td>` por `<td colspan="11" class="text-center text-muted py-6">Nenhuma propina encontrada.</td>`
4. Na linha de cada propina, a seguir ao `<td>` do estado (o que termina com o `</div>` das origens), acrescentar:
```vue
                                <td class="text-end">
                                    <Link :href="`${BASE}/${propina.id}`" class="btn btn-light btn-active-light-primary btn-sm">
                                        <AcaoIcone acao="visualizar" class="me-1" /> Ver
                                    </Link>
                                </td>
```

Em `Modules/Financeiro/resources/js/Components/Propinas/ResultadoGeracao.vue` (O2), a seguir à linha do badge "Geração tardia" (`<span v-else-if="propina.tardia" …>Geração tardia</span>`), dentro do mesmo `<li>`:
```vue
                            <span v-if="propina.regenera_cancelada" class="badge badge-light-danger ms-1">Período cancelado</span>
                            <div v-if="propina.regenera_cancelada" class="text-danger fs-8">{{ propina.regenera_cancelada }}</div>
```

- [ ] **Step 4: Correr e compilar**

Run: `php artisan test Modules/Financeiro/tests/Unit/DetalhePropinaVueTest.php && npm run build`
Expected: PASS (4 testes) e build sem erros.

- [ ] **Step 5: Verificação visual (executor com a app local, login `admin@mositec.gmail.com`; nunca escrever na BD directamente)**

Com `FINANCEIRO_GERACAO_PROPINAS=true` no `.env` local (e `php artisan config:clear`): gerar propinas para uma turma; na lista, "Ver" abre o detalhe; os cartões mostram valores, snapshot, matrícula e plano; "Cancelar propina" pede motivo (botão desactivado com < 5 caracteres), cancela e o detalhe passa a "Cancelada" com o cartão de cancelamento e a linha no histórico; numa propina Parcial/Paga o botão não aparece e a razão é mostrada. Verificar tema claro e escuro (`bg-body-secondary` nos blocos internos).

- [ ] **Step 6: Stage**

```bash
git add Modules/Financeiro/resources/js/Pages/Propinas/Show.vue Modules/Financeiro/resources/js/Components/Propinas/CancelarPropinaModal.vue Modules/Financeiro/resources/js/Pages/Propinas/Index.vue Modules/Financeiro/resources/js/Components/Propinas/ResultadoGeracao.vue Modules/Financeiro/tests/Unit/DetalhePropinaVueTest.php
```

---

### Task 13: Transversal — fronteira, tenancy e permissões

**Files:**
- Modify: `tests/Feature/Arquitectura/FronteiraMatriculaFinanceiroTest.php` (+ evento só com primitivos; docblock)
- Modify: `Modules/Financeiro/tests/Feature/FinanceiroTenancyTest.php` (+ cancelamento por estado só no tenant da matrícula; reconciliação por tenant)
- Modify: `Modules/Financeiro/tests/Feature/PermissoesPropinaTest.php` (+ rotas novas com a ability certa)

**Interfaces:**
- Consumes: T3, T4, T6, T8, T11.
- Produces: provas de que (a) o evento da Matrícula só tem primitivos e a Matrícula continua sem importar o Financeiro; (b) o listener e a reconciliação só tocam no tenant da matrícula; (c) `show` usa `can:propina.ver` e `cancelar` usa `can:propina.cancelar`. `MenuFinanceiroTest` **não muda** (D15): continua verde.

- [ ] **Step 1: Escrever os testes**

Em `tests/Feature/Arquitectura/FronteiraMatriculaFinanceiroTest.php`:
1. `use` novos (ordem alfabética):
```php
use Modules\Matricula\Events\MatriculaEstadoAlterado;
use ReflectionClass;
use ReflectionNamedType;
```
2. Substituir o docblock da classe por:
```php
/**
 * O Financeiro depende da Matrícula (propinas pertencem a matrículas); o inverso seria circular. A Matrícula só
 * emite o seu evento (MatriculaEstadoAlterado, com primitivos) e consulta o seu contrato (DependenciasDaMatricula),
 * ambos implementados/escutados pelo Financeiro (F3).
 */
```
3. Novo método, a seguir a `test_o_modulo_matricula_nao_importa_o_modulo_financeiro()`:
```php

    public function test_o_evento_da_matricula_so_leva_primitivos(): void
    {
        $parametros = (new ReflectionClass(MatriculaEstadoAlterado::class))->getConstructor()->getParameters();

        $this->assertSame(
            ['matriculaId', 'estadoAnterior', 'estadoNovo', 'dataEfeito', 'utilizadorId'],
            array_map(fn ($parametro) => $parametro->getName(), $parametros),
        );

        foreach ($parametros as $parametro) {
            $tipo = $parametro->getType();
            $this->assertInstanceOf(ReflectionNamedType::class, $tipo, $parametro->getName());
            $this->assertTrue($tipo->isBuiltin(), "{$parametro->getName()} tem de ser um primitivo (nunca um model).");
        }
    }
```

Em `Modules/Financeiro/tests/Feature/FinanceiroTenancyTest.php`:
1. `use` novos (ordem alfabética):
```php
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Services\ReconciliarCancelamentosDePropinas;
use Modules\Matricula\Actions\AlterarEstadoMatriculaAction;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
```
2. Novos métodos, no fim da classe:
```php

    public function test_o_cancelamento_por_estado_da_matricula_so_toca_no_tenant_dela(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        ['plano' => $plano, 'matricula' => $minha] = $this->cenarioPropinas();
        $minhaPropina = $this->propina($minha, $plano, 10);

        $propinaDela = $this->noTenant($outro, function () {
            ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
            $propina = $this->propina($matricula, $plano, 10);
            // Sem relógio congelado nesta classe: uma data de fim passada e ≥ data da matrícula (O1) é estável.
            app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::CANCELADA, null, '2026-09-15');

            return $propina->id;
        });

        $this->assertSame(EstadoCobranca::EM_ABERTO, $minhaPropina->refresh()->estado);
        $this->assertSame(EstadoCobranca::CANCELADA, $this->noTenant($outro, fn () => Propina::query()->find($propinaDela)->estado));
    }

    public function test_a_reconciliacao_so_ve_as_matriculas_do_tenant_corrente(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $propinaDela = $this->noTenant($outro, function () {
            ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
            $propina = $this->propina($matricula, $plano, 10);
            $matricula->forceFill(['estado' => EstadoMatriculaEnum::CANCELADA, 'data_fim' => '2026-10-16'])->save();

            return $propina->id;
        });

        $this->assertSame(['matriculas' => 0, 'canceladas' => 0, 'erros' => 0], app(ReconciliarCancelamentosDePropinas::class)->executar());
        $this->assertSame(EstadoCobranca::EM_ABERTO, $this->noTenant($outro, fn () => Propina::query()->find($propinaDela)->estado));
    }
```

Em `Modules/Financeiro/tests/Feature/PermissoesPropinaTest.php`:
1. `use` novo: `use Illuminate\Support\Facades\Route;`
2. Novo método, no fim da classe:
```php

    public function test_detalhe_e_cancelar_exigem_a_ability_propria(): void
    {
        $this->assertContains('can:propina.ver', Route::getRoutes()->getByName('financeiro.propinas.show')->gatherMiddleware());
        $this->assertContains('can:propina.cancelar', Route::getRoutes()->getByName('financeiro.propinas.cancelar')->gatherMiddleware());
        $this->assertSame(['PATCH'], Route::getRoutes()->getByName('financeiro.propinas.cancelar')->methods());
    }
```

- [ ] **Step 2: Correr**

Run: `php artisan test tests/Feature/Arquitectura Modules/Financeiro/tests/Feature/FinanceiroTenancyTest.php Modules/Financeiro/tests/Feature/PermissoesPropinaTest.php tests/Feature/RotasPermissaoReconhecidaTest.php tests/Feature/RotasEscritaAutorizadasTest.php`
Expected: PASS (inclui `MenuFinanceiroTest`, `TenancyArquitecturaTest`, `TenancyEsquemaTest`, `RelogioDoTenantArquitecturaTest`, `EscritoresDePropinaTest`, `CriadoresDePropinaTest`, `CanceladoresDePropinaTest` — nenhum alargado).

- [ ] **Step 3: Stage**

```bash
git add tests/Feature/Arquitectura/FronteiraMatriculaFinanceiroTest.php Modules/Financeiro/tests/Feature/FinanceiroTenancyTest.php Modules/Financeiro/tests/Feature/PermissoesPropinaTest.php
```

---

### Task 14: Concorrência em PostgreSQL (duas ligações, dados confirmados)

**Files:**
- Test: `Modules/Financeiro/tests/Feature/Pgsql/CancelamentoPropinasPgsqlTest.php`

**Interfaces:**
- Consumes: `Tests\PgsqlConcorrenciaTestCase` (F2: `outraLigacao(int $esperaMs = 300)`, `DatabaseTruncation`, grupo `pgsql`), T3, T6, T7, T8, `GerarPropinasService::gerar`.
- Produces: prova, em PostgreSQL real, de D5: (1) o cancelamento manual segura a propina até ao commit; (2) a mudança de estado segura a matrícula (contra `KEY SHARE` — inserir propinas dela noutra sessão) e as propinas que cancela até ao commit; (3) a edição da matrícula segura-a **antes** de consultar as dependências (a geração noutra sessão não se intercala); (4) a geração não bloqueia as propinas já existentes da matrícula, por isso o cancelamento manual não espera por ela (sem ciclo).
- **Comando:** `DB_CONNECTION=pgsql DB_DATABASE=mositec_escola_test vendor/bin/phpunit --group=pgsql` (nunca `php artisan test`, nunca a base `mositec_escola`).

- [ ] **Step 1: Escrever os testes**

`Modules/Financeiro/tests/Feature/Pgsql/CancelamentoPropinasPgsqlTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature\Pgsql;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Modules\Financeiro\Actions\CancelarPropinaAction;
use Modules\Financeiro\DTO\CancelarPropinaDTO;
use Modules\Financeiro\DTO\PedidoGeracaoPropinasDTO;
use Modules\Financeiro\Enums\AmbitoGeracaoPropinas;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\GerarPropinasService;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Matricula\Actions\AlterarEstadoMatriculaAction;
use Modules\Matricula\Actions\AtualizarMatriculaAction;
use Modules\Matricula\Contracts\DependenciasDaMatricula;
use Modules\Matricula\DTO\MatriculaDTO;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Events\MatriculaEstadoAlterado;
use Modules\Matricula\Models\Matricula;
use Modules\Matricula\Support\DependenciasRegistadasDaMatricula;
use PHPUnit\Framework\Attributes\Group;
use Tests\PgsqlConcorrenciaTestCase;

/**
 * D5 do plano F3, provado com duas sessões reais: ordem matrícula → propinas; o cancelamento manual só bloqueia a
 * propina; a mudança de estado e a edição seguram a matrícula até ao commit. SQLite ignora FOR UPDATE.
 */
#[Group('pgsql')]
class CancelamentoPropinasPgsqlTest extends PgsqlConcorrenciaTestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;

    /** SQLSTATE da falha de uma consulta na outra ligação; null se correu (55P03 = esperou mais do que o lock_timeout). */
    private function sqlstateNaOutraLigacao(string $sql, array $valores): ?string
    {
        try {
            $this->outraLigacao()->select($sql, $valores);

            return null;
        } catch (QueryException $e) {
            return $e->errorInfo[0] ?? (string) $e->getCode();
        }
    }

    public function test_o_cancelamento_manual_bloqueia_a_propina_ate_ao_commit(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano, 3);
        $bloqueio = 'nao-verificado';
        Propina::updating(function (Propina $actual) use ($propina, &$bloqueio) {
            if ($actual->id === $propina->id && $bloqueio === 'nao-verificado') {
                $bloqueio = $this->sqlstateNaOutraLigacao('select id from propinas where id = ? for update', [$propina->id]);
            }
        });

        app(CancelarPropinaAction::class)->executar($propina, new CancelarPropinaDTO('Motivo de teste'), null);

        $this->assertSame('55P03', $bloqueio, 'Outra sessão (ex.: um pagamento) tem de esperar pelo cancelamento.');
        $this->assertNull($this->sqlstateNaOutraLigacao('select id from propinas where id = ? for update', [$propina->id]));
        $this->assertSame(4, (int) $this->outraLigacao()->selectOne('select estado from propinas where id = ?', [$propina->id])->estado);
    }

    public function test_a_mudanca_de_estado_segura_a_matricula_e_as_propinas_que_cancela_ate_ao_commit(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

        foreach (range(1, 10) as $ordem) {
            $this->propina($matricula, $plano, $ordem);
        }

        $junho = Propina::query()->where('matricula_id', $matricula->id)->where('ordem', 10)->firstOrFail();
        $matriculaBloqueada = 'nao-verificado';
        $propinaBloqueada = 'nao-verificado';
        // Registado depois do listener do Financeiro: corre a seguir ao cancelamento, ainda na transacção.
        Event::listen(MatriculaEstadoAlterado::class, function () use ($matricula, $junho, &$matriculaBloqueada, &$propinaBloqueada) {
            $matriculaBloqueada = $this->sqlstateNaOutraLigacao('select id from matriculas where id = ? for key share', [$matricula->id]);
            $propinaBloqueada = $this->sqlstateNaOutraLigacao('select id from propinas where id = ? for update', [$junho->id]);
        });

        // O1: data de fim passada e ≥ data da matrícula (o relógio não está congelado); Nov–Jun começam depois → 8.
        app(AlterarEstadoMatriculaAction::class)->executar($matricula, EstadoMatriculaEnum::CANCELADA, null, '2026-10-01');

        $this->assertSame('55P03', $matriculaBloqueada, 'Inserir propinas desta matrícula noutra sessão (KEY SHARE) tem de esperar.');
        $this->assertSame('55P03', $propinaBloqueada);
        $this->assertSame(8, (int) $this->outraLigacao()->selectOne('select count(*) as n from propinas where matricula_id = ? and estado = 4', [$matricula->id])->n);
    }

    public function test_a_edicao_bloqueia_a_matricula_antes_de_consultar_as_dependencias(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();
        $bloqueio = 'nao-verificado';
        $verificar = function () use ($matricula, &$bloqueio) {
            $bloqueio = $this->sqlstateNaOutraLigacao('select id from matriculas where id = ? for key share', [$matricula->id]);
        };
        $dependencia = new class ($verificar) implements DependenciasDaMatricula {
            public function __construct(private Closure $verificar)
            {
            }

            public function bloqueiaAlteracao(Matricula $matricula, int $turmaNovaId, int $anoLectivoNovoId, string $dataMatriculaNova): ?string
            {
                ($this->verificar)();

                return null;
            }
        };
        $this->app->instance('dependencia.pgsql', $dependencia);
        $this->app->tag(['dependencia.pgsql'], DependenciasRegistadasDaMatricula::ETIQUETA);

        app(AtualizarMatriculaAction::class)->executar($matricula, new MatriculaDTO(
            turmaId: $matricula->turma_id,
            anoLectivoId: $matricula->ano_lectivo_id,
            dataMatricula: '2026-09-15',
            estado: null,
            observacoes: null,
        ));

        $this->assertSame('55P03', $bloqueio, 'A geração (que precisa de KEY SHARE/FOR UPDATE na matrícula) não se pode intercalar.');
    }

    public function test_a_geracao_nao_bloqueia_as_propinas_existentes_o_cancelamento_manual_nao_espera(): void
    {
        ['ano' => $ano, 'turma' => $turma, 'plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $existente = $this->propina($matricula, $plano, 1);
        $bloqueio = 'nao-verificado';
        Propina::creating(function () use ($existente, &$bloqueio) {
            if ($bloqueio === 'nao-verificado') {
                $bloqueio = $this->sqlstateNaOutraLigacao('select id from propinas where id = ? for update', [$existente->id]);
            }
        });

        $resultado = app(GerarPropinasService::class)->gerar(new PedidoGeracaoPropinasDTO(
            ano_lectivo_id: $ano->id,
            ambito: AmbitoGeracaoPropinas::TURMA,
            origem: OrigemGeracaoPropina::MANUAL,
            alvo_id: $turma->id,
        ));

        $this->assertSame(9, $resultado->totais()['criadas']);
        $this->assertNull($bloqueio, 'A geração segura a matrícula, não as propinas existentes: um cancelamento manual não espera por ela (D5, sem ciclo).');
    }
}
```

- [ ] **Step 2: Correr sem base PostgreSQL**

Run: `vendor/bin/phpunit --group=pgsql`
Expected: os testes de `CancelamentoPropinasPgsqlTest` (e os de F1/F2) **skipped** com "Teste PostgreSQL: corra com …".

Run: `php artisan test Modules/Financeiro/tests/Feature/Pgsql`
Expected: 0 testes executados (grupo excluído por omissão).

- [ ] **Step 3: (Dono, ou executor com a base de teste disponível) correr em PostgreSQL**

Run: `DB_CONNECTION=pgsql DB_DATABASE=mositec_escola_test vendor/bin/phpunit --group=pgsql`
Expected: `PropinaPgsqlTest`, `GeracaoPropinasPgsqlTest` e `CancelamentoPropinasPgsqlTest` verdes.

- [ ] **Step 4: Stage**

```bash
git add Modules/Financeiro/tests/Feature/Pgsql/CancelamentoPropinasPgsqlTest.php
```

---

### Task 15: Verificação final

- [ ] **Step 1: Suite completa**

Run: `php artisan test` (timeout alargado, ~180 s; fora da janela 23:00–24:00 UTC)
Expected: 0 falhas (o grupo `pgsql` não aparece). Falhas fora do módulo são regressões deste plano (em especial `MatriculaActionTest`, `MatriculaHttpTest`, `TenancyArquitecturaTest`, `EscritoresDePropinaTest`, `CriadoresDePropinaTest`, `CanceladoresDePropinaTest`, `RelogioDoTenantArquitecturaTest`, `FronteiraMatriculaFinanceiroTest`, `RotasPermissaoReconhecidaTest`, `RotasEscritaAutorizadasTest`, `MatrizIsolamentoTest`, `MenuFinanceiroTest`): corrigir seguindo a convenção, sem enfraquecer nenhum teste nem alargar listas de excepções, e documentar quais e porquê.

- [ ] **Step 2: Limpeza**

Run:
```bash
grep -rnE "insertOrIgnore|->insert\(|upsert\(|DB::raw|DB::statement|DB::table|withoutEvents|Quietly\(" Modules/Financeiro/app Modules/Matricula/app
grep -rnE "\bnow\(\)|\btoday\(\)|Carbon(Immutable)?::(now|today)|new \\\\?DateTime(Immutable)?\(\)" Modules/Financeiro/app
grep -rn "Modules\\\\Financeiro" Modules/Matricula/app Modules/Matricula/routes
grep -rnE "AnularPropinaAction|AlterarPrecoPlanoAction|propina_ajustes|PropinaMulta" Modules/Financeiro/app
grep -rn "Event::fake()" Modules/*/tests tests
grep -nE "Route::(get|post|put|patch|delete)" Modules/Financeiro/routes/web.php | grep "\[PropinaController::class"
git diff --cached --name-only | grep -E "database/migrations|database/seeders" || true
```
Expected: os cinco primeiros não devolvem nada (sem SQL cru, sem "hoje" fora do relógio, Matrícula sem Financeiro, sem peças de Pagamentos/F4/F5, nenhum `Event::fake()` sem lista que desligue os listeners); o sexto mostra exactamente 5 rotas (`GET /`, `GET /{propina}`, `POST /geracao/pre-visualizar`, `POST /geracao`, `PATCH /{propina}/cancelar`); o último não mostra nada de F3 (comparar com `/tmp/f3-stage-antes.txt`: F3 não cria migrações nem seeders).

- [ ] **Step 3: Interruptor de segurança ainda presente (Task 16 não executada)**

Run: `grep -rnE "geracao_activa|FINANCEIRO_GERACAO_PROPINAS" Modules/Financeiro/config/config.php phpunit.xml .env.example`
Expected: a chave e a variável continuam lá (`config.php` com `geracao_activa`, `phpunit.xml` com `value="true"`, `.env.example` com `=false`; a chave `geracao_activa` só existe no `config.php`, por isso procurar também a variável). Se não estiverem, alguém executou a Task 16 sem autorização: parar e informar o dono.

- [ ] **Step 4: Build**

Run: `npm run build`
Expected: sucesso.

- [ ] **Step 5: Estado do git**

Run: `git status --short` e `git diff --cached --stat`
Expected: só ficheiros deste plano (mais os que já estavam em stage antes de começar) em stage, nada gerado (`public/build`), **nenhum commit**.

- [ ] **Step 6: Informar o dono do que lhe cabe**

1. **Nada a migrar nem a semear** (D15). `propina.cancelar` já existe desde F1; `financeiro:sincronizar --todos` não é necessário para F3.
2. Correr o grupo PostgreSQL: `DB_CONNECTION=pgsql DB_DATABASE=mositec_escola_test vendor/bin/phpunit --group=pgsql` (critério de validação de F3).
3. Verificação na app (login local `admin@mositec.gmail.com`, `FINANCEIRO_GERACAO_PROPINAS=true` no `.env` local + `php artisan config:clear`; nunca editar a BD à mão):
   - Propinas → "Ver" → detalhe; cancelar uma propina Em Aberto com motivo; ver o histórico.
   - Matrículas → cancelar uma matrícula com propinas → voltar a Propinas e ver as futuras "Cancelada" com o motivo "Matrícula cancelada em …"; as já começadas continuam em dívida.
   - Editar uma matrícula com propinas e tentar mudar a turma → mensagem a indicar "Transferida"; mudar só o dia no mesmo mês → passa.
   - Ligar «Gerar cobranças automaticamente», criar/activar uma matrícula → propinas geradas logo (origem "Matrícula").
4. Confirmar que o agendador (`schedule:work` ou cron `schedule:run`) corre onde a geração automática for ligada: a reconciliação diária depende dele.
5. **Decidir se autoriza a Task 16** (remover o interruptor de segurança) depois dos pontos 2 e 3. Sem autorização expressa, o interruptor fica.
6. Responder às perguntas abertas (secção "Decisões/perguntas abertas para o dono").

---

### Task 16: Remover o interruptor de segurança — ⛔ EXECUTAR SÓ COM AUTORIZAÇÃO EXPRESSA DO DONO

> **Pré-condições obrigatórias:** (1) o dono escreveu explicitamente que autoriza remover o interruptor; (2) Tasks 1–15 concluídas e suite verde; (3) o dono correu o grupo `pgsql` (Task 14) com sucesso; (4) o dono validou F3 na app (Task 15, Step 6). Sem as quatro, **não executar** — nem em modo `subagent-driven-development`, nem como "limpeza".

**Files:**
- Modify: `Modules/Financeiro/config/config.php`
- Delete: `Modules/Financeiro/app/Exceptions/GeracaoDePropinasDesactivada.php`
- Modify: `Modules/Financeiro/app/Services/GerarPropinasService.php`, `Modules/Financeiro/app/Console/GerarPropinasCommand.php`, `Modules/Financeiro/app/Listeners/GerarPropinasAoActivarMatricula.php`
- Modify: `Modules/Financeiro/app/Http/Requests/Concerns/ValidaGeracaoPropinas.php`, `Modules/Financeiro/app/Http/Requests/AtualizarRegraCobrancaRequest.php`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Modify: `Modules/Financeiro/resources/js/Pages/Propinas/Index.vue`, `Modules/Financeiro/resources/js/Pages/RegrasCobranca/Edit.vue`
- Move/rewrite: `Modules/Financeiro/tests/Feature/GeracaoPropinasInterruptorTest.php` → `Modules/Financeiro/tests/Feature/GeracaoPropinasAnoEncerradoTest.php` (só os testes de ano encerrado)
- Modify: `Modules/Financeiro/tests/Feature/GerarPropinasAoActivarMatriculaTest.php`, `Modules/Financeiro/tests/Feature/ReconciliarCancelamentosDePropinasTest.php` (retirar os testes do interruptor)
- Modify: `phpunit.xml`, `.env.example`

**Interfaces:**
- Consumes: tudo o que as Tasks 1–15 entregaram.
- Produces: a geração (manual, comando, activação) deixa de depender de `financeiro.propinas.geracao_activa`; a chave, a variável `FINANCEIRO_GERACAO_PROPINAS`, a excepção, a prop partilhada `financeiro.geracaoPropinasActiva` e os avisos "Em validação" desaparecem. Comportamento restante igual.

- [ ] **Step 1: Escrever o teste que falha**

`Modules/Financeiro/tests/Feature/GeracaoPropinasAnoEncerradoTest.php` (criado com `git mv Modules/Financeiro/tests/Feature/GeracaoPropinasInterruptorTest.php Modules/Financeiro/tests/Feature/GeracaoPropinasAnoEncerradoTest.php` e depois substituído por inteiro):
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Financeiro\DTO\PedidoGeracaoPropinasDTO;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Services\GerarPropinasService;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Turma\Models\Turma;
use Tests\TestCase;

/**
 * Anos lectivos encerrados nunca geram propinas (§7). O interruptor de segurança de F2 (R-F2.1) foi removido na
 * Task 16 de F3, com autorização do dono: a geração já não depende de configuração.
 */
class GeracaoPropinasAnoEncerradoTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    private const PREVIA = 'financeiro.propinas.geracao.pre-visualizar';

    private const GERAR = 'financeiro.propinas.geracao.store';

    private const REGRA = '/financeiro/configuracao/regras-cobranca';

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

    private function encerrar(AnoLectivo $ano): void
    {
        $ano->forceFill(['estado' => EstadoAnoLectivo::ENCERRADO])->saveQuietly();
    }

    private function pedido(AnoLectivo $ano): array
    {
        return ['ano_lectivo_id' => $ano->id, 'ambito' => 'turma', 'turma_id' => Turma::query()->where('ano_lectivo_id', $ano->id)->value('id')];
    }

    public function test_o_interruptor_de_seguranca_ja_nao_existe(): void
    {
        $this->assertNull(config('financeiro.propinas.geracao_activa'));
        $this->assertFalse(class_exists('Modules\\Financeiro\\Exceptions\\GeracaoDePropinasDesactivada'));

        $this->actingAs($this->adminEscola())
            ->get(route('financeiro.propinas.index'))
            ->assertInertia(fn (AssertableInertia $p) => $p->missing('financeiro'));
    }

    public function test_a_regra_aceita_ligar_a_geracao_automatica(): void
    {
        $this->actingAs($this->adminEscola())
            ->put(self::REGRA, [
                'dia_vencimento' => 10, 'dias_tolerancia' => 5, 'permite_pagamento_parcial' => true,
                'permite_pagamento_antecipado' => false, 'gerar_automaticamente' => true,
                'permite_negociacao' => false, 'desconto_maximo_negociacao' => 0,
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue((bool) RegraCobranca::doTenant()->gerar_automaticamente);
    }

    public function test_http_rejeita_ano_lectivo_encerrado_em_ano_lectivo_id(): void
    {
        ['ano' => $ano] = $this->cenarioPropinas();
        $this->encerrar($ano);
        $this->actingAs($this->adminEscola());

        $this->postJson(route(self::PREVIA), $this->pedido($ano))
            ->assertUnprocessable()
            ->assertJsonPath('errors.ano_lectivo_id.0', 'Não é possível gerar propinas para um ano lectivo encerrado.');
        $this->postJson(route(self::GERAR), $this->pedido($ano))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ano_lectivo_id');
        $this->assertSame(0, Propina::query()->count());
    }

    public function test_http_aceita_anos_activo_e_planeado(): void
    {
        ['ano' => $ano] = $this->cenarioPropinas();
        $this->actingAs($this->adminEscola());

        $this->postJson(route(self::PREVIA), $this->pedido($ano))->assertOk();

        $ano->forceFill(['estado' => EstadoAnoLectivo::PLANEADO])->saveQuietly();
        $this->postJson(route(self::PREVIA), $this->pedido($ano))->assertOk();
    }

    public function test_servico_recusa_gerar_num_ano_encerrado(): void
    {
        ['ano' => $ano] = $this->cenarioPropinas();
        $this->encerrar($ano);

        try {
            app(GerarPropinasService::class)->gerar(PedidoGeracaoPropinasDTO::automatica($ano->id));
            $this->fail('Devia ter recusado.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('ano_lectivo_id', $e->errors());
        }

        $this->assertSame(0, Propina::query()->count());
    }

    public function test_comando_ignora_matriculas_de_ano_lectivo_encerrado(): void
    {
        RegraCobranca::doTenant()->update(['gerar_automaticamente' => true]);
        ['matricula' => $actual] = $this->cenarioPropinas();
        $anterior = $this->anoLectivo('2025/2026', '2025-09-01', '2026-07-31');
        $this->plano($anterior, 'Propina 2025/2026', ['valor' => Dinheiro::deUnidadesMenores(2_000_000)]);
        $antiga = $this->matriculaActiva($this->turma($anterior, $this->nivel('NA')), '2025-09-01');
        $this->encerrar($anterior);

        $this->artisan('financeiro:gerar-propinas', ['--tenant' => $this->tenant->codigo])->assertSuccessful();

        $this->assertSame(10, Propina::query()->where('matricula_id', $actual->id)->count());
        $this->assertSame(0, Propina::query()->where('matricula_id', $antiga->id)->count());
    }
}
```

Em `Modules/Financeiro/tests/Feature/GerarPropinasAoActivarMatriculaTest.php`, apagar o método `test_com_o_interruptor_de_seguranca_desligado_nao_gera_mas_activa()` inteiro.

Em `Modules/Financeiro/tests/Feature/ReconciliarCancelamentosDePropinasTest.php`, apagar o método `test_corre_mesmo_com_o_interruptor_de_seguranca_desligado()` inteiro.

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/GeracaoPropinasAnoEncerradoTest.php`
Expected: FAIL em `test_o_interruptor_de_seguranca_ja_nao_existe` (a chave, a classe e a prop ainda existem).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/config/config.php` (substituir o ficheiro inteiro):
```php
<?php

return [
    'name' => 'Financeiro',
];
```

Apagar a excepção: `git rm Modules/Financeiro/app/Exceptions/GeracaoDePropinasDesactivada.php`

`Modules/Financeiro/app/Services/GerarPropinasService.php`:
- apagar `use Modules\Financeiro\Exceptions\GeracaoDePropinasDesactivada;`
- em `gerar()`, substituir
```php
        // Ponto único de criação: o interruptor de segurança (R-F2.1) e o ano encerrado travam aqui, também por defesa em profundidade.
        if (! GeracaoDePropinasDesactivada::activa()) {
            throw new GeracaoDePropinasDesactivada();
        }

        $this->recusarAnoEncerrado($pedido->ano_lectivo_id);
```
por
```php
        // Ponto único de criação: o ano encerrado trava aqui, também por defesa em profundidade.
        $this->recusarAnoEncerrado($pedido->ano_lectivo_id);
```

`Modules/Financeiro/app/Console/GerarPropinasCommand.php`:
- apagar `use Modules\Financeiro\Exceptions\GeracaoDePropinasDesactivada;`
- no docblock da classe, substituir
```php
 * 1. Reconciliação dos cancelamentos por estado da matrícula (D12 de F3): SEMPRE, mesmo com a geração automática
 *    ou o interruptor de segurança desligados — cancelar é protector.
 * 2. Geração automática (spec §7.6): só com «gerar automaticamente» ligado (e, até à Task 16 de F3, o interruptor
 *    de segurança). Idempotente; recupera o que a geração ao activar uma matrícula não fez (§8.2/3).
```
  por
```php
 * 1. Reconciliação dos cancelamentos por estado da matrícula (D12 de F3): SEMPRE, mesmo com a geração automática
 *    desligada — cancelar é protector.
 * 2. Geração automática (spec §7.6): só com «gerar automaticamente» ligado. Idempotente; recupera o que a geração
 *    ao activar uma matrícula não fez (§8.2/3).
```
- em `handle()`, substituir
```php
        $geracaoActiva = GeracaoDePropinasDesactivada::activa();

        // Interruptor de segurança (R-F2.1): a geração fica inerte (código 0 de propósito — o comando está agendado
        // todos os dias); a reconciliação dos cancelamentos corre na mesma.
        if (! $geracaoActiva) {
            Log::warning('financeiro.gerar-propinas.desactivada');
            $this->warn(GeracaoDePropinasDesactivada::MENSAGEM . ' Nada foi gerado; só corre a reconciliação dos cancelamentos.');
        }

        return $this->paraTenants(function (TenantAtual $tenant) use ($geracao, $reconciliacao, $geracaoActiva): void {
```
por
```php
        return $this->paraTenants(function (TenantAtual $tenant) use ($geracao, $reconciliacao): void {
```
  e substituir
```php
            $erros = $reconciliados['erros'];

            if ($geracaoActiva) {
                $erros += $this->gerar($geracao, $tenant);
            }
```
  por
```php
            $erros = $reconciliados['erros'] + $this->gerar($geracao, $tenant);
```

`Modules/Financeiro/app/Listeners/GerarPropinasAoActivarMatricula.php`:
- apagar `use Modules\Financeiro\Exceptions\GeracaoDePropinasDesactivada;`
- no docblock, apagar ` (e, até à Task 16, o interruptor de segurança ligado)`
- em `gerar()`, substituir
```php
        // Interruptor de segurança (R-F2.1, removido na Task 16) e opção da escola (Q7). Leitura pura: sem firstOrCreate.
        if (! GeracaoDePropinasDesactivada::activa() || ! (RegraCobranca::query()->first()?->gerar_automaticamente ?? false)) {
```
  por
```php
        // Opção da escola (Q7). Leitura pura: sem firstOrCreate.
        if (! (RegraCobranca::query()->first()?->gerar_automaticamente ?? false)) {
```

`Modules/Financeiro/app/Http/Requests/Concerns/ValidaGeracaoPropinas.php`:
- apagar `use Illuminate\Auth\Access\AuthorizationException;` e `use Modules\Financeiro\Exceptions\GeracaoDePropinasDesactivada;`
- em `authorize()`, apagar o bloco
```php
        // Interruptor de segurança (R-F2.1): desligado, nem a pré-visualização nem a confirmação passam.
        if (! GeracaoDePropinasDesactivada::activa()) {
            return false;
        }

```
- apagar o método `failedAuthorization()` inteiro.

`Modules/Financeiro/app/Http/Requests/AtualizarRegraCobrancaRequest.php`:
- apagar `use Modules\Financeiro\Exceptions\GeracaoDePropinasDesactivada;` e `use Modules\Financeiro\Models\RegraCobranca;` (o único uso de `RegraCobranca` no ficheiro é o bloco apagado abaixo)
- em `after()`, apagar o bloco
```php
            // R-F2.1: com o interruptor desligado só se aceita manter/pôr a geração automática desligada
            // (um `true` já gravado e reenviado sem alteração continua a passar, para não bloquear outras edições).
            if ($this->boolean('gerar_automaticamente')
                && ! GeracaoDePropinasDesactivada::activa()
                && ! (RegraCobranca::doTenant()->gerar_automaticamente ?? false)) {
                $validador->errors()->add('gerar_automaticamente', 'A geração automática está indisponível: a geração de propinas está desactivada até estar concluída a protecção das matrículas (F3).');
            }

```

`app/Http/Middleware/HandleInertiaRequests.php`: apagar
```php
            // Interruptor de segurança da geração de propinas (Financeiro, R-F2.1): o frontend esconde o que depende dele.
            'financeiro' => [
                'geracaoPropinasActiva' => (bool) config('financeiro.propinas.geracao_activa', false),
            ],
```

`Modules/Financeiro/resources/js/Pages/Propinas/Index.vue`:
- apagar `const geracaoActiva = computed(() => page.props.financeiro?.geracaoPropinasActiva === true);`
- substituir `<div v-if="can('propina.criar') && geracaoActiva">` por `<div v-if="can('propina.criar')">`
- apagar o bloco
```vue
        <div v-if="!geracaoActiva" class="alert alert-info fs-7 py-3 mb-6" data-aviso="geracao-desactivada">
            Em validação: a geração de propinas está desactivada até estar concluída a protecção das matrículas (F3). Não usar com dados reais.
        </div>
```

`Modules/Financeiro/resources/js/Pages/RegrasCobranca/Edit.vue`:
- substituir `import { router, usePage } from '@inertiajs/vue3';` por `import { router } from '@inertiajs/vue3';` (`usePage`/`page` só servem o interruptor neste ficheiro)
- apagar as linhas
```js
// Interruptor de segurança (R-F2.1): desligado, não se pode LIGAR a geração automática (desligar continua possível).
const page = usePage();
const geracaoActiva = computed(() => page.props.financeiro?.geracaoPropinasActiva === true);
const bloqueiaGeracaoAutomatica = computed(() => !geracaoActiva.value && !form.gerar_automaticamente);
```
- substituir `:disabled="!podeEditar || bloqueiaGeracaoAutomatica"` por `:disabled="!podeEditar"`
- apagar o bloco
```vue
                    <div v-if="!geracaoActiva" class="alert alert-info fs-7 py-3 mt-4 mb-0" data-aviso="geracao-desactivada">
                        Em validação: a geração de propinas está desactivada até estar concluída a protecção das matrículas (F3). Não usar com dados reais.
                    </div>
```

`phpunit.xml`: apagar `<env name="FINANCEIRO_GERACAO_PROPINAS" value="true"/>`.

`.env.example`: apagar as três linhas
```
# Financeiro: interruptor de segurança da geração de propinas (manual e automática). Mantém-se desligado
# até estar concluída a protecção das matrículas (F3). Para testar localmente: true + php artisan config:clear.
FINANCEIRO_GERACAO_PROPINAS=false
```
(e a linha em branco que as precede, se ficar duplicada).

- [ ] **Step 4: Correr e ver passar**

Run:
```bash
php artisan test
grep -rnE "geracao_activa|GeracaoDePropinasDesactivada|FINANCEIRO_GERACAO_PROPINAS|geracaoPropinasActiva|geracao-desactivada" app Modules resources tests phpunit.xml .env.example
npm run build
```
Expected: suite verde; o `grep` não devolve nada; build sem erros. `AvisoGeracaoAutomaticaTest` (aviso P3) continua verde — esse aviso fica.

- [ ] **Step 5: Stage e informar o dono**

```bash
git add -A Modules/Financeiro/config/config.php Modules/Financeiro/app Modules/Financeiro/resources/js/Pages Modules/Financeiro/tests app/Http/Middleware/HandleInertiaRequests.php phpunit.xml .env.example
```
Informar o dono: retirar `FINANCEIRO_GERACAO_PROPINAS` do `.env` de cada ambiente (deixa de ser lida) e correr `php artisan config:clear` onde a configuração estiver em cache. **Nenhum commit.**

---

## Riscos (a ler antes de executar e antes de ligar em produção)

| # | Risco | Mitigação / estado |
|---|---|---|
| R-F3.1 | **Q5 (atómico):** um defeito no listener de cancelamento impede cancelar/transferir/concluir/renovar matrículas. | Listener simples, testado (Task 8) incluindo a falha que reverte; reconciliação diária (D12) como rede; o erro chega ao utilizador como erro da mudança de estado. Decisão aprovada pelo dono (Q5). |
| R-F3.2 | **`Event::fake()` sem lista** num teste desliga o cancelamento e a geração ao activar, escondendo regressões. | `ListenersDaMatriculaTest` prova o registo; o `grep` da Task 15 recusa `Event::fake()` sem lista nos módulos Financeiro e Matrícula. |
| R-F3.3 | **`data_fim` retroactiva** (até `data_matricula`) ainda cancela os períodos sem pagamentos que começam depois dela, mesmo já vencidos (regra §9). | O1 aprovado e aplicado (Task 6): nunca antes de `data_matricula` nem depois de hoje (Concluída: até ao fim do ano lectivo). Dados antigos com `data_fim` fora do intervalo não são corrigidos (a reconciliação usa o `data_fim` gravado). |
| R-F3.4 | **Mudanças em Matrícula** (módulo estável): transacção e `lockForUpdate` novos em `AlterarEstado`/`Atualizar`. | Mínimas; testes existentes de Matrícula verdes; ordem de bloqueio matrícula → aluno → propinas sem ciclos (Criar bloqueia o aluno e cria uma matrícula nova; não bloqueia matrículas existentes). |
| R-F3.5 | **Concorrência não demonstrável em SQLite.** | Task 14 (pgsql, duas ligações). Até o dono a correr, risco aceite como em F1/F2. |
| R-F3.6 | **D10:** a geração automática nunca recria um período cancelado; se o cancelamento foi um engano, só a geração manual o repõe. | A pré-visualização do comando mostra "Período cancelado…"; a geração manual continua a regenerar e a pré-visualização assinala cada período cancelado que volta a cobrar (O2 aprovado, `regenera_cancelada`). |
| R-F3.7 | **Reconciliação** com muitas matrículas terminadas: candidatas por `whereColumn` (só as que têm propinas Em Aberto com início depois do fim). | Índices `(tenant_id, matricula_id, periodo_inicio)` e `(tenant_id, estado, data_vencimento)` de F1; custo por matrícula pequeno; idempotente. |
| R-F3.8 | **Geração ao activar** no pedido HTTP (depois do commit, mas ainda no mesmo pedido): uma matrícula ≈ 10 propinas, aceitável; activações em massa (se surgirem) somam. | Hoje não há activação em massa; se surgir, mover para fila (o listener é a única peça a mudar). |
| R-F3.9 | Cancelar/Transferir/Concluir com o Financeiro **desactivado** (módulo fora de `modules_statuses.json`) não cancela nada. | Por desenho (Matrícula independente); ao reactivar o Financeiro, a reconciliação diária apanha o que ficou. |

## Decisões/perguntas abertas para o dono

Aprovadas e aplicadas: Q5, Q6, Q7, Q8, Q10 (fases §3/§8); **O1** e **O2** (dono, 2026-10-14). As restantes decisões desta fase (D1–D17) seguem o plano de fases.

- **O1 — aprovado e aplicado (Task 6):** `data_fim` validada na `AlterarEstadoMatriculaAction`, sobre a linha bloqueada: entre `data_matricula` e `RelogioDoTenant::hoje()`; Concluída aceita futura até ao `data_fim` do ano lectivo; fora disso `ValidationException` em `data_fim`. Detalhes escolhidos no pré-voo (a confirmar pelo dono, não bloqueiam): (a) matrícula que ainda não começou (`data_matricula` > hoje) → o mínimo passa a ser hoje; (b) dados antigos com `data_fim` fora do intervalo não são corrigidos; (c) a UI de mudança de estado não envia `data_fim` (usa hoje), por isso a validação só se vê pela API/Actions.
- **O2 — aprovado e aplicado (Tasks 10 e 12):** a geração manual continua a regenerar um período cancelado, mas cada item traz `regenera_cancelada` e a pré-visualização mostra o badge "Período cancelado" com o aviso (data no fuso da escola e motivo). Não há confirmação adicional além do botão "Confirmar e gerar" já existente.
- **O3 — aberta:** a **Task 16** (remover o interruptor de segurança) **só com autorização expressa do dono**, depois de validar F3 na app e em PostgreSQL.

---

## Tabelas e relações (no fim de F3)

Nenhuma tabela nem coluna nova (D15). Relações usadas:

| Tabela | Estado | Relações / uso em F3 |
|---|---|---|
| `propinas` | existe (F1) | N:1 `matriculas` (restrict), N:1 `planos_propina` (restrict), N:1 `ano_lectivos`, N:1 `tenants`, N:1 `users` (`criado_por`, `cancelado_por`, `anulado_por`). F3 escreve só `estado` = 4, `motivo_cancelamento`, `cancelado_por`, `cancelado_em` (ponto único `CancelamentoDePropinas`); únicos parciais (estados 1–3) deixam regerar depois de cancelar |
| `matriculas` | existe (Matrícula) | 1:N `propinas`; bloqueada `FOR UPDATE` na mudança de estado, na edição, na geração e na reconciliação; emite `MatriculaEstadoAlterado`; consulta `DependenciasDaMatricula`; não conhece o Financeiro |
| `matricula_historicos` | existe | 1 linha por mudança de estado, agora na mesma transacção que o cancelamento das propinas futuras |
| `planos_propina` | existe | lido no detalhe (plano de origem, valor actual) e na geração ao activar |
| `regras_cobranca` | existe | `gerar_automaticamente` decide a geração ao activar (Q7); nunca referenciada por FK |
| `turmas`, `ano_lectivos`, `alunos`, `dados_pessoas`, `users` | existem | só leitura (detalhe; Q8 compara turma/ano) |
| (sem tabela) | — | pagamentos e ajustes no detalhe são listas vazias até Pagamentos-A (`HistoricoDePagamentosDaPropina`, `FonteDePagamentoDePropina`) e F4 |

