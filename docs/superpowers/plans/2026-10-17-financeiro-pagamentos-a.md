# Financeiro → Pagamentos-A: registo, distribuição, crédito do aluno e anulação — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Git:** só `git add` no fim de cada tarefa. **Nunca `git commit`** (é do dono). `migrate`, `migrate:fresh`, `db:seed`, `financeiro:sincronizar` e a base `mositec_escola_test` são do dono. Nunca correr nada contra a base de desenvolvimento `mositec_escola`. Nunca editar dados reais por tinker/SQL: na app, só pelos formulários.

**Goal:** Registar o dinheiro efectivamente recebido de uma matrícula (só Confirmado/Anulado), distribuí-lo pelas propinas (explícita ou automaticamente, da mais antiga para a mais recente), guardar o excedente como crédito **do aluno**, usar esse crédito em propinas de qualquer matrícula do mesmo aluno, anular pagamentos e utilizações de crédito com motivo (nada se apaga) e — com o histórico que isto cria — activar **anular propina** (spec Propinas §8). Todo o `valor_pago` passa a vir de pagamentos reais através do `RecalcularPropina`.

**Architecture:** As tabelas novas (`pagamentos`, `pagamento_propinas`, `creditos`, `credito_utilizacoes`, `pagamento_sequencias`) vivem no módulo `Financeiro` com models planos. Um serviço só de leitura (`Services\DistribuicaoDePagamentoService::calcular`) decide **toda** a divisão (crédito primeiro, depois dinheiro novo, excedente) e é usado duas vezes: pela pré-visualização (sem bloqueios) e pelas Actions, sobre o estado já bloqueado. As Actions (`RegistarPagamentoAction`, `AplicarCreditoAction`, `AnularPagamentoAction`, `AnularUtilizacaoCreditoAction`, `AnularPropinaAction`) abrem **uma** `DB::transaction`, bloqueiam pela ordem global **matrícula → pagamento → propinas (id crescente) → créditos (id crescente) → sequência**, gravam só por criação (registos imutáveis; anular é uma transição única com motivo) e terminam com `RecalcularPropina` (único escritor de `valor_pago`/`estado` 1–3/`capital_liquidado_em`) e `RecalcularCredito` (único escritor de `valor_utilizado`/estado do crédito). O `valor_pago` chega ao `RecalcularPropina` pelos contratos já existentes desde F1: `FonteDePagamentoDePropina` (implementada por `Support\PagamentosDaPropina`) e `HistoricoDePagamentosDaPropina` (implementada por `Support\HistoricoDosPagamentos`).

**Tech Stack:** Laravel 12.69 + nwidart/laravel-modules, Inertia + Vue 3 (axios para pesquisa/pré-visualização JSON), PHPUnit 11.5 com SQLite em memória (produção PostgreSQL; grupo `pgsql` opcional com `PgsqlTestCase`/`PgsqlConcorrenciaTestCase` + `ComProcessosConcorrentes`), Metronic/Bootstrap.

**Spec:** `docs/superpowers/specs/2026-10-08-financeiro-pagamentos-design.md` (**fonte principal**: §2–§9, §11). Também: `docs/superpowers/specs/2026-10-08-financeiro-propinas-design.md` §3 (invariantes), §4 (estado resolvido), §6 (`RecalcularPropina`), §8 (cancelar ≠ anular), §9 (dívidas pagáveis), §15.3 (data efectiva; pagamento retroactivo não reverte multa); `docs/superpowers/specs/2026-10-08-modulo-financeiro-configuracao-design.md` (métodos de pagamento; `permite_pagamento_parcial`/`permite_pagamento_antecipado`; snapshot de moeda/câmbio, "Moeda e Câmbio" e §5 ponto 2 "métodos inactivos continuam referenciáveis"). Plano de fases: `docs/superpowers/plans/2026-10-12-financeiro-propinas-fases.md` §0 (ordem: F4 → **Pagamentos-A** → F5 → Pagamentos-B), §1.2–§1.3 (pontos únicos e contratos), secção "Pagamentos-A", §8.2/1 e §8.2/2 (critérios), §8.2b (relógio), §8.3 (pgsql). Formato e rigor: `2026-10-15-financeiro-propinas-f3.md`, `2026-10-16-financeiro-propinas-f4.md`.

## Global Constraints

- Texto de UI, mensagens e comentários em PT-PT com acentos; identificadores, rotas, tabelas e variáveis sem acento.
- Camada fina: Controller → Service (leitura) / Action (escrita), com DTO e FormRequest. Nada de query no controller (o route model binding resolve os models). `use` no topo, nunca FQN inline (também em testes).
- Dinheiro **sempre** em unidades menores da moeda do tenant (`Dinheiro`, `DinheiroCast`, `Dinheiro::deDecimal($texto, MoedaDoTenant::atual())`), nunca float. Pagamentos guardam `moeda` + `cambio_usd` (snapshot, `SnapshotMonetario::em($dataPagamento)`).
- Tenancy: models novos com `PertenceAoTenant`; `tenant_id` NOT NULL com FK; nunca vem do cliente; ids de outro tenant → 404 (binding) ou 422 (ids no corpo, `Rule::exists` com `tenant_id`). Hooks `saving` **null-safe** (`MatrizIsolamentoTest` grava `(new $classe())->forceFill([])->save()` sem contexto e espera `TenantNaoResolvido`).
- **Proibido** em `Modules/*/app` (`TenancyArquitecturaTest`): `insert`, `insertOrIgnore`, `insertGetId`, `upsert`, `DB::table|raw|statement|select`, `*Quietly`, `withoutEvents`, `withoutGlobalScope(s)`. SQL cru (CHECK, índices parciais, triggers) **só em migrations**.
- "Hoje"/"agora" de negócio só por `RelogioDoTenant::hoje()`/`agora()` em `Modules/Financeiro/app` (`RelogioDoTenantArquitecturaTest`; lista de excepções vazia — não alargar). Instantes gravados: `$relogio->agora()->setTimezone(config('app.timezone'))` (padrão de `CancelamentoDePropinas`).
- Escritores únicos (testes de arquitectura): `valor_pago`, `capital_liquidado_em` e `estado` 2/3 de uma propina só no `RecalcularPropina` (`EscritoresDePropinaTest`, sem excepções novas); criação de propinas só no `GerarPropinasService` (`CriadoresDePropinaTest`); Cancelada só no `CancelamentoDePropinas` (`CanceladoresDePropinaTest`). **Novos:** Anulada só no `AnulacaoDePropinas` (`AnuladoresDePropinaTest`, Task 10); `valor_utilizado` e estado de `Credito` só no `RecalcularCredito`; `pagamento_propinas` nunca alterada nem apagada (`EscritoresDePagamentoTest`, Task 5).
- **A Matrícula nunca importa `Modules\Financeiro\`** (`FronteiraMatriculaFinanceiroTest`). Pagamentos-A **não toca** no módulo Matrícula nem no módulo Aluno (Financeiro só os lê).
- Dentro de `DB::transaction` não se apanham excepções para continuar (PostgreSQL aborta a transacção). Conversões de erro (`ViolacaoDeUnicidade`) só **à volta** da transacção. Efeitos fora da BD só depois do commit (`DB::afterCommit`) — Pagamentos-A não tem nenhum.
- Imutabilidade: um pagamento Confirmado **nunca** se edita (valor, data, método, matrícula, referência, observação, número); corrigir = anular (motivo) + registar novo. `pagamento_propinas` só se cria. Uma utilização de crédito só passa de Activa a Anulada. Nada se apaga (model lança em `deleting`; pgsql: trigger).
- Menus: acrescentar nos **dois** sítios (sidebar `SidebarMenuWrapper.vue` + `useFinanceiroMenu.js`), **descomentando** o placeholder "Pagamentos" que já existe (não inventar).
- **Git: só `git add`. Nunca `git commit`.**

## Decisões desta fase (spec + aplicação ao código real; não reabrir sem o dono)

| # | Decisão | Origem / porquê |
|---|---|---|
| D1 | **Âmbito A vs B.** Pagamentos-A entrega `pagamentos`, `pagamento_propinas`, `creditos`, `credito_utilizacoes` (só `propina_id`; mais `pagamento_id` nullable, O8), as fontes de `valor_pago`/histórico, registar/aplicar crédito/anular pagamento/anular utilização, **anular propina** e a UI. **Fica para Pagamentos-B** (depois de F5): `pagamento_multas`, `credito_utilizacoes.propina_multa_id` + CHECK XOR, imputação capital → multa efectiva e `RecalcularMulta`. Em A a distribuição só liquida **capital**; o motor deixa o ponto de extensão documentado ("depois do capital desta propina, a multa — Pagamentos-B") no mesmo sítio onde B o vai ligar. | Plano de fases §0 (A/B à volta de F5), spec Pagamentos §3 `pagamento_multas`. |
| D2 | **Estados.** `EstadoPagamento` CONFIRMADO=1, ANULADO=2; `EstadoCredito` DISPONIVEL=1, ESGOTADO=2, ANULADO=3; `EstadoUtilizacaoCredito` ACTIVA=1, ANULADA=2. Todos com coluna `*_descricao` sincronizada num hook `saving` próprio null-safe (o `SincronizaEstadoDescricao` do Core assume `Core\Enums\Estado`, L14). Sem Pendente nem conciliação. | Spec Pagamentos §2.2, §3. |
| D3 | **Dinheiro e snapshot.** `valor` (bigint unsigned, > 0) em unidades menores; `moeda char(3)` + `cambio_usd bigint null` copiados por `SnapshotMonetario::em(data_pagamento)`; nunca convertido. A moeda da escola não muda enquanto existir qualquer pagamento (`PagamentosReferenciam` para `ConfiguracaoMonetaria`). Um método de pagamento com pagamentos não se elimina (só desactiva) — mesmo contrato. | Spec Configuração "Moeda e Câmbio" e §5.2; contrato `ReferenciaFinanceira`. |
| D4 | **Número sequencial** por tenant e ano (`AAAA-NNNN`) pelo `GeradorSequencia` do Core com o model `PagamentoSequencia implements SequenciaPorTenant`; o ano é o do **dia de registo na escola** (`RelogioDoTenant::hoje()->year`), passado num novo parâmetro opcional `?int $ano` do gerador (hoje usa `now()->year` em UTC). Pedido **depois** de todas as validações e bloqueios (último bloqueio): um rollback desfaz o incremento → sem lacunas em operações bem-sucedidas. `unique(tenant_id, numero)`. **Ver O2.** | Spec §3 `numero`, §9 "sem lacunas"; `GeradorSequencia` (Core) já é seguro em concorrência. |
| D5 | **Referência:** `trim`, vazia → `null`, até 100. Única por `(tenant_id, metodo_pagamento_id, referencia)` **entre pagamentos Confirmados** (índice único parcial `WHERE referencia IS NOT NULL AND estado = 1`, SQL cru na migration, SQLite e pgsql): uma referência de um pagamento anulado pode ser reutilizada no pagamento correcto. A Action verifica antes (mensagem clara) e a violação do índice (corrida) é convertida **fora** da transacção em 422 `referencia`. Se a referência já foi usada num pagamento **Anulado** do mesmo tenant e método, a pré-visualização e a resposta do registo devolvem um **aviso** não bloqueante (`DistribuicaoDePagamentoService::avisoDeReferencia`, Task 9; **O1**). **Ver O1.** | Spec §3 `unique(...)`; fluxo "anular + registar de novo" (D3 do princípio de imutabilidade). |
| D6 | **Data do pagamento:** `date`, obrigatória, **nem futura (> hoje da escola) nem anterior à `data_matricula` da matrícula** (422 `data_pagamento`; igual ao limite passa; mensagem PT-PT "A data do pagamento não pode ser anterior à data da matrícula (dd/mm/aaaa)."), validado **duas vezes**: no pedido (`ValidaPagamento`) e na Action **sob o lock da matrícula** com a `data_matricula` relida (`DistribuicaoDePagamentoService::validarData($data, $matricula)`) (**O3, decidida pelo dono**; a tolerância "N dias de atraso com permissão própria" **não** entra: ver O10). Consequência: uma matrícula com `data_matricula` futura ainda não aceita pagamentos (R-PA.9); a aplicação de crédito (D12) usa sempre hoje e não é afectada. É a data **efectiva** das contribuições (`ContribuicaoDePagamento`) e decide `capital_liquidado_em`. Um pagamento com data anterior **nunca** reverte multas já aplicadas (F5, spec Propinas §15.3 "decisão explícita"): em A não há multas; o comentário/ponto de extensão fica no `RecalcularPropina` (já existe "F5: …") e o teste de F5 provará. | Spec §3, Propinas §15.3. |
| D7 | **Pagamento pertence a uma matrícula**, em **qualquer** estado (Activa, Concluída, Cancelada, Transferida, Pendente) e de qualquer ano, incluindo encerrado (dívidas antigas sempre pagáveis). Matrícula eliminada (soft delete) → 404 no registo. `pagamentos.aluno_id` = `matricula.aluno_id` (snapshot imutável, para o filtro por aluno e para o crédito; validado no model). | Spec §2.6, §4 "Dívidas de anos anteriores", Propinas §9. |
| D8 | **Propinas pagáveis** = da mesma matrícula e tenant, estado persistido **Em Aberto ou Parcialmente Paga** (Pendente/Em Atraso são resolvidos destes), saldo > 0. Validações por linha explícita: pertence à matrícula e é pagável (senão 422 `distribuicao.N.propina_id`); `valor ≤ saldo`; sem `permite_pagamento_parcial` → `valor = saldo` (pagar o remanescente é sempre permitido, porque é o saldo); `periodo_inicio > data efectiva` exige `permite_pagamento_antecipado` (**ver O6**); ids repetidos → 422. Soma das linhas ≤ dinheiro + crédito usado. | Spec §4 "Validações de cada distribuição". |
| D9 | **Distribuição automática** (sem linhas): pagáveis da matrícula ordenadas por `periodo_inicio`, `ordem`, `id`; consome dinheiro + crédito; uma propina futura sem antecipado **termina** a lista (as seguintes também são futuras); sem parcial, liquida só por inteiro e **pára** na primeira que não cabe (nunca salta uma mais antiga para pagar uma mais recente — **ver O5**); com parcial, a última leva o que sobra. O que sobrar do dinheiro é excedente. | Spec §4 "Distribuição automática". |
| D10 | **Crédito primeiro.** `valor_credito` (0 = não usar) consome créditos DISPONIVEL do aluno, do mais antigo para o mais recente, **antes** do dinheiro novo, percorrendo as linhas pela ordem das propinas; uma linha pode ser coberta por vários créditos e por crédito + dinheiro. `valor_credito` > saldo total dos créditos → 422; > total distribuído → 422 (não se "move" crédito para crédito). A utilização não entra no `valor` do pagamento e regista-se independente (`credito_utilizacoes`, `data_utilizacao` = **hoje da escola**, **ver O7**). Quando feita no mesmo acto de um pagamento com dinheiro novo, a utilização guarda `pagamento_id` (FK `pagamentos`, restrict, **nullable**, imutável; **O8**); a aplicação isolada (D12) deixa-o `null`. | Spec §4 "Combinar crédito", §3 `credito_utilizacoes`. |
| D11 | **Excedente → crédito do aluno.** `excedente = dinheiro − Σ dinheiro distribuído`; se > 0, cria **um** `creditos` (`origem_pagamento_id` único, `aluno_id` = aluno da matrícula, DISPONIVEL). Invariante: `pagamento.valor = Σ pagamento_propinas.valor + crédito gerado.valor`. Pagamento sem nenhuma propina pagável é permitido (tudo crédito; **ver O4**). | Spec §2.3, §3 invariante, §4 "Excedente". |
| D12 | **Aplicar crédito sem dinheiro novo** (`AplicarCreditoAction`, permissão `pagamento.criar`, spec §8 "acções de crédito reutilizam criar"): mesmo motor com dinheiro = 0 e data efectiva = hoje; só cria `credito_utilizacoes`. | Spec §8; necessário para usar crédito numa matrícula futura (§2.3). |
| D13 | **Ordem global de bloqueio** (sem ciclos com F2–F4): **matrícula** (`FOR UPDATE`) → **pagamento** (só na anulação) → **propinas** (`BloqueioDePropinas`, id crescente) → **créditos** (id crescente) → **utilização** (só na anulação da utilização) → **sequência** (`GeradorSequencia`, só no registo). Pagamentos nunca bloqueiam planos (não os lêem), por isso respeitam a ordem matrícula → plano → propinas de F2 (geração), F3 (cancelamento por estado: matrícula → propinas) e F4 (matrículas → plano → propinas). O cancelamento manual (F3) e a anulação de propina bloqueiam **só** a propina (subsequência da mesma ordem). Depois do bloqueio, tudo é relido: uma propina cancelada por outra sessão antes do bloqueio deixa de ser pagável. | Plano F4 D7; F3 D5; spec §4 "ordena e bloqueia". |
| D14 | **Anular pagamento** (`pagamento.anular`, motivo 5–500): recusado se já Anulado; **bloqueado** se o crédito gerado tem utilizações Activas (mensagem com a soma e "anule primeiro essas utilizações"); efeito: pagamento → ANULADO (+ motivo, autor, instante), crédito gerado → ANULADO (`RecalcularCredito::anular`), `RecalcularPropina` de todas as propinas distribuídas (regressam a Em Aberto/Parcial; `capital_liquidado_em` refeito). As utilizações de crédito feitas no mesmo acto (`credito_utilizacoes.pagamento_id` = este pagamento) **não** são tocadas: continuam Activas (independentes, spec §4; **O8**), mas o detalhe e o modal de anulação **listam-nas** para o utilizador as poder anular à parte. Nada se apaga. | Spec §5. |
| D15 | **Anular utilização de crédito** (`pagamento.anular`, motivo): ACTIVA → ANULADA; `RecalcularCredito` (o crédito recupera saldo; ESGOTADO volta a DISPONIVEL); `RecalcularPropina` da propina. | Spec §6. |
| D16 | **Anular propina** (`propina.anular`, motivo 5–500): só persistida **Em Aberto**, `valor_pago = 0`, sem contribuições activas **e com histórico** (alguma distribuição ou utilização que a tocou, em qualquer estado). Sem histórico → "cancele-a". Ponto único `Support\AnulacaoDePropinas` (espelho de `CancelamentoDePropinas`), Action bloqueia **só** a propina. O texto do `CancelamentoDePropinas` deixa de dizer "(disponível com o módulo de Pagamentos)". ADMIN_ESCOLA ganha `propina.anular`. | Spec Propinas §8; plano de fases §8.2/2, secção Pagamentos-A. |
| D17 | **Imutabilidade na base (pgsql):** CHECK + triggers como `propina_ajustes`: `pagamento_propinas` recusa UPDATE/DELETE; `pagamentos` só aceita a transição CONFIRMADO → ANULADO com os campos de anulação (e o `ON DELETE SET NULL` das colunas de autoria); `credito_utilizacoes` só ACTIVA → ANULADA (`pagamento_id` incluído: o trigger compara todas as colunas excepto as de anulação/autoria, por isso é imutável); `creditos` recusa mudar `aluno_id`/`origem_pagamento_id`/`valor`, sair de ANULADO e DELETE. Em SQLite as mesmas regras vivem nos models (lançam `LogicException`). | Princípio "nunca editar pagamento confirmado"; spec §2.4. |
| D18 | **Permissões:** `Modulo::PAGAMENTO = 23`, slug `pagamento`, label "Pagamento", acções `['ver','listar','criar','anular','exportar']` (lista explícita; sem `editar`/`eliminar`). `ModuloSeeder` 23 => 'Pagamento'. ADMIN_ESCOLA: `pagamento` ver/listar/criar/anular/exportar e `propina.anular` (**ver O9**). Ver créditos segue `pagamento.ver`; aplicar crédito `pagamento.criar`; anular utilização `pagamento.anular`. `exportar` declarado mas sem rota (como em `propina`). Dono corre `db:seed --force` e `financeiro:sincronizar --todos`. | Spec §8; padrão de F1 (Q11). |
| D19 | **Interface.** Menu "Financeiro → Pagamentos" (descomentar nos dois sítios). Lista com filtros (pesquisa aluno/matrícula/número, método, estado, intervalo de datas, referência); registo em passos (matrícula → valor e método → distribuição automática editável → resumo com crédito usado e excedente; a confirmação envia **sempre a distribuição explícita** que o resumo mostrou e o servidor revalida tudo sob bloqueio; o campo da data tem `min` = data da matrícula e `max` = hoje; o resumo mostra avisos não bloqueantes, ex. referência já usada num pagamento anulado); detalhe com distribuição, crédito gerado, utilizações de crédito combinadas no mesmo acto e anulação (o modal lista as utilizações que continuam Activas); página de créditos do aluno (com aplicar crédito e anular utilização); detalhe da propina passa a listar pagamentos/utilizações e ganha "Anular propina". | Spec §7, Propinas §10. |

**Não provado em SQLite (só no grupo pgsql, Tasks 18–19):** `FOR UPDATE` a segurar outras sessões; dois pagamentos simultâneos na mesma propina; pagamento × alteração de preço × cancelamento; numeração sem lacunas sob concorrência; CHECK e triggers de imutabilidade.

## Notas pré-voo (verificadas no código em 2026-10-15, ramo `feat/modulo-financeiro`, F1–F4 já em commits do dono: `3296817`)

- **Contratos de F1 prontos a implementar** (não mudam de assinatura):
  - `Contracts\FonteDePagamentoDePropina::contribuicoes(Propina): list<ContribuicaoDePagamento>`; agregador `Support\FontesDePagamentoDePropina` (etiqueta `FontesDePagamentoDePropina::ETIQUETA = 'financeiro.fontes-pagamento-propina'`) ordena por data (estável).
  - `Support\ContribuicaoDePagamento(Dinheiro $valor, CarbonImmutable $data)` lança se valor ≤ 0 ou data > `RelogioDoTenant::hoje()`.
  - `Contracts\HistoricoDePagamentosDaPropina::temHistorico(Propina): bool`; agregador `Support\HistoricosDePagamentoDaPropina` (etiqueta `'financeiro.historicos-pagamento-propina'`).
  - Nenhuma implementação registada no `FinanceiroServiceProvider` (só fakes nos testes: `FontePagamentoFalsa`, `HistoricoPagamentoFalso`, via `fontePagamentoFalsa()`/`historicoPagamentoFalso()` de `ComPropinasFinanceiro`). Os testes antigos continuam válidos: as fontes reais devolvem `[]`/`false` para propinas sem pagamentos.
- `Services\RecalcularPropina`: `executar(Propina)`, `executarVarias(list<int>)` (bloqueia todas por id crescente), `reajustarValor` (F4). Exige transacção; lança se houver contribuições numa Cancelada/Anulada; `capital_liquidado_em` = data da contribuição que levou o acumulado a ≥ valor. Comentário "F5: a multa…" já marca o ponto de `RecalcularMulta`.
- `Support\BloqueioDePropinas::bloquear(list<int>): Collection` (exige transacção; id crescente; devolve linhas relidas).
- `Support\CancelamentoDePropinas::motivoParaNaoCancelar()` já consulta fontes e históricos; a mensagem de histórico diz "(disponível com o módulo de Pagamentos)" — `CancelarPropinaTest:107` e `CancelamentoDePropinasTest:74` testam esse texto (Task 10 actualiza ambos).
- `EstadoCobranca::transicoesPermitidas()`: EM_ABERTO → PARCIAL/PAGA/CANCELADA/ANULADA; PARCIAL → EM_ABERTO/PAGA; PAGA → EM_ABERTO/PARCIAL. O model `Propina` valida coerência estado/`valor_pago`/`capital_liquidado_em`/`anulado_*` (mesmas regras dos CHECK pgsql `propinas_*`).
- `Propina::IMUTAVEIS` inclui `matricula_id`, `ano_lectivo_id`, `moeda`…; `Propina::ESTADOS_ACTIVOS = [1, 2, 3]`; `scopeActivas`; `saldo()`, `valorRecebido()`.
- `Modulo` enum vai até `PROPINA = 22` (`acoesAplicaveis()` só declara `PROPINA`); `ModuloSeeder` até 22; `AcaoSeeder` já tem `anular` (7). `SincronizarPerfisDeSistemaAction::PERMISSOES_POR_PERFIL[ADMIN_ESCOLA][PROPINA] = ['ver','listar','criar','cancelar','exportar','ajustar']` (comentário: "`anular` entra com Pagamentos"). `PermissoesPropinaTest::test_admin_escola_tem_as_acoes_de_f1_e_ajustar` afirma que ADMIN **não** tem `anular` → Task 1 actualiza.
- `MetodoPagamento` (`metodos_pagamento`): `nome`, `tipo` (`TipoMetodoPagamento`), `estado` int (`Core\Enums\Estado::ATIVO = 1`, `INATIVO = 0`), `scopeActivos()`. `EliminarMetodoPagamentoAction` já consulta `ReferenciasFinanceiras` → basta etiquetar `PagamentosReferenciam`.
- `RegraCobranca::doTenant()` (firstOrCreate) com `permite_pagamento_parcial` e `permite_pagamento_antecipado` (bool, default false, ambos no `$fillable`).
- `Core\Services\GeradorSequencia::gerar(string $modelo): string` → `'%d-%04d'` com `now()->year` (UTC); está nas excepções de `TenancyArquitecturaTest` (upsert). Precedente: `Modules\Matricula\Models\MatriculaRegistoSequencia` + migration `matricula_registo_sequencias` (`tenant_id`, `ano` smallint, `ultimo_numero` int, `unique(tenant_id, ano)`). Teste: `Modules/Core/tests/Feature/GeradorSequenciaTest.php`. `tests/Feature/Tenancy/SequenciasIsolamentoTest.php` cobre isolamento de sequências.
- `Services\SnapshotMonetario::em(CarbonInterface): array{moeda: string, cambio_usd: ?int}` (instância injectada; é `Services`, não `Support`); `Support\Moeda::de($codigo)->paraFrontend()`; `Rules\ValorMonetario(bool $permiteZero)`.
- `Support\ViolacaoDeUnicidade::e()`, `dasPropinas()`, `doNomeDoPlano()` — Task 7 acrescenta `daReferenciaDoPagamento()`.
- Ordem de bloqueio existente: `GerarPropinasService` (matrícula `FOR UPDATE` → planos `FOR SHARE`), `CancelarPropinasAoTerminarMatricula`/`ReconciliarCancelamentosDePropinas` (matrícula → propinas), `AlterarPrecoPlanoAction` (matrículas → plano → propinas), `CancelarPropinaAction` (só propina), `AlterarEstadoMatriculaAction` e `AtualizarMatriculaAction` (matrícula `FOR UPDATE`).
- Rotas: grupo `financeiro/propinas` (`financeiro.propinas.`) com `index`, `show`, geração e `PATCH /{propina}/cancelar`. Controller `PropinaController` (show passa `podeCancelar`). `PropinaConsultaService::detalhe()` devolve `'pagamentos' => []` e `'cancelamento_bloqueado'`; `Pages/Propinas/Show.vue` tem o cartão "Pagamentos" com o texto "Os pagamentos aparecem aqui quando o módulo de Pagamentos estiver disponível."
- Pesquisa com `whereContem` (macro existente) por `numero_registo_matricula`, `alunos.numero_matricula` e `dadosPessoa.nome_completo` (padrão de `PropinaConsultaService::listar`). Páginas Inertia: `Inertia::render('Financeiro/<Pasta>/<Pagina>')` ↔ `Modules/Financeiro/resources/js/Pages/<Pasta>/<Pagina>.vue`. JS: `Support/dinheiro.js` (`formatarDinheiro`, `unidadesMenoresParaDecimal`, `decimalParaUnidadesMenores`), `Support/periodos.js` (`dataCurta`), `Models/Propina.js` (`estadoCobrancaBadgeClass`).
- Menu: `SidebarMenuWrapper.vue` `const financeiro` tem `// { href: '#', title: 'Pagamentos' },`; `useFinanceiroMenu.js` tem `// { href: '#', label: 'Pagamentos' },`. `tests/Feature/Arquitectura/MenuFinanceiroTest.php` exige hoje **um** só `href` activo em cada (Task 15 passa a dois).
- Testes pgsql: `tests/PgsqlTestCase.php`, `tests/PgsqlConcorrenciaTestCase.php` (`outraLigacao()`, DatabaseTruncation), `Modules/Financeiro/tests/Feature/Pgsql/ComProcessosConcorrentes.php` (processos filhos com ops `alterar`, `gerar`, `cancelar`; `lancar()`, `esperar()`, `esperarQueOFilhoFiqueABloquear()`, `aCorrer()`, `deadlocksDaBase()`).
- `app.timezone = 'UTC'`. **Testes sensíveis à hora:** não correr a suite entre as 23:00 e as 24:00 UTC (F2/F3). Os testes deste plano congelam `Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'))` (hoje da escola, Luanda, = 2026-10-16).
- **Pré-voo executado (2026-10-15, cópia descartável, T1–T19 à letra):** SQLite 3124 testes OK (baseline 2976 + 148); grupo `pgsql` em PostgreSQL 16 descartável 52 testes OK, 3 execuções seguidas, 0 deadlocks; `npm run build` OK. Corrigidos no plano: T1 (contagens de módulos 23→24 e `AcoesAplicaveisTest`), T4 (teste "exige transacção" com `DB::rollBack()`), T5 (regex apanhava o cast `EstadoCredito::class`), T8 (teste recusava por parcial antes do crédito), T12 (`detalhe` com `load` em vez de `loadMissing`), T18 (`insert` com `id => null` em PostgreSQL).
- **Emendas pós-pré-voo (decisões do dono, 2026-10-15; mesmas 20 tarefas, sem renumerar):** (1) **O3 mudou**: `data_pagamento` nunca anterior à `data_matricula` nem futura, no pedido **e** na Action sob lock (T6 `validarData`, T7, T13, T16); "N dias de atraso com permissão própria" **não** entra (O10, pendente). (2) **O8**: `credito_utilizacoes.pagamento_id` nullable preenchido nas utilizações do mesmo acto de um pagamento combinado (T3 migration/model/helper, T7, T8 `null`, T9, T12 detalhe, T15 modal/detalhe, T18 pgsql); as utilizações continuam Activas ao anular o pagamento. (3) **O1**: aviso (não bloqueia) de referência já usada num pagamento Anulado do mesmo tenant+método (T9 serviço, T13 pré-visualização e flash `warning`, T16 resumo). (4) **O9** fica V1 como está; a ressalva de segregação de funções fica registada (R-PA.10, O9).
- **Antes da Task 1:** `git diff --cached --name-only > /tmp/pa-stage-antes.txt` e `php artisan test 2>&1 | tail -3` (baseline; anotar o número de testes).

## Fora de âmbito

`pagamento_multas`, imputação capital → multa efectiva, crédito sobre multa e `RecalcularMulta` (Pagamentos-B, depois de F5); multas (F5); estado Pendente/conciliação; recibos (o `numero` é a base deles); devolução/reembolso de crédito; um pagamento para várias matrículas numa operação (regista-se um por matrícula); dívidas/acordos; descontos/bolsas; juros; relatórios e exportação (acção `exportar` declarada sem rota); fiscalidade; cobranças de Produtos/Serviços (spec §10).

## Review Focus

1. **Duplo clique / duas abas a registar o mesmo pagamento** (mesmo valor, mesma propina, sem referência): o segundo encontra a propina já Paga (ou com saldo menor) e é recusado na distribuição explícita, ou — sem parcial e em automático — vira **crédito inteiro** sem aviso. A pré-visualização tem de mostrar "Nada a distribuir: o valor fica como crédito do aluno" e o resumo exigir confirmação explícita quando `excedente = valor` (Task 16, `test_o_resumo_avisa_quando_tudo_vai_para_credito`); no servidor, o 2.º registo explícito é 422 (Task 7, `test_segundo_registo_explicito_da_mesma_propina_e_recusado`).
2. **Pagamento entre a pré-visualização e a confirmação de uma alteração de preço** (F4): agora com pagamentos reais — a alteração tem de recusar por impressão digital (Task 11, `test_pagamento_real_entre_previa_e_confirmacao_faz_recusar_a_alteracao`) e, em pgsql, as duas operações concorrentes nunca bloqueiam em ciclo (Task 19).
3. **Anular um pagamento cuja propina, entretanto, teve o preço reduzido abaixo do pago** (F4 D6, `valor = valor_pago`): a propina volta a Em Aberto com `valor` reduzido e o `valor_original` intacto; a invariante auditável continua verde (Task 11, `test_anular_pagamento_depois_de_descida_abaixo_do_pago`).
4. **Matrícula terminada** (Cancelada/Transferida com propinas futuras canceladas por F3): as dívidas restantes continuam pagáveis; as Canceladas nunca aparecem como pagáveis nem aceitam linhas explícitas (Task 6 e Task 11, `test_divida_de_matricula_cancelada_e_pagavel_e_as_canceladas_nao`).
5. **Crédito de um irmão / de outro aluno / de outro tenant**: `valor_credito` só vê créditos do aluno da matrícula; ids de créditos nunca vêm do cliente (Task 6 `test_creditos_de_outro_aluno_nao_contam`; Task 11 tenancy).
6. **Referência duplicada em corrida** (duas sessões com a mesma referência bancária): uma passa, a outra recebe 422 `referencia`, não 500 (Task 7 sequencial; Task 19 pgsql).
7. **Anulação parcial em cadeia**: anular uma utilização de crédito e depois o pagamento que gerou esse crédito; a ordem inversa é recusada com mensagem (Task 9).
8. **Retro-datar um pagamento para fugir a multas (O3)**: uma data antes da `data_matricula` é recusada no pedido e, sob lock, na Action (Task 7 `test_data_do_pagamento_nunca_anterior_a_matricula_nem_futura`); datas entre a matrícula e hoje continuam livres (O10 pendente: tolerância de N dias com permissão própria).
9. **Utilização combinada rastreável (O8)**: anular um pagamento deixa as utilizações do mesmo acto Activas **mas listadas** no detalhe e no modal (Task 9, Task 12, Task 15).

---

## Mapa de ficheiros

```
Modules/Core/app/Services/GeradorSequencia.php                                    (T2, + parâmetro ?int $ano)
Modules/Core/tests/Feature/GeradorSequenciaTest.php                               (T2, + caso)
Modules/Permissao/app/Enums/Modulo.php                                            (T1, + PAGAMENTO = 23)
Modules/Permissao/database/seeders/ModuloSeeder.php                               (T1, + 23 => 'Pagamento')
Modules/Permissao/app/Actions/SincronizarPerfisDeSistemaAction.php                (T1, + pagamento.*, propina.anular)
Modules/Permissao/tests/Unit/ModuloEnumTest.php                                   (T1, + caso)
Modules/Permissao/tests/Feature/ModuloSeederTest.php                              (T1, + caso)
Modules/Financeiro/
  database/migrations/2026_10_17_100000_create_pagamento_sequencias_table.php     (T2, novo)
  database/migrations/2026_10_17_100100_create_pagamentos_table.php               (T2, novo)
  database/migrations/2026_10_17_100200_create_pagamento_propinas_table.php       (T3, novo)
  database/migrations/2026_10_17_100300_create_creditos_table.php                 (T3, novo)
  database/migrations/2026_10_17_100400_create_credito_utilizacoes_table.php      (T3, novo; + pagamento_id nullable, O8)
  app/Enums/EstadoPagamento.php, EstadoCredito.php, EstadoUtilizacaoCredito.php   (T2/T3, novos)
  app/Models/PagamentoSequencia.php, Pagamento.php                                (T2, novos)
  app/Models/PagamentoPropina.php, Credito.php, CreditoUtilizacao.php             (T3, novos)
  app/Support/PagamentosDaPropina.php                                             (T4, novo: FonteDePagamentoDePropina)
  app/Support/HistoricoDosPagamentos.php                                          (T4, novo: HistoricoDePagamentosDaPropina)
  app/Support/PagamentosReferenciam.php                                           (T4, novo: ReferenciaFinanceira)
  app/Services/RecalcularCredito.php                                              (T4, novo)
  app/Providers/FinanceiroServiceProvider.php                                     (T4, etiquetas)
  app/DTO/LinhaDeDistribuicao.php, RegistarPagamentoDTO.php, AplicarCreditoDTO.php (T6, novos)
  app/DTO/PlanoDePagamentoDTO.php, MotivoDeAnulacaoDTO.php                        (T6, novos)
  app/Services/DistribuicaoDePagamentoService.php                                 (T6, novo; T9 + avisoDeReferencia)
  app/Support/BloqueioDePagamentos.php                                            (T7, novo)
  app/Support/ViolacaoDeUnicidade.php                                             (T7, + daReferenciaDoPagamento)
  app/Actions/RegistarPagamentoAction.php                                         (T7, novo)
  app/Actions/AplicarCreditoAction.php                                            (T8, novo)
  app/Actions/AnularPagamentoAction.php, AnularUtilizacaoCreditoAction.php        (T9, novos)
  app/Support/AnulacaoDePropinas.php, app/Actions/AnularPropinaAction.php         (T10, novos)
  app/Support/CancelamentoDePropinas.php                                          (T10, texto da mensagem)
  app/Services/PagamentoConsultaService.php                                       (T12, novo)
  app/Services/PropinaConsultaService.php                                         (T14, pagamentos + anulação no detalhe)
  app/Http/Requests/Concerns/ValidaPagamento.php, ValidaMotivoDeAnulacao.php      (T13, novos)
  app/Http/Requests/PreVisualizarPagamentoRequest.php, RegistarPagamentoRequest.php, AplicarCreditoRequest.php (T13, novos)
  app/Http/Requests/AnularPagamentoRequest.php, AnularUtilizacaoCreditoRequest.php, AnularPropinaRequest.php (T13/T14, novos)
  app/Http/Controllers/PagamentoController.php, CreditoController.php             (T13, novos)
  app/Http/Controllers/PropinaController.php                                      (T14, + anular, podeAnular)
  routes/web.php                                                                  (T13/T14, + rotas)
  resources/js/Models/Pagamento.js                                                (T15, novo)
  resources/js/Pages/Pagamentos/Index.vue, Show.vue                               (T15, novos)
  resources/js/Components/Pagamentos/AnularRegistoModal.vue                       (T15, novo; + lista de itens afectados)
  resources/js/Pages/Pagamentos/Create.vue                                        (T16, novo; data min/max e avisos)
  resources/js/Components/Pagamentos/DistribuicaoResumo.vue                       (T16, novo)
  resources/js/Pages/Creditos/Aluno.vue                                           (T17, novo)
  resources/js/Components/Pagamentos/AplicarCreditoModal.vue                      (T17, novo)
  resources/js/Pages/Propinas/Show.vue                                            (T17, pagamentos + anular)
  tests/Concerns/ComPagamentosFinanceiro.php                                      (T2→T7, novo; cresce por tarefa)
  tests/Feature/PermissoesPagamentoTest.php                                       (T1, novo)
  tests/Feature/PermissoesPropinaTest.php                                         (T1, actualizado)
  tests/Feature/PagamentoModelTest.php                                            (T2, novo)
  tests/Feature/CreditoModelTest.php                                              (T3, novo)
  tests/Feature/FontesDosPagamentosTest.php                                       (T4, novo)
  tests/Feature/DistribuicaoDePagamentoTest.php                                   (T6, novo)
  tests/Feature/RegistarPagamentoTest.php                                         (T7, novo)
  tests/Feature/AplicarCreditoTest.php                                            (T8, novo)
  tests/Feature/AnularPagamentoTest.php                                           (T9, novo)
  tests/Feature/AnularPropinaTest.php                                             (T10, novo)
  tests/Feature/CancelarPropinaTest.php, CancelamentoDePropinasTest.php           (T10, texto)
  tests/Feature/PagamentosIntegracaoPropinasTest.php                              (T11, novo)
  tests/Feature/FinanceiroTenancyTest.php                                         (T11, + casos)
  tests/Feature/PagamentoConsultaTest.php                                         (T12, novo)
  tests/Feature/PagamentoHttpTest.php                                             (T13, novo)
  tests/Feature/PropinaDetalhePagamentosTest.php                                  (T14, novo)
  tests/Unit/EstadosPagamentoTest.php                                             (T2/T3, novo)
  tests/Unit/PagamentosVueTest.php                                                (T15–T17, novo)
  tests/Feature/Pgsql/PagamentoPgsqlTest.php                                      (T18, novo)
  tests/Feature/Pgsql/ComProcessosConcorrentes.php                                (T19, + ops)
  tests/Feature/Pgsql/PagamentoConcorrenciaPgsqlTest.php                          (T19, novo)
tests/Feature/Arquitectura/EscritoresDePagamentoTest.php                          (T5, novo)
tests/Feature/Arquitectura/AnuladoresDePropinaTest.php                            (T10, novo)
tests/Feature/Arquitectura/MenuFinanceiroTest.php                                 (T15, dois links)
resources/js/Components/Layout/SidebarMenuWrapper.vue                            (T15, descomentar Pagamentos)
resources/js/Composables/useFinanceiroMenu.js                                    (T15, descomentar Pagamentos)
```

(Todos os caminhos `tests/…` dentro de `Modules/Financeiro/` salvo os de `tests/Feature/Arquitectura/` e os do Core/Permissão, que estão indicados por inteiro.)

## Grupos e ordem

| Grupo | Tarefas | Entregável verificável |
|---|---|---|
| A — Permissões e dados | 1, 2, 3 | `Modulo::PAGAMENTO`, tabelas, models imutáveis, numeração |
| B — Ligação às propinas | 4, 5 | fontes de `valor_pago`/histórico reais, referências, `RecalcularCredito`, regras de arquitectura |
| C — Cálculo (só leitura) | 6 | motor de distribuição (explícita/automática/parcial/antecipado/crédito/excedente) |
| D — Escrita transaccional | 7, 8, 9, 10, 11 | registar, aplicar crédito, anular pagamento/utilização, anular propina, integração F3/F4 e tenancy |
| E — Leitura e HTTP | 12, 13, 14 | consultas, rotas JSON + Inertia, permissões, detalhe da propina |
| F — Frontend | 15, 16, 17 | menu, lista, detalhe, registo em passos, créditos do aluno, anular propina |
| G — PostgreSQL | 18, 19 | CHECK/triggers e concorrência com sessões reais |
| H — Verificação | 20 | suite, build, git, instruções ao dono |

Dependências: 1 → 2 → 3 → 4 → 5; 6 depende de 3; 7 depende de 4, 6; 8 depende de 7; 9 depende de 7 e 8; 10 depende de 4; 11 depende de 7–10; 12 depende de 3; 13 depende de 7–9, 12; 14 depende de 10, 12; 15–17 dependem de 13, 14; 18 depende de 3, 7; 19 depende de 7–10; 20 no fim.

---

### Task 1: Permissões — `Modulo::PAGAMENTO` e `propina.anular` (D16, D18)

**Files:**
- Modify: `Modules/Permissao/app/Enums/Modulo.php`
- Modify: `Modules/Permissao/database/seeders/ModuloSeeder.php` (lista `$modulos`, depois de `22 => 'Propina'`)
- Modify: `Modules/Permissao/app/Actions/SincronizarPerfisDeSistemaAction.php` (`PERMISSOES_POR_PERFIL[ADMIN_ESCOLA]`)
- Modify: `Modules/Permissao/tests/Unit/ModuloEnumTest.php`, `Modules/Permissao/tests/Feature/ModuloSeederTest.php`, `Modules/Permissao/tests/Feature/AcoesAplicaveisTest.php`
- Modify: `Modules/Financeiro/tests/Feature/PermissoesPropinaTest.php` (`test_admin_escola_tem_as_acoes_de_f1_e_ajustar`)
- Create: `Modules/Financeiro/tests/Feature/PermissoesPagamentoTest.php`

**Interfaces:**
- Consumes: catálogo `acoes` (já tem `anular` = 7), `PermissaoCache::invalidarTudo()` (já chamado por `concederPermissoes`).
- Produces: abilities `pagamento.ver|listar|criar|anular|exportar` e `propina.anular` concedidas ao ADMIN_ESCOLA; `Modulo::PAGAMENTO` (23, `pagamento`, "Pagamento"). Todas as rotas seguintes usam estas abilities.

- [ ] **Step 1: Escrever os testes**

`Modules/Financeiro/tests/Feature/PermissoesPagamentoTest.php`:
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

class PermissoesPagamentoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
    }

    private function utilizadorCom(Perfil $perfil): User
    {
        $user = User::create(['name' => 'Teste', 'email' => $perfil->value . '-pag@example.com', 'password' => Hash::make('x')]);
        $user->roles()->syncWithoutDetaching([Role::where('nome', $perfil->value)->first()->id]);

        return $user;
    }

    public function test_modulo_tem_slug_label_e_acoes_proprias(): void
    {
        $this->assertSame(23, Modulo::PAGAMENTO->value);
        $this->assertSame('pagamento', Modulo::PAGAMENTO->slug());
        $this->assertSame(Modulo::PAGAMENTO, Modulo::fromSlug('pagamento'));
        $this->assertSame('Pagamento', Modulo::PAGAMENTO->label());
        $this->assertSame(['ver', 'listar', 'criar', 'anular', 'exportar'], Modulo::PAGAMENTO->acoesAplicaveis());
    }

    public function test_admin_escola_tem_as_acoes_de_pagamento_e_nunca_editar_nem_eliminar(): void
    {
        $admin = $this->utilizadorCom(Perfil::ADMIN_ESCOLA);

        foreach (['ver', 'listar', 'criar', 'anular', 'exportar'] as $acao) {
            $this->assertTrue(Gate::forUser($admin)->allows("pagamento.{$acao}"), $acao);
        }

        foreach (['editar', 'eliminar', 'confirmar'] as $acao) {
            $this->assertFalse(Gate::forUser($admin)->allows("pagamento.{$acao}"), $acao);
        }
    }

    public function test_funcionario_e_professor_nao_acedem(): void
    {
        foreach ([Perfil::FUNCIONARIO, Perfil::PROFESSOR] as $perfil) {
            $this->assertFalse(Gate::forUser($this->utilizadorCom($perfil))->allows('pagamento.ver'), $perfil->name);
        }
    }

    public function test_sincronizar_concede_pagamento_e_propina_anular_a_tenants_existentes(): void
    {
        $admin = $this->utilizadorCom(Perfil::ADMIN_ESCOLA);
        // Simula um tenant criado antes de Pagamentos-A: sem pagamento.* nem propina.anular.
        RolePermissao::query()->where('modulo_id', ModuloRegistro::where('nome', Modulo::PAGAMENTO->value)->value('id'))->delete();
        RolePermissao::query()
            ->where('modulo_id', ModuloRegistro::where('nome', Modulo::PROPINA->value)->value('id'))
            ->whereHas('acao', fn ($acao) => $acao->where('nome', 'anular'))
            ->delete();
        $this->assertTrue(Gate::forUser($admin)->denies('pagamento.ver')); // aquece a cache
        $this->assertTrue(Gate::forUser($admin)->denies('propina.anular'));

        $this->artisan('financeiro:sincronizar', ['--tenant' => $this->tenant->codigo])->assertSuccessful();

        $this->assertTrue(Gate::forUser($admin)->allows('pagamento.criar'));
        $this->assertTrue(Gate::forUser($admin)->allows('pagamento.anular'));
        $this->assertTrue(Gate::forUser($admin)->allows('propina.anular'));
    }
}
```
(`RolePermissao::acao()` existe — verificado.)

Em `PermissoesPropinaTest::test_admin_escola_tem_as_acoes_de_f1_e_ajustar`, substituir o corpo por:
```php
        $admin = $this->utilizadorCom(Perfil::ADMIN_ESCOLA);

        // P1 (F2): `ajustar`; Pagamentos-A: `anular` (D16).
        foreach (['ver', 'listar', 'criar', 'cancelar', 'anular', 'exportar', 'ajustar'] as $acao) {
            $this->assertTrue(Gate::forUser($admin)->allows("propina.{$acao}"), $acao);
        }

        foreach (['editar', 'eliminar', 'negociar', 'isentar-multa'] as $acao) {
            $this->assertFalse(Gate::forUser($admin)->allows("propina.{$acao}"), $acao);
        }
```
Em `ModuloEnumTest` acrescentar:
```php
    public function test_pagamento_slug_e_label(): void
    {
        $this->assertSame('pagamento', Modulo::PAGAMENTO->slug());
        $this->assertSame('Pagamento', Modulo::PAGAMENTO->label());
        $this->assertSame(Modulo::PAGAMENTO, Modulo::fromSlug('pagamento'));
        $this->assertSame(Modulo::PAGAMENTO, Modulo::tryFrom(23));
    }
```
Em `ModuloSeederTest` acrescentar:
```php
    public function test_seeder_inclui_o_modulo_pagamento(): void
    {
        $this->seed(ModuloSeeder::class);

        $this->assertDatabaseHas('modulos', ['nome' => 23, 'descricao' => 'Pagamento']);
    }
```
Em `ModuloSeederTest::test_seeder_e_idempotente` a contagem passa de 23 para 24: `$this->assertSame(24, Modulo::count());`.

Em `Modules/Permissao/tests/Feature/AcoesAplicaveisTest.php` (pré-voo: sem isto a suite Permissao falha):
- `test_os_modulos_sem_acoes_proprias_aplicam_as_acoes_base`: `if (in_array($modulo, [ModuloEnum::PROPINA, ModuloEnum::PAGAMENTO], true)) { continue; }` (PAGAMENTO tem acções próprias);
- `test_grelha_do_perfil_mostra_so_acoes_aplicaveis`: `->has('modulos', 24)` (era 23).

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/PermissoesPagamentoTest.php Modules/Financeiro/tests/Feature/PermissoesPropinaTest.php Modules/Permissao/tests/Unit/ModuloEnumTest.php Modules/Permissao/tests/Feature/ModuloSeederTest.php`
Expected: FAIL (`Undefined constant Modulo::PAGAMENTO`; `propina.anular` negado).

- [ ] **Step 3: Implementar**

`Modulo.php`: novo caso a seguir a `PROPINA`:
```php
    case PROPINA = 22;
    case PAGAMENTO = 23;
```
em `acoesAplicaveis()`:
```php
            self::PROPINA => ['ver', 'listar', 'criar', 'cancelar', 'anular', 'exportar', 'ajustar'],
            // Um pagamento nunca se edita nem se elimina: anula-se (spec Pagamentos §8). As acções de crédito
            // reutilizam `criar` (aplicar) e `anular` (anular utilização).
            self::PAGAMENTO => ['ver', 'listar', 'criar', 'anular', 'exportar'],
```
em `slug()`: `self::PAGAMENTO => 'pagamento',`; em `label()`: `self::PAGAMENTO => 'Pagamento',`.

`ModuloSeeder.php`, a seguir a `['nome' => 22, 'descricao' => 'Propina'],`:
```php
            ['nome' => 23, 'descricao' => 'Pagamento'],
```

`SincronizarPerfisDeSistemaAction.php`, em `ADMIN_ESCOLA`:
```php
            // Propinas: `ajustar` entrou em F2 (via P1) e é também o que autoriza a geração retroactiva; `anular` em Pagamentos-A.
            Modulo::PROPINA->value => ['ver', 'listar', 'criar', 'cancelar', 'anular', 'exportar', 'ajustar'],
            Modulo::PAGAMENTO->value => ['ver', 'listar', 'criar', 'anular', 'exportar'],
```

- [ ] **Step 4: Correr e ver passar**

Run: o comando do Step 2, e `php artisan test Modules/Permissao tests/Feature/RotasPermissaoReconhecidaTest.php`
Expected: PASS.

- [ ] **Step 5: Stage**

```bash
git add Modules/Permissao/app/Enums/Modulo.php Modules/Permissao/database/seeders/ModuloSeeder.php Modules/Permissao/app/Actions/SincronizarPerfisDeSistemaAction.php Modules/Permissao/tests/Unit/ModuloEnumTest.php Modules/Permissao/tests/Feature/ModuloSeederTest.php Modules/Permissao/tests/Feature/AcoesAplicaveisTest.php Modules/Financeiro/tests/Feature/PermissoesPropinaTest.php Modules/Financeiro/tests/Feature/PermissoesPagamentoTest.php
```

---

### Task 2: `pagamentos` + numeração por tenant e ano + model `Pagamento` imutável (D2–D7, D17)

**Files:**
- Modify: `Modules/Core/app/Services/GeradorSequencia.php`
- Modify: `Modules/Core/tests/Feature/GeradorSequenciaTest.php`
- Create: `Modules/Financeiro/database/migrations/2026_10_17_100000_create_pagamento_sequencias_table.php`
- Create: `Modules/Financeiro/database/migrations/2026_10_17_100100_create_pagamentos_table.php`
- Create: `Modules/Financeiro/app/Enums/EstadoPagamento.php`
- Create: `Modules/Financeiro/app/Models/PagamentoSequencia.php`
- Create: `Modules/Financeiro/app/Models/Pagamento.php`
- Create: `Modules/Financeiro/tests/Concerns/ComPagamentosFinanceiro.php`
- Create: `Modules/Financeiro/tests/Feature/PagamentoModelTest.php`
- Create: `Modules/Financeiro/tests/Unit/EstadosPagamentoTest.php`

**Interfaces:**
- Consumes: `GeradorSequencia`, `SequenciaPorTenant`, `MetodoPagamento`, `Matricula` (`aluno_id`), `DinheiroCast`, `DataCast`, `Moeda::existe()`.
- Produces:
  - `GeradorSequencia::gerar(string $modeloSequencia, ?int $ano = null): string` (sem `$ano` = comportamento actual).
  - `Enums\EstadoPagamento` (CONFIRMADO=1, ANULADO=2; `label()`, `opcoes()`).
  - `Models\PagamentoSequencia implements SequenciaPorTenant`.
  - `Models\Pagamento`: `IMUTAVEIS`, relações `matricula()`, `aluno()`, `metodo()`, `criadoPor()`, `anuladoPor()` (T3 acrescenta `distribuicoes()` e `credito()`); `estaConfirmado(): bool`.
  - Trait de testes `ComPagamentosFinanceiro`: `metodoPagamento(string $nome = 'Numerário', bool $activo = true): MetodoPagamento`, `pagamentoDirecto(Matricula $matricula, int $valor = 2_500_000, array $atributos = []): Pagamento` (cria sem passar pela Action; só para preparar cenários de model).

- [ ] **Step 1: Escrever os testes**

Em `GeradorSequenciaTest` acrescentar:
```php
    public function test_o_ano_pode_ser_indicado_e_cada_ano_tem_a_sua_sequencia(): void
    {
        $this->assertSame('2031-0001', $this->gerador()->gerar(MatriculaSequencia::class, 2031));
        $this->assertSame('2031-0002', $this->gerador()->gerar(MatriculaSequencia::class, 2031));
        $this->assertSame('2030-0001', $this->gerador()->gerar(MatriculaSequencia::class, 2030));
        $this->assertSame(now()->year . '-0001', $this->gerarEmA(), 'Sem ano, usa o ano corrente (comportamento anterior).');
    }
```

`Modules/Financeiro/tests/Unit/EstadosPagamentoTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Unit;

use Modules\Financeiro\Enums\EstadoPagamento;
use PHPUnit\Framework\TestCase;

class EstadosPagamentoTest extends TestCase
{
    public function test_estado_do_pagamento(): void
    {
        $this->assertSame([1, 2], array_map(fn (EstadoPagamento $e) => $e->value, EstadoPagamento::cases()));
        $this->assertSame('Confirmado', EstadoPagamento::CONFIRMADO->label());
        $this->assertSame('Anulado', EstadoPagamento::ANULADO->label());
        $this->assertSame([['value' => 1, 'label' => 'Confirmado'], ['value' => 2, 'label' => 'Anulado']], EstadoPagamento::opcoes());
    }
}
```

`Modules/Financeiro/tests/Concerns/ComPagamentosFinanceiro.php` (versão inicial; as Tasks 7–9 acrescentam métodos):
```php
<?php

namespace Modules\Financeiro\Tests\Concerns;

use Modules\Core\Enums\Estado;
use Modules\Financeiro\Enums\TipoMetodoPagamento;
use Modules\Financeiro\Models\MetodoPagamento;
use Modules\Financeiro\Models\Pagamento;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Matricula\Models\Matricula;

/**
 * Métodos de pagamento e pagamentos para testes. Requer ComDadosAcademicosFinanceiro e ComPropinasFinanceiro.
 * `pagamentoDirecto` grava SEM a RegistarPagamentoAction (sem distribuição, crédito nem recálculo): só para
 * testar o model e as fontes. Os cenários de negócio usam `pagar()` (Task 7).
 */
trait ComPagamentosFinanceiro
{
    protected function metodoPagamento(string $nome = 'Numerário', bool $activo = true): MetodoPagamento
    {
        return MetodoPagamento::query()->firstOrCreate(
            ['nome' => $nome],
            ['tipo' => TipoMetodoPagamento::NUMERARIO, 'estado' => $activo ? Estado::ATIVO->value : Estado::INATIVO->value],
        );
    }

    /**
     * @param  array<string, mixed>  $atributos  sobrepõe qualquer coluna (numero, data_pagamento, referencia…)
     */
    protected function pagamentoDirecto(Matricula $matricula, int $valor = 2_500_000, array $atributos = []): Pagamento
    {
        $pagamento = (new Pagamento())->forceFill(array_merge([
            'matricula_id' => $matricula->id,
            'aluno_id' => $matricula->aluno_id,
            'metodo_pagamento_id' => $this->metodoPagamento()->id,
            'numero' => sprintf('2026-%04d', Pagamento::query()->count() + 1),
            'valor' => $valor,
            'moeda' => app(MoedaDoTenant::class)->atual()->codigo,
            'cambio_usd' => null,
            'data_pagamento' => '2026-10-16',
        ], $atributos));
        $pagamento->save();

        return $pagamento->refresh();
    }
}
```

`Modules/Financeiro/tests/Feature/PagamentoModelTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use Modules\Financeiro\Enums\EstadoPagamento;
use Modules\Financeiro\Models\Pagamento;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Tests\TestCase;

class PagamentoModelTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPagamentosFinanceiro;
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

    public function test_nasce_confirmado_com_descricao_e_snapshot(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();

        $pagamento = $this->pagamentoDirecto($matricula, 2_500_000, ['referencia' => 'TRF-1']);

        $this->assertSame(EstadoPagamento::CONFIRMADO, $pagamento->estado);
        $this->assertSame('Confirmado', $pagamento->estado_descricao);
        $this->assertSame(2_500_000, $pagamento->valor->unidadesMenores());
        $this->assertSame('2026-10-16', $pagamento->data_pagamento->toDateString());
        $this->assertSame((int) $matricula->aluno_id, $pagamento->aluno_id);
        $this->assertTrue($pagamento->estaConfirmado());
    }

    public function test_campos_financeiros_nunca_mudam(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();
        $pagamento = $this->pagamentoDirecto($matricula);

        foreach ([
            'valor' => 1, 'data_pagamento' => '2026-10-01', 'metodo_pagamento_id' => $this->metodoPagamento('TPA')->id,
            'referencia' => 'X', 'observacao' => 'Y', 'numero' => '2026-9999', 'moeda' => 'USD',
        ] as $campo => $novo) {
            try {
                $pagamento->fresh()->forceFill([$campo => $novo])->save();
                $this->fail("{$campo} devia ser imutável.");
            } catch (LogicException $e) {
                $this->assertStringContainsString('nunca se edita', $e->getMessage(), $campo);
            }
        }
    }

    public function test_so_se_anula_uma_vez_e_com_motivo_e_instante(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();
        $pagamento = $this->pagamentoDirecto($matricula);

        try {
            $pagamento->forceFill(['estado' => EstadoPagamento::ANULADO])->save();
            $this->fail('Anular sem motivo nem instante devia lançar.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $pagamento = $pagamento->fresh();
        $pagamento->forceFill(['estado' => EstadoPagamento::ANULADO, 'motivo_anulacao' => 'Valor errado', 'anulado_em' => now()])->save();
        $this->assertSame('Anulado', $pagamento->fresh()->estado_descricao);

        $this->expectException(LogicException::class);
        $pagamento->fresh()->forceFill(['estado' => EstadoPagamento::CONFIRMADO, 'motivo_anulacao' => null, 'anulado_em' => null])->save();
    }

    public function test_nao_se_elimina(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();
        $pagamento = $this->pagamentoDirecto($matricula);

        $this->expectException(LogicException::class);
        $pagamento->delete();
    }

    public function test_valor_tem_de_ser_positivo_e_o_aluno_o_da_matricula(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();

        try {
            $this->pagamentoDirecto($matricula, 0);
            $this->fail('Valor 0 devia lançar.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $outro = $this->alunoFinanceiro('Outro Aluno');
        $this->expectException(LogicException::class);
        $this->pagamentoDirecto($matricula, 100, ['aluno_id' => $outro->id]);
    }

    public function test_numero_unico_por_tenant(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();
        $this->pagamentoDirecto($matricula, 100, ['numero' => '2026-0001']);

        $this->expectException(QueryException::class);
        $this->pagamentoDirecto($matricula, 100, ['numero' => '2026-0001']);
    }

    public function test_referencia_unica_entre_confirmados_do_mesmo_metodo_e_livre_depois_de_anular(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();
        $primeiro = $this->pagamentoDirecto($matricula, 100, ['referencia' => 'TRF-77']);
        $this->pagamentoDirecto($matricula, 100, ['referencia' => 'TRF-77', 'metodo_pagamento_id' => $this->metodoPagamento('TPA')->id]);

        try {
            $this->pagamentoDirecto($matricula, 100, ['referencia' => 'TRF-77']);
            $this->fail('Mesma referência no mesmo método devia falhar.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $primeiro->forceFill(['estado' => EstadoPagamento::ANULADO, 'motivo_anulacao' => 'Valor errado', 'anulado_em' => now()])->save();
        $this->pagamentoDirecto($matricula, 100, ['referencia' => 'TRF-77']);
        $this->assertSame(3, Pagamento::query()->count());
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/PagamentoModelTest.php Modules/Financeiro/tests/Unit/EstadosPagamentoTest.php Modules/Core/tests/Feature/GeradorSequenciaTest.php`
Expected: FAIL (classes/tabelas inexistentes; `gerar()` com 2 argumentos ignora o ano).

- [ ] **Step 3: Implementar**

`GeradorSequencia::gerar` — assinatura e primeira linha da closure:
```php
    /**
     * @param class-string<SequenciaPorTenant&\Illuminate\Database\Eloquent\Model> $modeloSequencia
     * @param int|null $ano ano da sequência; null = ano corrente da aplicação (Pagamentos passam o ano do dia da escola)
     */
    public function gerar(string $modeloSequencia, ?int $ano = null): string
    {
        // … (validação e $tenantId/$tabela inalterados)

        return DB::transaction(function () use ($modeloSequencia, $tabela, $tenantId, $ano) {
            $ano ??= now()->year;
            // … (resto inalterado)
```
(O docblock `@param class-string<…>` já existe com FQN em comentário; não é código.)

`Modules/Financeiro/database/migrations/2026_10_17_100000_create_pagamento_sequencias_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Numeração dos pagamentos (AAAA-NNNN) por tenant e ano, gerida pelo GeradorSequencia (Core).
        Schema::create('pagamento_sequencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedSmallInteger('ano');
            $table->unsignedInteger('ultimo_numero')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'ano']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagamento_sequencias');
    }
};
```

`Modules/Financeiro/database/migrations/2026_10_17_100100_create_pagamentos_table.php`:
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
        // Dinheiro recebido de uma matrícula (spec Pagamentos §3). Só Confirmado/Anulado; nunca se edita nem se apaga.
        Schema::create('pagamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('matricula_id')->constrained('matriculas')->restrictOnDelete();
            $table->foreignId('aluno_id')->constrained('alunos')->restrictOnDelete();
            $table->foreignId('metodo_pagamento_id')->constrained('metodos_pagamento')->restrictOnDelete();
            $table->string('numero', 20);
            $table->unsignedBigInteger('valor');
            $table->char('moeda', 3);
            $table->bigInteger('cambio_usd')->nullable();
            $table->date('data_pagamento');
            $table->string('referencia', 100)->nullable();
            $table->text('observacao')->nullable();
            $table->unsignedTinyInteger('estado')->default(1);
            $table->string('estado_descricao');
            $table->text('motivo_anulacao')->nullable();
            $table->foreignId('anulado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('anulado_em')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'numero'], 'pagamentos_numero_unique');
            $table->index(['tenant_id', 'matricula_id', 'data_pagamento'], 'pagamentos_matricula_data_index');
            $table->index(['tenant_id', 'aluno_id'], 'pagamentos_aluno_index');
            $table->index(['tenant_id', 'estado', 'data_pagamento'], 'pagamentos_estado_data_index');
            $table->index(['tenant_id', 'metodo_pagamento_id'], 'pagamentos_metodo_index');
        });

        // D5: a mesma referência no mesmo método só entre Confirmados (um anulado liberta-a para o pagamento correcto).
        // Sintaxe comum a SQLite e PostgreSQL (precedente: propinas_periodo_activo_unique).
        DB::statement('CREATE UNIQUE INDEX pagamentos_referencia_confirmada_unique ON pagamentos (tenant_id, metodo_pagamento_id, referencia) WHERE referencia IS NOT NULL AND estado = 1');

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'pagamentos_valor_check' => 'valor > 0',
            'pagamentos_estado_check' => 'estado IN (1, 2)',
            'pagamentos_anulacao_check' => '(estado = 2) = (anulado_em IS NOT NULL)',
            'pagamentos_motivo_anulacao_check' => "estado <> 2 OR btrim(coalesce(motivo_anulacao, '')) <> ''",
            'pagamentos_anulado_por_check' => 'estado = 2 OR anulado_por IS NULL',
            'pagamentos_numero_check' => "btrim(numero) <> ''",
            'pagamentos_referencia_check' => "referencia IS NULL OR btrim(referencia) <> ''",
        ] as $nome => $expressao) {
            DB::statement("ALTER TABLE pagamentos ADD CONSTRAINT {$nome} CHECK ({$expressao})");
        }

        // D17: a única alteração de negócio é anular (1 → 2, uma vez, com os campos de anulação). Tolera o
        // ON DELETE SET NULL das colunas de autoria e gravações sem alterações de negócio (editado_por, updated_at).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION pagamentos_imutavel() RETURNS trigger AS $$
            DECLARE
                autoria text[] := ARRAY['criado_por', 'editado_por', 'anulado_por', 'updated_at'];
                anulacao text[] := ARRAY['estado', 'estado_descricao', 'motivo_anulacao', 'anulado_por', 'anulado_em', 'editado_por', 'updated_at'];
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'pagamentos: um pagamento nunca se elimina (anule-o)' USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                IF (to_jsonb(NEW) - autoria) = (to_jsonb(OLD) - autoria)
                    AND (NEW.criado_por IS NULL OR NEW.criado_por IS NOT DISTINCT FROM OLD.criado_por)
                    AND (NEW.anulado_por IS NULL OR NEW.anulado_por IS NOT DISTINCT FROM OLD.anulado_por) THEN
                    RETURN NEW;
                END IF;
                IF OLD.estado = 1 AND NEW.estado = 2 AND (to_jsonb(NEW) - anulacao) = (to_jsonb(OLD) - anulacao) THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'pagamentos: um pagamento registado nunca se edita (anule-o e registe outro)' USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER pagamentos_imutavel BEFORE UPDATE OR DELETE ON pagamentos FOR EACH ROW EXECUTE FUNCTION pagamentos_imutavel()');
    }

    public function down(): void
    {
        Schema::dropIfExists('pagamentos');

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS pagamentos_imutavel()');
        }
    }
};
```

`Modules/Financeiro/app/Enums/EstadoPagamento.php`:
```php
<?php

namespace Modules\Financeiro\Enums;

/**
 * Estados de um pagamento (spec Pagamentos §2.2): só Confirmado e Anulado. Regista-se já Confirmado;
 * Anulado é terminal.
 */
enum EstadoPagamento: int
{
    case CONFIRMADO = 1;
    case ANULADO = 2;

    public function label(): string
    {
        return match ($this) {
            self::CONFIRMADO => 'Confirmado',
            self::ANULADO => 'Anulado',
        };
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public static function opcoes(): array
    {
        return array_map(fn (self $estado) => ['value' => $estado->value, 'label' => $estado->label()], self::cases());
    }
}
```

`Modules/Financeiro/app/Models/PagamentoSequencia.php`:
```php
<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Tenancy\SequenciaPorTenant;

class PagamentoSequencia extends Model implements SequenciaPorTenant
{
    use PertenceAoTenant;

    protected $table = 'pagamento_sequencias';

    protected $fillable = ['ano', 'ultimo_numero'];
}
```

`Modules/Financeiro/app/Models/Pagamento.php`:
```php
<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Aluno\Models\Aluno;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Core\Traits\RegistaAutoria;
use Modules\Financeiro\Casts\DataCast;
use Modules\Financeiro\Casts\DinheiroCast;
use Modules\Financeiro\Enums\EstadoPagamento;
use Modules\Financeiro\Support\Moeda;
use Modules\Matricula\Models\Matricula;
use Modules\Usuario\Models\User;

/**
 * Dinheiro efectivamente recebido de uma matrícula (spec Pagamentos §3). Nasce Confirmado pela
 * RegistarPagamentoAction; a única alteração permitida é anular, uma vez, com motivo (AnularPagamentoAction).
 * Corrigir um pagamento = anular + registar outro. Nunca se elimina. Em PostgreSQL um trigger repete estas regras.
 */
class Pagamento extends Model
{
    use PertenceAoTenant;
    use RegistaAutoria;

    /** Dados financeiros e de identificação: nunca mudam depois de registados. */
    public const IMUTAVEIS = [
        'matricula_id', 'aluno_id', 'metodo_pagamento_id', 'numero', 'valor', 'moeda', 'cambio_usd',
        'data_pagamento', 'referencia', 'observacao',
    ];

    public const CAMPOS_DE_ANULACAO = ['motivo_anulacao', 'anulado_por', 'anulado_em'];

    protected $table = 'pagamentos';

    protected $fillable = [
        'matricula_id',
        'metodo_pagamento_id',
        'valor',
        'data_pagamento',
        'referencia',
        'observacao',
    ];

    protected $hidden = ['tenant_id', 'criado_por', 'editado_por'];

    protected $attributes = [
        'estado' => 1,
    ];

    protected $casts = [
        'matricula_id' => 'integer',
        'aluno_id' => 'integer',
        'metodo_pagamento_id' => 'integer',
        'valor' => DinheiroCast::class,
        'cambio_usd' => 'integer',
        'data_pagamento' => DataCast::class,
        'estado' => EstadoPagamento::class,
        'anulado_por' => 'integer',
        'anulado_em' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Null-safe: a matriz de isolamento grava models vazios sem contexto e espera TenantNaoResolvido.
        static::saving(function (self $pagamento) {
            $pagamento->estado_descricao = $pagamento->estado?->label();
        });

        // Corre depois da verificação de tenant da trait.
        static::creating(function (self $pagamento) {
            $pagamento->validarCriacao();
        });

        static::updating(function (self $pagamento) {
            $alterados = array_values(array_filter(self::IMUTAVEIS, fn (string $campo) => $pagamento->isDirty($campo)));

            if ($alterados !== []) {
                throw new LogicException('Um pagamento registado nunca se edita (' . implode(', ', $alterados) . '): anule-o e registe outro.');
            }

            $original = $pagamento->getOriginal('estado');

            if ($pagamento->isDirty('estado') && ! ($original === EstadoPagamento::CONFIRMADO && $pagamento->estado === EstadoPagamento::ANULADO)) {
                throw new LogicException('Um pagamento só passa de Confirmado a Anulado, e uma única vez.');
            }

            if (! $pagamento->isDirty('estado') && $pagamento->isDirty(self::CAMPOS_DE_ANULACAO)) {
                throw new LogicException('Os dados da anulação só se gravam no acto de anular.');
            }

            $pagamento->validarCoerencia();
        });

        static::deleting(function () {
            throw new LogicException('Um pagamento nunca é eliminado: anule-o.');
        });
    }

    public function matricula(): BelongsTo
    {
        return $this->belongsTo(Matricula::class, 'matricula_id')->withTrashed();
    }

    public function aluno(): BelongsTo
    {
        return $this->belongsTo(Aluno::class, 'aluno_id');
    }

    public function metodo(): BelongsTo
    {
        return $this->belongsTo(MetodoPagamento::class, 'metodo_pagamento_id');
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por');
    }

    public function estaConfirmado(): bool
    {
        return $this->estado === EstadoPagamento::CONFIRMADO;
    }

    private function validarCriacao(): void
    {
        if ($this->estado !== EstadoPagamento::CONFIRMADO) {
            throw new LogicException('Um pagamento regista-se sempre Confirmado.');
        }

        if (trim((string) $this->numero) === '') {
            throw new LogicException('O número do pagamento é obrigatório.');
        }

        if ($this->data_pagamento === null) {
            throw new LogicException('A data do pagamento é obrigatória.');
        }

        if ($this->moeda === null || ! Moeda::existe($this->moeda)) {
            throw new LogicException('Moeda desconhecida no pagamento.');
        }

        if ($this->cambio_usd !== null && $this->cambio_usd <= 0) {
            throw new LogicException('O câmbio do pagamento tem de ser maior que zero (ou nulo).');
        }

        if ($this->referencia !== null && trim($this->referencia) === '') {
            throw new LogicException('Uma referência vazia grava-se como nula.');
        }

        // O scope do tenant esconde a matrícula e o método de outra escola: ambos têm de existir aqui.
        $matricula = Matricula::query()->withTrashed()->find($this->matricula_id);

        if ($matricula === null || ! MetodoPagamento::query()->whereKey($this->metodo_pagamento_id)->exists()) {
            throw new LogicException('A matrícula e o método de pagamento têm de existir no tenant do pagamento.');
        }

        if ((int) $matricula->aluno_id !== (int) $this->aluno_id) {
            throw new LogicException('O aluno do pagamento tem de ser o aluno da matrícula.');
        }

        $this->validarCoerencia();
    }

    /** As mesmas regras dos CHECK de PostgreSQL (migration), para valerem também em SQLite. */
    private function validarCoerencia(): void
    {
        if (($this->valor?->unidadesMenores() ?? 0) <= 0) {
            throw new LogicException('O valor do pagamento tem de ser maior que zero.');
        }

        $anulado = $this->estado === EstadoPagamento::ANULADO;

        if ($anulado !== ($this->anulado_em !== null)) {
            throw new LogicException('Um pagamento anulado exige o instante da anulação, e só ele o tem.');
        }

        if ($anulado && trim((string) $this->motivo_anulacao) === '') {
            throw new LogicException('Um pagamento anulado exige o motivo da anulação.');
        }

        if (! $anulado && $this->anulado_por !== null) {
            throw new LogicException('Só um pagamento anulado tem o autor da anulação.');
        }
    }
}
```

- [ ] **Step 4: Correr e ver passar**

Run: o comando do Step 2, e `php artisan test tests/Feature/Tenancy tests/Feature/Arquitectura/TenancyEsquemaTest.php tests/Feature/Arquitectura/TenancyArquitecturaTest.php`
Expected: PASS (`TenancyEsquemaTest` passa a ver `pagamentos` e `pagamento_sequencias` com `tenant_id` NOT NULL e FK; `MatrizIsolamentoTest` descobre `Pagamento`/`PagamentoSequencia` e o `saving` null-safe deixa a trait lançar `TenantNaoResolvido`).

- [ ] **Step 5: Stage**

```bash
git add Modules/Core/app/Services/GeradorSequencia.php Modules/Core/tests/Feature/GeradorSequenciaTest.php Modules/Financeiro/database/migrations/2026_10_17_100000_create_pagamento_sequencias_table.php Modules/Financeiro/database/migrations/2026_10_17_100100_create_pagamentos_table.php Modules/Financeiro/app/Enums/EstadoPagamento.php Modules/Financeiro/app/Models/PagamentoSequencia.php Modules/Financeiro/app/Models/Pagamento.php Modules/Financeiro/tests/Concerns/ComPagamentosFinanceiro.php Modules/Financeiro/tests/Feature/PagamentoModelTest.php Modules/Financeiro/tests/Unit/EstadosPagamentoTest.php
```

---

### Task 3: Distribuições, créditos e utilizações — tabelas e models (D2, D10, D11, D17)

**Files:**
- Create: `Modules/Financeiro/database/migrations/2026_10_17_100200_create_pagamento_propinas_table.php`
- Create: `Modules/Financeiro/database/migrations/2026_10_17_100300_create_creditos_table.php`
- Create: `Modules/Financeiro/database/migrations/2026_10_17_100400_create_credito_utilizacoes_table.php`
- Create: `Modules/Financeiro/app/Enums/EstadoCredito.php`, `Modules/Financeiro/app/Enums/EstadoUtilizacaoCredito.php`
- Create: `Modules/Financeiro/app/Models/PagamentoPropina.php`, `Credito.php`, `CreditoUtilizacao.php`
- Modify: `Modules/Financeiro/app/Models/Pagamento.php` (+ `distribuicoes()`, `credito()`, `utilizacoesCombinadas()`)
- Modify: `Modules/Financeiro/app/Models/Propina.php` (+ `distribuicoes()`, `utilizacoesDeCredito()`)
- Modify: `Modules/Financeiro/tests/Concerns/ComPagamentosFinanceiro.php` (+ `distribuicaoDirecta`, `creditoDirecto`, `utilizacaoDirecta`)
- Modify: `Modules/Financeiro/tests/Unit/EstadosPagamentoTest.php`
- Create: `Modules/Financeiro/tests/Feature/CreditoModelTest.php`

**Interfaces:**
- Consumes: `Pagamento` (T2), `Propina`, `Aluno`.
- Produces:
  - `Enums\EstadoCredito` (DISPONIVEL=1 "Disponível", ESGOTADO=2 "Esgotado", ANULADO=3 "Anulado"); `Enums\EstadoUtilizacaoCredito` (ACTIVA=1 "Activa", ANULADA=2 "Anulada"); ambos com `label()` e `opcoes()`.
  - `Models\PagamentoPropina` (só criação; `pagamento()`, `propina()`).
  - `Models\Credito` (`IMUTAVEIS = ['aluno_id','origem_pagamento_id','valor']`; `saldo(): Dinheiro`; `utilizacoes()`, `origem()`, `aluno()`); `valor_utilizado`/`estado` não preenchíveis (só `RecalcularCredito`, T4).
  - `Models\CreditoUtilizacao` (`credito()`, `propina()`, `pagamento()`, `criadoPor()`, `anuladoPor()`; só ACTIVA → ANULADA; `pagamento_id` **nullable e imutável** (O8): quando preenchido, o pagamento é do mesmo aluno do crédito e da mesma matrícula da propina).
  - `Pagamento::distribuicoes(): HasMany`, `Pagamento::credito(): HasOne`, `Pagamento::utilizacoesCombinadas(): HasMany`; `Propina::distribuicoes(): HasMany`, `Propina::utilizacoesDeCredito(): HasMany`.
  - Helpers de teste: `distribuicaoDirecta(Pagamento, Propina, int $valor): PagamentoPropina`, `creditoDirecto(Pagamento $origem, int $valor): Credito`, `utilizacaoDirecta(Credito, Propina, int $valor, string $data = '2026-10-16', ?Pagamento $combinadoCom = null): CreditoUtilizacao` (`$combinadoCom` preenche `pagamento_id`, O8).

- [ ] **Step 1: Escrever os testes**

Em `EstadosPagamentoTest` acrescentar:
```php
    public function test_estados_do_credito_e_da_utilizacao(): void
    {
        $this->assertSame(['Disponível', 'Esgotado', 'Anulado'], array_map(fn (EstadoCredito $e) => $e->label(), EstadoCredito::cases()));
        $this->assertSame([1, 2, 3], array_map(fn (EstadoCredito $e) => $e->value, EstadoCredito::cases()));
        $this->assertSame([['value' => 1, 'label' => 'Activa'], ['value' => 2, 'label' => 'Anulada']], EstadoUtilizacaoCredito::opcoes());
    }
```
(com `use Modules\Financeiro\Enums\EstadoCredito;` e `use Modules\Financeiro\Enums\EstadoUtilizacaoCredito;`).

Em `ComPagamentosFinanceiro` acrescentar (com os `use` de `Credito`, `CreditoUtilizacao`, `PagamentoPropina`, `Propina`):
```php
    protected function distribuicaoDirecta(Pagamento $pagamento, Propina $propina, int $valor): PagamentoPropina
    {
        return PagamentoPropina::create(['pagamento_id' => $pagamento->id, 'propina_id' => $propina->id, 'valor' => $valor]);
    }

    protected function creditoDirecto(Pagamento $origem, int $valor): Credito
    {
        return Credito::create(['aluno_id' => $origem->aluno_id, 'origem_pagamento_id' => $origem->id, 'valor' => $valor])->refresh();
    }

    protected function utilizacaoDirecta(Credito $credito, Propina $propina, int $valor, string $data = '2026-10-16', ?Pagamento $combinadoCom = null): CreditoUtilizacao
    {
        return CreditoUtilizacao::create([
            'credito_id' => $credito->id, 'propina_id' => $propina->id, 'valor' => $valor, 'data_utilizacao' => $data,
            'pagamento_id' => $combinadoCom?->id,
        ])->refresh();
    }
```

`Modules/Financeiro/tests/Feature/CreditoModelTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use Modules\Financeiro\Enums\EstadoCredito;
use Modules\Financeiro\Enums\EstadoUtilizacaoCredito;
use Modules\Financeiro\Models\Credito;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Tests\TestCase;

class CreditoModelTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPagamentosFinanceiro;
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

    public function test_distribuicao_so_se_cria_e_tem_de_ser_da_mesma_matricula(): void
    {
        ['plano' => $plano, 'matricula' => $matricula, 'turma' => $turma] = $this->cenarioPropinas();
        $pagamento = $this->pagamentoDirecto($matricula);
        $distribuicao = $this->distribuicaoDirecta($pagamento, $this->propina($matricula, $plano, 1), 1_000_000);

        foreach ([fn () => $distribuicao->forceFill(['valor' => 1])->save(), fn () => $distribuicao->delete()] as $operacao) {
            try {
                $operacao();
                $this->fail('A distribuição devia ser imutável.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $outra = $this->matriculaActiva($turma);
        $this->expectException(LogicException::class);
        $this->distribuicaoDirecta($pagamento, $this->propina($outra, $plano, 1), 100);
    }

    public function test_credito_nasce_disponivel_e_os_campos_de_origem_nao_mudam(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();
        $credito = $this->creditoDirecto($this->pagamentoDirecto($matricula), 500_000);

        $this->assertSame(EstadoCredito::DISPONIVEL, $credito->estado);
        $this->assertSame('Disponível', $credito->estado_descricao);
        $this->assertSame(500_000, $credito->saldo()->unidadesMenores());

        foreach (['valor' => 1, 'aluno_id' => $this->alunoFinanceiro('Outro')->id] as $campo => $novo) {
            try {
                $credito->fresh()->forceFill([$campo => $novo])->save();
                $this->fail("{$campo} devia ser imutável.");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(LogicException::class);
        $credito->delete();
    }

    public function test_credito_e_do_aluno_do_pagamento_de_origem_e_um_por_pagamento(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();
        $pagamento = $this->pagamentoDirecto($matricula);

        try {
            Credito::create(['aluno_id' => $this->alunoFinanceiro('Outro')->id, 'origem_pagamento_id' => $pagamento->id, 'valor' => 100]);
            $this->fail('O crédito tem de ser do aluno do pagamento.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $this->creditoDirecto($pagamento, 100);
        $this->expectException(QueryException::class);
        $this->creditoDirecto($pagamento, 200);
    }

    public function test_coerencia_do_valor_utilizado_com_o_estado(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();
        $credito = $this->creditoDirecto($this->pagamentoDirecto($matricula), 500_000);

        foreach ([
            ['valor_utilizado' => 600_000, 'estado' => EstadoCredito::DISPONIVEL],
            ['valor_utilizado' => 500_000, 'estado' => EstadoCredito::DISPONIVEL],
            ['valor_utilizado' => 100_000, 'estado' => EstadoCredito::ESGOTADO],
            ['valor_utilizado' => 100_000, 'estado' => EstadoCredito::ANULADO],
        ] as $incoerente) {
            try {
                $credito->fresh()->forceFill($incoerente)->save();
                $this->fail(json_encode($incoerente));
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }

        $credito->fresh()->forceFill(['valor_utilizado' => 0, 'estado' => EstadoCredito::ANULADO])->save();
        $this->expectException(LogicException::class);
        $credito->fresh()->forceFill(['estado' => EstadoCredito::DISPONIVEL])->save();
    }

    public function test_utilizacao_combinada_guarda_o_pagamento_e_o_vinculo_nao_muda(): void
    {
        ['plano' => $plano, 'matricula' => $matricula, 'turma' => $turma] = $this->cenarioPropinas();
        $credito = $this->creditoDirecto($this->pagamentoDirecto($matricula), 500_000);
        $combinado = $this->pagamentoDirecto($matricula, 1_000_000);
        $propina = $this->propina($matricula, $plano, 1);

        $isolada = $this->utilizacaoDirecta($credito, $propina, 100_000);
        $combinada = $this->utilizacaoDirecta($credito, $propina, 100_000, combinadoCom: $combinado);

        $this->assertNull($isolada->pagamento_id, 'Aplicação isolada: sem pagamento.');
        $this->assertSame((int) $combinado->id, (int) $combinada->pagamento_id);
        $this->assertSame([$combinada->id], $combinado->utilizacoesCombinadas()->pluck('id')->all());

        try {
            $combinada->fresh()->forceFill(['pagamento_id' => null])->save();
            $this->fail('O pagamento da utilização é imutável.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $outra = $this->matriculaActiva($turma); // outro aluno: o pagamento não é do aluno do crédito
        $this->expectException(LogicException::class);
        $this->utilizacaoDirecta($credito, $propina, 100, combinadoCom: $this->pagamentoDirecto($outra));
    }

    public function test_utilizacao_so_em_propina_do_mesmo_aluno_e_so_se_anula(): void
    {
        ['plano' => $plano, 'matricula' => $matricula, 'turma' => $turma] = $this->cenarioPropinas();
        $credito = $this->creditoDirecto($this->pagamentoDirecto($matricula), 500_000);
        $utilizacao = $this->utilizacaoDirecta($credito, $this->propina($matricula, $plano, 1), 100_000);

        $this->assertSame(EstadoUtilizacaoCredito::ACTIVA, $utilizacao->estado);

        try {
            $utilizacao->forceFill(['valor' => 1])->save();
            $this->fail('O valor da utilização é imutável.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $utilizacao->fresh()->forceFill(['estado' => EstadoUtilizacaoCredito::ANULADA, 'motivo_anulacao' => 'Engano', 'anulado_em' => now()])->save();
        $this->assertSame('Anulada', $utilizacao->fresh()->estado_descricao);

        $irmao = $this->matriculaActiva($turma); // outro aluno
        $this->expectException(LogicException::class);
        $this->utilizacaoDirecta($credito, $this->propina($irmao, $plano, 1), 100);
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/CreditoModelTest.php Modules/Financeiro/tests/Unit/EstadosPagamentoTest.php`
Expected: FAIL (classes inexistentes).

- [ ] **Step 3: Implementar**

`2026_10_17_100200_create_pagamento_propinas_table.php`:
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
        // Distribuição do dinheiro de um pagamento pelas propinas (spec Pagamentos §3). Só se cria; conta como pago
        // apenas enquanto o pagamento estiver Confirmado.
        Schema::create('pagamento_propinas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('pagamento_id')->constrained('pagamentos')->restrictOnDelete();
            $table->foreignId('propina_id')->constrained('propinas')->restrictOnDelete();
            $table->unsignedBigInteger('valor');
            $table->timestamp('created_at')->nullable();

            $table->unique(['pagamento_id', 'propina_id'], 'pagamento_propinas_pagamento_propina_unique');
            $table->index(['tenant_id', 'propina_id'], 'pagamento_propinas_propina_index');
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE pagamento_propinas ADD CONSTRAINT pagamento_propinas_valor_check CHECK (valor > 0)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION pagamento_propinas_imutavel() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'pagamento_propinas é imutável: anula-se o pagamento' USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER pagamento_propinas_imutavel BEFORE UPDATE OR DELETE ON pagamento_propinas FOR EACH ROW EXECUTE FUNCTION pagamento_propinas_imutavel()');
    }

    public function down(): void
    {
        Schema::dropIfExists('pagamento_propinas');

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS pagamento_propinas_imutavel()');
        }
    }
};
```

`2026_10_17_100300_create_creditos_table.php`:
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
        // Excedente de um pagamento, do ALUNO (spec Pagamentos §2.3): usável em qualquer matrícula dele.
        // valor_utilizado e estado são caches do RecalcularCredito.
        Schema::create('creditos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('aluno_id')->constrained('alunos')->restrictOnDelete();
            $table->foreignId('origem_pagamento_id')->unique()->constrained('pagamentos')->restrictOnDelete();
            $table->unsignedBigInteger('valor');
            $table->unsignedBigInteger('valor_utilizado')->default(0);
            $table->unsignedTinyInteger('estado')->default(1);
            $table->string('estado_descricao');
            $table->timestamps();

            $table->index(['tenant_id', 'aluno_id', 'estado'], 'creditos_aluno_estado_index');
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'creditos_valor_check' => 'valor > 0',
            'creditos_valor_utilizado_check' => 'valor_utilizado >= 0 AND valor_utilizado <= valor',
            'creditos_estado_coerente_check' => '(estado = 1 AND valor_utilizado < valor) OR (estado = 2 AND valor_utilizado = valor) OR (estado = 3 AND valor_utilizado = 0)',
        ] as $nome => $expressao) {
            DB::statement("ALTER TABLE creditos ADD CONSTRAINT {$nome} CHECK ({$expressao})");
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION creditos_imutavel() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'creditos: um crédito nunca se elimina (anula-se com o pagamento)' USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                IF NEW.tenant_id <> OLD.tenant_id OR NEW.aluno_id <> OLD.aluno_id OR NEW.origem_pagamento_id <> OLD.origem_pagamento_id OR NEW.valor <> OLD.valor THEN
                    RAISE EXCEPTION 'creditos: aluno, origem e valor de um crédito nunca mudam' USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                IF OLD.estado = 3 AND NEW.estado <> 3 THEN
                    RAISE EXCEPTION 'creditos: um crédito anulado é terminal' USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER creditos_imutavel BEFORE UPDATE OR DELETE ON creditos FOR EACH ROW EXECUTE FUNCTION creditos_imutavel()');
    }

    public function down(): void
    {
        Schema::dropIfExists('creditos');

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS creditos_imutavel()');
        }
    }
};
```

`2026_10_17_100400_create_credito_utilizacoes_table.php`:
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
        // Uso de um crédito numa propina (spec Pagamentos §3, §6). Pagamentos-B acrescenta propina_multa_id + XOR.
        // pagamento_id (O8): o pagamento em cujo acto a utilização foi combinada; NULL na aplicação isolada (D12). Imutável.
        Schema::create('credito_utilizacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('credito_id')->constrained('creditos')->restrictOnDelete();
            $table->foreignId('propina_id')->constrained('propinas')->restrictOnDelete();
            $table->foreignId('pagamento_id')->nullable()->constrained('pagamentos')->restrictOnDelete();
            $table->unsignedBigInteger('valor');
            $table->date('data_utilizacao');
            $table->unsignedTinyInteger('estado')->default(1);
            $table->string('estado_descricao');
            $table->text('motivo_anulacao')->nullable();
            $table->foreignId('anulado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('anulado_em')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'credito_id', 'estado'], 'credito_utilizacoes_credito_estado_index');
            $table->index(['tenant_id', 'propina_id'], 'credito_utilizacoes_propina_index');
            $table->index(['tenant_id', 'pagamento_id'], 'credito_utilizacoes_pagamento_index');
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'credito_utilizacoes_valor_check' => 'valor > 0',
            'credito_utilizacoes_estado_check' => 'estado IN (1, 2)',
            'credito_utilizacoes_anulacao_check' => '(estado = 2) = (anulado_em IS NOT NULL)',
            'credito_utilizacoes_motivo_check' => "estado <> 2 OR btrim(coalesce(motivo_anulacao, '')) <> ''",
            'credito_utilizacoes_anulado_por_check' => 'estado = 2 OR anulado_por IS NULL',
        ] as $nome => $expressao) {
            DB::statement("ALTER TABLE credito_utilizacoes ADD CONSTRAINT {$nome} CHECK ({$expressao})");
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION credito_utilizacoes_imutavel() RETURNS trigger AS $$
            DECLARE
                autoria text[] := ARRAY['criado_por', 'anulado_por', 'updated_at'];
                anulacao text[] := ARRAY['estado', 'estado_descricao', 'motivo_anulacao', 'anulado_por', 'anulado_em', 'updated_at'];
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'credito_utilizacoes: uma utilização nunca se elimina (anule-a)' USING ERRCODE = 'integrity_constraint_violation';
                END IF;
                IF (to_jsonb(NEW) - autoria) = (to_jsonb(OLD) - autoria)
                    AND (NEW.criado_por IS NULL OR NEW.criado_por IS NOT DISTINCT FROM OLD.criado_por)
                    AND (NEW.anulado_por IS NULL OR NEW.anulado_por IS NOT DISTINCT FROM OLD.anulado_por) THEN
                    RETURN NEW;
                END IF;
                IF OLD.estado = 1 AND NEW.estado = 2 AND (to_jsonb(NEW) - anulacao) = (to_jsonb(OLD) - anulacao) THEN
                    RETURN NEW;
                END IF;
                RAISE EXCEPTION 'credito_utilizacoes: uma utilização só passa de Activa a Anulada' USING ERRCODE = 'integrity_constraint_violation';
            END;
            $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER credito_utilizacoes_imutavel BEFORE UPDATE OR DELETE ON credito_utilizacoes FOR EACH ROW EXECUTE FUNCTION credito_utilizacoes_imutavel()');
    }

    public function down(): void
    {
        Schema::dropIfExists('credito_utilizacoes');

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS credito_utilizacoes_imutavel()');
        }
    }
};
```

`Modules/Financeiro/app/Enums/EstadoCredito.php`:
```php
<?php

namespace Modules\Financeiro\Enums;

/**
 * Estados de um crédito do aluno (spec Pagamentos §3). DISPONIVEL/ESGOTADO derivam de valor_utilizado
 * (RecalcularCredito); ANULADO acompanha a anulação do pagamento de origem e é terminal.
 */
enum EstadoCredito: int
{
    case DISPONIVEL = 1;
    case ESGOTADO = 2;
    case ANULADO = 3;

    public function label(): string
    {
        return match ($this) {
            self::DISPONIVEL => 'Disponível',
            self::ESGOTADO => 'Esgotado',
            self::ANULADO => 'Anulado',
        };
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public static function opcoes(): array
    {
        return array_map(fn (self $estado) => ['value' => $estado->value, 'label' => $estado->label()], self::cases());
    }
}
```

`Modules/Financeiro/app/Enums/EstadoUtilizacaoCredito.php`:
```php
<?php

namespace Modules\Financeiro\Enums;

enum EstadoUtilizacaoCredito: int
{
    case ACTIVA = 1;
    case ANULADA = 2;

    public function label(): string
    {
        return match ($this) {
            self::ACTIVA => 'Activa',
            self::ANULADA => 'Anulada',
        };
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public static function opcoes(): array
    {
        return array_map(fn (self $estado) => ['value' => $estado->value, 'label' => $estado->label()], self::cases());
    }
}
```

`Modules/Financeiro/app/Models/PagamentoPropina.php`:
```php
<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Financeiro\Casts\DinheiroCast;

/**
 * Parte de um pagamento atribuída a uma propina (spec Pagamentos §3). Só se cria (RegistarPagamentoAction);
 * conta para o valor pago apenas enquanto o pagamento estiver Confirmado.
 */
class PagamentoPropina extends Model
{
    use PertenceAoTenant;

    public const UPDATED_AT = null;

    protected $table = 'pagamento_propinas';

    protected $fillable = ['pagamento_id', 'propina_id', 'valor'];

    protected $hidden = ['tenant_id'];

    protected $casts = [
        'pagamento_id' => 'integer',
        'propina_id' => 'integer',
        'valor' => DinheiroCast::class,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $distribuicao) {
            if (($distribuicao->valor?->unidadesMenores() ?? 0) <= 0) {
                throw new LogicException('O valor distribuído a uma propina tem de ser maior que zero.');
            }

            $pagamento = Pagamento::query()->find($distribuicao->pagamento_id);
            $propina = Propina::query()->find($distribuicao->propina_id);

            if ($pagamento === null || $propina === null) {
                throw new LogicException('O pagamento e a propina têm de existir no tenant da distribuição.');
            }

            if ((int) $propina->matricula_id !== (int) $pagamento->matricula_id) {
                throw new LogicException('Um pagamento só se distribui por propinas da sua matrícula.');
            }
        });

        static::updating(function () {
            throw new LogicException('Uma distribuição de pagamento nunca se edita: anula-se o pagamento.');
        });

        static::deleting(function () {
            throw new LogicException('Uma distribuição de pagamento nunca se elimina: anula-se o pagamento.');
        });
    }

    public function pagamento(): BelongsTo
    {
        return $this->belongsTo(Pagamento::class, 'pagamento_id');
    }

    public function propina(): BelongsTo
    {
        return $this->belongsTo(Propina::class, 'propina_id');
    }
}
```

`Modules/Financeiro/app/Models/Credito.php`:
```php
<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;
use Modules\Aluno\Models\Aluno;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Financeiro\Casts\DinheiroCast;
use Modules\Financeiro\Enums\EstadoCredito;
use Modules\Financeiro\Support\Dinheiro;

/**
 * Excedente de um pagamento, pertencente ao ALUNO (spec Pagamentos §2.3). `valor_utilizado` e `estado`
 * (Disponível/Esgotado/Anulado) só são escritos pelo RecalcularCredito. Nunca se elimina.
 */
class Credito extends Model
{
    use PertenceAoTenant;

    public const IMUTAVEIS = ['aluno_id', 'origem_pagamento_id', 'valor'];

    protected $table = 'creditos';

    protected $fillable = ['aluno_id', 'origem_pagamento_id', 'valor'];

    protected $hidden = ['tenant_id'];

    protected $attributes = [
        'estado' => 1,
        'valor_utilizado' => 0,
    ];

    protected $casts = [
        'aluno_id' => 'integer',
        'origem_pagamento_id' => 'integer',
        'valor' => DinheiroCast::class,
        'valor_utilizado' => DinheiroCast::class,
        'estado' => EstadoCredito::class,
    ];

    protected static function booted(): void
    {
        static::saving(function (self $credito) {
            $credito->estado_descricao = $credito->estado?->label();
        });

        static::creating(function (self $credito) {
            $origem = Pagamento::query()->find($credito->origem_pagamento_id);

            if ($origem === null) {
                throw new LogicException('O pagamento de origem do crédito tem de existir no tenant.');
            }

            if ((int) $origem->aluno_id !== (int) $credito->aluno_id) {
                throw new LogicException('O crédito pertence ao aluno do pagamento que o gerou.');
            }

            if ($credito->estado !== EstadoCredito::DISPONIVEL || $credito->valor_utilizado?->unidadesMenores() !== 0) {
                throw new LogicException('Um crédito nasce Disponível e por utilizar.');
            }

            $credito->validarCoerencia();
        });

        static::updating(function (self $credito) {
            $alterados = array_values(array_filter(self::IMUTAVEIS, fn (string $campo) => $credito->isDirty($campo)));

            if ($alterados !== []) {
                throw new LogicException('O aluno, a origem e o valor de um crédito nunca mudam: ' . implode(', ', $alterados) . '.');
            }

            if ($credito->getOriginal('estado') === EstadoCredito::ANULADO && $credito->isDirty('estado')) {
                throw new LogicException('Um crédito anulado é terminal.');
            }

            $credito->validarCoerencia();
        });

        static::deleting(function () {
            throw new LogicException('Um crédito nunca é eliminado.');
        });
    }

    public function aluno(): BelongsTo
    {
        return $this->belongsTo(Aluno::class, 'aluno_id');
    }

    public function origem(): BelongsTo
    {
        return $this->belongsTo(Pagamento::class, 'origem_pagamento_id');
    }

    public function utilizacoes(): HasMany
    {
        return $this->hasMany(CreditoUtilizacao::class, 'credito_id')->orderBy('id');
    }

    public function saldo(): Dinheiro
    {
        return $this->valor->subtrair($this->valor_utilizado ?? Dinheiro::deUnidadesMenores(0));
    }

    /** As mesmas regras dos CHECK de PostgreSQL. */
    private function validarCoerencia(): void
    {
        $valor = $this->valor?->unidadesMenores() ?? 0;
        $usado = $this->valor_utilizado?->unidadesMenores() ?? 0;

        if ($valor <= 0) {
            throw new LogicException('O valor de um crédito tem de ser maior que zero.');
        }

        $coerente = match ($this->estado) {
            EstadoCredito::DISPONIVEL => $usado < $valor,
            EstadoCredito::ESGOTADO => $usado === $valor,
            EstadoCredito::ANULADO => $usado === 0,
            default => false,
        };

        if (! $coerente || $usado > $valor) {
            throw new LogicException("O estado {$this->estado?->label()} do crédito não é coerente com o valor utilizado.");
        }
    }
}
```

`Modules/Financeiro/app/Models/CreditoUtilizacao.php`:
```php
<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Financeiro\Casts\DataCast;
use Modules\Financeiro\Casts\DinheiroCast;
use Modules\Financeiro\Enums\EstadoUtilizacaoCredito;
use Modules\Matricula\Models\Matricula;
use Modules\Usuario\Models\User;

/**
 * Uso de um crédito numa propina (spec Pagamentos §3, §6), independente de qualquer pagamento novo. Só passa
 * de Activa a Anulada (AnularUtilizacaoCreditoAction). Nunca se elimina.
 */
class CreditoUtilizacao extends Model
{
    use PertenceAoTenant;

    public const IMUTAVEIS = ['credito_id', 'propina_id', 'pagamento_id', 'valor', 'data_utilizacao', 'criado_por'];

    public const CAMPOS_DE_ANULACAO = ['motivo_anulacao', 'anulado_por', 'anulado_em'];

    protected $table = 'credito_utilizacoes';

    protected $fillable = ['credito_id', 'propina_id', 'pagamento_id', 'valor', 'data_utilizacao'];

    protected $hidden = ['tenant_id'];

    protected $attributes = [
        'estado' => 1,
    ];

    protected $casts = [
        'credito_id' => 'integer',
        'propina_id' => 'integer',
        'pagamento_id' => 'integer',
        'valor' => DinheiroCast::class,
        'data_utilizacao' => DataCast::class,
        'estado' => EstadoUtilizacaoCredito::class,
        'criado_por' => 'integer',
        'anulado_por' => 'integer',
        'anulado_em' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $utilizacao) {
            $utilizacao->estado_descricao = $utilizacao->estado?->label();
        });

        static::creating(function (self $utilizacao) {
            $utilizacao->criado_por ??= auth()->id();

            if (($utilizacao->valor?->unidadesMenores() ?? 0) <= 0 || $utilizacao->data_utilizacao === null) {
                throw new LogicException('Uma utilização de crédito tem valor maior que zero e data.');
            }

            if ($utilizacao->estado !== EstadoUtilizacaoCredito::ACTIVA) {
                throw new LogicException('Uma utilização de crédito nasce Activa.');
            }

            $credito = Credito::query()->find($utilizacao->credito_id);
            $propina = Propina::query()->find($utilizacao->propina_id);
            $matricula = $propina === null ? null : Matricula::query()->withTrashed()->find($propina->matricula_id);

            if ($credito === null || $matricula === null) {
                throw new LogicException('O crédito e a propina têm de existir no tenant da utilização.');
            }

            if ((int) $matricula->aluno_id !== (int) $credito->aluno_id) {
                throw new LogicException('Um crédito só se usa em propinas de matrículas do mesmo aluno.');
            }

            if ($utilizacao->pagamento_id !== null) {
                $pagamento = Pagamento::query()->find($utilizacao->pagamento_id);

                if ($pagamento === null || (int) $pagamento->aluno_id !== (int) $credito->aluno_id || (int) $pagamento->matricula_id !== (int) $propina->matricula_id) {
                    throw new LogicException('A utilização combinada tem de ser da mesma matrícula e do mesmo aluno do pagamento (O8).');
                }
            }
        });

        static::updating(function (self $utilizacao) {
            $alterados = array_values(array_filter(self::IMUTAVEIS, fn (string $campo) => $utilizacao->isDirty($campo)));

            if ($alterados !== []) {
                throw new LogicException('Uma utilização de crédito nunca se edita (' . implode(', ', $alterados) . '): anule-a.');
            }

            $original = $utilizacao->getOriginal('estado');

            if ($utilizacao->isDirty('estado') && ! ($original === EstadoUtilizacaoCredito::ACTIVA && $utilizacao->estado === EstadoUtilizacaoCredito::ANULADA)) {
                throw new LogicException('Uma utilização de crédito só passa de Activa a Anulada.');
            }

            if (! $utilizacao->isDirty('estado') && $utilizacao->isDirty(self::CAMPOS_DE_ANULACAO)) {
                throw new LogicException('Os dados da anulação só se gravam no acto de anular.');
            }

            $anulada = $utilizacao->estado === EstadoUtilizacaoCredito::ANULADA;

            if ($anulada !== ($utilizacao->anulado_em !== null) || ($anulada && trim((string) $utilizacao->motivo_anulacao) === '')) {
                throw new LogicException('Uma utilização anulada exige o motivo e o instante da anulação.');
            }
        });

        static::deleting(function () {
            throw new LogicException('Uma utilização de crédito nunca é eliminada: anule-a.');
        });
    }

    public function credito(): BelongsTo
    {
        return $this->belongsTo(Credito::class, 'credito_id');
    }

    public function propina(): BelongsTo
    {
        return $this->belongsTo(Propina::class, 'propina_id');
    }

    /** O pagamento do mesmo acto (O8); null na aplicação isolada. */
    public function pagamento(): BelongsTo
    {
        return $this->belongsTo(Pagamento::class, 'pagamento_id');
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }

    public function anuladoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'anulado_por');
    }
}
```

`Pagamento.php` — acrescentar (com `use Illuminate\Database\Eloquent\Relations\HasMany;` e `HasOne`):
```php
    public function distribuicoes(): HasMany
    {
        return $this->hasMany(PagamentoPropina::class, 'pagamento_id')->orderBy('id');
    }

    /** O crédito gerado pelo excedente (no máximo um). */
    public function credito(): HasOne
    {
        return $this->hasOne(Credito::class, 'origem_pagamento_id');
    }

    /** Utilizações de crédito combinadas no mesmo acto (O8). Independentes do pagamento: anulá-lo não as anula. */
    public function utilizacoesCombinadas(): HasMany
    {
        return $this->hasMany(CreditoUtilizacao::class, 'pagamento_id')->orderBy('id');
    }
```

`Propina.php` — acrescentar a seguir a `ajustes()`:
```php
    /** Distribuições de pagamentos (qualquer estado do pagamento), da mais antiga para a mais recente. */
    public function distribuicoes(): HasMany
    {
        return $this->hasMany(PagamentoPropina::class, 'propina_id')->orderBy('id');
    }

    /** Utilizações de crédito (Activas e Anuladas). */
    public function utilizacoesDeCredito(): HasMany
    {
        return $this->hasMany(CreditoUtilizacao::class, 'propina_id')->orderBy('id');
    }
```

- [ ] **Step 4: Correr e ver passar**

Run: o comando do Step 2, e `php artisan test tests/Feature/Tenancy/MatrizIsolamentoTest.php tests/Feature/Arquitectura/TenancyEsquemaTest.php tests/Feature/Arquitectura/EscritoresDePropinaTest.php`
Expected: PASS (as três tabelas novas com `tenant_id` NOT NULL e FK; os models novos não escrevem campos de cache de propinas).

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/database/migrations/2026_10_17_100200_create_pagamento_propinas_table.php Modules/Financeiro/database/migrations/2026_10_17_100300_create_creditos_table.php Modules/Financeiro/database/migrations/2026_10_17_100400_create_credito_utilizacoes_table.php Modules/Financeiro/app/Enums/EstadoCredito.php Modules/Financeiro/app/Enums/EstadoUtilizacaoCredito.php Modules/Financeiro/app/Models/PagamentoPropina.php Modules/Financeiro/app/Models/Credito.php Modules/Financeiro/app/Models/CreditoUtilizacao.php Modules/Financeiro/app/Models/Pagamento.php Modules/Financeiro/app/Models/Propina.php Modules/Financeiro/tests/Concerns/ComPagamentosFinanceiro.php Modules/Financeiro/tests/Unit/EstadosPagamentoTest.php Modules/Financeiro/tests/Feature/CreditoModelTest.php
```

---

### Task 4: Fontes reais de `valor_pago` e de histórico, referências e `RecalcularCredito` (spec Propinas §6; D3, D10, D14, D15)

**Files:**
- Create: `Modules/Financeiro/app/Support/PagamentosDaPropina.php`
- Create: `Modules/Financeiro/app/Support/HistoricoDosPagamentos.php`
- Create: `Modules/Financeiro/app/Support/PagamentosReferenciam.php`
- Create: `Modules/Financeiro/app/Services/RecalcularCredito.php`
- Modify: `Modules/Financeiro/app/Providers/FinanceiroServiceProvider.php` (`register()`)
- Create: `Modules/Financeiro/tests/Feature/FontesDosPagamentosTest.php`

**Interfaces:**
- Consumes: `FonteDePagamentoDePropina`, `HistoricoDePagamentosDaPropina`, `ReferenciaFinanceira` (F1/Configuração), `ContribuicaoDePagamento`, models de T2/T3, `FontesDePagamentoDePropina::ETIQUETA`, `HistoricosDePagamentoDaPropina::ETIQUETA`, `ReferenciasFinanceiras::ETIQUETA`.
- Produces:
  - `PagamentosDaPropina::contribuicoes(Propina): list<ContribuicaoDePagamento>` — distribuições de pagamentos **Confirmados** (data = `data_pagamento`) + utilizações **Activas** (data = `data_utilizacao`), por data, depois distribuições antes de utilizações, depois id.
  - `HistoricoDosPagamentos::temHistorico(Propina): bool` — alguma distribuição (qualquer estado do pagamento) ou utilização (qualquer estado).
  - `PagamentosReferenciam::existeReferenciaA(Model): bool` — `MetodoPagamento` com pagamentos; `ConfiguracaoMonetaria` com qualquer pagamento.
  - `RecalcularCredito::executar(Credito): Credito` (relê com `FOR UPDATE`; `valor_utilizado = Σ utilizações Activas`; DISPONIVEL/ESGOTADO; nunca reabre um ANULADO) e `RecalcularCredito::anular(Credito): Credito` (exige 0 utilizado → ANULADO). Ambos exigem transacção.

- [ ] **Step 1: Escrever os testes**

`Modules/Financeiro/tests/Feature/FontesDosPagamentosTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Financeiro\Actions\EliminarMetodoPagamentoAction;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\EstadoCredito;
use Modules\Financeiro\Enums\EstadoPagamento;
use Modules\Financeiro\Enums\EstadoUtilizacaoCredito;
use Modules\Financeiro\Models\ConfiguracaoMonetaria;
use Modules\Financeiro\Models\Credito;
use Modules\Financeiro\Models\Pagamento;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\RecalcularCredito;
use Modules\Financeiro\Services\RecalcularPropina;
use Modules\Financeiro\Support\FontesDePagamentoDePropina;
use Modules\Financeiro\Support\HistoricosDePagamentoDaPropina;
use Modules\Financeiro\Support\ReferenciasFinanceiras;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Tests\TestCase;

class FontesDosPagamentosTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPagamentosFinanceiro;
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

    private function recalcular(Propina $propina): Propina
    {
        return DB::transaction(fn () => app(RecalcularPropina::class)->executar($propina));
    }

    private function anularDirecto(Pagamento $pagamento): void
    {
        $pagamento->forceFill(['estado' => EstadoPagamento::ANULADO, 'motivo_anulacao' => 'Teste', 'anulado_em' => now()])->save();
    }

    public function test_as_fontes_reais_estao_registadas_e_comecam_vazias(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano, 2);

        $this->assertSame([], app(FontesDePagamentoDePropina::class)->contribuicoes($propina));
        $this->assertFalse(app(HistoricosDePagamentoDaPropina::class)->temHistorico($propina));
    }

    public function test_distribuicoes_confirmadas_e_utilizacoes_activas_contam_com_a_sua_data(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano, 2); // 25.000,00
        $pagamento = $this->pagamentoDirecto($matricula, 1_000_000, ['data_pagamento' => '2026-10-05']);
        $this->distribuicaoDirecta($pagamento, $propina, 1_000_000);
        $origem = $this->pagamentoDirecto($matricula, 1_500_000, ['data_pagamento' => '2026-09-20']);
        $credito = $this->creditoDirecto($origem, 1_500_000);
        $this->utilizacaoDirecta($credito, $propina, 1_500_000, '2026-10-12');

        $contribuicoes = app(FontesDePagamentoDePropina::class)->contribuicoes($propina);

        $this->assertSame([[1_000_000, '2026-10-05'], [1_500_000, '2026-10-12']], array_map(
            fn ($c) => [$c->valor->unidadesMenores(), $c->data->toDateString()],
            $contribuicoes,
        ));

        $recalculada = $this->recalcular($propina);
        $this->assertSame(EstadoCobranca::PAGA, $recalculada->estado);
        $this->assertSame(2_500_000, $recalculada->valor_pago->unidadesMenores());
        $this->assertSame('2026-10-12', $recalculada->capital_liquidado_em->toDateString(), 'A contribuição que levou o saldo a 0.');
        $this->assertPropinasCoerentes();
    }

    public function test_pagamento_anulado_e_utilizacao_anulada_nao_contam_mas_ficam_no_historico(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano, 2);
        $pagamento = $this->pagamentoDirecto($matricula, 1_000_000);
        $this->distribuicaoDirecta($pagamento, $propina, 1_000_000);
        $this->assertSame(EstadoCobranca::PARCIALMENTE_PAGA, $this->recalcular($propina)->estado);

        $this->anularDirecto($pagamento);

        $recalculada = $this->recalcular($propina);
        $this->assertSame(EstadoCobranca::EM_ABERTO, $recalculada->estado);
        $this->assertSame(0, $recalculada->valor_pago->unidadesMenores());
        $this->assertNull($recalculada->capital_liquidado_em);
        $this->assertTrue(app(HistoricosDePagamentoDaPropina::class)->temHistorico($propina));
        $this->assertPropinasCoerentes();
    }

    public function test_so_uma_utilizacao_de_credito_tambem_e_historico(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano, 2);
        $credito = $this->creditoDirecto($this->pagamentoDirecto($matricula, 500_000), 500_000);
        $utilizacao = $this->utilizacaoDirecta($credito, $propina, 500_000);
        $utilizacao->forceFill(['estado' => EstadoUtilizacaoCredito::ANULADA, 'motivo_anulacao' => 'Engano', 'anulado_em' => now()])->save();

        $this->assertSame([], app(FontesDePagamentoDePropina::class)->contribuicoes($propina));
        $this->assertTrue(app(HistoricosDePagamentoDaPropina::class)->temHistorico($propina));
    }

    public function test_metodo_com_pagamentos_nao_se_elimina_e_a_moeda_fica_presa(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();
        $metodo = $this->metodoPagamento('Transferência');
        $livre = $this->metodoPagamento('Cheque');
        $this->pagamentoDirecto($matricula, 100, ['metodo_pagamento_id' => $metodo->id]);

        $referencias = app(ReferenciasFinanceiras::class);
        $this->assertTrue($referencias->existeReferenciaA($metodo));
        $this->assertFalse($referencias->existeReferenciaA($livre));
        $this->assertTrue($referencias->existeReferenciaA(ConfiguracaoMonetaria::doTenant()));

        $this->expectException(ValidationException::class);
        app(EliminarMetodoPagamentoAction::class)->executar($metodo);
    }

    public function test_recalcular_credito_segue_as_utilizacoes_activas_e_anular_exige_credito_por_usar(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $credito = $this->creditoDirecto($this->pagamentoDirecto($matricula, 500_000), 500_000);
        $utilizacao = $this->utilizacaoDirecta($credito, $this->propina($matricula, $plano, 2), 500_000);
        $recalcular = app(RecalcularCredito::class);

        $credito = DB::transaction(fn () => $recalcular->executar($credito));
        $this->assertSame(EstadoCredito::ESGOTADO, $credito->estado);
        $this->assertSame(0, $credito->saldo()->unidadesMenores());

        try {
            DB::transaction(fn () => $recalcular->anular($credito));
            $this->fail('Um crédito utilizado não se anula.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $utilizacao->forceFill(['estado' => EstadoUtilizacaoCredito::ANULADA, 'motivo_anulacao' => 'Engano', 'anulado_em' => now()])->save();
        $credito = DB::transaction(fn () => $recalcular->executar($credito));
        $this->assertSame(EstadoCredito::DISPONIVEL, $credito->estado);
        $this->assertSame(500_000, $credito->saldo()->unidadesMenores());

        $credito = DB::transaction(fn () => $recalcular->anular($credito));
        $this->assertSame(EstadoCredito::ANULADO, $credito->estado);
        $this->assertSame(EstadoCredito::ANULADO, DB::transaction(fn () => $recalcular->executar($credito))->estado, 'Anulado é terminal.');
    }

    public function test_recalcular_credito_exige_transaccao(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();
        $credito = $this->creditoDirecto($this->pagamentoDirecto($matricula, 500_000), 500_000);

        // O RefreshDatabase envolve o teste numa transacção: sai-se dela para provar a recusa (como no RecalcularPropinaTest).
        DB::rollBack();
        $this->assertSame(0, DB::transactionLevel());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('exige uma transacção aberta');
        app(RecalcularCredito::class)->executar($credito);
    }

    public function test_credito_de_outro_tenant_nao_se_recalcula(): void
    {
        ['matricula' => $matricula] = $this->cenarioPropinas();
        $credito = $this->creditoDirecto($this->pagamentoDirecto($matricula, 500_000), 500_000);
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        $this->noTenant($outro, function () use ($credito) {
            $this->expectException(LogicException::class);
            DB::transaction(fn () => app(RecalcularCredito::class)->executar($credito));
        });
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/FontesDosPagamentosTest.php`
Expected: FAIL (classes inexistentes; contribuições vazias).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Support/PagamentosDaPropina.php`:
```php
<?php

namespace Modules\Financeiro\Support;

use Carbon\CarbonImmutable;
use Modules\Financeiro\Contracts\FonteDePagamentoDePropina;
use Modules\Financeiro\Enums\EstadoPagamento;
use Modules\Financeiro\Enums\EstadoUtilizacaoCredito;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Models\PagamentoPropina;
use Modules\Financeiro\Models\Propina;

/**
 * O que conta para o valor pago de uma propina (spec Propinas §6, Pagamentos §3): distribuições de pagamentos
 * CONFIRMADOS (data = data_pagamento) e utilizações de crédito ACTIVAS (data = data_utilizacao). Só é lida pelo
 * RecalcularPropina (via FontesDePagamentoDePropina). Ordem estável: data, distribuições antes de utilizações, id.
 */
class PagamentosDaPropina implements FonteDePagamentoDePropina
{
    public function contribuicoes(Propina $propina): array
    {
        $linhas = [];

        $distribuicoes = PagamentoPropina::query()
            ->where('propina_id', $propina->getKey())
            ->whereHas('pagamento', fn ($pagamento) => $pagamento->where('estado', EstadoPagamento::CONFIRMADO->value))
            ->with('pagamento')
            ->orderBy('id')
            ->get();

        foreach ($distribuicoes as $distribuicao) {
            $linhas[] = [$distribuicao->pagamento->data_pagamento->toDateString(), 0, $distribuicao->id, $distribuicao->valor];
        }

        $utilizacoes = CreditoUtilizacao::query()
            ->where('propina_id', $propina->getKey())
            ->where('estado', EstadoUtilizacaoCredito::ACTIVA->value)
            ->orderBy('id')
            ->get();

        foreach ($utilizacoes as $utilizacao) {
            $linhas[] = [$utilizacao->data_utilizacao->toDateString(), 1, $utilizacao->id, $utilizacao->valor];
        }

        usort($linhas, fn (array $a, array $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        return array_map(
            fn (array $linha) => new ContribuicaoDePagamento($linha[3], CarbonImmutable::createFromFormat('!Y-m-d', $linha[0])),
            $linhas,
        );
    }
}
```

`Modules/Financeiro/app/Support/HistoricoDosPagamentos.php`:
```php
<?php

namespace Modules\Financeiro\Support;

use Modules\Financeiro\Contracts\HistoricoDePagamentosDaPropina;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Models\PagamentoPropina;
use Modules\Financeiro\Models\Propina;

/**
 * "Esta propina alguma vez recebeu dinheiro?" (spec Propinas §8): qualquer distribuição, de um pagamento em qualquer
 * estado, ou qualquer utilização de crédito, Activa ou Anulada. Com histórico, a propina já não se cancela: anula-se.
 */
class HistoricoDosPagamentos implements HistoricoDePagamentosDaPropina
{
    public function temHistorico(Propina $propina): bool
    {
        return PagamentoPropina::query()->where('propina_id', $propina->getKey())->exists()
            || CreditoUtilizacao::query()->where('propina_id', $propina->getKey())->exists();
    }
}
```

`Modules/Financeiro/app/Support/PagamentosReferenciam.php`:
```php
<?php

namespace Modules\Financeiro\Support;

use Illuminate\Database\Eloquent\Model;
use Modules\Financeiro\Contracts\ReferenciaFinanceira;
use Modules\Financeiro\Models\ConfiguracaoMonetaria;
use Modules\Financeiro\Models\MetodoPagamento;
use Modules\Financeiro\Models\Pagamento;

/**
 * Pagamentos são registos operacionais: um método com pagamentos (em qualquer estado) não se elimina — desactiva-se
 * (spec Configuração §5.2: métodos inactivos continuam referenciáveis) — e a moeda da escola não muda enquanto
 * existir qualquer pagamento.
 */
class PagamentosReferenciam implements ReferenciaFinanceira
{
    public function existeReferenciaA(Model $configuracao): bool
    {
        return match (true) {
            $configuracao instanceof MetodoPagamento => Pagamento::query()->where('metodo_pagamento_id', $configuracao->getKey())->exists(),
            $configuracao instanceof ConfiguracaoMonetaria => Pagamento::query()->exists(),
            default => false,
        };
    }
}
```

`Modules/Financeiro/app/Services/RecalcularCredito.php`:
```php
<?php

namespace Modules\Financeiro\Services;

use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Financeiro\Enums\EstadoCredito;
use Modules\Financeiro\Enums\EstadoUtilizacaoCredito;
use Modules\Financeiro\Models\Credito;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Support\Dinheiro;

/**
 * ÚNICO escritor de `valor_utilizado` e do estado de um crédito (EscritoresDePagamentoTest). Corre na transacção do
 * chamador, com o crédito bloqueado (relê-o com FOR UPDATE). Devolve a instância relida e gravada.
 */
class RecalcularCredito
{
    public function executar(Credito $credito): Credito
    {
        $bloqueado = $this->bloquear($credito);
        $usado = $this->usado($bloqueado);

        if ($bloqueado->estado === EstadoCredito::ANULADO) {
            if ($usado !== 0) {
                throw new LogicException("O crédito {$bloqueado->id} está anulado e tem utilizações activas.");
            }

            return $bloqueado;
        }

        $valor = $bloqueado->valor->unidadesMenores();

        if ($usado > $valor) {
            throw new LogicException("As utilizações ({$usado}) excedem o crédito {$bloqueado->id} ({$valor}).");
        }

        $bloqueado->forceFill([
            'valor_utilizado' => Dinheiro::deUnidadesMenores($usado),
            'estado' => $usado === $valor ? EstadoCredito::ESGOTADO : EstadoCredito::DISPONIVEL,
        ])->save();

        return $bloqueado;
    }

    /** Acompanha a anulação do pagamento de origem: só um crédito sem utilizações activas. */
    public function anular(Credito $credito): Credito
    {
        $bloqueado = $this->bloquear($credito);

        if ($this->usado($bloqueado) !== 0) {
            throw new LogicException("O crédito {$bloqueado->id} tem utilizações activas: anule-as primeiro.");
        }

        $bloqueado->forceFill(['valor_utilizado' => Dinheiro::deUnidadesMenores(0), 'estado' => EstadoCredito::ANULADO])->save();

        return $bloqueado;
    }

    private function bloquear(Credito $credito): Credito
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Recalcular um crédito exige uma transacção aberta do chamador.');
        }

        return Credito::query()->whereKey($credito->getKey())->lockForUpdate()->first()
            ?? throw new LogicException('O crédito a recalcular não existe no tenant corrente.');
    }

    private function usado(Credito $credito): int
    {
        return (int) CreditoUtilizacao::query()
            ->where('credito_id', $credito->getKey())
            ->where('estado', EstadoUtilizacaoCredito::ACTIVA->value)
            ->sum('valor');
    }
}
```

`FinanceiroServiceProvider::register()` — acrescentar (com os `use` de `PagamentosDaPropina`, `HistoricoDosPagamentos`, `PagamentosReferenciam`, `FontesDePagamentoDePropina`, `HistoricosDePagamentoDaPropina`):
```php
        $this->app->tag([PropinasReferenciam::class, PagamentosReferenciam::class], ReferenciasFinanceiras::ETIQUETA);
        // Pagamentos-A: as fontes reais do valor pago e do histórico de uma propina (contratos de F1).
        $this->app->tag([PagamentosDaPropina::class], FontesDePagamentoDePropina::ETIQUETA);
        $this->app->tag([HistoricoDosPagamentos::class], HistoricosDePagamentoDaPropina::ETIQUETA);
```
(substituindo a linha existente `$this->app->tag([PropinasReferenciam::class], ReferenciasFinanceiras::ETIQUETA);`).

- [ ] **Step 4: Correr e ver passar (e a suite de propinas continua verde)**

Run: `php artisan test Modules/Financeiro/tests/Feature/FontesDosPagamentosTest.php Modules/Financeiro/tests/Feature/RecalcularPropinaTest.php Modules/Financeiro/tests/Feature/CancelamentoDePropinasTest.php Modules/Financeiro/tests/Feature/HistoricosDePagamentoDaPropinaTest.php Modules/Financeiro/tests/Feature/MetodoPagamentoTest.php Modules/Financeiro/tests/Feature/ReferenciasFinanceirasTest.php Modules/Financeiro/tests/Feature/MoedaDoTenantTest.php`
Expected: PASS. Se `HistoricosDePagamentoDaPropinaTest` afirmar "sem fontes registadas, nenhuma tem histórico", ajustar a frase do teste para "sem pagamentos, nenhuma tem histórico" (o comportamento é o mesmo: a fonte real devolve `false`).

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Support/PagamentosDaPropina.php Modules/Financeiro/app/Support/HistoricoDosPagamentos.php Modules/Financeiro/app/Support/PagamentosReferenciam.php Modules/Financeiro/app/Services/RecalcularCredito.php Modules/Financeiro/app/Providers/FinanceiroServiceProvider.php Modules/Financeiro/tests/Feature/FontesDosPagamentosTest.php
```

---

### Task 5: Arquitectura — escritores únicos e registos de pagamento sem escrita em massa (D17)

**Files:**
- Create: `tests/Feature/Arquitectura/EscritoresDePagamentoTest.php`

**Interfaces:**
- Consumes: `RecalcularCredito` (T4), models de T2/T3.
- Produces: rede de segurança estática: (1) `valor_utilizado` só em `Modules/Financeiro/app/Services/RecalcularCredito.php`; (2) `'estado' => EstadoCredito::…` / `->estado = EstadoCredito::…` só no mesmo ficheiro; (3) nenhuma escrita em massa (query builder: `update`, `delete`, `insert`, `upsert`, `increment`, `destroy`…) em `Pagamento`, `PagamentoPropina`, `Credito`, `CreditoUtilizacao` nem nas tabelas `pagamentos`, `pagamento_propinas`, `creditos`, `credito_utilizacoes` (contornaria os hooks de imutabilidade). Varre `app`, `routes`, `database/seeders` da raiz e dos módulos (mesmo âmbito de `EscritoresDePropinaTest`).

- [ ] **Step 1: Escrever o teste**

`tests/Feature/Arquitectura/EscritoresDePagamentoTest.php`:
```php
<?php

namespace Tests\Feature\Arquitectura;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Pagamentos-A (spec Pagamentos §2.4–2.5): `valor_utilizado` e o estado de um crédito são caches do RecalcularCredito;
 * pagamentos, distribuições, créditos e utilizações só mudam por instância (passando pelos hooks de imutabilidade do
 * model), nunca por escrita em massa. Mesma técnica e mesmos limites de EscritoresDePropinaTest (tokens; não apanha
 * chaves dinâmicas, SQL em strings cruas nem variáveis com o nome do model). O `valor_pago` das propinas continua
 * protegido por EscritoresDePropinaTest (só RecalcularPropina).
 */
class EscritoresDePagamentoTest extends TestCase
{
    private const ESCRITOR_DO_CREDITO = 'Modules/Financeiro/app/Services/RecalcularCredito.php';

    private const MODELS = ['Pagamento', 'PagamentoPropina', 'Credito', 'CreditoUtilizacao'];

    private const TABELAS = ['pagamentos', 'pagamento_propinas', 'creditos', 'credito_utilizacoes'];

    private const ESCRITA_EM_MASSA = [
        'update', 'updateQuietly', 'updateOrInsert', 'upsert', 'delete', 'deleteQuietly', 'forceDelete', 'destroy',
        'truncate', 'increment', 'decrement', 'insert', 'insertOrIgnore', 'insertGetId', 'insertUsing',
    ];

    private const METODOS_DE_ESCRITA = [
        'fill', 'forceFill', 'update', 'create', 'forceCreate', 'make', 'firstOrCreate', 'firstOrNew', 'updateOrCreate',
        'updateOrInsert', 'setRawAttributes', 'setAttribute', 'increment', 'decrement', 'insert', 'upsert',
    ];

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

    /** @return list<string> */
    private function escritasDoValorUtilizado(string $codigo): array
    {
        $tokens = $this->tokens($codigo);
        $total = count($tokens);
        $achados = [];

        for ($i = 0; $i < $total; $i++) {
            // $x->valor_utilizado = …, +=, ++ …
            if (in_array($tokens[$i][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                && ($tokens[$i + 1][1] ?? null) === 'valor_utilizado'
                && in_array($tokens[$i + 2][0] ?? null, ['=', T_PLUS_EQUAL, T_MINUS_EQUAL, T_COALESCE_EQUAL, T_INC, T_DEC], true)) {
                $achados[] = $this->texto($tokens, $i, min($i + 3, $total - 1));

                continue;
            }

            if ($tokens[$i][0] !== T_STRING
                || ! in_array($tokens[$i][1], self::METODOS_DE_ESCRITA, true)
                || ($tokens[$i + 1][0] ?? null) !== '('
                || ! in_array($tokens[$i - 1][0] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                continue;
            }

            $nivel = 0;

            for ($j = $i + 1; $j < $total; $j++) {
                if (in_array($tokens[$j][0], ['(', '[', '{', T_CURLY_OPEN], true)) {
                    $nivel++;
                } elseif (in_array($tokens[$j][0], [')', ']', '}'], true) && --$nivel === 0) {
                    break;
                }

                if ($this->eString($tokens[$j], ['valor_utilizado'])
                    && (($tokens[$j + 1][0] ?? null) === T_DOUBLE_ARROW || $tokens[$j - 1][0] === '(')) {
                    $achados[] = $this->texto($tokens, $j, min($j + 3, $total - 1));
                }
            }
        }

        return $achados;
    }

    /** @return list<string> */
    private function escritasDoEstadoDoCredito(string $codigo): array
    {
        $semComentarios = implode(' ', array_map(fn (array $t): string => $t[1], $this->tokens($codigo)));
        preg_match_all("/(?:'estado'\s*=>|->\s*estado\s*=(?!=))\s*EstadoCredito\s*::\s*(?!class\b)\w+/", $semComentarios, $m);

        return $m[0];
    }

    /** @return list<string> */
    private function escritasEmMassa(string $codigo): array
    {
        $tokens = $this->tokens($codigo);
        $total = count($tokens);
        $achados = [];

        for ($i = 0; $i < $total; $i++) {
            $modelo = in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && in_array(substr(strrchr('\\' . $tokens[$i][1], '\\'), 1), self::MODELS, true)
                && ($tokens[$i + 1][0] ?? null) === T_DOUBLE_COLON
                && ($tokens[$i - 1][0] ?? null) !== T_NEW;

            if (! $modelo && ! $this->eString($tokens[$i], self::TABELAS)) {
                continue;
            }

            for ($j = $i + 1; $j < $total && $tokens[$j][0] !== ';'; $j++) {
                if ($tokens[$j][0] === T_STRING
                    && in_array($tokens[$j][1], self::ESCRITA_EM_MASSA, true)
                    && ($tokens[$j + 1][0] ?? null) === '('
                    && in_array($tokens[$j - 1][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                    $achados[] = $this->texto($tokens, $i, $j + 1);

                    break;
                }
            }
        }

        return $achados;
    }

    private function raiz(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string, string> */
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

    public function test_so_o_recalcular_credito_escreve_o_valor_utilizado_e_o_estado_do_credito(): void
    {
        $violacoes = [];

        foreach ($this->codigoVarrido() as $relativo => $codigo) {
            if ($relativo === self::ESCRITOR_DO_CREDITO) {
                continue;
            }

            foreach ([...$this->escritasDoValorUtilizado($codigo), ...$this->escritasDoEstadoDoCredito($codigo)] as $excerto) {
                $violacoes[] = "{$relativo}: {$excerto}";
            }
        }

        $this->assertSame([], $violacoes, "Só RecalcularCredito escreve valor_utilizado e o estado de um crédito:\n" . implode("\n", $violacoes));
    }

    public function test_o_escritor_do_credito_existe_e_e_apanhado(): void
    {
        $codigo = file_get_contents($this->raiz() . '/' . self::ESCRITOR_DO_CREDITO);

        $this->assertNotSame([], $this->escritasDoValorUtilizado($codigo));
        $this->assertNotSame([], $this->escritasDoEstadoDoCredito($codigo));
    }

    public function test_os_registos_de_pagamento_nunca_se_escrevem_em_massa(): void
    {
        $violacoes = [];

        foreach ($this->codigoVarrido() as $relativo => $codigo) {
            foreach ($this->escritasEmMassa($codigo) as $excerto) {
                $violacoes[] = "{$relativo}: {$excerto}";
            }
        }

        $this->assertSame([], $violacoes, "Pagamentos, distribuições, créditos e utilizações só mudam por instância (hooks de imutabilidade):\n" . implode("\n", $violacoes));
    }

    #[DataProvider('exemplosValorUtilizado')]
    public function test_o_varrimento_do_valor_utilizado_apanha_o_que_deve(string $codigo, bool $escrita): void
    {
        $this->assertSame($escrita, $this->escritasDoValorUtilizado('<?php ' . $codigo) !== [], $codigo);
    }

    public static function exemplosValorUtilizado(): array
    {
        return [
            'atribuição' => ['$c->valor_utilizado = 1;', true],
            'composta' => ['$c->valor_utilizado += 1;', true],
            'forceFill' => ["\$c->forceFill(['estado' => 1, 'valor_utilizado' => 0])->save();", true],
            'setAttribute' => ["\$c->setAttribute('valor_utilizado', 1);", true],
            'increment' => ["Credito::query()->increment('valor_utilizado', 5);", true],
            'leitura' => ['$x = $c->valor_utilizado; if ($c->valor_utilizado === $y) {}', false],
            'casts' => ["protected \$casts = ['valor_utilizado' => DinheiroCast::class];", false],
            'array que não grava' => ["\$linha = ['valor_utilizado' => \$c->valor_utilizado];", false],
            'comentário' => ['// $c->valor_utilizado = 1;', false],
        ];
    }

    #[DataProvider('exemplosEstado')]
    public function test_o_varrimento_do_estado_do_credito_apanha_o_que_deve(string $codigo, bool $escrita): void
    {
        $this->assertSame($escrita, $this->escritasDoEstadoDoCredito('<?php ' . $codigo) !== [], $codigo);
    }

    public static function exemplosEstado(): array
    {
        return [
            'forceFill' => ["\$c->forceFill(['estado' => EstadoCredito::ESGOTADO]);", true],
            'atribuição' => ['$c->estado = EstadoCredito::ANULADO;', true],
            'comparação' => ['if ($c->estado === EstadoCredito::ANULADO || $c->estado !== EstadoCredito::DISPONIVEL) {}', false],
            'filtro' => ["Credito::query()->where('estado', EstadoCredito::DISPONIVEL->value);", false],
            'outro enum' => ["\$p->forceFill(['estado' => EstadoPagamento::ANULADO]);", false],
            'cast do model' => ["protected \$casts = ['estado' => EstadoCredito::class];", false],
        ];
    }

    #[DataProvider('exemplosEmMassa')]
    public function test_o_varrimento_de_escritas_em_massa_apanha_o_que_deve(string $codigo, bool $escrita): void
    {
        $this->assertSame($escrita, $this->escritasEmMassa('<?php ' . $codigo) !== [], $codigo);
    }

    public static function exemplosEmMassa(): array
    {
        return [
            'update em query' => ["Pagamento::query()->whereKey(1)->update(['estado' => 2]);", true],
            'delete em query' => ["PagamentoPropina::query()->where('pagamento_id', 1)->delete();", true],
            'increment' => ["Credito::query()->whereKey(1)->increment('valor_utilizado');", true],
            'insert estático' => ['CreditoUtilizacao::insert($linhas);', true],
            'destroy' => ['Pagamento::destroy(1);', true],
            'tabela' => ["DB::table('pagamento_propinas')->delete();", true],
            'FQN' => ['\\Modules\\Financeiro\\Models\\Credito::query()->update([]);', true],
            'create' => ["PagamentoPropina::create(['valor' => 1]);", false],
            'leitura' => ["Pagamento::query()->where('estado', 1)->exists(); Credito::query()->sum('valor');", false],
            'relação' => ['return $this->hasMany(PagamentoPropina::class, "pagamento_id")->orderBy("id");', false],
            'instância' => ["\$pagamento->forceFill(['estado' => 2])->save();", false],
            'exists rule' => ["Rule::exists('pagamentos', 'id')->where(fn (\$q) => \$q->where('tenant_id', 1));", false],
        ];
    }
}
```

- [ ] **Step 2: Correr**

Run: `php artisan test tests/Feature/Arquitectura/EscritoresDePagamentoTest.php tests/Feature/Arquitectura/EscritoresDePropinaTest.php`
Expected: PASS (os detectores apanham os exemplos; o código de T2–T4 respeita as regras). Se `test_os_registos_de_pagamento_nunca_se_escrevem_em_massa` apontar algo em código de T2–T4, corrigir o código (nunca o teste).

- [ ] **Step 3: Stage**

```bash
git add tests/Feature/Arquitectura/EscritoresDePagamentoTest.php
```

---

### Task 6: Motor de distribuição (só leitura) e DTOs (D8–D12)

**Files:**
- Create: `Modules/Financeiro/app/DTO/LinhaDeDistribuicao.php`
- Create: `Modules/Financeiro/app/DTO/RegistarPagamentoDTO.php`
- Create: `Modules/Financeiro/app/DTO/AplicarCreditoDTO.php`
- Create: `Modules/Financeiro/app/DTO/MotivoDeAnulacaoDTO.php`
- Create: `Modules/Financeiro/app/DTO/PlanoDePagamentoDTO.php`
- Create: `Modules/Financeiro/app/Services/DistribuicaoDePagamentoService.php`
- Modify: `Modules/Financeiro/tests/Concerns/ComPagamentosFinanceiro.php` (+ `regras()`, `linhas()`, `pedidoPagamento()`, `pedidoCredito()`)
- Create: `Modules/Financeiro/tests/Feature/DistribuicaoDePagamentoTest.php`

**Interfaces:**
- Consumes: `Propina` (`saldo()`, `periodo_inicio`, `ordem`, `estado`), `Credito` (`saldo()`), `RegraCobranca::doTenant()` (`permite_pagamento_parcial`, `permite_pagamento_antecipado`), `RelogioDoTenant`, `MoedaDoTenant`, `RotuloDePeriodo::periodo()`.
- Produces:
  - `LinhaDeDistribuicao(int $propinaId, Dinheiro $valor)`.
  - `RegistarPagamentoDTO(int $metodoPagamentoId, Dinheiro $valor, CarbonImmutable $dataPagamento, ?string $referencia = null, ?string $observacao = null, ?array $distribuicao = null, ?Dinheiro $valorCredito = null)` + `fromRequest(FormRequest)`; `distribuicao` `null` = automática.
  - `AplicarCreditoDTO(Dinheiro $valorCredito, ?array $distribuicao = null)` + `fromRequest`.
  - `MotivoDeAnulacaoDTO(string $motivo)` (`MINIMO = 5`, `MAXIMO = 500`) + `fromRequest`.
  - `PlanoDePagamentoDTO` (`dinheiro`, `credito`, `linhas`, `utilizacoes`, `excedente`; `distribuicoes()`, `totalDistribuido()`, `propinasTocadas()`, `creditosUsados()`, `toArray()`).
  - `DistribuicaoDePagamentoService::calcular(int $dinheiro, int $creditoPedido, CarbonImmutable $dataEfeito, ?array $distribuicao, Collection $propinas, Collection $creditos, RegraCobranca $regras): PlanoDePagamentoDTO` (lança `ValidationException`), `preVisualizarPagamento(Matricula, RegistarPagamentoDTO)`, `preVisualizarCredito(Matricula, AplicarCreditoDTO)`, `propinasPagaveis(Matricula): Collection`, `creditosDisponiveis(Matricula): Collection`, `validarData(CarbonImmutable $data, Matricula $matricula): void` (recusa data futura **e** anterior a `$matricula->data_matricula`; O3).
  - Helpers de teste: `regras(bool $parcial = false, bool $antecipado = false): RegraCobranca`, `linhas(array $pares): list<LinhaDeDistribuicao>` (`[[Propina|int, int], …]`), `pedidoPagamento(int $valor, ?array $linhas = null, int $credito = 0, string $data = '2026-10-16', ?string $referencia = null, ?int $metodoId = null): RegistarPagamentoDTO`, `pedidoCredito(int $credito, ?array $linhas = null): AplicarCreditoDTO`.

- [ ] **Step 1: Escrever os testes**

Em `ComPagamentosFinanceiro` acrescentar (com `use Carbon\CarbonImmutable;`, `use Modules\Financeiro\DTO\AplicarCreditoDTO;`, `use Modules\Financeiro\DTO\LinhaDeDistribuicao;`, `use Modules\Financeiro\DTO\RegistarPagamentoDTO;`, `use Modules\Financeiro\Models\RegraCobranca;`, `use Modules\Financeiro\Support\Dinheiro;`):
```php
    protected function regras(bool $parcial = false, bool $antecipado = false): RegraCobranca
    {
        $regras = RegraCobranca::doTenant();
        $regras->update(['permite_pagamento_parcial' => $parcial, 'permite_pagamento_antecipado' => $antecipado]);

        return $regras->refresh();
    }

    /**
     * @param  list<array{0: Propina|int, 1: int}>  $pares
     * @return list<LinhaDeDistribuicao>
     */
    protected function linhas(array $pares): array
    {
        return array_map(
            fn (array $par) => new LinhaDeDistribuicao($par[0] instanceof Propina ? (int) $par[0]->id : $par[0], Dinheiro::deUnidadesMenores($par[1])),
            $pares,
        );
    }

    /**
     * @param  list<array{0: Propina|int, 1: int}>|null  $linhas  null = distribuição automática
     */
    protected function pedidoPagamento(int $valor, ?array $linhas = null, int $credito = 0, string $data = '2026-10-16', ?string $referencia = null, ?int $metodoId = null): RegistarPagamentoDTO
    {
        return new RegistarPagamentoDTO(
            metodoPagamentoId: $metodoId ?? (int) $this->metodoPagamento()->id,
            valor: Dinheiro::deUnidadesMenores($valor),
            dataPagamento: CarbonImmutable::createFromFormat('!Y-m-d', $data),
            referencia: $referencia,
            distribuicao: $linhas === null ? null : $this->linhas($linhas),
            valorCredito: Dinheiro::deUnidadesMenores($credito),
        );
    }

    /**
     * @param  list<array{0: Propina|int, 1: int}>|null  $linhas
     */
    protected function pedidoCredito(int $credito, ?array $linhas = null): AplicarCreditoDTO
    {
        return new AplicarCreditoDTO(Dinheiro::deUnidadesMenores($credito), $linhas === null ? null : $this->linhas($linhas));
    }
```

`Modules/Financeiro/tests/Feature/DistribuicaoDePagamentoTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Financeiro\DTO\PlanoDePagamentoDTO;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Models\Pagamento;
use Modules\Financeiro\Models\PagamentoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\DistribuicaoDePagamentoService;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Matricula\Models\Matricula;
use Tests\TestCase;

/**
 * Hoje = 16/10/2026. Plano mensal Set→Jun a 25.000,00. Set e Out já começaram; Nov em diante são futuras.
 */
class DistribuicaoDePagamentoTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPagamentosFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    private Matricula $matricula;

    /** @var array<string, Propina> */
    private array $p;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
        ['plano' => $plano, 'matricula' => $this->matricula] = $this->cenarioPropinas();
        $this->p = ['set' => $this->propina($this->matricula, $plano, 1), 'out' => $this->propina($this->matricula, $plano, 2), 'nov' => $this->propina($this->matricula, $plano, 3)];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function previa(int $valor, ?array $linhas = null, int $credito = 0, string $data = '2026-10-16'): PlanoDePagamentoDTO
    {
        return app(DistribuicaoDePagamentoService::class)->preVisualizarPagamento($this->matricula, $this->pedidoPagamento($valor, $linhas, $credito, $data));
    }

    /** @return array<int, array{0: int, 1: int}> propina_id => [crédito, dinheiro] */
    private function porPropina(PlanoDePagamentoDTO $plano): array
    {
        $saida = [];

        foreach ($plano->linhas as $linha) {
            $saida[$linha['propina_id']] = [$linha['credito'], $linha['dinheiro']];
        }

        return $saida;
    }

    private function assertRecusado(string $campo, Closure $operacao): void
    {
        try {
            $operacao();
            $this->fail("Devia recusar em {$campo}.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($campo, $e->errors(), json_encode($e->errors()));
        }
    }

    public function test_explicita_paga_o_saldo_e_o_resto_e_excedente(): void
    {
        $this->regras();
        $plano = $this->previa(3_000_000, [[$this->p['set'], 2_500_000]]);

        $this->assertSame([$this->p['set']->id => [0, 2_500_000]], $this->porPropina($plano));
        $this->assertSame(500_000, $plano->excedente);
        $this->assertSame([['propina_id' => $this->p['set']->id, 'valor' => 2_500_000]], $plano->distribuicoes());
    }

    public function test_sem_pagamento_parcial_a_linha_tem_de_ser_o_saldo_e_com_parcial_pode_ser_menos(): void
    {
        $this->regras(parcial: false);
        $this->assertRecusado('distribuicao.0.valor', fn () => $this->previa(1_000_000, [[$this->p['set'], 1_000_000]]));

        $this->regras(parcial: true);
        $this->assertSame([$this->p['set']->id => [0, 1_000_000]], $this->porPropina($this->previa(1_000_000, [[$this->p['set'], 1_000_000]])));
    }

    public function test_pagar_o_remanescente_de_uma_parcial_e_sempre_permitido(): void
    {
        $this->regras(parcial: false);
        $this->comEstado($this->p['set'], EstadoCobranca::PARCIALMENTE_PAGA, 1_000_000);

        $plano = $this->previa(1_500_000, [[$this->p['set'], 1_500_000]]);

        $this->assertSame([$this->p['set']->id => [0, 1_500_000]], $this->porPropina($plano));
        $this->assertSame(0, $plano->linhas[0]['saldo_depois']);
    }

    public function test_linha_maior_que_o_saldo_ou_soma_maior_que_o_valor_recusa(): void
    {
        $this->regras(parcial: true);
        $this->assertRecusado('distribuicao.0.valor', fn () => $this->previa(3_000_000, [[$this->p['set'], 2_600_000]]));
        $this->assertRecusado('distribuicao', fn () => $this->previa(3_000_000, [[$this->p['set'], 2_500_000], [$this->p['out'], 1_000_000]]));
    }

    public function test_antecipado_depende_da_regra_e_da_data_do_pagamento(): void
    {
        $this->regras(antecipado: false);
        $this->assertRecusado('distribuicao.0.propina_id', fn () => $this->previa(2_500_000, [[$this->p['nov'], 2_500_000]]));

        $this->regras(antecipado: true);
        $this->assertSame([$this->p['nov']->id => [0, 2_500_000]], $this->porPropina($this->previa(2_500_000, [[$this->p['nov'], 2_500_000]])));

        $this->regras(antecipado: false);
        $this->assertRecusado('distribuicao.0.propina_id', fn () => $this->previa(2_500_000, [[$this->p['out'], 2_500_000]], data: '2026-09-30'));
    }

    public function test_propina_de_outra_matricula_paga_cancelada_ou_anulada_nao_e_pagavel(): void
    {
        $this->regras();
        ['plano' => $outroPlano, 'matricula' => $outra] = $this->cenarioPropinasNoMesmoAno();
        $alheia = $this->propina($outra, $outroPlano, 1);
        $paga = $this->comEstado($this->propina($this->matricula, $this->p['set']->plano, 4), EstadoCobranca::PAGA);
        $cancelada = $this->comEstado($this->propina($this->matricula, $this->p['set']->plano, 5), EstadoCobranca::CANCELADA);

        foreach ([$alheia, $paga, $cancelada] as $propina) {
            $this->assertRecusado('distribuicao.0.propina_id', fn () => $this->previa(2_500_000, [[$propina, 2_500_000]]));
        }
    }

    public function test_ids_repetidos_sao_recusados_no_dto(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->pedidoPagamento(5_000_000, [[$this->p['set'], 2_500_000], [$this->p['set'], 2_500_000]]);
    }

    public function test_automatica_paga_da_mais_antiga_para_a_mais_recente_e_para_nas_futuras_sem_antecipado(): void
    {
        $this->regras(parcial: false, antecipado: false);
        $plano = $this->previa(8_000_000);

        $this->assertSame([$this->p['set']->id => [0, 2_500_000], $this->p['out']->id => [0, 2_500_000]], $this->porPropina($plano));
        $this->assertSame(3_000_000, $plano->excedente, 'Novembro é futura: o resto vira crédito.');

        $this->regras(parcial: false, antecipado: true);
        $this->assertSame(500_000, $this->previa(8_000_000)->excedente, 'Com antecipado paga também Novembro.');
    }

    public function test_automatica_sem_parcial_para_na_primeira_que_nao_cabe_e_com_parcial_paga_parte(): void
    {
        $this->regras(parcial: false);
        $plano = $this->previa(3_000_000);
        $this->assertSame([$this->p['set']->id => [0, 2_500_000]], $this->porPropina($plano));
        $this->assertSame(500_000, $plano->excedente, 'Nunca salta Outubro; o resto vai a crédito.');

        $this->regras(parcial: true);
        $plano = $this->previa(3_000_000);
        $this->assertSame([$this->p['set']->id => [0, 2_500_000], $this->p['out']->id => [0, 500_000]], $this->porPropina($plano));
        $this->assertSame(0, $plano->excedente);
    }

    public function test_sem_divida_tudo_e_excedente(): void
    {
        $this->regras();
        foreach ($this->p as $propina) {
            $this->comEstado($propina, EstadoCobranca::PAGA);
        }

        $plano = $this->previa(1_000_000);

        $this->assertSame([], $plano->linhas);
        $this->assertSame(1_000_000, $plano->excedente);
    }

    public function test_credito_e_usado_primeiro_do_mais_antigo_e_pode_cobrir_uma_linha_com_dois_creditos(): void
    {
        $this->regras(parcial: true);
        $antigo = $this->creditoDirecto($this->pagamentoDirecto($this->matricula, 1_000_000), 1_000_000);
        $novo = $this->creditoDirecto($this->pagamentoDirecto($this->matricula, 2_000_000), 2_000_000);

        $plano = $this->previa(2_000_000, null, 3_000_000); // 5.000,00 para Set e Out

        $this->assertSame([$this->p['set']->id => [2_500_000, 0], $this->p['out']->id => [500_000, 2_000_000]], $this->porPropina($plano));
        $this->assertSame([
            ['credito_id' => $antigo->id, 'propina_id' => $this->p['set']->id, 'valor' => 1_000_000],
            ['credito_id' => $novo->id, 'propina_id' => $this->p['set']->id, 'valor' => 1_500_000],
            ['credito_id' => $novo->id, 'propina_id' => $this->p['out']->id, 'valor' => 500_000],
        ], $plano->utilizacoes);
        $this->assertSame(0, $plano->excedente);
    }

    public function test_credito_pedido_acima_do_disponivel_ou_do_distribuido_recusa(): void
    {
        $this->regras(parcial: true);
        $this->creditoDirecto($this->pagamentoDirecto($this->matricula, 1_000_000), 1_000_000);

        $this->assertRecusado('valor_credito', fn () => $this->previa(1_000_000, null, 1_500_000));
        $this->assertRecusado('valor_credito', fn () => $this->previa(1_000_000, [[$this->p['set'], 500_000]], 1_000_000));
    }

    public function test_creditos_de_outro_aluno_nao_contam(): void
    {
        $this->regras(parcial: true);
        ['matricula' => $outra] = $this->cenarioPropinasNoMesmoAno();
        $this->creditoDirecto($this->pagamentoDirecto($outra, 1_000_000), 1_000_000);

        $this->assertRecusado('valor_credito', fn () => $this->previa(1_000_000, null, 500_000));
    }

    public function test_data_futura_recusa_e_a_previsualizacao_nao_escreve(): void
    {
        $this->regras();
        $this->assertRecusado('data_pagamento', fn () => $this->previa(2_500_000, null, 0, '2026-10-17'));

        $antes = [Pagamento::query()->count(), PagamentoPropina::query()->count(), CreditoUtilizacao::query()->count()];
        $this->previa(8_000_000);
        $this->assertSame($antes, [Pagamento::query()->count(), PagamentoPropina::query()->count(), CreditoUtilizacao::query()->count()]);
    }

    public function test_data_nunca_anterior_a_matricula(): void
    {
        $this->regras();
        $this->assertSame('2026-09-01', $this->matricula->data_matricula->toDateString());

        $this->assertSame(2_500_000, $this->previa(2_500_000, null, 0, '2026-09-01')->totalDistribuido(), 'Igual ao limite passa.');

        try {
            $this->previa(2_500_000, null, 0, '2026-08-31');
            $this->fail('Um dia antes da matrícula devia recusar.');
        } catch (ValidationException $e) {
            $this->assertSame(['A data do pagamento não pode ser anterior à data da matrícula (01/09/2026).'], $e->errors()['data_pagamento']);
        }

        $this->assertRecusado('data_pagamento', fn () => $this->previa(2_500_000, null, 0, '2026-10-17'));
    }

    /** @return array{plano: mixed, matricula: Matricula} outra matrícula (outro aluno) noutro plano do mesmo ano */
    private function cenarioPropinasNoMesmoAno(): array
    {
        $plano = $this->p['set']->plano;
        $turma = $this->turma($plano->anoLectivo, $this->nivel('N' . uniqid()));

        return ['plano' => $plano, 'matricula' => $this->matriculaActiva($turma)];
    }
}
```
(`PlanoPropina::anoLectivo()` existe — verificado.)

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/DistribuicaoDePagamentoTest.php`
Expected: FAIL (classes inexistentes).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/DTO/LinhaDeDistribuicao.php`:
```php
<?php

namespace Modules\Financeiro\DTO;

use InvalidArgumentException;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Support\Moeda;

/** Quanto de uma operação (crédito + dinheiro) vai para uma propina. */
final class LinhaDeDistribuicao
{
    public const AUTOMATICA = 'automatica';

    public const EXPLICITA = 'explicita';

    public function __construct(
        public readonly int $propinaId,
        public readonly Dinheiro $valor,
    ) {
        if ($propinaId <= 0 || $valor->unidadesMenores() <= 0) {
            throw new InvalidArgumentException('Cada linha da distribuição indica uma propina e um valor maior que zero.');
        }
    }

    /**
     * @param  array<mixed>  $linhas
     * @return list<self>
     */
    public static function lista(array $linhas): array
    {
        $vistas = [];

        foreach ($linhas as $linha) {
            if (! $linha instanceof self) {
                throw new InvalidArgumentException('A distribuição é uma lista de ' . self::class . '.');
            }

            if (isset($vistas[$linha->propinaId])) {
                throw new InvalidArgumentException('Cada propina só pode aparecer uma vez na distribuição.');
            }

            $vistas[$linha->propinaId] = true;
        }

        return array_values($linhas);
    }

    /**
     * Linhas do pedido HTTP (`[{propina_id, valor}]`, valor decimal na moeda do tenant). O campo `modo_distribuicao`
     * decide: 'automatica' → null (o motor escolhe); 'explicita' → a lista, que PODE ser vazia (nada para propinas,
     * tudo crédito). Assim o que o resumo mostrou é exactamente o que se grava (D19), mesmo sem linhas.
     *
     * @return list<self>|null
     */
    public static function doPedido(string $modo, mixed $linhas, Moeda $moeda): ?array
    {
        if ($modo !== self::EXPLICITA) {
            return null;
        }

        return self::lista(array_map(
            fn (array $linha) => new self((int) $linha['propina_id'], Dinheiro::deDecimal((string) $linha['valor'], $moeda)),
            array_values(is_array($linhas) ? $linhas : []),
        ));
    }
}
```

`Modules/Financeiro/app/DTO/RegistarPagamentoDTO.php`:
```php
<?php

namespace Modules\Financeiro\DTO;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Support\Dinheiro;

final class RegistarPagamentoDTO
{
    public const REFERENCIA_MAXIMA = 100;

    public const OBSERVACAO_MAXIMA = 1000;

    public readonly ?string $referencia;

    public readonly ?string $observacao;

    /** @var list<LinhaDeDistribuicao>|null null = distribuição automática */
    public readonly ?array $distribuicao;

    public readonly Dinheiro $valorCredito;

    /**
     * @param  list<LinhaDeDistribuicao>|null  $distribuicao
     */
    public function __construct(
        public readonly int $metodoPagamentoId,
        public readonly Dinheiro $valor,
        public readonly CarbonImmutable $dataPagamento,
        ?string $referencia = null,
        ?string $observacao = null,
        ?array $distribuicao = null,
        ?Dinheiro $valorCredito = null,
    ) {
        if ($valor->unidadesMenores() <= 0) {
            throw new InvalidArgumentException('O valor do pagamento tem de ser maior que zero.');
        }

        $referencia = $referencia === null ? null : trim($referencia);
        $observacao = $observacao === null ? null : trim($observacao);

        if ($referencia !== null && mb_strlen($referencia) > self::REFERENCIA_MAXIMA) {
            throw new InvalidArgumentException('A referência tem no máximo ' . self::REFERENCIA_MAXIMA . ' caracteres.');
        }

        if ($observacao !== null && mb_strlen($observacao) > self::OBSERVACAO_MAXIMA) {
            throw new InvalidArgumentException('A observação tem no máximo ' . self::OBSERVACAO_MAXIMA . ' caracteres.');
        }

        $this->referencia = $referencia === '' ? null : $referencia;
        $this->observacao = $observacao === '' ? null : $observacao;
        $this->distribuicao = $distribuicao === null ? null : LinhaDeDistribuicao::lista($distribuicao);
        $this->valorCredito = $valorCredito ?? Dinheiro::deUnidadesMenores(0);
    }

    public static function fromRequest(FormRequest $request): self
    {
        $moeda = app(MoedaDoTenant::class)->atual();
        $dados = $request->validated();

        return new self(
            metodoPagamentoId: (int) $dados['metodo_pagamento_id'],
            valor: Dinheiro::deDecimal((string) $dados['valor'], $moeda),
            dataPagamento: CarbonImmutable::createFromFormat('!Y-m-d', (string) $dados['data_pagamento']),
            referencia: $dados['referencia'] ?? null,
            observacao: $dados['observacao'] ?? null,
            distribuicao: LinhaDeDistribuicao::doPedido((string) $dados['modo_distribuicao'], $dados['distribuicao'] ?? [], $moeda),
            valorCredito: Dinheiro::deDecimal((string) ($dados['valor_credito'] ?? '0'), $moeda),
        );
    }
}
```

`Modules/Financeiro/app/DTO/AplicarCreditoDTO.php`:
```php
<?php

namespace Modules\Financeiro\DTO;

use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Support\Dinheiro;

/** Usar crédito do aluno sem dinheiro novo (D12). */
final class AplicarCreditoDTO
{
    /** @var list<LinhaDeDistribuicao>|null */
    public readonly ?array $distribuicao;

    /**
     * @param  list<LinhaDeDistribuicao>|null  $distribuicao
     */
    public function __construct(
        public readonly Dinheiro $valorCredito,
        ?array $distribuicao = null,
    ) {
        if ($valorCredito->unidadesMenores() <= 0) {
            throw new InvalidArgumentException('O crédito a aplicar tem de ser maior que zero.');
        }

        $this->distribuicao = $distribuicao === null ? null : LinhaDeDistribuicao::lista($distribuicao);
    }

    public static function fromRequest(FormRequest $request): self
    {
        $moeda = app(MoedaDoTenant::class)->atual();

        return new self(
            Dinheiro::deDecimal((string) $request->validated('valor_credito'), $moeda),
            LinhaDeDistribuicao::doPedido((string) $request->validated('modo_distribuicao'), $request->validated('distribuicao') ?? [], $moeda),
        );
    }
}
```

`Modules/Financeiro/app/DTO/MotivoDeAnulacaoDTO.php`:
```php
<?php

namespace Modules\Financeiro\DTO;

use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;

/** Motivo obrigatório de qualquer anulação (pagamento, utilização de crédito, propina). */
final class MotivoDeAnulacaoDTO
{
    public const MINIMO = 5;

    public const MAXIMO = 500;

    public readonly string $motivo;

    public function __construct(string $motivo)
    {
        $motivo = trim($motivo);
        $tamanho = mb_strlen($motivo);

        if ($tamanho < self::MINIMO || $tamanho > self::MAXIMO) {
            throw new InvalidArgumentException('O motivo da anulação tem de ter entre ' . self::MINIMO . ' e ' . self::MAXIMO . ' caracteres.');
        }

        $this->motivo = $motivo;
    }

    public static function fromRequest(FormRequest $request): self
    {
        return new self((string) $request->validated('motivo'));
    }
}
```

`Modules/Financeiro/app/DTO/PlanoDePagamentoDTO.php`:
```php
<?php

namespace Modules\Financeiro\DTO;

/**
 * Resultado do motor de distribuição (unidades menores). Cada linha diz quanto de crédito e de dinheiro novo vai
 * para uma propina; `utilizacoes` diz de que créditos sai o crédito; `excedente` é o dinheiro que vira crédito novo.
 * Invariante: dinheiro = Σ linhas.dinheiro + excedente; credito = Σ linhas.credito = Σ utilizacoes.valor.
 */
final class PlanoDePagamentoDTO
{
    /**
     * @param  list<array{propina_id: int, periodo: string, periodo_inicio: string, saldo_antes: int, credito: int, dinheiro: int, saldo_depois: int}>  $linhas
     * @param  list<array{credito_id: int, propina_id: int, valor: int}>  $utilizacoes
     */
    public function __construct(
        public readonly int $dinheiro,
        public readonly int $credito,
        public readonly array $linhas,
        public readonly array $utilizacoes,
        public readonly int $excedente,
    ) {
    }

    /** @return list<array{propina_id: int, valor: int}> as partes pagas com dinheiro novo (pagamento_propinas) */
    public function distribuicoes(): array
    {
        $saida = [];

        foreach ($this->linhas as $linha) {
            if ($linha['dinheiro'] > 0) {
                $saida[] = ['propina_id' => $linha['propina_id'], 'valor' => $linha['dinheiro']];
            }
        }

        return $saida;
    }

    public function totalDistribuido(): int
    {
        return array_sum(array_map(fn (array $linha) => $linha['credito'] + $linha['dinheiro'], $this->linhas));
    }

    /** @return list<int> */
    public function propinasTocadas(): array
    {
        return array_values(array_unique(array_column($this->linhas, 'propina_id')));
    }

    /** @return list<int> */
    public function creditosUsados(): array
    {
        return array_values(array_unique(array_column($this->utilizacoes, 'credito_id')));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'dinheiro' => $this->dinheiro,
            'credito' => $this->credito,
            'linhas' => $this->linhas,
            'utilizacoes' => $this->utilizacoes,
            'excedente' => $this->excedente,
            'total_distribuido' => $this->totalDistribuido(),
        ];
    }
}
```

`Modules/Financeiro/app/Services/DistribuicaoDePagamentoService.php`:
```php
<?php

namespace Modules\Financeiro\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\DTO\AplicarCreditoDTO;
use Modules\Financeiro\DTO\LinhaDeDistribuicao;
use Modules\Financeiro\DTO\PlanoDePagamentoDTO;
use Modules\Financeiro\DTO\RegistarPagamentoDTO;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\EstadoCredito;
use Modules\Financeiro\Models\Credito;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Support\RotuloDePeriodo;
use Modules\Matricula\Models\Matricula;

/**
 * Motor ÚNICO da divisão de uma operação de pagamento (spec Pagamentos §4; D8–D12). Só lê. Usado pela
 * pré-visualização (dados sem bloqueio) e pelas Actions (dados já bloqueados), por isso tudo o que decide vem dos
 * argumentos de `calcular`. Ordem: crédito primeiro, depois dinheiro novo, da propina mais antiga para a mais recente.
 * Pagamentos-B: depois do capital de cada propina, a multa dela (imputação capital → multa) — ponto marcado abaixo.
 */
class DistribuicaoDePagamentoService
{
    public function __construct(
        private RelogioDoTenant $relogio,
        private MoedaDoTenant $moedaDoTenant,
    ) {
    }

    public function preVisualizarPagamento(Matricula $matricula, RegistarPagamentoDTO $dto): PlanoDePagamentoDTO
    {
        $this->validarData($dto->dataPagamento, $matricula);

        return $this->calcular(
            $dto->valor->unidadesMenores(),
            $dto->valorCredito->unidadesMenores(),
            $dto->dataPagamento,
            $dto->distribuicao,
            $this->propinasPagaveis($matricula),
            $dto->valorCredito->unidadesMenores() > 0 ? $this->creditosDisponiveis($matricula) : new Collection(),
            RegraCobranca::doTenant(),
        );
    }

    public function preVisualizarCredito(Matricula $matricula, AplicarCreditoDTO $dto): PlanoDePagamentoDTO
    {
        return $this->calcular(
            0,
            $dto->valorCredito->unidadesMenores(),
            $this->relogio->hoje(),
            $dto->distribuicao,
            $this->propinasPagaveis($matricula),
            $this->creditosDisponiveis($matricula),
            RegraCobranca::doTenant(),
        );
    }

    /** @return Collection<int, Propina> Em Aberto ou Parcialmente Paga, da matrícula (sem bloqueio). */
    public function propinasPagaveis(Matricula $matricula): Collection
    {
        return Propina::query()
            ->where('matricula_id', $matricula->getKey())
            ->whereIn('estado', [EstadoCobranca::EM_ABERTO->value, EstadoCobranca::PARCIALMENTE_PAGA->value])
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, Credito> créditos Disponíveis do aluno da matrícula, do mais antigo para o mais recente. */
    public function creditosDisponiveis(Matricula $matricula): Collection
    {
        return Credito::query()
            ->where('aluno_id', $matricula->aluno_id)
            ->where('estado', EstadoCredito::DISPONIVEL->value)
            ->orderBy('id')
            ->get();
    }

    /**
     * O3 (decidida pelo dono): nem futura nem anterior à data da matrícula. Nas Actions a matrícula chega já bloqueada
     * (`data_matricula` relida sob o lock); na pré-visualização é a lida sem bloqueio.
     */
    public function validarData(CarbonImmutable $data, Matricula $matricula): void
    {
        if ($data->toDateString() > $this->relogio->hoje()->toDateString()) {
            throw ValidationException::withMessages(['data_pagamento' => 'A data do pagamento não pode ser futura.']);
        }

        $inicio = $matricula->data_matricula;

        if ($inicio !== null && $data->toDateString() < $inicio->toDateString()) {
            throw ValidationException::withMessages(['data_pagamento' => 'A data do pagamento não pode ser anterior à data da matrícula (' . $inicio->format('d/m/Y') . ').']);
        }
    }

    /**
     * @param  list<LinhaDeDistribuicao>|null  $distribuicao  null = automática
     * @param  Collection<int, Propina>  $propinas  da matrícula (filtradas e ordenadas aqui)
     * @param  Collection<int, Credito>  $creditos  do aluno (filtrados e ordenados aqui)
     */
    public function calcular(
        int $dinheiro,
        int $creditoPedido,
        CarbonImmutable $dataEfeito,
        ?array $distribuicao,
        Collection $propinas,
        Collection $creditos,
        RegraCobranca $regras,
    ): PlanoDePagamentoDTO {
        $pagaveis = $propinas
            ->filter(fn (Propina $p) => in_array($p->estado, [EstadoCobranca::EM_ABERTO, EstadoCobranca::PARCIALMENTE_PAGA], true)
                && $p->saldo()->unidadesMenores() > 0)
            ->sort(fn (Propina $a, Propina $b) => [$a->periodo_inicio->toDateString(), $a->ordem, $a->id] <=> [$b->periodo_inicio->toDateString(), $b->ordem, $b->id])
            ->values();
        $total = $dinheiro + $creditoPedido;

        $alvos = $distribuicao === null
            ? $this->automatica($pagaveis, $total, $dataEfeito, $regras)
            : $this->explicita($distribuicao, $pagaveis, $total, $dataEfeito, $regras);
        $somaAlvos = array_sum(array_column($alvos, 'valor'));

        $fila = $creditos
            ->filter(fn (Credito $c) => $c->estado === EstadoCredito::DISPONIVEL && $c->saldo()->unidadesMenores() > 0)
            ->sortBy('id')
            ->map(fn (Credito $c) => ['id' => (int) $c->id, 'saldo' => $c->saldo()->unidadesMenores()])
            ->values()
            ->all();
        $disponivel = array_sum(array_column($fila, 'saldo'));

        if ($creditoPedido > $disponivel) {
            throw ValidationException::withMessages(['valor_credito' => 'O aluno só tem ' . $this->formatar($disponivel) . ' de crédito disponível.']);
        }

        if ($creditoPedido > $somaAlvos) {
            throw ValidationException::withMessages([
                'valor_credito' => 'O crédito a usar (' . $this->formatar($creditoPedido) . ') é maior do que o total a distribuir pelas propinas (' . $this->formatar($somaAlvos) . ').',
            ]);
        }

        $restanteCredito = $creditoPedido;
        $linhas = [];
        $utilizacoes = [];

        foreach ($alvos as ['propina' => $propina, 'valor' => $valor]) {
            $doCredito = min($restanteCredito, $valor);
            $restanteCredito -= $doCredito;
            $porCobrir = $doCredito;

            while ($porCobrir > 0) {
                $uso = min($fila[0]['saldo'], $porCobrir);
                $utilizacoes[] = ['credito_id' => $fila[0]['id'], 'propina_id' => (int) $propina->id, 'valor' => $uso];
                $fila[0]['saldo'] -= $uso;
                $porCobrir -= $uso;

                if ($fila[0]['saldo'] === 0) {
                    array_shift($fila);
                }
            }

            $saldo = $propina->saldo()->unidadesMenores();
            $inicio = $propina->periodo_inicio->toDateString();
            $linhas[] = [
                'propina_id' => (int) $propina->id,
                'periodo' => RotuloDePeriodo::periodo($inicio, $propina->periodo_fim->toDateString()),
                'periodo_inicio' => $inicio,
                'saldo_antes' => $saldo,
                'credito' => $doCredito,
                'dinheiro' => $valor - $doCredito,
                'saldo_depois' => $saldo - $valor,
            ];

            // Pagamentos-B: aqui, com o capital desta propina liquidado, a multa dela (imputação capital → multa).
        }

        $dinheiroDistribuido = array_sum(array_column($linhas, 'dinheiro'));

        return new PlanoDePagamentoDTO($dinheiro, $creditoPedido, $linhas, $utilizacoes, $dinheiro - $dinheiroDistribuido);
    }

    /**
     * @param  Collection<int, Propina>  $pagaveis  ordenadas
     * @return list<array{propina: Propina, valor: int}>
     */
    private function automatica(Collection $pagaveis, int $total, CarbonImmutable $dataEfeito, RegraCobranca $regras): array
    {
        $alvos = [];
        $restante = $total;

        foreach ($pagaveis as $propina) {
            if ($restante === 0) {
                break;
            }

            // D9: as seguintes também são futuras (ordenadas por período): sem antecipado, a lista termina aqui.
            if ($propina->periodo_inicio->toDateString() > $dataEfeito->toDateString() && ! $regras->permite_pagamento_antecipado) {
                break;
            }

            $saldo = $propina->saldo()->unidadesMenores();

            if ($restante >= $saldo) {
                $alvos[] = ['propina' => $propina, 'valor' => $saldo];
                $restante -= $saldo;

                continue;
            }

            if ($regras->permite_pagamento_parcial) {
                $alvos[] = ['propina' => $propina, 'valor' => $restante];
            }

            // Sem parcial: nunca se salta uma propina mais antiga para pagar uma mais recente (o resto vira crédito).
            break;
        }

        return $alvos;
    }

    /**
     * @param  list<LinhaDeDistribuicao>  $distribuicao
     * @param  Collection<int, Propina>  $pagaveis  ordenadas
     * @return list<array{propina: Propina, valor: int}>
     */
    private function explicita(array $distribuicao, Collection $pagaveis, int $total, CarbonImmutable $dataEfeito, RegraCobranca $regras): array
    {
        $porId = $pagaveis->keyBy('id');
        $posicao = $pagaveis->pluck('id')->flip();
        $alvos = [];

        foreach (array_values($distribuicao) as $i => $linha) {
            $propina = $porId->get($linha->propinaId) ?? throw ValidationException::withMessages([
                "distribuicao.{$i}.propina_id" => 'A propina indicada não é desta matrícula ou já não está em dívida (paga, cancelada ou anulada).',
            ]);
            $valor = $linha->valor->unidadesMenores();
            $saldo = $propina->saldo()->unidadesMenores();

            if ($valor > $saldo) {
                throw ValidationException::withMessages(["distribuicao.{$i}.valor" => 'O valor excede o saldo em dívida desta propina (' . $this->formatar($saldo) . ').']);
            }

            if (! $regras->permite_pagamento_parcial && $valor !== $saldo) {
                throw ValidationException::withMessages(["distribuicao.{$i}.valor" => 'A escola não permite pagamento parcial: pague o saldo inteiro desta propina (' . $this->formatar($saldo) . ').']);
            }

            if ($propina->periodo_inicio->toDateString() > $dataEfeito->toDateString() && ! $regras->permite_pagamento_antecipado) {
                throw ValidationException::withMessages(["distribuicao.{$i}.propina_id" => 'A escola não permite pagamento antecipado: esta propina ainda não tinha começado na data do pagamento.']);
            }

            $alvos[] = ['propina' => $propina, 'valor' => $valor];
        }

        if (array_sum(array_column($alvos, 'valor')) > $total) {
            throw ValidationException::withMessages(['distribuicao' => 'A distribuição excede o valor do pagamento mais o crédito usado (' . $this->formatar($total) . ').']);
        }

        // Crédito primeiro na propina mais antiga: as linhas seguem a ordem das propinas, não a do pedido.
        usort($alvos, fn (array $a, array $b) => $posicao[$a['propina']->id] <=> $posicao[$b['propina']->id]);

        return $alvos;
    }

    private function formatar(int $unidades): string
    {
        return Dinheiro::deUnidadesMenores($unidades)->formatar($this->moedaDoTenant->atual());
    }
}
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Feature/DistribuicaoDePagamentoTest.php tests/Feature/Arquitectura/RelogioDoTenantArquitecturaTest.php`
Expected: PASS.

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/DTO/LinhaDeDistribuicao.php Modules/Financeiro/app/DTO/RegistarPagamentoDTO.php Modules/Financeiro/app/DTO/AplicarCreditoDTO.php Modules/Financeiro/app/DTO/MotivoDeAnulacaoDTO.php Modules/Financeiro/app/DTO/PlanoDePagamentoDTO.php Modules/Financeiro/app/Services/DistribuicaoDePagamentoService.php Modules/Financeiro/tests/Concerns/ComPagamentosFinanceiro.php Modules/Financeiro/tests/Feature/DistribuicaoDePagamentoTest.php
```

---

### Task 7: `RegistarPagamentoAction` — uma transacção, ordem de bloqueio, numeração, crédito (D3–D11, D13)

**Files:**
- Create: `Modules/Financeiro/app/Support/BloqueioDePagamentos.php`
- Modify: `Modules/Financeiro/app/Support/ViolacaoDeUnicidade.php` (+ `daReferenciaDoPagamento`)
- Create: `Modules/Financeiro/app/Actions/RegistarPagamentoAction.php`
- Modify: `Modules/Financeiro/tests/Concerns/ComPagamentosFinanceiro.php` (+ `pagar()`, `assertPagamentosCoerentes()`)
- Create: `Modules/Financeiro/tests/Feature/RegistarPagamentoTest.php`

**Interfaces:**
- Consumes: `DistribuicaoDePagamentoService::calcular/propinasPagaveis/creditosDisponiveis/validarData` (T6), `BloqueioDePropinas::bloquear`, `RecalcularPropina::executarVarias`, `RecalcularCredito::executar` (T4), `GeradorSequencia::gerar(PagamentoSequencia::class, $ano)` (T2), `SnapshotMonetario::em`, `RelogioDoTenant`, `MetodoPagamento`, `RegraCobranca::doTenant()`.
- Produces (a Action valida a data com `validarData($data, $matriculaBloqueada)`; as utilizações criadas no mesmo acto levam `pagamento_id` = o pagamento novo, O8):
  - `BloqueioDePagamentos::matricula(int $id, bool $incluirEliminadas = false): ?Matricula`, `pagamento(int $id): ?Pagamento`, `creditos(list<int> $ids): Collection<int, Credito>` (id crescente), `utilizacao(int $id): ?CreditoUtilizacao` — todos `FOR UPDATE`, exigem transacção.
  - `ViolacaoDeUnicidade::daReferenciaDoPagamento(Throwable): bool`.
  - `RegistarPagamentoAction::executar(Matricula $matricula, RegistarPagamentoDTO $dto): Pagamento` (com `distribuicoes` e `credito` carregados); `RegistarPagamentoAction::MENSAGEM_REFERENCIA`.
  - Helpers: `pagar(Matricula $matricula, int $valor, ?array $linhas = null, int $credito = 0, string $data = '2026-10-16', ?string $referencia = null): Pagamento`, `assertPagamentosCoerentes(): void` (reconciliação global: pagamentos, créditos e propinas).

- [ ] **Step 1: Escrever os testes**

Em `ComPagamentosFinanceiro` acrescentar (com `use Modules\Financeiro\Actions\RegistarPagamentoAction;`, `use Modules\Financeiro\Enums\EstadoCredito;`, `use Modules\Financeiro\Enums\EstadoPagamento;`, `use Modules\Financeiro\Enums\EstadoUtilizacaoCredito;`):
```php
    /**
     * Regista pela Action real (distribuição, crédito, recálculo).
     *
     * @param  list<array{0: Propina|int, 1: int}>|null  $linhas  null = automática
     */
    protected function pagar(Matricula $matricula, int $valor, ?array $linhas = null, int $credito = 0, string $data = '2026-10-16', ?string $referencia = null): Pagamento
    {
        return app(RegistarPagamentoAction::class)->executar($matricula->fresh(), $this->pedidoPagamento($valor, $linhas, $credito, $data, $referencia));
    }

    /**
     * Reconciliação global de Pagamentos-A (critério 8.2/1 do plano de fases), sobre TODO o tenant corrente:
     * - pagamento Confirmado: valor = Σ distribuições + crédito gerado; Anulado: o crédito gerado (se existe) está Anulado;
     * - crédito: valor_utilizado = Σ utilizações Activas, estado coerente, aluno = aluno do pagamento de origem;
     * - propina: valor_pago = Σ distribuições de pagamentos Confirmados + Σ utilizações Activas.
     */
    protected function assertPagamentosCoerentes(): void
    {
        foreach (Pagamento::query()->with(['distribuicoes', 'credito'])->orderBy('id')->get() as $pagamento) {
            $id = "pagamento {$pagamento->numero}";
            $distribuido = $pagamento->distribuicoes->sum(fn ($d) => $d->valor->unidadesMenores());
            $gerado = $pagamento->credito?->valor->unidadesMenores() ?? 0;

            $this->assertSame($pagamento->valor->unidadesMenores(), $distribuido + $gerado, "{$id}: valor = Σ distribuições + crédito gerado");

            if ($pagamento->estado === EstadoPagamento::ANULADO && $pagamento->credito !== null) {
                $this->assertSame(EstadoCredito::ANULADO, $pagamento->credito->estado, "{$id}: o crédito de um pagamento anulado está anulado");
            }
        }

        foreach (Credito::query()->with(['utilizacoes', 'origem'])->orderBy('id')->get() as $credito) {
            $activas = $credito->utilizacoes->where('estado', EstadoUtilizacaoCredito::ACTIVA)->sum(fn ($u) => $u->valor->unidadesMenores());

            $this->assertSame($activas, $credito->valor_utilizado->unidadesMenores(), "crédito {$credito->id}: valor_utilizado");
            $this->assertSame((int) $credito->origem->aluno_id, (int) $credito->aluno_id, "crédito {$credito->id}: aluno");

            foreach ($credito->utilizacoes->whereNotNull('pagamento_id') as $u) {
                $this->assertSame((int) $credito->aluno_id, (int) Pagamento::query()->findOrFail($u->pagamento_id)->aluno_id, "utilização {$u->id}: o pagamento combinado é do aluno do crédito (O8)");
            }
        }

        foreach (Propina::query()->orderBy('id')->get() as $propina) {
            $pago = PagamentoPropina::query()->where('propina_id', $propina->id)
                ->whereHas('pagamento', fn ($p) => $p->where('estado', EstadoPagamento::CONFIRMADO->value))->get()
                ->sum(fn ($d) => $d->valor->unidadesMenores())
                + CreditoUtilizacao::query()->where('propina_id', $propina->id)->where('estado', EstadoUtilizacaoCredito::ACTIVA->value)->get()
                    ->sum(fn ($u) => $u->valor->unidadesMenores());

            $this->assertSame($pago, $propina->valor_pago->unidadesMenores(), "propina {$propina->id}: valor_pago = Σ pagamentos confirmados + créditos activos");
        }

        $this->assertPropinasCoerentes();
    }
```

`Modules/Financeiro/tests/Feature/RegistarPagamentoTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Actions\RegistarPagamentoAction;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\EstadoCredito;
use Modules\Financeiro\Enums\EstadoPagamento;
use Modules\Financeiro\Models\Credito;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Models\Pagamento;
use Modules\Financeiro\Models\PagamentoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use RuntimeException;
use Tests\TestCase;

class RegistarPagamentoTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPagamentosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    private Matricula $matricula;

    /** @var array<string, Propina> */
    private array $p;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
        ['plano' => $plano, 'matricula' => $this->matricula] = $this->cenarioPropinas();
        $this->p = ['set' => $this->propina($this->matricula, $plano, 1), 'out' => $this->propina($this->matricula, $plano, 2), 'nov' => $this->propina($this->matricula, $plano, 3)];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function contagens(): array
    {
        return [Pagamento::query()->count(), PagamentoPropina::query()->count(), Credito::query()->count(), CreditoUtilizacao::query()->count()];
    }

    private function assertRecusado(string $campo, Closure $operacao): void
    {
        $antes = $this->contagens();

        try {
            $operacao();
            $this->fail("Devia recusar em {$campo}.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($campo, $e->errors(), json_encode($e->errors()));
        }

        $this->assertSame($antes, $this->contagens(), 'Nada gravado.');
    }

    public function test_regista_parcial_com_numero_snapshot_autor_e_recalculo(): void
    {
        $this->regras(parcial: true);
        $admin = $this->adminEscola();
        $this->actingAs($admin);

        $pagamento = $this->pagar($this->matricula, 1_000_000, [[$this->p['set'], 1_000_000]], referencia: '  TRF-1  ');

        $this->assertSame('2026-0001', $pagamento->numero);
        $this->assertSame(EstadoPagamento::CONFIRMADO, $pagamento->estado);
        $this->assertSame('TRF-1', $pagamento->referencia);
        $this->assertSame(app(MoedaDoTenant::class)->atual()->codigo, $pagamento->moeda);
        $this->assertSame((int) $this->matricula->aluno_id, $pagamento->aluno_id);
        $this->assertSame($admin->id, (int) $pagamento->criado_por);
        $this->assertCount(1, $pagamento->distribuicoes);
        $this->assertNull($pagamento->credito);

        $set = $this->p['set']->fresh();
        $this->assertSame(EstadoCobranca::PARCIALMENTE_PAGA, $set->estado);
        $this->assertSame(1_000_000, $set->valor_pago->unidadesMenores());
        $this->assertPagamentosCoerentes();
    }

    public function test_pagamento_com_data_anterior_liquida_o_capital_nessa_data(): void
    {
        $this->regras();

        $this->pagar($this->matricula, 2_500_000, [[$this->p['set'], 2_500_000]], data: '2026-09-08');

        $set = $this->p['set']->fresh();
        $this->assertSame(EstadoCobranca::PAGA, $set->estado);
        $this->assertSame('2026-09-08', $set->capital_liquidado_em->toDateString());
        $this->assertPagamentosCoerentes();
    }

    public function test_automatica_com_excedente_gera_credito_do_aluno(): void
    {
        $this->regras();

        $pagamento = $this->pagar($this->matricula, 6_000_000);

        $this->assertSame([$this->p['set']->id, $this->p['out']->id], $pagamento->distribuicoes->pluck('propina_id')->all());
        $this->assertSame(1_000_000, $pagamento->credito->valor->unidadesMenores());
        $this->assertSame(EstadoCredito::DISPONIVEL, $pagamento->credito->estado);
        $this->assertSame((int) $this->matricula->aluno_id, (int) $pagamento->credito->aluno_id);
        $this->assertSame(EstadoCobranca::EM_ABERTO, $this->p['nov']->fresh()->estado, 'Novembro é futura e a escola não permite antecipado.');
        $this->assertPagamentosCoerentes();
    }

    public function test_usar_credito_primeiro_e_dinheiro_novo_depois(): void
    {
        $this->regras(parcial: true, antecipado: true);
        $origem = $this->pagar($this->matricula, 6_000_000, [[$this->p['set'], 2_500_000], [$this->p['out'], 2_500_000]]); // 1.000.000 de crédito

        $pagamento = $this->pagar($this->matricula, 1_500_000, [[$this->p['nov'], 2_500_000]], credito: 1_000_000);

        $this->assertSame(1_500_000, $pagamento->distribuicoes->sole()->valor->unidadesMenores(), 'Só o dinheiro novo entra no pagamento.');
        $this->assertNull($pagamento->credito, 'Sem excedente.');
        $utilizacao = CreditoUtilizacao::query()->sole();
        $this->assertSame((int) $pagamento->id, (int) $utilizacao->pagamento_id, 'O8: a utilização guarda o pagamento do mesmo acto.');
        $this->assertSame([(int) $origem->credito->id, $this->p['nov']->id, 1_000_000, '2026-10-16'], [(int) $utilizacao->credito_id, (int) $utilizacao->propina_id, $utilizacao->valor->unidadesMenores(), $utilizacao->data_utilizacao->toDateString()]);
        $this->assertSame(EstadoCredito::ESGOTADO, $origem->credito->fresh()->estado);
        $this->assertSame(EstadoCobranca::PAGA, $this->p['nov']->fresh()->estado);
        $this->assertPagamentosCoerentes();
    }

    public function test_data_do_pagamento_nunca_anterior_a_matricula_nem_futura(): void
    {
        $this->regras();
        $this->assertSame('2026-09-01', $this->matricula->data_matricula->toDateString());

        $this->assertRecusado('data_pagamento', fn () => $this->pagar($this->matricula, 2_500_000, [[$this->p['set'], 2_500_000]], data: '2026-08-31'));
        $this->assertRecusado('data_pagamento', fn () => $this->pagar($this->matricula, 2_500_000, [[$this->p['set'], 2_500_000]], data: '2026-10-17'));

        $limite = $this->pagar($this->matricula, 2_500_000, [[$this->p['set'], 2_500_000]], data: '2026-09-01');

        $this->assertSame('2026-09-01', $limite->data_pagamento->toDateString(), 'Igual à data da matrícula passa.');
        $this->assertSame('2026-09-01', $this->p['set']->fresh()->capital_liquidado_em->toDateString());
        $this->assertSame('2026-0001', $limite->numero, 'As recusas não consomem número.');
        $this->assertPagamentosCoerentes();
    }

    public function test_a_data_valida_se_com_a_data_da_matricula_relida_sob_o_lock(): void
    {
        $this->regras();
        $obsoleta = $this->matricula->fresh(); // cópia com data_matricula 2026-09-01
        Matricula::query()->whereKey($this->matricula->id)->update(['data_matricula' => '2026-09-20']);

        $this->assertRecusado('data_pagamento', fn () => app(RegistarPagamentoAction::class)->executar(
            $obsoleta,
            $this->pedidoPagamento(2_500_000, [[$this->p['set'], 2_500_000]], data: '2026-09-10'),
        ));
    }

    public function test_numeracao_sequencial_sem_lacunas_mesmo_com_recusas(): void
    {
        $this->regras(parcial: true);

        $this->assertSame('2026-0001', $this->pagar($this->matricula, 100_000, [[$this->p['set'], 100_000]])->numero);
        $this->assertRecusado('data_pagamento', fn () => $this->pagar($this->matricula, 100_000, [[$this->p['set'], 100_000]], data: '2026-10-17'));
        $this->assertRecusado('distribuicao.0.valor', fn () => $this->pagar($this->matricula, 9_000_000, [[$this->p['set'], 9_000_000]]));
        $this->assertSame('2026-0002', $this->pagar($this->matricula, 100_000, [[$this->p['set'], 100_000]])->numero);
    }

    public function test_o_ano_do_numero_e_o_do_dia_da_escola(): void
    {
        $this->regras();
        Carbon::setTestNow(CarbonImmutable::parse('2026-12-31 23:30:00', 'UTC')); // 01/01/2027 00:30 em Luanda

        $this->assertSame('2027-0001', $this->pagar($this->matricula, 2_500_000, [[$this->p['set'], 2_500_000]], data: '2027-01-01')->numero);
    }

    public function test_metodo_inactivo_ou_inexistente_recusa(): void
    {
        $this->regras();
        $inactivo = $this->metodoPagamento('Cheque', activo: false);

        $this->assertRecusado('metodo_pagamento_id', fn () => app(RegistarPagamentoAction::class)->executar(
            $this->matricula,
            $this->pedidoPagamento(2_500_000, [[$this->p['set'], 2_500_000]], metodoId: $inactivo->id),
        ));
        $this->assertRecusado('metodo_pagamento_id', fn () => app(RegistarPagamentoAction::class)->executar(
            $this->matricula,
            $this->pedidoPagamento(2_500_000, [[$this->p['set'], 2_500_000]], metodoId: 999_999),
        ));
    }

    public function test_referencia_duplicada_no_mesmo_metodo_recusa_noutro_metodo_passa(): void
    {
        $this->regras(parcial: true);
        $this->pagar($this->matricula, 100_000, [[$this->p['set'], 100_000]], referencia: 'TRF-9');

        $this->assertRecusado('referencia', fn () => $this->pagar($this->matricula, 100_000, [[$this->p['set'], 100_000]], referencia: 'TRF-9'));

        app(RegistarPagamentoAction::class)->executar(
            $this->matricula,
            $this->pedidoPagamento(100_000, [[$this->p['set'], 100_000]], referencia: 'TRF-9', metodoId: $this->metodoPagamento('TPA')->id),
        );
        $this->assertSame(2, Pagamento::query()->count());
    }

    public function test_segundo_registo_explicito_da_mesma_propina_e_recusado(): void
    {
        $this->regras();
        $this->pagar($this->matricula, 2_500_000, [[$this->p['set'], 2_500_000]]);

        $this->assertRecusado('distribuicao.0.propina_id', fn () => $this->pagar($this->matricula, 2_500_000, [[$this->p['set'], 2_500_000]]));
    }

    public function test_falha_a_meio_reverte_tudo_e_nao_consome_numero(): void
    {
        $this->regras();
        $falhar = true;
        Credito::creating(function () use (&$falhar) {
            if ($falhar) {
                throw new RuntimeException('Falha injectada: crédito');
            }
        });

        try {
            $this->pagar($this->matricula, 6_000_000);
            $this->fail('A falha devia propagar.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame([0, 0, 0, 0], $this->contagens());
        $this->assertSame(EstadoCobranca::EM_ABERTO, $this->p['set']->fresh()->estado);
        $falhar = false;
        $this->assertSame('2026-0001', $this->pagar($this->matricula, 2_500_000, [[$this->p['set'], 2_500_000]])->numero, 'O número não foi consumido.');
    }

    public function test_matricula_eliminada_nao_recebe_pagamentos(): void
    {
        $this->regras();
        $pendente = $this->matriculaActiva($this->p['set']->matricula->turma, '2026-09-01', EstadoMatriculaEnum::PENDENTE);
        $pendente->delete();

        $this->assertRecusado('matricula', fn () => app(RegistarPagamentoAction::class)->executar($pendente, $this->pedidoPagamento(100_000)));
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/RegistarPagamentoTest.php`
Expected: FAIL (`RegistarPagamentoAction` inexistente).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Support/BloqueioDePagamentos.php`:
```php
<?php

namespace Modules\Financeiro\Support;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Financeiro\Models\Credito;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Models\Pagamento;
use Modules\Matricula\Models\Matricula;

/**
 * Bloqueios de Pagamentos pela ordem global (D13): matrícula → pagamento → propinas (BloqueioDePropinas) →
 * créditos (id crescente) → utilização → sequência. Todos FOR UPDATE, dentro da transacção do chamador; devolvem
 * as linhas relidas. SQLite ignora FOR UPDATE; o efeito real só existe em PostgreSQL.
 */
class BloqueioDePagamentos
{
    public function matricula(int $id, bool $incluirEliminadas = false): ?Matricula
    {
        $this->exigirTransaccao();

        return Matricula::query()->when($incluirEliminadas, fn ($q) => $q->withTrashed())->whereKey($id)->lockForUpdate()->first();
    }

    public function pagamento(int $id): ?Pagamento
    {
        $this->exigirTransaccao();

        return Pagamento::query()->whereKey($id)->lockForUpdate()->first();
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Credito>
     */
    public function creditos(array $ids): Collection
    {
        $this->exigirTransaccao();
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $ids === [] ? new Collection() : Credito::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
    }

    public function utilizacao(int $id): ?CreditoUtilizacao
    {
        $this->exigirTransaccao();

        return CreditoUtilizacao::query()->whereKey($id)->lockForUpdate()->first();
    }

    private function exigirTransaccao(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Os bloqueios de Pagamentos exigem uma transacção aberta (DB::transaction) do chamador.');
        }
    }
}
```

`ViolacaoDeUnicidade.php` — acrescentar:
```php
    /**
     * Violação de `pagamentos_referencia_confirmada_unique` (mesma referência no mesmo método entre Confirmados): a
     * única unicidade do registo de pagamentos que se atribui a concorrência (duas sessões com a mesma referência).
     */
    public static function daReferenciaDoPagamento(Throwable $e): bool
    {
        if (! self::e($e)) {
            return false;
        }

        $mensagem = $e->getMessage();

        return stripos($mensagem, 'pagamentos_referencia_confirmada_unique') !== false
            || (stripos($mensagem, 'UNIQUE constraint failed') !== false && stripos($mensagem, 'pagamentos.referencia') !== false);
    }
```

`Modules/Financeiro/app/Actions/RegistarPagamentoAction.php`:
```php
<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Enums\Estado;
use Modules\Core\Services\GeradorSequencia;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\DTO\RegistarPagamentoDTO;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\EstadoCredito;
use Modules\Financeiro\Enums\EstadoPagamento;
use Modules\Financeiro\Models\Credito;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Models\MetodoPagamento;
use Modules\Financeiro\Models\Pagamento;
use Modules\Financeiro\Models\PagamentoPropina;
use Modules\Financeiro\Models\PagamentoSequencia;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Services\DistribuicaoDePagamentoService;
use Modules\Financeiro\Services\RecalcularCredito;
use Modules\Financeiro\Services\RecalcularPropina;
use Modules\Financeiro\Services\SnapshotMonetario;
use Modules\Financeiro\Support\BloqueioDePagamentos;
use Modules\Financeiro\Support\BloqueioDePropinas;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Support\ViolacaoDeUnicidade;
use Modules\Matricula\Models\Matricula;

/**
 * Regista um pagamento (spec Pagamentos §4). UMA transacção, sem try/catch lá dentro:
 *   1. bloquear pela ordem global (D13): matrícula → propinas pagáveis (id crescente) → créditos do aluno (só se usar);
 *   2. validar a data (nem futura nem anterior à matrícula bloqueada, O3), o método activo e a referência; calcular a divisão sobre o estado bloqueado (o mesmo motor da pré-visualização);
 *   3. número (último bloqueio: só depois de tudo validado, um rollback devolve-o);
 *   4. criar pagamento, distribuições, utilizações e o crédito do excedente;
 *   5. RecalcularPropina (todas as tocadas) e RecalcularCredito (os usados).
 * A violação do índice da referência (corrida entre sessões) converte-se em 422 FORA da transacção.
 */
class RegistarPagamentoAction
{
    public const MENSAGEM_REFERENCIA = 'Já existe um pagamento confirmado com esta referência neste método de pagamento.';

    public function __construct(
        private BloqueioDePagamentos $bloqueio,
        private BloqueioDePropinas $bloqueioDePropinas,
        private DistribuicaoDePagamentoService $distribuicao,
        private RecalcularPropina $recalcularPropina,
        private RecalcularCredito $recalcularCredito,
        private GeradorSequencia $sequencia,
        private SnapshotMonetario $snapshot,
        private RelogioDoTenant $relogio,
    ) {
    }

    public function executar(Matricula $matricula, RegistarPagamentoDTO $dto): Pagamento
    {
        try {
            return DB::transaction(fn () => $this->registar($matricula, $dto));
        } catch (QueryException $e) {
            if (ViolacaoDeUnicidade::daReferenciaDoPagamento($e)) {
                throw ValidationException::withMessages(['referencia' => self::MENSAGEM_REFERENCIA]);
            }

            throw $e;
        }
    }

    private function registar(Matricula $matricula, RegistarPagamentoDTO $dto): Pagamento
    {
        // 1. Bloqueios (D13). A matrícula primeiro: a geração e a alteração de preço também começam por ela.
        $bloqueada = $this->bloqueio->matricula((int) $matricula->getKey())
            ?? throw ValidationException::withMessages(['matricula' => 'A matrícula não existe ou foi eliminada.']);

        // O3: nem futura nem anterior à matrícula, com a data_matricula RELIDA sob o lock (a do pedido pode estar obsoleta).
        $this->distribuicao->validarData($dto->dataPagamento, $bloqueada);

        $ids = Propina::query()->where('matricula_id', $bloqueada->id)
            ->whereIn('estado', [EstadoCobranca::EM_ABERTO->value, EstadoCobranca::PARCIALMENTE_PAGA->value])
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        $propinas = $this->bloqueioDePropinas->bloquear($ids); // relidas: o calcular volta a filtrar o estado

        $creditos = new Collection();

        if ($dto->valorCredito->unidadesMenores() > 0) {
            $creditos = $this->bloqueio->creditos(
                Credito::query()->where('aluno_id', $bloqueada->aluno_id)->where('estado', EstadoCredito::DISPONIVEL->value)
                    ->pluck('id')->map(fn ($id) => (int) $id)->all(),
            );
        }

        // 2. Validações de negócio sobre o estado bloqueado.
        $metodo = MetodoPagamento::query()->find($dto->metodoPagamentoId);

        if ($metodo === null || (int) $metodo->estado !== Estado::ATIVO->value) {
            throw ValidationException::withMessages(['metodo_pagamento_id' => 'Escolha um método de pagamento activo.']);
        }

        if ($dto->referencia !== null && Pagamento::query()
            ->where('metodo_pagamento_id', $metodo->id)
            ->where('referencia', $dto->referencia)
            ->where('estado', EstadoPagamento::CONFIRMADO->value)
            ->exists()) {
            throw ValidationException::withMessages(['referencia' => self::MENSAGEM_REFERENCIA]);
        }

        $plano = $this->distribuicao->calcular(
            $dto->valor->unidadesMenores(),
            $dto->valorCredito->unidadesMenores(),
            $dto->dataPagamento,
            $dto->distribuicao,
            $propinas,
            $creditos,
            RegraCobranca::doTenant(),
        );

        // 3. Número: último bloqueio (a linha da sequência fica presa até ao commit; um rollback devolve-o).
        $numero = $this->sequencia->gerar(PagamentoSequencia::class, $this->relogio->hoje()->year);

        // 4. Escritas (só criações).
        $pagamento = new Pagamento([
            'matricula_id' => $bloqueada->id,
            'metodo_pagamento_id' => $metodo->id,
            'valor' => $dto->valor,
            'data_pagamento' => $dto->dataPagamento->toDateString(),
            'referencia' => $dto->referencia,
            'observacao' => $dto->observacao,
        ]);
        $pagamento->forceFill(['numero' => $numero, 'aluno_id' => $bloqueada->aluno_id, ...$this->snapshot->em($dto->dataPagamento)])->save();

        foreach ($plano->distribuicoes() as $distribuicao) {
            PagamentoPropina::create([
                'pagamento_id' => $pagamento->id,
                'propina_id' => $distribuicao['propina_id'],
                'valor' => Dinheiro::deUnidadesMenores($distribuicao['valor']),
            ]);
        }

        $hoje = $this->relogio->hoje()->toDateString();

        foreach ($plano->utilizacoes as $utilizacao) {
            CreditoUtilizacao::create([
                'credito_id' => $utilizacao['credito_id'],
                'propina_id' => $utilizacao['propina_id'],
                'valor' => Dinheiro::deUnidadesMenores($utilizacao['valor']),
                'data_utilizacao' => $hoje,
                'pagamento_id' => $pagamento->id, // O8: rastreia o acto em que foi combinada
            ]);
        }

        if ($plano->excedente > 0) {
            Credito::create([
                'aluno_id' => $bloqueada->aluno_id,
                'origem_pagamento_id' => $pagamento->id,
                'valor' => Dinheiro::deUnidadesMenores($plano->excedente),
            ]);
        }

        // 5. Caches (pontos únicos).
        $this->recalcularPropina->executarVarias($plano->propinasTocadas());

        foreach ($creditos->whereIn('id', $plano->creditosUsados()) as $credito) {
            $this->recalcularCredito->executar($credito);
        }

        return $pagamento->fresh(['distribuicoes', 'credito']);
    }
}
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Feature/RegistarPagamentoTest.php tests/Feature/Arquitectura`
Expected: PASS (incluindo `EscritoresDePropinaTest`, `EscritoresDePagamentoTest`, `RelogioDoTenantArquitecturaTest`, `TenancyArquitecturaTest`).

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Support/BloqueioDePagamentos.php Modules/Financeiro/app/Support/ViolacaoDeUnicidade.php Modules/Financeiro/app/Actions/RegistarPagamentoAction.php Modules/Financeiro/tests/Concerns/ComPagamentosFinanceiro.php Modules/Financeiro/tests/Feature/RegistarPagamentoTest.php
```

---

### Task 8: `AplicarCreditoAction` — usar crédito sem dinheiro novo, em qualquer matrícula do aluno (D10, D12)

**Files:**
- Create: `Modules/Financeiro/app/Actions/AplicarCreditoAction.php`
- Create: `Modules/Financeiro/tests/Feature/AplicarCreditoTest.php`

**Interfaces:**
- Consumes: T6 (`calcular`, `AplicarCreditoDTO`), T7 (`BloqueioDePagamentos`), `BloqueioDePropinas`, `RecalcularPropina`, `RecalcularCredito`, `RelogioDoTenant`.
- Produces: `AplicarCreditoAction::executar(Matricula $matricula, AplicarCreditoDTO $dto): list<CreditoUtilizacao>` — data efectiva = hoje da escola; nenhum `Pagamento` nem número; as utilizações ficam com `pagamento_id = null` (O8: só as combinadas com dinheiro novo o preenchem).

- [ ] **Step 1: Escrever os testes**

`Modules/Financeiro/tests/Feature/AplicarCreditoTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Financeiro\Actions\AplicarCreditoAction;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\EstadoCredito;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Models\Pagamento;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Tests\TestCase;

class AplicarCreditoTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPagamentosFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    private Matricula $matricula;

    /** @var array<string, Propina> */
    private array $p;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
        ['plano' => $plano, 'matricula' => $this->matricula] = $this->cenarioPropinas();
        $this->p = ['set' => $this->propina($this->matricula, $plano, 1), 'out' => $this->propina($this->matricula, $plano, 2)];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function aplicar(Matricula $matricula, int $credito, ?array $linhas = null): array
    {
        return app(AplicarCreditoAction::class)->executar($matricula->fresh(), $this->pedidoCredito($credito, $linhas));
    }

    private function assertRecusado(string $campo, Closure $operacao): void
    {
        $antes = CreditoUtilizacao::query()->count();

        try {
            $operacao();
            $this->fail("Devia recusar em {$campo}.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($campo, $e->errors(), json_encode($e->errors()));
        }

        $this->assertSame($antes, CreditoUtilizacao::query()->count());
    }

    /** Renovação: matrícula do MESMO aluno no ano seguinte, com a 1.ª propina (Set/2027). */
    private function matriculaDoAnoSeguinte(): array
    {
        $ano = $this->anoLectivo('2027/2028', '2027-09-01', '2028-07-31');
        $turma = $this->turma($ano, $this->nivel('N' . uniqid()));
        $plano = $this->plano($ano, 'Propina Mensal 2027');
        $matricula = Matricula::create([
            'aluno_id' => $this->matricula->aluno_id,
            'turma_id' => $turma->id,
            'ano_lectivo_id' => $ano->id,
            'numero_registo_matricula' => 'MR-' . uniqid(),
            'data_matricula' => '2027-09-01',
            'estado' => EstadoMatriculaEnum::ACTIVA->value,
        ]);

        return [$matricula, $this->propina($matricula, $plano, 1)];
    }

    public function test_aplica_o_credito_sem_criar_pagamento_nem_consumir_numero(): void
    {
        $this->regras();
        $this->pagar($this->matricula, 3_000_000, [[$this->p['set'], 2_500_000]]); // 500.000 de crédito
        $this->regras(parcial: true);

        $utilizacoes = $this->aplicar($this->matricula, 500_000);

        $this->assertCount(1, $utilizacoes);
        $this->assertSame([$this->p['out']->id, 500_000, '2026-10-16'], [(int) $utilizacoes[0]->propina_id, $utilizacoes[0]->valor->unidadesMenores(), $utilizacoes[0]->data_utilizacao->toDateString()]);
        $this->assertNull($utilizacoes[0]->pagamento_id, 'O8: aplicação isolada, sem pagamento do mesmo acto.');
        $this->assertSame(1, Pagamento::query()->count());
        $this->assertSame(EstadoCobranca::PARCIALMENTE_PAGA, $this->p['out']->fresh()->estado);
        $this->assertSame(EstadoCredito::ESGOTADO, Pagamento::query()->sole()->credito->estado);
        $this->assertSame('2026-0002', $this->pagar($this->matricula, 2_000_000, [[$this->p['out'], 2_000_000]])->numero);
        $this->assertPagamentosCoerentes();
    }

    public function test_credito_do_aluno_serve_numa_matricula_de_outro_ano(): void
    {
        $this->regras();
        $this->pagar($this->matricula, 3_000_000, [[$this->p['set'], 2_500_000]]); // 500.000
        $this->pagar($this->matricula, 4_500_000, [[$this->p['out'], 2_500_000]]); // 2.000.000
        [$nova, $setembro2027] = $this->matriculaDoAnoSeguinte();
        $this->regras(antecipado: true);

        $utilizacoes = $this->aplicar($nova, 2_500_000, [[$setembro2027, 2_500_000]]);

        $this->assertCount(2, $utilizacoes, 'O crédito mais antigo primeiro, depois o seguinte.');
        $this->assertSame(EstadoCobranca::PAGA, $setembro2027->fresh()->estado);
        $this->assertPagamentosCoerentes();
    }

    public function test_credito_de_outro_aluno_nao_se_aplica(): void
    {
        $this->regras();
        $this->pagar($this->matricula, 3_000_000, [[$this->p['set'], 2_500_000]]);
        $outra = $this->matriculaActiva($this->p['set']->matricula->turma);
        $propinaDaOutra = $this->propina($outra, $this->p['set']->plano, 1);
        $this->regras(parcial: true); // sem isto a linha parcial seria recusada antes (distribuicao.0.valor)

        $this->assertRecusado('valor_credito', fn () => $this->aplicar($outra, 500_000, [[$propinaDaOutra, 500_000]]));
    }

    public function test_regras_de_parcial_valem_tambem_para_o_credito(): void
    {
        $this->regras();
        $this->pagar($this->matricula, 3_000_000, [[$this->p['set'], 2_500_000]]);

        $this->assertRecusado('distribuicao.0.valor', fn () => $this->aplicar($this->matricula, 500_000, [[$this->p['out'], 500_000]]));
        $this->assertRecusado('valor_credito', fn () => $this->aplicar($this->matricula, 500_000));
    }

    public function test_valor_zero_e_recusado_no_dto(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->pedidoCredito(0);
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/AplicarCreditoTest.php`
Expected: FAIL (`AplicarCreditoAction` inexistente).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Actions/AplicarCreditoAction.php`:
```php
<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\DTO\AplicarCreditoDTO;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\EstadoCredito;
use Modules\Financeiro\Models\Credito;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Services\DistribuicaoDePagamentoService;
use Modules\Financeiro\Services\RecalcularCredito;
use Modules\Financeiro\Services\RecalcularPropina;
use Modules\Financeiro\Support\BloqueioDePagamentos;
use Modules\Financeiro\Support\BloqueioDePropinas;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Matricula\Models\Matricula;

/**
 * Usa crédito do aluno em propinas de uma matrícula dele, sem dinheiro novo (spec Pagamentos §4, §8; D12). Mesma
 * ordem de bloqueio e mesmo motor do registo; data efectiva = hoje da escola. Não cria pagamento nem número.
 */
class AplicarCreditoAction
{
    public function __construct(
        private BloqueioDePagamentos $bloqueio,
        private BloqueioDePropinas $bloqueioDePropinas,
        private DistribuicaoDePagamentoService $distribuicao,
        private RecalcularPropina $recalcularPropina,
        private RecalcularCredito $recalcularCredito,
        private RelogioDoTenant $relogio,
    ) {
    }

    /**
     * @return list<CreditoUtilizacao>
     */
    public function executar(Matricula $matricula, AplicarCreditoDTO $dto): array
    {
        return DB::transaction(function () use ($matricula, $dto) {
            $bloqueada = $this->bloqueio->matricula((int) $matricula->getKey())
                ?? throw ValidationException::withMessages(['matricula' => 'A matrícula não existe ou foi eliminada.']);

            $propinas = $this->bloqueioDePropinas->bloquear(
                Propina::query()->where('matricula_id', $bloqueada->id)
                    ->whereIn('estado', [EstadoCobranca::EM_ABERTO->value, EstadoCobranca::PARCIALMENTE_PAGA->value])
                    ->pluck('id')->map(fn ($id) => (int) $id)->all(),
            );
            $creditos = $this->bloqueio->creditos(
                Credito::query()->where('aluno_id', $bloqueada->aluno_id)->where('estado', EstadoCredito::DISPONIVEL->value)
                    ->pluck('id')->map(fn ($id) => (int) $id)->all(),
            );
            $hoje = $this->relogio->hoje();

            $plano = $this->distribuicao->calcular(
                0,
                $dto->valorCredito->unidadesMenores(),
                $hoje,
                $dto->distribuicao,
                $propinas,
                $creditos,
                RegraCobranca::doTenant(),
            );

            $criadas = [];

            foreach ($plano->utilizacoes as $utilizacao) {
                $criadas[] = CreditoUtilizacao::create([
                    'credito_id' => $utilizacao['credito_id'],
                    'propina_id' => $utilizacao['propina_id'],
                    'valor' => Dinheiro::deUnidadesMenores($utilizacao['valor']),
                    'data_utilizacao' => $hoje->toDateString(),
                ]);
            }

            $this->recalcularPropina->executarVarias($plano->propinasTocadas());

            foreach ($creditos->whereIn('id', $plano->creditosUsados()) as $credito) {
                $this->recalcularCredito->executar($credito);
            }

            return array_map(fn (CreditoUtilizacao $u) => $u->fresh(), $criadas);
        });
    }
}
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Feature/AplicarCreditoTest.php tests/Feature/Arquitectura`
Expected: PASS.

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Actions/AplicarCreditoAction.php Modules/Financeiro/tests/Feature/AplicarCreditoTest.php
```

---

### Task 9: Anular pagamento e anular utilização de crédito (D14, D15)

**Files:**
- Create: `Modules/Financeiro/app/Actions/AnularPagamentoAction.php`
- Create: `Modules/Financeiro/app/Actions/AnularUtilizacaoCreditoAction.php`
- Modify: `Modules/Financeiro/tests/Concerns/ComPagamentosFinanceiro.php` (+ `anularPagamento()`, `anularUtilizacao()`)
- Modify: `Modules/Financeiro/app/Services/DistribuicaoDePagamentoService.php` (+ `avisoDeReferencia`, O1; precisa de pagamentos Anulados para ser testado, por isso entra aqui)
- Create: `Modules/Financeiro/tests/Feature/AnularPagamentoTest.php`

**Interfaces:**
- Consumes: T7 (`BloqueioDePagamentos`), `BloqueioDePropinas`, `RecalcularPropina::executarVarias/executar`, `RecalcularCredito::executar/anular`, `MotivoDeAnulacaoDTO` (T6), `RelogioDoTenant::agora()`.
- Produces:
  - `AnularPagamentoAction::executar(Pagamento $pagamento, MotivoDeAnulacaoDTO $dto, ?int $utilizadorId): Pagamento`; `AnularPagamentoAction::motivoParaNaoAnular(Pagamento): ?string` (só leitura, para a UI: já anulado / crédito gerado com utilizações activas).
  - `AnularUtilizacaoCreditoAction::executar(CreditoUtilizacao $utilizacao, MotivoDeAnulacaoDTO $dto, ?int $utilizadorId): CreditoUtilizacao`.
  - `DistribuicaoDePagamentoService::avisoDeReferencia(int $metodoPagamentoId, ?string $referencia): ?string` (O1) — só leitura; `null` sem referência ou sem pagamento Anulado do mesmo método (o tenant vem do scope global); caso contrário a mensagem "Esta referência já foi usada no pagamento n.º … , que foi anulado. Confirme que não é um registo em duplicado.". **Não bloqueia** (o bloqueio é só contra Confirmados, D5). As utilizações do mesmo acto (O8) ficam acessíveis por `Pagamento::utilizacoesCombinadas()` (T3).
  - Helpers: `anularPagamento(Pagamento $p, string $motivo = 'Registado por engano'): Pagamento`, `anularUtilizacao(CreditoUtilizacao $u, string $motivo = 'Aplicado por engano'): CreditoUtilizacao`, `aplicarCredito(Propina $propina, int $valor): CreditoUtilizacao` (usa a `AplicarCreditoAction` da Task 8).

- [ ] **Step 1: Escrever os testes**

Em `ComPagamentosFinanceiro` acrescentar (com `use Modules\Financeiro\Actions\AnularPagamentoAction;`, `use Modules\Financeiro\Actions\AnularUtilizacaoCreditoAction;`, `use Modules\Financeiro\DTO\MotivoDeAnulacaoDTO;`):
```php
    protected function anularPagamento(Pagamento $pagamento, string $motivo = 'Registado por engano'): Pagamento
    {
        return app(AnularPagamentoAction::class)->executar($pagamento->fresh(), new MotivoDeAnulacaoDTO($motivo), auth()->id());
    }

    protected function anularUtilizacao(CreditoUtilizacao $utilizacao, string $motivo = 'Aplicado por engano'): CreditoUtilizacao
    {
        return app(AnularUtilizacaoCreditoAction::class)->executar($utilizacao->fresh(), new MotivoDeAnulacaoDTO($motivo), auth()->id());
    }

    /** Aplica crédito do aluno a UMA propina (AplicarCreditoAction real) e devolve a utilização criada. */
    protected function aplicarCredito(Propina $propina, int $valor): CreditoUtilizacao
    {
        return app(AplicarCreditoAction::class)->executar($propina->matricula()->firstOrFail(), $this->pedidoCredito($valor, [[$propina, $valor]]))[0];
    }
```
(com `use Modules\Financeiro\Actions\AplicarCreditoAction;`).

`Modules/Financeiro/tests/Feature/AnularPagamentoTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Financeiro\DTO\MotivoDeAnulacaoDTO;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\EstadoCredito;
use Modules\Financeiro\Enums\EstadoPagamento;
use Modules\Financeiro\Enums\EstadoUtilizacaoCredito;
use Modules\Financeiro\Models\Credito;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Models\Pagamento;
use Modules\Financeiro\Models\PagamentoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\DistribuicaoDePagamentoService;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

class AnularPagamentoTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPagamentosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    private Matricula $matricula;

    /** @var array<string, Propina> */
    private array $p;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
        ['plano' => $plano, 'matricula' => $this->matricula] = $this->cenarioPropinas();
        $this->p = ['set' => $this->propina($this->matricula, $plano, 1), 'out' => $this->propina($this->matricula, $plano, 2)];
        $this->actingAs($this->adminEscola());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function contagens(): array
    {
        return [Pagamento::query()->count(), PagamentoPropina::query()->count(), Credito::query()->count(), CreditoUtilizacao::query()->count()];
    }

    private function assertRecusado(string $campo, string $texto, Closure $operacao): void
    {
        try {
            $operacao();
            $this->fail("Devia recusar em {$campo}.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($campo, $e->errors(), json_encode($e->errors()));
            $this->assertStringContainsString($texto, $e->errors()[$campo][0]);
        }
    }

    public function test_anula_reverte_as_propinas_e_o_credito_e_nada_se_apaga(): void
    {
        $this->regras();
        $pagamento = $this->pagar($this->matricula, 6_000_000, data: '2026-10-02'); // Set + Out + 1.000.000 de crédito
        $antes = $this->contagens();

        $anulado = $this->anularPagamento($pagamento, '  Valor errado no talão  ');

        $this->assertSame(EstadoPagamento::ANULADO, $anulado->estado);
        $this->assertSame('Valor errado no talão', $anulado->motivo_anulacao);
        $this->assertSame(auth()->id(), (int) $anulado->anulado_por);
        $this->assertNotNull($anulado->anulado_em);
        $this->assertSame($pagamento->numero, $anulado->numero);
        $this->assertSame(EstadoCredito::ANULADO, $anulado->credito->estado);

        foreach (['set', 'out'] as $mes) {
            $propina = $this->p[$mes]->fresh();
            $this->assertSame(EstadoCobranca::EM_ABERTO, $propina->estado, $mes);
            $this->assertNull($propina->capital_liquidado_em, $mes);
        }

        $this->assertSame($antes, $this->contagens(), 'Nenhuma linha eliminada.');
        $this->assertPagamentosCoerentes();
    }

    public function test_anular_duas_vezes_recusa(): void
    {
        $this->regras();
        $pagamento = $this->pagar($this->matricula, 2_500_000, [[$this->p['set'], 2_500_000]]);
        $this->anularPagamento($pagamento);

        $this->assertRecusado('pagamento', 'já está anulado', fn () => $this->anularPagamento($pagamento));
    }

    public function test_bloqueado_enquanto_o_credito_gerado_tem_utilizacoes_activas(): void
    {
        $this->regras(parcial: true);
        $origem = $this->pagar($this->matricula, 3_000_000, [[$this->p['set'], 2_500_000]]); // 500.000 de crédito
        $utilizacao = $this->aplicarCredito($this->p['out'], 500_000);

        $this->assertRecusado('pagamento', 'anule primeiro', fn () => $this->anularPagamento($origem));

        $this->anularUtilizacao($utilizacao);
        $this->assertSame(EstadoCredito::DISPONIVEL, $origem->credito->fresh()->estado);
        $this->assertSame(EstadoCobranca::EM_ABERTO, $this->p['out']->fresh()->estado);

        $this->anularPagamento($origem);
        $this->assertSame(EstadoCredito::ANULADO, $origem->credito->fresh()->estado);
        $this->assertPagamentosCoerentes();
    }

    public function test_anular_utilizacao_duas_vezes_recusa(): void
    {
        $this->regras(parcial: true);
        $this->pagar($this->matricula, 3_000_000, [[$this->p['set'], 2_500_000]]);
        $utilizacao = $this->aplicarCredito($this->p['out'], 500_000);
        $this->anularUtilizacao($utilizacao);

        $this->assertRecusado('utilizacao', 'já está anulada', fn () => $this->anularUtilizacao($utilizacao));
        $this->assertSame(EstadoUtilizacaoCredito::ANULADA, $utilizacao->fresh()->estado);
    }

    public function test_as_utilizacoes_combinadas_no_mesmo_acto_ficam_activas(): void
    {
        $this->regras(parcial: true);
        $this->pagar($this->matricula, 3_000_000, [[$this->p['set'], 2_500_000]]); // 500.000 de crédito
        $combinado = $this->pagar($this->matricula, 2_000_000, [[$this->p['out'], 2_500_000]], credito: 500_000);

        $this->anularPagamento($combinado);

        $combinada = CreditoUtilizacao::query()->sole();
        $this->assertSame((int) $combinado->id, (int) $combinada->pagamento_id, 'O8: rastreada pelo pagamento do mesmo acto.');
        $this->assertSame([$combinada->id], $combinado->fresh()->utilizacoesCombinadas->pluck('id')->all(), 'O detalhe e o modal de anulação listam-na.');
        $this->assertSame(EstadoUtilizacaoCredito::ACTIVA, $combinada->estado, 'D14/O8: independentes.');
        $out = $this->p['out']->fresh();
        $this->assertSame(EstadoCobranca::PARCIALMENTE_PAGA, $out->estado);
        $this->assertSame(500_000, $out->valor_pago->unidadesMenores());
        $this->assertPagamentosCoerentes();
    }

    public function test_a_referencia_fica_livre_depois_de_anular(): void
    {
        $this->regras();
        $errado = $this->pagar($this->matricula, 2_500_000, [[$this->p['set'], 2_500_000]], referencia: 'TRF-55');
        $this->anularPagamento($errado);

        $certo = $this->pagar($this->matricula, 2_500_000, [[$this->p['set'], 2_500_000]], referencia: 'TRF-55');

        $this->assertSame(EstadoPagamento::CONFIRMADO, $certo->estado);
        $this->assertPagamentosCoerentes();
    }

    public function test_referencia_usada_num_pagamento_anulado_devolve_aviso_sem_bloquear(): void
    {
        $this->regras();
        $errado = $this->pagar($this->matricula, 2_500_000, [[$this->p['set'], 2_500_000]], referencia: 'TRF-77');
        $servico = app(DistribuicaoDePagamentoService::class);
        $metodo = (int) $errado->metodo_pagamento_id;

        $this->assertNull($servico->avisoDeReferencia($metodo, 'TRF-77'), 'Confirmado: é o bloqueio de D5, não um aviso.');

        $this->anularPagamento($errado);

        $this->assertStringContainsString($errado->numero, (string) $servico->avisoDeReferencia($metodo, 'TRF-77'));
        $this->assertNull($servico->avisoDeReferencia($metodo, 'OUTRA'));
        $this->assertNull($servico->avisoDeReferencia($metodo, null));
        $this->assertNull($servico->avisoDeReferencia($this->metodoPagamento('TPA')->id, 'TRF-77'), 'Outro método: sem aviso.');

        $this->pagar($this->matricula, 2_500_000, [[$this->p['set'], 2_500_000]], referencia: 'TRF-77'); // não bloqueia
        $this->assertSame(2, Pagamento::query()->count());
    }

    public function test_pagamento_de_matricula_eliminada_ainda_se_anula(): void
    {
        $this->regras();
        $pendente = $this->matriculaActiva($this->p['set']->matricula->turma, '2026-09-01', EstadoMatriculaEnum::PENDENTE);
        $adiantamento = $this->pagar($pendente, 1_000_000); // sem propinas: tudo crédito
        $pendente->delete();

        $this->assertSame(EstadoPagamento::ANULADO, $this->anularPagamento($adiantamento)->estado);
    }

    public function test_motivo_curto_recusado_no_dto(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new MotivoDeAnulacaoDTO(' abc ');
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/AnularPagamentoTest.php`
Expected: FAIL (Actions inexistentes).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Actions/AnularPagamentoAction.php`:
```php
<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\DTO\MotivoDeAnulacaoDTO;
use Modules\Financeiro\Enums\EstadoPagamento;
use Modules\Financeiro\Enums\EstadoUtilizacaoCredito;
use Modules\Financeiro\Models\Credito;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Models\Pagamento;
use Modules\Financeiro\Models\PagamentoPropina;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Services\RecalcularCredito;
use Modules\Financeiro\Services\RecalcularPropina;
use Modules\Financeiro\Support\BloqueioDePagamentos;
use Modules\Financeiro\Support\BloqueioDePropinas;
use Modules\Financeiro\Support\Dinheiro;

/**
 * Anula um pagamento (spec Pagamentos §5; D14): matrícula → pagamento → propinas → crédito gerado. Bloqueado se o
 * crédito gerado tem utilizações Activas. Efeito: pagamento e crédito gerado Anulados; RecalcularPropina das propinas
 * distribuídas (regressam a Em Aberto / Parcialmente Paga). As utilizações de crédito feitas no mesmo acto são
 * independentes e não mudam (ficam rastreadas por `pagamento_id`, O8). Nada é apagado.
 */
class AnularPagamentoAction
{
    public function __construct(
        private BloqueioDePagamentos $bloqueio,
        private BloqueioDePropinas $bloqueioDePropinas,
        private RecalcularPropina $recalcularPropina,
        private RecalcularCredito $recalcularCredito,
        private RelogioDoTenant $relogio,
        private MoedaDoTenant $moedaDoTenant,
    ) {
    }

    /** null = anulável. Sem bloqueio é só indicativo (botão do detalhe); dentro da Action é definitivo. */
    public function motivoParaNaoAnular(Pagamento $pagamento): ?string
    {
        if ($pagamento->estado === EstadoPagamento::ANULADO) {
            return 'O pagamento já está anulado.';
        }

        $credito = Credito::query()->where('origem_pagamento_id', $pagamento->getKey())->first();
        $usado = $credito === null ? 0 : (int) CreditoUtilizacao::query()
            ->where('credito_id', $credito->id)
            ->where('estado', EstadoUtilizacaoCredito::ACTIVA->value)
            ->sum('valor');

        if ($usado > 0) {
            return 'O crédito gerado por este pagamento já foi utilizado (' . Dinheiro::deUnidadesMenores($usado)->formatar($this->moedaDoTenant->atual())
                . '): anule primeiro essas utilizações de crédito.';
        }

        return null;
    }

    public function executar(Pagamento $pagamento, MotivoDeAnulacaoDTO $dto, ?int $utilizadorId): Pagamento
    {
        return DB::transaction(function () use ($pagamento, $dto, $utilizadorId) {
            // D13: matrícula (mesmo eliminada: um adiantamento numa Pendente apagada) → pagamento → propinas → crédito.
            $this->bloqueio->matricula((int) $pagamento->matricula_id, incluirEliminadas: true);
            $bloqueado = $this->bloqueio->pagamento((int) $pagamento->getKey())
                ?? throw ValidationException::withMessages(['pagamento' => 'O pagamento não existe.']);

            $propinas = PagamentoPropina::query()->where('pagamento_id', $bloqueado->id)->pluck('propina_id')->map(fn ($id) => (int) $id)->all();
            $this->bloqueioDePropinas->bloquear($propinas);
            $credito = $this->bloqueio->creditos(
                Credito::query()->where('origem_pagamento_id', $bloqueado->id)->pluck('id')->map(fn ($id) => (int) $id)->all(),
            )->first();

            if (($razao = $this->motivoParaNaoAnular($bloqueado)) !== null) {
                throw ValidationException::withMessages(['pagamento' => $razao]);
            }

            $bloqueado->forceFill([
                'estado' => EstadoPagamento::ANULADO,
                'motivo_anulacao' => $dto->motivo,
                'anulado_por' => $utilizadorId,
                'anulado_em' => $this->relogio->agora()->setTimezone(config('app.timezone')),
            ])->save();

            if ($credito !== null) {
                $this->recalcularCredito->anular($credito);
            }

            $this->recalcularPropina->executarVarias($propinas);
            // Pagamentos-B: RecalcularMulta de cada multa distribuída (pagamento_multas).

            return $bloqueado->fresh(['distribuicoes', 'credito']);
        });
    }
}
```

`Modules/Financeiro/app/Actions/AnularUtilizacaoCreditoAction.php`:
```php
<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\DTO\MotivoDeAnulacaoDTO;
use Modules\Financeiro\Enums\EstadoUtilizacaoCredito;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\RecalcularCredito;
use Modules\Financeiro\Services\RecalcularPropina;
use Modules\Financeiro\Support\BloqueioDePagamentos;
use Modules\Financeiro\Support\BloqueioDePropinas;

/**
 * Anula uma utilização de crédito (spec Pagamentos §6; D15): matrícula da propina → propina → crédito → utilização.
 * O crédito recupera saldo (Esgotado volta a Disponível) e a propina é recalculada.
 */
class AnularUtilizacaoCreditoAction
{
    public function __construct(
        private BloqueioDePagamentos $bloqueio,
        private BloqueioDePropinas $bloqueioDePropinas,
        private RecalcularPropina $recalcularPropina,
        private RecalcularCredito $recalcularCredito,
        private RelogioDoTenant $relogio,
    ) {
    }

    public function executar(CreditoUtilizacao $utilizacao, MotivoDeAnulacaoDTO $dto, ?int $utilizadorId): CreditoUtilizacao
    {
        return DB::transaction(function () use ($utilizacao, $dto, $utilizadorId) {
            $propina = Propina::query()->findOrFail($utilizacao->propina_id);
            $this->bloqueio->matricula((int) $propina->matricula_id, incluirEliminadas: true);
            $this->bloqueioDePropinas->bloquear([(int) $propina->id]);
            $credito = $this->bloqueio->creditos([(int) $utilizacao->credito_id])->first();
            $bloqueada = $this->bloqueio->utilizacao((int) $utilizacao->getKey())
                ?? throw ValidationException::withMessages(['utilizacao' => 'A utilização de crédito não existe.']);

            if ($bloqueada->estado === EstadoUtilizacaoCredito::ANULADA) {
                throw ValidationException::withMessages(['utilizacao' => 'A utilização de crédito já está anulada.']);
            }

            $bloqueada->forceFill([
                'estado' => EstadoUtilizacaoCredito::ANULADA,
                'motivo_anulacao' => $dto->motivo,
                'anulado_por' => $utilizadorId,
                'anulado_em' => $this->relogio->agora()->setTimezone(config('app.timezone')),
            ])->save();

            $this->recalcularCredito->executar($credito);
            $this->recalcularPropina->executar($propina);

            return $bloqueada->fresh();
        });
    }
}
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Feature/AnularPagamentoTest.php tests/Feature/Arquitectura`
Expected: PASS.

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Actions/AnularPagamentoAction.php Modules/Financeiro/app/Actions/AnularUtilizacaoCreditoAction.php Modules/Financeiro/tests/Concerns/ComPagamentosFinanceiro.php Modules/Financeiro/tests/Feature/AnularPagamentoTest.php
```

---

### Task 10: Anular propina — ponto único, Action e regra de arquitectura (D16)

**Files:**
- Create: `Modules/Financeiro/app/Support/AnulacaoDePropinas.php`
- Create: `Modules/Financeiro/app/Actions/AnularPropinaAction.php`
- Modify: `Modules/Financeiro/app/Support/CancelamentoDePropinas.php` (texto da mensagem de histórico)
- Modify: `Modules/Financeiro/tests/Feature/CancelarPropinaTest.php:107`, `Modules/Financeiro/tests/Feature/CancelamentoDePropinasTest.php:74` (texto)
- Create: `Modules/Financeiro/tests/Feature/AnularPropinaTest.php`
- Create: `tests/Feature/Arquitectura/AnuladoresDePropinaTest.php`

**Interfaces:**
- Consumes: `FontesDePagamentoDePropina`, `HistoricosDePagamentoDaPropina` (agora com fontes reais, T4), `BloqueioDePropinas`, `MotivoDeAnulacaoDTO`, `RelogioDoTenant`.
- Produces:
  - `AnulacaoDePropinas::motivoParaNaoAnular(Propina): ?string`, `AnulacaoDePropinas::anular(Propina $bloqueada, string $motivo, ?int $utilizadorId): Propina` — único escritor do estado Anulada de uma propina.
  - `AnularPropinaAction::executar(Propina $propina, MotivoDeAnulacaoDTO $dto, ?int $utilizadorId): Propina` (bloqueia só a propina, como o cancelamento).
  - Arquitectura: o estado Anulada de propina só em `AnulacaoDePropinas`; `motivo_anulacao`/`anulado_por`/`anulado_em` só em `AnulacaoDePropinas`, `AnularPagamentoAction` e `AnularUtilizacaoCreditoAction` (cada um nos seus registos).

- [ ] **Step 1: Escrever os testes**

`Modules/Financeiro/tests/Feature/AnularPropinaTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Actions\AnularPropinaAction;
use Modules\Financeiro\Actions\CancelarPropinaAction;
use Modules\Financeiro\DTO\CancelarPropinaDTO;
use Modules\Financeiro\DTO\MotivoDeAnulacaoDTO;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Matricula\Models\Matricula;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

class AnularPropinaTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPagamentosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    private Matricula $matricula;

    private Propina $set;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
        ['plano' => $plano, 'matricula' => $this->matricula] = $this->cenarioPropinas();
        $this->set = $this->propina($this->matricula, $plano, 1);
        $this->actingAs($this->adminEscola());
        $this->regras();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function anular(Propina $propina, string $motivo = 'Propina emitida por engano'): Propina
    {
        return app(AnularPropinaAction::class)->executar($propina->fresh(), new MotivoDeAnulacaoDTO($motivo), auth()->id());
    }

    private function assertRecusado(string $texto, Closure $operacao): void
    {
        try {
            $operacao();
            $this->fail('Devia recusar.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($texto, $e->errors()['propina'][0]);
        }
    }

    public function test_sem_historico_nao_se_anula_cancela_se(): void
    {
        $this->assertRecusado('cancele-a', fn () => $this->anular($this->set));
        $this->assertSame(EstadoCobranca::EM_ABERTO, $this->set->fresh()->estado);
    }

    public function test_com_pagamento_activo_nao_se_anula_nem_cancela(): void
    {
        $this->pagar($this->matricula, 2_500_000, [[$this->set, 2_500_000]]);

        $this->assertRecusado('anule primeiro os pagamentos', fn () => $this->anular($this->set));
        $this->assertRecusado('Anule primeiro os pagamentos', fn () => app(CancelarPropinaAction::class)->executar($this->set->fresh(), new CancelarPropinaDTO('Motivo qualquer'), auth()->id()));
    }

    public function test_com_todos_os_pagamentos_anulados_anula_se_e_ja_nao_se_cancela(): void
    {
        $pagamento = $this->pagar($this->matricula, 2_500_000, [[$this->set, 2_500_000]]);
        $this->anularPagamento($pagamento);

        $this->assertRecusado('não se cancela, anula-se', fn () => app(CancelarPropinaAction::class)->executar($this->set->fresh(), new CancelarPropinaDTO('Motivo qualquer'), auth()->id()));

        $anulada = $this->anular($this->set, '  Bolsa integral atribuída  ');

        $this->assertSame(EstadoCobranca::ANULADA, $anulada->estado);
        $this->assertSame('Bolsa integral atribuída', $anulada->motivo_anulacao);
        $this->assertSame(auth()->id(), (int) $anulada->anulado_por);
        $this->assertNotNull($anulada->anulado_em);
        $this->assertSame(0, $anulada->valor_pago->unidadesMenores());
        $this->assertPagamentosCoerentes();
    }

    public function test_anulada_e_terminal_e_nao_e_pagavel(): void
    {
        $this->anularPagamento($this->pagar($this->matricula, 2_500_000, [[$this->set, 2_500_000]]));
        $this->anular($this->set);

        $this->assertRecusado('já está anulada', fn () => $this->anular($this->set));

        try {
            $this->pagar($this->matricula, 2_500_000, [[$this->set, 2_500_000]]);
            $this->fail('Uma anulada não recebe pagamentos.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('distribuicao.0.propina_id', $e->errors());
        }
    }

    public function test_so_utilizacao_de_credito_anulada_tambem_permite_anular(): void
    {
        $this->regras(parcial: true);
        $out = $this->propina($this->matricula, $this->set->plano, 2);
        $this->pagar($this->matricula, 3_000_000, [[$this->set, 2_500_000]]); // 500.000 de crédito
        $utilizacao = $this->aplicarCredito($out, 500_000);
        $this->anularUtilizacao($utilizacao);

        $this->assertSame(EstadoCobranca::ANULADA, $this->anular($out)->estado);
    }
}
```
(`aplicarCredito()` vem de `ComPagamentosFinanceiro`, Task 9.)

Nos testes existentes, trocar o texto esperado:
- `CancelarPropinaTest.php:107`: `'A propina já recebeu pagamentos (entretanto anulados): não se cancela, anula-se.'`
- `CancelamentoDePropinasTest.php:74`: continua `assertStringContainsString('anula-se', …)` (sem alteração necessária; confirmar).

`tests/Feature/Arquitectura/AnuladoresDePropinaTest.php` (adaptação completa de `CanceladoresDePropinaTest`):
```php
<?php

namespace Tests\Feature\Arquitectura;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Ponto único de anulação de propinas (D16 de Pagamentos-A): o estado Anulada de uma propina só se escreve em
 * AnulacaoDePropinas, onde vivem as pré-condições de §8 (Em Aberto, sem valor pago, sem contribuições, COM histórico).
 * Os campos `motivo_anulacao`, `anulado_por`, `anulado_em` também existem em pagamentos e utilizações de crédito: só os
 * três escritores listados os escrevem, cada um nos seus registos. Mesma técnica e limites de CanceladoresDePropinaTest.
 */
class AnuladoresDePropinaTest extends TestCase
{
    private const ANULADOR_DE_PROPINAS = 'Modules/Financeiro/app/Support/AnulacaoDePropinas.php';

    private const ESCRITORES_DOS_CAMPOS_DE_ANULACAO = [
        'Modules/Financeiro/app/Support/AnulacaoDePropinas.php',
        'Modules/Financeiro/app/Actions/AnularPagamentoAction.php',
        'Modules/Financeiro/app/Actions/AnularUtilizacaoCreditoAction.php',
    ];

    private const CAMPOS_DE_ANULACAO = ['motivo_anulacao', 'anulado_por', 'anulado_em'];

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

    /** O valor que começa em $i é 5 ou EstadoCobranca::ANULADA? (EstadoUtilizacaoCredito::ANULADA não conta.) */
    private function eAnulada(array $tokens, int $i): bool
    {
        $valor = [];

        for ($j = $i; $j < count($tokens) && ! in_array($tokens[$j][0], [',', ')', ']', ';'], true) && count($valor) < 5; $j++) {
            $valor[] = $tokens[$j];
        }

        if (count($valor) === 1 && $valor[0][0] === T_LNUMBER && $valor[0][1] === '5') {
            return true;
        }

        for ($k = 0; $k + 2 < count($valor); $k++) {
            if (str_ends_with($valor[$k][1], 'EstadoCobranca') && $valor[$k + 1][0] === T_DOUBLE_COLON && $valor[$k + 2][1] === 'ANULADA') {
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

    /**
     * @return array{estado: list<string>, campos: list<string>} escritas do estado Anulada (de propina) e dos campos
     */
    private function anulacoes(string $codigo): array
    {
        $tokens = $this->tokens($codigo);
        $total = count($tokens);
        $referePropina = $this->referePropina($tokens);
        $achados = ['estado' => [], 'campos' => []];

        for ($i = 0; $i < $total; $i++) {
            if (in_array($tokens[$i][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                && isset($tokens[$i + 2])
                && $tokens[$i + 1][0] === T_STRING
                && in_array($tokens[$i + 2][0], self::ATRIBUICOES, true)) {
                $campo = $tokens[$i + 1][1];

                if (in_array($campo, self::CAMPOS_DE_ANULACAO, true)) {
                    $achados['campos'][] = $this->texto($tokens, $i, min($i + 5, $total - 1));
                } elseif ($campo === 'estado' && $referePropina && $this->eAnulada($tokens, $i + 3)) {
                    $achados['estado'][] = $this->texto($tokens, $i, min($i + 5, $total - 1));
                }

                continue;
            }

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
                } elseif (in_array($tokens[$j][0], [')', ']', '}'], true) && --$nivel === 0) {
                    break;
                }

                $campoAnulacao = $this->eString($tokens[$j], self::CAMPOS_DE_ANULACAO);
                $campoEstado = $referePropina && $this->eString($tokens[$j], ['estado']);

                if (! $campoAnulacao && ! $campoEstado) {
                    continue;
                }

                $chave = ($tokens[$j + 1][0] ?? null) === T_DOUBLE_ARROW;
                $argumento = $metodo === 'setAttribute' && $tokens[$j - 1][0] === '(';

                if (! $chave && ! $argumento) {
                    continue;
                }

                if ($campoAnulacao) {
                    $achados['campos'][] = $this->texto($tokens, $j, min($j + 3, $total - 1));
                } elseif ($this->eAnulada($tokens, $j + 2)) {
                    $achados['estado'][] = $this->texto($tokens, $j, min($j + 3, $total - 1));
                }
            }
        }

        return $achados;
    }

    private function raiz(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string, string> */
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

    public function test_so_a_anulacao_de_propinas_anula_propinas(): void
    {
        $violacoes = [];

        foreach ($this->codigoVarrido() as $relativo => $codigo) {
            if ($relativo === self::ANULADOR_DE_PROPINAS) {
                continue;
            }

            foreach ($this->anulacoes($codigo)['estado'] as $excerto) {
                $violacoes[] = "{$relativo}: {$excerto}";
            }
        }

        $this->assertSame([], $violacoes, "Só AnulacaoDePropinas escreve o estado Anulada de uma propina:\n" . implode("\n", $violacoes));
    }

    public function test_so_os_tres_anuladores_escrevem_os_campos_de_anulacao(): void
    {
        $violacoes = [];

        foreach ($this->codigoVarrido() as $relativo => $codigo) {
            if (in_array($relativo, self::ESCRITORES_DOS_CAMPOS_DE_ANULACAO, true)) {
                continue;
            }

            foreach ($this->anulacoes($codigo)['campos'] as $excerto) {
                $violacoes[] = "{$relativo}: {$excerto}";
            }
        }

        $this->assertSame([], $violacoes, "Só AnulacaoDePropinas, AnularPagamentoAction e AnularUtilizacaoCreditoAction escrevem os campos de anulação:\n" . implode("\n", $violacoes));
    }

    public function test_os_anuladores_existem_e_sao_apanhados(): void
    {
        $this->assertNotSame([], $this->anulacoes(file_get_contents($this->raiz() . '/' . self::ANULADOR_DE_PROPINAS))['estado']);

        foreach (self::ESCRITORES_DOS_CAMPOS_DE_ANULACAO as $ficheiro) {
            $this->assertNotSame([], $this->anulacoes(file_get_contents($this->raiz() . '/' . $ficheiro))['campos'], $ficheiro);
        }
    }

    #[DataProvider('exemplos')]
    public function test_o_varrimento_apanha_o_que_deve(string $codigo, bool $estado, bool $campos): void
    {
        $achados = $this->anulacoes('<?php ' . $codigo);

        $this->assertSame([$estado, $campos], [$achados['estado'] !== [], $achados['campos'] !== []], $codigo);
    }

    public static function exemplos(): array
    {
        return [
            'estado Anulada por enum em query de Propina' => ["Propina::query()->update(['estado' => EstadoCobranca::ANULADA]);", true, false],
            'estado 5 literal na tabela' => ["DB::table('propinas')->update(['estado' => 5]);", true, false],
            'estado Anulada atribuído a uma propina' => ['$p = Propina::find(1); $p->estado = EstadoCobranca::ANULADA;', true, false],
            'anulação completa de propina' => ["\$p->forceFill(['estado' => EstadoCobranca::ANULADA, 'motivo_anulacao' => 'x', 'anulado_em' => \$agora])->save();", false, true],
            'campos num pagamento' => ["\$pagamento->forceFill(['estado' => EstadoPagamento::ANULADO, 'motivo_anulacao' => 'x']);", false, true],
            'utilização anulada não é propina' => ["use Modules\\Financeiro\\Models\\Propina; \$u->forceFill(['estado' => EstadoUtilizacaoCredito::ANULADA]);", false, false],
            'estado Cancelada não é anulação' => ["Propina::query()->update(['estado' => EstadoCobranca::CANCELADA]);", false, false],
            'atribuição de anulado_por' => ['$x->anulado_por = 1;', false, true],
            'leitura' => ['$x = $p->motivo_anulacao; if ($p->estado === EstadoCobranca::ANULADA) {}', false, false],
            'filtro' => ["Propina::query()->where('estado', EstadoCobranca::ANULADA)->get();", false, false],
            'array de saída' => ["\$linha = ['motivo_anulacao' => \$p->motivo_anulacao];", false, false],
            'comentário' => ["// \$p->anulado_em = 1;", false, false],
        ];
    }
}
```
**Nota:** o exemplo "anulação completa de propina" dá `estado = false` porque o ficheiro de exemplo não refere `Propina` (só `$p`); com `use …\Propina` daria `true`. É o mesmo limite de `CanceladoresDePropinaTest` e está correcto para o teste dos detectores.

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/AnularPropinaTest.php tests/Feature/Arquitectura/AnuladoresDePropinaTest.php Modules/Financeiro/tests/Feature/CancelarPropinaTest.php`
Expected: FAIL (classes inexistentes; texto antigo).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Support/AnulacaoDePropinas.php`:
```php
<?php

namespace Modules\Financeiro\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\DTO\MotivoDeAnulacaoDTO;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\Propina;

/**
 * Ponto ÚNICO de escrita do estado Anulada de uma propina e de `motivo_anulacao`, `anulado_por`, `anulado_em`
 * (AnuladoresDePropinaTest). Cancelar ≠ anular (spec Propinas §8; §8.2/2 do plano de fases): anula-se só uma propina
 * persistida Em Aberto, sem valor pago, sem contribuições activas e COM histórico (todos os pagamentos e utilizações
 * de crédito que a tocaram já anulados). Sem histórico, cancela-se.
 */
class AnulacaoDePropinas
{
    public function __construct(
        private FontesDePagamentoDePropina $fontes,
        private HistoricosDePagamentoDaPropina $historicos,
        private RelogioDoTenant $relogio,
    ) {
    }

    /** null = anulável. Com a propina bloqueada pelo chamador é definitivo; sem bloqueio é indicativo (UI). */
    public function motivoParaNaoAnular(Propina $propina): ?string
    {
        return match (true) {
            $propina->estado === EstadoCobranca::ANULADA => 'A propina já está anulada.',
            $propina->estado === EstadoCobranca::CANCELADA => 'Uma propina cancelada não pode ser anulada.',
            $propina->estado !== EstadoCobranca::EM_ABERTO,
            $propina->valorRecebido()->unidadesMenores() > 0,
            $this->fontes->contribuicoes($propina) !== [] => 'A propina tem pagamentos ou créditos activos: anule primeiro os pagamentos e as utilizações de crédito que a liquidam.',
            ! $this->historicos->temHistorico($propina) => 'A propina nunca recebeu pagamentos: cancele-a em vez de a anular.',
            default => null,
        };
    }

    /** Anula uma propina JÁ BLOQUEADA pelo chamador. Nunca apanhar a excepção dentro da transacção. */
    public function anular(Propina $bloqueada, string $motivo, ?int $utilizadorId): Propina
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Anular uma propina exige uma transacção aberta e a propina bloqueada pelo chamador.');
        }

        $motivo = trim($motivo);
        $tamanho = mb_strlen($motivo);

        if ($tamanho < MotivoDeAnulacaoDTO::MINIMO || $tamanho > MotivoDeAnulacaoDTO::MAXIMO) {
            throw new InvalidArgumentException('O motivo da anulação tem de ter entre ' . MotivoDeAnulacaoDTO::MINIMO . ' e ' . MotivoDeAnulacaoDTO::MAXIMO . ' caracteres.');
        }

        if (($razao = $this->motivoParaNaoAnular($bloqueada)) !== null) {
            throw new LogicException($razao);
        }

        $bloqueada->forceFill([
            'estado' => EstadoCobranca::ANULADA,
            'motivo_anulacao' => $motivo,
            'anulado_por' => $utilizadorId,
            'anulado_em' => $this->relogio->agora()->setTimezone(config('app.timezone')),
        ])->save();

        // F5: a multa da propina (se existir e não tiver pagamentos) passa a Anulada aqui, na mesma transacção.

        return $bloqueada;
    }
}
```

`Modules/Financeiro/app/Actions/AnularPropinaAction.php`:
```php
<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Financeiro\DTO\MotivoDeAnulacaoDTO;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Support\AnulacaoDePropinas;
use Modules\Financeiro\Support\BloqueioDePropinas;

/**
 * Anulação manual de uma propina (spec Propinas §8, `propina.anular`, motivo obrigatório). Bloqueia SÓ a propina
 * (como o cancelamento, F3 D5) e decide sobre a linha relida e bloqueada.
 */
class AnularPropinaAction
{
    public function __construct(
        private BloqueioDePropinas $bloqueio,
        private AnulacaoDePropinas $anulacao,
    ) {
    }

    public function executar(Propina $propina, MotivoDeAnulacaoDTO $dto, ?int $utilizadorId): Propina
    {
        return DB::transaction(function () use ($propina, $dto, $utilizadorId) {
            $bloqueada = $this->bloqueio->bloquear([(int) $propina->getKey()])->first()
                ?? throw new LogicException('A propina a anular não existe no tenant corrente.');

            if (($razao = $this->anulacao->motivoParaNaoAnular($bloqueada)) !== null) {
                throw ValidationException::withMessages(['propina' => $razao]);
            }

            return $this->anulacao->anular($bloqueada, $dto->motivo, $utilizadorId);
        });
    }
}
```

`CancelamentoDePropinas::motivoParaNaoCancelar` — trocar a linha do histórico por:
```php
            $this->historicos->temHistorico($propina) => 'A propina já recebeu pagamentos (entretanto anulados): não se cancela, anula-se.',
```
(e no docblock da classe, "anula-se (Pagamentos-A)" → "anula-se (AnulacaoDePropinas)").

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Feature/AnularPropinaTest.php Modules/Financeiro/tests/Feature/CancelarPropinaTest.php Modules/Financeiro/tests/Feature/CancelamentoDePropinasTest.php tests/Feature/Arquitectura`
Expected: PASS (incluindo `CanceladoresDePropinaTest` — `AnulacaoDePropinas` não escreve campos de cancelamento).

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Support/AnulacaoDePropinas.php Modules/Financeiro/app/Actions/AnularPropinaAction.php Modules/Financeiro/app/Support/CancelamentoDePropinas.php Modules/Financeiro/tests/Feature/AnularPropinaTest.php Modules/Financeiro/tests/Feature/CancelarPropinaTest.php tests/Feature/Arquitectura/AnuladoresDePropinaTest.php
```

---

### Task 11: Integração com F3/F4 e isolamento de tenant (spec Propinas §8, §9, §14; Review Focus 2–4)

**Files:**
- Create: `Modules/Financeiro/tests/Feature/PagamentosIntegracaoPropinasTest.php`
- Modify: `Modules/Financeiro/tests/Feature/FinanceiroTenancyTest.php` (+ casos, + `tearDown`)

**Interfaces:**
- Consumes: tudo de T7–T10; `AlterarEstadoMatriculaAction::executar(Matricula, EstadoMatriculaEnum, ?int $utilizadorId = null, ?string $dataFim = null)`; `ComAlteracaoPreco` (`previa`, `alterarPreco`, `pedidoAlteracao`); `AlterarPrecoPlanoAction`.
- Produces: prova (SQLite, sequencial) de que pagamentos reais convivem com o cancelamento por estado da matrícula (F3) e com a alteração de preço (F4), e de que nada atravessa tenants. Sem código de produção novo — se algum teste falhar, o defeito está em T2–T10 e corrige-se lá.

- [ ] **Step 1: Escrever os testes**

`Modules/Financeiro/tests/Feature/PagamentosIntegracaoPropinasTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Actions\AlterarPrecoPlanoAction;
use Modules\Financeiro\Actions\AnularPropinaAction;
use Modules\Financeiro\DTO\MotivoDeAnulacaoDTO;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Tests\Concerns\ComAlteracaoPreco;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Matricula\Actions\AlterarEstadoMatriculaAction;
use Modules\Matricula\Enums\EstadoMatriculaEnum;
use Modules\Matricula\Models\Matricula;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

class PagamentosIntegracaoPropinasTest extends TestCase
{
    use ComAlteracaoPreco;
    use ComDadosAcademicosFinanceiro;
    use ComPagamentosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    private Matricula $matricula;

    private PlanoPropina $plano;

    /** @var array<int, Propina> ordem => propina (Set=1 … Jun=10) */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
        ['plano' => $this->plano, 'matricula' => $this->matricula] = $this->cenarioPropinas();

        foreach (range(1, 10) as $ordem) {
            $this->p[$ordem] = $this->propina($this->matricula, $this->plano, $ordem);
        }

        $this->actingAs($this->adminEscola());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_divida_de_matricula_cancelada_e_pagavel_e_as_canceladas_nao(): void
    {
        $this->regras(antecipado: true);
        $this->pagar($this->matricula, 2_500_000, [[$this->p[1], 2_500_000]]);

        app(AlterarEstadoMatriculaAction::class)->executar($this->matricula->fresh(), EstadoMatriculaEnum::CANCELADA, null, '2026-10-01');

        $this->assertSame(EstadoCobranca::CANCELADA, $this->p[3]->fresh()->estado, 'Novembro: futura sem pagamentos.');
        $this->assertSame(EstadoCobranca::EM_ABERTO, $this->p[2]->fresh()->estado, 'Outubro fica como dívida.');

        $this->pagar($this->matricula, 2_500_000, [[$this->p[2], 2_500_000]]);
        $this->assertSame(EstadoCobranca::PAGA, $this->p[2]->fresh()->estado);

        try {
            $this->pagar($this->matricula, 2_500_000, [[$this->p[3], 2_500_000]]);
            $this->fail('Uma cancelada não é pagável.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('distribuicao.0.propina_id', $e->errors());
        }

        $this->assertPagamentosCoerentes();
    }

    public function test_propina_futura_com_historico_nao_e_cancelada_pelo_evento_e_anula_se_a_mao(): void
    {
        $this->regras(antecipado: true);
        $this->anularPagamento($this->pagar($this->matricula, 2_500_000, [[$this->p[5], 2_500_000]])); // Janeiro: histórico

        app(AlterarEstadoMatriculaAction::class)->executar($this->matricula->fresh(), EstadoMatriculaEnum::CANCELADA, null, '2026-10-01');

        $this->assertSame(EstadoCobranca::EM_ABERTO, $this->p[5]->fresh()->estado, 'Com histórico não se cancela (F3 + Pagamentos).');
        $this->assertSame(EstadoCobranca::CANCELADA, $this->p[6]->fresh()->estado);
        $this->assertSame(EstadoCobranca::ANULADA, app(AnularPropinaAction::class)->executar(
            $this->p[5]->fresh(), new MotivoDeAnulacaoDTO('Matrícula cancelada'), auth()->id(),
        )->estado);
    }

    public function test_pagamento_real_entre_previa_e_confirmacao_faz_recusar_a_alteracao(): void
    {
        $this->regras(parcial: true, antecipado: true);
        $impressao = $this->previa($this->plano, '2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS)->impressaoDigital;
        $this->pagar($this->matricula, 1_000_000, [[$this->p[6], 1_000_000]]);

        $this->assertRecusa('impressao_digital', 'mudaram', fn () => app(AlterarPrecoPlanoAction::class)->executar(
            $this->plano->fresh(),
            $this->pedidoAlteracao('2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS, 'Actualização anual', $impressao),
        ));
        $this->assertSame(2_500_000, $this->p[6]->fresh()->valor->unidadesMenores());
    }

    public function test_aplicar_com_parcial_real_preserva_o_recebido(): void
    {
        $this->regras(parcial: true, antecipado: true);
        $this->pagar($this->matricula, 1_000_000, [[$this->p[6], 1_000_000]]);

        $this->alterarPreco($this->plano, '2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS);

        $fevereiro = Propina::query()->whereKey($this->p[6]->id)->firstOrFail();
        $this->assertSame([3_000_000, 1_000_000, EstadoCobranca::PARCIALMENTE_PAGA], [$fevereiro->valor->unidadesMenores(), $fevereiro->valor_pago->unidadesMenores(), $fevereiro->estado]);
        $this->assertPagamentosCoerentes();
    }

    public function test_anular_pagamento_depois_de_descida_abaixo_do_pago(): void
    {
        $this->regras(parcial: true, antecipado: true);
        $pagamento = $this->pagar($this->matricula, 2_000_000, [[$this->p[6], 2_000_000]]);
        $this->alterarPreco($this->plano, '2027-02', 1_500_000, ModoAlteracaoPreco::APLICAR_GERADAS);
        $this->assertSame(EstadoCobranca::PAGA, $this->p[6]->fresh()->estado, 'valor = valor_pago (2.000.000), sem devolução.');

        $this->anularPagamento($pagamento);

        $fevereiro = $this->p[6]->fresh();
        $this->assertSame(EstadoCobranca::EM_ABERTO, $fevereiro->estado);
        $this->assertSame(2_000_000, $fevereiro->valor->unidadesMenores());
        $this->assertSame(2_500_000, $fevereiro->valor_original->unidadesMenores());
        $this->assertPagamentosCoerentes();
    }

    public function test_reconciliacao_global_depois_de_uma_sequencia_mista(): void
    {
        $this->regras(parcial: true, antecipado: true);
        $a = $this->pagar($this->matricula, 6_000_000, [[$this->p[1], 2_500_000], [$this->p[2], 2_500_000]]); // + 1.000.000 crédito
        $this->aplicarCredito($this->p[3], 1_000_000);                         // Nov parcial por crédito
        $b = $this->pagar($this->matricula, 2_000_000, [[$this->p[3], 1_500_000]]); // Nov paga + 500.000 crédito
        $this->anularPagamento($b);                                            // Nov volta a Parcial (só crédito); crédito de b anulado
        $this->pagar($this->matricula, 1_500_000, [[$this->p[3], 1_500_000]], data: '2026-10-10');

        $this->assertSame(EstadoCobranca::PAGA, $this->p[3]->fresh()->estado);
        $this->assertSame('2026-10-16', $this->p[3]->fresh()->capital_liquidado_em->toDateString(), 'A contribuição que fechou o saldo é a utilização de hoje (ordem por data).');
        $this->assertNotNull($a->fresh()->credito);
        $this->assertPagamentosCoerentes();
    }
}
```

**Explicação do último teste** (para o executor não "corrigir" a asserção): Novembro recebe 1.000.000 por crédito com data 16/10 e 1.500.000 em dinheiro com data 10/10. Ordenadas por data, o dinheiro (10/10) vem primeiro e acumula 1.500.000; a utilização (16/10) leva o acumulado a 2.500.000 → `capital_liquidado_em = 2026-10-16`.

Em `FinanceiroTenancyTest` acrescentar `use ComPagamentosFinanceiro;` (e `use Carbon\CarbonImmutable;`, `use Illuminate\Support\Carbon;`, `use Modules\Financeiro\Actions\RegistarPagamentoAction;`, `use Illuminate\Validation\ValidationException;`, `use Modules\Financeiro\Models\Credito;`, `use Modules\Financeiro\Models\Pagamento;`, `use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;`), um `tearDown` com `Carbon::setTestNow();` e:
```php
    public function test_pagamentos_e_creditos_so_aparecem_no_tenant_dono(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $this->noTenant($outro, function () {
            ['matricula' => $matricula] = $this->cenarioPropinas();
            $this->regras();
            $this->pagar($matricula, 1_000_000); // sem propinas: tudo crédito
        });

        $this->assertSame(0, Pagamento::query()->count());
        $this->assertSame(0, Credito::query()->count());
        $this->noTenant($outro, fn () => $this->assertSame([1, 1], [Pagamento::query()->count(), Credito::query()->count()]));
    }

    public function test_metodo_ou_propina_de_outro_tenant_sao_recusados(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        [$metodoDela, $propinaDela] = $this->noTenant($outro, function () {
            ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

            return [$this->metodoPagamento('Numerário B'), $this->propina($matricula, $plano, 1)];
        });
        ['plano' => $plano, 'matricula' => $minha] = $this->cenarioPropinas();
        $this->propina($minha, $plano, 1);
        $this->regras();

        foreach ([
            'metodo_pagamento_id' => $this->pedidoPagamento(2_500_000, null, metodoId: $metodoDela->id),
            'distribuicao.0.propina_id' => $this->pedidoPagamento(2_500_000, [[$propinaDela->id, 2_500_000]]),
        ] as $campo => $pedido) {
            try {
                app(RegistarPagamentoAction::class)->executar($minha, $pedido);
                $this->fail("Devia recusar em {$campo}.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($campo, $e->errors());
            }
        }

        $this->assertSame(0, Pagamento::query()->count());
    }
```

- [ ] **Step 2: Correr**

Run: `php artisan test Modules/Financeiro/tests/Feature/PagamentosIntegracaoPropinasTest.php Modules/Financeiro/tests/Feature/FinanceiroTenancyTest.php`
Expected: PASS. Uma falha aqui é um defeito de T2–T10 (ou de F3/F4 exposto por pagamentos reais): diagnosticar com `superpowers:systematic-debugging`, corrigir no código de produção, nunca enfraquecer a asserção.

- [ ] **Step 3: Stage**

```bash
git add Modules/Financeiro/tests/Feature/PagamentosIntegracaoPropinasTest.php Modules/Financeiro/tests/Feature/FinanceiroTenancyTest.php
```

---

### Task 12: Leitura — `PagamentoConsultaService` (spec §7)

**Files:**
- Create: `Modules/Financeiro/app/Services/PagamentoConsultaService.php`
- Create: `Modules/Financeiro/tests/Feature/PagamentoConsultaTest.php`

**Interfaces:**
- Consumes: models de T2/T3, `DistribuicaoDePagamentoService::propinasPagaveis/creditosDisponiveis` (T6), `AnularPagamentoAction::motivoParaNaoAnular` (T9, só leitura), `RelogioDoTenant`, `RegraCobranca::doTenant()`, `RotuloDePeriodo`, `TaxaCambio::deMicros()->formatar()`, macro `whereContem`.
- Produces (todas as quantias em unidades menores; datas `Y-m-d`; instantes `Y-m-d H:i` no fuso da escola):
  - `listar(array $filtros = []): LengthAwarePaginator` — filtros `pesquisa` (n.º do pagamento, n.º de registo da matrícula, n.º do aluno, nome), `metodo_pagamento_id`, `estado`, `data_de`, `data_ate`, `referencia`, `aluno_id`, `matricula_id`; linhas `{id, numero, data_pagamento, aluno_id, aluno_nome, aluno_numero, matricula_id, matricula_numero, metodo_nome, referencia, valor, total_distribuido, credito_gerado, estado, estado_descricao}`; `POR_PAGINA = 15`; ordem `data_pagamento desc, id desc`.
  - `metodos(): Collection` (todos, para o filtro), `metodosActivos(): Collection` (registo), `estados(): array` (`EstadoPagamento::opcoes()`).
  - `detalhe(Pagamento): array` — `{id, numero, estado, estado_descricao, data_pagamento, valor, moeda, cambio_usd, referencia, observacao, metodo{id,nome,tipo_descricao}, matricula{id,numero,estado_descricao,aluno_id,aluno_nome,aluno_numero,turma_nome,ano_lectivo_nome}, registado{por,em}, anulacao{motivo,por,em}|null, distribuicoes[{propina_id,periodo,plano_nome,valor,estado_propina_descricao}], credito{id,valor,valor_utilizado,saldo,estado_descricao,utilizacoes[…]}|null, utilizacoes_combinadas[{id,credito_id,propina_id,periodo,matricula_numero,valor,data_utilizacao,estado,estado_descricao,motivo_anulacao}], anulacao_bloqueada}` (as `utilizacoes_combinadas` são as feitas no mesmo acto, O8, em qualquer estado; o modal de anulação lista as Activas).
  - `pesquisarMatriculas(string $termo): list<array{id,numero,estado_descricao,aluno_id,aluno_nome,aluno_numero,turma_nome,ano_lectivo_nome}>` (≥ 2 caracteres, máx. 10, qualquer estado, sem eliminadas).
  - `resumoDaMatricula(int $id): ?array` (a mesma forma de uma linha da pesquisa; `null` se não existir). Todas as formas de `matricula` ganham `data_matricula` (`Y-m-d`): é o limite inferior do campo da data no registo (O3).
  - `situacao(Matricula): array` — `{matricula{…}, hoje, regras{permite_pagamento_parcial, permite_pagamento_antecipado}, propinas[{id,periodo,periodo_inicio,data_vencimento,valor,valor_pago,saldo,estado,estado_descricao,futura}], creditos[{id,valor,saldo,origem_numero,origem_data}], credito_disponivel}`.
  - `creditosDoAluno(Aluno): array` — `{aluno{id,nome,numero}, saldo_disponivel, creditos[{id,origem_pagamento_id,origem_numero,origem_data,valor,valor_utilizado,saldo,estado,estado_descricao,utilizacoes[{id,propina_id,periodo,matricula_numero,valor,data_utilizacao,estado,estado_descricao,motivo_anulacao}]}], matriculas[{id,numero,ano_lectivo_nome,turma_nome,estado_descricao}]}`.
  - `daPropina(Propina): list<array{tipo: 'pagamento'|'credito', id: int, pagamento_id: ?int, numero: ?string, data: string, valor: int, estado_descricao: string, activo: bool}>` — distribuições e utilizações da propina, por data.

- [ ] **Step 1: Escrever os testes**

`Modules/Financeiro/tests/Feature/PagamentoConsultaTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Financeiro\Enums\EstadoPagamento;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\PagamentoConsultaService;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Matricula\Models\Matricula;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

class PagamentoConsultaTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPagamentosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    private Matricula $matricula;

    /** @var array<int, Propina> */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
        ['plano' => $plano, 'matricula' => $this->matricula] = $this->cenarioPropinas();

        foreach ([1, 2, 3] as $ordem) {
            $this->p[$ordem] = $this->propina($this->matricula, $plano, $ordem);
        }

        $this->actingAs($this->adminEscola());
        $this->regras(parcial: true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function consulta(): PagamentoConsultaService
    {
        return app(PagamentoConsultaService::class);
    }

    public function test_lista_com_totais_e_filtros(): void
    {
        $a = $this->pagar($this->matricula, 3_000_000, [[$this->p[1], 2_500_000]], data: '2026-10-01', referencia: 'TRF-1');
        $b = $this->pagar($this->matricula, 1_000_000, [[$this->p[2], 1_000_000]], data: '2026-10-10');
        $this->anularPagamento($b);

        $linhas = $this->consulta()->listar()->items();
        $this->assertSame([$b->id, $a->id], array_column($linhas, 'id'), 'Mais recente primeiro.');
        $this->assertSame([3_000_000, 2_500_000, 500_000], [$linhas[1]['valor'], $linhas[1]['total_distribuido'], $linhas[1]['credito_gerado']]);

        $this->assertSame([$a->id], array_column($this->consulta()->listar(['estado' => EstadoPagamento::CONFIRMADO->value])->items(), 'id'));
        $this->assertSame([$a->id], array_column($this->consulta()->listar(['referencia' => 'TRF'])->items(), 'id'));
        $this->assertSame([$b->id], array_column($this->consulta()->listar(['data_de' => '2026-10-05'])->items(), 'id'));
        $this->assertSame([$a->id], array_column($this->consulta()->listar(['data_ate' => '2026-10-05'])->items(), 'id'));
        $this->assertSame([$a->id], array_column($this->consulta()->listar(['pesquisa' => $a->numero])->items(), 'id'));
        $this->assertCount(2, $this->consulta()->listar(['pesquisa' => $this->matricula->numero_registo_matricula])->items());
        $this->assertCount(0, $this->consulta()->listar(['metodo_pagamento_id' => $this->metodoPagamento('TPA')->id])->items());
        $this->assertCount(2, $this->consulta()->listar(['data_de' => 'lixo'])->items(), 'Datas inválidas são ignoradas.');
    }

    public function test_detalhe_com_distribuicao_credito_e_bloqueio_de_anulacao(): void
    {
        $origem = $this->pagar($this->matricula, 3_000_000, [[$this->p[1], 2_500_000]]); // 500.000 de crédito
        $this->aplicarCredito($this->p[2], 500_000);

        $detalhe = $this->consulta()->detalhe($origem);

        $this->assertSame($origem->numero, $detalhe['numero']);
        $this->assertSame('Confirmado', $detalhe['estado_descricao']);
        $this->assertSame([[$this->p[1]->id, 2_500_000]], array_map(fn ($d) => [$d['propina_id'], $d['valor']], $detalhe['distribuicoes']));
        $this->assertSame([500_000, 500_000, 0], [$detalhe['credito']['valor'], $detalhe['credito']['valor_utilizado'], $detalhe['credito']['saldo']]);
        $this->assertCount(1, $detalhe['credito']['utilizacoes']);
        $this->assertStringContainsString('anule primeiro', $detalhe['anulacao_bloqueada']);
        $this->assertNull($detalhe['anulacao']);
        $this->assertSame($this->matricula->numero_registo_matricula, $detalhe['matricula']['numero']);
    }

    public function test_detalhe_lista_as_utilizacoes_combinadas_no_mesmo_acto(): void
    {
        $this->pagar($this->matricula, 3_000_000, [[$this->p[1], 2_500_000]]); // 500.000 de crédito
        $combinado = $this->pagar($this->matricula, 2_000_000, [[$this->p[2], 2_500_000]], credito: 500_000);

        $detalhe = $this->consulta()->detalhe($combinado);

        $this->assertCount(1, $detalhe['utilizacoes_combinadas']);
        $this->assertSame([$this->p[2]->id, 500_000, 'Activa', $combinado->id], [
            $detalhe['utilizacoes_combinadas'][0]['propina_id'], $detalhe['utilizacoes_combinadas'][0]['valor'],
            $detalhe['utilizacoes_combinadas'][0]['estado_descricao'], $detalhe['utilizacoes_combinadas'][0]['pagamento_id'],
        ]);

        $this->anularPagamento($combinado);

        $depois = $this->consulta()->detalhe($combinado->fresh());
        $this->assertSame(['Activa'], array_column($depois['utilizacoes_combinadas'], 'estado_descricao'), 'O8: continuam Activas e listadas.');
    }

    public function test_pesquisa_de_matriculas_e_situacao(): void
    {
        $this->creditoDirecto($this->pagamentoDirecto($this->matricula, 700_000), 700_000);

        $this->assertSame([], $this->consulta()->pesquisarMatriculas('a'));
        $this->assertSame([$this->matricula->id], array_column($this->consulta()->pesquisarMatriculas('Aluno de Teste'), 'id'));

        $situacao = $this->consulta()->situacao($this->matricula);

        $this->assertSame('2026-10-16', $situacao['hoje']);
        $this->assertSame('2026-09-01', $situacao['matricula']['data_matricula'], 'Limite inferior da data do pagamento (O3).');
        $this->assertSame(['permite_pagamento_parcial' => true, 'permite_pagamento_antecipado' => false], $situacao['regras']);
        $this->assertSame([$this->p[1]->id, $this->p[2]->id, $this->p[3]->id], array_column($situacao['propinas'], 'id'));
        $this->assertSame([false, false, true], array_column($situacao['propinas'], 'futura'));
        $this->assertSame(700_000, $situacao['credito_disponivel']);
    }

    public function test_creditos_do_aluno_e_pagamentos_da_propina(): void
    {
        $this->pagar($this->matricula, 3_000_000, [[$this->p[1], 2_500_000]]);
        $utilizacao = $this->aplicarCredito($this->p[2], 300_000);
        $this->anularUtilizacao($utilizacao);
        $this->aplicarCredito($this->p[2], 200_000);

        $creditos = $this->consulta()->creditosDoAluno($this->matricula->aluno);

        $this->assertSame(300_000, $creditos['saldo_disponivel']);
        $this->assertSame(['Anulada', 'Activa'], array_column($creditos['creditos'][0]['utilizacoes'], 'estado_descricao'));
        $this->assertSame([$this->matricula->id], array_column($creditos['matriculas'], 'id'));

        $daPropina = $this->consulta()->daPropina($this->p[2]->fresh());
        $this->assertSame(['credito', 'credito'], array_column($daPropina, 'tipo'));
        $this->assertSame([false, true], array_column($daPropina, 'activo'));
    }

    public function test_outro_tenant_e_invisivel(): void
    {
        $this->pagar($this->matricula, 1_000_000, [[$this->p[1], 1_000_000]]);
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');

        $this->noTenant($outro, function () {
            $this->assertSame(0, $this->consulta()->listar()->total());
            $this->assertSame([], $this->consulta()->pesquisarMatriculas('Aluno de Teste'));
        });
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/PagamentoConsultaTest.php`
Expected: FAIL (serviço inexistente).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Services/PagamentoConsultaService.php`:
```php
<?php

namespace Modules\Financeiro\Services;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Modules\Aluno\Models\Aluno;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\Actions\AnularPagamentoAction;
use Modules\Financeiro\Enums\EstadoPagamento;
use Modules\Financeiro\Enums\EstadoUtilizacaoCredito;
use Modules\Financeiro\Models\Credito;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Models\MetodoPagamento;
use Modules\Financeiro\Models\Pagamento;
use Modules\Financeiro\Models\PagamentoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Support\RotuloDePeriodo;
use Modules\Financeiro\Support\TaxaCambio;
use Modules\Matricula\Models\Matricula;

/**
 * Leitura de pagamentos e créditos (spec Pagamentos §7). Quantias em unidades menores; datas Y-m-d; instantes no fuso
 * da escola. Nunca escreve.
 */
class PagamentoConsultaService
{
    public const POR_PAGINA = 15;

    public function __construct(
        private RelogioDoTenant $relogio,
        private DistribuicaoDePagamentoService $distribuicao,
        private AnularPagamentoAction $anulacao,
    ) {
    }

    public function listar(array $filtros = []): LengthAwarePaginator
    {
        $estado = is_numeric($filtros['estado'] ?? null) ? EstadoPagamento::tryFrom((int) $filtros['estado']) : null;
        $pesquisa = is_string($filtros['pesquisa'] ?? null) ? trim($filtros['pesquisa']) : '';
        $referencia = is_string($filtros['referencia'] ?? null) ? trim($filtros['referencia']) : '';

        return Pagamento::query()
            ->with(['matricula.aluno.dadosPessoa', 'metodo', 'credito'])
            ->withSum('distribuicoes as total_distribuido', 'valor')
            ->when($estado !== null, fn ($q) => $q->where('estado', $estado->value))
            ->when(is_numeric($filtros['metodo_pagamento_id'] ?? null), fn ($q) => $q->where('metodo_pagamento_id', (int) $filtros['metodo_pagamento_id']))
            ->when(is_numeric($filtros['aluno_id'] ?? null), fn ($q) => $q->where('aluno_id', (int) $filtros['aluno_id']))
            ->when(is_numeric($filtros['matricula_id'] ?? null), fn ($q) => $q->where('matricula_id', (int) $filtros['matricula_id']))
            ->when($this->data($filtros['data_de'] ?? null), fn ($q, string $dia) => $q->where('data_pagamento', '>=', $dia))
            ->when($this->data($filtros['data_ate'] ?? null), fn ($q, string $dia) => $q->where('data_pagamento', '<=', $dia))
            ->when($referencia !== '', fn ($q) => $q->whereContem('referencia', $referencia))
            ->when($pesquisa !== '', fn ($q) => $q->where(fn ($grupo) => $grupo
                ->whereContem('numero', $pesquisa)
                ->orWhereHas('matricula', fn ($matricula) => $matricula->withTrashed()->where(fn ($m) => $m
                    ->whereContem('numero_registo_matricula', $pesquisa)
                    ->orWhereHas('aluno', fn ($aluno) => $aluno->where(fn ($a) => $a
                        ->whereContem('numero_matricula', $pesquisa)
                        ->orWhereHas('dadosPessoa', fn ($pessoa) => $pessoa->whereContem('nome_completo', $pesquisa))))))))
            ->orderByDesc('data_pagamento')
            ->orderByDesc('id')
            ->paginate(self::POR_PAGINA)
            ->withQueryString()
            ->through(fn (Pagamento $pagamento) => [
                'id' => $pagamento->id,
                'numero' => $pagamento->numero,
                'data_pagamento' => $pagamento->data_pagamento->toDateString(),
                'aluno_id' => $pagamento->aluno_id,
                'aluno_nome' => $pagamento->matricula?->aluno?->dadosPessoa?->nome_completo,
                'aluno_numero' => $pagamento->matricula?->aluno?->numero_matricula,
                'matricula_id' => $pagamento->matricula_id,
                'matricula_numero' => $pagamento->matricula?->numero_registo_matricula,
                'metodo_nome' => $pagamento->metodo?->nome,
                'referencia' => $pagamento->referencia,
                'valor' => $pagamento->valor->unidadesMenores(),
                'total_distribuido' => (int) $pagamento->total_distribuido,
                'credito_gerado' => $pagamento->credito?->valor->unidadesMenores() ?? 0,
                'estado' => $pagamento->estado->value,
                'estado_descricao' => $pagamento->estado->label(),
            ]);
    }

    /** @return Collection<int, MetodoPagamento> */
    public function metodos(): Collection
    {
        return MetodoPagamento::query()->orderBy('nome')->get(['id', 'nome', 'estado']);
    }

    /** @return Collection<int, MetodoPagamento> */
    public function metodosActivos(): Collection
    {
        return MetodoPagamento::query()->activos()->orderBy('nome')->get(['id', 'nome', 'tipo']);
    }

    /** @return list<array{value: int, label: string}> */
    public function estados(): array
    {
        return EstadoPagamento::opcoes();
    }

    /** @return array<string, mixed> */
    public function detalhe(Pagamento $pagamento): array
    {
        // load (não loadMissing): relações já carregadas (ex.: o `credito` devolvido pela Action) podem estar obsoletas.
        $pagamento->load([
            'matricula.aluno.dadosPessoa', 'matricula.turma', 'matricula.anoLectivo', 'metodo', 'criadoPor', 'anuladoPor',
            'distribuicoes.propina.plano', 'credito.utilizacoes.propina.matricula', 'utilizacoesCombinadas.propina.matricula',
        ]);
        $fuso = $this->relogio->fuso();
        $credito = $pagamento->credito;

        return [
            'id' => $pagamento->id,
            'numero' => $pagamento->numero,
            'estado' => $pagamento->estado->value,
            'estado_descricao' => $pagamento->estado->label(),
            'data_pagamento' => $pagamento->data_pagamento->toDateString(),
            'valor' => $pagamento->valor->unidadesMenores(),
            'moeda' => $pagamento->moeda,
            'cambio_usd' => $pagamento->cambio_usd === null ? null : TaxaCambio::deMicros($pagamento->cambio_usd)->formatar(),
            'referencia' => $pagamento->referencia,
            'observacao' => $pagamento->observacao,
            'metodo' => ['id' => $pagamento->metodo?->id, 'nome' => $pagamento->metodo?->nome, 'tipo_descricao' => $pagamento->metodo?->tipo?->label()],
            'matricula' => $this->resumo($pagamento->matricula),
            'registado' => ['por' => $pagamento->criadoPor?->name, 'em' => $this->instante($pagamento->created_at, $fuso)],
            'anulacao' => $pagamento->estado === EstadoPagamento::ANULADO ? [
                'motivo' => $pagamento->motivo_anulacao,
                'por' => $pagamento->anuladoPor?->name,
                'em' => $this->instante($pagamento->anulado_em, $fuso),
            ] : null,
            'distribuicoes' => $pagamento->distribuicoes->map(fn (PagamentoPropina $d) => [
                'propina_id' => $d->propina_id,
                'periodo' => RotuloDePeriodo::periodo($d->propina->periodo_inicio->toDateString(), $d->propina->periodo_fim->toDateString()),
                'plano_nome' => $d->propina->plano?->nome,
                'valor' => $d->valor->unidadesMenores(),
                'estado_propina_descricao' => $d->propina->estadoResolvido($this->relogio->hoje())->label(),
            ])->values()->all(),
            'credito' => $credito === null ? null : [
                'id' => $credito->id,
                'valor' => $credito->valor->unidadesMenores(),
                'valor_utilizado' => $credito->valor_utilizado->unidadesMenores(),
                'saldo' => $credito->saldo()->unidadesMenores(),
                'estado_descricao' => $credito->estado->label(),
                'utilizacoes' => $credito->utilizacoes->map(fn (CreditoUtilizacao $u) => $this->utilizacao($u))->values()->all(),
            ],
            'utilizacoes_combinadas' => $pagamento->utilizacoesCombinadas->map(fn (CreditoUtilizacao $u) => $this->utilizacao($u))->values()->all(),
            'anulacao_bloqueada' => $this->anulacao->motivoParaNaoAnular($pagamento),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function pesquisarMatriculas(string $termo): array
    {
        $termo = trim($termo);

        if (mb_strlen($termo) < 2) {
            return [];
        }

        return Matricula::query()
            ->with(['aluno.dadosPessoa', 'turma', 'anoLectivo'])
            ->where(fn ($grupo) => $grupo
                ->whereContem('numero_registo_matricula', $termo)
                ->orWhereHas('aluno', fn ($aluno) => $aluno->where(fn ($a) => $a
                    ->whereContem('numero_matricula', $termo)
                    ->orWhereHas('dadosPessoa', fn ($pessoa) => $pessoa->whereContem('nome_completo', $termo)))))
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (Matricula $m) => $this->resumo($m))
            ->values()
            ->all();
    }

    /** @return array<string, mixed>|null */
    public function resumoDaMatricula(int $id): ?array
    {
        $matricula = Matricula::query()->with(['aluno.dadosPessoa', 'turma', 'anoLectivo'])->find($id);

        return $matricula === null ? null : $this->resumo($matricula);
    }

    /** @return array<string, mixed> */
    public function situacao(Matricula $matricula): array
    {
        $hoje = $this->relogio->hoje();
        $regras = RegraCobranca::doTenant();
        $propinas = $this->distribuicao->propinasPagaveis($matricula)
            ->sort(fn (Propina $a, Propina $b) => [$a->periodo_inicio->toDateString(), $a->ordem, $a->id] <=> [$b->periodo_inicio->toDateString(), $b->ordem, $b->id])
            ->values();
        $creditos = $this->distribuicao->creditosDisponiveis($matricula)->load('origem');

        return [
            'matricula' => $this->resumo($matricula->loadMissing(['aluno.dadosPessoa', 'turma', 'anoLectivo'])),
            'hoje' => $hoje->toDateString(),
            'regras' => [
                'permite_pagamento_parcial' => (bool) $regras->permite_pagamento_parcial,
                'permite_pagamento_antecipado' => (bool) $regras->permite_pagamento_antecipado,
            ],
            'propinas' => $propinas->map(function (Propina $p) use ($hoje) {
                $estado = $p->estadoResolvido($hoje);

                return [
                    'id' => $p->id,
                    'periodo' => RotuloDePeriodo::periodo($p->periodo_inicio->toDateString(), $p->periodo_fim->toDateString()),
                    'periodo_inicio' => $p->periodo_inicio->toDateString(),
                    'data_vencimento' => $p->data_vencimento->toDateString(),
                    'valor' => $p->valor->unidadesMenores(),
                    'valor_pago' => $p->valor_pago->unidadesMenores(),
                    'saldo' => $p->saldo()->unidadesMenores(),
                    'estado' => $estado->value,
                    'estado_descricao' => $estado->label(),
                    'futura' => $p->periodo_inicio->toDateString() > $hoje->toDateString(),
                ];
            })->all(),
            'creditos' => $creditos->map(fn (Credito $c) => [
                'id' => $c->id,
                'valor' => $c->valor->unidadesMenores(),
                'saldo' => $c->saldo()->unidadesMenores(),
                'origem_numero' => $c->origem?->numero,
                'origem_data' => $c->origem?->data_pagamento?->toDateString(),
            ])->values()->all(),
            'credito_disponivel' => (int) $creditos->sum(fn (Credito $c) => $c->saldo()->unidadesMenores()),
        ];
    }

    /** @return array<string, mixed> */
    public function creditosDoAluno(Aluno $aluno): array
    {
        $aluno->loadMissing('dadosPessoa');
        $creditos = Credito::query()->where('aluno_id', $aluno->id)->with(['origem', 'utilizacoes.propina.matricula'])->orderBy('id')->get();

        return [
            'aluno' => ['id' => $aluno->id, 'nome' => $aluno->dadosPessoa?->nome_completo, 'numero' => $aluno->numero_matricula],
            'saldo_disponivel' => (int) $creditos->sum(fn (Credito $c) => $c->saldo()->unidadesMenores()),
            'creditos' => $creditos->map(fn (Credito $c) => [
                'id' => $c->id,
                'origem_pagamento_id' => $c->origem_pagamento_id,
                'origem_numero' => $c->origem?->numero,
                'origem_data' => $c->origem?->data_pagamento?->toDateString(),
                'valor' => $c->valor->unidadesMenores(),
                'valor_utilizado' => $c->valor_utilizado->unidadesMenores(),
                'saldo' => $c->saldo()->unidadesMenores(),
                'estado' => $c->estado->value,
                'estado_descricao' => $c->estado->label(),
                'utilizacoes' => $c->utilizacoes->map(fn (CreditoUtilizacao $u) => $this->utilizacao($u))->values()->all(),
            ])->values()->all(),
            'matriculas' => Matricula::query()->where('aluno_id', $aluno->id)->with(['turma', 'anoLectivo'])->orderByDesc('id')->get()
                ->map(fn (Matricula $m) => [
                    'id' => $m->id,
                    'numero' => $m->numero_registo_matricula,
                    'ano_lectivo_nome' => $m->anoLectivo?->nome,
                    'turma_nome' => $m->turma?->nome,
                    'estado_descricao' => $m->estado?->label(),
                ])->values()->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function daPropina(Propina $propina): array
    {
        $linhas = PagamentoPropina::query()->where('propina_id', $propina->getKey())->with('pagamento')->get()
            ->map(fn (PagamentoPropina $d) => [
                'tipo' => 'pagamento',
                'id' => $d->id,
                'pagamento_id' => $d->pagamento_id,
                'numero' => $d->pagamento->numero,
                'data' => $d->pagamento->data_pagamento->toDateString(),
                'valor' => $d->valor->unidadesMenores(),
                'estado_descricao' => $d->pagamento->estado->label(),
                'activo' => $d->pagamento->estado === EstadoPagamento::CONFIRMADO,
            ])
            ->concat(CreditoUtilizacao::query()->where('propina_id', $propina->getKey())->with('credito.origem')->get()
                ->map(fn (CreditoUtilizacao $u) => [
                    'tipo' => 'credito',
                    'id' => $u->id,
                    'pagamento_id' => $u->credito?->origem_pagamento_id,
                    'numero' => $u->credito?->origem?->numero,
                    'data' => $u->data_utilizacao->toDateString(),
                    'valor' => $u->valor->unidadesMenores(),
                    'estado_descricao' => $u->estado->label(),
                    'activo' => $u->estado === EstadoUtilizacaoCredito::ACTIVA,
                ]));

        return $linhas->sortBy([['data', 'asc'], ['tipo', 'desc'], ['id', 'asc']])->values()->all();
    }

    /** @return array<string, mixed>|null */
    private function resumo(?Matricula $m): ?array
    {
        return $m === null ? null : [
            'id' => $m->id,
            'numero' => $m->numero_registo_matricula,
            'data_matricula' => $m->data_matricula?->toDateString(),
            'estado_descricao' => $m->estado?->label(),
            'aluno_id' => $m->aluno_id,
            'aluno_nome' => $m->aluno?->dadosPessoa?->nome_completo,
            'aluno_numero' => $m->aluno?->numero_matricula,
            'turma_nome' => $m->turma?->nome,
            'ano_lectivo_nome' => $m->anoLectivo?->nome,
        ];
    }

    /** @return array<string, mixed> */
    private function utilizacao(CreditoUtilizacao $u): array
    {
        return [
            'id' => $u->id,
            'credito_id' => $u->credito_id,
            'pagamento_id' => $u->pagamento_id,
            'propina_id' => $u->propina_id,
            'periodo' => $u->propina === null ? null : RotuloDePeriodo::periodo($u->propina->periodo_inicio->toDateString(), $u->propina->periodo_fim->toDateString()),
            'matricula_numero' => $u->propina?->matricula?->numero_registo_matricula,
            'valor' => $u->valor->unidadesMenores(),
            'data_utilizacao' => $u->data_utilizacao->toDateString(),
            'estado' => $u->estado->value,
            'estado_descricao' => $u->estado->label(),
            'motivo_anulacao' => $u->motivo_anulacao,
        ];
    }

    private function instante(?CarbonInterface $instante, string $fuso): ?string
    {
        return $instante === null ? null : $instante->copy()->setTimezone($fuso)->format('Y-m-d H:i');
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
(Notas: `withTrashed()` no `orWhereHas('matricula', …)` porque um pagamento de uma Pendente eliminada continua listável — confirmar que o macro `whereHas` aceita o `withTrashed()` dentro do closure; se não, retirar e documentar. O `sortBy` com vários critérios exige Laravel ≥ 8 — existe.)

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Feature/PagamentoConsultaTest.php`
Expected: PASS.

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Services/PagamentoConsultaService.php Modules/Financeiro/tests/Feature/PagamentoConsultaTest.php
```

---

### Task 13: HTTP — pedidos, controllers e rotas de pagamentos e créditos (D18, D19)

**Files:**
- Create: `Modules/Financeiro/app/Http/Requests/Concerns/ValidaPagamento.php`
- Create: `Modules/Financeiro/app/Http/Requests/Concerns/ValidaMotivoDeAnulacao.php`
- Create: `Modules/Financeiro/app/Http/Requests/PreVisualizarPagamentoRequest.php`, `RegistarPagamentoRequest.php`, `AplicarCreditoRequest.php`, `AnularPagamentoRequest.php`, `AnularUtilizacaoCreditoRequest.php`
- Create: `Modules/Financeiro/app/Http/Controllers/PagamentoController.php`, `CreditoController.php`
- Modify: `Modules/Financeiro/routes/web.php`
- Create: `Modules/Financeiro/tests/Feature/PagamentoHttpTest.php`

**Interfaces:**
- Consumes: T6–T9 (DTOs e Actions), T12 (`PagamentoConsultaService`), `MoedaDoTenant`, `Moeda::de()`, `RelogioDoTenant`, `TenantContext`, `ValorMonetario`.
- Produces (rotas; todas `auth`):

| Método | URI | Nome | Ability | Resposta |
|---|---|---|---|---|
| GET | `/financeiro/pagamentos` | `financeiro.pagamentos.index` | `pagamento.ver` | Inertia `Financeiro/Pagamentos/Index` |
| GET | `/financeiro/pagamentos/novo` | `financeiro.pagamentos.create` | `pagamento.criar` | Inertia `Financeiro/Pagamentos/Create` (`?matricula=ID` opcional) |
| GET | `/financeiro/pagamentos/matriculas?q=` | `financeiro.pagamentos.matriculas.pesquisar` | `pagamento.criar` | JSON `{matriculas}` |
| GET | `/financeiro/pagamentos/matriculas/{matricula}/situacao` | `financeiro.pagamentos.matriculas.situacao` | `pagamento.criar` | JSON `{situacao}` |
| POST | `/financeiro/pagamentos/matriculas/{matricula}/pre-visualizar` | `financeiro.pagamentos.pre-visualizar` | `pagamento.criar` | JSON `{plano}` / 422 |
| POST | `/financeiro/pagamentos/matriculas/{matricula}` | `financeiro.pagamentos.store` | `pagamento.criar` | redirect `show` + flash |
| GET | `/financeiro/pagamentos/{pagamento}` | `financeiro.pagamentos.show` | `pagamento.ver` | Inertia `Financeiro/Pagamentos/Show` |
| PATCH | `/financeiro/pagamentos/{pagamento}/anular` | `financeiro.pagamentos.anular` | `pagamento.anular` | back + flash |
| GET | `/financeiro/creditos/alunos/{aluno}` | `financeiro.creditos.aluno` | `pagamento.ver` | Inertia `Financeiro/Creditos/Aluno` |
| POST | `/financeiro/creditos/matriculas/{matricula}/pre-visualizar` | `financeiro.creditos.pre-visualizar` | `pagamento.criar` | JSON `{plano}` |
| POST | `/financeiro/creditos/matriculas/{matricula}/aplicar` | `financeiro.creditos.aplicar` | `pagamento.criar` | back + flash |
| PATCH | `/financeiro/creditos/utilizacoes/{utilizacao}/anular` | `financeiro.creditos.utilizacoes.anular` | `pagamento.anular` | back + flash |

  Respostas: a pré-visualização devolve `{plano, avisos: list<string>}` (O1: aviso se a referência já foi usada num pagamento Anulado do mesmo método; nunca bloqueia) e o registo põe esse mesmo aviso em `flash.warning` além do `success`. A data do pagamento é validada no pedido contra `data_matricula` da matrícula da rota (O3).

  Corpo do registo/pré-visualização: `metodo_pagamento_id`, `valor` (decimal), `data_pagamento` (`Y-m-d`), `referencia?`, `observacao?`, `valor_credito?` (decimal), `modo_distribuicao` (`automatica`|`explicita`), `distribuicao` (`[{propina_id, valor}]`, obrigatória e possivelmente vazia em `explicita`, ignorada em `automatica`). Aplicar crédito: `valor_credito`, `modo_distribuicao`, `distribuicao`. Anular: `motivo`.

- [ ] **Step 1: Escrever os testes**

`Modules/Financeiro/tests/Feature/PagamentoHttpTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\EstadoPagamento;
use Modules\Financeiro\Models\Pagamento;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Matricula\Models\Matricula;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

class PagamentoHttpTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPagamentosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    private Matricula $matricula;

    private Propina $set;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
        ['plano' => $plano, 'matricula' => $this->matricula] = $this->cenarioPropinas();
        $this->set = $this->propina($this->matricula, $plano, 1);
        $this->regras();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array<string, mixed> valores em decimal (moeda da escola, 2 casas) */
    private function corpo(array $sobrepor = []): array
    {
        return array_merge([
            'metodo_pagamento_id' => $this->metodoPagamento()->id,
            'valor' => '25000,00',
            'data_pagamento' => '2026-10-16',
            'referencia' => 'TRF-100',
            'modo_distribuicao' => 'explicita',
            'distribuicao' => [['propina_id' => $this->set->id, 'valor' => '25000,00']],
        ], $sobrepor);
    }

    public function test_todas_as_rotas_exigem_a_ability_certa(): void
    {
        $esperado = [
            'financeiro.pagamentos.index' => 'can:pagamento.ver',
            'financeiro.pagamentos.create' => 'can:pagamento.criar',
            'financeiro.pagamentos.matriculas.pesquisar' => 'can:pagamento.criar',
            'financeiro.pagamentos.matriculas.situacao' => 'can:pagamento.criar',
            'financeiro.pagamentos.pre-visualizar' => 'can:pagamento.criar',
            'financeiro.pagamentos.store' => 'can:pagamento.criar',
            'financeiro.pagamentos.show' => 'can:pagamento.ver',
            'financeiro.pagamentos.anular' => 'can:pagamento.anular',
            'financeiro.creditos.aluno' => 'can:pagamento.ver',
            'financeiro.creditos.pre-visualizar' => 'can:pagamento.criar',
            'financeiro.creditos.aplicar' => 'can:pagamento.criar',
            'financeiro.creditos.utilizacoes.anular' => 'can:pagamento.anular',
        ];

        foreach ($esperado as $nome => $ability) {
            $this->assertContains($ability, Route::getRoutes()->getByName($nome)->gatherMiddleware(), $nome);
        }
    }

    public function test_registar_pela_rota_redirecciona_para_o_detalhe(): void
    {
        $this->actingAs($this->adminEscola());

        $resposta = $this->post(route('financeiro.pagamentos.store', $this->matricula->id), $this->corpo());

        $pagamento = Pagamento::query()->sole();
        $resposta->assertRedirect(route('financeiro.pagamentos.show', $pagamento->id))->assertSessionHas('success', "Pagamento n.º {$pagamento->numero} registado.");
        $this->assertSame(2_500_000, $pagamento->valor->unidadesMenores());
        $this->assertSame(EstadoCobranca::PAGA, $this->set->fresh()->estado);
    }

    public function test_validacao_do_pedido(): void
    {
        $this->actingAs($this->adminEscola());
        $de = route('financeiro.pagamentos.create');

        $this->from($de)->post(route('financeiro.pagamentos.store', $this->matricula->id), $this->corpo(['valor' => '0']))->assertSessionHasErrors('valor');
        $this->from($de)->post(route('financeiro.pagamentos.store', $this->matricula->id), $this->corpo(['data_pagamento' => '2026-10-17']))->assertSessionHasErrors('data_pagamento');
        $this->from($de)->post(route('financeiro.pagamentos.store', $this->matricula->id), $this->corpo(['data_pagamento' => '2026-08-31']))->assertSessionHasErrors('data_pagamento'); // O3: antes da matrícula (01/09/2026)
        $this->from($de)->post(route('financeiro.pagamentos.store', $this->matricula->id), $this->corpo(['metodo_pagamento_id' => $this->metodoPagamento('Velho', activo: false)->id]))->assertSessionHasErrors('metodo_pagamento_id');
        $this->from($de)->post(route('financeiro.pagamentos.store', $this->matricula->id), $this->corpo(['distribuicao' => [
            ['propina_id' => $this->set->id, 'valor' => '1,00'], ['propina_id' => $this->set->id, 'valor' => '1,00'],
        ]]))->assertSessionHasErrors('distribuicao.1.propina_id');
        $this->from($de)->post(route('financeiro.pagamentos.store', $this->matricula->id), $this->corpo(['distribuicao' => [['propina_id' => $this->set->id, 'valor' => '1000,00']]]))->assertSessionHasErrors('distribuicao.0.valor');
        $this->assertSame(0, Pagamento::query()->count());
    }

    public function test_pre_visualizar_devolve_o_plano_e_nao_escreve(): void
    {
        $this->actingAs($this->adminEscola());

        $this->postJson(route('financeiro.pagamentos.pre-visualizar', $this->matricula->id), $this->corpo(['valor' => '30000,00', 'modo_distribuicao' => 'automatica']))
            ->assertOk()
            ->assertJsonPath('plano.excedente', 500_000)
            ->assertJsonPath('plano.linhas.0.propina_id', $this->set->id)
            ->assertJsonPath('plano.linhas.0.dinheiro', 2_500_000);
        $this->postJson(route('financeiro.pagamentos.pre-visualizar', $this->matricula->id), $this->corpo(['distribuicao' => []]))
            ->assertOk()->assertJsonPath('plano.linhas', [])->assertJsonPath('plano.excedente', 2_500_000); // explícita vazia = tudo crédito
        $this->postJson(route('financeiro.pagamentos.pre-visualizar', $this->matricula->id), $this->corpo(['data_pagamento' => '2026-10-17']))
            ->assertStatus(422)->assertJsonValidationErrors('data_pagamento');
        $this->postJson(route('financeiro.pagamentos.pre-visualizar', $this->matricula->id), $this->corpo(['data_pagamento' => '2026-08-31']))
            ->assertStatus(422)->assertJsonValidationErrors('data_pagamento');
        $this->postJson(route('financeiro.pagamentos.pre-visualizar', $this->matricula->id), $this->corpo(['data_pagamento' => '2026-09-01']))->assertOk(); // igual à data da matrícula passa
        $this->assertSame(0, Pagamento::query()->count());
    }

    public function test_referencia_usada_num_pagamento_anulado_avisa_sem_bloquear(): void
    {
        $this->actingAs($this->adminEscola());
        $anulado = $this->pagar($this->matricula, 2_500_000, [[$this->set, 2_500_000]], referencia: 'TRF-100');
        $this->anularPagamento($anulado);

        $previa = $this->postJson(route('financeiro.pagamentos.pre-visualizar', $this->matricula->id), $this->corpo())->assertOk()->assertJsonCount(1, 'avisos');
        $this->assertStringContainsString($anulado->numero, $previa->json('avisos.0'));
        $this->postJson(route('financeiro.pagamentos.pre-visualizar', $this->matricula->id), $this->corpo(['referencia' => 'OUTRA']))->assertOk()->assertJsonCount(0, 'avisos');

        $this->post(route('financeiro.pagamentos.store', $this->matricula->id), $this->corpo())->assertSessionHas('success')->assertSessionHas('warning');
        $this->assertSame(1, Pagamento::query()->where('estado', EstadoPagamento::CONFIRMADO->value)->count());
    }

    public function test_pesquisa_situacao_e_paginas(): void
    {
        $this->actingAs($this->adminEscola());

        $this->getJson(route('financeiro.pagamentos.matriculas.pesquisar', ['q' => 'Aluno de']))->assertOk()->assertJsonPath('matriculas.0.id', $this->matricula->id);
        $this->getJson(route('financeiro.pagamentos.matriculas.situacao', $this->matricula->id))->assertOk()->assertJsonPath('situacao.propinas.0.id', $this->set->id);
        $this->get(route('financeiro.pagamentos.index'))->assertOk()->assertInertia(fn (Assert $p) => $p->component('Financeiro/Pagamentos/Index')->has('pagamentos.data')->where('podeRegistar', true));
        $this->get(route('financeiro.pagamentos.create', ['matricula' => $this->matricula->id]))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Financeiro/Pagamentos/Create')->where('hoje', '2026-10-16')->where('matriculaInicial.id', $this->matricula->id));
    }

    public function test_detalhe_e_anular_pela_rota(): void
    {
        $admin = $this->adminEscola();
        $this->actingAs($admin);
        $pagamento = $this->pagar($this->matricula, 2_500_000, [[$this->set, 2_500_000]]);

        $this->get(route('financeiro.pagamentos.show', $pagamento->id))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Financeiro/Pagamentos/Show')->where('pagamento.numero', $pagamento->numero)->where('podeAnular', true));

        $this->from(route('financeiro.pagamentos.show', $pagamento->id))
            ->patch(route('financeiro.pagamentos.anular', $pagamento->id), ['motivo' => 'abc'])->assertSessionHasErrors('motivo');
        $this->from(route('financeiro.pagamentos.show', $pagamento->id))
            ->patch(route('financeiro.pagamentos.anular', $pagamento->id), ['motivo' => 'Talão em duplicado'])
            ->assertRedirect(route('financeiro.pagamentos.show', $pagamento->id))->assertSessionHas('success', 'Pagamento anulado.');
        $this->assertSame(EstadoPagamento::ANULADO, $pagamento->fresh()->estado);
        $this->assertSame($admin->id, (int) $pagamento->fresh()->anulado_por);
    }

    public function test_creditos_do_aluno_aplicar_e_anular_utilizacao_pela_rota(): void
    {
        $this->actingAs($this->adminEscola());
        $this->regras(parcial: true);
        $out = $this->propina($this->matricula, $this->set->plano, 2);
        $this->pagar($this->matricula, 3_000_000, [[$this->set, 2_500_000]]);

        $this->get(route('financeiro.creditos.aluno', $this->matricula->aluno_id))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('Financeiro/Creditos/Aluno')->where('saldo_disponivel', 500_000));
        $this->postJson(route('financeiro.creditos.pre-visualizar', $this->matricula->id), ['valor_credito' => '5000,00', 'modo_distribuicao' => 'automatica'])->assertOk()
            ->assertJsonPath('plano.utilizacoes.0.propina_id', $out->id);
        $this->from(route('financeiro.creditos.aluno', $this->matricula->aluno_id))
            ->post(route('financeiro.creditos.aplicar', $this->matricula->id), ['valor_credito' => '5000,00', 'modo_distribuicao' => 'automatica'])->assertSessionHas('success', 'Crédito aplicado.');
        $utilizacao = $out->utilizacoesDeCredito()->sole();

        $this->from(route('financeiro.creditos.aluno', $this->matricula->aluno_id))
            ->patch(route('financeiro.creditos.utilizacoes.anular', $utilizacao->id), ['motivo' => 'Aplicado à propina errada'])
            ->assertSessionHas('success', 'Utilização de crédito anulada.');
        $this->assertSame(EstadoCobranca::EM_ABERTO, $out->fresh()->estado);
    }

    public function test_professor_nao_entra_e_ids_de_outro_tenant_dao_404(): void
    {
        $pagamento = $this->pagar($this->matricula, 2_500_000, [[$this->set, 2_500_000]]);
        $this->actingAs($this->professor());
        $this->get(route('financeiro.pagamentos.index'))->assertForbidden();
        $this->post(route('financeiro.pagamentos.store', $this->matricula->id), $this->corpo())->assertForbidden();

        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        [$matriculaDela, $pagamentoDela] = $this->noTenant($outro, function () {
            ['plano' => $plano, 'matricula' => $m] = $this->cenarioPropinas();
            $this->regras();

            return [$m, $this->pagar($m, 2_500_000, [[$this->propina($m, $plano, 1), 2_500_000]])];
        });

        $this->actingAs($this->adminEscola());
        $this->get(route('financeiro.pagamentos.show', $pagamentoDela->id))->assertNotFound();
        $this->post(route('financeiro.pagamentos.store', $matriculaDela->id), $this->corpo())->assertNotFound();
        $this->getJson(route('financeiro.pagamentos.matriculas.situacao', $matriculaDela->id))->assertNotFound();
        $this->patch(route('financeiro.pagamentos.anular', $pagamentoDela->id), ['motivo' => 'Tentativa alheia'])->assertNotFound();
        $this->assertSame(EstadoPagamento::CONFIRMADO, $pagamento->fresh()->estado);
    }

    public function test_nao_existe_edicao_nem_eliminacao(): void
    {
        $this->actingAs($this->adminEscola());
        $pagamento = $this->pagar($this->matricula, 2_500_000, [[$this->set, 2_500_000]]);

        $this->put('/financeiro/pagamentos/' . $pagamento->id, $this->corpo())->assertStatus(405);
        $this->delete('/financeiro/pagamentos/' . $pagamento->id)->assertStatus(405);
    }
}
```
(A moeda por omissão do tenant de teste tem 2 casas — `'25000,00'` = 2.500.000 unidades menores, como nos testes de planos. Se o provisionamento usar outra moeda, ajustar os textos.)

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/PagamentoHttpTest.php`
Expected: FAIL (rotas inexistentes).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Http/Requests/Concerns/ValidaPagamento.php`:
```php
<?php

namespace Modules\Financeiro\Http\Requests\Concerns;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\Rule;
use Modules\Core\Enums\Estado;
use Modules\Core\Tenancy\TenantContext;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\DTO\LinhaDeDistribuicao;
use Modules\Financeiro\DTO\RegistarPagamentoDTO;
use Modules\Financeiro\Rules\ValorMonetario;
use Modules\Matricula\Models\Matricula;

/** Regras comuns ao registo e à pré-visualização de um pagamento (D5–D10). As regras de negócio vivem na Action. */
trait ValidaPagamento
{
    public function authorize(): bool
    {
        return $this->user()?->can('pagamento.criar') ?? false;
    }

    protected function prepareForValidation(): void
    {
        foreach (['referencia', 'observacao'] as $campo) {
            if (is_string($this->input($campo))) {
                $this->merge([$campo => trim($this->input($campo)) === '' ? null : trim($this->input($campo))]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->id();
        $hoje = app(RelogioDoTenant::class)->hoje()->toDateString();
        $matricula = $this->route('matricula');
        $minimo = $matricula instanceof Matricula ? $matricula->data_matricula?->toDateString() : null; // O3

        return [
            'metodo_pagamento_id' => ['required', 'integer', Rule::exists('metodos_pagamento', 'id')
                ->where(fn ($q) => $q->where('tenant_id', $tenantId)->where('estado', Estado::ATIVO->value))],
            'valor' => ['required', new ValorMonetario(false)],
            'data_pagamento' => ['bail', 'required', 'date_format:Y-m-d', 'before_or_equal:' . $hoje, function (string $atributo, mixed $valor, Closure $falhar) use ($minimo) {
                if ($minimo !== null && is_string($valor) && $valor < $minimo) {
                    $falhar('A data do pagamento não pode ser anterior à data da matrícula (' . CarbonImmutable::parse($minimo)->format('d/m/Y') . ').');
                }
            }],
            'referencia' => ['nullable', 'string', 'max:' . RegistarPagamentoDTO::REFERENCIA_MAXIMA],
            'observacao' => ['nullable', 'string', 'max:' . RegistarPagamentoDTO::OBSERVACAO_MAXIMA],
            'valor_credito' => ['nullable', new ValorMonetario(true)],
            'modo_distribuicao' => ['required', Rule::in([LinhaDeDistribuicao::AUTOMATICA, LinhaDeDistribuicao::EXPLICITA])],
            'distribuicao' => ['exclude_unless:modo_distribuicao,' . LinhaDeDistribuicao::EXPLICITA, 'present', 'array', 'max:60'],
            'distribuicao.*.propina_id' => ['required', 'integer', 'distinct'],
            'distribuicao.*.valor' => ['required', new ValorMonetario(false)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'metodo_pagamento_id.required' => 'Escolha o método de pagamento.',
            'metodo_pagamento_id.exists' => 'Escolha um método de pagamento activo.',
            'valor.required' => 'Indique o valor recebido.',
            'data_pagamento.required' => 'Indique a data do pagamento.',
            'data_pagamento.date_format' => 'A data do pagamento é inválida.',
            'data_pagamento.before_or_equal' => 'A data do pagamento não pode ser futura.',
            'referencia.max' => 'A referência tem no máximo ' . RegistarPagamentoDTO::REFERENCIA_MAXIMA . ' caracteres.',
            'observacao.max' => 'A observação tem no máximo ' . RegistarPagamentoDTO::OBSERVACAO_MAXIMA . ' caracteres.',
            'modo_distribuicao.required' => 'Indique se a distribuição é automática ou explícita.',
            'distribuicao.present' => 'Envie a distribuição (pode ser vazia).',
            'distribuicao.max' => 'Demasiadas linhas na distribuição.',
            'distribuicao.*.propina_id.distinct' => 'Cada propina só pode aparecer uma vez na distribuição.',
            'distribuicao.*.valor.required' => 'Indique o valor de cada linha.',
        ];
    }
}
```

`Modules/Financeiro/app/Http/Requests/PreVisualizarPagamentoRequest.php` e `RegistarPagamentoRequest.php` (iguais, nomes diferentes por clareza nas rotas):
```php
<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Modules\Financeiro\Http\Requests\Concerns\ValidaPagamento;

class RegistarPagamentoRequest extends BaseRequest
{
    use ValidaPagamento;
}
```
(e `class PreVisualizarPagamentoRequest extends BaseRequest { use ValidaPagamento; }`.)

`Modules/Financeiro/app/Http/Requests/AplicarCreditoRequest.php`:
```php
<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Illuminate\Validation\Rule;
use Modules\Financeiro\DTO\LinhaDeDistribuicao;
use Modules\Financeiro\Rules\ValorMonetario;

class AplicarCreditoRequest extends BaseRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('pagamento.criar') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'valor_credito' => ['required', new ValorMonetario(false)],
            'modo_distribuicao' => ['required', Rule::in([LinhaDeDistribuicao::AUTOMATICA, LinhaDeDistribuicao::EXPLICITA])],
            'distribuicao' => ['exclude_unless:modo_distribuicao,' . LinhaDeDistribuicao::EXPLICITA, 'present', 'array', 'max:60'],
            'distribuicao.*.propina_id' => ['required', 'integer', 'distinct'],
            'distribuicao.*.valor' => ['required', new ValorMonetario(false)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'valor_credito.required' => 'Indique quanto crédito aplicar.',
            'distribuicao.*.propina_id.distinct' => 'Cada propina só pode aparecer uma vez na distribuição.',
        ];
    }
}
```

`Modules/Financeiro/app/Http/Requests/Concerns/ValidaMotivoDeAnulacao.php`:
```php
<?php

namespace Modules\Financeiro\Http\Requests\Concerns;

use Modules\Financeiro\DTO\MotivoDeAnulacaoDTO;

trait ValidaMotivoDeAnulacao
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('motivo'))) {
            $this->merge(['motivo' => trim($this->input('motivo'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['motivo' => ['required', 'string', 'min:' . MotivoDeAnulacaoDTO::MINIMO, 'max:' . MotivoDeAnulacaoDTO::MAXIMO]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'motivo.required' => 'Indique o motivo da anulação.',
            'motivo.min' => 'O motivo tem de ter pelo menos ' . MotivoDeAnulacaoDTO::MINIMO . ' caracteres.',
            'motivo.max' => 'O motivo não pode ter mais de ' . MotivoDeAnulacaoDTO::MAXIMO . ' caracteres.',
        ];
    }
}
```

`AnularPagamentoRequest.php` e `AnularUtilizacaoCreditoRequest.php`:
```php
<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Modules\Financeiro\Http\Requests\Concerns\ValidaMotivoDeAnulacao;

class AnularPagamentoRequest extends BaseRequest
{
    use ValidaMotivoDeAnulacao;

    public function authorize(): bool
    {
        return $this->user()?->can('pagamento.anular') ?? false;
    }
}
```
(`AnularUtilizacaoCreditoRequest`: o mesmo, com `pagamento.anular`.)

`Modules/Financeiro/app/Http/Controllers/PagamentoController.php`:
```php
<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\Actions\AnularPagamentoAction;
use Modules\Financeiro\Actions\RegistarPagamentoAction;
use Modules\Financeiro\DTO\MotivoDeAnulacaoDTO;
use Modules\Financeiro\DTO\RegistarPagamentoDTO;
use Modules\Financeiro\Http\Requests\AnularPagamentoRequest;
use Modules\Financeiro\Http\Requests\PreVisualizarPagamentoRequest;
use Modules\Financeiro\Http\Requests\RegistarPagamentoRequest;
use Modules\Financeiro\Models\Pagamento;
use Modules\Financeiro\Services\DistribuicaoDePagamentoService;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Services\PagamentoConsultaService;
use Modules\Financeiro\Support\Moeda;
use Modules\Matricula\Models\Matricula;

class PagamentoController extends Controller
{
    public function __construct(
        private PagamentoConsultaService $consulta,
        private DistribuicaoDePagamentoService $distribuicao,
        private RegistarPagamentoAction $registar,
        private AnularPagamentoAction $anularAction,
        private MoedaDoTenant $moedaDoTenant,
        private RelogioDoTenant $relogio,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('pagamento.ver');

        $filtros = $request->only(['pesquisa', 'metodo_pagamento_id', 'estado', 'data_de', 'data_ate', 'referencia', 'aluno_id', 'matricula_id']);

        return Inertia::render('Financeiro/Pagamentos/Index', [
            'pagamentos' => $this->consulta->listar($filtros),
            'filtros' => $filtros,
            'metodos' => $this->consulta->metodos(),
            'estados' => $this->consulta->estados(),
            'moeda' => $this->moedaDoTenant->atual()->paraFrontend(),
            'podeRegistar' => $request->user()?->can('pagamento.criar') ?? false,
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('pagamento.criar');

        return Inertia::render('Financeiro/Pagamentos/Create', [
            'metodos' => $this->consulta->metodosActivos(),
            'moeda' => $this->moedaDoTenant->atual()->paraFrontend(),
            'hoje' => $this->relogio->hoje()->toDateString(),
            'matriculaInicial' => $request->filled('matricula') ? $this->consulta->resumoDaMatricula($request->integer('matricula')) : null,
        ]);
    }

    public function pesquisarMatriculas(Request $request): JsonResponse
    {
        $this->authorize('pagamento.criar');

        return response()->json(['matriculas' => $this->consulta->pesquisarMatriculas((string) $request->query('q', ''))]);
    }

    public function situacao(Matricula $matricula): JsonResponse
    {
        $this->authorize('pagamento.criar');

        return response()->json(['situacao' => $this->consulta->situacao($matricula)]);
    }

    public function preVisualizar(PreVisualizarPagamentoRequest $request, Matricula $matricula): JsonResponse
    {
        $this->authorize('pagamento.criar');

        $dto = RegistarPagamentoDTO::fromRequest($request);
        $aviso = $this->distribuicao->avisoDeReferencia($dto->metodoPagamentoId, $dto->referencia); // O1: não bloqueia

        return response()->json([
            'plano' => $this->distribuicao->preVisualizarPagamento($matricula, $dto)->toArray(),
            'avisos' => $aviso === null ? [] : [$aviso],
        ]);
    }

    public function store(RegistarPagamentoRequest $request, Matricula $matricula)
    {
        $this->authorize('pagamento.criar');

        $dto = RegistarPagamentoDTO::fromRequest($request);
        $aviso = $this->distribuicao->avisoDeReferencia($dto->metodoPagamentoId, $dto->referencia); // O1: antes de registar; não bloqueia

        $pagamento = $this->registar->executar($matricula, $dto);

        $resposta = redirect()->route('financeiro.pagamentos.show', $pagamento->id)->with('success', "Pagamento n.º {$pagamento->numero} registado.");

        return $aviso === null ? $resposta : $resposta->with('warning', $aviso);
    }

    public function show(Request $request, Pagamento $pagamento)
    {
        $this->authorize('pagamento.ver');

        $detalhe = $this->consulta->detalhe($pagamento);
        $podeAnular = $request->user()?->can('pagamento.anular') ?? false;

        return Inertia::render('Financeiro/Pagamentos/Show', [
            'pagamento' => $detalhe,
            'moeda' => Moeda::de($pagamento->moeda)->paraFrontend(),
            // O servidor decide: permissão E pagamento anulável (o botão nunca aparece para um 422 certo).
            'podeAnular' => $podeAnular && $detalhe['anulacao_bloqueada'] === null,
            'podeAnularUtilizacoes' => $podeAnular,
        ]);
    }

    public function anular(AnularPagamentoRequest $request, Pagamento $pagamento)
    {
        $this->authorize('pagamento.anular');

        $this->anularAction->executar($pagamento, MotivoDeAnulacaoDTO::fromRequest($request), $request->user()?->id);

        return redirect()->back()->with('success', 'Pagamento anulado.');
    }
}
```

`Modules/Financeiro/app/Http/Controllers/CreditoController.php`:
```php
<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Aluno\Models\Aluno;
use Modules\Financeiro\Actions\AnularUtilizacaoCreditoAction;
use Modules\Financeiro\Actions\AplicarCreditoAction;
use Modules\Financeiro\DTO\AplicarCreditoDTO;
use Modules\Financeiro\DTO\MotivoDeAnulacaoDTO;
use Modules\Financeiro\Http\Requests\AnularUtilizacaoCreditoRequest;
use Modules\Financeiro\Http\Requests\AplicarCreditoRequest;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Services\DistribuicaoDePagamentoService;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Services\PagamentoConsultaService;
use Modules\Matricula\Models\Matricula;

class CreditoController extends Controller
{
    public function __construct(
        private PagamentoConsultaService $consulta,
        private DistribuicaoDePagamentoService $distribuicao,
        private AplicarCreditoAction $aplicarAction,
        private AnularUtilizacaoCreditoAction $anularAction,
        private MoedaDoTenant $moedaDoTenant,
    ) {
    }

    public function aluno(Request $request, Aluno $aluno)
    {
        $this->authorize('pagamento.ver');

        return Inertia::render('Financeiro/Creditos/Aluno', [
            ...$this->consulta->creditosDoAluno($aluno),
            'moeda' => $this->moedaDoTenant->atual()->paraFrontend(),
            'podeAplicar' => $request->user()?->can('pagamento.criar') ?? false,
            'podeAnular' => $request->user()?->can('pagamento.anular') ?? false,
        ]);
    }

    public function preVisualizar(AplicarCreditoRequest $request, Matricula $matricula): JsonResponse
    {
        $this->authorize('pagamento.criar');

        return response()->json(['plano' => $this->distribuicao->preVisualizarCredito($matricula, AplicarCreditoDTO::fromRequest($request))->toArray()]);
    }

    public function aplicar(AplicarCreditoRequest $request, Matricula $matricula)
    {
        $this->authorize('pagamento.criar');

        $this->aplicarAction->executar($matricula, AplicarCreditoDTO::fromRequest($request));

        return redirect()->back()->with('success', 'Crédito aplicado.');
    }

    public function anularUtilizacao(AnularUtilizacaoCreditoRequest $request, CreditoUtilizacao $utilizacao)
    {
        $this->authorize('pagamento.anular');

        $this->anularAction->executar($utilizacao, MotivoDeAnulacaoDTO::fromRequest($request), $request->user()?->id);

        return redirect()->back()->with('success', 'Utilização de crédito anulada.');
    }
}
```

`Modules/Financeiro/routes/web.php` — acrescentar no fim (com `use` dos dois controllers no topo):
```php
Route::middleware(['auth'])->prefix('financeiro/pagamentos')->name('financeiro.pagamentos.')->group(function () {
    Route::get('/', [PagamentoController::class, 'index'])->middleware('can:pagamento.ver')->name('index');
    Route::get('/novo', [PagamentoController::class, 'create'])->middleware('can:pagamento.criar')->name('create');
    Route::get('/matriculas', [PagamentoController::class, 'pesquisarMatriculas'])->middleware('can:pagamento.criar')->name('matriculas.pesquisar');
    Route::get('/matriculas/{matricula}/situacao', [PagamentoController::class, 'situacao'])->whereNumber('matricula')->middleware('can:pagamento.criar')->name('matriculas.situacao');
    Route::post('/matriculas/{matricula}/pre-visualizar', [PagamentoController::class, 'preVisualizar'])->whereNumber('matricula')->middleware('can:pagamento.criar')->name('pre-visualizar');
    Route::post('/matriculas/{matricula}', [PagamentoController::class, 'store'])->whereNumber('matricula')->middleware('can:pagamento.criar')->name('store');
    Route::get('/{pagamento}', [PagamentoController::class, 'show'])->whereNumber('pagamento')->middleware('can:pagamento.ver')->name('show');
    Route::patch('/{pagamento}/anular', [PagamentoController::class, 'anular'])->whereNumber('pagamento')->middleware('can:pagamento.anular')->name('anular');
});

Route::middleware(['auth'])->prefix('financeiro/creditos')->name('financeiro.creditos.')->group(function () {
    Route::get('/alunos/{aluno}', [CreditoController::class, 'aluno'])->whereNumber('aluno')->middleware('can:pagamento.ver')->name('aluno');
    Route::post('/matriculas/{matricula}/pre-visualizar', [CreditoController::class, 'preVisualizar'])->whereNumber('matricula')->middleware('can:pagamento.criar')->name('pre-visualizar');
    Route::post('/matriculas/{matricula}/aplicar', [CreditoController::class, 'aplicar'])->whereNumber('matricula')->middleware('can:pagamento.criar')->name('aplicar');
    Route::patch('/utilizacoes/{utilizacao}/anular', [CreditoController::class, 'anularUtilizacao'])->whereNumber('utilizacao')->middleware('can:pagamento.anular')->name('utilizacoes.anular');
});
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Feature/PagamentoHttpTest.php tests/Feature/RotasPermissaoReconhecidaTest.php tests/Feature/RotasEscritaAutorizadasTest.php Modules/Financeiro/tests/Feature/PermissoesPorRecursoTest.php`
Expected: PASS (as abilities `pagamento.*` são reconhecidas porque `Modulo::PAGAMENTO` declara as acções; todas as rotas de escrita têm `can:` e FormRequest com `authorize`).

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Http/Requests/Concerns/ValidaPagamento.php Modules/Financeiro/app/Http/Requests/Concerns/ValidaMotivoDeAnulacao.php Modules/Financeiro/app/Http/Requests/PreVisualizarPagamentoRequest.php Modules/Financeiro/app/Http/Requests/RegistarPagamentoRequest.php Modules/Financeiro/app/Http/Requests/AplicarCreditoRequest.php Modules/Financeiro/app/Http/Requests/AnularPagamentoRequest.php Modules/Financeiro/app/Http/Requests/AnularUtilizacaoCreditoRequest.php Modules/Financeiro/app/Http/Controllers/PagamentoController.php Modules/Financeiro/app/Http/Controllers/CreditoController.php Modules/Financeiro/routes/web.php Modules/Financeiro/tests/Feature/PagamentoHttpTest.php
```

---

### Task 14: Detalhe da propina — pagamentos, utilizações e "Anular propina" (spec Propinas §8, §10; D16)

**Files:**
- Create: `Modules/Financeiro/app/Http/Requests/AnularPropinaRequest.php`
- Modify: `Modules/Financeiro/app/Services/PropinaConsultaService.php` (`detalhe()`: `pagamentos`, `anulacao_bloqueada`; construtor)
- Modify: `Modules/Financeiro/app/Http/Controllers/PropinaController.php` (+ `anular`, `podeAnular` no `show`)
- Modify: `Modules/Financeiro/routes/web.php` (+ `PATCH /financeiro/propinas/{propina}/anular`)
- Create: `Modules/Financeiro/tests/Feature/PropinaDetalhePagamentosTest.php`

**Interfaces:**
- Consumes: `PagamentoConsultaService::daPropina` (T12), `AnulacaoDePropinas::motivoParaNaoAnular`, `AnularPropinaAction` (T10), `MotivoDeAnulacaoDTO`, `ValidaMotivoDeAnulacao` (T13).
- Produces: `detalhe()['pagamentos']` = `daPropina()`; `detalhe()['anulacao_bloqueada']: ?string`; prop Inertia `podeAnular` (permissão `propina.anular` **e** anulável); rota `financeiro.propinas.anular` (`can:propina.anular`).

- [ ] **Step 1: Escrever os testes**

`Modules/Financeiro/tests/Feature/PropinaDetalhePagamentosTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

class PropinaDetalhePagamentosTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPagamentosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
        $this->regras();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_o_detalhe_lista_pagamentos_e_so_deixa_anular_quando_tudo_foi_anulado(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano, 1);
        $this->actingAs($this->adminEscola());
        $pagamento = $this->pagar($matricula, 2_500_000, [[$propina, 2_500_000]]);

        $this->get(route('financeiro.propinas.show', $propina->id))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('propina.pagamentos.0.numero', $pagamento->numero)
            ->where('propina.pagamentos.0.activo', true)
            ->where('podeAnular', false)
            ->where('podeCancelar', false));

        $this->anularPagamento($pagamento);

        $this->get(route('financeiro.propinas.show', $propina->id))->assertOk()->assertInertia(fn (Assert $p) => $p
            ->where('propina.pagamentos.0.activo', false)
            ->where('propina.anulacao_bloqueada', null)
            ->where('podeAnular', true)
            ->where('podeCancelar', false));

        $this->from(route('financeiro.propinas.show', $propina->id))
            ->patch(route('financeiro.propinas.anular', $propina->id), ['motivo' => 'Bolsa atribuída'])
            ->assertSessionHas('success', 'Propina anulada.');
        $this->assertSame(EstadoCobranca::ANULADA, $propina->fresh()->estado);
    }

    public function test_sem_historico_a_rota_recusa_e_sem_permissao_da_403(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano, 1);
        $this->assertContains('can:propina.anular', Route::getRoutes()->getByName('financeiro.propinas.anular')->gatherMiddleware());

        $this->actingAs($this->adminEscola());
        $this->from(route('financeiro.propinas.show', $propina->id))
            ->patch(route('financeiro.propinas.anular', $propina->id), ['motivo' => 'Sem histórico'])
            ->assertSessionHasErrors(['propina' => 'A propina nunca recebeu pagamentos: cancele-a em vez de a anular.']);

        $this->actingAs($this->professor());
        $this->patch(route('financeiro.propinas.anular', $propina->id), ['motivo' => 'Sem permissão'])->assertForbidden();
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/PropinaDetalhePagamentosTest.php`
Expected: FAIL (rota/props inexistentes).

- [ ] **Step 3: Implementar**

`AnularPropinaRequest.php`:
```php
<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Modules\Financeiro\Http\Requests\Concerns\ValidaMotivoDeAnulacao;

class AnularPropinaRequest extends BaseRequest
{
    use ValidaMotivoDeAnulacao;

    public function authorize(): bool
    {
        return $this->user()?->can('propina.anular') ?? false;
    }
}
```

`PropinaConsultaService`: construtor passa a
```php
    public function __construct(
        private RelogioDoTenant $relogio,
        private CancelamentoDePropinas $cancelamento,
        private AnulacaoDePropinas $anulacao,
        private PagamentoConsultaService $pagamentos,
    ) {
    }
```
e em `detalhe()` substituir `'pagamentos' => [],` por `'pagamentos' => $this->pagamentos->daPropina($propina),` e acrescentar a seguir a `'cancelamento_bloqueado' => …`:
```php
            'anulacao_bloqueada' => $this->anulacao->motivoParaNaoAnular($propina),
```
(e o docblock "Pagamentos (Pagamentos-A) ainda não existem: lista vazia." → "Pagamentos e utilizações de crédito, por data (PagamentoConsultaService::daPropina).").

`PropinaController`: injectar `AnularPropinaAction $anularAction`; em `show()` acrescentar ao array do render:
```php
            'podeAnular' => ($request->user()?->can('propina.anular') ?? false) && $detalhe['anulacao_bloqueada'] === null,
```
e o método:
```php
    public function anular(AnularPropinaRequest $request, Propina $propina)
    {
        $this->authorize('propina.anular');

        $this->anularAction->executar($propina, MotivoDeAnulacaoDTO::fromRequest($request), $request->user()?->id);

        return redirect()->back()->with('success', 'Propina anulada.');
    }
```
(com `use Modules\Financeiro\Actions\AnularPropinaAction;`, `use Modules\Financeiro\DTO\MotivoDeAnulacaoDTO;`, `use Modules\Financeiro\Http\Requests\AnularPropinaRequest;`).

`routes/web.php`, no grupo `financeiro/propinas`, a seguir à rota `cancelar`:
```php
    Route::patch('/{propina}/anular', [PropinaController::class, 'anular'])->whereNumber('propina')->middleware('can:propina.anular')->name('anular');
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Feature/PropinaDetalhePagamentosTest.php Modules/Financeiro/tests/Feature/PropinaDetalheTest.php Modules/Financeiro/tests/Feature/PropinaDetalheAjustesTest.php Modules/Financeiro/tests/Feature/CancelarPropinaTest.php`
Expected: PASS (`PropinaDetalheTest` continua a ver `pagamentos` vazio para propinas sem pagamentos; se algum teste lá afirmar a lista exacta de chaves do detalhe, acrescentar `anulacao_bloqueada`).

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Http/Requests/AnularPropinaRequest.php Modules/Financeiro/app/Services/PropinaConsultaService.php Modules/Financeiro/app/Http/Controllers/PropinaController.php Modules/Financeiro/routes/web.php Modules/Financeiro/tests/Feature/PropinaDetalhePagamentosTest.php
```

---

### Task 15: Frontend — menu (dois sítios), lista e detalhe do pagamento (D19)

**Files:**
- Modify: `resources/js/Components/Layout/SidebarMenuWrapper.vue` (`const financeiro`)
- Modify: `resources/js/Composables/useFinanceiroMenu.js`
- Modify: `tests/Feature/Arquitectura/MenuFinanceiroTest.php`
- Create: `Modules/Financeiro/resources/js/Models/Pagamento.js`
- Create: `Modules/Financeiro/resources/js/Components/Pagamentos/AnularRegistoModal.vue`
- Create: `Modules/Financeiro/resources/js/Pages/Pagamentos/Index.vue`
- Create: `Modules/Financeiro/resources/js/Pages/Pagamentos/Show.vue`
- Create: `Modules/Financeiro/tests/Unit/PagamentosVueTest.php`

**Interfaces:**
- Consumes: props de `PagamentoController::index/show` (T13), formas de `PagamentoConsultaService::listar/detalhe` (T12); `formatarDinheiro`, `dataCurta`, `SelectSolid`, `Pagination`, `AcaoIcone`, `BotaoVoltar`, `AppLayout`, `can()`.
- Produces: `Models/Pagamento.js` (`ESTADO_PAGAMENTO`, `ESTADO_CREDITO`, `ESTADO_UTILIZACAO`, `MODO_DISTRIBUICAO`, `estadoPagamentoBadgeClass`, `estadoCreditoBadgeClass`); `AnularRegistoModal.vue` (props `show, titulo, subtitulo, aviso, rotuloConfirmar, processing, errors, campoErro`; emits `confirmar(motivo)`, `cancelar`) reutilizado nas Tasks 16–17.

- [ ] **Step 1: Escrever o teste estático (padrão `DetalhePropinaVueTest`/`MenuFinanceiroTest`)**

Em `MenuFinanceiroTest`:
- renomear `test_a_sidebar_mostra_a_seccao_financeiro_so_com_propinas` para `test_a_sidebar_mostra_a_seccao_financeiro_com_propinas_e_pagamentos` e trocar as duas últimas asserções por:
```php
        $this->assertStringContainsString("{ href: '/financeiro/propinas', title: 'Propinas', permissao: 'propina.ver' }", $bloco[1]);
        $this->assertStringContainsString("{ href: '/financeiro/pagamentos', title: 'Pagamentos', permissao: 'pagamento.ver' }", $bloco[1]);
        $this->assertSame(2, preg_match_all('/href:/', $bloco[1]), 'Propinas e Pagamentos activos; os outros itens continuam comentados.');
```
- em `test_o_header_tem_o_mesmo_link_com_a_mesma_permissao`:
```php
        $this->assertStringContainsString("{ href: '/financeiro/propinas', label: 'Propinas', permissao: 'propina.ver' }", $composable);
        $this->assertStringContainsString("{ href: '/financeiro/pagamentos', label: 'Pagamentos', permissao: 'pagamento.ver' }", $composable);
        $this->assertSame(2, preg_match_all('/href:/', $composable));
```
(o docblock da classe passa a "só Propinas e Pagamentos activos").

`Modules/Financeiro/tests/Unit/PagamentosVueTest.php` (as Tasks 16–17 acrescentam métodos):
```php
<?php

namespace Modules\Financeiro\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Não há testes de frontend: lê os ficheiros Vue (sem comentários) e prova as decisões que importam (D19): as acções
 * dependem do que o servidor decidiu, as rotas certas, e o registo envia a distribuição que o resumo mostrou.
 */
class PagamentosVueTest extends TestCase
{
    private function vue(string $relativo): string
    {
        return preg_replace('/<!--.*?-->/s', '', file_get_contents(dirname(__DIR__, 2) . '/resources/js/' . $relativo));
    }

    public function test_a_lista_so_mostra_registar_com_permissao_e_liga_ao_detalhe(): void
    {
        $index = $this->vue('Pages/Pagamentos/Index.vue');

        $this->assertStringContainsString('podeRegistar: { type: Boolean, required: true }', $index);
        $this->assertSame(1, preg_match('/<Link[^>]*v-if="podeRegistar"[^>]*href="\/financeiro\/pagamentos\/novo"/s', $index));
        $this->assertStringContainsString(':href="`/financeiro/pagamentos/${pagamento.id}`"', $index);
    }

    public function test_o_detalhe_so_deixa_anular_quando_o_servidor_permite(): void
    {
        $show = $this->vue('Pages/Pagamentos/Show.vue');

        $this->assertStringContainsString('podeAnular: { type: Boolean, required: true }', $show);
        $this->assertSame(1, preg_match('/<button[^>]*v-if="podeAnular"[^>]*>\s*Anular pagamento/s', $show));
        $this->assertStringContainsString("router.patch(`/financeiro/pagamentos/\${props.pagamento.id}/anular`, { motivo }", $show);
        $this->assertStringContainsString("router.patch(`/financeiro/creditos/utilizacoes/\${utilizacaoAnular.value.id}/anular`, { motivo }", $show);
        $this->assertStringContainsString('pagamento.anulacao_bloqueada', $show);
    }

    public function test_a_anulacao_lista_as_utilizacoes_de_credito_do_mesmo_acto(): void
    {
        $show = $this->vue('Pages/Pagamentos/Show.vue');
        $modal = $this->vue('Components/Pagamentos/AnularRegistoModal.vue');

        $this->assertStringContainsString('pagamento.utilizacoes_combinadas', $show);
        $this->assertStringContainsString(':itens="itensDaAnulacao"', $show);
        $this->assertStringContainsString('itens: { type: Array', $modal);
        $this->assertStringContainsString('v-for="(item, indice) in itens"', $modal);
        $this->assertStringContainsString('page.props.flash?.warning', $show);
    }

    public function test_o_modal_de_anulacao_exige_motivo_com_limites(): void
    {
        $modal = $this->vue('Components/Pagamentos/AnularRegistoModal.vue');

        $this->assertStringContainsString('maxlength="500"', $modal);
        $this->assertStringContainsString('motivo.value.trim().length < 5', $modal);
        $this->assertStringContainsString('errors[campoErro]', $modal);
        $this->assertStringContainsString('errors.motivo', $modal);
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Unit/PagamentosVueTest.php tests/Feature/Arquitectura/MenuFinanceiroTest.php`
Expected: FAIL (ficheiros inexistentes; um só `href`).

- [ ] **Step 3: Implementar**

`SidebarMenuWrapper.vue` — em `const financeiro`, trocar `// { href: '#', title: 'Pagamentos' },` por:
```js
        { href: '/financeiro/pagamentos', title: 'Pagamentos', permissao: 'pagamento.ver' },
```
`useFinanceiroMenu.js` — trocar `// { href: '#', label: 'Pagamentos' },` por:
```js
            { href: '/financeiro/pagamentos', label: 'Pagamentos', permissao: 'pagamento.ver' },
```
(`useActiveMenu.isActive` já acende o link em `/financeiro/pagamentos/novo` e `/financeiro/pagamentos/{id}` por prefixo; a página de créditos do aluno, `/financeiro/creditos/…`, não tem item próprio.)

`Modules/Financeiro/resources/js/Models/Pagamento.js`:
```js
/**
 * Espelha EstadoPagamento, EstadoCredito e EstadoUtilizacaoCredito (PHP). Só para apresentação; a autoridade é o backend.
 */
export const ESTADO_PAGAMENTO = Object.freeze({ CONFIRMADO: 1, ANULADO: 2 });
export const ESTADO_CREDITO = Object.freeze({ DISPONIVEL: 1, ESGOTADO: 2, ANULADO: 3 });
export const ESTADO_UTILIZACAO = Object.freeze({ ACTIVA: 1, ANULADA: 2 });
export const MODO_DISTRIBUICAO = Object.freeze({ AUTOMATICA: 'automatica', EXPLICITA: 'explicita' });

export const estadoPagamentoBadgeClass = (estado) => (estado === ESTADO_PAGAMENTO.CONFIRMADO ? 'badge-light-success' : 'badge-light-dark');

export const estadoCreditoBadgeClass = (estado) => {
    switch (estado) {
        case ESTADO_CREDITO.DISPONIVEL: return 'badge-light-primary';
        case ESTADO_CREDITO.ESGOTADO: return 'badge-light-secondary';
        default: return 'badge-light-dark';
    }
};
```

`Modules/Financeiro/resources/js/Components/Pagamentos/AnularRegistoModal.vue` (mesmo padrão visual de `CancelarPropinaModal.vue`):
```vue
<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    titulo: { type: String, required: true },
    subtitulo: { type: String, default: '' },
    aviso: { type: String, required: true },
    itens: { type: Array, default: () => [] }, // O8: o que NÃO muda com a anulação mas o utilizador deve ver (ex.: utilizações de crédito do mesmo acto)
    itensTitulo: { type: String, default: '' },
    rotuloConfirmar: { type: String, required: true },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
    campoErro: { type: String, required: true }, // 'pagamento' | 'utilizacao' | 'propina'
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
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5); overflow-y: auto;" @click.self="!processing && emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content p-6">
                <h3 class="mb-2">{{ titulo }}</h3>
                <p v-if="subtitulo" class="text-muted fs-6 mb-5">{{ subtitulo }}</p>

                <div class="card bg-body-secondary mb-5">
                    <div class="card-body py-4 fs-7">{{ aviso }}</div>
                </div>

                <div v-if="itens.length" class="mb-5">
                    <div class="fw-semibold fs-7 mb-1">{{ itensTitulo }}</div>
                    <ul class="fs-7 mb-0">
                        <li v-for="(item, indice) in itens" :key="indice">{{ item }}</li>
                    </ul>
                </div>

                <div v-if="errors[campoErro]" class="alert alert-danger fs-7 py-3 mb-5">{{ errors[campoErro] }}</div>

                <label for="motivo-anulacao" class="form-label required fw-semibold">Motivo</label>
                <textarea
                    id="motivo-anulacao"
                    v-model="motivo"
                    class="form-control form-control-solid"
                    :class="{ 'is-invalid': errors.motivo }"
                    rows="3"
                    maxlength="500"
                    placeholder="Ex.: valor errado no talão; registado em duplicado"
                ></textarea>
                <div class="d-flex justify-content-between mt-1">
                    <div class="text-danger fs-7">{{ errors.motivo }}</div>
                    <div class="text-muted fs-8">{{ motivo.length }}/{{ MAXIMO }}</div>
                </div>

                <div class="d-flex justify-content-end gap-3 mt-6">
                    <button type="button" class="btn btn-light" :disabled="processing" @click="emit('cancelar')">Voltar</button>
                    <button type="button" class="btn btn-danger" :disabled="processing || invalido" @click="emit('confirmar', motivo.trim())">
                        {{ processing ? 'A anular…' : rotuloConfirmar }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
```

`Modules/Financeiro/resources/js/Pages/Pagamentos/Index.vue`:
```vue
<script setup>
import { computed, reactive, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import Pagination from '@/Components/Shared/Pagination.vue';
import AcaoIcone from '@/Components/Shared/AcaoIcone.vue';
import { estadoPagamentoBadgeClass } from '../../Models/Pagamento';
import { formatarDinheiro } from '../../Support/dinheiro';
import { dataCurta } from '../../Support/periodos';

const BASE = '/financeiro/pagamentos';

const props = defineProps({
    pagamentos: { type: Object, required: true },
    filtros: { type: Object, default: () => ({}) },
    metodos: { type: Array, required: true },
    estados: { type: Array, required: true },
    moeda: { type: Object, required: true },
    podeRegistar: { type: Boolean, required: true },
});
defineOptions({ layout: AppLayout });

const numeroOuVazio = (valor) => (valor !== undefined && valor !== null && valor !== '' && Number.isFinite(Number(valor)) ? Number(valor) : '');

const filtros = reactive({
    pesquisa: props.filtros.pesquisa ?? '',
    metodo_pagamento_id: numeroOuVazio(props.filtros.metodo_pagamento_id),
    estado: numeroOuVazio(props.filtros.estado),
    referencia: props.filtros.referencia ?? '',
    data_de: props.filtros.data_de ?? '',
    data_ate: props.filtros.data_ate ?? '',
});

const opcoesMetodo = computed(() => [{ value: '', label: 'Todos os métodos' }, ...props.metodos.map((m) => ({ value: m.id, label: m.nome }))]);
const opcoesEstado = computed(() => [{ value: '', label: 'Todos os estados' }, ...props.estados]);

let debounceId = null;
watch(filtros, (valor) => {
    clearTimeout(debounceId);
    debounceId = setTimeout(() => router.get(BASE, valor, { preserveState: true, preserveScroll: true, replace: true }), 300);
});
</script>

<template>
    <div class="app-container container-xxl py-6">
        <div class="d-flex justify-content-between align-items-center mb-6">
            <div>
                <h1 class="fs-2 fw-bold mb-1">Pagamentos</h1>
                <p class="text-muted fs-6 mb-0" style="max-width: 720px">
                    Dinheiro recebido das matrículas, distribuído pelas propinas. O que sobra fica como crédito do aluno.
                    Um pagamento nunca se edita: anula-se com motivo e regista-se de novo.
                </p>
            </div>
            <Link v-if="podeRegistar" href="/financeiro/pagamentos/novo" class="btn btn-primary">Registar pagamento</Link>
        </div>

        <div class="card mb-6">
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-12 col-md-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">Pesquisa</label>
                        <input v-model="filtros.pesquisa" type="text" class="form-control form-control-solid" placeholder="Nº do pagamento, aluno, nº do aluno ou da matrícula" />
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">Método</label>
                        <SelectSolid v-model="filtros.metodo_pagamento_id" :options="opcoesMetodo" />
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">Estado</label>
                        <SelectSolid v-model="filtros.estado" :options="opcoesEstado" />
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="fw-semibold fs-7 text-muted mb-1">Referência</label>
                        <input v-model="filtros.referencia" type="text" class="form-control form-control-solid" />
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="fw-semibold fs-7 text-muted mb-1">Data de</label>
                        <input v-model="filtros.data_de" type="date" class="form-control form-control-solid" />
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="fw-semibold fs-7 text-muted mb-1">Data até</label>
                        <input v-model="filtros.data_ate" type="date" class="form-control form-control-solid" />
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
                                <th class="min-w-100px">Nº</th>
                                <th class="min-w-100px">Data</th>
                                <th class="min-w-175px">Aluno</th>
                                <th class="min-w-100px">Matrícula</th>
                                <th class="min-w-125px">Método</th>
                                <th class="text-end min-w-100px">Valor</th>
                                <th class="text-end min-w-100px">Propinas</th>
                                <th class="text-end min-w-100px">Crédito</th>
                                <th class="min-w-100px">Estado</th>
                                <th class="text-end min-w-75px">Acções</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-600 fw-semibold">
                            <tr v-if="pagamentos.data.length === 0">
                                <td colspan="10" class="text-center text-muted py-6">Nenhum pagamento encontrado.</td>
                            </tr>
                            <tr v-for="pagamento in pagamentos.data" :key="pagamento.id">
                                <td class="text-gray-800">{{ pagamento.numero }}</td>
                                <td>{{ dataCurta(pagamento.data_pagamento) }}</td>
                                <td class="text-gray-800">
                                    {{ pagamento.aluno_nome ?? '—' }}
                                    <div class="text-muted fs-7">nº {{ pagamento.aluno_numero ?? '—' }}</div>
                                </td>
                                <td>{{ pagamento.matricula_numero ?? '—' }}</td>
                                <td>
                                    {{ pagamento.metodo_nome ?? '—' }}
                                    <div v-if="pagamento.referencia" class="text-muted fs-8">{{ pagamento.referencia }}</div>
                                </td>
                                <td class="text-end">{{ formatarDinheiro(pagamento.valor, moeda) }}</td>
                                <td class="text-end">{{ formatarDinheiro(pagamento.total_distribuido, moeda) }}</td>
                                <td class="text-end">{{ pagamento.credito_gerado ? formatarDinheiro(pagamento.credito_gerado, moeda) : '—' }}</td>
                                <td><span class="badge" :class="estadoPagamentoBadgeClass(pagamento.estado)">{{ pagamento.estado_descricao }}</span></td>
                                <td class="text-end">
                                    <Link :href="`/financeiro/pagamentos/${pagamento.id}`" class="btn btn-light btn-active-light-primary btn-sm">
                                        <AcaoIcone acao="visualizar" class="me-1" /> Ver
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div v-if="pagamentos.data.length" class="card-footer d-flex justify-content-end">
                <Pagination :links="pagamentos.links" />
            </div>
        </div>
    </div>
</template>
```

`Modules/Financeiro/resources/js/Pages/Pagamentos/Show.vue`:
```vue
<script setup>
import { computed, onMounted, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotaoVoltar from '@/Components/Shared/BotaoVoltar.vue';
import { can } from '@/Composables/usePermissoes';
import AnularRegistoModal from '../../Components/Pagamentos/AnularRegistoModal.vue';
import { ESTADO_UTILIZACAO, estadoCreditoBadgeClass, estadoPagamentoBadgeClass } from '../../Models/Pagamento';
import { formatarDinheiro } from '../../Support/dinheiro';
import { dataCurta } from '../../Support/periodos';

const props = defineProps({
    pagamento: { type: Object, required: true },
    moeda: { type: Object, required: true },
    podeAnular: { type: Boolean, required: true },
    podeAnularUtilizacoes: { type: Boolean, required: true },
});
defineOptions({ layout: AppLayout });

const page = usePage();
const anularAberto = ref(false);
const utilizacaoAnular = ref(null);
const processing = ref(false);
const errors = ref({});

// O1: o registo redirecciona para aqui com um aviso (referência já usada num pagamento anulado).
onMounted(() => {
    const aviso = page.props.flash?.warning;
    if (aviso) toast.warning(aviso);
});

// O8: as utilizações de crédito do mesmo acto não se anulam com o pagamento; o modal di-lo e lista as que ficam Activas.
const itensDaAnulacao = computed(() => props.pagamento.utilizacoes_combinadas
    .filter((u) => u.estado === ESTADO_UTILIZACAO.ACTIVA)
    .map((u) => `${u.periodo} — ${formatarDinheiro(u.valor, props.moeda)} (${dataCurta(u.data_utilizacao)})`));

const instante = (valor) => (valor ? `${dataCurta(valor.slice(0, 10))} ${valor.slice(11, 16)}` : '—');

function anular(motivo) {
    if (processing.value) return;
    processing.value = true;
    errors.value = {};

    router.patch(`/financeiro/pagamentos/${props.pagamento.id}/anular`, { motivo }, {
        preserveScroll: true,
        onSuccess: () => {
            anularAberto.value = false;
            toast.success(page.props.flash?.success ?? 'Pagamento anulado.');
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

function anularUtilizacao(motivo) {
    if (processing.value) return;
    processing.value = true;
    errors.value = {};

    router.patch(`/financeiro/creditos/utilizacoes/${utilizacaoAnular.value.id}/anular`, { motivo }, {
        preserveScroll: true,
        onSuccess: () => {
            utilizacaoAnular.value = null;
            toast.success(page.props.flash?.success ?? 'Utilização de crédito anulada.');
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
        <BotaoVoltar href="/financeiro/pagamentos" />

        <div class="d-flex flex-wrap justify-content-between align-items-start gap-4 mb-6">
            <div>
                <h1 class="fs-2 fw-bold mb-1">Pagamento nº {{ pagamento.numero }}</h1>
                <p class="text-muted fs-6 mb-2">
                    {{ pagamento.matricula?.aluno_nome ?? '—' }} · matrícula nº {{ pagamento.matricula?.numero ?? '—' }}
                </p>
                <span class="badge" :class="estadoPagamentoBadgeClass(pagamento.estado)">{{ pagamento.estado_descricao }}</span>
            </div>
            <div class="text-end">
                <button v-if="podeAnular" type="button" class="btn btn-light-danger" @click="errors = {}; anularAberto = true">
                    Anular pagamento
                </button>
                <div v-else-if="can('pagamento.anular') && pagamento.anulacao_bloqueada" class="text-muted fs-7" style="max-width: 360px">
                    {{ pagamento.anulacao_bloqueada }}
                </div>
            </div>
        </div>

        <div class="row g-6 mb-6">
            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Pagamento</h3></div>
                    <div class="card-body pt-0">
                        <table class="table table-row-dashed fs-6 gy-3 mb-0">
                            <tbody>
                                <tr><td class="text-muted">Valor recebido</td><td class="text-end fw-bold">{{ formatarDinheiro(pagamento.valor, moeda) }}</td></tr>
                                <tr><td class="text-muted">Data do pagamento</td><td class="text-end">{{ dataCurta(pagamento.data_pagamento) }}</td></tr>
                                <tr><td class="text-muted">Método</td><td class="text-end">{{ pagamento.metodo?.nome ?? '—' }}</td></tr>
                                <tr><td class="text-muted">Referência</td><td class="text-end">{{ pagamento.referencia ?? '—' }}</td></tr>
                                <tr><td class="text-muted">Câmbio (1 USD)</td><td class="text-end">{{ pagamento.cambio_usd ?? '—' }}</td></tr>
                                <tr><td class="text-muted">Registado</td><td class="text-end">{{ instante(pagamento.registado?.em) }} · {{ pagamento.registado?.por ?? 'Sistema' }}</td></tr>
                                <tr v-if="pagamento.observacao"><td class="text-muted">Observação</td><td class="text-end">{{ pagamento.observacao }}</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Matrícula</h3></div>
                    <div class="card-body pt-0">
                        <table class="table table-row-dashed fs-6 gy-3 mb-0">
                            <tbody>
                                <tr><td class="text-muted">Aluno</td><td class="text-end">{{ pagamento.matricula?.aluno_nome ?? '—' }} (nº {{ pagamento.matricula?.aluno_numero ?? '—' }})</td></tr>
                                <tr><td class="text-muted">Turma / Ano</td><td class="text-end">{{ pagamento.matricula?.turma_nome ?? '—' }} · {{ pagamento.matricula?.ano_lectivo_nome ?? '—' }}</td></tr>
                                <tr><td class="text-muted">Estado da matrícula</td><td class="text-end">{{ pagamento.matricula?.estado_descricao ?? '—' }}</td></tr>
                            </tbody>
                        </table>
                        <Link v-if="pagamento.matricula" :href="`/financeiro/creditos/alunos/${pagamento.matricula.aluno_id}`" class="btn btn-light btn-sm mt-4">
                            Créditos do aluno
                        </Link>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="pagamento.anulacao" class="card bg-body-secondary mb-6">
            <div class="card-body py-4 fs-7">
                <strong>Anulado</strong> em {{ instante(pagamento.anulacao.em) }} por {{ pagamento.anulacao.por ?? '—' }} — {{ pagamento.anulacao.motivo }}
            </div>
        </div>

        <div class="row g-6">
            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Distribuição pelas propinas</h3></div>
                    <div class="card-body pt-0">
                        <p v-if="pagamento.distribuicoes.length === 0" class="text-muted mb-0">Nenhuma propina: o valor ficou todo como crédito do aluno.</p>
                        <table v-else class="table table-row-dashed align-middle fs-7 gy-2 mb-0">
                            <thead><tr class="text-muted fw-bold text-uppercase"><th>Período</th><th>Plano</th><th>Estado</th><th class="text-end">Valor</th></tr></thead>
                            <tbody>
                                <tr v-for="d in pagamento.distribuicoes" :key="d.propina_id">
                                    <td><Link :href="`/financeiro/propinas/${d.propina_id}`">{{ d.periodo }}</Link></td>
                                    <td>{{ d.plano_nome ?? '—' }}</td>
                                    <td>{{ d.estado_propina_descricao }}</td>
                                    <td class="text-end">{{ formatarDinheiro(d.valor, moeda) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="card h-100">
                    <div class="card-header"><h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Crédito gerado</h3></div>
                    <div class="card-body pt-0">
                        <p v-if="!pagamento.credito" class="text-muted mb-0">Sem excedente.</p>
                        <template v-else>
                            <p class="mb-3">
                                {{ formatarDinheiro(pagamento.credito.valor, moeda) }} · utilizado {{ formatarDinheiro(pagamento.credito.valor_utilizado, moeda) }} ·
                                saldo {{ formatarDinheiro(pagamento.credito.saldo, moeda) }}
                                <span class="badge ms-2" :class="estadoCreditoBadgeClass(pagamento.credito.estado)">{{ pagamento.credito.estado_descricao }}</span>
                            </p>
                            <table v-if="pagamento.credito.utilizacoes.length" class="table table-row-dashed align-middle fs-7 gy-2 mb-0">
                                <thead><tr class="text-muted fw-bold text-uppercase"><th>Data</th><th>Propina</th><th>Estado</th><th class="text-end">Valor</th><th></th></tr></thead>
                                <tbody>
                                    <tr v-for="u in pagamento.credito.utilizacoes" :key="u.id">
                                        <td>{{ dataCurta(u.data_utilizacao) }}</td>
                                        <td><Link :href="`/financeiro/propinas/${u.propina_id}`">{{ u.periodo }}</Link> <span class="text-muted">· {{ u.matricula_numero }}</span></td>
                                        <td>{{ u.estado_descricao }}</td>
                                        <td class="text-end">{{ formatarDinheiro(u.valor, moeda) }}</td>
                                        <td class="text-end">
                                            <button v-if="podeAnularUtilizacoes && u.estado === ESTADO_UTILIZACAO.ACTIVA" type="button" class="btn btn-sm btn-light-danger" @click="errors = {}; utilizacaoAnular = u">Anular</button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="pagamento.utilizacoes_combinadas.length" class="card mt-6">
            <div class="card-header"><h3 class="card-title fs-6 fw-bold text-uppercase text-gray-600">Crédito usado neste pagamento</h3></div>
            <div class="card-body pt-0">
                <p class="text-muted fs-7">Utilizações de crédito feitas no mesmo acto. São independentes do dinheiro deste pagamento: anular o pagamento não as anula.</p>
                <table class="table table-row-dashed align-middle fs-7 gy-2 mb-0">
                    <thead><tr class="text-muted fw-bold text-uppercase"><th>Data</th><th>Propina</th><th>Estado</th><th class="text-end">Valor</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="u in pagamento.utilizacoes_combinadas" :key="u.id">
                            <td>{{ dataCurta(u.data_utilizacao) }}</td>
                            <td><Link :href="`/financeiro/propinas/${u.propina_id}`">{{ u.periodo }}</Link> <span class="text-muted">· {{ u.matricula_numero }}</span></td>
                            <td>{{ u.estado_descricao }}</td>
                            <td class="text-end">{{ formatarDinheiro(u.valor, moeda) }}</td>
                            <td class="text-end">
                                <button v-if="podeAnularUtilizacoes && u.estado === ESTADO_UTILIZACAO.ACTIVA" type="button" class="btn btn-sm btn-light-danger" @click="errors = {}; utilizacaoAnular = u">Anular</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <AnularRegistoModal
            :show="anularAberto"
            titulo="Anular pagamento"
            :subtitulo="`Nº ${pagamento.numero} — ${formatarDinheiro(pagamento.valor, moeda)}`"
            aviso="O pagamento passa a Anulado e deixa de contar: as propinas que liquidava voltam a ficar em dívida e o crédito gerado é anulado. Nada é apagado. Para corrigir, registe depois o pagamento certo."
            rotulo-confirmar="Anular pagamento"
            :itens="itensDaAnulacao"
            itens-titulo="Continuam Activas depois de anular este pagamento (anule-as à parte, se for caso disso):"
            campo-erro="pagamento"
            :processing="processing"
            :errors="errors"
            @confirmar="anular"
            @cancelar="anularAberto = false"
        />
        <AnularRegistoModal
            :show="utilizacaoAnular !== null"
            titulo="Anular utilização de crédito"
            :subtitulo="utilizacaoAnular ? `${utilizacaoAnular.periodo} — ${formatarDinheiro(utilizacaoAnular.valor, moeda)}` : ''"
            aviso="O crédito recupera este valor e a propina volta a ficar em dívida nessa parte."
            rotulo-confirmar="Anular utilização"
            campo-erro="utilizacao"
            :processing="processing"
            :errors="errors"
            @confirmar="anularUtilizacao"
            @cancelar="utilizacaoAnular = null"
        />
    </div>
</template>
```

- [ ] **Step 4: Correr e ver passar; compilar**

Run: `php artisan test Modules/Financeiro/tests/Unit/PagamentosVueTest.php tests/Feature/Arquitectura/MenuFinanceiroTest.php && npm run build`
Expected: PASS e build sem erros.

- [ ] **Step 5: Stage**

```bash
git add resources/js/Components/Layout/SidebarMenuWrapper.vue resources/js/Composables/useFinanceiroMenu.js tests/Feature/Arquitectura/MenuFinanceiroTest.php Modules/Financeiro/resources/js/Models/Pagamento.js Modules/Financeiro/resources/js/Components/Pagamentos/AnularRegistoModal.vue Modules/Financeiro/resources/js/Pages/Pagamentos/Index.vue Modules/Financeiro/resources/js/Pages/Pagamentos/Show.vue Modules/Financeiro/tests/Unit/PagamentosVueTest.php
```

---

### Task 16: Frontend — registo em passos (matrícula → valor e método → distribuição → resumo) (D19; Review Focus 1)

**Files:**
- Create: `Modules/Financeiro/resources/js/Components/Pagamentos/DistribuicaoResumo.vue`
- Create: `Modules/Financeiro/resources/js/Pages/Pagamentos/Create.vue`
- Modify: `Modules/Financeiro/tests/Unit/PagamentosVueTest.php` (+ 3 testes)

**Interfaces:**
- Consumes: props de `PagamentoController::create` (`metodos`, `moeda`, `hoje`, `matriculaInicial`); JSON de `pesquisarMatriculas`, `situacao`, `preVisualizar` (T13; `plano` = `PlanoDePagamentoDTO::toArray()`); `MODO_DISTRIBUICAO`; `formatarDinheiro`, `unidadesMenoresParaDecimal`, `dataCurta`; `EstadoCobrancaBadge` (`estado`, `estadoDescricao`); `SelectSolid`; `BotaoVoltar`.
- Produces: `DistribuicaoResumo.vue` (props `plano`, `moeda`) — reutilizado no modal de aplicar crédito (T17).

- [ ] **Step 1: Escrever os testes**

Em `PagamentosVueTest` acrescentar:
```php
    public function test_o_registo_confirma_a_distribuicao_que_o_resumo_mostrou(): void
    {
        $create = $this->vue('Pages/Pagamentos/Create.vue');

        $this->assertStringContainsString("axios.post(`/financeiro/pagamentos/matriculas/\${matricula.value.id}/pre-visualizar`, pedidoDaEdicao())", $create);
        $this->assertStringContainsString('unidadesMenoresParaDecimal(l.credito + l.dinheiro, props.moeda)', $create);
        $this->assertStringContainsString("router.post(`/financeiro/pagamentos/matriculas/\${matricula.value.id}`, { ...base(), modo_distribuicao: MODO_DISTRIBUICAO.EXPLICITA, distribuicao }", $create);
        $this->assertStringContainsString(':max="hoje"', $create);
    }

    public function test_o_resumo_avisa_quando_tudo_vai_para_credito(): void
    {
        $create = $this->vue('Pages/Pagamentos/Create.vue');

        $this->assertStringContainsString('Nada a distribuir: o valor fica como crédito do aluno', $create);
        $this->assertStringContainsString('v-model="confirmaTudoCredito"', $create);
        $this->assertStringContainsString('(!tudoParaCredito.value || confirmaTudoCredito.value)', $create);
        $this->assertSame(1, preg_match('/<button[^>]*:disabled="!podeConfirmar"[^>]*>\s*\{\{ processing \? \'A registar…\' : \'Confirmar pagamento\' \}\}/s', $create));
    }

    public function test_o_registo_limita_a_data_e_mostra_os_avisos_sem_bloquear(): void
    {
        $create = $this->vue('Pages/Pagamentos/Create.vue');

        $this->assertStringContainsString(':min="situacao?.matricula?.data_matricula"', $create);
        $this->assertStringContainsString('avisos.value = data.avisos ?? [];', $create);
        $this->assertStringContainsString('v-for="aviso in avisos"', $create);
        $this->assertStringNotContainsString('avisos.length', $create, 'Os avisos nunca desactivam a confirmação (O1).');
    }
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Unit/PagamentosVueTest.php`
Expected: FAIL (`Create.vue` inexistente).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/resources/js/Components/Pagamentos/DistribuicaoResumo.vue`:
```vue
<script setup>
import { formatarDinheiro } from '../../Support/dinheiro';

defineProps({
    plano: { type: Object, required: true }, // PlanoDePagamentoDTO::toArray()
    moeda: { type: Object, required: true },
});
</script>

<template>
    <div>
        <div class="table-responsive">
            <table class="table table-row-dashed align-middle fs-7 gy-2 mb-4">
                <thead>
                    <tr class="text-muted fw-bold text-uppercase">
                        <th>Período</th>
                        <th class="text-end">Em dívida</th>
                        <th class="text-end">Crédito</th>
                        <th class="text-end">Dinheiro</th>
                        <th class="text-end">Fica em dívida</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="plano.linhas.length === 0"><td colspan="5" class="text-muted">Nenhuma propina recebe valor.</td></tr>
                    <tr v-for="linha in plano.linhas" :key="linha.propina_id">
                        <td>{{ linha.periodo }}</td>
                        <td class="text-end">{{ formatarDinheiro(linha.saldo_antes, moeda) }}</td>
                        <td class="text-end">{{ linha.credito ? formatarDinheiro(linha.credito, moeda) : '—' }}</td>
                        <td class="text-end">{{ linha.dinheiro ? formatarDinheiro(linha.dinheiro, moeda) : '—' }}</td>
                        <td class="text-end">{{ formatarDinheiro(linha.saldo_depois, moeda) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="card bg-body-secondary">
            <div class="card-body py-4 fs-7 d-flex flex-wrap gap-6">
                <div>Dinheiro recebido: <strong>{{ formatarDinheiro(plano.dinheiro, moeda) }}</strong></div>
                <div>Crédito usado: <strong>{{ formatarDinheiro(plano.credito, moeda) }}</strong></div>
                <div>Total para propinas: <strong>{{ formatarDinheiro(plano.total_distribuido, moeda) }}</strong></div>
                <div>Crédito novo do aluno: <strong>{{ formatarDinheiro(plano.excedente, moeda) }}</strong></div>
            </div>
        </div>
    </div>
</template>
```

`Modules/Financeiro/resources/js/Pages/Pagamentos/Create.vue`:
```vue
<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotaoVoltar from '@/Components/Shared/BotaoVoltar.vue';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import EstadoCobrancaBadge from '../../Components/Shared/EstadoCobrancaBadge.vue';
import DistribuicaoResumo from '../../Components/Pagamentos/DistribuicaoResumo.vue';
import { MODO_DISTRIBUICAO } from '../../Models/Pagamento';
import { formatarDinheiro, unidadesMenoresParaDecimal } from '../../Support/dinheiro';
import { dataCurta } from '../../Support/periodos';

const props = defineProps({
    metodos: { type: Array, required: true },
    moeda: { type: Object, required: true },
    hoje: { type: String, required: true },
    matriculaInicial: { type: Object, default: null },
});
defineOptions({ layout: AppLayout });

const PASSOS = ['Matrícula', 'Valor e método', 'Distribuição', 'Resumo'];
const passo = ref(1);

const termo = ref('');
const resultados = ref([]);
const matricula = ref(null);
const situacao = ref(null);

const form = reactive({
    metodo_pagamento_id: props.metodos[0]?.id ?? '',
    valor: '',
    data_pagamento: props.hoje,
    referencia: '',
    observacao: '',
    valor_credito: '',
});
const opcoesMetodo = computed(() => props.metodos.map((m) => ({ value: m.id, label: m.nome })));

const modo = ref(MODO_DISTRIBUICAO.AUTOMATICA);
const manual = reactive({}); // propina_id → texto decimal

const plano = ref(null);
const avisos = ref([]); // O1: avisos não bloqueantes devolvidos pela pré-visualização
const confirmaTudoCredito = ref(false);
const errors = ref({});
const aPrever = ref(false);
const processing = ref(false);

const tudoParaCredito = computed(() => plano.value !== null && plano.value.linhas.length === 0 && plano.value.excedente > 0);
const podeConfirmar = computed(() => plano.value !== null && !processing.value && (!tudoParaCredito.value || confirmaTudoCredito.value));
const bloqueadaPorAntecipado = (propina) => propina.futura && !situacao.value?.regras.permite_pagamento_antecipado;
const primeiroErro = (erros) => Object.fromEntries(Object.entries(erros ?? {}).map(([campo, msgs]) => [campo, Array.isArray(msgs) ? msgs[0] : msgs]));

let debounceId = null;
watch(termo, (valor) => {
    clearTimeout(debounceId);

    if (valor.trim().length < 2) {
        resultados.value = [];
        return;
    }

    debounceId = setTimeout(async () => {
        const { data } = await axios.get('/financeiro/pagamentos/matriculas', { params: { q: valor.trim() } });
        resultados.value = data.matriculas;
    }, 300);
});

async function escolher(escolhida) {
    const { data } = await axios.get(`/financeiro/pagamentos/matriculas/${escolhida.id}/situacao`);
    matricula.value = escolhida;
    situacao.value = data.situacao;
    Object.keys(manual).forEach((chave) => delete manual[chave]);
    plano.value = null;
    passo.value = 2;
}

onMounted(() => {
    if (props.matriculaInicial) escolher(props.matriculaInicial);
});

function base() {
    return {
        metodo_pagamento_id: form.metodo_pagamento_id,
        valor: form.valor,
        data_pagamento: form.data_pagamento,
        referencia: form.referencia,
        observacao: form.observacao,
        valor_credito: form.valor_credito || '0',
    };
}

function pedidoDaEdicao() {
    if (modo.value === MODO_DISTRIBUICAO.AUTOMATICA) {
        return { ...base(), modo_distribuicao: MODO_DISTRIBUICAO.AUTOMATICA };
    }

    const distribuicao = Object.entries(manual)
        .filter(([, valor]) => String(valor ?? '').trim() !== '')
        .map(([id, valor]) => ({ propina_id: Number(id), valor: String(valor).trim() }));

    return { ...base(), modo_distribuicao: MODO_DISTRIBUICAO.EXPLICITA, distribuicao };
}

async function preVisualizar() {
    if (aPrever.value) return;
    aPrever.value = true;
    errors.value = {};
    plano.value = null;
    avisos.value = [];
    confirmaTudoCredito.value = false;

    try {
        const { data } = await axios.post(`/financeiro/pagamentos/matriculas/${matricula.value.id}/pre-visualizar`, pedidoDaEdicao());
        plano.value = data.plano;
        avisos.value = data.avisos ?? [];
        passo.value = 4;
    } catch (erro) {
        if (erro.response?.status !== 422) {
            toast.error('Não foi possível calcular a distribuição. Tente de novo.');
            return;
        }

        errors.value = primeiroErro(erro.response.data.errors);
        toast.error(Object.values(errors.value)[0]);
        passo.value = ['metodo_pagamento_id', 'valor', 'data_pagamento', 'referencia', 'observacao', 'valor_credito'].some((c) => errors.value[c]) ? 2 : 3;
    } finally {
        aPrever.value = false;
    }
}

/**
 * Confirma EXACTAMENTE o que o resumo mostrou (D19): distribuição explícita (crédito + dinheiro por propina), mesmo
 * vazia. O servidor revalida tudo sob bloqueio; se os dados mudaram entretanto, recusa e volta-se à distribuição.
 */
function confirmar() {
    if (!podeConfirmar.value) return;
    processing.value = true;
    const distribuicao = plano.value.linhas.map((l) => ({ propina_id: l.propina_id, valor: unidadesMenoresParaDecimal(l.credito + l.dinheiro, props.moeda) }));

    router.post(`/financeiro/pagamentos/matriculas/${matricula.value.id}`, { ...base(), modo_distribuicao: MODO_DISTRIBUICAO.EXPLICITA, distribuicao }, {
        onError: (erros) => {
            errors.value = erros;
            toast.error(Object.values(erros)[0]);
            passo.value = 3;
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}
</script>

<template>
    <div class="app-container container-xxl py-6">
        <BotaoVoltar href="/financeiro/pagamentos" />
        <h1 class="fs-2 fw-bold mb-6">Registar pagamento</h1>

        <div class="d-flex flex-wrap gap-3 mb-6">
            <span v-for="(nome, i) in PASSOS" :key="nome" class="badge fs-7" :class="passo === i + 1 ? 'badge-primary' : 'badge-light'">{{ i + 1 }}. {{ nome }}</span>
        </div>

        <!-- 1. Matrícula -->
        <div v-if="passo === 1" class="card">
            <div class="card-body">
                <label class="form-label required fw-semibold">Matrícula</label>
                <input v-model="termo" type="text" class="form-control form-control-solid mb-4" placeholder="Nome do aluno, nº do aluno ou nº da matrícula (mín. 2 caracteres)" autofocus />
                <p v-if="termo.trim().length >= 2 && resultados.length === 0" class="text-muted fs-7">Nenhuma matrícula encontrada.</p>
                <div class="list-group">
                    <button v-for="m in resultados" :key="m.id" type="button" class="list-group-item list-group-item-action" @click="escolher(m)">
                        <strong>{{ m.aluno_nome ?? '—' }}</strong> <span class="text-muted">· nº {{ m.aluno_numero ?? '—' }}</span>
                        <div class="fs-7 text-muted">Matrícula {{ m.numero }} · {{ m.turma_nome ?? '—' }} · {{ m.ano_lectivo_nome ?? '—' }} · {{ m.estado_descricao }}</div>
                    </button>
                </div>
            </div>
        </div>

        <!-- Cabeçalho da matrícula escolhida (passos 2–4) -->
        <div v-if="passo > 1 && matricula" class="card bg-body-secondary mb-6">
            <div class="card-body py-4 d-flex justify-content-between align-items-center">
                <div>
                    <strong>{{ matricula.aluno_nome }}</strong> · matrícula {{ matricula.numero }} · {{ matricula.turma_nome ?? '—' }} · {{ matricula.ano_lectivo_nome ?? '—' }}
                    <div class="fs-7 text-muted">
                        Crédito disponível do aluno: {{ formatarDinheiro(situacao?.credito_disponivel ?? 0, moeda) }} ·
                        Pagamento parcial {{ situacao?.regras.permite_pagamento_parcial ? 'permitido' : 'não permitido' }} ·
                        Antecipado {{ situacao?.regras.permite_pagamento_antecipado ? 'permitido' : 'não permitido' }}
                    </div>
                </div>
                <button type="button" class="btn btn-light btn-sm" @click="passo = 1">Trocar</button>
            </div>
        </div>

        <!-- 2. Valor e método -->
        <div v-if="passo === 2" class="card">
            <div class="card-body">
                <div class="row g-5">
                    <div class="col-12 col-md-4">
                        <label class="form-label required fw-semibold">Valor recebido ({{ moeda.simbolo }})</label>
                        <input v-model="form.valor" type="text" inputmode="decimal" class="form-control form-control-solid" :class="{ 'is-invalid': errors.valor }" placeholder="Ex.: 25000,00" />
                        <div class="text-danger fs-7">{{ errors.valor }}</div>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label required fw-semibold">Método</label>
                        <SelectSolid v-model="form.metodo_pagamento_id" :options="opcoesMetodo" />
                        <div class="text-danger fs-7">{{ errors.metodo_pagamento_id }}</div>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label required fw-semibold">Data do pagamento</label>
                        <input v-model="form.data_pagamento" type="date" :min="situacao?.matricula?.data_matricula" :max="hoje" class="form-control form-control-solid" :class="{ 'is-invalid': errors.data_pagamento }" />
                        <div class="text-danger fs-7">{{ errors.data_pagamento }}</div>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label fw-semibold">Referência</label>
                        <input v-model="form.referencia" type="text" maxlength="100" class="form-control form-control-solid" :class="{ 'is-invalid': errors.referencia }" placeholder="Nº da transferência, do talão…" />
                        <div class="text-danger fs-7">{{ errors.referencia }}</div>
                    </div>
                    <div v-if="(situacao?.credito_disponivel ?? 0) > 0" class="col-12 col-md-4">
                        <label class="form-label fw-semibold">Usar crédito do aluno ({{ moeda.simbolo }})</label>
                        <input v-model="form.valor_credito" type="text" inputmode="decimal" class="form-control form-control-solid" :class="{ 'is-invalid': errors.valor_credito }" placeholder="0" />
                        <div class="text-muted fs-8">Usado antes do dinheiro novo. Disponível: {{ formatarDinheiro(situacao.credito_disponivel, moeda) }}</div>
                        <div class="text-danger fs-7">{{ errors.valor_credito }}</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Observação</label>
                        <textarea v-model="form.observacao" rows="2" maxlength="1000" class="form-control form-control-solid"></textarea>
                    </div>
                </div>
                <div class="d-flex justify-content-end mt-6">
                    <button type="button" class="btn btn-primary" :disabled="!form.valor || !form.metodo_pagamento_id" @click="passo = 3">Continuar</button>
                </div>
            </div>
        </div>

        <!-- 3. Distribuição -->
        <div v-if="passo === 3" class="card">
            <div class="card-body">
                <div class="d-flex gap-6 mb-5">
                    <label class="form-check form-check-custom form-check-solid">
                        <input v-model="modo" class="form-check-input" type="radio" :value="MODO_DISTRIBUICAO.AUTOMATICA" />
                        <span class="form-check-label">Automática (da mais antiga para a mais recente)</span>
                    </label>
                    <label class="form-check form-check-custom form-check-solid">
                        <input v-model="modo" class="form-check-input" type="radio" :value="MODO_DISTRIBUICAO.EXPLICITA" />
                        <span class="form-check-label">Escolher por propina</span>
                    </label>
                </div>
                <div v-if="errors.distribuicao" class="alert alert-danger fs-7 py-3">{{ errors.distribuicao }}</div>
                <p v-if="!situacao?.propinas.length" class="text-muted">Esta matrícula não tem propinas em dívida: o valor ficará como crédito do aluno.</p>
                <div v-else class="table-responsive">
                    <table class="table table-row-dashed align-middle fs-7 gy-2 mb-0">
                        <thead>
                            <tr class="text-muted fw-bold text-uppercase">
                                <th>Período</th><th>Vencimento</th><th>Estado</th><th class="text-end">Em dívida</th>
                                <th v-if="modo === MODO_DISTRIBUICAO.EXPLICITA" class="text-end min-w-150px">Pagar ({{ moeda.simbolo }})</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(propina, i) in situacao.propinas" :key="propina.id">
                                <td>
                                    {{ propina.periodo }}
                                    <span v-if="bloqueadaPorAntecipado(propina)" class="badge badge-light-warning ms-1" title="A escola não permite pagamento antecipado.">Futura</span>
                                </td>
                                <td>{{ dataCurta(propina.data_vencimento) }}</td>
                                <td><EstadoCobrancaBadge :estado="propina.estado" :estado-descricao="propina.estado_descricao" /></td>
                                <td class="text-end">{{ formatarDinheiro(propina.saldo, moeda) }}</td>
                                <td v-if="modo === MODO_DISTRIBUICAO.EXPLICITA" class="text-end">
                                    <input v-model="manual[propina.id]" type="text" inputmode="decimal" class="form-control form-control-sm form-control-solid text-end"
                                        :disabled="bloqueadaPorAntecipado(propina)"
                                        :placeholder="situacao.regras.permite_pagamento_parcial ? '0' : unidadesMenoresParaDecimal(propina.saldo, moeda)" />
                                    <div class="text-danger fs-8">{{ errors[`distribuicao.${i}.valor`] ?? errors[`distribuicao.${i}.propina_id`] }}</div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="d-flex justify-content-between mt-6">
                    <button type="button" class="btn btn-light" @click="passo = 2">Voltar</button>
                    <button type="button" class="btn btn-primary" :disabled="aPrever" @click="preVisualizar">{{ aPrever ? 'A calcular…' : 'Ver resumo' }}</button>
                </div>
            </div>
        </div>

        <!-- 4. Resumo -->
        <div v-if="passo === 4 && plano" class="card">
            <div class="card-body">
                <DistribuicaoResumo :plano="plano" :moeda="moeda" />
                <div v-for="aviso in avisos" :key="aviso" class="alert alert-warning fs-7 mt-5 mb-0">{{ aviso }}</div>
                <div v-if="tudoParaCredito" class="alert alert-warning fs-7 mt-5">
                    <strong>Nada a distribuir: o valor fica como crédito do aluno.</strong>
                    Se esperava pagar uma propina, verifique se já não foi paga (por exemplo, um registo em duplicado).
                    <label class="form-check form-check-custom form-check-solid mt-3">
                        <input v-model="confirmaTudoCredito" class="form-check-input" type="checkbox" />
                        <span class="form-check-label">Confirmo que o valor fica todo como crédito do aluno.</span>
                    </label>
                </div>
                <div class="d-flex justify-content-between mt-6">
                    <button type="button" class="btn btn-light" :disabled="processing" @click="passo = 3">Voltar</button>
                    <button type="button" class="btn btn-primary" :disabled="!podeConfirmar" @click="confirmar">{{ processing ? 'A registar…' : 'Confirmar pagamento' }}</button>
                </div>
            </div>
        </div>
    </div>
</template>
```
(Notas: a ordem das linhas manuais enviadas segue a tabela, por isso `distribuicao.${i}` do erro corresponde à linha `i` quando todas as linhas estão preenchidas; com linhas vazias o índice desloca-se — aceitável em V1, o erro aparece também no toast. O `form-check` segue as classes Metronic já usadas nos formulários do projecto.)

- [ ] **Step 4: Correr e ver passar; compilar**

Run: `php artisan test Modules/Financeiro/tests/Unit/PagamentosVueTest.php && npm run build`
Expected: PASS e build sem erros.

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/resources/js/Components/Pagamentos/DistribuicaoResumo.vue Modules/Financeiro/resources/js/Pages/Pagamentos/Create.vue Modules/Financeiro/tests/Unit/PagamentosVueTest.php
```

---

### Task 17: Frontend — créditos do aluno, aplicar crédito e anular propina no detalhe (D12, D15, D16)

**Files:**
- Create: `Modules/Financeiro/resources/js/Components/Pagamentos/AplicarCreditoModal.vue`
- Create: `Modules/Financeiro/resources/js/Pages/Creditos/Aluno.vue`
- Modify: `Modules/Financeiro/resources/js/Pages/Propinas/Show.vue` (cartão Pagamentos + "Anular propina")
- Modify: `Modules/Financeiro/resources/js/Components/Propinas/CancelarPropinaModal.vue` (texto)
- Modify: `Modules/Financeiro/tests/Unit/PagamentosVueTest.php` (+ 2 testes)

**Interfaces:**
- Consumes: props de `CreditoController::aluno` (`aluno`, `saldo_disponivel`, `creditos`, `matriculas`, `moeda`, `podeAplicar`, `podeAnular`); JSON de `financeiro.creditos.pre-visualizar`; `PropinaController::show` (`podeAnular`, `propina.pagamentos`, `propina.anulacao_bloqueada`); `AnularRegistoModal` e `DistribuicaoResumo` (T15/T16).
- Produces: UI final de Pagamentos-A.

- [ ] **Step 1: Escrever os testes**

Em `PagamentosVueTest` acrescentar:
```php
    public function test_creditos_do_aluno_aplicam_e_anulam_pelas_rotas_certas(): void
    {
        $aluno = $this->vue('Pages/Creditos/Aluno.vue');
        $modal = $this->vue('Components/Pagamentos/AplicarCreditoModal.vue');

        $this->assertStringContainsString('podeAplicar: { type: Boolean, required: true }', $aluno);
        $this->assertStringContainsString("router.patch(`/financeiro/creditos/utilizacoes/\${utilizacaoAnular.value.id}/anular`, { motivo }", $aluno);
        $this->assertStringContainsString("axios.post(`/financeiro/creditos/matriculas/\${matriculaId.value}/pre-visualizar`", $modal);
        $this->assertStringContainsString("router.post(`/financeiro/creditos/matriculas/\${matriculaId.value}/aplicar`", $modal);
        $this->assertStringContainsString('modo_distribuicao: MODO_DISTRIBUICAO.EXPLICITA', $modal);
    }

    public function test_o_detalhe_da_propina_lista_pagamentos_e_so_anula_quando_o_servidor_permite(): void
    {
        $show = $this->vue('Pages/Propinas/Show.vue');

        $this->assertStringContainsString('podeAnular: { type: Boolean, required: true }', $show);
        $this->assertSame(1, preg_match('/<button[^>]*v-if="podeAnular"[^>]*>\s*Anular propina/s', $show));
        $this->assertStringContainsString("router.patch(`/financeiro/propinas/\${props.propina.id}/anular`, { motivo }", $show);
        $this->assertStringContainsString('v-for="registo in propina.pagamentos"', $show);
        $this->assertStringNotContainsString('quando o módulo de Pagamentos estiver disponível', $show);
        $this->assertStringNotContainsString('com o módulo de Pagamentos', $this->vue('Components/Propinas/CancelarPropinaModal.vue'));
    }
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Unit/PagamentosVueTest.php Modules/Financeiro/tests/Unit/DetalhePropinaVueTest.php`
Expected: FAIL nos dois testes novos.

- [ ] **Step 3: Implementar**

`Modules/Financeiro/resources/js/Components/Pagamentos/AplicarCreditoModal.vue`:
```vue
<script setup>
import { ref, watch } from 'vue';
import axios from 'axios';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import DistribuicaoResumo from './DistribuicaoResumo.vue';
import { MODO_DISTRIBUICAO } from '../../Models/Pagamento';
import { formatarDinheiro, unidadesMenoresParaDecimal } from '../../Support/dinheiro';

const props = defineProps({
    show: { type: Boolean, default: false },
    matriculas: { type: Array, required: true }, // [{id, numero, ano_lectivo_nome, turma_nome, estado_descricao}]
    saldoDisponivel: { type: Number, required: true },
    moeda: { type: Object, required: true },
});
const emit = defineEmits(['fechar']);

const matriculaId = ref(null);
const valor = ref('');
const plano = ref(null);
const erro = ref('');
const aPrever = ref(false);
const processing = ref(false);

watch(() => props.show, (show) => {
    if (!show) return;
    matriculaId.value = props.matriculas[0]?.id ?? null;
    valor.value = unidadesMenoresParaDecimal(props.saldoDisponivel, props.moeda);
    plano.value = null;
    erro.value = '';
});

async function preVisualizar() {
    aPrever.value = true;
    erro.value = '';
    plano.value = null;

    try {
        const { data } = await axios.post(`/financeiro/creditos/matriculas/${matriculaId.value}/pre-visualizar`, { valor_credito: valor.value, modo_distribuicao: MODO_DISTRIBUICAO.AUTOMATICA });
        plano.value = data.plano;
    } catch (e) {
        erro.value = e.response?.status === 422 ? Object.values(e.response.data.errors)[0][0] : 'Não foi possível calcular.';
    } finally {
        aPrever.value = false;
    }
}

function aplicar() {
    processing.value = true;
    const distribuicao = plano.value.linhas.map((l) => ({ propina_id: l.propina_id, valor: unidadesMenoresParaDecimal(l.credito, props.moeda) }));

    router.post(`/financeiro/creditos/matriculas/${matriculaId.value}/aplicar`, { valor_credito: valor.value, modo_distribuicao: MODO_DISTRIBUICAO.EXPLICITA, distribuicao }, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success('Crédito aplicado.');
            emit('fechar');
        },
        onError: (erros) => {
            erro.value = Object.values(erros)[0];
            plano.value = null;
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}
</script>

<template>
    <div v-if="show" class="modal d-block" style="background: rgba(0,0,0,0.5); overflow-y: auto;" @click.self="!processing && emit('fechar')">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content p-6">
                <h3 class="mb-2">Aplicar crédito</h3>
                <p class="text-muted fs-6 mb-5">Disponível: {{ formatarDinheiro(saldoDisponivel, moeda) }}. Usa os créditos mais antigos primeiro, nas propinas mais antigas da matrícula.</p>

                <div class="row g-4 mb-4">
                    <div class="col-12 col-md-7">
                        <label class="form-label required fw-semibold">Matrícula</label>
                        <SelectSolid v-model="matriculaId" :options="matriculas.map((m) => ({ value: m.id, label: `${m.numero} · ${m.ano_lectivo_nome ?? '—'} · ${m.turma_nome ?? '—'} (${m.estado_descricao})` }))" />
                    </div>
                    <div class="col-12 col-md-5">
                        <label class="form-label required fw-semibold">Valor ({{ moeda.simbolo }})</label>
                        <input v-model="valor" type="text" inputmode="decimal" class="form-control form-control-solid" />
                    </div>
                </div>

                <div v-if="erro" class="alert alert-danger fs-7 py-3">{{ erro }}</div>
                <DistribuicaoResumo v-if="plano" :plano="plano" :moeda="moeda" />

                <div class="d-flex justify-content-end gap-3 mt-6">
                    <button type="button" class="btn btn-light" :disabled="processing" @click="emit('fechar')">Fechar</button>
                    <button v-if="!plano" type="button" class="btn btn-primary" :disabled="aPrever || !matriculaId || !valor" @click="preVisualizar">{{ aPrever ? 'A calcular…' : 'Ver resumo' }}</button>
                    <button v-else type="button" class="btn btn-primary" :disabled="processing" @click="aplicar">{{ processing ? 'A aplicar…' : 'Aplicar crédito' }}</button>
                </div>
            </div>
        </div>
    </div>
</template>
```

`Modules/Financeiro/resources/js/Pages/Creditos/Aluno.vue`:
```vue
<script setup>
import { ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import AppLayout from '@/Layouts/AppLayout.vue';
import BotaoVoltar from '@/Components/Shared/BotaoVoltar.vue';
import AnularRegistoModal from '../../Components/Pagamentos/AnularRegistoModal.vue';
import AplicarCreditoModal from '../../Components/Pagamentos/AplicarCreditoModal.vue';
import { ESTADO_UTILIZACAO, estadoCreditoBadgeClass } from '../../Models/Pagamento';
import { formatarDinheiro } from '../../Support/dinheiro';
import { dataCurta } from '../../Support/periodos';

defineProps({
    aluno: { type: Object, required: true },
    saldo_disponivel: { type: Number, required: true },
    creditos: { type: Array, required: true },
    matriculas: { type: Array, required: true },
    moeda: { type: Object, required: true },
    podeAplicar: { type: Boolean, required: true },
    podeAnular: { type: Boolean, required: true },
});
defineOptions({ layout: AppLayout });

const page = usePage();
const aplicarAberto = ref(false);
const utilizacaoAnular = ref(null);
const processing = ref(false);
const errors = ref({});

function anularUtilizacao(motivo) {
    if (processing.value) return;
    processing.value = true;
    errors.value = {};

    router.patch(`/financeiro/creditos/utilizacoes/${utilizacaoAnular.value.id}/anular`, { motivo }, {
        preserveScroll: true,
        onSuccess: () => {
            utilizacaoAnular.value = null;
            toast.success(page.props.flash?.success ?? 'Utilização de crédito anulada.');
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
        <BotaoVoltar href="/financeiro/pagamentos" />

        <div class="d-flex flex-wrap justify-content-between align-items-start gap-4 mb-6">
            <div>
                <h1 class="fs-2 fw-bold mb-1">Créditos de {{ aluno.nome ?? 'aluno' }}</h1>
                <p class="text-muted fs-6 mb-0">nº {{ aluno.numero ?? '—' }} · disponível {{ formatarDinheiro(saldo_disponivel, moeda) }}</p>
            </div>
            <button v-if="podeAplicar && saldo_disponivel > 0" type="button" class="btn btn-primary" @click="aplicarAberto = true">Aplicar crédito</button>
        </div>

        <p v-if="creditos.length === 0" class="text-muted">Este aluno não tem créditos.</p>

        <div v-for="credito in creditos" :key="credito.id" class="card mb-6">
            <div class="card-header align-items-center">
                <h3 class="card-title fs-6 fw-bold">
                    {{ formatarDinheiro(credito.valor, moeda) }} · saldo {{ formatarDinheiro(credito.saldo, moeda) }}
                    <span class="badge ms-2" :class="estadoCreditoBadgeClass(credito.estado)">{{ credito.estado_descricao }}</span>
                </h3>
                <Link :href="`/financeiro/pagamentos/${credito.origem_pagamento_id}`" class="fs-7">Origem: pagamento nº {{ credito.origem_numero }} ({{ dataCurta(credito.origem_data) }})</Link>
            </div>
            <div class="card-body pt-0">
                <p v-if="credito.utilizacoes.length === 0" class="text-muted mb-0 mt-4">Sem utilizações.</p>
                <table v-else class="table table-row-dashed align-middle fs-7 gy-2 mb-0">
                    <thead><tr class="text-muted fw-bold text-uppercase"><th>Data</th><th>Propina</th><th>Matrícula</th><th>Estado</th><th class="text-end">Valor</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="u in credito.utilizacoes" :key="u.id">
                            <td>{{ dataCurta(u.data_utilizacao) }}</td>
                            <td><Link :href="`/financeiro/propinas/${u.propina_id}`">{{ u.periodo }}</Link></td>
                            <td>{{ u.matricula_numero ?? '—' }}</td>
                            <td>{{ u.estado_descricao }}<div v-if="u.motivo_anulacao" class="text-muted fs-8">{{ u.motivo_anulacao }}</div></td>
                            <td class="text-end">{{ formatarDinheiro(u.valor, moeda) }}</td>
                            <td class="text-end">
                                <button v-if="podeAnular && u.estado === ESTADO_UTILIZACAO.ACTIVA" type="button" class="btn btn-sm btn-light-danger" @click="errors = {}; utilizacaoAnular = u">Anular</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <AplicarCreditoModal :show="aplicarAberto" :matriculas="matriculas" :saldo-disponivel="saldo_disponivel" :moeda="moeda" @fechar="aplicarAberto = false" />
        <AnularRegistoModal
            :show="utilizacaoAnular !== null"
            titulo="Anular utilização de crédito"
            :subtitulo="utilizacaoAnular ? `${utilizacaoAnular.periodo} — ${formatarDinheiro(utilizacaoAnular.valor, moeda)}` : ''"
            aviso="O crédito recupera este valor e a propina volta a ficar em dívida nessa parte."
            rotulo-confirmar="Anular utilização"
            campo-erro="utilizacao"
            :processing="processing"
            :errors="errors"
            @confirmar="anularUtilizacao"
            @cancelar="utilizacaoAnular = null"
        />
    </div>
</template>
```

`Pages/Propinas/Show.vue`:
- `defineProps`: acrescentar `podeAnular: { type: Boolean, required: true },`; importar `AnularRegistoModal` (`'../../Components/Pagamentos/AnularRegistoModal.vue'`) e `Link` de `@inertiajs/vue3`.
- `script`: acrescentar
```js
const anularAberto = ref(false);

function anular(motivo) {
    if (processing.value) return;
    processing.value = true;
    errors.value = {};

    router.patch(`/financeiro/propinas/${props.propina.id}/anular`, { motivo }, {
        preserveScroll: true,
        onSuccess: () => {
            anularAberto.value = false;
            toast.success(page.props.flash?.success ?? 'Propina anulada.');
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
```
- no bloco `<div class="text-end">` do cabeçalho, a seguir ao botão/aviso de cancelar:
```vue
                <button v-if="podeAnular" type="button" class="btn btn-light-danger ms-2" @click="errors = {}; anularAberto = true">
                    Anular propina
                </button>
                <div v-else-if="can('propina.anular') && !podeCancelar && propina.anulacao_bloqueada" class="text-muted fs-7 mt-2" style="max-width: 360px">
                    {{ propina.anulacao_bloqueada }}
                </div>
```
- cartão "Pagamentos": substituir o `<p v-if="propina.pagamentos.length === 0" …>Sem pagamentos. Os pagamentos aparecem aqui quando o módulo de Pagamentos estiver disponível.</p>` por:
```vue
                        <p v-if="propina.pagamentos.length === 0" class="text-muted mb-0">Sem pagamentos.</p>
                        <table v-else class="table table-row-dashed align-middle fs-7 gy-2 mb-0">
                            <thead><tr class="text-muted fw-bold text-uppercase"><th>Data</th><th>Origem</th><th>Estado</th><th class="text-end">Valor</th></tr></thead>
                            <tbody>
                                <tr v-for="registo in propina.pagamentos" :key="`${registo.tipo}-${registo.id}`" :class="{ 'text-muted text-decoration-line-through': !registo.activo }">
                                    <td>{{ dataCurta(registo.data) }}</td>
                                    <td>
                                        <Link v-if="registo.pagamento_id" :href="`/financeiro/pagamentos/${registo.pagamento_id}`">
                                            {{ registo.tipo === 'pagamento' ? 'Pagamento' : 'Crédito do pagamento' }} nº {{ registo.numero }}
                                        </Link>
                                    </td>
                                    <td>{{ registo.estado_descricao }}</td>
                                    <td class="text-end">{{ formatarDinheiro(registo.valor, moeda) }}</td>
                                </tr>
                            </tbody>
                        </table>
```
- antes de `</div>` final do template, a seguir ao `CancelarPropinaModal`:
```vue
        <AnularRegistoModal
            :show="anularAberto"
            titulo="Anular propina"
            :subtitulo="`${propina.periodo} — ${propina.matricula?.aluno_nome ?? 'Aluno'}`"
            aviso="A propina recebeu pagamentos que foram todos anulados. Passa a Anulada e deixa de ser cobrada. A operação não se desfaz."
            rotulo-confirmar="Anular propina"
            campo-erro="propina"
            :processing="processing"
            :errors="errors"
            @confirmar="anular"
            @cancelar="anularAberto = false"
        />
```
`CancelarPropinaModal.vue`: trocar "não se cancela (anula-se, com o módulo de Pagamentos)." por "não se cancela: anula-se.".

- [ ] **Step 4: Correr e ver passar; compilar**

Run: `php artisan test Modules/Financeiro/tests/Unit && npm run build`
Expected: PASS (incluindo `DetalhePropinaVueTest`, que continua a encontrar o botão "Cancelar propina" com `v-if="podeCancelar"`) e build sem erros.

- [ ] **Step 5: Verificação na app (sem editar a BD)**

Com `npm run dev` e login `admin@mositec.gmail.com` (só depois de o dono correr as migrações e `financeiro:sincronizar --todos`): Financeiro → Pagamentos aparece no header e na sidebar; registar um pagamento parcial e um com excedente; ver o detalhe; abrir "Créditos do aluno", aplicar crédito, anular a utilização; anular o pagamento; abrir a propina e anulá-la. Confirmar o tema claro e escuro dos cartões `bg-body-secondary`. Se o dono ainda não correu as migrações, registar no relatório que esta verificação fica pendente.

- [ ] **Step 6: Stage**

```bash
git add Modules/Financeiro/resources/js/Components/Pagamentos/AplicarCreditoModal.vue Modules/Financeiro/resources/js/Pages/Creditos/Aluno.vue Modules/Financeiro/resources/js/Pages/Propinas/Show.vue Modules/Financeiro/resources/js/Components/Propinas/CancelarPropinaModal.vue Modules/Financeiro/tests/Unit/PagamentosVueTest.php
```

---

### Task 18: PostgreSQL — CHECK, triggers de imutabilidade e índice da referência (D5, D17; fases §8.3)

**Files:**
- Create: `Modules/Financeiro/tests/Feature/Pgsql/PagamentoPgsqlTest.php`

**Interfaces:**
- Consumes: migrations de T2/T3, `ComPagamentosFinanceiro` (`pagar`, `anularPagamento`, `aplicarCredito`, `regras`), `PgsqlTestCase`, grupo `pgsql`.
- Produces: prova, só em PostgreSQL, de que a base recusa por si o que os models recusam em SQLite. Ignorado (skipped) sem `mositec_escola_test`.

- [ ] **Step 1: Escrever os testes**

`Modules/Financeiro/tests/Feature/Pgsql/PagamentoPgsqlTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature\Pgsql;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Financeiro\Actions\AnularPropinaAction;
use Modules\Financeiro\DTO\MotivoDeAnulacaoDTO;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Matricula\Models\Matricula;
use PHPUnit\Framework\Attributes\Group;
use Tests\PgsqlTestCase;

/**
 * O que só o PostgreSQL prova em Pagamentos-A: CHECK e triggers de imutabilidade de pagamentos, distribuições,
 * créditos e utilizações, e o índice único parcial da referência. SQL cru só aqui (testes), nunca no código dos módulos.
 */
#[Group('pgsql')]
class PagamentoPgsqlTest extends PgsqlTestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPagamentosFinanceiro;
    use ComPropinasFinanceiro;

    private Matricula $matricula;

    private Propina $set;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
        ['plano' => $plano, 'matricula' => $this->matricula] = $this->cenarioPropinas();
        $this->set = $this->propina($this->matricula, $plano, 1);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Num savepoint: o erro aborta só o savepoint e a transacção do teste continua utilizável. */
    private function assertRecusadoPelaBase(string $sqlstate, string $texto, Closure $operacao): void
    {
        try {
            DB::transaction($operacao);
        } catch (QueryException $e) {
            $this->assertSame($sqlstate, $e->errorInfo[0] ?? null, $e->getMessage());
            $this->assertStringContainsString($texto, $e->getMessage());

            return;
        }

        $this->fail("A base devia ter recusado ({$texto}).");
    }

    public function test_pagamento_so_aceita_a_anulacao_e_nunca_se_apaga(): void
    {
        $this->regras();
        $pagamento = $this->pagar($this->matricula, 3_000_000, [[$this->set, 2_500_000]]);
        $linha = fn () => DB::table('pagamentos')->where('id', $pagamento->id);

        $this->assertRecusadoPelaBase('23000', 'nunca se edita', fn () => $linha()->update(['valor' => 1]));
        $this->assertRecusadoPelaBase('23000', 'nunca se edita', fn () => $linha()->update(['data_pagamento' => '2026-10-01']));
        $this->assertRecusadoPelaBase('23000', 'nunca se elimina', fn () => $linha()->delete());
        $this->assertRecusadoPelaBase('23514', 'pagamentos_anulacao_check', fn () => $linha()->update(['estado' => 2, 'estado_descricao' => 'Anulado']));
        $this->assertRecusadoPelaBase('23514', 'pagamentos_valor_check', fn () => DB::table('pagamentos')->insert(array_merge(
            array_diff_key((array) $linha()->first(), ['id' => true]), ['numero' => '2026-9999', 'valor' => 0, 'referencia' => null],
        )));

        DB::transaction(fn () => $linha()->update(['estado' => 2, 'estado_descricao' => 'Anulado', 'motivo_anulacao' => 'Teste', 'anulado_em' => now()]));
        $this->assertRecusadoPelaBase('23000', 'nunca se edita', fn () => $linha()->update(['estado' => 1, 'estado_descricao' => 'Confirmado', 'motivo_anulacao' => null, 'anulado_em' => null]));
    }

    public function test_pagamento_recusa_cambio_e_moeda_invalidos(): void
    {
        $this->regras();
        $pagamento = $this->pagar($this->matricula, 3_000_000, [[$this->set, 2_500_000]]);
        $copia = fn (array $alterar) => fn () => DB::table('pagamentos')->insert(array_merge(
            array_diff_key((array) DB::table('pagamentos')->where('id', $pagamento->id)->first(), ['id' => true]),
            ['numero' => '2026-9998', 'referencia' => null],
            $alterar,
        ));

        $this->assertRecusadoPelaBase('23514', 'pagamentos_cambio_check', $copia(['cambio_usd' => 0]));
        $this->assertRecusadoPelaBase('23514', 'pagamentos_moeda_check', $copia(['moeda' => 'AO']));
    }

    public function test_distribuicoes_sao_imutaveis_e_positivas(): void
    {
        $this->regras();
        $pagamento = $this->pagar($this->matricula, 2_500_000, [[$this->set, 2_500_000]]);
        $distribuicao = fn () => DB::table('pagamento_propinas')->where('pagamento_id', $pagamento->id);

        $this->assertRecusadoPelaBase('23000', 'imutável', fn () => $distribuicao()->update(['valor' => 1]));
        $this->assertRecusadoPelaBase('23000', 'imutável', fn () => $distribuicao()->delete());
    }

    public function test_credito_coerente_e_com_origem_imutavel(): void
    {
        $this->regras();
        $pagamento = $this->pagar($this->matricula, 3_000_000, [[$this->set, 2_500_000]]); // 500.000 de crédito
        $credito = fn () => DB::table('creditos')->where('origem_pagamento_id', $pagamento->id);

        $this->assertRecusadoPelaBase('23000', 'nunca mudam', fn () => $credito()->update(['valor' => 1]));
        // Viola dois CHECK; o PostgreSQL verifica-os por ordem alfabética do nome, por isso só se afirma o prefixo.
        $this->assertRecusadoPelaBase('23514', 'creditos_', fn () => $credito()->update(['valor_utilizado' => 600_000]));
        $this->assertRecusadoPelaBase('23514', 'creditos_estado_coerente_check', fn () => $credito()->update(['estado' => 2, 'estado_descricao' => 'Esgotado']));
        $this->assertRecusadoPelaBase('23000', 'nunca se elimina', fn () => $credito()->delete());

        DB::transaction(fn () => $credito()->update(['estado' => 3, 'estado_descricao' => 'Anulado']));
        $this->assertRecusadoPelaBase('23000', 'terminal', fn () => $credito()->update(['estado' => 1, 'estado_descricao' => 'Disponível']));
    }

    public function test_utilizacao_so_passa_a_anulada(): void
    {
        $this->regras(parcial: true);
        $out = $this->propina($this->matricula, $this->set->plano, 2);
        $this->pagar($this->matricula, 3_000_000, [[$this->set, 2_500_000]]);
        $utilizacao = $this->aplicarCredito($out, 500_000);
        $linha = fn () => DB::table('credito_utilizacoes')->where('id', $utilizacao->id);

        $this->assertRecusadoPelaBase('23000', 'Activa a Anulada', fn () => $linha()->update(['valor' => 1]));
        $this->assertRecusadoPelaBase('23514', 'credito_utilizacoes_anulacao_check', fn () => $linha()->update(['estado' => 2, 'estado_descricao' => 'Anulada']));
        $this->assertRecusadoPelaBase('23000', 'nunca se elimina', fn () => $linha()->delete());
    }

    public function test_utilizacao_combinada_nao_muda_de_pagamento(): void
    {
        $this->regras(parcial: true);
        $out = $this->propina($this->matricula, $this->set->plano, 2);
        $this->pagar($this->matricula, 3_000_000, [[$this->set, 2_500_000]]); // 500.000 de crédito
        $combinado = $this->pagar($this->matricula, 2_000_000, [[$out, 2_500_000]], credito: 500_000);
        $linha = fn () => DB::table('credito_utilizacoes')->where('pagamento_id', $combinado->id);

        $this->assertSame(1, $linha()->count(), 'O8: a utilização do mesmo acto guarda o pagamento.');
        $this->assertRecusadoPelaBase('23000', 'Activa a Anulada', fn () => $linha()->update(['pagamento_id' => null]));

        $this->anularPagamento($combinado);
        $this->assertSame(1, $linha()->where('estado', 1)->count(), 'D14/O8: continua Activa depois de anular o pagamento.');
    }

    public function test_referencia_unica_so_entre_confirmados(): void
    {
        $this->regras(parcial: true);
        $primeiro = $this->pagar($this->matricula, 100_000, [[$this->set, 100_000]], referencia: 'TRF-1');
        $copia = fn () => DB::table('pagamentos')->insert(array_merge(
            array_diff_key((array) DB::table('pagamentos')->where('id', $primeiro->id)->first(), ['id' => true]),
            ['numero' => '2026-0999', 'estado' => 1, 'estado_descricao' => 'Confirmado', 'motivo_anulacao' => null, 'anulado_por' => null, 'anulado_em' => null],
        ));

        $this->assertRecusadoPelaBase('23505', 'pagamentos_referencia_confirmada_unique', $copia);

        $this->anularPagamento($primeiro);
        DB::transaction($copia); // o anulado libertou a referência
        $this->assertSame(2, DB::table('pagamentos')->where('referencia', 'TRF-1')->count());
    }

    public function test_ciclo_completo_sob_os_check_das_propinas(): void
    {
        $this->regras(parcial: true);
        $a = $this->pagar($this->matricula, 1_000_000, [[$this->set, 1_000_000]], data: '2026-10-05');
        $b = $this->pagar($this->matricula, 1_500_000, [[$this->set, 1_500_000]]);
        $this->assertSame(EstadoCobranca::PAGA, $this->set->fresh()->estado);

        $this->anularPagamento($b);
        $this->assertSame(EstadoCobranca::PARCIALMENTE_PAGA, $this->set->fresh()->estado);
        $this->anularPagamento($a);
        $this->assertSame(EstadoCobranca::EM_ABERTO, $this->set->fresh()->estado);

        $this->assertSame(EstadoCobranca::ANULADA, app(AnularPropinaAction::class)->executar(
            $this->set->fresh(), new MotivoDeAnulacaoDTO('Ciclo de teste'), null,
        )->estado);
        $this->assertPagamentosCoerentes();
    }
}
```

(`DB::table(...)` só em testes, fora de `Modules/*/app`: não viola `TenancyArquitecturaTest`. `insert` SEM a coluna `id` (`array_diff_key`) deixa a sequência escolher o id: em PostgreSQL um `id => null` explícito viola o NOT NULL — 23502 — em vez de usar o DEFAULT.)

- [ ] **Step 2: Correr (dono, ou executor se a base de teste existir)**

Run: `DB_CONNECTION=pgsql DB_DATABASE=mositec_escola_test vendor/bin/phpunit --group=pgsql --filter=PagamentoPgsqlTest`
Expected: PASS. Sem a base: *skipped*. `php artisan test Modules/Financeiro/tests/Feature/Pgsql` → skipped em SQLite.

- [ ] **Step 3: Stage**

```bash
git add Modules/Financeiro/tests/Feature/Pgsql/PagamentoPgsqlTest.php
```

---

### Task 19: PostgreSQL — concorrência com sessões reais (D13; spec §9 "Concorrência"; Review Focus 1, 2, 6)

**Files:**
- Modify: `Modules/Financeiro/tests/Feature/Pgsql/ComProcessosConcorrentes.php` (ops novas no script do filho)
- Create: `Modules/Financeiro/tests/Feature/Pgsql/PagamentoConcorrenciaPgsqlTest.php`

**Interfaces:**
- Consumes: `PgsqlConcorrenciaTestCase` (`outraLigacao()`, dados confirmados), `ComProcessosConcorrentes` (`lancar`, `esperar`, `esperarQueOFilhoFiqueABloquear`, `aCorrer`, `deadlocksDaBase`), Actions de T7–T10, `AlterarPrecoPlanoAction` (op `alterar` já existente).
- Produces: ops de filho `pagar`, `anular-pagamento`, `aplicar-credito`, `cancelar-propina`; prova de D13 (sem ciclos com F2–F4), de "dois pagamentos simultâneos não excedem o saldo", da numeração sem lacunas e da conversão da violação de referência em 422.

- [ ] **Step 1: Acrescentar as ops ao filho**

Em `scriptDoFilho()` (heredoc `<<<'PHP'`), antes de `} else {` / `throw new RuntimeException('operação desconhecida');`, acrescentar (o script gerado usa nomes completos por não ter `use` — é a convenção já existente neste ficheiro):
```php
    } elseif ($args['op'] === 'pagar') {
        $dto = new Modules\Financeiro\DTO\RegistarPagamentoDTO(
            metodoPagamentoId: $a['metodo'],
            valor: Modules\Financeiro\Support\Dinheiro::deUnidadesMenores($a['valor']),
            dataPagamento: Carbon\CarbonImmutable::createFromFormat('!Y-m-d', $a['data'] ?? '2026-10-16'),
            referencia: $a['referencia'] ?? null,
            distribuicao: ($a['linhas'] ?? null) === null ? null : array_map(
                fn ($l) => new Modules\Financeiro\DTO\LinhaDeDistribuicao($l[0], Modules\Financeiro\Support\Dinheiro::deUnidadesMenores($l[1])),
                $a['linhas'],
            ),
            valorCredito: Modules\Financeiro\Support\Dinheiro::deUnidadesMenores($a['credito'] ?? 0),
        );
        $r = app(Modules\Financeiro\Actions\RegistarPagamentoAction::class)->executar(Modules\Matricula\Models\Matricula::findOrFail($a['matricula']), $dto);
        $saida(['ok' => true, 'pagamento' => $r->id, 'numero' => $r->numero]);
    } elseif ($args['op'] === 'anular-pagamento') {
        app(Modules\Financeiro\Actions\AnularPagamentoAction::class)->executar(
            Modules\Financeiro\Models\Pagamento::findOrFail($a['pagamento']), new Modules\Financeiro\DTO\MotivoDeAnulacaoDTO('Anulação concorrente'), null,
        );
        $saida(['ok' => true]);
    } elseif ($args['op'] === 'aplicar-credito') {
        $r = app(Modules\Financeiro\Actions\AplicarCreditoAction::class)->executar(
            Modules\Matricula\Models\Matricula::findOrFail($a['matricula']),
            new Modules\Financeiro\DTO\AplicarCreditoDTO(Modules\Financeiro\Support\Dinheiro::deUnidadesMenores($a['credito'])),
        );
        $saida(['ok' => true, 'utilizacoes' => count($r)]);
    } elseif ($args['op'] === 'cancelar-propina') {
        app(Modules\Financeiro\Actions\CancelarPropinaAction::class)->executar(
            Modules\Financeiro\Models\Propina::findOrFail($a['propina']), new Modules\Financeiro\DTO\CancelarPropinaDTO('Cancelamento concorrente'), null,
        );
        $saida(['ok' => true]);
```
e actualizar o docblock do trait: "Operações: alterar, gerar, cancelar (matrícula), pagar, anular-pagamento, aplicar-credito, cancelar-propina."

- [ ] **Step 2: Escrever os testes**

`Modules/Financeiro/tests/Feature/Pgsql/PagamentoConcorrenciaPgsqlTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature\Pgsql;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Modules\Financeiro\Actions\AlterarPrecoPlanoAction;
use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Financeiro\Models\AlteracaoPrecoPlano;
use Modules\Financeiro\Models\Pagamento;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Tests\Concerns\ComAlteracaoPreco;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPagamentosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Matricula\Models\Matricula;
use PHPUnit\Framework\Attributes\Group;
use Tests\PgsqlConcorrenciaTestCase;

/**
 * Pagamentos-A com sessões PostgreSQL reais (processos filhos): duplo pagamento, parciais simultâneos, pagamento ×
 * alteração de preço × cancelamento, numeração e referência. SQLite ignora FOR UPDATE.
 */
#[Group('pgsql')]
class PagamentoConcorrenciaPgsqlTest extends PgsqlConcorrenciaTestCase
{
    use ComAlteracaoPreco;
    use ComDadosAcademicosFinanceiro;
    use ComPagamentosFinanceiro;
    use ComProcessosConcorrentes;
    use ComPropinasFinanceiro;

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

    /** @return array{0: Matricula, 1: PlanoPropina, 2: array<int, Propina>} matrícula com Set…Jun, todas Em Aberto */
    private function cenario(): array
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propinas = [];

        foreach (range(1, 10) as $ordem) {
            $propinas[$ordem] = $this->propina($matricula, $plano, $ordem);
        }

        return [$matricula, $plano, $propinas];
    }

    private function dadosPagar(Matricula $matricula, int $valor, ?array $linhas, ?string $referencia = null): array
    {
        return ['metodo' => $this->metodoPagamento()->id, 'matricula' => $matricula->id, 'valor' => $valor, 'linhas' => $linhas, 'referencia' => $referencia];
    }

    /** @return list<array<string, mixed>> respostas dos filhos lançados ao mesmo tempo */
    private function emSimultaneo(array $operacoes): array
    {
        $partida = microtime(true) + 2.5;
        $filhos = array_map(fn (array $op) => $this->lancar($op[0], $op[1], $partida), $operacoes);

        return array_map(fn (array $filho) => $this->esperar($filho), $filhos);
    }

    private function sqlstateNaOutraLigacao(string $sql, array $valores): ?string
    {
        try {
            $this->outraLigacao()->select($sql, $valores);

            return null;
        } catch (QueryException $e) {
            return $e->errorInfo[0] ?? (string) $e->getCode();
        }
    }

    private function assertCoerenteNaOutraLigacao(): void
    {
        $divergentes = $this->outraLigacao()->select(
            "select p.id from propinas p where p.valor_pago <> coalesce((select sum(pp.valor) from pagamento_propinas pp join pagamentos g on g.id = pp.pagamento_id where pp.propina_id = p.id and g.estado = 1), 0)
             + coalesce((select sum(u.valor) from credito_utilizacoes u where u.propina_id = p.id and u.estado = 1), 0)",
        );
        $this->assertSame([], $divergentes, 'valor_pago = Σ pagamentos confirmados + créditos activos em todas as propinas.');
        $this->assertSame([], $this->outraLigacao()->select(
            'select g.id from pagamentos g where g.estado = 1 and g.valor <> coalesce((select sum(pp.valor) from pagamento_propinas pp where pp.pagamento_id = g.id), 0) + coalesce((select c.valor from creditos c where c.origem_pagamento_id = g.id), 0)',
        ), 'valor = Σ distribuições + crédito gerado.');
    }

    public function test_dois_pagamentos_simultaneos_da_mesma_propina_so_um_passa(): void
    {
        [$matricula, , $p] = $this->cenario();
        $this->regras();
        $antes = $this->deadlocksDaBase();

        $respostas = $this->emSimultaneo([
            ['pagar', $this->dadosPagar($matricula, 2_500_000, [[$p[1]->id, 2_500_000]])],
            ['pagar', $this->dadosPagar($matricula, 2_500_000, [[$p[1]->id, 2_500_000]])],
        ]);

        $this->assertSame(1, count(array_filter($respostas, fn ($r) => $r['ok'])), json_encode($respostas));
        $recusada = array_values(array_filter($respostas, fn ($r) => ! $r['ok']))[0];
        $this->assertSame(['validacao', ['distribuicao.0.propina_id']], [$recusada['tipo'], $recusada['campos']]);
        $this->assertSame(2_500_000, (int) $this->outraLigacao()->selectOne('select valor_pago from propinas where id = ?', [$p[1]->id])->valor_pago);
        $this->assertSame(1, (int) $this->outraLigacao()->selectOne('select count(*) as n from pagamentos')->n);
        $this->assertSame($antes, $this->deadlocksDaBase());
        $this->assertCoerenteNaOutraLigacao();
    }

    public function test_dois_parciais_simultaneos_nunca_excedem_o_saldo(): void
    {
        [$matricula, , $p] = $this->cenario();
        $this->regras(parcial: true);

        $respostas = $this->emSimultaneo([
            ['pagar', $this->dadosPagar($matricula, 2_000_000, [[$p[1]->id, 2_000_000]])],
            ['pagar', $this->dadosPagar($matricula, 2_000_000, [[$p[1]->id, 2_000_000]])],
        ]);

        $this->assertSame(1, count(array_filter($respostas, fn ($r) => $r['ok'])), json_encode($respostas));
        $this->assertSame(2_000_000, (int) $this->outraLigacao()->selectOne('select valor_pago from propinas where id = ?', [$p[1]->id])->valor_pago);
        $this->assertCoerenteNaOutraLigacao();
    }

    public function test_o_registo_segura_matricula_propinas_e_sequencia_ate_ao_commit(): void
    {
        [$matricula, , $p] = $this->cenario();
        $this->regras();
        // A linha da sequência só é visível (e bloqueável) por outra sessão depois do 1.º commit: um pagamento antes.
        $this->pagar($matricula, 2_500_000, [[$p[2], 2_500_000]]);
        $verificado = [];
        Pagamento::created(function () use ($matricula, $p, &$verificado) {
            $verificado = [
                'matricula' => $this->sqlstateNaOutraLigacao('select id from matriculas where id = ? for update', [$matricula->id]),
                'propina' => $this->sqlstateNaOutraLigacao('select id from propinas where id = ? for update', [$p[1]->id]),
                'sequencia' => $this->sqlstateNaOutraLigacao('select id from pagamento_sequencias where tenant_id = ? for update', [$this->tenant->id]),
                'plano' => $this->sqlstateNaOutraLigacao('select id from planos_propina where id = ? for update', [$p[1]->plano_propina_id]),
            ];
        });

        $this->pagar($matricula, 2_500_000, [[$p[1], 2_500_000]]);

        $this->assertSame(['matricula' => '55P03', 'propina' => '55P03', 'sequencia' => '55P03', 'plano' => null], $verificado, 'D13: matrícula, propinas e sequência presas; o plano nunca.');
        $this->assertNull($this->sqlstateNaOutraLigacao('select id from matriculas where id = ? for update', [$matricula->id]), 'Libertado no commit.');
    }

    public function test_pagamento_durante_uma_alteracao_de_preco_espera_e_depois_paga_ao_preco_novo(): void
    {
        [$matricula, $plano, $p] = $this->cenario();
        $this->regras(parcial: true, antecipado: true);
        $impressao = $this->previa($plano, '2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS)->impressaoDigital;
        $estado = ['filho' => null, 'ficouPreso' => null];
        $antes = $this->deadlocksDaBase();
        AlteracaoPrecoPlano::creating(function () use ($matricula, $p, &$estado) {
            $estado['filho'] = $this->lancar('pagar', $this->dadosPagar($matricula, 1_000_000, [[$p[6]->id, 1_000_000]]));
            $estado['ficouPreso'] = $this->esperarQueOFilhoFiqueABloquear($estado['filho']);
        });

        app(AlterarPrecoPlanoAction::class)->executar($plano->fresh(), $this->pedidoAlteracao('2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS, 'Actualização anual', $impressao));
        $resposta = $this->esperar($estado['filho']);

        $this->assertTrue($estado['ficouPreso'], 'O pagamento (FOR UPDATE da matrícula) espera pela alteração.');
        $this->assertTrue($resposta['ok'], json_encode($resposta));
        $linha = $this->outraLigacao()->selectOne('select valor, valor_pago, estado from propinas where id = ?', [$p[6]->id]);
        $this->assertSame([3_000_000, 1_000_000, 2], [(int) $linha->valor, (int) $linha->valor_pago, (int) $linha->estado]);
        $this->assertSame($antes, $this->deadlocksDaBase());
        $this->assertCoerenteNaOutraLigacao();
    }

    public function test_alteracao_de_preco_durante_um_pagamento_espera_e_recusa_por_impressao(): void
    {
        [$matricula, $plano, $p] = $this->cenario();
        $this->regras(parcial: true, antecipado: true);
        $impressao = $this->previa($plano, '2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS)->impressaoDigital;
        $estado = ['filho' => null, 'ficouPreso' => null];
        Pagamento::created(function () use ($plano, $impressao, &$estado) {
            $estado['filho'] = $this->lancar('alterar', [
                'plano' => $plano->id, 'mes' => '2027-02', 'valor' => 3_000_000, 'modo' => ModoAlteracaoPreco::APLICAR_GERADAS->value,
                'motivo' => 'Actualização anual', 'impressao' => $impressao,
            ]);
            $estado['ficouPreso'] = $this->esperarQueOFilhoFiqueABloquear($estado['filho']);
        });

        $this->pagar($matricula, 1_000_000, [[$p[6], 1_000_000]]);
        $resposta = $this->esperar($estado['filho']);

        $this->assertTrue($estado['ficouPreso'], 'A alteração (FOR UPDATE das matrículas) espera pelo pagamento.');
        $this->assertSame(['validacao', ['impressao_digital']], [$resposta['tipo'] ?? null, $resposta['campos'] ?? null], json_encode($resposta));
        $this->assertSame(0, (int) $this->outraLigacao()->selectOne('select count(*) as n from alteracoes_preco_plano')->n);
        $this->assertCoerenteNaOutraLigacao();
    }

    public function test_pagamento_e_cancelamento_manual_simultaneos_so_um_vence(): void
    {
        [$matricula, , $p] = $this->cenario();
        $this->regras();

        $respostas = $this->emSimultaneo([
            ['pagar', $this->dadosPagar($matricula, 2_500_000, [[$p[2]->id, 2_500_000]])],
            ['cancelar-propina', ['propina' => $p[2]->id]],
        ]);

        $this->assertSame(1, count(array_filter($respostas, fn ($r) => $r['ok'])), json_encode($respostas));
        $linha = $this->outraLigacao()->selectOne('select estado from propinas where id = ?', [$p[2]->id]);
        $pagamentos = (int) $this->outraLigacao()->selectOne('select count(*) as n from pagamentos')->n;
        $this->assertContains([(int) $linha->estado, $pagamentos], [[4, 0], [3, 1]], 'Ou Cancelada sem pagamento, ou Paga com um pagamento.');
        $this->assertCoerenteNaOutraLigacao();
    }

    public function test_numeracao_sem_lacunas_com_pagamentos_simultaneos(): void
    {
        $this->regras();
        $matriculas = [];
        ['turma' => $turma] = $this->cenarioPropinas();

        foreach (range(1, 4) as $i) {
            $matriculas[] = $this->matriculaActiva($turma);
        }

        $respostas = $this->emSimultaneo(array_map(fn (Matricula $m) => ['pagar', $this->dadosPagar($m, 100_000, null)], $matriculas));

        $this->assertSame([true, true, true, true], array_column($respostas, 'ok'), json_encode($respostas));
        $numeros = array_column($respostas, 'numero');
        sort($numeros);
        $this->assertSame(['2026-0001', '2026-0002', '2026-0003', '2026-0004'], $numeros);
    }

    public function test_mesma_referencia_em_simultaneo_uma_passa_e_a_outra_recebe_422(): void
    {
        $this->regras();
        ['turma' => $turma] = $this->cenarioPropinas();
        [$a, $b] = [$this->matriculaActiva($turma), $this->matriculaActiva($turma)];

        $respostas = $this->emSimultaneo([
            ['pagar', $this->dadosPagar($a, 100_000, null, 'TRF-777')],
            ['pagar', $this->dadosPagar($b, 100_000, null, 'TRF-777')],
        ]);

        $this->assertSame(1, count(array_filter($respostas, fn ($r) => $r['ok'])), json_encode($respostas));
        $recusada = array_values(array_filter($respostas, fn ($r) => ! $r['ok']))[0];
        $this->assertSame(['validacao', ['referencia']], [$recusada['tipo'], $recusada['campos']], 'Violação do índice convertida em 422 FORA da transacção.');
    }

    public function test_anular_pagamento_e_usar_o_seu_credito_em_simultaneo_nunca_os_dois(): void
    {
        [$matricula, , $p] = $this->cenario();
        $this->regras(parcial: true);
        $origem = $this->pagar($matricula, 3_000_000, [[$p[1], 2_500_000]]); // 500.000 de crédito

        $respostas = $this->emSimultaneo([
            ['anular-pagamento', ['pagamento' => $origem->id]],
            ['aplicar-credito', ['matricula' => $matricula->id, 'credito' => 500_000]],
        ]);

        $this->assertSame(1, count(array_filter($respostas, fn ($r) => $r['ok'])), json_encode($respostas));
        $this->assertCoerenteNaOutraLigacao();
    }
}
```
(Notas: (1) `Pagamento::created` corre dentro da transacção do registo, depois de todos os bloqueios — incluindo a sequência; (2) em `test_numeracao…` as matrículas não têm propinas: tudo vai a crédito e só a sequência é partilhada; (3) o `lock_timeout` de `outraLigacao()` é 300 ms, por isso `55P03` significa "estava preso".)

- [ ] **Step 3: Correr (dono, ou executor se a base de teste existir)**

Run: `DB_CONNECTION=pgsql DB_DATABASE=mositec_escola_test vendor/bin/phpunit --group=pgsql`
Expected: PASS (grupo anterior + Tasks 18–19). Sem a base: *skipped*. Correr 3 vezes seguidas (as corridas são não-deterministas): 0 falhas, `deadlocks` inalterado.

- [ ] **Step 4: Stage**

```bash
git add Modules/Financeiro/tests/Feature/Pgsql/ComProcessosConcorrentes.php Modules/Financeiro/tests/Feature/Pgsql/PagamentoConcorrenciaPgsqlTest.php
```

---

### Task 20: Verificação final

- [ ] **Step 1: Suite completa**

Run: `php artisan test` (timeout alargado; fora da janela 23:00–24:00 UTC)
Expected: 0 falhas (grupo `pgsql` *skipped*). Número de testes = baseline anotada antes da Task 1 + os deste plano. Falhas fora do módulo são regressões deste plano (em especial `TenancyArquitecturaTest`, `TenancyEsquemaTest`, `MatrizIsolamentoTest`, `EscritoresDePropinaTest`, `EscritoresDePagamentoTest`, `CriadoresDePropinaTest`, `CanceladoresDePropinaTest`, `AnuladoresDePropinaTest`, `RelogioDoTenantArquitecturaTest`, `FronteiraMatriculaFinanceiroTest`, `RotasPermissaoReconhecidaTest`, `RotasEscritaAutorizadasTest`, `MenuFinanceiroTest`, `GeradorSequenciaTest`, `SequenciasIsolamentoTest`): corrigir seguindo a convenção, sem enfraquecer testes nem alargar listas de excepções.

- [ ] **Step 2: Limpeza e fronteiras**

Run:
```bash
grep -rnE "insertOrIgnore|->insert\(|upsert\(|DB::raw|DB::statement|DB::table|DB::select|withoutEvents|Quietly\(" Modules/Financeiro/app
grep -rnE "\bnow\(\)|\btoday\(\)|Carbon(Immutable)?::(now|today)|new \\\\?DateTime(Immutable)?\(\)" Modules/Financeiro/app
grep -rn "Modules\\\\Financeiro" Modules/Matricula/app Modules/Matricula/routes Modules/Aluno/app
grep -rnE "\\\\Modules\\\\|\\\\Illuminate\\\\|\\\\Carbon\\\\" Modules/Financeiro/app/Actions/RegistarPagamentoAction.php Modules/Financeiro/app/Actions/AplicarCreditoAction.php Modules/Financeiro/app/Actions/AnularPagamentoAction.php Modules/Financeiro/app/Actions/AnularUtilizacaoCreditoAction.php Modules/Financeiro/app/Actions/AnularPropinaAction.php Modules/Financeiro/app/Services/DistribuicaoDePagamentoService.php Modules/Financeiro/app/Services/PagamentoConsultaService.php Modules/Financeiro/app/Support/PagamentosDaPropina.php Modules/Financeiro/app/DTO/*.php Modules/Financeiro/tests/Feature/*Pagamento*.php Modules/Financeiro/tests/Feature/AnularPropinaTest.php Modules/Financeiro/tests/Feature/Pgsql/PagamentoPgsqlTest.php
grep -n "try {" Modules/Financeiro/app/Actions/RegistarPagamentoAction.php Modules/Financeiro/app/Actions/AnularPagamentoAction.php Modules/Financeiro/app/Actions/AplicarCreditoAction.php
git diff --cached --name-only | grep -E "database/migrations"
```
Expected: os quatro primeiros vazios (os docblocks com `@param class-string<…>` não contam; o script do filho em `ComProcessosConcorrentes` não está na lista); um só `try {` (em `RegistarPagamentoAction`, **à volta** de `DB::transaction`); as migrações novas em stage são exactamente as cinco `2026_10_17_1000xx_…`.

- [ ] **Step 3: Build**

Run: `npm run build`
Expected: sucesso.

- [ ] **Step 4: Estado do git**

Run: `git status --short` e `git diff --cached --stat`
Expected: só ficheiros deste plano (mais os que já estavam em stage antes da Task 1) em stage; nada gerado (`public/build`); **nenhum commit**.

- [ ] **Step 5: Informar o dono do que lhe cabe**

1. `php artisan migrate` (cria `pagamento_sequencias`, `pagamentos`, `pagamento_propinas`, `creditos`, `credito_utilizacoes`; em PostgreSQL também os CHECK e os 4 triggers).
2. `php artisan db:seed --force` (ModuloSeeder: módulo 23 "Pagamento") e `php artisan financeiro:sincronizar --todos` (concede `pagamento.*` e `propina.anular` ao ADMIN_ESCOLA dos tenants existentes e invalida a cache de permissões).
3. Grupo PostgreSQL: `DB_CONNECTION=pgsql DB_DATABASE=mositec_escola_test vendor/bin/phpunit --group=pgsql` (obrigatório para considerar Pagamentos-A validado: concorrência só se prova aqui).
4. Verificação na app (Task 17, Step 5) com `admin@mositec.gmail.com`; nunca editar a BD à mão.
5. Responder às decisões ainda abertas **O9** (segregação de funções) e **O10** (retro-datação com tolerância e permissão), e rever as já decididas por si (O1, O3, O8) na secção abaixo. **F5 (multas) só arranca depois disto validado** (L17 do plano de fases).

---

## Riscos (a ler antes de executar)

| # | Risco | Mitigação / estado |
|---|---|---|
| R-PA.1 | **Concorrência só provada em PostgreSQL** (SQLite ignora `FOR UPDATE`). | Task 19 com processos reais; até o dono correr o grupo `pgsql`, risco aceite (como F3/F4). |
| R-PA.2 | **A linha da sequência serializa todos os registos de um tenant** até ao commit (D4). | Pedida no fim da transacção (último bloqueio), por isso o tempo preso é curto; aceitável em V1. Se surgir contenção, numerar depois (fora de âmbito). |
| R-PA.3 | **Ano da numeração**: o `GeradorSequencia` passa a aceitar `?int $ano`; os outros chamadores (matrículas, utilizadores) mantêm `now()->year` (UTC) — à meia-noite de 31/12 em Luanda podem divergir. | Fora deste plano; registado para o dono (mesma correcção seria passar o ano do relógio da escola). |
| R-PA.4 | **Distribuição explícita com linhas vazias no formulário manual** desloca os índices dos erros `distribuicao.N`. | O erro aparece também no toast; em V1 aceitável (Task 16, nota). |
| R-PA.5 | **Matrícula Pendente com adiantamento e depois eliminada** (soft delete): o pagamento continua válido e anulável, mas não se registam novos nessa matrícula; o crédito continua do aluno. | Comportamento definido (D7, testes T7/T9). Se o dono quiser impedir eliminar matrículas com pagamentos, é um contrato novo do lado da Matrícula (fora de âmbito). |
| R-PA.6 | **Anular um pagamento combinado com crédito** deixa as utilizações Activas (D14/O8): a propina continua parcialmente coberta pelo crédito. | É o spec (§4 "independente") e decisão do dono. Rastreio por `credito_utilizacoes.pagamento_id` (nullable, imutável); o detalhe e o modal de anulação **listam** essas utilizações, com botão próprio de anular. |
| R-PA.7 | **`PagamentoConsultaService` depende de `AnularPagamentoAction`** (só o método de leitura `motivoParaNaoAnular`). | Sem ciclo; se incomodar a regra de camadas, mover o método para `Support\AnulacaoDePagamentos` (refactor pequeno). |
| R-PA.8 | **Pagamentos-B** terá de alterar `credito_utilizacoes.propina_id` para nulo + `propina_multa_id` + CHECK XOR numa tabela já com dados. | Migração `ALTER` simples (colunas novas nulas, sem backfill); o trigger de imutabilidade tem de ser recriado para aceitar a coluna nova. Registado para o plano de B. |
| R-PA.9 | **Data mínima = `data_matricula` (O3).** Uma matrícula com `data_matricula` futura (ex.: Pendente para o ano seguinte) não aceita pagamentos até essa data, porque nenhuma data satisfaz "≥ matrícula e ≤ hoje"; e alterar depois a `data_matricula` para uma data posterior não revalida pagamentos já registados. | Aplicar crédito (D12) usa hoje e continua possível. Se o dono quiser adiantamentos em matrículas futuras ou revalidação na alteração da data, é decisão nova (O10 cobre só a tolerância de N dias). |
| R-PA.10 | **Segregação de funções (O9).** O mesmo ADMIN_ESCOLA regista e anula pagamentos; combinado com datas livres entre a matrícula e hoje, permite retro-datar e anular sem segundo par de olhos. | V1 aceite pelo dono. Ressalva registada: perfil de tesouraria sem `anular` e/ou anulação por utilizador diferente do que registou ficam como decisão futura (O9). A auditoria existe (autor, instante e motivo em tudo). |

## Decisões abertas para o dono (não inventadas; o plano segue a opção indicada até resposta)

- **O1 — Referência duplicada (spec §3). DECIDIDA pelo dono (pré-voo).** O spec diz `unique(tenant_id, metodo_pagamento_id, referencia)` "quando a referência não é nula", sem olhar ao estado. Seguido: único só entre **Confirmados** (D5) **mais** um **aviso não bloqueante** quando a referência já foi usada num pagamento Anulado do mesmo tenant e método (pré-visualização, resposta do registo, resumo da UI; Tasks 9, 13, 16). **Pendente (não decidido):** a mesma transferência registada noutro método passa sem aviso; a alternativa seria a unicidade/aviso sem o método.
- **O2 — Numeração.** **Por omissão:** `AAAA-NNNN` por tenant, com o ano do **dia de registo** na escola (D4). Alternativas: ano de `data_pagamento` (um pagamento retroactivo de Dezembro registado em Janeiro ficaria no ano anterior, fora de ordem), prefixo (ex.: `PAG-2026-0001`), ou ano lectivo.
- **O3 — Limite inferior da data do pagamento. DECIDIDA pelo dono (muda o plano).** `data_pagamento` **nunca anterior à `data_matricula`** da matrícula e nunca futura, validada no pedido e na Action sob lock (D6; Tasks 6, 7, 13, 16). A tolerância "N dias de atraso com permissão própria" **não entra** (ver O10).
- **O4 — Pagamento sem nenhuma propina em dívida** (adiantamento, tudo crédito). **Por omissão:** permitido, com aviso e confirmação explícita na UI (D11, Review Focus 1). Alternativa: recusar.
- **O5 — Distribuição automática sem pagamento parcial.** **Por omissão:** pára na primeira propina que não cabe por inteiro e o resto vira crédito (nunca salta uma mais antiga) (D9). Alternativa: saltar para a seguinte que caiba.
- **O6 — "Antecipado" compara com quê?** **Por omissão:** `periodo_inicio` da propina > **data do pagamento** (D8). Alternativa: > hoje (um pagamento retroactivo registado depois do início do período deixaria de ser "antecipado").
- **O7 — Data de uma utilização de crédito.** **Por omissão:** o dia em que se aplica (hoje da escola), mesmo combinada com um pagamento retroactivo (D10). Conta para `capital_liquidado_em` e, em F5, para as multas. Alternativa: a data do pagamento com que foi combinada.
- **O8 — Anular um pagamento combinado com crédito. DECIDIDA pelo dono.** As utilizações feitas no mesmo acto ficam Activas (spec §4: independentes) (D14) e passam a ser **rastreáveis**: `credito_utilizacoes.pagamento_id` nullable (FK `pagamentos`, restrict), preenchido nas utilizações do mesmo acto, imutável; o detalhe e o modal de anulação listam-nas (Tasks 3, 7, 9, 12, 15). Alternativa não seguida: anulá-las automaticamente com o pagamento.
- **O9 — Permissões por omissão. V1 como está (decisão do dono).** ADMIN_ESCOLA recebe `pagamento` ver/listar/criar/anular/exportar e `propina.anular`; FUNCIONARIO nada (D18). **Ressalva registada (R-PA.10), decisão futura:** sem segregação de funções (o mesmo utilizador regista e anula); alternativas: perfil de tesouraria (FUNCIONARIO com `pagamento.ver/criar`, sem `anular`) e/ou anulação por utilizador diferente do que registou.
- **O10 — Retro-datação com tolerância e permissão própria (NOVA, pendente do dono).** Proposta do pré-voo, **não implementada**: além do limite da `data_matricula` (O3), exigir uma permissão própria (ex.: `pagamento.retrodatar`) para datas anteriores a hoje − N dias (N a definir). Razão: `capital_liquidado_em` antigo evita multas em F5. **Por omissão (até resposta):** datas entre a matrícula e hoje livres para quem tem `pagamento.criar`. Decidir ANTES de F5.

---

## Tabelas e relações (no fim de Pagamentos-A)

| Tabela | Estado | Relações |
|---|---|---|
| `pagamento_sequencias` | **nova** | N:1 `tenants`; `unique(tenant_id, ano)`; gerida pelo `GeradorSequencia` (Core) |
| `pagamentos` | **nova** | N:1 `tenants`; N:1 `matriculas` (restrict); N:1 `alunos` (snapshot de `matricula.aluno_id`, restrict); N:1 `metodos_pagamento` (restrict); N:1 `users` (`criado_por`, `editado_por`, `anulado_por`, set null); 1:N `pagamento_propinas`; 1:0..1 `creditos` (`origem_pagamento_id` único); 1:N `credito_utilizacoes` (utilizações combinadas no mesmo acto, `pagamento_id`). `unique(tenant_id, numero)`; único parcial `(tenant_id, metodo_pagamento_id, referencia) WHERE referencia IS NOT NULL AND estado = 1`. CHECK + trigger: só CONFIRMADO → ANULADO |
| `pagamento_propinas` | **nova** | N:1 `tenants`; N:1 `pagamentos` (restrict); N:1 `propinas` (restrict); `unique(pagamento_id, propina_id)`. Só criação (trigger). Conta para `propinas.valor_pago` enquanto o pagamento está Confirmado |
| `creditos` | **nova** | N:1 `tenants`; N:1 `alunos` (o crédito é do aluno); 1:1 `pagamentos` (`origem_pagamento_id` único, restrict); 1:N `credito_utilizacoes`. CHECK `0 ≤ valor_utilizado ≤ valor` e estado coerente; trigger: aluno/origem/valor imutáveis, ANULADO terminal |
| `credito_utilizacoes` | **nova** | N:1 `tenants`; N:1 `creditos` (restrict); N:1 `propinas` (restrict; propina de uma matrícula do mesmo aluno); N:1 `pagamentos` (`pagamento_id`, **nullable**, restrict: o pagamento do mesmo acto, O8; null na aplicação isolada; imutável); N:1 `users` (`criado_por`, `anulado_por`). Só ACTIVA → ANULADA (trigger). Conta para `propinas.valor_pago` enquanto Activa. Pagamentos-B: `propina_multa_id` + XOR |
| `propinas` | existe (F1) | 1:N `pagamento_propinas`; 1:N `credito_utilizacoes`. `valor_pago`, `estado` 1–3 e `capital_liquidado_em` só pelo `RecalcularPropina` (agora com as fontes reais `PagamentosDaPropina`); estado Anulada só pela `AnulacaoDePropinas` |
| `metodos_pagamento` | existe | 1:N `pagamentos`; com pagamentos não se elimina (`PagamentosReferenciam`), só se desactiva |
| `configuracoes_monetarias` | existe | a moeda não muda enquanto existir qualquer pagamento (`PagamentosReferenciam`) |
| `matriculas`, `alunos` | existem (Matrícula, Aluno) | só lidas e bloqueadas (`FOR UPDATE` da matrícula); nenhum dos dois módulos conhece o Financeiro |
| `modulos` / `role_permissoes` | existem (Permissão) | módulo 23 "Pagamento"; ADMIN_ESCOLA ganha `pagamento.*` e `propina.anular` |

