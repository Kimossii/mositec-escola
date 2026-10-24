# Financeiro → Propinas — F4: Alteração de preço de um plano com propinas — Plano de Implementação

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Git:** só `git add` no fim de cada tarefa. **Nunca `git commit`** (é do dono). `migrate`, `migrate:fresh`, `db:seed`, `financeiro:sincronizar` e a base `mositec_escola_test` são do dono. Nunca correr nada contra a base de desenvolvimento `mositec_escola`.

**Goal:** Uma única operação explícita, transaccional e auditável para mudar o preço de um plano de propina que já tem propinas geradas: novo valor + `mes_efeito` + escolha obrigatória **sem opção pré-seleccionada** entre APLICAR_GERADAS e MANTER_GERADAS; pré-visualização obrigatória com impressão digital; divisão do plano em `mes_efeito`; registo em `alteracoes_preco_plano` e `propina_ajustes` (só acrescentam); isolamento estrito por ano lectivo.

**Architecture:** Um serviço só de leitura (`Services\AlteracaoPrecoPlanoService::preVisualizar`) calcula **tudo** (validações, planos resultantes, propinas afectadas e inalteradas, totais, avisos, impressão digital) e é usado **duas vezes**: pela pré-visualização (sem bloqueios) e pela Action, sobre o estado já bloqueado. `Actions\AlterarPrecoPlanoAction` abre **uma** `DB::transaction`, bloqueia pela ordem global **matrículas → plano → propinas**, recalcula a pré-visualização, recusa se a impressão digital mudou, e delega as escritas em pontos únicos: `Support\DivisaoDePlanoPropina` (encurta o plano antigo e cria o novo com os mesmos alvos, ou muda o valor in loco), `Support\ReassociacaoDePropinas` (único escritor de `plano_propina_id`/`ordem` de uma propina existente) e `RecalcularPropina::reajustarValor` (único escritor de `valor` de uma propina existente, porque `valor`, `estado`, `valor_pago` e `capital_liquidado_em` têm de mudar na **mesma** escrita para cumprir o CHECK `propinas_estado_coerente_check`). Os registos (`AlteracaoPrecoPlano`, `PropinaAjuste`) são models só de criação. Nada fora da BD corre dentro da transacção.

**Tech Stack:** Laravel 12.69 + nwidart/laravel-modules, Inertia + Vue 3 (axios para a pré-visualização JSON), PHPUnit 11.5 com SQLite em memória (produção PostgreSQL; grupo `pgsql` opcional), Metronic/Bootstrap.

**Spec:** `docs/superpowers/specs/2026-10-08-financeiro-propinas-design.md` §14 (a regra inteira), §3 (imutabilidade, invariante auditável), §6 (`RecalcularPropina` ponto único), §15.6 (multas: "não alteradas" — F5 ainda não existe). Plano de fases: `docs/superpowers/plans/2026-10-12-financeiro-propinas-fases.md` §1.2, §4 (L1, L6, L7, L15, L16, L18, L20), F4, §6 (R4, R5, R9, R10), **§8 (decisões do dono: Q10, Q12, Q14 aprovadas; critérios 8.2/1 e 8.2/4; §8.2b relógio; §8.3 pgsql)**. Spec de configuração: `docs/superpowers/specs/2026-10-08-modulo-financeiro-configuracao-design.md` (linha 91: edição de valor de plano com propinas → passo explícito). Formato e rigor: `2026-10-14-financeiro-propinas-f2.md`, `2026-10-15-financeiro-propinas-f3.md`.

## Global Constraints

- Texto de UI, mensagens e comentários em PT-PT com acentos; identificadores, rotas, tabelas e variáveis sem acento.
- Camada fina: Controller → Service (leitura) / Action (escrita), com DTO e FormRequest. `use` no topo, nunca FQN inline (também em testes).
- Dinheiro em **unidades menores** da moeda do tenant (`Dinheiro`, `DinheiroCast`, `Dinheiro::deDecimal($texto, MoedaDoTenant::atual())`), nunca float. Todos os registos na moeda do tenant.
- Tenancy: models novos com `PertenceAoTenant`; `tenant_id` NOT NULL com FK; nunca vem do cliente; plano de outro tenant → 404 (route model binding com `TenantScope`). Hooks `saving` **null-safe** (a `MatrizIsolamentoTest` grava models vazios em cru e espera `TenantNaoResolvido`).
- **Proibido** em `Modules/*/app` (`TenancyArquitecturaTest`): `insert`, `insertOrIgnore`, `insertGetId`, `upsert`, `DB::table|raw|statement`, `*Quietly`, `withoutEvents`, `withoutGlobalScope(s)`. SQL cru só em migrations.
- "Hoje" de negócio só por `RelogioDoTenant::hoje()`/`agora()` em `Modules/Financeiro/app` (`RelogioDoTenantArquitecturaTest`, lista de excepções vazia — não alargar).
- `valor_pago`, `capital_liquidado_em` e `estado` 2/3 só no `RecalcularPropina` (`EscritoresDePropinaTest`); criação de propinas só no `GerarPropinasService` (`CriadoresDePropinaTest`); cancelamento só no `CancelamentoDePropinas` (`CanceladoresDePropinaTest`). **Novo (Task 4):** `valor` de uma propina existente só no `RecalcularPropina`; `plano_propina_id`/`ordem` de uma propina existente só no `ReassociacaoDePropinas`.
- **A Matrícula nunca importa `Modules\Financeiro\`** (`FronteiraMatriculaFinanceiroTest`). F4 não toca no módulo Matrícula.
- Dentro de `DB::transaction` não se apanham excepções para continuar (PostgreSQL aborta a transacção). Conversões de erro (`ViolacaoDeUnicidade`) só **à volta** da transacção. Efeitos fora da BD só depois do commit (`DB::afterCommit`) — F4 não tem nenhum.
- Pagas, Canceladas e Anuladas **nunca** são alteradas; `valor_pago` nunca muda (parciais preservam o recebido); `valor_original` imutável (já garantido pelo model `Propina` desde F1).
- **Git: só `git add`. Nunca `git commit`.**

## Decisões desta fase (finais do dono + aplicação ao código real; não reabrir)

| # | Decisão | Origem / porquê |
|---|---|---|
| D1 | **Operação explícita.** O valor de um plano **com propinas** (qualquer estado, mesmo só canceladas — L18, já em vigor em `AtualizarPlanoPropinaAction`) só muda por `AlterarPrecoPlanoAction`. Parâmetros: `valor` (novo, > 0, ≠ actual), `mes_efeito` (`Y-m`, obrigatório, **início de um período** do plano) e `modo` ∈ {APLICAR_GERADAS=1, MANTER_GERADAS=2} **obrigatório sem valor por omissão** (ausente → 422 "Escolha como tratar as propinas já geradas."). Motivo obrigatório (5–500 após `trim`) e autor (`criado_por`) registados. Plano **sem** propinas → a operação recusa e indica "Editar" (a edição simples continua a funcionar como hoje). | Spec §14 "Princípio", "Parâmetros"; dono. |
| D2 | **Âmbito e origem do conjunto.** Candidatas = `propinas.plano_propina_id = plano.id AND periodo_inicio >= mes_efeito` — **nunca** por turma, alvos ou aluno. APLICAR altera as candidatas com estado persistido **1 ou 2** (Em Aberto/Parcialmente Paga, incluindo as que se resolvem Pendente/Em Atraso); as restantes (Paga, Cancelada, Anulada) ficam no plano antigo, intactas. MANTER não altera nenhuma propina. Propinas com `periodo_inicio < mes_efeito`: intocadas em ambos os modos (só contadas na pré-visualização). | Spec §14 "Âmbito", "Regras fechadas", isolamento. |
| D3 | **Divisão do plano** (quando `mes_efeito` ≠ início do 1.º período): plano antigo encurtado (`mes_fim` = mês anterior a `mes_efeito`); plano novo com **mesmo** `ano_lectivo_id`, `periodicidade`, `intervalo_meses`, `descricao`, `estado` e **alvos copiados**, `mes_inicio` = mês de `mes_efeito`, `mes_fim` original, `valor` novo, nome `"{nome} – Fase N"` (truncado a 100, sufixo ` 2`, ` 3`… se colidir no ano). APLICAR reassocia as elegíveis ao plano novo com `ordem` = posição do `periodo_inicio` nos períodos do plano novo (vencimento, tolerância, limite e período **mantidos**). | Spec §14 "Regras fechadas"; dono ("MANTER_GERADAS cria plano novo com mesmos alvos a partir de mes_efeito e encurta o antigo"). |
| D4 | **Modo in loco (L1):** `mes_efeito` = início do 1.º período → sem divisão, `plano_novo_id = null`; `planos_propina.valor` passa ao novo; APLICAR reajusta as elegíveis no próprio plano (sem reassociar); MANTER só muda o valor do plano. | Fases F4 / L1 (a tabela do spec prevê `plano_novo_id` nulo neste caso). **Ver O1** (confirmação do dono). |
| D5 | **Âncora (L6):** com divisão, a pré-visualização exige que as competências do plano novo (calculadas por `CalendarioDePlano` a partir de `ano_lectivo.data_inicio`) sejam **exactamente** o sufixo das do plano antigo a partir de `mes_efeito`, e que cada `periodo_inicio` a reassociar exista nos períodos do plano novo; senão 422 em `mes_efeito` com mensagem clara. | Fases L6, spec §14 ("recusada na pré-visualização"). |
| D6 | **Reajuste:** `valor = max(valor_novo, valor_pago)`; `valor_pago` nunca muda (o `RecalcularPropina` recalcula-o das contribuições e **lança** se divergir da cache); descida abaixo do pago → `valor = valor_pago`, estado Paga, `capital_liquidado_em` preenchido, **sem devolução**, assinalado na pré-visualização (`excesso`). Uma propina cujo valor não mudaria (já tem o valor resultante) fica em "inalteradas" ("já tem este valor") e não gera ajuste. **Excepção (revisão C): em APLICAR com divisão, as Em Aberto/Parciais que já têm o valor novo NÃO ficam de fora: são reassociadas ao plano novo (ordem pelo período) sem mudar de valor e sem ajuste, para não ficarem presas num plano antigo cujo calendário já não as contém (cenário: MANTER in loco 25k→30k e depois APLICAR com divisão de volta a 25k). Aparecem em `reassociadas_sem_ajuste` da pré-visualização, em `totais.reassociadas_sem_ajuste` e na impressão digital; `total_propinas_afectadas` conta só ajustes.** | Spec §14 "Pagamento parcial"; CHECK `propina_ajustes.valor_novo <> valor_anterior`. |
| D7 | **Ordem de bloqueio (global): matrículas → plano → propinas.** Na transacção: (1) `Matricula::withTrashed()->whereKey(ids)->orderBy('id')->lockForUpdate()` das matrículas das candidatas (lidas antes sem bloqueio); (2) `PlanoPropina::whereKey(id)->lockForUpdate()`; (3) `BloqueioDePropinas::bloquear(ids das candidatas)` (id crescente). A geração (F2: matrícula `FOR UPDATE` → planos `FOR SHARE` → insere) e o cancelamento por estado (F3: matrícula → propinas) usam a mesma ordem: sem ciclos. Uma candidata que apareça entre a leitura e o bloqueio muda a impressão digital → recusa. | Dono; F2 D2/L15; F3 D5. |
| D8 | **Impressão digital** = sha256 de JSON com: versão, `modo`, `mes_efeito`, `valor_novo`; plano (`id`, `ano_lectivo_id`, `valor`, `periodicidade`, `intervalo_meses`, `mes_inicio`, `mes_fim`, `estado`, `updated_at`, chaves dos alvos ordenadas); contagem de propinas de outros anos; e, por **cada candidata** (ordenada por id): `id`, `matricula_id`, `plano_propina_id`, `ano_lectivo_id`, `ordem`, `periodo_inicio`, `estado`, `valor`, `valor_pago`. Confirmação com impressão diferente → 422 em `impressao_digital` ("Os dados mudaram desde a pré-visualização…"); um 2.º clique cai aqui (o plano mudou). | Spec §14 "Confirmação idempotente", isolamento ("inclui o ano_lectivo_id"); fases L16. |
| D9 | **Isolamento por ano lectivo:** além de D2, a pré-visualização conta as propinas **do plano** (todas, qualquer mês) com `propinas.ano_lectivo_id ≠ plano.ano_lectivo_id` **ou** `matriculas.ano_lectivo_id ≠ plano.ano_lectivo_id`; se > 0 → 422 em `plano` ("Foram encontradas propinas de outro ano lectivo…") + `Log::error` com os ids, e a Action reverte tudo (a validação corre dentro da transacção, sobre o estado bloqueado). A pré-visualização mostra o ano (`id`, `nome`) e a linha "Nenhuma propina de outros anos lectivos será alterada" com a contagem verificada (0). Plano novo herda o `ano_lectivo_id` (asserção em `DivisaoDePlanoPropina`). `alteracoes_preco_plano.ano_lectivo_id` guarda o ano (snapshot). | Spec §14 "Regra obrigatória"; fases R10. |
| D10 | **MANTER (L7, M1/M2):** a pré-visualização mostra `aviso_valores_diferentes` com contagens reais: matrículas com propinas activas candidatas ao valor antigo; matrículas Activas do ano sem propina activa a cobrir `mes_efeito` cujas turmas resolvem para este plano nesse mês (pagarão o novo); e uma turma de exemplo com os dois casos (se existir). Se a 1.ª contagem > 0, a confirmação exige `confirmar_valores_diferentes = true` (422 senão). | Spec §14 escolha 2; fases M1, M2 (Q14 aprovado). |
| D11 | **Permissões:** as rotas exigem **as duas** abilities `propina.ajustar` **e** `plano-propina.editar` (Q12 aprovado). `propina.ajustar` já é concedida ao ADMIN_ESCOLA desde F2 (`SincronizarPerfisDeSistemaAction`, `PermissoesPropinaTest::test_admin_escola_tem_as_acoes_de_f1_e_ajustar`): **nada a semear nem a sincronizar**. | Verificado no código. |
| D12 | **Registos só de criação:** `AlteracaoPrecoPlano` e `PropinaAjuste` lançam `LogicException` em `updating`/`deleting`; reverter é uma nova alteração com o seu motivo (M4). `propina_ajustes` sem `updated_at`. Invariante auditável: `valor = valor_original + Σ(valor_novo − valor_anterior)` e encadeamento (`valor_anterior` = `valor_novo` do ajuste anterior da mesma propina; o 1.º = `valor_original`) — `assertPropinasCoerentes` passa a prová-lo para **todas** as propinas. | Spec §3, §14 "Invariantes". |
| D13 | **Uma transacção, tudo ou nada**, pela ordem do spec: bloquear → (encurtar plano antigo → criar plano novo) ou valor in loco → reassociar e reajustar → registar `alteracoes_preco_plano` + `propina_ajustes`. `ValidationException` lançadas lá dentro (não apanhadas) revertem. `QueryException` de unicidade (ex.: nome do plano criado em paralelo) convertida **fora** da transacção em 422 `impressao_digital`. | Spec §14 "Atomicidade de ponta a ponta". |
| D14 | **Anos encerrados:** a operação **é permitida** sobre o plano de qualquer ano (é assim que se corrigem dívidas de anos anteriores, spec §14: "operação separada, feita explicitamente sobre o plano desse ano"). A geração continua a recusar anos encerrados (F2). | Spec §14. **Ver O2** (confirmação do dono). |
| D15 | `mes_efeito` pode ser passado ("para reajustar dívidas antigas, a escola escolhe um `mes_efeito` igual ao primeiro mês em dívida"). Sem dependência do "hoje" excepto no `RecalcularPropina` (já usa `RelogioDoTenant`). | Spec §14. |
| D16 | **Multas (§15.6):** F5 ainda não existe; nada a listar. Quando F5 entrar, acrescenta "multas não alteradas" à pré-visualização (fora deste plano). | Fases F5. |

**Não provado em SQLite (só no grupo pgsql, Task 15):** `FOR UPDATE` das matrículas, do plano e das propinas a segurar outras sessões até ao commit; a geração (`FOR SHARE` nos planos) a esperar pela divisão; a reversão total em PostgreSQL depois de uma falha a meio; os CHECK novos.

## Notas pré-voo (verificadas no código em 2026-10-14, ramo `feat/modulo-financeiro`, F1–F3 em stage)

- **Já existe e F4 reaproveita:**
  - `AtualizarPlanoPropinaAction` bloqueia o plano (`lockForUpdate`) e, com qualquer propina do plano (mesmo Cancelada), recusa mudar `valor` (`MENSAGEM_VALOR`) e o calendário (`MENSAGEM_CALENDARIO`) — `PlanoPropinaComPropinasTest`. F4 só muda o **texto** de `MENSAGEM_VALOR` para apontar "Alterar preço" (Task 11); os testes usam a constante.
  - `PlanoPropinaConsultaService::listar` já devolve `tem_propinas` (`withExists('propinas')`) e `PlanoPropinaFormModal.vue` já desactiva valor e calendário com `tem_propinas` (`calendarioBloqueado`).
  - `Propina::IMUTAVEIS` **não** inclui `plano_propina_id`, `ordem` nem `valor` (comentário: "Plano, ordem e valor só mudam pela alteração de preço (F4)"); o hook `updating` já exige que um novo `plano_propina_id` seja de um plano do **mesmo ano lectivo** e valida a coerência estado/valor pago (as mesmas regras dos CHECK pgsql).
  - `valor_original` já existe, preenchido no `creating` e imutável (F1).
  - `RecalcularPropina` (único escritor de `valor_pago`/`estado` 2–3/`capital_liquidado_em`) bloqueia por `BloqueioDePropinas` e lê contribuições de `FontesDePagamentoDePropina` (sem implementações até Pagamentos-A → `valor_pago = 0` em produção; nos testes, `FontePagamentoFalsa`).
  - `ResolucaoDePlanosMemorizada::sincronizar(…, bloquear: true)` põe os planos do ano em `FOR SHARE` na transacção de cada matrícula da geração (L15) e tem uma impressão dos planos+alvos: uma divisão confirmada faz esquecer a memória. `GeracaoPropinasPgsqlTest::test_os_planos_ficam_em_modo_partilhado_a_divisao_espera_outra_geracao_nao` já prova o lado da geração.
  - A geração ignora "já coberto" por **qualquer** propina activa sobreposta da matrícula, de qualquer plano (spec §14 "nunca se valida contra as competências do plano actual").
  - Permissões: `Modulo::PROPINA` declara `ajustar`; ADMIN_ESCOLA já a tem (F2). `plano-propina.editar` existe (`Modulo::PLANO_PROPINA = 20`).
  - `PropinaConsultaService::detalhe` devolve `'ajustes' => []` e `Pages/Propinas/Show.vue` já tem o cartão "Ajustes de preço" com "Sem ajustes de preço." — F4 preenche-os (Task 13).
  - `ComPropinasFinanceiro::assertPropinasCoerentes` compara `valor === valor_original` com o comentário "F4 acrescenta Σ(ajustes)" — Task 1 actualiza-o.
- **CHECK pgsql relevante:** `propinas_estado_coerente_check` e `propinas_capital_liquidado_check` são verificados por linha em cada `UPDATE`: baixar o `valor` de uma Parcialmente Paga até ao `valor_pago` só passa se `estado = 3` e `capital_liquidado_em` mudarem **no mesmo UPDATE** — por isso o reajuste vive no `RecalcularPropina` (Task 2), nunca numa escrita separada de `valor`.
- **Índice único parcial** `propinas_plano_ordem_activo_unique (tenant_id, matricula_id, plano_propina_id, ordem) WHERE estado IN (1,2,3)`: reassociar para o plano novo (acabado de criar, sem propinas) nunca colide.
- `planos_propina` tem `unique(tenant_id, ano_lectivo_id, nome)` (nome até 100) e não tem CHECK.
- `PlanoPropinaConsultaService::colisaoDeAlvos(ano, alvos, competencias, ignorarPlanoId)` conta planos inactivos e só ignora **um** id.
- **Nenhuma** classe ou tabela de F4 existe (`grep -rn "AlterarPrecoPlano\|propina_ajustes\|alteracoes_preco" Modules/Financeiro` → vazio).
- **Testes sensíveis à hora:** entre as 23:00 e as 24:00 UTC alguns testes pré-existentes falham (F2/F3, notas pré-voo). Não correr a suite nessa janela. Os testes de F4 congelam o relógio (`Carbon::setTestNow('2026-10-16 10:00:00', 'UTC')`).
- **`FontePagamentoFalsa` é obrigatória nos cenários com parciais:** `comEstado(…, PARCIALMENTE_PAGA, x)` grava a cache sem contribuições; o `reajustarValor` recalcula das contribuições e lança se divergir (D6). Os testes de F4 definem sempre a contribuição igual ao `valor_pago` (`$fonte->definir($p, [[x, '2026-10-01']])`).
- **Antes da Task 1:** `git diff --cached --name-only > /tmp/f4-stage-antes.txt` e `php artisan test 2>&1 | tail -3` (baseline; anotar o número).

## Fora de âmbito

Multas (F5; "não alteradas" entra com F5), pagamentos reais (Pagamentos-A), histórico de preços por plano no ecrã de configuração (os registos ficam consultáveis no detalhe de cada propina), agrupar visualmente planos "(desde …)" por família, cópia de planos divididos para o ano seguinte (L20, cosmético), correcção estrutural da âncora do calendário (L6).

## Review Focus

1. **Propinas "órfãs" de uma MANTER anterior:** depois de MANTER, as propinas do antigo com `periodo_inicio ≥ mes_efeito` ficam num plano cujo calendário já não as contém. Uma nova alteração **com divisão** sobre o plano antigo encontra-as como candidatas sem período correspondente no plano novo → tem de recusar com mensagem clara (nunca inventar `ordem`); in loco reajusta-as. Teste na Task 5 (`test_candidata_sem_periodo_no_plano_novo_recusa`).
2. **Pagamento entre a pré-visualização e a confirmação** (cache `valor_pago` muda, ou uma Paga volta a Parcial por anulação): recusa por impressão digital e a UI refaz a pré-visualização — Tasks 8 e 14.
3. **Duplo clique / duas pessoas** a confirmar a mesma pré-visualização: a segunda recusa (o plano mudou — `mes_fim`/`valor`/`updated_at`) e nada é duplicado (um só plano novo, um só registo) — Task 8 (SQLite, sequencial) e Task 15 (pgsql, sessões reais).
4. **Nome do plano novo** a colidir (já existe "Propina Mensal (desde 01/2027)" de uma divisão anterior, ou nome com 100 caracteres): sufixo numérico e truncagem sem violar `unique` — Task 5.
5. **Plano inactivo** com propinas: a operação funciona e o plano novo herda `estado` inactivo (não começa a gerar sozinho) — Task 8 (`test_plano_inactivo_divide_e_o_novo_fica_inactivo`).
6. Isolamento por ano lectivo (8 testes obrigatórios do spec) — Tasks 7, 10 e 15.
7. Reversão total com falha injectada em cada etapa — Task 9.

## Mapa de ficheiros

```
Modules/Financeiro/
  database/migrations/2026_10_16_100000_create_alteracoes_preco_plano_table.php   (T1, novo)
  database/migrations/2026_10_16_100100_create_propina_ajustes_table.php          (T1, novo)
  app/Enums/ModoAlteracaoPreco.php                                               (T1, novo)
  app/Models/AlteracaoPrecoPlano.php                                             (T1, novo)
  app/Models/PropinaAjuste.php                                                   (T1, novo)
  app/Models/Propina.php                                                         (T1, + ajustes())
  app/Services/RecalcularPropina.php                                             (T2, + reajustarValor())
  app/Support/ReassociacaoDePropinas.php                                         (T3, novo)
  app/Support/DivisaoDePlanoPropina.php                                          (T3, novo)
  app/DTO/AlterarPrecoPlanoDTO.php                                               (T5, novo)
  app/DTO/PreVisualizacaoAlteracaoPrecoDTO.php                                   (T5, novo)
  app/Services/AlteracaoPrecoPlanoService.php                                    (T5 núcleo; T6 aviso MANTER; T7 isolamento)
  app/Actions/AlterarPrecoPlanoAction.php                                        (T8, novo)
  app/Actions/AtualizarPlanoPropinaAction.php                                    (T11, texto de MENSAGEM_VALOR)
  app/Http/Requests/Concerns/ValidaAlteracaoPreco.php                            (T12, novo)
  app/Http/Requests/PreVisualizarAlteracaoPrecoRequest.php                       (T12, novo)
  app/Http/Requests/AlterarPrecoPlanoRequest.php                                 (T12, novo)
  app/Http/Controllers/PlanoPropinaController.php                                (T12, + 2 métodos)
  app/Services/PlanoPropinaConsultaService.php                                   (T12, + inicios_periodo)
  app/Services/PropinaConsultaService.php                                        (T13, ajustes no detalhe)
  routes/web.php                                                                 (T12, + 2 rotas)
  resources/js/Components/PlanosPropina/AlterarPrecoPlanoModal.vue               (T14, novo)
  resources/js/Pages/PlanosPropina/Index.vue                                     (T14, acção "Alterar preço")
  resources/js/Pages/Propinas/Show.vue                                           (T14, tabela de ajustes)
  tests/Concerns/ComPropinasFinanceiro.php                                       (T1, invariante auditável)
  tests/Concerns/ComAlteracaoPreco.php                                           (T5, novo: helpers de pré-visualização/confirmação)
  tests/Feature/AlteracaoPrecoRegistosTest.php                                   (T1, novo)
  tests/Feature/ReajustarValorDaPropinaTest.php                                  (T2, novo)
  tests/Feature/DivisaoEReassociacaoTest.php                                     (T3, novo)
  tests/Feature/AlteracaoPrecoPreVisualizacaoTest.php                            (T5, T6, novo)
  tests/Feature/AlterarPrecoPlanoTest.php                                        (T8, novo)
  tests/Feature/AlterarPrecoPlanoFalhasTest.php                                  (T9, novo)
  tests/Feature/AlterarPrecoPlanoIsolamentoAnoLectivoTest.php                    (T7, T10, novo)
  tests/Feature/GeracaoDepoisDaAlteracaoPrecoTest.php                            (T11, novo)
  tests/Feature/AlterarPrecoPlanoHttpTest.php                                    (T12, novo)
  tests/Feature/PropinaDetalheAjustesTest.php                                    (T13, novo)
  tests/Feature/PlanoPropinaComPropinasTest.php                                  (T11, + caso)
  tests/Feature/FinanceiroTenancyTest.php                                        (T1, + casos)
  tests/Unit/ModoAlteracaoPrecoTest.php                                          (T1, novo)
  tests/Unit/AlterarPrecoPlanoVueTest.php                                        (T14, novo)
  tests/Feature/Pgsql/AlteracaoPrecoPgsqlTest.php                                (T15, novo: CHECK)
  tests/Feature/Pgsql/AlteracaoPrecoConcorrenciaPgsqlTest.php                    (T15, novo: duas ligações)
tests/Feature/Arquitectura/EscritoresDePropinaTest.php                           (T4, + regra de reajuste)
resources/js/Components/Shared/acoesLista.js                                     (T14, + ícone alterar-preco)
```

## Grupos e ordem

| Grupo | Tarefas | Entregável verificável |
|---|---|---|
| A — Dados e escritores únicos | 1, 2, 3, 4 | tabelas, models imutáveis, `reajustarValor`, divisão/reassociação, teste de arquitectura |
| B — Cálculo (só leitura) | 5, 6, 7 | pré-visualização completa com impressão digital, aviso MANTER, isolamento |
| C — Escrita transaccional | 8, 9, 10, 11 | Action, injecção de falhas, isolamento na escrita, geração depois da divisão |
| D — HTTP e detalhe | 12, 13 | rotas JSON + confirmação, permissões, detalhe com ajustes |
| E — Frontend | 14 | modal em 2 passos, sem opção pré-seleccionada |
| F — PostgreSQL | 15 | CHECK e concorrência com duas ligações |
| G — Verificação | 16 | suite, build, git, instruções ao dono |

Dependências: 1 → 2 → 3 → 4; 5 depende de 1; 6, 7 dependem de 5; 8 depende de 2, 3, 5–7; 9, 10, 11 dependem de 8; 12 depende de 8; 13 depende de 1; 14 depende de 12, 13; 15 depende de 8; 16 no fim.

---

### Task 1: Tabelas, enum e models só de criação (`alteracoes_preco_plano`, `propina_ajustes`) + invariante auditável (D9, D12)

**Files:**
- Create: `Modules/Financeiro/database/migrations/2026_10_16_100000_create_alteracoes_preco_plano_table.php`
- Create: `Modules/Financeiro/database/migrations/2026_10_16_100100_create_propina_ajustes_table.php`
- Create: `Modules/Financeiro/app/Enums/ModoAlteracaoPreco.php`
- Create: `Modules/Financeiro/app/Models/AlteracaoPrecoPlano.php`
- Create: `Modules/Financeiro/app/Models/PropinaAjuste.php`
- Modify: `Modules/Financeiro/app/Models/Propina.php` (+ relação `ajustes()`)
- Modify: `Modules/Financeiro/tests/Concerns/ComPropinasFinanceiro.php` (`assertPropinasCoerentes` com Σ ajustes e encadeamento)
- Test: `Modules/Financeiro/tests/Unit/ModoAlteracaoPrecoTest.php`, `Modules/Financeiro/tests/Feature/AlteracaoPrecoRegistosTest.php`, `Modules/Financeiro/tests/Feature/FinanceiroTenancyTest.php` (+ 1 caso)

**Interfaces:**
- Consumes: `PertenceAoTenant`, `DinheiroCast`, `DataCast`, `Propina`, `PlanoPropina`, `AnoLectivo`, `User`.
- Produces:
  - `enum ModoAlteracaoPreco: int { APLICAR_GERADAS = 1; MANTER_GERADAS = 2; label(): string; static opcoes(): list<array{value:int,label:string}> }`.
  - `AlteracaoPrecoPlano` (tabela `alteracoes_preco_plano`; preenchíveis: `ano_lectivo_id`, `plano_propina_id`, `plano_novo_id`, `modo`, `valor_anterior`, `valor_novo`, `mes_efeito`, `motivo`, `total_propinas_afectadas`, `impressao_digital`; `criado_por` = `auth()->id()` no `creating` se nulo; `modo_descricao` no `saving`; `updating`/`deleting` lançam `LogicException`). Relações `plano()`, `planoNovo()`, `anoLectivo()`, `ajustes()`, `criadoPor()`.
  - `PropinaAjuste` (tabela `propina_ajustes`, `UPDATED_AT = null`; preenchíveis: `alteracao_preco_id`, `propina_id`, `valor_anterior`, `valor_novo`, `valor_pago_no_momento`, `plano_anterior_id`, `plano_novo_id`, `motivo`; mesmas regras de imutabilidade e autoria). Relações `alteracao()`, `propina()`, `planoAnterior()`, `planoNovo()`, `criadoPor()`.
  - `Propina::ajustes(): HasMany` (por id crescente).
  - `ComPropinasFinanceiro::assertPropinasCoerentes()` passa a provar `valor = valor_original + Σ(valor_novo − valor_anterior)` e o encadeamento.

- [ ] **Step 1: Escrever os testes que falham**

`Modules/Financeiro/tests/Unit/ModoAlteracaoPrecoTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Unit;

use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use PHPUnit\Framework\TestCase;

class ModoAlteracaoPrecoTest extends TestCase
{
    public function test_valores_e_rotulos_sao_os_do_spec(): void
    {
        $this->assertSame(1, ModoAlteracaoPreco::APLICAR_GERADAS->value);
        $this->assertSame(2, ModoAlteracaoPreco::MANTER_GERADAS->value);
        $this->assertSame('Aplicar às propinas já geradas', ModoAlteracaoPreco::APLICAR_GERADAS->label());
        $this->assertSame('Manter as já geradas', ModoAlteracaoPreco::MANTER_GERADAS->label());
    }

    public function test_as_opcoes_nao_marcam_nenhuma_por_omissao(): void
    {
        $this->assertSame([
            ['value' => 1, 'label' => 'Aplicar às propinas já geradas'],
            ['value' => 2, 'label' => 'Manter as já geradas'],
        ], ModoAlteracaoPreco::opcoes());
    }
}
```

`Modules/Financeiro/tests/Feature/AlteracaoPrecoRegistosTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Financeiro\Models\AlteracaoPrecoPlano;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\PropinaAjuste;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

class AlteracaoPrecoRegistosTest extends TestCase
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

    /** @return array{plano: PlanoPropina, propina: Propina, alteracao: AlteracaoPrecoPlano} */
    private function registos(): array
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano, 5);

        $alteracao = AlteracaoPrecoPlano::create([
            'ano_lectivo_id' => $plano->ano_lectivo_id,
            'plano_propina_id' => $plano->id,
            'plano_novo_id' => null,
            'modo' => ModoAlteracaoPreco::APLICAR_GERADAS,
            'valor_anterior' => Dinheiro::deUnidadesMenores(2_500_000),
            'valor_novo' => Dinheiro::deUnidadesMenores(3_000_000),
            'mes_efeito' => '2027-01-01',
            'motivo' => 'Actualização anual',
            'total_propinas_afectadas' => 1,
            'impressao_digital' => str_repeat('a', 64),
        ]);

        return ['plano' => $plano, 'propina' => $propina, 'alteracao' => $alteracao];
    }

    public function test_a_alteracao_regista_modo_descricao_autor_e_snapshot(): void
    {
        $admin = $this->adminEscola();
        $this->actingAs($admin);

        ['alteracao' => $alteracao] = $this->registos();
        $alteracao->refresh();

        $this->assertSame(ModoAlteracaoPreco::APLICAR_GERADAS, $alteracao->modo);
        $this->assertSame('Aplicar às propinas já geradas', $alteracao->modo_descricao);
        $this->assertSame($admin->id, $alteracao->criado_por);
        $this->assertSame('2027-01-01', $alteracao->mes_efeito->toDateString());
        $this->assertSame(3_000_000, $alteracao->valor_novo->unidadesMenores());
    }

    public function test_a_alteracao_nunca_muda_nem_se_elimina(): void
    {
        ['alteracao' => $alteracao] = $this->registos();

        try {
            $alteracao->update(['motivo' => 'Outro']);
            $this->fail('Uma alteração registada não pode mudar.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('nunca muda', $e->getMessage());
        }

        $this->expectException(LogicException::class);
        $alteracao->delete();
    }

    public function test_o_ajuste_so_se_cria_e_a_propina_lista_os_seus_ajustes_por_ordem(): void
    {
        ['plano' => $plano, 'propina' => $propina, 'alteracao' => $alteracao] = $this->registos();
        $primeiro = PropinaAjuste::create([
            'alteracao_preco_id' => $alteracao->id,
            'propina_id' => $propina->id,
            'valor_anterior' => Dinheiro::deUnidadesMenores(2_500_000),
            'valor_novo' => Dinheiro::deUnidadesMenores(3_000_000),
            'valor_pago_no_momento' => Dinheiro::deUnidadesMenores(0),
            'plano_anterior_id' => $plano->id,
            'plano_novo_id' => null,
            'motivo' => 'Actualização anual',
        ]);

        $this->assertNull($primeiro->getAttribute('updated_at'));
        $this->assertNotNull($primeiro->created_at);
        $this->assertSame([$primeiro->id], $propina->ajustes()->pluck('id')->all());

        try {
            $primeiro->update(['motivo' => 'Outro']);
            $this->fail('Um ajuste não pode mudar.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('só se acrescentam', $e->getMessage());
        }

        $this->expectException(LogicException::class);
        $primeiro->delete();
    }

    public function test_a_reconciliacao_exige_valor_igual_ao_original_mais_os_ajustes(): void
    {
        ['propina' => $propina] = $this->registos();
        // Fora da app (só no teste): um valor mudado sem ajuste tem de ser apanhado pela reconciliação.
        DB::table('propinas')->where('id', $propina->id)->update(['valor' => 3_000_000]);

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('valor = valor_original + Σ(ajustes)');

        $this->assertPropinasCoerentes();
    }

    public function test_a_reconciliacao_aceita_um_valor_explicado_pelos_ajustes_encadeados(): void
    {
        ['plano' => $plano, 'propina' => $propina] = $this->registos();
        DB::table('propinas')->where('id', $propina->id)->update(['valor' => 2_800_000]);

        foreach ([[2_500_000, 3_000_000], [3_000_000, 2_800_000]] as [$antes, $depois]) {
            PropinaAjuste::create([
                'alteracao_preco_id' => $this->novaAlteracao($plano)->id,
                'propina_id' => $propina->id,
                'valor_anterior' => Dinheiro::deUnidadesMenores($antes),
                'valor_novo' => Dinheiro::deUnidadesMenores($depois),
                'valor_pago_no_momento' => Dinheiro::deUnidadesMenores(0),
                'plano_anterior_id' => $plano->id,
                'motivo' => 'Teste',
            ]);
        }

        $this->assertPropinasCoerentes();
    }

    private function novaAlteracao(PlanoPropina $plano): AlteracaoPrecoPlano
    {
        return AlteracaoPrecoPlano::create([
            'ano_lectivo_id' => $plano->ano_lectivo_id,
            'plano_propina_id' => $plano->id,
            'modo' => ModoAlteracaoPreco::APLICAR_GERADAS,
            'valor_anterior' => Dinheiro::deUnidadesMenores(2_500_000),
            'valor_novo' => Dinheiro::deUnidadesMenores(2_800_000),
            'mes_efeito' => '2027-01-01',
            'motivo' => 'Teste',
            'total_propinas_afectadas' => 1,
            'impressao_digital' => str_repeat('b', 64),
        ]);
    }
}
```
(O `unique(alteracao_preco_id, propina_id)` obriga a um registo de alteração por ajuste; `novaAlteracao` cria-os.)

Em `Modules/Financeiro/tests/Feature/FinanceiroTenancyTest.php`, acrescentar os `use` (por ordem alfabética) `Modules\Financeiro\Enums\ModoAlteracaoPreco`, `Modules\Financeiro\Models\AlteracaoPrecoPlano`, `Modules\Financeiro\Models\PropinaAjuste`, `Modules\Financeiro\Support\Dinheiro` e o teste:
```php
    public function test_os_registos_de_alteracao_de_preco_sao_do_tenant_e_sem_contexto_lancam(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $criar = function (): int {
            ['plano' => $plano] = $this->cenarioPropinas();

            return AlteracaoPrecoPlano::create([
                'ano_lectivo_id' => $plano->ano_lectivo_id,
                'plano_propina_id' => $plano->id,
                'modo' => ModoAlteracaoPreco::MANTER_GERADAS,
                'valor_anterior' => Dinheiro::deUnidadesMenores(2_500_000),
                'valor_novo' => Dinheiro::deUnidadesMenores(3_000_000),
                'mes_efeito' => '2027-01-01',
                'motivo' => 'Teste',
                'total_propinas_afectadas' => 0,
                'impressao_digital' => str_repeat('c', 64),
            ])->id;
        };
        $minha = $criar();
        $dela = $this->noTenant($outro, $criar);

        $this->assertSame([$minha], AlteracaoPrecoPlano::query()->pluck('id')->all());
        $this->assertNull(AlteracaoPrecoPlano::query()->find($dela));

        app(TenantContext::class)->limpar();

        try {
            AlteracaoPrecoPlano::query()->count();
            $this->fail('A leitura sem contexto devia lançar.');
        } catch (TenantNaoResolvido) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(TenantNaoResolvido::class);
        (new PropinaAjuste())->forceFill([])->save();
    }
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Unit/ModoAlteracaoPrecoTest.php Modules/Financeiro/tests/Feature/AlteracaoPrecoRegistosTest.php` e `php artisan test Modules/Financeiro/tests/Feature/FinanceiroTenancyTest.php --filter=registos_de_alteracao`
Expected: FAIL (enum, models e tabelas inexistentes).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Enums/ModoAlteracaoPreco.php`:
```php
<?php

namespace Modules\Financeiro\Enums;

/**
 * Como tratar as propinas já geradas quando o preço de um plano com propinas muda (spec Propinas §14).
 * Não há valor por omissão: o utilizador escolhe explicitamente a cada alteração.
 */
enum ModoAlteracaoPreco: int
{
    case APLICAR_GERADAS = 1;
    case MANTER_GERADAS = 2;

    public function label(): string
    {
        return match ($this) {
            self::APLICAR_GERADAS => 'Aplicar às propinas já geradas',
            self::MANTER_GERADAS => 'Manter as já geradas',
        };
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public static function opcoes(): array
    {
        return array_map(fn (self $modo) => ['value' => $modo->value, 'label' => $modo->label()], self::cases());
    }
}
```

`Modules/Financeiro/database/migrations/2026_10_16_100000_create_alteracoes_preco_plano_table.php`:
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
        Schema::create('alteracoes_preco_plano', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            // Snapshot do ano do plano alterado (isolamento por ano lectivo, spec §14).
            $table->foreignId('ano_lectivo_id')->constrained('ano_lectivos')->restrictOnDelete();
            $table->foreignId('plano_propina_id')->constrained('planos_propina')->restrictOnDelete();
            // Nulo só no modo in loco (mes_efeito = início do plano).
            $table->foreignId('plano_novo_id')->nullable()->constrained('planos_propina')->restrictOnDelete();
            $table->unsignedTinyInteger('modo');
            $table->string('modo_descricao');
            $table->unsignedBigInteger('valor_anterior');
            $table->unsignedBigInteger('valor_novo');
            $table->date('mes_efeito');
            $table->text('motivo');
            $table->unsignedInteger('total_propinas_afectadas');
            $table->char('impressao_digital', 64);
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'plano_propina_id'], 'alteracoes_preco_plano_plano_index');
            $table->index(['tenant_id', 'plano_novo_id'], 'alteracoes_preco_plano_novo_index');
            $table->index(['tenant_id', 'ano_lectivo_id'], 'alteracoes_preco_plano_ano_index');
        });

        // SQLite não aceita ALTER TABLE … ADD CONSTRAINT: os CHECK só existem em PostgreSQL (produção).
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'alteracoes_preco_plano_valores_check' => 'valor_anterior > 0 AND valor_novo > 0 AND valor_novo <> valor_anterior',
            'alteracoes_preco_plano_modo_check' => 'modo IN (1, 2)',
            'alteracoes_preco_plano_mes_efeito_check' => 'EXTRACT(DAY FROM mes_efeito) = 1',
            'alteracoes_preco_plano_motivo_check' => "btrim(motivo) <> ''",
            'alteracoes_preco_plano_planos_check' => 'plano_novo_id IS NULL OR plano_novo_id <> plano_propina_id',
        ] as $nome => $expressao) {
            DB::statement("ALTER TABLE alteracoes_preco_plano ADD CONSTRAINT {$nome} CHECK ({$expressao})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('alteracoes_preco_plano');
    }
};
```

`Modules/Financeiro/database/migrations/2026_10_16_100100_create_propina_ajustes_table.php`:
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
        // Só acrescenta (o model recusa update/delete): reverter é uma nova alteração com o seu motivo.
        Schema::create('propina_ajustes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('alteracao_preco_id')->constrained('alteracoes_preco_plano')->restrictOnDelete();
            $table->foreignId('propina_id')->constrained('propinas')->restrictOnDelete();
            $table->unsignedBigInteger('valor_anterior');
            $table->unsignedBigInteger('valor_novo');
            $table->unsignedBigInteger('valor_pago_no_momento');
            $table->foreignId('plano_anterior_id')->constrained('planos_propina')->restrictOnDelete();
            $table->foreignId('plano_novo_id')->nullable()->constrained('planos_propina')->restrictOnDelete();
            $table->text('motivo');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['alteracao_preco_id', 'propina_id'], 'propina_ajustes_alteracao_propina_unique');
            $table->index(['tenant_id', 'propina_id', 'id'], 'propina_ajustes_propina_index');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            'propina_ajustes_valores_check' => 'valor_anterior > 0 AND valor_novo > 0 AND valor_novo <> valor_anterior',
            'propina_ajustes_valor_pago_check' => 'valor_pago_no_momento <= valor_novo',
            'propina_ajustes_motivo_check' => "btrim(motivo) <> ''",
        ] as $nome => $expressao) {
            DB::statement("ALTER TABLE propina_ajustes ADD CONSTRAINT {$nome} CHECK ({$expressao})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('propina_ajustes');
    }
};
```

`Modules/Financeiro/app/Models/AlteracaoPrecoPlano.php`:
```php
<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Financeiro\Casts\DataCast;
use Modules\Financeiro\Casts\DinheiroCast;
use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Usuario\Models\User;

/**
 * Registo de uma alteração de preço de um plano com propinas (spec Propinas §14): a escolha, o mês de efeito,
 * o motivo e o autor. Só se cria: reverter é uma nova alteração, com o seu motivo.
 */
class AlteracaoPrecoPlano extends Model
{
    use PertenceAoTenant;

    protected $table = 'alteracoes_preco_plano';

    protected $fillable = [
        'ano_lectivo_id',
        'plano_propina_id',
        'plano_novo_id',
        'modo',
        'valor_anterior',
        'valor_novo',
        'mes_efeito',
        'motivo',
        'total_propinas_afectadas',
        'impressao_digital',
    ];

    protected $hidden = ['tenant_id'];

    protected $casts = [
        'ano_lectivo_id' => 'integer',
        'plano_propina_id' => 'integer',
        'plano_novo_id' => 'integer',
        'modo' => ModoAlteracaoPreco::class,
        'valor_anterior' => DinheiroCast::class,
        'valor_novo' => DinheiroCast::class,
        'mes_efeito' => DataCast::class,
        'total_propinas_afectadas' => 'integer',
        'criado_por' => 'integer',
    ];

    protected static function booted(): void
    {
        // Null-safe: a matriz de isolamento grava models vazios em cru e espera TenantNaoResolvido (lançado no
        // `creating` da trait, registado antes deste).
        static::saving(function (self $alteracao) {
            $alteracao->modo_descricao = $alteracao->modo?->label();
        });

        // Sem `editado_por` (nunca há edição): não usa RegistaAutoria.
        static::creating(function (self $alteracao) {
            $alteracao->criado_por ??= auth()->id();
        });

        static::updating(function () {
            throw new LogicException('Uma alteração de preço registada nunca muda: reverter é uma nova alteração, com o seu motivo.');
        });

        static::deleting(function () {
            throw new LogicException('Uma alteração de preço registada nunca é eliminada.');
        });
    }

    public function plano(): BelongsTo
    {
        return $this->belongsTo(PlanoPropina::class, 'plano_propina_id');
    }

    public function planoNovo(): BelongsTo
    {
        return $this->belongsTo(PlanoPropina::class, 'plano_novo_id');
    }

    public function anoLectivo(): BelongsTo
    {
        return $this->belongsTo(AnoLectivo::class, 'ano_lectivo_id')->withTrashed();
    }

    public function ajustes(): HasMany
    {
        return $this->hasMany(PropinaAjuste::class, 'alteracao_preco_id')->orderBy('id');
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }
}
```

`Modules/Financeiro/app/Models/PropinaAjuste.php`:
```php
<?php

namespace Modules\Financeiro\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;
use Modules\Core\Tenancy\PertenceAoTenant;
use Modules\Financeiro\Casts\DinheiroCast;
use Modules\Usuario\Models\User;

/**
 * Um reajuste do valor de UMA propina por uma alteração de preço (spec Propinas §14). Só se acrescenta: a cadeia
 * de ajustes explica o valor corrente (`valor = valor_original + Σ(valor_novo − valor_anterior)`).
 */
class PropinaAjuste extends Model
{
    use PertenceAoTenant;

    public const UPDATED_AT = null;

    protected $table = 'propina_ajustes';

    protected $fillable = [
        'alteracao_preco_id',
        'propina_id',
        'valor_anterior',
        'valor_novo',
        'valor_pago_no_momento',
        'plano_anterior_id',
        'plano_novo_id',
        'motivo',
    ];

    protected $hidden = ['tenant_id'];

    protected $casts = [
        'alteracao_preco_id' => 'integer',
        'propina_id' => 'integer',
        'valor_anterior' => DinheiroCast::class,
        'valor_novo' => DinheiroCast::class,
        'valor_pago_no_momento' => DinheiroCast::class,
        'plano_anterior_id' => 'integer',
        'plano_novo_id' => 'integer',
        'criado_por' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $ajuste) {
            $ajuste->criado_por ??= auth()->id();
        });

        static::updating(function () {
            throw new LogicException('Os ajustes de uma propina só se acrescentam: nunca se editam.');
        });

        static::deleting(function () {
            throw new LogicException('Os ajustes de uma propina só se acrescentam: nunca se eliminam.');
        });
    }

    public function alteracao(): BelongsTo
    {
        return $this->belongsTo(AlteracaoPrecoPlano::class, 'alteracao_preco_id');
    }

    public function propina(): BelongsTo
    {
        return $this->belongsTo(Propina::class, 'propina_id');
    }

    public function planoAnterior(): BelongsTo
    {
        return $this->belongsTo(PlanoPropina::class, 'plano_anterior_id');
    }

    public function planoNovo(): BelongsTo
    {
        return $this->belongsTo(PlanoPropina::class, 'plano_novo_id');
    }

    public function criadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criado_por');
    }
}
```
Atenção: a mensagem de `updating` tem de conter "só se acrescentam" (o teste procura-a).

Em `Modules/Financeiro/app/Models/Propina.php`: acrescentar `use Illuminate\Database\Eloquent\Relations\HasMany;` e, a seguir a `anoLectivo()`:
```php
    /**
     * Ajustes de preço (F4, spec §14), do mais antigo para o mais recente.
     */
    public function ajustes(): HasMany
    {
        return $this->hasMany(PropinaAjuste::class, 'propina_id')->orderBy('id');
    }
```

Em `Modules/Financeiro/tests/Concerns/ComPropinasFinanceiro.php`: acrescentar `use Modules\Financeiro\Models\PropinaAjuste;`, mudar o docblock de `assertPropinasCoerentes` para "Reconciliação global (critério 8.2/1 do plano de fases) sobre TODAS as propinas do tenant corrente, incluindo a invariante auditável de §3 (valor = valor_original + Σ ajustes, encadeados)." e substituir a linha
```php
            $this->assertSame($propina->valor_original->unidadesMenores(), $valor, "{$id}: valor = valor_original (sem ajustes em F1)");
```
por
```php
            $esperado = $propina->valor_original->unidadesMenores();
            $anterior = $esperado;

            foreach (PropinaAjuste::query()->where('propina_id', $propina->id)->orderBy('id')->get() as $ajuste) {
                $this->assertSame($anterior, $ajuste->valor_anterior->unidadesMenores(), "{$id}: o ajuste {$ajuste->id} continua o anterior");
                $esperado += $ajuste->valor_novo->unidadesMenores() - $ajuste->valor_anterior->unidadesMenores();
                $anterior = $ajuste->valor_novo->unidadesMenores();
            }

            $this->assertSame($esperado, $valor, "{$id}: valor = valor_original + Σ(ajustes)");
```
(Nota: a variável `$esperado` já é usada mais abaixo para o estado esperado; renomear essa para `$estadoEsperado` nas duas linhas onde aparece.)

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Unit/ModoAlteracaoPrecoTest.php Modules/Financeiro/tests/Feature/AlteracaoPrecoRegistosTest.php Modules/Financeiro/tests/Feature/FinanceiroTenancyTest.php tests/Feature/Tenancy/MatrizIsolamentoTest.php tests/Feature/Arquitectura/TenancyEsquemaTest.php`
Expected: PASS (a matriz descobre os dois models novos: sem contexto lançam `TenantNaoResolvido`; o esquema vê `tenant_id` NOT NULL com FK nas duas tabelas).

Run: `php artisan test Modules/Financeiro` (todos os testes que usam `assertPropinasCoerentes` continuam verdes: sem ajustes, Σ = 0).

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/database/migrations/2026_10_16_100000_create_alteracoes_preco_plano_table.php Modules/Financeiro/database/migrations/2026_10_16_100100_create_propina_ajustes_table.php Modules/Financeiro/app/Enums/ModoAlteracaoPreco.php Modules/Financeiro/app/Models/AlteracaoPrecoPlano.php Modules/Financeiro/app/Models/PropinaAjuste.php Modules/Financeiro/app/Models/Propina.php Modules/Financeiro/tests/Concerns/ComPropinasFinanceiro.php Modules/Financeiro/tests/Unit/ModoAlteracaoPrecoTest.php Modules/Financeiro/tests/Feature/AlteracaoPrecoRegistosTest.php Modules/Financeiro/tests/Feature/FinanceiroTenancyTest.php
```

---

### Task 2: `RecalcularPropina::reajustarValor` — único escritor de `valor` de uma propina existente (D6)

**Files:**
- Modify: `Modules/Financeiro/app/Services/RecalcularPropina.php`
- Test: `Modules/Financeiro/tests/Feature/ReajustarValorDaPropinaTest.php`

**Interfaces:**
- Consumes: `BloqueioDePropinas::bloquear(list<int>)`, `FontesDePagamentoDePropina::contribuicoes(Propina)`, `RelogioDoTenant::hoje()`, `EstadoCobranca`.
- Produces: `RecalcularPropina::reajustarValor(Propina $propina, Dinheiro $valorPretendido): Propina` — exige transacção aberta (via `BloqueioDePropinas`), bloqueia e relê a propina; só Em Aberto/Parcialmente Paga (senão `LogicException`); `valorPretendido > 0` (senão `InvalidArgumentException`); recalcula `valor_pago` das contribuições e **lança `LogicException` se divergir da cache** (o recebido nunca muda); grava numa só escrita `valor = max(pretendido, pago)`, `valor_pago`, `estado` (Em Aberto/Parcial/Paga) e `capital_liquidado_em`. Devolve a instância gravada. `executar()`/`executarVarias()` não mudam de comportamento.

- [ ] **Step 1: Escrever o teste que falha**

`Modules/Financeiro/tests/Feature/ReajustarValorDaPropinaTest.php`:
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
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\RecalcularPropina;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\FontePagamentoFalsa;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReajustarValorDaPropinaTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    private FontePagamentoFalsa $fonte;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
        $this->fonte = $this->fontePagamentoFalsa();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function aberta(): Propina
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

        return $this->propina($matricula, $plano, 5);
    }

    /** Parcialmente Paga com 10.000,00 recebidos (cache E contribuição coerentes). */
    private function parcial(): Propina
    {
        $propina = $this->comEstado($this->aberta(), EstadoCobranca::PARCIALMENTE_PAGA, 1_000_000);
        $this->fonte->definir($propina, [[1_000_000, '2026-10-01']]);

        return $propina;
    }

    private function reajustar(Propina $propina, int $valor): Propina
    {
        return DB::transaction(fn () => app(RecalcularPropina::class)->reajustarValor($propina, Dinheiro::deUnidadesMenores($valor)));
    }

    public function test_subida_numa_propina_em_aberto_muda_so_o_valor(): void
    {
        $propina = $this->reajustar($this->aberta(), 3_000_000)->refresh();

        $this->assertSame(3_000_000, $propina->valor->unidadesMenores());
        $this->assertSame(2_500_000, $propina->valor_original->unidadesMenores());
        $this->assertSame(0, $propina->valor_pago->unidadesMenores());
        $this->assertSame(EstadoCobranca::EM_ABERTO, $propina->estado);
        $this->assertNull($propina->capital_liquidado_em);
    }

    public function test_parcial_com_subida_preserva_o_pago_e_recalcula_o_saldo(): void
    {
        $propina = $this->reajustar($this->parcial(), 3_000_000)->refresh();

        $this->assertSame(3_000_000, $propina->valor->unidadesMenores());
        $this->assertSame(1_000_000, $propina->valor_pago->unidadesMenores());
        $this->assertSame(2_000_000, $propina->saldo()->unidadesMenores());
        $this->assertSame(EstadoCobranca::PARCIALMENTE_PAGA, $propina->estado);
    }

    public function test_parcial_com_descida_acima_do_pago_continua_parcial(): void
    {
        $propina = $this->reajustar($this->parcial(), 1_500_000)->refresh();

        $this->assertSame(1_500_000, $propina->valor->unidadesMenores());
        $this->assertSame(500_000, $propina->saldo()->unidadesMenores());
        $this->assertSame(EstadoCobranca::PARCIALMENTE_PAGA, $propina->estado);
    }

    #[DataProvider('descidasAteAoPago')]
    public function test_descida_ate_ou_abaixo_do_pago_fica_no_pago_e_passa_a_paga_sem_devolucao(int $pretendido): void
    {
        $propina = $this->reajustar($this->parcial(), $pretendido)->refresh();

        $this->assertSame(1_000_000, $propina->valor->unidadesMenores(), 'valor = valor_pago (sem devolução)');
        $this->assertSame(1_000_000, $propina->valor_pago->unidadesMenores());
        $this->assertSame(EstadoCobranca::PAGA, $propina->estado);
        $this->assertSame('2026-10-01', $propina->capital_liquidado_em?->toDateString());
    }

    public static function descidasAteAoPago(): array
    {
        return ['igual ao pago' => [1_000_000], 'abaixo do pago' => [800_000]];
    }

    #[DataProvider('estadosQueNaoSeReajustam')]
    public function test_paga_cancelada_e_anulada_nunca_se_reajustam(EstadoCobranca $estado): void
    {
        $propina = $this->comEstado($this->aberta(), $estado);

        try {
            $this->reajustar($propina, 3_000_000);
            $this->fail('Devia recusar.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Só se reajusta o valor de uma propina Em Aberto ou Parcialmente Paga', $e->getMessage());
        }

        $this->assertSame(2_500_000, $propina->refresh()->valor->unidadesMenores());
    }

    public static function estadosQueNaoSeReajustam(): array
    {
        return ['Paga' => [EstadoCobranca::PAGA], 'Cancelada' => [EstadoCobranca::CANCELADA], 'Anulada' => [EstadoCobranca::ANULADA]];
    }

    public function test_cache_de_valor_pago_divergente_recusa_e_nada_muda(): void
    {
        // Cache 10.000,00 sem contribuições: reajustar mudaria o recebido, o que o spec proíbe.
        $propina = $this->comEstado($this->aberta(), EstadoCobranca::PARCIALMENTE_PAGA, 1_000_000);

        try {
            $this->reajustar($propina, 3_000_000);
            $this->fail('Devia recusar.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('não coincide com os pagamentos', $e->getMessage());
        }

        $propina->refresh();
        $this->assertSame(2_500_000, $propina->valor->unidadesMenores());
        $this->assertSame(1_000_000, $propina->valor_pago->unidadesMenores());
    }

    public function test_valor_pretendido_tem_de_ser_positivo(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->reajustar($this->aberta(), 0);
    }

    public function test_exige_transaccao_aberta(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('exige uma transacção aberta');

        app(RecalcularPropina::class)->reajustarValor($this->aberta(), Dinheiro::deUnidadesMenores(3_000_000));
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/ReajustarValorDaPropinaTest.php`
Expected: FAIL ("Call to undefined method …reajustarValor()").

- [ ] **Step 3: Implementar**

Em `Modules/Financeiro/app/Services/RecalcularPropina.php`:

1. Acrescentar `use InvalidArgumentException;` (ordem alfabética, antes de `use LogicException;`).
2. Actualizar o docblock da classe: "ÚNICO escritor de `valor_pago`, de `estado` entre Em Aberto / Parcialmente Paga / Paga, de `capital_liquidado_em` e — desde F4 — do `valor` de uma propina já gerada (`reajustarValor`, spec §14). …" (resto igual).
3. Acrescentar, a seguir a `executarVarias()`:
```php
    /**
     * Reajusta o valor de UMA propina Em Aberto ou Parcialmente Paga (alteração de preço, F4 / spec §14), na MESMA
     * escrita que o estado, o valor pago e a liquidação: os CHECK de PostgreSQL são verificados por linha, e baixar o
     * valor até ao pago só é coerente se a propina passar a Paga no mesmo UPDATE. `valor = max(pretendido, pago)`:
     * o recebido nunca muda e uma descida abaixo dele fica no pago (Paga, sem devolução). Exige a transacção do chamador.
     */
    public function reajustarValor(Propina $propina, Dinheiro $valorPretendido): Propina
    {
        if ($valorPretendido->unidadesMenores() <= 0) {
            throw new InvalidArgumentException('O valor reajustado de uma propina tem de ser maior que zero.');
        }

        $bloqueada = $this->bloqueio->bloquear([(int) $propina->getKey()])->first()
            ?? throw new LogicException('A propina a reajustar não existe no tenant corrente.');

        if (! in_array($bloqueada->estado, [EstadoCobranca::EM_ABERTO, EstadoCobranca::PARCIALMENTE_PAGA], true)) {
            throw new LogicException("Só se reajusta o valor de uma propina Em Aberto ou Parcialmente Paga (a propina {$bloqueada->id} está {$bloqueada->estado->label()}).");
        }

        return $this->recalcular($bloqueada, $valorPretendido->unidadesMenores());
    }
```
4. Substituir o método privado `recalcular` inteiro por:
```php
    /**
     * @param  int|null  $valorPretendido  só no reajuste de preço (F4); null = o valor actual não muda
     */
    private function recalcular(Propina $bloqueada, ?int $valorPretendido = null): Propina
    {
        $contribuicoes = $this->fontes->contribuicoes($bloqueada);

        if (in_array($bloqueada->estado, [EstadoCobranca::CANCELADA, EstadoCobranca::ANULADA], true)) {
            if ($contribuicoes !== []) {
                throw new LogicException('Uma propina cancelada ou anulada não pode ter pagamentos activos.');
            }

            return $bloqueada;
        }

        $hoje = $this->relogio->hoje()->toDateString();
        $pago = 0;

        foreach ($contribuicoes as $contribuicao) {
            if ($contribuicao->data->toDateString() > $hoje) {
                throw new LogicException("Contribuição com data futura na propina {$bloqueada->id}.");
            }

            $pago += $contribuicao->valor->unidadesMenores();
        }

        // F4 (spec §14): o recebido nunca muda com um reajuste de preço. Uma cache desactualizada mudá-lo-ia.
        if ($valorPretendido !== null && $pago !== $bloqueada->valor_pago->unidadesMenores()) {
            throw new LogicException("O valor pago em cache da propina {$bloqueada->id} não coincide com os pagamentos: recalcule-a antes de reajustar o valor.");
        }

        $valor = $valorPretendido === null ? $bloqueada->valor->unidadesMenores() : max($valorPretendido, $pago);

        if ($pago > $valor) {
            throw new LogicException("O valor pago ({$pago}) excede o valor da propina ({$valor}) na propina {$bloqueada->id}.");
        }

        $liquidadaEm = null;
        $acumulado = 0;

        foreach ($contribuicoes as $contribuicao) {
            $acumulado += $contribuicao->valor->unidadesMenores();

            if ($acumulado >= $valor) {
                $liquidadaEm = $contribuicao->data;

                break;
            }
        }

        $novoEstado = match (true) {
            $pago === 0 => EstadoCobranca::EM_ABERTO,
            $pago === $valor => EstadoCobranca::PAGA,
            default => EstadoCobranca::PARCIALMENTE_PAGA,
        };

        if ($novoEstado !== $bloqueada->estado && ! $bloqueada->estado->podeTransitarPara($novoEstado)) {
            throw new LogicException("Transição inválida de {$bloqueada->estado->label()} para {$novoEstado->label()}.");
        }

        // Uma só escrita (CHECK por linha em PostgreSQL). Sem reajuste, `valor` fica igual (não sai como alterado).
        $bloqueada->forceFill([
            'valor' => Dinheiro::deUnidadesMenores($valor),
            'valor_pago' => Dinheiro::deUnidadesMenores($pago),
            'estado' => $novoEstado,
            'capital_liquidado_em' => $pago === $valor ? $liquidadaEm : null,
        ])->save();

        // F5: a multa da propina (se existir) é recalculada aqui, na mesma transacção.

        return $bloqueada;
    }
```
(Comportamento de `executar` igual ao anterior: o 1.º laço soma e valida datas; o 2.º encontra a data de liquidação pela mesma ordem das contribuições.)

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Feature/ReajustarValorDaPropinaTest.php Modules/Financeiro/tests/Feature/RecalcularPropinaTest.php tests/Feature/Arquitectura/EscritoresDePropinaTest.php`
Expected: PASS (todos os casos antigos de `RecalcularPropinaTest` incluídos).

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Services/RecalcularPropina.php Modules/Financeiro/tests/Feature/ReajustarValorDaPropinaTest.php
```

---

### Task 3: Pontos únicos de escrita do plano e da reassociação — `DivisaoDePlanoPropina`, `ReassociacaoDePropinas` (D3, D4, D9)

**Files:**
- Create: `Modules/Financeiro/app/Support/DivisaoDePlanoPropina.php`
- Create: `Modules/Financeiro/app/Support/ReassociacaoDePropinas.php`
- Test: `Modules/Financeiro/tests/Feature/DivisaoEReassociacaoTest.php`

**Interfaces:**
- Consumes: `PlanoPropina` (`alvos()`, `substituirAlvos()`, `competencias()`, `periodos()`), `PlanoPropinaConsultaService::colisaoDeAlvos(int, array, array, ?int): bool`, `Propina`.
- Produces:
  - `DivisaoDePlanoPropina::dividir(PlanoPropina $bloqueado, CarbonImmutable $mesEfeito, Dinheiro $valorNovo, string $nomeNovo): PlanoPropina` — exige transacção; encurta o antigo (`mes_fim` = mês anterior a `mes_efeito`) e cria o novo (mesmo ano, periodicidade, intervalo, descrição, estado, alvos; `mes_inicio` = mês de `mes_efeito`; `mes_fim` original; valor novo; nome dado). Devolve o novo com `alvos` e `anoLectivo` carregados. `LogicException` se o novo não for do mesmo ano ou colidir com outro plano (asserções: nunca deviam acontecer).
  - `DivisaoDePlanoPropina::alterarValor(PlanoPropina $bloqueado, Dinheiro $valorNovo): PlanoPropina` — modo in loco; exige transacção.
  - `ReassociacaoDePropinas::reassociar(Propina $bloqueada, PlanoPropina $planoNovo, int $ordem): Propina` — exige transacção e a propina já bloqueada pelo chamador; só Em Aberto/Parcialmente Paga; `ordem ≥ 1`; plano do mesmo ano lectivo da propina; grava só `plano_propina_id` e `ordem`.
  - Este ficheiro **não** referencia o tipo `Propina` em `DivisaoDePlanoPropina` (o teste de arquitectura da Task 4 só vigia `valor` em ficheiros que referenciam `Propina`; o `valor` do **plano** escreve-se aqui).

- [ ] **Step 1: Escrever o teste que falha**

`Modules/Financeiro/tests/Feature/DivisaoEReassociacaoTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Core\Enums\Estado;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\Periodicidade;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\PlanoPropinaAlvo;
use Modules\Financeiro\Support\AlvosDoPlano;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Support\DivisaoDePlanoPropina;
use Modules\Financeiro\Support\ReassociacaoDePropinas;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Tests\TestCase;

class DivisaoEReassociacaoTest extends TestCase
{
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    private function dividir(PlanoPropina $plano, string $mesEfeito, int $valor = 3_000_000, string $nome = 'Novo'): PlanoPropina
    {
        return DB::transaction(fn () => app(DivisaoDePlanoPropina::class)->dividir(
            PlanoPropina::query()->whereKey($plano->id)->lockForUpdate()->firstOrFail(),
            CarbonImmutable::createFromFormat('!Y-m', $mesEfeito),
            Dinheiro::deUnidadesMenores($valor),
            $nome,
        ));
    }

    /** @return list<string> */
    private function chavesDosAlvos(PlanoPropina $plano): array
    {
        $chaves = $plano->alvos()->get()->map(fn (PlanoPropinaAlvo $a) => AlvosDoPlano::chave($a->only(['nivel_academico_id', 'curso_id', 'turno_id', 'turma_id'])))->all();
        sort($chaves);

        return $chaves;
    }

    public function test_dividir_encurta_o_antigo_e_cria_o_novo_com_os_mesmos_alvos_e_ano(): void
    {
        $ano = $this->anoLectivo();
        $nivel = $this->nivel('N1');
        $turma = $this->turma($ano, $this->nivel('N2'));
        $plano = $this->plano($ano, 'Propina Mensal', ['descricao' => 'Desc'], [['nivel' => $nivel], ['turma' => $turma]]);

        $novo = $this->dividir($plano, '2027-01', 3_000_000, 'Propina Mensal (desde 01/2027)');
        $plano->refresh();

        $this->assertSame(9, $plano->mes_inicio);
        $this->assertSame(12, $plano->mes_fim);
        $this->assertSame(2_500_000, $plano->valor->unidadesMenores());
        $this->assertCount(4, $plano->periodos());

        $this->assertSame($plano->ano_lectivo_id, $novo->ano_lectivo_id);
        $this->assertSame('Propina Mensal (desde 01/2027)', $novo->nome);
        $this->assertSame('Desc', $novo->descricao);
        $this->assertSame(Periodicidade::MENSAL, $novo->periodicidade);
        $this->assertSame(1, $novo->intervalo_meses);
        $this->assertSame(1, $novo->mes_inicio);
        $this->assertSame(6, $novo->mes_fim);
        $this->assertSame(3_000_000, $novo->valor->unidadesMenores());
        $this->assertSame(Estado::ATIVO->value, $novo->estado);
        $this->assertSame($this->chavesDosAlvos($plano), $this->chavesDosAlvos($novo));
        $this->assertSame('2027-01-01', $novo->periodos()[0]['inicio']);
        $this->assertCount(6, $novo->periodos());
    }

    public function test_dividir_um_plano_trimestral_numa_fronteira_mantem_os_periodos_seguintes(): void
    {
        $plano = $this->plano($this->anoLectivo(), 'Trimestral', ['periodicidade' => Periodicidade::TRIMESTRAL, 'intervalo_meses' => 3]);

        $novo = $this->dividir($plano, '2026-12');

        $this->assertSame([3], array_column($plano->refresh()->periodos(), 'meses'));
        $this->assertSame([['2026-12-01', 3], ['2027-03-01', 3], ['2027-06-01', 1]], array_map(fn ($p) => [$p['inicio'], $p['meses']], $novo->periodos()));
    }

    public function test_plano_inactivo_divide_e_o_novo_fica_inactivo(): void
    {
        $plano = $this->plano($this->anoLectivo(), 'Inactivo', ['estado' => Estado::INATIVO->value]);

        $this->assertSame(Estado::INATIVO->value, $this->dividir($plano, '2027-01')->estado);
    }

    public function test_alterar_valor_in_loco_so_muda_o_valor(): void
    {
        $plano = $this->plano($this->anoLectivo(), 'Mensal');

        DB::transaction(fn () => app(DivisaoDePlanoPropina::class)->alterarValor($plano, Dinheiro::deUnidadesMenores(2_800_000)));

        $plano->refresh();
        $this->assertSame(2_800_000, $plano->valor->unidadesMenores());
        $this->assertSame([9, 6], [$plano->mes_inicio, $plano->mes_fim]);
    }

    public function test_dividir_e_alterar_valor_exigem_transaccao(): void
    {
        $plano = $this->plano($this->anoLectivo(), 'Mensal');

        try {
            app(DivisaoDePlanoPropina::class)->alterarValor($plano, Dinheiro::deUnidadesMenores(2_800_000));
            $this->fail('Devia exigir transacção.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('transacção aberta', $e->getMessage());
        }

        $this->expectException(LogicException::class);
        app(DivisaoDePlanoPropina::class)->dividir($plano, CarbonImmutable::parse('2027-01-01'), Dinheiro::deUnidadesMenores(1), 'X');
    }

    public function test_reassociar_muda_so_plano_e_ordem_de_uma_propina_em_aberto_ou_parcial(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $janeiro = $this->comEstado($this->propina($matricula, $plano, 5), EstadoCobranca::PARCIALMENTE_PAGA, 1_000_000);
        $novo = $this->dividir($plano, '2027-01');

        DB::transaction(fn () => app(ReassociacaoDePropinas::class)->reassociar($janeiro, $novo, 1));
        $janeiro->refresh();

        $this->assertSame($novo->id, $janeiro->plano_propina_id);
        $this->assertSame(1, $janeiro->ordem);
        $this->assertSame(2_500_000, $janeiro->valor->unidadesMenores());
        $this->assertSame(1_000_000, $janeiro->valor_pago->unidadesMenores());
        $this->assertSame('2027-01-01', $janeiro->periodo_inicio->toDateString());
        $this->assertSame('2027-01-10', $janeiro->data_vencimento->toDateString());
    }

    public function test_reassociar_recusa_paga_e_plano_de_outro_ano(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $paga = $this->comEstado($this->propina($matricula, $plano, 5), EstadoCobranca::PAGA);
        $aberta = $this->propina($matricula, $plano, 6);
        $novo = $this->dividir($plano, '2027-01');
        $deOutroAno = $this->plano($this->anoLectivo('2027/2028', '2027-09-01', '2028-07-31'), 'Outro ano');

        try {
            DB::transaction(fn () => app(ReassociacaoDePropinas::class)->reassociar($paga, $novo, 1));
            $this->fail('Uma Paga nunca se reassocia.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('Em Aberto ou Parcialmente Paga', $e->getMessage());
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('mesmo ano lectivo');
        DB::transaction(fn () => app(ReassociacaoDePropinas::class)->reassociar($aberta, $deOutroAno, 2));
    }
}
```
(`Estado::INATIVO = 0` e `Periodicidade::TRIMESTRAL = 3` verificados no código.)

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/DivisaoEReassociacaoTest.php`
Expected: FAIL (classes inexistentes).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Support/DivisaoDePlanoPropina.php`:
```php
<?php

namespace Modules\Financeiro\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\PlanoPropinaAlvo;
use Modules\Financeiro\Services\PlanoPropinaConsultaService;

/**
 * Escritas no PLANO de uma alteração de preço (spec Propinas §14, F4): dividir em `mes_efeito` (o antigo termina no
 * mês anterior; o novo, com os mesmos alvos, o mesmo ano lectivo, a mesma periodicidade e o novo valor, vai até ao
 * fim original) ou mudar o valor in loco (`mes_efeito` = início do plano). Só a AlterarPrecoPlanoAction a chama,
 * dentro da sua transacção e com o plano bloqueado.
 */
final class DivisaoDePlanoPropina
{
    public function __construct(private PlanoPropinaConsultaService $consulta)
    {
    }

    public function dividir(PlanoPropina $bloqueado, CarbonImmutable $mesEfeito, Dinheiro $valorNovo, string $nomeNovo): PlanoPropina
    {
        $this->exigirTransaccao();

        $mesFimOriginal = (int) $bloqueado->mes_fim;
        $alvos = $bloqueado->alvos()->get()
            ->map(fn (PlanoPropinaAlvo $alvo) => $alvo->only(['nivel_academico_id', 'curso_id', 'turno_id', 'turma_id']))
            ->all();

        $bloqueado->update(['mes_fim' => $mesEfeito->startOfMonth()->subMonthNoOverflow()->month]);

        $novo = PlanoPropina::create([
            'ano_lectivo_id' => $bloqueado->ano_lectivo_id,
            'nome' => $nomeNovo,
            'descricao' => $bloqueado->descricao,
            'periodicidade' => $bloqueado->periodicidade,
            'intervalo_meses' => $bloqueado->intervalo_meses,
            'valor' => $valorNovo,
            'mes_inicio' => $mesEfeito->month,
            'mes_fim' => $mesFimOriginal,
            'estado' => $bloqueado->estado,
        ]);

        $novo->substituirAlvos($alvos);
        $novo->load(['alvos', 'anoLectivo']);

        // Isolamento por ano lectivo (spec §14): o plano novo nunca é de outro ano. Asserção (o create acima já o garante).
        if ((int) $novo->ano_lectivo_id !== (int) $bloqueado->ano_lectivo_id) {
            throw new LogicException('O plano criado pela divisão tem de ser do mesmo ano lectivo do plano alterado.');
        }

        // O antigo já não cobre estes meses; outro plano com os mesmos alvos neles seria um defeito anterior.
        if ($this->consulta->colisaoDeAlvos((int) $novo->ano_lectivo_id, $alvos, $novo->competencias(), (int) $novo->id)) {
            throw new LogicException('A divisão criaria um plano em colisão com outro plano do mesmo ano lectivo e dos mesmos alvos.');
        }

        return $novo;
    }

    public function alterarValor(PlanoPropina $bloqueado, Dinheiro $valorNovo): PlanoPropina
    {
        $this->exigirTransaccao();

        $bloqueado->update(['valor' => $valorNovo]);

        return $bloqueado;
    }

    private function exigirTransaccao(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Alterar o preço de um plano exige uma transacção aberta (DB::transaction) do chamador.');
        }
    }
}
```

`Modules/Financeiro/app/Support/ReassociacaoDePropinas.php`:
```php
<?php

namespace Modules\Financeiro\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;

/**
 * ÚNICO escritor de `plano_propina_id` e `ordem` de uma propina já gerada (alteração de preço com divisão, spec §14
 * "Escolha 1"): a propina passa ao plano novo com a posição do seu período nele; período, vencimento, tolerância e
 * limite mantêm-se, e não nasce propina nova (sem cobrança dupla). O valor reajusta-se a seguir no RecalcularPropina.
 */
final class ReassociacaoDePropinas
{
    public function reassociar(Propina $bloqueada, PlanoPropina $planoNovo, int $ordem): Propina
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Reassociar propinas exige uma transacção aberta (DB::transaction) do chamador.');
        }

        if (! in_array($bloqueada->estado, [EstadoCobranca::EM_ABERTO, EstadoCobranca::PARCIALMENTE_PAGA], true)) {
            throw new LogicException("Só se reassocia uma propina Em Aberto ou Parcialmente Paga (a propina {$bloqueada->id} está {$bloqueada->estado->label()}).");
        }

        if ($ordem < 1) {
            throw new InvalidArgumentException('A ordem do período no plano novo tem de ser maior ou igual a 1.');
        }

        if ((int) $planoNovo->ano_lectivo_id !== (int) $bloqueada->ano_lectivo_id) {
            throw new LogicException('O plano novo tem de ser do mesmo ano lectivo da propina.');
        }

        $bloqueada->forceFill(['plano_propina_id' => $planoNovo->id, 'ordem' => $ordem])->save();

        return $bloqueada;
    }
}
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Feature/DivisaoEReassociacaoTest.php`
Expected: PASS (7 testes).

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Support/DivisaoDePlanoPropina.php Modules/Financeiro/app/Support/ReassociacaoDePropinas.php Modules/Financeiro/tests/Feature/DivisaoEReassociacaoTest.php
```

---

### Task 4: Arquitectura — só os pontos únicos escrevem `valor`, `plano_propina_id` e `ordem` de uma propina existente

**Files:**
- Modify: `tests/Feature/Arquitectura/EscritoresDePropinaTest.php`

**Interfaces:**
- Consumes: T2 (`RecalcularPropina` escreve `'valor'` num `forceFill`), T3 (`ReassociacaoDePropinas` escreve `'plano_propina_id'` e `'ordem'` num `forceFill`).
- Produces: regra nova no mesmo teste: em ficheiros que referenciam `Propina` (mesma detecção já usada para o `estado` 2/3), chamadas de **actualização** (`fill`, `forceFill`, `update`, `updateQuietly`, `updateOrCreate`, `updateOrInsert`, `setRawAttributes`, `setAttribute`, `increment`, `decrement`, `upsert`) com as chaves `valor`, `plano_propina_id` ou `ordem`, e atribuições `$x->valor|plano_propina_id|ordem = …` (excepto `$this->…`), só são permitidas em `RecalcularPropina` (`valor`) e `ReassociacaoDePropinas` (`plano_propina_id`, `ordem`). A criação (`create`, `forceCreate`, `make`, `firstOrCreate`, `firstOrNew`) fica de fora: é do `CriadoresDePropinaTest`.

- [ ] **Step 1: Escrever os testes (falham até a regra existir)**

Em `tests/Feature/Arquitectura/EscritoresDePropinaTest.php`:

1. Acrescentar ao docblock da classe o parágrafo: "F4: `valor` de uma propina já gerada só muda no RecalcularPropina (`reajustarValor`, na mesma escrita que o estado e o valor pago); `plano_propina_id` e `ordem` só na ReassociacaoDePropinas. A criação fica com CriadoresDePropinaTest."
2. A seguir a `METODOS_DE_ESCRITA`, acrescentar:
```php
    /** F4: quem pode actualizar estes campos de uma propina JÁ GERADA (a criação é do CriadoresDePropinaTest). */
    private const REAJUSTES_PERMITIDOS = [
        'valor' => ['Modules/Financeiro/app/Services/RecalcularPropina.php'],
        'plano_propina_id' => ['Modules/Financeiro/app/Support/ReassociacaoDePropinas.php'],
        'ordem' => ['Modules/Financeiro/app/Support/ReassociacaoDePropinas.php'],
    ];

    private const METODOS_DE_ACTUALIZACAO = [
        'fill', 'forceFill', 'update', 'updateQuietly', 'updateOrCreate', 'updateOrInsert', 'setRawAttributes', 'setAttribute',
        'increment', 'decrement', 'upsert',
    ];
```
3. Extrair a detecção de "o ficheiro refere Propina" de `escritas()` para um método e usá-lo lá. Substituir em `escritas()` o bloco
```php
        $referePropina = false;

        foreach ($tokens as $token) {
            if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && ($token[1] === 'Propina' || str_ends_with($token[1], '\\Propina') || $token[1] === 'propina')) {
                $referePropina = true;
                break;
            }

            if ($this->eString($token, ['propinas'])) {
                $referePropina = true;
                break;
            }
        }
```
por
```php
        $referePropina = $this->referePropina($tokens);
```
e acrescentar:
```php
    private function referePropina(array $tokens): bool
    {
        foreach ($tokens as $token) {
            if (in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && ($token[1] === 'Propina' || str_ends_with($token[1], '\\Propina') || $token[1] === 'propina')) {
                return true;
            }

            if ($this->eString($token, ['propinas'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Actualizações de `valor`, `plano_propina_id` e `ordem` em ficheiros que referem Propina.
     *
     * @return list<array{0: string, 1: string}> [campo, excerto]
     */
    private function reajustes(string $codigo): array
    {
        $tokens = $this->tokens($codigo);

        if (! $this->referePropina($tokens)) {
            return [];
        }

        $campos = array_keys(self::REAJUSTES_PERMITIDOS);
        $total = count($tokens);
        $reajustes = [];

        for ($i = 0; $i < $total; $i++) {
            // Atribuição a uma propriedade de outro objecto: $p->valor = …, $p->ordem++ (não $this->valor = …).
            if (in_array($tokens[$i][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                && isset($tokens[$i + 2])
                && $tokens[$i + 1][0] === T_STRING
                && in_array($tokens[$i + 1][1], $campos, true)
                && in_array($tokens[$i + 2][0], self::ATRIBUICOES, true)
                && ! (($tokens[$i - 1][0] ?? null) === T_VARIABLE && $tokens[$i - 1][1] === '$this')) {
                $reajustes[] = [$tokens[$i + 1][1], $this->texto($tokens, max(0, $i - 1), min($i + 4, $total - 1))];

                continue;
            }

            if ($tokens[$i][0] !== T_STRING
                || ! in_array($tokens[$i][1], self::METODOS_DE_ACTUALIZACAO, true)
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

                if (! $this->eString($tokens[$j], $campos)) {
                    continue;
                }

                $chave = ($tokens[$j + 1][0] ?? null) === T_DOUBLE_ARROW;
                $argumento = in_array($metodo, ['setAttribute', 'increment', 'decrement'], true) && $tokens[$j - 1][0] === '(';

                if ($chave || $argumento) {
                    $reajustes[] = [trim($tokens[$j][1], '\'"'), $this->texto($tokens, $j, min($j + 3, $total - 1))];
                }
            }
        }

        return $reajustes;
    }
```
4. Acrescentar os testes:
```php
    public function test_so_os_pontos_de_reajuste_actualizam_valor_plano_e_ordem_de_uma_propina(): void
    {
        $violacoes = [];

        foreach ($this->codigoVarrido() as $relativo => $codigo) {
            foreach ($this->reajustes($codigo) as [$campo, $excerto]) {
                if (! in_array($relativo, self::REAJUSTES_PERMITIDOS[$campo], true)) {
                    $violacoes[] = "{$relativo}: {$excerto}";
                }
            }
        }

        $this->assertSame([], $violacoes, "Só RecalcularPropina actualiza o valor e só ReassociacaoDePropinas o plano e a ordem de uma propina:\n" . implode("\n", $violacoes));
    }

    public function test_os_pontos_de_reajuste_existem_e_sao_apanhados_pelo_varrimento(): void
    {
        foreach (self::REAJUSTES_PERMITIDOS as $campo => $ficheiros) {
            foreach ($ficheiros as $ficheiro) {
                $caminho = $this->raiz() . '/' . $ficheiro;

                $this->assertFileExists($caminho);
                $this->assertContains($campo, array_column($this->reajustes(file_get_contents($caminho)), 0), "{$ficheiro} devia escrever {$campo}.");
            }
        }
    }

    #[DataProvider('exemplosDeReajuste')]
    public function test_o_varrimento_de_reajustes_apanha_o_que_deve(string $codigo, bool $escrita): void
    {
        $this->assertSame($escrita, $this->reajustes('<?php ' . $codigo) !== [], $codigo);
    }

    public static function exemplosDeReajuste(): array
    {
        return [
            'forceFill de valor' => ["use Modules\\Financeiro\\Models\\Propina; \$p->forceFill(['valor' => \$v])->save();", true],
            'update de plano em query' => ["Propina::query()->whereKey(1)->update(['plano_propina_id' => 2]);", true],
            'atribuição de ordem' => ['$p = Propina::find(1); $p->ordem = 3;', true],
            'incremento de ordem' => ['Propina::class; $p->ordem++;', true],
            'setAttribute de valor' => ["Propina::class; \$p->setAttribute('valor', 1);", true],
            'via relação propina' => ["\$ajuste->propina->forceFill(['ordem' => 2]);", true],
            'criação não conta (CriadoresDePropinaTest)' => ["Propina::create(['valor' => 1, 'plano_propina_id' => 2, 'ordem' => 1]);", false],
            'valor do plano num ficheiro sem Propina' => ["PlanoPropina::query()->whereKey(1)->update(['valor' => 1]);", false],
            'propriedade do próprio objecto' => ['Propina::class; $this->valor = $v;', false],
            'leituras' => ['Propina::class; $x = $p->valor; $o = $p->ordem === 1;', false],
            'ordenação' => ["Propina::query()->orderBy('ordem')->get();", false],
            'array que não grava' => ["Propina::class; \$linha = ['valor' => \$p->valor, 'ordem' => 1];", false],
        ];
    }
```

- [ ] **Step 2: Correr e ver o resultado**

Run: `php artisan test tests/Feature/Arquitectura/EscritoresDePropinaTest.php`
Expected: PASS em todos (os escritores das Tasks 2 e 3 já existem). Se `test_so_os_pontos_de_reajuste_…` listar algum ficheiro, **não** alargar `REAJUSTES_PERMITIDOS`: mover a escrita para o ponto único certo (ou, se for um falso positivo sobre outro model num ficheiro que refere `Propina`, separar essa escrita para uma classe que não refira `Propina`, como `DivisaoDePlanoPropina`) e documentar no relatório da tarefa.

Sanidade (prova de que a regra morde): acrescentar temporariamente `$x->forceFill(['valor' => 1]);` a `Modules/Financeiro/app/Actions/CancelarPropinaAction.php`, correr o teste (FAIL a listar o ficheiro) e reverter a linha à mão (confirmar com `git diff Modules/Financeiro/app/Actions/CancelarPropinaAction.php` → vazio).

- [ ] **Step 3: Stage**

```bash
git add tests/Feature/Arquitectura/EscritoresDePropinaTest.php
```

---

### Task 5: Pré-visualização — `AlteracaoPrecoPlanoService::preVisualizar` (D1–D6, D8, D9)

**Files:**
- Create: `Modules/Financeiro/app/DTO/AlterarPrecoPlanoDTO.php`
- Create: `Modules/Financeiro/app/DTO/PreVisualizacaoAlteracaoPrecoDTO.php`
- Create: `Modules/Financeiro/app/Services/AlteracaoPrecoPlanoService.php`
- Create: `Modules/Financeiro/tests/Concerns/ComAlteracaoPreco.php`
- Test: `Modules/Financeiro/tests/Feature/AlteracaoPrecoPreVisualizacaoTest.php`

**Interfaces:**
- Consumes: `PlanoPropina::periodos()/competencias()/propinas()/alvos`, `CalendarioDePlano::competencias/periodos`, `RotuloDePeriodo`, `AlvosDoPlano::chave`, `TenantContext::id()`, `MoedaDoTenant::atual()`, `Dinheiro::deDecimal`.
- Produces:
  - `final class AlterarPrecoPlanoDTO { readonly Dinheiro $valorNovo; readonly CarbonImmutable $mesEfeito (1.º dia do mês); readonly ModoAlteracaoPreco $modo; readonly ?string $motivo; readonly ?string $impressaoDigital; readonly bool $confirmaValoresDiferentes; static fromRequest(FormRequest): self }`.
  - `final class PreVisualizacaoAlteracaoPrecoDTO` com as propriedades públicas só de leitura `modo`, `mesEfeito` (`Y-m-d`), `inLoco`, `anoLectivo` (`{id, nome}`), `propinasDeOutrosAnos` (sempre 0 quando devolvida), `valorAnterior`, `valorNovo`, `nomePlanoNovo` (`?string`), `planos`, `afectadas`, `inalteradas`, `totais`, `avisoValoresDiferentes` (`?array`, preenchido na Task 6), `impressaoDigital`; métodos `exigeConfirmacaoValoresDiferentes(): bool` e `toArray(): array` (contrato JSON da Task 12).
  - Cada item de `afectadas`: `propina_id`, `matricula_numero`, `aluno_nome`, `periodo`, `periodo_inicio`, `estado`, `estado_descricao`, `ordem_antes`, `ordem_depois`, `valor_antes`, `valor_depois`, `valor_pago`, `saldo_antes`, `saldo_depois`, `excesso`, `passa_a_paga`. Cada item de `inalteradas`: os mesmos campos-base + `motivo`. `totais`: `afectadas`, `inalteradas`, `anteriores_ao_mes_efeito`, `valor_antes`, `valor_depois`, `recebido`, `saldo_antes`, `saldo_depois`, `excesso`. Cada plano: `papel` (`mesmo`|`antigo`|`novo`), `nome`, `valor`, `mes_inicio`, `mes_fim`, `periodos` (`list<{ordem, periodo, meses}>`).
  - `AlteracaoPrecoPlanoService::preVisualizar(PlanoPropina $plano, AlterarPrecoPlanoDTO $dto): PreVisualizacaoAlteracaoPrecoDTO` — **nunca escreve**; lança `ValidationException` (`valor`, `mes_efeito` ou `plano`) para as recusas. `nomeDoPlanoNovo(PlanoPropina, CarbonImmutable): string`. `impressaoDigital(PlanoPropina, AlterarPrecoPlanoDTO, Collection<Propina> $candidatas): string` (pública para o teste de isolamento 7). Constantes `MENSAGEM_SEM_PROPINAS`, `MENSAGEM_VALOR_IGUAL`, `MENSAGEM_ANCORA`, `MENSAGEM_OUTRO_ANO`, `NOME_MAXIMO = 100`, `VERSAO_IMPRESSAO = 1`.
  - Teste: trait `ComAlteracaoPreco` com `pedidoAlteracao(...)`, `previa(...)`, `assertRecusa(campo, texto, Closure)` e `fotografia()`.

- [ ] **Step 1: Escrever o teste que falha**

`Modules/Financeiro/tests/Concerns/ComAlteracaoPreco.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Concerns;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\DTO\AlterarPrecoPlanoDTO;
use Modules\Financeiro\DTO\PreVisualizacaoAlteracaoPrecoDTO;
use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Financeiro\Models\AlteracaoPrecoPlano;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\PlanoPropinaAlvo;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\PropinaAjuste;
use Modules\Financeiro\Services\AlteracaoPrecoPlanoService;
use Modules\Financeiro\Support\Dinheiro;

/**
 * Pedidos de alteração de preço (F4) para testes. Requer ComDadosAcademicosFinanceiro e ComPropinasFinanceiro.
 */
trait ComAlteracaoPreco
{
    protected function pedidoAlteracao(
        string $mesEfeito,
        int $valorNovo,
        ModoAlteracaoPreco $modo,
        ?string $motivo = null,
        ?string $impressaoDigital = null,
        bool $confirmaValoresDiferentes = false,
    ): AlterarPrecoPlanoDTO {
        return new AlterarPrecoPlanoDTO(
            valorNovo: Dinheiro::deUnidadesMenores($valorNovo),
            mesEfeito: CarbonImmutable::createFromFormat('!Y-m', $mesEfeito),
            modo: $modo,
            motivo: $motivo,
            impressaoDigital: $impressaoDigital,
            confirmaValoresDiferentes: $confirmaValoresDiferentes,
        );
    }

    protected function previa(PlanoPropina $plano, string $mesEfeito, int $valorNovo, ModoAlteracaoPreco $modo): PreVisualizacaoAlteracaoPrecoDTO
    {
        return app(AlteracaoPrecoPlanoService::class)->preVisualizar($plano->fresh(), $this->pedidoAlteracao($mesEfeito, $valorNovo, $modo));
    }

    protected function assertRecusa(string $campo, string $texto, Closure $operacao): void
    {
        try {
            $operacao();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($campo, $e->errors(), 'Recusado noutro campo: ' . json_encode($e->errors(), JSON_UNESCAPED_UNICODE));
            $this->assertStringContainsString($texto, $e->errors()[$campo][0]);

            return;
        }

        $this->fail("Devia recusar em «{$campo}».");
    }

    /**
     * Tudo o que F4 pode tocar, em cru, para provar "nada mudou".
     *
     * @return array<string, mixed>
     */
    protected function fotografia(): array
    {
        return [
            'planos' => PlanoPropina::query()->orderBy('id')->get()->map(fn (PlanoPropina $p) => $p->getAttributes())->all(),
            'alvos' => PlanoPropinaAlvo::query()->orderBy('id')->get()->map(fn (PlanoPropinaAlvo $a) => $a->getAttributes())->all(),
            'propinas' => Propina::query()->orderBy('id')->get()->map(fn (Propina $p) => $p->getAttributes())->all(),
            'alteracoes' => AlteracaoPrecoPlano::query()->count(),
            'ajustes' => PropinaAjuste::query()->count(),
        ];
    }
}
```

`Modules/Financeiro/tests/Feature/AlteracaoPrecoPreVisualizacaoTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Enums\Estado;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Financeiro\Enums\Periodicidade;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\AlteracaoPrecoPlanoService;
use Modules\Financeiro\Tests\Concerns\ComAlteracaoPreco;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Tests\TestCase;

class AlteracaoPrecoPreVisualizacaoTest extends TestCase
{
    use ComAlteracaoPreco;
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    private const APLICAR = ModoAlteracaoPreco::APLICAR_GERADAS;
    private const MANTER = ModoAlteracaoPreco::MANTER_GERADAS;

    /** @return array{0: PlanoPropina, 1: array<string, Propina>} plano "Propina Mensal" (Set→Jun, 25.000,00) e as 10 propinas de cenarioDeEstados */
    private function estados(): array
    {
        $estados = $this->cenarioDeEstados();

        return [$estados['set_aberta']->plano()->firstOrFail(), $estados];
    }

    public function test_plano_sem_propinas_recusa_e_indica_editar(): void
    {
        ['plano' => $plano] = $this->cenarioPropinas();

        $this->assertRecusa('valor', 'altere o valor em «Editar»', fn () => $this->previa($plano, '2027-01', 3_000_000, self::APLICAR));
    }

    public function test_valor_igual_ao_actual_recusa(): void
    {
        [$plano] = $this->estados();

        $this->assertRecusa('valor', 'diferente do valor actual', fn () => $this->previa($plano, '2027-01', 2_500_000, self::APLICAR));
    }

    public function test_mes_de_efeito_tem_de_ser_inicio_de_um_periodo_do_plano_no_seu_ano(): void
    {
        ['ano' => $ano, 'turma' => $turma] = $this->cenarioPropinas();
        $trimestral = $this->plano($ano, 'Trimestral', ['periodicidade' => Periodicidade::TRIMESTRAL, 'intervalo_meses' => 3]);
        $this->propina($this->matriculaActiva($turma), $trimestral, 1);

        foreach (['2027-01', '2025-12', '2027-07', '2027-09'] as $mes) {
            $this->assertRecusa('mes_efeito', 'tem de ser o início de um período deste plano', fn () => $this->previa($trimestral, $mes, 3_000_000, self::APLICAR));
        }

        $this->assertFalse($this->previa($trimestral, '2026-12', 3_000_000, self::APLICAR)->inLoco);
    }

    public function test_aplicar_com_divisao_lista_afectadas_inalteradas_planos_e_totais(): void
    {
        [$plano, $e] = $this->estados();

        $previa = $this->previa($plano, '2027-02', 3_000_000, self::APLICAR);

        $this->assertFalse($previa->inLoco);
        $this->assertSame('Propina Mensal (desde 02/2027)', $previa->nomePlanoNovo);
        $this->assertSame([$e['fev_aberta']->id, $e['mar_parcial']->id, $e['mai_aberta']->id, $e['jun_aberta']->id], array_column($previa->afectadas, 'propina_id'));
        $this->assertSame([6, 7, 9, 10], array_column($previa->afectadas, 'ordem_antes'));
        $this->assertSame([1, 2, 4, 5], array_column($previa->afectadas, 'ordem_depois'));
        $this->assertSame([3_000_000, 3_000_000, 3_000_000, 3_000_000], array_column($previa->afectadas, 'valor_depois'));
        $this->assertSame([3_000_000, 1_000_000, 3_000_000, 3_000_000], array_column($previa->afectadas, 'saldo_depois'));
        $this->assertSame([$e['abr_paga']->id], array_column($previa->inalteradas, 'propina_id'));
        $this->assertStringContainsString('Paga', $previa->inalteradas[0]['motivo']);
        $this->assertSame([
            'afectadas' => 4, 'inalteradas' => 1, 'anteriores_ao_mes_efeito' => 5,
            'valor_antes' => 10_000_000, 'valor_depois' => 12_000_000, 'recebido' => 2_000_000,
            'saldo_antes' => 8_000_000, 'saldo_depois' => 10_000_000, 'excesso' => 0,
        ], $previa->totais);
        $this->assertSame(['antigo', 'novo'], array_column($previa->planos, 'papel'));
        $this->assertSame([9, 1], [$previa->planos[0]['mes_inicio'], $previa->planos[0]['mes_fim']]);
        $this->assertCount(5, $previa->planos[0]['periodos']);
        $this->assertSame([2, 6, 3_000_000], [$previa->planos[1]['mes_inicio'], $previa->planos[1]['mes_fim'], $previa->planos[1]['valor']]);
        $this->assertSame('Fev/2027', $previa->planos[1]['periodos'][0]['periodo']);
        $this->assertSame(['id' => $plano->ano_lectivo_id, 'nome' => '2026/2027'], $previa->anoLectivo);
        $this->assertSame(0, $previa->propinasDeOutrosAnos);
        $this->assertNull($previa->avisoValoresDiferentes);
        $this->assertFalse($previa->exigeConfirmacaoValoresDiferentes());
        $this->assertSame(64, strlen($previa->impressaoDigital));
    }

    public function test_descida_abaixo_do_pago_e_assinalada_e_fica_no_pago(): void
    {
        [$plano, $e] = $this->estados();

        $previa = $this->previa($plano, '2027-02', 1_500_000, self::APLICAR);
        $marco = collect($previa->afectadas)->firstWhere('propina_id', $e['mar_parcial']->id);

        $this->assertSame(2_000_000, $marco['valor_depois']);
        $this->assertSame(0, $marco['saldo_depois']);
        $this->assertSame(500_000, $marco['excesso']);
        $this->assertTrue($marco['passa_a_paga']);
        $this->assertSame(500_000, $previa->totais['excesso']);
        $this->assertFalse(collect($previa->afectadas)->firstWhere('propina_id', $e['fev_aberta']->id)['passa_a_paga']);
    }

    public function test_in_loco_quando_o_mes_de_efeito_e_o_inicio_do_plano(): void
    {
        [$plano, $e] = $this->estados();

        $previa = $this->previa($plano, '2026-09', 3_000_000, self::APLICAR);

        $this->assertTrue($previa->inLoco);
        $this->assertNull($previa->nomePlanoNovo);
        $this->assertSame(['mesmo'], array_column($previa->planos, 'papel'));
        $this->assertSame(
            [$e['set_aberta']->id, $e['out_parcial']->id, $e['fev_aberta']->id, $e['mar_parcial']->id, $e['mai_aberta']->id, $e['jun_aberta']->id],
            array_column($previa->afectadas, 'propina_id'),
        );
        $this->assertSame(array_column($previa->afectadas, 'ordem_antes'), array_column($previa->afectadas, 'ordem_depois'));
        $this->assertSame(0, $previa->totais['anteriores_ao_mes_efeito']);
    }

    public function test_manter_nao_afecta_nenhuma_propina_e_explica_porque(): void
    {
        [$plano, $e] = $this->estados();

        $previa = $this->previa($plano, '2027-02', 3_000_000, self::MANTER);

        $this->assertSame([], $previa->afectadas);
        $this->assertSame(0, $previa->totais['afectadas']);
        $this->assertCount(5, $previa->inalteradas);
        $motivos = array_column($previa->inalteradas, 'motivo', 'propina_id');
        $this->assertStringContainsString('Paga', $motivos[$e['abr_paga']->id]);
        $this->assertStringContainsString('Manter as já geradas', $motivos[$e['fev_aberta']->id]);
        $this->assertSame(['antigo', 'novo'], array_column($previa->planos, 'papel'));
    }

    public function test_a_pre_visualizacao_nunca_escreve(): void
    {
        [$plano] = $this->estados();
        $antes = $this->fotografia();

        $this->previa($plano, '2027-02', 3_000_000, self::APLICAR);
        $this->previa($plano, '2027-02', 1_500_000, self::MANTER);
        $this->previa($plano, '2026-09', 3_000_000, self::APLICAR);

        $this->assertSame($antes, $this->fotografia());
    }

    public function test_ancora_do_calendario_l6_recusa_um_plano_novo_que_cairia_noutro_ano_civil(): void
    {
        ['ano' => $ano, 'turma' => $turma] = $this->cenarioPropinas();
        // Out → Set num ano que começa em Setembro: um plano novo "Set → Set" seria ancorado em Set/2026, não Set/2027.
        $plano = $this->plano($ano, 'Out a Set', ['mes_inicio' => 10, 'mes_fim' => 9]);
        $this->propina($this->matriculaActiva($turma), $plano, 1);

        $this->assertRecusa('mes_efeito', 'calendário do plano novo', fn () => $this->previa($plano, '2027-09', 3_000_000, self::MANTER));
        $this->assertFalse($this->previa($plano, '2027-08', 3_000_000, self::MANTER)->inLoco);
    }

    public function test_candidata_sem_periodo_no_plano_novo_recusa(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->propina($matricula, $plano, 6);
        $junho = $this->propina($matricula, $plano, 10);
        // Como depois de um MANTER anterior a partir de Mai: Junho ficou neste plano, fora do seu calendário.
        $plano->update(['mes_fim' => 4]);

        $this->assertRecusa('mes_efeito', 'não corresponde a nenhum período do plano novo', fn () => $this->previa($plano, '2027-02', 3_000_000, self::APLICAR));

        $inLoco = $this->previa($plano, '2026-09', 3_000_000, self::APLICAR);
        $this->assertSame(10, collect($inLoco->afectadas)->firstWhere('propina_id', $junho->id)['ordem_depois']);
    }

    public function test_nome_do_plano_novo_e_unico_no_ano_e_cabe_em_100_caracteres(): void
    {
        ['ano' => $ano, 'plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->propina($matricula, $plano, 6);
        $this->plano($ano, 'Propina Mensal (desde 02/2027)', ['estado' => Estado::INATIVO->value, 'mes_inicio' => 7, 'mes_fim' => 8]);
        $servico = app(AlteracaoPrecoPlanoService::class);

        $this->assertSame('Propina Mensal (desde 02/2027) 2', $this->previa($plano, '2027-02', 3_000_000, self::APLICAR)->nomePlanoNovo);

        $plano->update(['nome' => str_repeat('A', 100)]);
        $longo = $servico->nomeDoPlanoNovo($plano->fresh(), $this->pedidoAlteracao('2027-02', 1, self::APLICAR)->mesEfeito);
        $this->assertSame(100, mb_strlen($longo));
        $this->assertStringEndsWith(' (desde 02/2027)', $longo);

        $plano->update(['nome' => 'Mensal (desde 01/2027)']);
        $this->assertSame('Mensal (desde 03/2027)', $servico->nomeDoPlanoNovo($plano->fresh(), $this->pedidoAlteracao('2027-03', 1, self::APLICAR)->mesEfeito));
    }

    public function test_impressao_digital_estavel_e_sensivel_a_parametros_e_a_pagamentos(): void
    {
        [$plano, $e] = $this->estados();
        $base = $this->previa($plano, '2027-02', 3_000_000, self::APLICAR)->impressaoDigital;

        $this->assertSame($base, $this->previa($plano, '2027-02', 3_000_000, self::APLICAR)->impressaoDigital);
        $this->assertNotSame($base, $this->previa($plano, '2027-02', 3_100_000, self::APLICAR)->impressaoDigital);
        $this->assertNotSame($base, $this->previa($plano, '2027-02', 3_000_000, self::MANTER)->impressaoDigital);
        $this->assertNotSame($base, $this->previa($plano, '2027-03', 3_000_000, self::APLICAR)->impressaoDigital);

        // Um pagamento entre a pré-visualização e a confirmação.
        $this->comEstado($e['fev_aberta'], EstadoCobranca::PARCIALMENTE_PAGA, 100_000);
        $this->assertNotSame($base, $this->previa($plano, '2027-02', 3_000_000, self::APLICAR)->impressaoDigital);
    }

    public function test_to_array_tem_o_contrato_json(): void
    {
        [$plano] = $this->estados();

        $json = $this->previa($plano, '2027-02', 3_000_000, self::APLICAR)->toArray();

        $this->assertSame([
            'modo', 'modo_descricao', 'mes_efeito', 'mes_efeito_rotulo', 'in_loco', 'ano_lectivo', 'garantia_outros_anos',
            'propinas_de_outros_anos', 'valor_anterior', 'valor_novo', 'nome_plano_novo', 'planos', 'afectadas', 'inalteradas',
            'totais', 'aviso_valores_diferentes', 'exige_confirmacao_valores_diferentes', 'impressao_digital',
        ], array_keys($json));
        $this->assertSame('2027-02', $json['mes_efeito']);
        $this->assertSame('Fev/2027', $json['mes_efeito_rotulo']);
        $this->assertSame('Nenhuma propina de outros anos lectivos será alterada.', $json['garantia_outros_anos']);
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/AlteracaoPrecoPreVisualizacaoTest.php`
Expected: FAIL (classes inexistentes).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/DTO/AlterarPrecoPlanoDTO.php`:
```php
<?php

namespace Modules\Financeiro\DTO;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Support\Dinheiro;

/**
 * Pedido de alteração de preço de um plano (spec §14): novo valor, mês de efeito (1.º dia) e a escolha explícita.
 * Motivo, impressão digital e a confirmação de valores diferentes só existem na confirmação.
 */
final class AlterarPrecoPlanoDTO
{
    public function __construct(
        public readonly Dinheiro $valorNovo,
        public readonly CarbonImmutable $mesEfeito,
        public readonly ModoAlteracaoPreco $modo,
        public readonly ?string $motivo = null,
        public readonly ?string $impressaoDigital = null,
        public readonly bool $confirmaValoresDiferentes = false,
    ) {
    }

    public static function fromRequest(FormRequest $request): self
    {
        $dados = $request->validated();

        return new self(
            valorNovo: Dinheiro::deDecimal((string) $dados['valor'], app(MoedaDoTenant::class)->atual()),
            mesEfeito: CarbonImmutable::createFromFormat('!Y-m', (string) $dados['mes_efeito']),
            modo: ModoAlteracaoPreco::from((int) $dados['modo']),
            motivo: isset($dados['motivo']) ? trim((string) $dados['motivo']) : null,
            impressaoDigital: $dados['impressao_digital'] ?? null,
            confirmaValoresDiferentes: (bool) ($dados['confirmar_valores_diferentes'] ?? false),
        );
    }
}
```

`Modules/Financeiro/app/DTO/PreVisualizacaoAlteracaoPrecoDTO.php`:
```php
<?php

namespace Modules\Financeiro\DTO;

use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Financeiro\Support\RotuloDePeriodo;

/**
 * O que a alteração de preço faria (spec §14 "Pré-visualização obrigatória"). A mesma estrutura serve a confirmação:
 * a Action recalcula-a sobre o estado bloqueado e compara a impressão digital.
 */
final class PreVisualizacaoAlteracaoPrecoDTO
{
    public const GARANTIA_OUTROS_ANOS = 'Nenhuma propina de outros anos lectivos será alterada.';

    /**
     * @param  array{id: int, nome: ?string}  $anoLectivo
     * @param  list<array<string, mixed>>  $planos
     * @param  list<array<string, mixed>>  $afectadas
     * @param  list<array<string, mixed>>  $inalteradas
     * @param  array<string, int>  $totais
     * @param  array<string, mixed>|null  $avisoValoresDiferentes
     */
    public function __construct(
        public readonly ModoAlteracaoPreco $modo,
        public readonly string $mesEfeito,
        public readonly bool $inLoco,
        public readonly array $anoLectivo,
        public readonly int $propinasDeOutrosAnos,
        public readonly int $valorAnterior,
        public readonly int $valorNovo,
        public readonly ?string $nomePlanoNovo,
        public readonly array $planos,
        public readonly array $afectadas,
        public readonly array $inalteradas,
        public readonly array $totais,
        public readonly ?array $avisoValoresDiferentes,
        public readonly string $impressaoDigital,
    ) {
    }

    /** M2 (fases F4): com MANTER e alunos já gerados ao valor antigo, a confirmação exige reconhecer os dois valores. */
    public function exigeConfirmacaoValoresDiferentes(): bool
    {
        return $this->modo === ModoAlteracaoPreco::MANTER_GERADAS
            && (int) ($this->avisoValoresDiferentes['matriculas_valor_antigo'] ?? 0) > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'modo' => $this->modo->value,
            'modo_descricao' => $this->modo->label(),
            'mes_efeito' => substr($this->mesEfeito, 0, 7),
            'mes_efeito_rotulo' => RotuloDePeriodo::competencia((int) substr($this->mesEfeito, 0, 4), (int) substr($this->mesEfeito, 5, 2)),
            'in_loco' => $this->inLoco,
            'ano_lectivo' => $this->anoLectivo,
            'garantia_outros_anos' => self::GARANTIA_OUTROS_ANOS,
            'propinas_de_outros_anos' => $this->propinasDeOutrosAnos,
            'valor_anterior' => $this->valorAnterior,
            'valor_novo' => $this->valorNovo,
            'nome_plano_novo' => $this->nomePlanoNovo,
            'planos' => $this->planos,
            'afectadas' => $this->afectadas,
            'inalteradas' => $this->inalteradas,
            'totais' => $this->totais,
            'aviso_valores_diferentes' => $this->avisoValoresDiferentes,
            'exige_confirmacao_valores_diferentes' => $this->exigeConfirmacaoValoresDiferentes(),
            'impressao_digital' => $this->impressaoDigital,
        ];
    }
}
```

`Modules/Financeiro/app/Services/AlteracaoPrecoPlanoService.php`:
```php
<?php

namespace Modules\Financeiro\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Core\Tenancy\TenantContext;
use Modules\Financeiro\DTO\AlterarPrecoPlanoDTO;
use Modules\Financeiro\DTO\PreVisualizacaoAlteracaoPrecoDTO;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\PlanoPropinaAlvo;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Support\AlvosDoPlano;
use Modules\Financeiro\Support\CalendarioDePlano;
use Modules\Financeiro\Support\RotuloDePeriodo;

/**
 * Pré-visualização da alteração de preço de um plano com propinas (spec Propinas §14). SÓ LÊ. É o mesmo cálculo
 * que a AlterarPrecoPlanoAction refaz sobre o estado bloqueado (a impressão digital tem de coincidir).
 *
 * - O conjunto parte SEMPRE de `propinas.plano_propina_id = plano` e `periodo_inicio >= mes_efeito` (isolamento por
 *   ano lectivo: nunca por turma, alvos ou aluno). Propinas do plano de outro ano → recusa total (D9).
 * - `mes_efeito` = início de um período do plano (no calendário do seu ano lectivo); o 1.º período = in loco (D4).
 * - Com divisão, o plano novo tem de continuar exactamente os meses do antigo (âncora L6) e cada propina a
 *   reassociar tem de ter período no plano novo.
 * - APLICAR altera Em Aberto/Parcialmente Paga (`valor = max(novo, pago)`); Pagas, Canceladas e Anuladas ficam.
 */
class AlteracaoPrecoPlanoService
{
    public const VERSAO_IMPRESSAO = 1;

    public const NOME_MAXIMO = 100;

    public const MENSAGEM_SEM_PROPINAS = 'Este plano ainda não tem propinas geradas: altere o valor em «Editar».';

    public const MENSAGEM_VALOR_IGUAL = 'O novo valor tem de ser diferente do valor actual do plano.';

    public const MENSAGEM_ANCORA = 'Com este mês de efeito, o calendário do plano novo não coincidiria com os meses que restam do plano actual (o mês de início repete-se noutro ano civil). Escolha outro mês de efeito ou altere o preço a partir do início do plano.';

    public const MENSAGEM_OUTRO_ANO = 'Foram encontradas propinas de outro ano lectivo associadas a este plano. A operação foi cancelada; contacte o suporte.';

    public function __construct(private TenantContext $tenant)
    {
    }

    public function preVisualizar(PlanoPropina $plano, AlterarPrecoPlanoDTO $dto): PreVisualizacaoAlteracaoPrecoDTO
    {
        $plano->loadMissing(['anoLectivo', 'alvos']);
        $mesEfeito = $dto->mesEfeito->startOfMonth();
        $dia = $mesEfeito->toDateString();
        $novo = $dto->valorNovo->unidadesMenores();

        if (! $plano->propinas()->exists()) {
            throw ValidationException::withMessages(['valor' => self::MENSAGEM_SEM_PROPINAS]);
        }

        if ($plano->valor->unidadesMenores() === $novo) {
            throw ValidationException::withMessages(['valor' => self::MENSAGEM_VALOR_IGUAL]);
        }

        $this->recusarPropinasDeOutrosAnos($plano);

        $periodos = $plano->periodos();
        $indice = array_search($dia, array_column($periodos, 'inicio'), true);

        if ($indice === false) {
            throw ValidationException::withMessages(['mes_efeito' => 'O mês de efeito tem de ser o início de um período deste plano, no seu ano lectivo ('
                . implode(', ', array_map(fn (array $p) => $this->rotuloDoMes($p['inicio']), $periodos)) . ').']);
        }

        $inLoco = $indice === 0;
        $novosPeriodos = $inLoco ? [] : $this->periodosDoPlanoNovo($plano, $mesEfeito);
        $ordemNoNovo = array_column($novosPeriodos, 'ordem', 'inicio');

        $candidatas = Propina::query()
            ->where('plano_propina_id', $plano->id)
            ->where('periodo_inicio', '>=', $dia)
            ->with(['matricula.aluno.dadosPessoa'])
            ->orderBy('periodo_inicio')
            ->orderBy('id')
            ->get();

        $afectadas = [];
        $inalteradas = [];

        foreach ($candidatas as $propina) {
            $linha = $this->linha($propina);
            $pago = $linha['valor_pago'];
            $depois = max($novo, $pago);
            $motivo = $this->motivoParaFicarInalterada($propina, $dto->modo)
                ?? ($depois === $linha['valor_antes'] ? 'Já tem este valor.' : null);

            if ($motivo !== null) {
                $inalteradas[] = $linha + ['motivo' => $motivo];

                continue;
            }

            $ordemDepois = $inLoco ? $linha['ordem_antes'] : ($ordemNoNovo[$linha['periodo_inicio']] ?? null);

            if ($ordemDepois === null) {
                throw ValidationException::withMessages(['mes_efeito' => "A propina de {$linha['periodo']} (matrícula {$linha['matricula_numero']}) não corresponde a nenhum período do plano novo: escolha outro mês de efeito ou o início do plano."]);
            }

            $afectadas[] = $linha + [
                'ordem_depois' => (int) $ordemDepois,
                'valor_depois' => $depois,
                'saldo_depois' => $depois - $pago,
                'excesso' => max(0, $pago - $novo),
                'passa_a_paga' => $pago > 0 && $depois === $pago,
            ];
        }

        $nomeNovo = $inLoco ? null : $this->nomeDoPlanoNovo($plano, $mesEfeito);

        return new PreVisualizacaoAlteracaoPrecoDTO(
            modo: $dto->modo,
            mesEfeito: $dia,
            inLoco: $inLoco,
            anoLectivo: ['id' => (int) $plano->ano_lectivo_id, 'nome' => $plano->anoLectivo?->nome],
            propinasDeOutrosAnos: 0,
            valorAnterior: $plano->valor->unidadesMenores(),
            valorNovo: $novo,
            nomePlanoNovo: $nomeNovo,
            planos: $inLoco
                ? [$this->descricaoDoPlano('mesmo', $plano->nome, $novo, (int) $plano->mes_inicio, (int) $plano->mes_fim, $periodos)]
                : [
                    $this->descricaoDoPlano('antigo', $plano->nome, $plano->valor->unidadesMenores(), (int) $plano->mes_inicio, $mesEfeito->subMonthNoOverflow()->month, array_slice($periodos, 0, $indice)),
                    $this->descricaoDoPlano('novo', (string) $nomeNovo, $novo, $mesEfeito->month, (int) $plano->mes_fim, $novosPeriodos),
                ],
            afectadas: $afectadas,
            inalteradas: $inalteradas,
            totais: $this->totais($plano, $dia, $afectadas, $inalteradas),
            avisoValoresDiferentes: null,
            impressaoDigital: $this->impressaoDigital($plano, $dto, $candidatas),
        );
    }

    /**
     * "{nome} – Fase N", sem repetir um sufixo de uma divisão anterior, cortado para caber em 100 caracteres e
     * com " 2", " 3"… se o nome já existir no ano lectivo do plano.
     */
    public function nomeDoPlanoNovo(PlanoPropina $plano, CarbonImmutable $mesEfeito): string
    {
        $base = (string) preg_replace('/ \(desde \d{2}\/\d{4}\)( \d+)?$/u', '', $plano->nome);
        $sufixo = ' (desde ' . $mesEfeito->format('m/Y') . ')';
        $n = 1;

        while (true) {
            $final = $sufixo . ($n === 1 ? '' : " {$n}");
            $nome = mb_substr($base, 0, self::NOME_MAXIMO - mb_strlen($final)) . $final;

            if (! PlanoPropina::query()->where('ano_lectivo_id', $plano->ano_lectivo_id)->where('nome', $nome)->exists()) {
                return $nome;
            }

            $n++;
        }
    }

    /** Defesa em profundidade (spec §14, isolamento): uma propina do plano de outro ano aborta a operação inteira. */
    private function recusarPropinasDeOutrosAnos(PlanoPropina $plano): void
    {
        $anoId = (int) $plano->ano_lectivo_id;
        $ids = Propina::query()
            ->where('plano_propina_id', $plano->id)
            ->where(fn ($q) => $q->where('ano_lectivo_id', '!=', $anoId)
                ->orWhereHas('matricula', fn ($m) => $m->where('ano_lectivo_id', '!=', $anoId)))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($ids === []) {
            return;
        }

        Log::error('financeiro.alterar-preco.propinas-de-outro-ano', [
            'tenant_id' => $this->tenant->id(),
            'plano_propina_id' => (int) $plano->id,
            'ano_lectivo_id' => $anoId,
            'propina_ids' => $ids,
        ]);

        throw ValidationException::withMessages(['plano' => self::MENSAGEM_OUTRO_ANO]);
    }

    /**
     * L6: as competências do plano novo (ancoradas no início do ano lectivo) têm de ser exactamente as que restam
     * do antigo a partir de `mes_efeito`.
     *
     * @return list<array{ordem: int, meses: int, inicio: string, fim: string}>
     */
    private function periodosDoPlanoNovo(PlanoPropina $plano, CarbonImmutable $mesEfeito): array
    {
        $inicioDoAno = $plano->anoLectivo->data_inicio;
        $antigas = $plano->competencias();
        $posicao = array_search(['ano' => $mesEfeito->year, 'mes' => $mesEfeito->month], $antigas, true);
        $novas = CalendarioDePlano::competencias($mesEfeito->month, (int) $plano->mes_fim, $inicioDoAno);

        if ($posicao === false || $novas !== array_slice($antigas, $posicao)) {
            throw ValidationException::withMessages(['mes_efeito' => self::MENSAGEM_ANCORA]);
        }

        return CalendarioDePlano::periodos($mesEfeito->month, (int) $plano->mes_fim, (int) $plano->intervalo_meses, $inicioDoAno);
    }

    private function motivoParaFicarInalterada(Propina $propina, ModoAlteracaoPreco $modo): ?string
    {
        return match (true) {
            $propina->estado === EstadoCobranca::PAGA => 'Paga: nunca é alterada.',
            $propina->estado === EstadoCobranca::CANCELADA => 'Cancelada: nunca é alterada.',
            $propina->estado === EstadoCobranca::ANULADA => 'Anulada: nunca é alterada.',
            $modo === ModoAlteracaoPreco::MANTER_GERADAS => 'Mantida no valor anterior (escolha «Manter as já geradas»).',
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function linha(Propina $propina): array
    {
        $valor = $propina->valor->unidadesMenores();
        $pago = $propina->valor_pago->unidadesMenores();
        $inicio = $propina->periodo_inicio->toDateString();

        return [
            'propina_id' => (int) $propina->id,
            'matricula_numero' => $propina->matricula?->numero_registo_matricula,
            'aluno_nome' => $propina->matricula?->aluno?->dadosPessoa?->nome_completo,
            'periodo' => RotuloDePeriodo::periodo($inicio, $propina->periodo_fim->toDateString()),
            'periodo_inicio' => $inicio,
            'estado' => $propina->estado->value,
            'estado_descricao' => $propina->estado->label(),
            'ordem_antes' => (int) $propina->ordem,
            'valor_antes' => $valor,
            'valor_pago' => $pago,
            'saldo_antes' => $valor - $pago,
        ];
    }

    /**
     * @param  list<array{ordem: int, meses: int, inicio: string, fim: string}>  $periodos
     * @return array<string, mixed>
     */
    private function descricaoDoPlano(string $papel, string $nome, int $valor, int $mesInicio, int $mesFim, array $periodos): array
    {
        return [
            'papel' => $papel,
            'nome' => $nome,
            'valor' => $valor,
            'mes_inicio' => $mesInicio,
            'mes_fim' => $mesFim,
            'periodos' => array_map(fn (array $p) => [
                'ordem' => $p['ordem'],
                'periodo' => RotuloDePeriodo::periodo($p['inicio'], $p['fim']),
                'meses' => $p['meses'],
            ], array_values($periodos)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $afectadas
     * @param  list<array<string, mixed>>  $inalteradas
     * @return array<string, int>
     */
    private function totais(PlanoPropina $plano, string $dia, array $afectadas, array $inalteradas): array
    {
        return [
            'afectadas' => count($afectadas),
            'inalteradas' => count($inalteradas),
            'anteriores_ao_mes_efeito' => Propina::query()->where('plano_propina_id', $plano->id)->where('periodo_inicio', '<', $dia)->count(),
            'valor_antes' => array_sum(array_column($afectadas, 'valor_antes')),
            'valor_depois' => array_sum(array_column($afectadas, 'valor_depois')),
            'recebido' => array_sum(array_column($afectadas, 'valor_pago')),
            'saldo_antes' => array_sum(array_column($afectadas, 'saldo_antes')),
            'saldo_depois' => array_sum(array_column($afectadas, 'saldo_depois')),
            'excesso' => array_sum(array_column($afectadas, 'excesso')),
        ];
    }

    /**
     * D8 (spec §14 "Confirmação idempotente"; fases L16): parâmetros, estado do plano (incluindo o ano lectivo e os
     * alvos) e cada candidata (ids, valores, valor pago e estado). Muda se alguém pagar, anular, gerar ou dividir.
     *
     * @param  Collection<int, Propina>  $candidatas
     */
    public function impressaoDigital(PlanoPropina $plano, AlterarPrecoPlanoDTO $dto, Collection $candidatas): string
    {
        $alvos = $plano->alvos
            ->map(fn (PlanoPropinaAlvo $alvo) => AlvosDoPlano::chave($alvo->only(['nivel_academico_id', 'curso_id', 'turno_id', 'turma_id'])))
            ->sort()
            ->values()
            ->all();

        return hash('sha256', json_encode([
            'versao' => self::VERSAO_IMPRESSAO,
            'modo' => $dto->modo->value,
            'mes_efeito' => $dto->mesEfeito->startOfMonth()->toDateString(),
            'valor_novo' => $dto->valorNovo->unidadesMenores(),
            'plano' => [
                'id' => (int) $plano->id,
                'ano_lectivo_id' => (int) $plano->ano_lectivo_id,
                'valor' => $plano->valor->unidadesMenores(),
                'periodicidade' => $plano->periodicidade?->value,
                'intervalo_meses' => (int) $plano->intervalo_meses,
                'mes_inicio' => (int) $plano->mes_inicio,
                'mes_fim' => (int) $plano->mes_fim,
                'estado' => (int) $plano->estado,
                'updated_at' => $plano->updated_at?->toDateTimeString(),
                'alvos' => $alvos,
            ],
            'propinas_de_outros_anos' => 0,
            'propinas' => $candidatas->sortBy('id')->map(fn (Propina $p) => [
                (int) $p->id,
                (int) $p->matricula_id,
                (int) $p->plano_propina_id,
                (int) $p->ano_lectivo_id,
                (int) $p->ordem,
                $p->periodo_inicio->toDateString(),
                $p->estado->value,
                $p->valor->unidadesMenores(),
                $p->valor_pago->unidadesMenores(),
            ])->values()->all(),
        ], JSON_THROW_ON_ERROR));
    }

    private function rotuloDoMes(string $dia): string
    {
        return RotuloDePeriodo::competencia((int) substr($dia, 0, 4), (int) substr($dia, 5, 2));
    }
}
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Feature/AlteracaoPrecoPreVisualizacaoTest.php`
Expected: PASS (13 testes).

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/DTO/AlterarPrecoPlanoDTO.php Modules/Financeiro/app/DTO/PreVisualizacaoAlteracaoPrecoDTO.php Modules/Financeiro/app/Services/AlteracaoPrecoPlanoService.php Modules/Financeiro/tests/Concerns/ComAlteracaoPreco.php Modules/Financeiro/tests/Feature/AlteracaoPrecoPreVisualizacaoTest.php
```

---

### Task 6: Pré-visualização — aviso "valores diferentes" em MANTER (D10; fases M1/M2, Q14)

**Files:**
- Modify: `Modules/Financeiro/app/Services/AlteracaoPrecoPlanoService.php`
- Test: `Modules/Financeiro/tests/Feature/AlteracaoPrecoPreVisualizacaoTest.php` (+ 3 casos)

**Interfaces:**
- Consumes: T5; `ResolvePlanoAplicavel::paraTurma(Turma, ?array{ano,mes}): ResultadoResolucaoPlano` (`->plano`); `Propina::scopeActivas`; `Matricula`, `EstadoMatriculaEnum::ACTIVA`, `Turma`.
- Produces: `PreVisualizacaoAlteracaoPrecoDTO::$avisoValoresDiferentes` = `null` em APLICAR; em MANTER `array{matriculas_valor_antigo: int, matriculas_valor_novo: int, turma_exemplo: ?string, mensagem: string}`:
  - `matriculas_valor_antigo` = matrículas distintas com propina **activa** (estados 1–3) entre as candidatas (ficam ao valor antigo);
  - `matriculas_valor_novo` = matrículas **Activas** do ano do plano, em turmas para as quais o resolvedor escolhe **este** plano no mês de efeito, **sem** propina activa que cubra o 1.º dia do mês de efeito (serão geradas ao valor novo);
  - `turma_exemplo` = nome da 1.ª turma (id crescente) com os dois casos, ou `null`.
  - `exigeConfirmacaoValoresDiferentes()` passa a `true` quando `matriculas_valor_antigo > 0` (M2; a Action exige-o na Task 8).

- [ ] **Step 1: Escrever os testes que falham**

Acrescentar a `AlteracaoPrecoPreVisualizacaoTest` os `use` `Modules\Matricula\Enums\EstadoMatriculaEnum;` e os testes:
```php
    public function test_manter_avisa_com_numeros_reais_e_exige_confirmacao(): void
    {
        ['ano' => $ano, 'turma' => $turma, 'plano' => $plano, 'matricula' => $comPropinas] = $this->cenarioPropinas();
        $outraTurma = $this->turma($ano, $this->nivel('N-OUTRA'));

        foreach (range(6, 10) as $ordem) {
            $this->propina($comPropinas, $plano, $ordem);
        }

        $soFevereiro = $this->matriculaActiva($turma);
        $this->propina($soFevereiro, $plano, 6);
        $this->matriculaActiva($turma);                                                  // sem propinas: pagará o novo
        $this->matriculaActiva($outraTurma);                                             // sem propinas: pagará o novo
        $this->matriculaActiva($turma, '2026-09-01', EstadoMatriculaEnum::PENDENTE);     // não Activa: não conta

        $previa = $this->previa($plano, '2027-02', 3_000_000, self::MANTER);

        $this->assertSame(2, $previa->avisoValoresDiferentes['matriculas_valor_antigo']);
        $this->assertSame(2, $previa->avisoValoresDiferentes['matriculas_valor_novo']);
        $this->assertSame($turma->nome, $previa->avisoValoresDiferentes['turma_exemplo']);
        $this->assertStringContainsString('valores diferentes', $previa->avisoValoresDiferentes['mensagem']);
        $this->assertTrue($previa->exigeConfirmacaoValoresDiferentes());
        $this->assertTrue($previa->toArray()['exige_confirmacao_valores_diferentes']);
    }

    public function test_aplicar_nao_tem_aviso_de_valores_diferentes(): void
    {
        [$plano] = $this->estados();

        $previa = $this->previa($plano, '2027-02', 3_000_000, self::APLICAR);

        $this->assertNull($previa->avisoValoresDiferentes);
        $this->assertFalse($previa->exigeConfirmacaoValoresDiferentes());
    }

    public function test_manter_sem_propinas_geradas_a_partir_do_mes_de_efeito_nao_exige_confirmacao(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->propina($matricula, $plano, 1);

        $previa = $this->previa($plano, '2027-02', 3_000_000, self::MANTER);

        $this->assertSame(0, $previa->avisoValoresDiferentes['matriculas_valor_antigo']);
        $this->assertSame(1, $previa->avisoValoresDiferentes['matriculas_valor_novo']);
        $this->assertNull($previa->avisoValoresDiferentes['turma_exemplo']);
        $this->assertFalse($previa->exigeConfirmacaoValoresDiferentes());
    }
```
(No 3.º caso, a matrícula tem só Setembro: em Fevereiro não está coberta, por isso conta como "pagará o novo".)

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/AlteracaoPrecoPreVisualizacaoTest.php --filter="aviso|valores_diferentes|nao_exige"`
Expected: FAIL (`avisoValoresDiferentes` é `null` em MANTER).

- [ ] **Step 3: Implementar**

Em `AlteracaoPrecoPlanoService`:

1. `use` novos: `Modules\Financeiro\Services\ResolvePlanoAplicavel` não é preciso (mesmo namespace); acrescentar `use Modules\Matricula\Enums\EstadoMatriculaEnum;`, `use Modules\Matricula\Models\Matricula;`, `use Modules\Turma\Models\Turma;`.
2. Construtor:
```php
    public function __construct(
        private TenantContext $tenant,
        private ResolvePlanoAplicavel $resolver,
    ) {
    }
```
3. Em `preVisualizar`, trocar `avisoValoresDiferentes: null,` por:
```php
            avisoValoresDiferentes: $this->avisoValoresDiferentes($plano, $dto->modo, $mesEfeito, $candidatas),
```
4. Acrescentar o método:
```php
    /**
     * M1 (fases F4, spec §14 escolha 2): com «Manter as já geradas», os alunos com propinas já geradas ficam ao valor
     * antigo e os que ainda não as têm nascem ao valor novo, no mesmo mês e turma. Contagens reais, para a escola
     * decidir sabendo o efeito.
     *
     * @param  Collection<int, Propina>  $candidatas
     * @return array{matriculas_valor_antigo: int, matriculas_valor_novo: int, turma_exemplo: ?string, mensagem: string}|null
     */
    private function avisoValoresDiferentes(PlanoPropina $plano, ModoAlteracaoPreco $modo, CarbonImmutable $mesEfeito, Collection $candidatas): ?array
    {
        if ($modo !== ModoAlteracaoPreco::MANTER_GERADAS) {
            return null;
        }

        $dia = $mesEfeito->toDateString();
        $comValorAntigo = $candidatas
            ->filter(fn (Propina $p) => in_array($p->estado->value, Propina::ESTADOS_ACTIVOS, true))
            ->map(fn (Propina $p) => (int) $p->matricula_id)
            ->unique()
            ->values();

        $turmas = Turma::query()
            ->where('ano_lectivo_id', $plano->ano_lectivo_id)
            ->orderBy('id')
            ->get()
            ->filter(fn (Turma $turma) => $this->resolver->paraTurma($turma, ['ano' => $mesEfeito->year, 'mes' => $mesEfeito->month])->plano?->id === $plano->id)
            ->values();

        $cobertas = Propina::query()->activas()->where('periodo_inicio', '<=', $dia)->where('periodo_fim', '>=', $dia)->select('matricula_id');
        $semPropina = Matricula::query()
            ->where('ano_lectivo_id', $plano->ano_lectivo_id)
            ->where('estado', EstadoMatriculaEnum::ACTIVA->value)
            ->whereIn('turma_id', $turmas->modelKeys())
            ->whereNotIn('id', $cobertas)
            ->get(['id', 'turma_id']);

        $turmaDosAntigos = Matricula::query()->whereIn('id', $comValorAntigo->all())->pluck('turma_id')->map(fn ($id) => (int) $id)->unique();
        $exemplo = $turmas->first(fn (Turma $turma) => $turmaDosAntigos->contains((int) $turma->id) && $semPropina->contains('turma_id', $turma->id));
        $rotulo = $this->rotuloDoMes($dia);

        return [
            'matriculas_valor_antigo' => $comValorAntigo->count(),
            'matriculas_valor_novo' => $semPropina->count(),
            'turma_exemplo' => $exemplo?->nome,
            'mensagem' => "Com «Manter as já geradas», {$comValorAntigo->count()} matrícula(s) mantêm as propinas já geradas ao valor anterior e "
                . "{$semPropina->count()} matrícula(s) activa(s) ainda sem propina em {$rotulo} pagarão o novo valor: no mesmo mês e turma haverá alunos a pagar valores diferentes."
                . ($exemplo !== null ? " Exemplo: turma {$exemplo->nome}." : ''),
        ];
    }
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Feature/AlteracaoPrecoPreVisualizacaoTest.php`
Expected: PASS (16 testes).

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Services/AlteracaoPrecoPlanoService.php Modules/Financeiro/tests/Feature/AlteracaoPrecoPreVisualizacaoTest.php
```

---

### Task 7: Pré-visualização — isolamento por ano lectivo (D9; spec §14 testes obrigatórios 4, 5, 6, 7)

**Files:**
- Test: `Modules/Financeiro/tests/Feature/AlterarPrecoPlanoIsolamentoAnoLectivoTest.php` (novo; a Task 10 acrescenta os casos de escrita)
- Modify (só se um teste falhar): `Modules/Financeiro/app/Services/AlteracaoPrecoPlanoService.php`

**Interfaces:**
- Consumes: T5 (`preVisualizar`, `MENSAGEM_OUTRO_ANO`, log `financeiro.alterar-preco.propinas-de-outro-ano`).
- Produces: a classe de teste com o helper `cenarioDoisAnos()` que a Task 10 reutiliza: `array{anoA, anoB, turmaA, turmaB, planoA, planoB, matriculaA, matriculaB}` — dois anos lectivos (2026/2027 e 2027/2028), um plano geral "Propina Mensal" em cada (Set→Jun, 25.000,00), o **mesmo aluno** matriculado nos dois (renovação), dez propinas mensais em aberto em cada ano.

- [ ] **Step 1: Escrever os testes**

`Modules/Financeiro/tests/Feature/AlterarPrecoPlanoIsolamentoAnoLectivoTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\AlteracaoPrecoPlanoService;
use Modules\Financeiro\Tests\Concerns\ComAlteracaoPreco;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Matricula\Models\Matricula;
use Tests\TestCase;

/**
 * Spec §14 "Regra obrigatória: isolamento por ano lectivo" — os 8 testes obrigatórios. Aqui (Task 7) os da
 * pré-visualização; a Task 10 acrescenta os da escrita.
 */
class AlterarPrecoPlanoIsolamentoAnoLectivoTest extends TestCase
{
    use ComAlteracaoPreco;
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use RefreshDatabase;

    private const APLICAR = ModoAlteracaoPreco::APLICAR_GERADAS;
    private const MANTER = ModoAlteracaoPreco::MANTER_GERADAS;

    /**
     * @return array{anoA: mixed, anoB: mixed, turmaA: mixed, turmaB: mixed, planoA: PlanoPropina, planoB: PlanoPropina, matriculaA: Matricula, matriculaB: Matricula}
     */
    private function cenarioDoisAnos(): array
    {
        $anoA = $this->anoLectivo('2026/2027', '2026-09-01', '2027-07-31');
        $anoB = $this->anoLectivo('2027/2028', '2027-09-01', '2028-07-31');
        $nivel = $this->nivel('N1');
        $turmaA = $this->turma($anoA, $nivel);
        $turmaB = $this->turma($anoB, $nivel);
        $planoA = $this->plano($anoA, 'Propina Mensal');
        $planoB = $this->plano($anoB, 'Propina Mensal');
        $matriculaA = $this->matriculaActiva($turmaA);
        // Mesmo aluno, renovado para o ano seguinte.
        $matriculaB = Matricula::create([
            'aluno_id' => $matriculaA->aluno_id,
            'turma_id' => $turmaB->id,
            'ano_lectivo_id' => $anoB->id,
            'numero_registo_matricula' => 'MR-' . uniqid(),
            'data_matricula' => '2027-09-01',
            'estado' => $matriculaA->estado->value,
        ]);

        foreach (range(1, 10) as $ordem) {
            $this->propina($matriculaA, $planoA, $ordem);
            $this->propina($matriculaB, $planoB, $ordem);
        }

        return compact('anoA', 'anoB', 'turmaA', 'turmaB', 'planoA', 'planoB', 'matriculaA', 'matriculaB');
    }

    public function test_4_mes_de_efeito_fora_do_calendario_do_ano_do_plano_e_recusado(): void
    {
        ['planoA' => $planoA] = $this->cenarioDoisAnos();

        // O mesmo mês noutro ano civil (o de B), e um mês depois do fim do plano.
        foreach (['2027-09', '2028-02', '2027-07', '2025-10'] as $mes) {
            $this->assertRecusa('mes_efeito', 'no seu ano lectivo', fn () => $this->previa($planoA, $mes, 3_000_000, self::APLICAR));
        }
    }

    public function test_5_e_6_propina_de_outro_ano_injectada_no_conjunto_faz_recusar_a_previsualizacao_e_regista_no_log(): void
    {
        ['planoA' => $planoA, 'matriculaB' => $matriculaB] = $this->cenarioDoisAnos();
        $intrusa = Propina::query()->where('matricula_id', $matriculaB->id)->where('ordem', 7)->firstOrFail();
        // Fixture que viola a invariante da geração (§3): só possível por SQL directo (nunca pela app).
        DB::table('propinas')->where('id', $intrusa->id)->update(['plano_propina_id' => $planoA->id]);
        Log::spy();

        $this->assertRecusa('plano', 'outro ano lectivo', fn () => $this->previa($planoA, '2027-02', 3_000_000, self::APLICAR));
        $this->assertRecusa('plano', 'outro ano lectivo', fn () => $this->previa($planoA, '2027-02', 3_000_000, self::MANTER));

        Log::shouldHaveReceived('error')->withArgs(fn (string $mensagem, array $contexto) => $mensagem === 'financeiro.alterar-preco.propinas-de-outro-ano'
            && $contexto['propina_ids'] === [$intrusa->id] && $contexto['plano_propina_id'] === $planoA->id);
    }

    public function test_5_matricula_de_outro_ano_com_propina_do_plano_tambem_faz_recusar(): void
    {
        ['planoA' => $planoA, 'matriculaA' => $matriculaA, 'anoB' => $anoB] = $this->cenarioDoisAnos();
        DB::table('matriculas')->where('id', $matriculaA->id)->update(['ano_lectivo_id' => $anoB->id]);

        $this->assertRecusa('plano', 'outro ano lectivo', fn () => $this->previa($planoA, '2026-09', 3_000_000, self::APLICAR));
    }

    public function test_6_previsualizacao_mostra_o_ano_e_a_contagem_zero_de_outros_anos(): void
    {
        ['planoA' => $planoA, 'anoA' => $anoA, 'matriculaB' => $matriculaB] = $this->cenarioDoisAnos();

        $previa = $this->previa($planoA, '2027-02', 3_000_000, self::APLICAR)->toArray();

        $this->assertSame(['id' => $anoA->id, 'nome' => '2026/2027'], $previa['ano_lectivo']);
        $this->assertSame(0, $previa['propinas_de_outros_anos']);
        $this->assertSame('Nenhuma propina de outros anos lectivos será alterada.', $previa['garantia_outros_anos']);
        $idsDoOutroAno = Propina::query()->where('matricula_id', $matriculaB->id)->pluck('id')->all();
        $this->assertSame([], array_values(array_intersect($idsDoOutroAno, array_column($previa['afectadas'], 'propina_id'))));
        $this->assertSame([], array_values(array_intersect($idsDoOutroAno, array_column($previa['inalteradas'], 'propina_id'))));
    }

    public function test_7_impressao_digital_muda_se_o_ano_do_plano_mudar(): void
    {
        ['planoA' => $planoA, 'anoB' => $anoB] = $this->cenarioDoisAnos();
        $servico = app(AlteracaoPrecoPlanoService::class);
        $pedido = $this->pedidoAlteracao('2027-02', 3_000_000, self::MANTER);
        $candidatas = Propina::query()->where('plano_propina_id', $planoA->id)->where('periodo_inicio', '>=', '2027-02-01')->get();
        $plano = $planoA->fresh('alvos');
        $antes = $servico->impressaoDigital($plano, $pedido, $candidatas);

        $this->assertSame($antes, $this->previa($planoA, '2027-02', 3_000_000, self::MANTER)->impressaoDigital);

        // Só em memória: o ano de um plano é imutável na app; prova-se que a impressão o inclui…
        $plano->setRawAttributes(array_merge($plano->getAttributes(), ['ano_lectivo_id' => $anoB->id]));
        $this->assertNotSame($antes, $servico->impressaoDigital($plano, $pedido, $candidatas));

        // …e que, com o ano trocado, as propinas do plano passam a ser "de outro ano" e a pré-visualização recusa.
        $this->assertRecusa('plano', 'outro ano lectivo', fn () => $servico->preVisualizar($plano, $pedido));
    }
}
```

- [ ] **Step 2: Correr**

Run: `php artisan test Modules/Financeiro/tests/Feature/AlterarPrecoPlanoIsolamentoAnoLectivoTest.php`
Expected: PASS (o comportamento já foi implementado na Task 5). Se algum falhar, corrigir `AlteracaoPrecoPlanoService` (nunca o teste) e documentar.

- [ ] **Step 3: Stage**

```bash
git add Modules/Financeiro/tests/Feature/AlterarPrecoPlanoIsolamentoAnoLectivoTest.php Modules/Financeiro/app/Services/AlteracaoPrecoPlanoService.php
```

---

### Task 8: `AlterarPrecoPlanoAction` — uma transacção, ordem de bloqueio, impressão digital (D1–D8, D10, D12, D13)

**Files:**
- Create: `Modules/Financeiro/app/Actions/AlterarPrecoPlanoAction.php`
- Modify: `Modules/Financeiro/tests/Concerns/ComAlteracaoPreco.php` (+ `alterarPreco()`)
- Test: `Modules/Financeiro/tests/Feature/AlterarPrecoPlanoTest.php`

**Interfaces:**
- Consumes: T2 `RecalcularPropina::reajustarValor`, T3 `DivisaoDePlanoPropina::dividir/alterarValor`, `ReassociacaoDePropinas::reassociar`, T5–T7 `AlteracaoPrecoPlanoService::preVisualizar` (+ `PreVisualizacaoAlteracaoPrecoDTO::exigeConfirmacaoValoresDiferentes()`), `BloqueioDePropinas`, `ViolacaoDeUnicidade::e`, T1 models.
- Produces:
  - `AlterarPrecoPlanoAction::executar(PlanoPropina $plano, AlterarPrecoPlanoDTO $dto): AlteracaoPrecoPlano` (com `plano`, `planoNovo` e `ajustes` carregados). Autor = `auth()->id()` (hooks dos models). Constantes `MENSAGEM_DESACTUALIZADA`, `MENSAGEM_CONFIRMAR_VALORES`, `MENSAGEM_MOTIVO`.
  - Erros (`ValidationException`): `motivo` (vazio), `impressao_digital` (ausente, diferente, violação de unicidade concorrente, ou recusa de `valor`/`mes_efeito` que só pode significar que o plano mudou desde a pré-visualização — convertida **fora** da transacção), `confirmar_valores_diferentes` (M2) e `plano` (isolamento por ano, mensagem própria).
  - Teste: `ComAlteracaoPreco::alterarPreco(PlanoPropina, string $mesEfeito, int $valorNovo, ModoAlteracaoPreco, string $motivo = 'Actualização do preço', bool $confirma = true): AlteracaoPrecoPlano` (pré-visualiza e confirma com a impressão obtida, como a UI).

- [ ] **Step 1: Escrever o teste que falha**

Em `Modules/Financeiro/tests/Concerns/ComAlteracaoPreco.php`, acrescentar `use Modules\Financeiro\Actions\AlterarPrecoPlanoAction;` e:
```php
    /** Pré-visualiza e confirma com a impressão digital obtida (como a UI). */
    protected function alterarPreco(PlanoPropina $plano, string $mesEfeito, int $valorNovo, ModoAlteracaoPreco $modo, string $motivo = 'Actualização do preço', bool $confirma = true): AlteracaoPrecoPlano
    {
        $impressao = $this->previa($plano, $mesEfeito, $valorNovo, $modo)->impressaoDigital;

        return app(AlterarPrecoPlanoAction::class)->executar(
            $plano->fresh(),
            $this->pedidoAlteracao($mesEfeito, $valorNovo, $modo, $motivo, $impressao, $confirma),
        );
    }
```

`Modules/Financeiro/tests/Feature/AlterarPrecoPlanoTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Core\Enums\Estado;
use Modules\Financeiro\Actions\AlterarPrecoPlanoAction;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Financeiro\Models\AlteracaoPrecoPlano;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\PropinaAjuste;
use Modules\Financeiro\Tests\Concerns\ComAlteracaoPreco;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

class AlterarPrecoPlanoTest extends TestCase
{
    use ComAlteracaoPreco;
    use ComDadosAcademicosFinanceiro;
    use ComPropinasFinanceiro;
    use ComUtilizadoresFinanceiro;
    use RefreshDatabase;

    private const APLICAR = ModoAlteracaoPreco::APLICAR_GERADAS;
    private const MANTER = ModoAlteracaoPreco::MANTER_GERADAS;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissaoDatabaseSeeder::class);
        Carbon::setTestNow(CarbonImmutable::parse('2026-10-16 10:00:00', 'UTC'));
        $this->actingAs($this->adminEscola());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * cenarioDeEstados com contribuições coerentes com o valor pago em cache (o reajuste recalcula-as).
     *
     * @return array{0: PlanoPropina, 1: array<string, Propina>}
     */
    private function estados(): array
    {
        $e = $this->cenarioDeEstados();
        $fonte = $this->fontePagamentoFalsa();
        $fonte->definir($e['out_parcial'], [[1_000_000, '2026-10-05']]);
        $fonte->definir($e['mar_parcial'], [[2_000_000, '2026-10-05']]);
        $fonte->definir($e['nov_paga'], [[2_500_000, '2026-10-05']]);
        $fonte->definir($e['abr_paga'], [[2_500_000, '2026-10-05']]);

        return [$e['set_aberta']->plano()->firstOrFail(), $e];
    }

    /** @return array<string, mixed> o que uma propina tem de "intocável" fora do valor/plano/ordem */
    private function snapshot(Propina $propina): array
    {
        $p = $propina->fresh();

        return [$p->periodo_inicio->toDateString(), $p->periodo_fim->toDateString(), $p->data_vencimento->toDateString(), $p->dias_tolerancia,
            $p->data_limite->toDateString(), $p->valor_original->unidadesMenores(), $p->valor_pago->unidadesMenores(), $p->moeda];
    }

    public function test_aplicar_divide_o_plano_reassocia_e_reajusta_so_as_em_aberto_e_parciais_a_partir_do_mes_de_efeito(): void
    {
        [$plano, $e] = $this->estados();
        $antes = array_map(fn (Propina $p) => $this->snapshot($p), $e);

        $alteracao = $this->alterarPreco($plano, '2027-02', 3_000_000, self::APLICAR, 'Actualização anual');

        $plano->refresh();
        $novo = PlanoPropina::query()->where('nome', 'Propina Mensal (desde 02/2027)')->firstOrFail();
        $this->assertSame([9, 1, 2_500_000], [$plano->mes_inicio, $plano->mes_fim, $plano->valor->unidadesMenores()]);
        $this->assertSame([2, 6, 3_000_000, $plano->ano_lectivo_id], [$novo->mes_inicio, $novo->mes_fim, $novo->valor->unidadesMenores(), $novo->ano_lectivo_id]);

        foreach (['fev_aberta' => 1, 'mar_parcial' => 2, 'mai_aberta' => 4, 'jun_aberta' => 5] as $chave => $ordem) {
            $p = $e[$chave]->fresh();
            $this->assertSame([$novo->id, $ordem, 3_000_000], [$p->plano_propina_id, $p->ordem, $p->valor->unidadesMenores()], $chave);
            $this->assertSame($antes[$chave], $this->snapshot($p), "{$chave}: período, vencimento, original e pago intactos");
        }

        $this->assertSame(EstadoCobranca::PARCIALMENTE_PAGA, $e['mar_parcial']->fresh()->estado);
        $this->assertSame(1_000_000, $e['mar_parcial']->fresh()->saldo()->unidadesMenores());

        foreach (['set_aberta', 'out_parcial', 'nov_paga', 'dez_cancelada', 'jan_anulada', 'abr_paga'] as $chave) {
            $p = $e[$chave]->fresh();
            $this->assertSame([$plano->id, $e[$chave]->ordem, 2_500_000], [$p->plano_propina_id, $p->ordem, $p->valor->unidadesMenores()], $chave);
            $this->assertSame($e[$chave]->estado, $p->estado, $chave);
        }

        $alteracao->refresh();
        $this->assertSame(ModoAlteracaoPreco::APLICAR_GERADAS, $alteracao->modo);
        $this->assertSame([$plano->id, $novo->id, $plano->ano_lectivo_id], [$alteracao->plano_propina_id, $alteracao->plano_novo_id, $alteracao->ano_lectivo_id]);
        $this->assertSame([2_500_000, 3_000_000, 4], [$alteracao->valor_anterior->unidadesMenores(), $alteracao->valor_novo->unidadesMenores(), $alteracao->total_propinas_afectadas]);
        $this->assertSame('2027-02-01', $alteracao->mes_efeito->toDateString());
        $this->assertSame('Actualização anual', $alteracao->motivo);
        $this->assertSame(auth()->id(), $alteracao->criado_por);
        $this->assertSame(64, strlen($alteracao->impressao_digital));

        $ajusteMarco = PropinaAjuste::query()->where('propina_id', $e['mar_parcial']->id)->firstOrFail();
        $this->assertSame([2_500_000, 3_000_000, 2_000_000], [$ajusteMarco->valor_anterior->unidadesMenores(), $ajusteMarco->valor_novo->unidadesMenores(), $ajusteMarco->valor_pago_no_momento->unidadesMenores()]);
        $this->assertSame([$plano->id, $novo->id, 'Actualização anual', auth()->id()], [$ajusteMarco->plano_anterior_id, $ajusteMarco->plano_novo_id, $ajusteMarco->motivo, $ajusteMarco->criado_por]);
        $this->assertSame(4, PropinaAjuste::query()->count());
        $this->assertPropinasCoerentes();
    }

    public function test_manter_divide_o_plano_sem_tocar_em_nenhuma_propina(): void
    {
        [$plano] = $this->estados();
        $propinasAntes = $this->fotografia()['propinas'];

        $alteracao = $this->alterarPreco($plano, '2027-02', 3_000_000, self::MANTER);

        $this->assertSame($propinasAntes, $this->fotografia()['propinas']);
        $this->assertSame(1, $plano->fresh()->mes_fim);
        $this->assertNotNull($alteracao->plano_novo_id);
        $this->assertSame(0, $alteracao->total_propinas_afectadas);
        $this->assertSame(0, PropinaAjuste::query()->count());
        $this->assertPropinasCoerentes();
    }

    public function test_manter_com_alunos_ao_valor_antigo_exige_confirmar_valores_diferentes(): void
    {
        [$plano] = $this->estados();
        $antes = $this->fotografia();

        $this->assertRecusa('confirmar_valores_diferentes', 'valores diferentes', fn () => $this->alterarPreco($plano, '2027-02', 3_000_000, self::MANTER, confirma: false));
        $this->assertSame($antes, $this->fotografia());
    }

    public function test_in_loco_aplicar_reajusta_no_proprio_plano(): void
    {
        [$plano, $e] = $this->estados();

        $alteracao = $this->alterarPreco($plano, '2026-09', 3_000_000, self::APLICAR);

        $this->assertNull($alteracao->plano_novo_id);
        $this->assertSame(1, PlanoPropina::query()->count());
        $this->assertSame([9, 6, 3_000_000], [$plano->fresh()->mes_inicio, $plano->fresh()->mes_fim, $plano->fresh()->valor->unidadesMenores()]);

        foreach (['set_aberta', 'out_parcial', 'fev_aberta', 'mar_parcial', 'mai_aberta', 'jun_aberta'] as $chave) {
            $p = $e[$chave]->fresh();
            $this->assertSame([$plano->id, $e[$chave]->ordem, 3_000_000], [$p->plano_propina_id, $p->ordem, $p->valor->unidadesMenores()], $chave);
        }

        $this->assertSame(6, $alteracao->total_propinas_afectadas);
        $this->assertNull(PropinaAjuste::query()->firstOrFail()->plano_novo_id);
        $this->assertPropinasCoerentes();
    }

    public function test_in_loco_manter_so_muda_o_valor_do_plano(): void
    {
        [$plano] = $this->estados();
        $propinasAntes = $this->fotografia()['propinas'];

        $this->alterarPreco($plano, '2026-09', 3_000_000, self::MANTER);

        $this->assertSame(3_000_000, $plano->fresh()->valor->unidadesMenores());
        $this->assertSame($propinasAntes, $this->fotografia()['propinas']);
    }

    public function test_descida_abaixo_do_pago_fica_no_pago_passa_a_paga_sem_devolucao_e_fica_registada(): void
    {
        [$plano, $e] = $this->estados();

        $this->alterarPreco($plano, '2027-02', 1_500_000, self::APLICAR);

        $marco = $e['mar_parcial']->fresh();
        $this->assertSame([2_000_000, 2_000_000, EstadoCobranca::PAGA], [$marco->valor->unidadesMenores(), $marco->valor_pago->unidadesMenores(), $marco->estado]);
        $this->assertSame('2026-10-05', $marco->capital_liquidado_em?->toDateString());
        $this->assertSame(1_500_000, $e['fev_aberta']->fresh()->valor->unidadesMenores());
        $ajuste = PropinaAjuste::query()->where('propina_id', $marco->id)->firstOrFail();
        $this->assertSame([2_500_000, 2_000_000, 2_000_000], [$ajuste->valor_anterior->unidadesMenores(), $ajuste->valor_novo->unidadesMenores(), $ajuste->valor_pago_no_momento->unidadesMenores()]);
        $this->assertPropinasCoerentes();
    }

    public function test_pagamento_entre_a_previsualizacao_e_a_confirmacao_recusa_e_nada_muda(): void
    {
        [$plano, $e] = $this->estados();
        $previa = $this->previa($plano, '2027-02', 3_000_000, self::APLICAR);
        $this->comEstado($e['fev_aberta'], EstadoCobranca::PARCIALMENTE_PAGA, 100_000);
        $antes = $this->fotografia();

        $this->assertRecusa('impressao_digital', 'Os dados mudaram desde a pré-visualização', fn () => app(AlterarPrecoPlanoAction::class)->executar(
            $plano->fresh(),
            $this->pedidoAlteracao('2027-02', 3_000_000, self::APLICAR, 'Motivo', $previa->impressaoDigital),
        ));
        $this->assertSame($antes, $this->fotografia());
    }

    public function test_segundo_clique_com_a_mesma_previsualizacao_e_recusado_e_nada_se_duplica(): void
    {
        [$plano] = $this->estados();
        $previa = $this->previa($plano, '2027-02', 3_000_000, self::APLICAR);
        $pedido = $this->pedidoAlteracao('2027-02', 3_000_000, self::APLICAR, 'Motivo', $previa->impressaoDigital);

        app(AlterarPrecoPlanoAction::class)->executar($plano->fresh(), $pedido);
        $depoisDoPrimeiro = $this->fotografia();

        $this->assertRecusa('impressao_digital', 'Os dados mudaram', fn () => app(AlterarPrecoPlanoAction::class)->executar($plano->fresh(), $pedido));
        $this->assertSame($depoisDoPrimeiro, $this->fotografia());
        $this->assertSame(1, AlteracaoPrecoPlano::query()->count());
        $this->assertSame(2, PlanoPropina::query()->count());
    }

    public function test_motivo_e_impressao_digital_sao_obrigatorios(): void
    {
        [$plano] = $this->estados();
        $impressao = $this->previa($plano, '2027-02', 3_000_000, self::APLICAR)->impressaoDigital;
        $acao = app(AlterarPrecoPlanoAction::class);

        $this->assertRecusa('motivo', 'motivo', fn () => $acao->executar($plano->fresh(), $this->pedidoAlteracao('2027-02', 3_000_000, self::APLICAR, '   ', $impressao)));
        $this->assertRecusa('impressao_digital', 'Os dados mudaram', fn () => $acao->executar($plano->fresh(), $this->pedidoAlteracao('2027-02', 3_000_000, self::APLICAR, 'Motivo', null)));
        $this->assertRecusa('impressao_digital', 'Os dados mudaram', fn () => $acao->executar($plano->fresh(), $this->pedidoAlteracao('2027-02', 3_000_000, self::APLICAR, 'Motivo', str_repeat('0', 64))));
        $this->assertSame(0, AlteracaoPrecoPlano::query()->count());
    }

    public function test_plano_inactivo_divide_e_o_novo_fica_inactivo(): void
    {
        [$plano, $e] = $this->estados();
        $plano->update(['estado' => Estado::INATIVO->value]);

        $alteracao = $this->alterarPreco($plano, '2027-02', 3_000_000, self::APLICAR);

        $this->assertSame(Estado::INATIVO->value, $alteracao->planoNovo->estado);
        $this->assertSame($alteracao->plano_novo_id, $e['fev_aberta']->fresh()->plano_propina_id);
    }

    public function test_reverter_e_uma_nova_alteracao_auditada_e_os_ajustes_encadeiam(): void
    {
        [$plano, $e] = $this->estados();
        $primeira = $this->alterarPreco($plano, '2027-02', 3_000_000, self::APLICAR, 'Subida');
        $novo = $primeira->planoNovo;

        // M4: reverter = nova alteração sobre o plano novo, in loco (o seu início é Fev/2027), de volta a 25.000,00.
        $this->alterarPreco($novo, '2027-02', 2_500_000, self::APLICAR, 'Reversão: subida aplicada por engano');

        $this->assertSame(2_500_000, $e['fev_aberta']->fresh()->valor->unidadesMenores());
        $this->assertSame(['Subida', 'Reversão: subida aplicada por engano'], $e['fev_aberta']->fresh()->ajustes->pluck('motivo')->all());
        $this->assertSame(2, AlteracaoPrecoPlano::query()->count());
        $this->assertPropinasCoerentes();
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/AlterarPrecoPlanoTest.php`
Expected: FAIL (`AlterarPrecoPlanoAction` inexistente).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Actions/AlterarPrecoPlanoAction.php`:
```php
<?php

namespace Modules\Financeiro\Actions;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\DTO\AlterarPrecoPlanoDTO;
use Modules\Financeiro\Models\AlteracaoPrecoPlano;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\PropinaAjuste;
use Modules\Financeiro\Services\AlteracaoPrecoPlanoService;
use Modules\Financeiro\Services\RecalcularPropina;
use Modules\Financeiro\Support\BloqueioDePropinas;
use Modules\Financeiro\Support\Dinheiro;
use Modules\Financeiro\Support\DivisaoDePlanoPropina;
use Modules\Financeiro\Support\ReassociacaoDePropinas;
use Modules\Financeiro\Support\ViolacaoDeUnicidade;
use Modules\Matricula\Models\Matricula;

/**
 * ÚNICA porta de entrada para mudar o preço de um plano com propinas (spec Propinas §14). Uma transacção, tudo ou
 * nada, pela ordem do spec:
 *   1. bloquear, pela ordem global (D7): matrículas das candidatas → plano → propinas candidatas (id crescente);
 *   2. refazer a pré-visualização sobre o estado bloqueado (isolamento por ano, fronteira, âncora) e exigir a MESMA
 *      impressão digital (um pagamento, uma geração, outra alteração ou um 2.º clique pelo meio → recusa);
 *   3–4. encurtar o plano antigo e criar o novo (mesmos alvos e ano), ou mudar o valor in loco;
 *   5. APLICAR: reassociar ao plano novo e reajustar (valor = max(novo, pago), no RecalcularPropina);
 *   6. registar `alteracoes_preco_plano` e um `propina_ajustes` por propina alterada.
 * Nada é apanhado dentro da transacção (PostgreSQL abortá-la-ia); a única conversão de erro é FORA dela. Nenhum
 * efeito fora da BD.
 */
class AlterarPrecoPlanoAction
{
    public const MENSAGEM_DESACTUALIZADA = 'Os dados mudaram desde a pré-visualização (pagamento, anulação, geração ou outra alteração deste plano). Reveja a pré-visualização e confirme de novo.';

    public const MENSAGEM_CONFIRMAR_VALORES = 'Confirme que compreende que alunos da mesma turma vão pagar valores diferentes.';

    public const MENSAGEM_MOTIVO = 'Indique o motivo da alteração de preço.';

    public function __construct(
        private AlteracaoPrecoPlanoService $calculo,
        private BloqueioDePropinas $bloqueio,
        private DivisaoDePlanoPropina $divisao,
        private ReassociacaoDePropinas $reassociacao,
        private RecalcularPropina $recalcular,
    ) {
    }

    public function executar(PlanoPropina $plano, AlterarPrecoPlanoDTO $dto): AlteracaoPrecoPlano
    {
        if (trim((string) $dto->motivo) === '') {
            throw ValidationException::withMessages(['motivo' => self::MENSAGEM_MOTIVO]);
        }

        if ((string) $dto->impressaoDigital === '') {
            throw ValidationException::withMessages(['impressao_digital' => self::MENSAGEM_DESACTUALIZADA]);
        }

        try {
            return DB::transaction(fn () => $this->gravar($plano, $dto));
        } catch (QueryException $e) {
            // Já FORA da transacção, revertida por inteiro. Uma unicidade violada aqui é outra operação concorrente
            // (ex.: o mesmo nome de plano criado ao mesmo tempo): o estado mudou, a pré-visualização tem de ser refeita.
            if (ViolacaoDeUnicidade::e($e)) {
                throw ValidationException::withMessages(['impressao_digital' => self::MENSAGEM_DESACTUALIZADA]);
            }

            throw $e;
        } catch (ValidationException $e) {
            // Já FORA da transacção. A confirmação segue uma pré-visualização aceite com os mesmos parâmetros: recusar
            // agora o valor ou o mês de efeito só acontece se o plano mudou entretanto (ex.: um 2.º clique depois da
            // divisão ou do valor in loco). Isolamento por ano («plano») e M2 mantêm a sua mensagem.
            if (array_intersect(array_keys($e->errors()), ['valor', 'mes_efeito']) !== []) {
                throw ValidationException::withMessages(['impressao_digital' => self::MENSAGEM_DESACTUALIZADA]);
            }

            throw $e;
        }
    }

    private function gravar(PlanoPropina $plano, AlterarPrecoPlanoDTO $dto): AlteracaoPrecoPlano
    {
        $mesEfeito = $dto->mesEfeito->startOfMonth();
        $dia = $mesEfeito->toDateString();

        // 1. Ordem global de bloqueio (D7): matrículas → plano → propinas. As candidatas lidas aqui sem bloqueio podem
        //    mudar até ao bloqueio do plano; se mudarem, a impressão digital (passo 2) já não coincide.
        $matriculas = Propina::query()->where('plano_propina_id', $plano->getKey())->where('periodo_inicio', '>=', $dia)
            ->distinct()->orderBy('matricula_id')->pluck('matricula_id')->map(fn ($id) => (int) $id)->all();

        if ($matriculas !== []) {
            Matricula::query()->withTrashed()->whereKey($matriculas)->orderBy('id')->lockForUpdate()->get(['id']);
        }

        $bloqueado = PlanoPropina::query()->whereKey($plano->getKey())->lockForUpdate()->firstOrFail();
        $this->bloqueio->bloquear(
            Propina::query()->where('plano_propina_id', $bloqueado->id)->where('periodo_inicio', '>=', $dia)->pluck('id')->map(fn ($id) => (int) $id)->all(),
        );

        // 2. O mesmo cálculo da pré-visualização, agora sobre o estado bloqueado. Recusas lançam e revertem.
        $previa = $this->calculo->preVisualizar($bloqueado, $dto);

        if (! hash_equals($previa->impressaoDigital, (string) $dto->impressaoDigital)) {
            throw ValidationException::withMessages(['impressao_digital' => self::MENSAGEM_DESACTUALIZADA]);
        }

        if ($previa->exigeConfirmacaoValoresDiferentes() && ! $dto->confirmaValoresDiferentes) {
            throw ValidationException::withMessages(['confirmar_valores_diferentes' => self::MENSAGEM_CONFIRMAR_VALORES]);
        }

        // 3–4. O plano: dividir em mes_efeito ou mudar o valor in loco.
        $planoNovo = null;

        if ($previa->inLoco) {
            $this->divisao->alterarValor($bloqueado, $dto->valorNovo);
        } else {
            $planoNovo = $this->divisao->dividir($bloqueado, $mesEfeito, $dto->valorNovo, (string) $previa->nomePlanoNovo);
        }

        // 5. As propinas (só APLICAR tem afectadas).
        $ajustes = [];

        foreach ($previa->afectadas as $item) {
            $propina = Propina::query()->findOrFail($item['propina_id']);
            $valorAnterior = $propina->valor;
            $planoAnteriorId = (int) $propina->plano_propina_id;

            if ($planoNovo !== null) {
                $propina = $this->reassociacao->reassociar($propina, $planoNovo, (int) $item['ordem_depois']);
            }

            $propina = $this->recalcular->reajustarValor($propina, $dto->valorNovo);

            $ajustes[] = [
                'propina_id' => (int) $propina->id,
                'valor_anterior' => $valorAnterior,
                'valor_novo' => $propina->valor,
                'valor_pago_no_momento' => $propina->valor_pago,
                'plano_anterior_id' => $planoAnteriorId,
                'plano_novo_id' => $planoNovo?->id,
            ];
        }

        // 6. Os registos (só acrescentam).
        $motivo = trim((string) $dto->motivo);
        $alteracao = AlteracaoPrecoPlano::create([
            'ano_lectivo_id' => $bloqueado->ano_lectivo_id,
            'plano_propina_id' => $bloqueado->id,
            'plano_novo_id' => $planoNovo?->id,
            'modo' => $dto->modo,
            'valor_anterior' => Dinheiro::deUnidadesMenores($previa->valorAnterior),
            'valor_novo' => $dto->valorNovo,
            'mes_efeito' => $dia,
            'motivo' => $motivo,
            'total_propinas_afectadas' => count($ajustes),
            'impressao_digital' => $previa->impressaoDigital,
        ]);

        foreach ($ajustes as $ajuste) {
            PropinaAjuste::create($ajuste + ['alteracao_preco_id' => $alteracao->id, 'motivo' => $motivo]);
        }

        return $alteracao->load(['plano', 'planoNovo', 'ajustes']);
    }
}
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Feature/AlterarPrecoPlanoTest.php tests/Feature/Arquitectura`
Expected: PASS (11 testes de F4 + arquitectura: `TenancyArquitecturaTest` sem SQL cru, `RelogioDoTenantArquitecturaTest` sem `now()`, `EscritoresDePropinaTest` com a Action fora das listas de escritores).

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Actions/AlterarPrecoPlanoAction.php Modules/Financeiro/tests/Concerns/ComAlteracaoPreco.php Modules/Financeiro/tests/Feature/AlterarPrecoPlanoTest.php
```

---

### Task 9: Atomicidade — falha injectada em cada etapa reverte tudo (D13; spec §14 "teste obrigatório")

**Files:**
- Test: `Modules/Financeiro/tests/Feature/AlterarPrecoPlanoFalhasTest.php`
- Modify (só se um teste falhar): `Modules/Financeiro/app/Actions/AlterarPrecoPlanoAction.php`

**Interfaces:**
- Consumes: T8 (`AlterarPrecoPlanoAction`), `ComAlteracaoPreco::fotografia()/previa()/pedidoAlteracao()`.
- Produces: prova de que, com uma excepção lançada por um listener de model em cada etapa (encurtar o plano antigo, criar o plano novo, valor in loco, 2.ª reassociação, 2.º reajuste, registo da alteração, 3.º ajuste), o plano antigo, os alvos, todas as propinas e as tabelas de registo ficam **exactamente** iguais, e a excepção chega ao chamador sem ser convertida.

- [ ] **Step 1: Escrever o teste**

`Modules/Financeiro/tests/Feature/AlterarPrecoPlanoFalhasTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Financeiro\Actions\AlterarPrecoPlanoAction;
use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Financeiro\Models\AlteracaoPrecoPlano;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\PropinaAjuste;
use Modules\Financeiro\Tests\Concerns\ComAlteracaoPreco;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Spec §14 "Atomicidade de ponta a ponta": uma falha em qualquer etapa reverte tudo. (Em PostgreSQL, a reversão
 * depois de um erro a meio prova-se na Task 15.)
 */
class AlterarPrecoPlanoFalhasTest extends TestCase
{
    use ComAlteracaoPreco;
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

    /** Lança na n-ésima actualização de uma propina que cumpra a condição. */
    private static function naPropina(int $n, Closure $condicao, string $etapa): void
    {
        $vezes = 0;
        Propina::updating(function (Propina $propina) use ($n, $condicao, $etapa, &$vezes) {
            if ($condicao($propina) && ++$vezes === $n) {
                throw new RuntimeException("Falha injectada: {$etapa}");
            }
        });
    }

    public static function etapas(): array
    {
        $aplicar = ModoAlteracaoPreco::APLICAR_GERADAS;
        $manter = ModoAlteracaoPreco::MANTER_GERADAS;

        return [
            'encurtar o plano antigo' => ['2027-02', $aplicar, fn () => PlanoPropina::updating(fn (PlanoPropina $p) => $p->isDirty('mes_fim') ? throw new RuntimeException('Falha injectada: encurtar') : null)],
            'criar o plano novo' => ['2027-02', $aplicar, fn () => PlanoPropina::creating(fn () => throw new RuntimeException('Falha injectada: plano novo'))],
            'mudar o valor in loco' => ['2026-09', $aplicar, fn () => PlanoPropina::updating(fn (PlanoPropina $p) => $p->isDirty('valor') ? throw new RuntimeException('Falha injectada: valor in loco') : null)],
            'reassociar a 2.ª propina' => ['2027-02', $aplicar, fn () => self::naPropina(2, fn (Propina $p) => $p->isDirty('plano_propina_id'), 'reassociar')],
            'reajustar a 2.ª propina' => ['2027-02', $aplicar, fn () => self::naPropina(2, fn (Propina $p) => $p->isDirty('valor'), 'reajustar')],
            'registar a alteração' => ['2027-02', $aplicar, fn () => AlteracaoPrecoPlano::creating(fn () => throw new RuntimeException('Falha injectada: alteração'))],
            'registar o 3.º ajuste' => ['2027-02', $aplicar, function () {
                $vezes = 0;
                PropinaAjuste::creating(function () use (&$vezes) {
                    if (++$vezes === 3) {
                        throw new RuntimeException('Falha injectada: ajuste');
                    }
                });
            }],
            'registar a alteração em MANTER' => ['2027-02', $manter, fn () => AlteracaoPrecoPlano::creating(fn () => throw new RuntimeException('Falha injectada: alteração MANTER'))],
        ];
    }

    #[DataProvider('etapas')]
    public function test_uma_falha_em_qualquer_etapa_reverte_tudo(string $mesEfeito, ModoAlteracaoPreco $modo, Closure $injectar): void
    {
        $e = $this->cenarioDeEstados();
        $fonte = $this->fontePagamentoFalsa();
        $fonte->definir($e['out_parcial'], [[1_000_000, '2026-10-05']]);
        $fonte->definir($e['mar_parcial'], [[2_000_000, '2026-10-05']]);
        $plano = $e['set_aberta']->plano()->firstOrFail();
        $impressao = $this->previa($plano, $mesEfeito, 3_000_000, $modo)->impressaoDigital;
        $antes = $this->fotografia();
        $injectar();

        try {
            app(AlterarPrecoPlanoAction::class)->executar($plano->fresh(), $this->pedidoAlteracao($mesEfeito, 3_000_000, $modo, 'Motivo', $impressao, true));
            $this->fail('A falha injectada devia chegar ao chamador.');
        } catch (RuntimeException $excepcao) {
            $this->assertStringStartsWith('Falha injectada', $excepcao->getMessage());
        }

        $this->assertSame($antes, $this->fotografia(), 'Plano, alvos, propinas e registos exactamente como antes.');
        $this->assertPropinasCoerentes();
    }
}
```

- [ ] **Step 2: Correr**

Run: `php artisan test Modules/Financeiro/tests/Feature/AlterarPrecoPlanoFalhasTest.php`
Expected: PASS (8 casos). Se algum caso não reverter, o defeito está na Action (algo escrito fora da transacção ou excepção apanhada lá dentro): corrigir a Action, nunca o teste.

- [ ] **Step 3: Stage**

```bash
git add Modules/Financeiro/tests/Feature/AlterarPrecoPlanoFalhasTest.php
```

---

### Task 10: Escrita — isolamento por ano lectivo (D9; spec §14 testes obrigatórios 1, 2, 3, 5, 8)

**Files:**
- Modify: `Modules/Financeiro/tests/Feature/AlterarPrecoPlanoIsolamentoAnoLectivoTest.php` (+ casos de escrita)
- Modify (só se um teste falhar): `Modules/Financeiro/app/Actions/AlterarPrecoPlanoAction.php`, `Modules/Financeiro/app/Services/AlteracaoPrecoPlanoService.php`

**Interfaces:**
- Consumes: T7 (`cenarioDoisAnos()`), T8 (`alterarPreco()`), `fotografia()`.
- Produces: os testes obrigatórios de escrita.

- [ ] **Step 1: Escrever os testes**

Acrescentar a `AlterarPrecoPlanoIsolamentoAnoLectivoTest` os `use` `Modules\Financeiro\Actions\AlterarPrecoPlanoAction;`, `Modules\Financeiro\Models\AlteracaoPrecoPlano;`, `Modules\Financeiro\Models\PropinaAjuste;` e:
```php
    /** @return array<int, array<string, mixed>> as propinas de uma matrícula, em cru, por id */
    private function propinasDe(Matricula $matricula): array
    {
        return Propina::query()->where('matricula_id', $matricula->id)->orderBy('id')->get()->mapWithKeys(fn (Propina $p) => [$p->id => $p->getAttributes()])->all();
    }

    public function test_1_duas_operacoes_em_anos_diferentes_nao_se_tocam(): void
    {
        ['planoA' => $planoA, 'planoB' => $planoB, 'matriculaA' => $matriculaA, 'matriculaB' => $matriculaB] = $this->cenarioDoisAnos();
        $deB = $this->propinasDe($matriculaB);

        $this->alterarPreco($planoA, '2027-02', 3_000_000, self::APLICAR);

        $this->assertSame($deB, $this->propinasDe($matriculaB), 'Propinas de B intactas: valor, valor_pago, plano, ordem.');
        $this->assertSame(0, PropinaAjuste::query()->whereIn('propina_id', array_keys($deB))->count());
        $deA = $this->propinasDe($matriculaA);

        $this->alterarPreco($planoB, '2028-02', 2_000_000, self::APLICAR);

        $this->assertSame($deA, $this->propinasDe($matriculaA), 'A segunda operação (ano B) não toca no ano A.');
        $this->assertSame(2, AlteracaoPrecoPlano::query()->count());
        $this->assertSame([$planoA->ano_lectivo_id, $planoB->ano_lectivo_id], AlteracaoPrecoPlano::query()->orderBy('id')->pluck('ano_lectivo_id')->all());
        $this->assertPropinasCoerentes();
    }

    public function test_2_ano_anterior_com_divida_em_aberto_nao_e_tocado_em_aplicar_nem_em_manter(): void
    {
        ['planoA' => $planoA, 'planoB' => $planoB, 'matriculaA' => $matriculaA] = $this->cenarioDoisAnos();
        $dividaDoAnoAnterior = $this->propinasDe($matriculaA);

        $this->alterarPreco($planoB, '2027-09', 3_000_000, self::APLICAR);
        $this->assertSame($dividaDoAnoAnterior, $this->propinasDe($matriculaA));

        $this->alterarPreco($planoB->fresh(), '2027-09', 3_500_000, self::MANTER);
        $this->assertSame($dividaDoAnoAnterior, $this->propinasDe($matriculaA));
        $this->assertSame([9, 6, 2_500_000], [$planoA->fresh()->mes_inicio, $planoA->fresh()->mes_fim, $planoA->fresh()->valor->unidadesMenores()]);
    }

    public function test_3_ano_futuro_com_plano_de_alvos_iguais_nao_e_tocado_nem_colide(): void
    {
        ['planoA' => $planoA, 'planoB' => $planoB] = $this->cenarioDoisAnos();
        $planoBAntes = $planoB->fresh()->getAttributes();

        $alteracao = $this->alterarPreco($planoA, '2027-02', 3_000_000, self::MANTER);

        $this->assertSame($planoBAntes, $planoB->fresh()->getAttributes());
        $this->assertSame($planoA->ano_lectivo_id, $alteracao->planoNovo->ano_lectivo_id);
        // O nome "Propina Mensal (desde 02/2027)" é único no ano A; o plano "Propina Mensal" de B não conta.
        $this->assertSame('Propina Mensal (desde 02/2027)', $alteracao->planoNovo->nome);
        $this->assertSame(2, PlanoPropina::query()->where('ano_lectivo_id', $planoA->ano_lectivo_id)->count());
        $this->assertSame(1, PlanoPropina::query()->where('ano_lectivo_id', $planoB->ano_lectivo_id)->count());
    }

    public function test_5_propina_de_outro_ano_injectada_aborta_a_confirmacao_inteira(): void
    {
        ['planoA' => $planoA, 'matriculaB' => $matriculaB] = $this->cenarioDoisAnos();
        $impressao = $this->previa($planoA, '2027-02', 3_000_000, self::APLICAR)->impressaoDigital;
        // A intrusa aparece DEPOIS da pré-visualização: só a verificação dentro da transacção a apanha.
        $intrusa = Propina::query()->where('matricula_id', $matriculaB->id)->where('ordem', 7)->firstOrFail();
        DB::table('propinas')->where('id', $intrusa->id)->update(['plano_propina_id' => $planoA->id]);
        $antes = $this->fotografia();

        $this->assertRecusa('plano', 'outro ano lectivo', fn () => app(AlterarPrecoPlanoAction::class)->executar(
            $planoA->fresh(),
            $this->pedidoAlteracao('2027-02', 3_000_000, self::APLICAR, 'Motivo', $impressao),
        ));
        $this->assertSame($antes, $this->fotografia(), 'Plano, propinas, alterações e ajustes inalterados.');
    }

    public function test_8_o_plano_novo_herda_o_ano_do_plano_alterado(): void
    {
        ['planoA' => $planoA, 'planoB' => $planoB] = $this->cenarioDoisAnos();

        $this->assertSame($planoA->ano_lectivo_id, $this->alterarPreco($planoA, '2027-02', 3_000_000, self::APLICAR)->planoNovo->ano_lectivo_id);
        $this->assertSame($planoB->ano_lectivo_id, $this->alterarPreco($planoB, '2028-01', 3_000_000, self::APLICAR)->planoNovo->ano_lectivo_id);
    }
```

- [ ] **Step 2: Correr**

Run: `php artisan test Modules/Financeiro/tests/Feature/AlterarPrecoPlanoIsolamentoAnoLectivoTest.php`
Expected: PASS (11 testes: 6 da Task 7 + 5 aqui). Se algum falhar, corrigir o código de produção (nunca o teste) e documentar.

- [ ] **Step 3: Stage**

```bash
git add Modules/Financeiro/tests/Feature/AlterarPrecoPlanoIsolamentoAnoLectivoTest.php
```

---

### Task 11: Geração depois de uma alteração de preço + "única porta de entrada" na edição do plano

**Files:**
- Modify: `Modules/Financeiro/app/Actions/AtualizarPlanoPropinaAction.php` (texto de `MENSAGEM_VALOR`)
- Modify: `Modules/Financeiro/tests/Feature/PlanoPropinaComPropinasTest.php` (+ 1 caso)
- Test: `Modules/Financeiro/tests/Feature/GeracaoDepoisDaAlteracaoPrecoTest.php`

**Interfaces:**
- Consumes: T8 (`alterarPreco()`), `GerarPropinasService::gerar(PedidoGeracaoPropinasDTO)`, `PedidoGeracaoPropinasDTO(ano_lectivo_id, ambito, origem, alvo_id)`, `AmbitoGeracaoPropinas::TURMA`, `OrigemGeracaoPropina::MANUAL`, `ResultadoGeracaoPropinasDTO::totais()['criadas']`.
- Produces: prova de que (a) depois de MANTER, as matrículas já geradas ficam ao valor antigo e as novas nascem ao novo no plano novo, sem duplicar meses; (b) depois de APLICAR, a geração não recria os meses reassociados; (c) in loco, as novas nascem ao valor novo; (d) regerar é idempotente. `AtualizarPlanoPropinaAction::MENSAGEM_VALOR` passa a apontar «Alterar preço».

- [ ] **Step 1: Escrever os testes que falham**

`Modules/Financeiro/tests/Feature/GeracaoDepoisDaAlteracaoPrecoTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Financeiro\DTO\PedidoGeracaoPropinasDTO;
use Modules\Financeiro\DTO\ResultadoGeracaoPropinasDTO;
use Modules\Financeiro\Enums\AmbitoGeracaoPropinas;
use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Financeiro\Enums\OrigemGeracaoPropina;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\GerarPropinasService;
use Modules\Financeiro\Tests\Concerns\ComAlteracaoPreco;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Matricula\Models\Matricula;
use Modules\Turma\Models\Turma;
use Tests\TestCase;

class GeracaoDepoisDaAlteracaoPrecoTest extends TestCase
{
    use ComAlteracaoPreco;
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

    private function gerar(Turma $turma): ResultadoGeracaoPropinasDTO
    {
        return app(GerarPropinasService::class)->gerar(new PedidoGeracaoPropinasDTO(
            ano_lectivo_id: (int) $turma->ano_lectivo_id,
            ambito: AmbitoGeracaoPropinas::TURMA,
            origem: OrigemGeracaoPropina::MANUAL,
            alvo_id: (int) $turma->id,
        ));
    }

    /** @return list<array{0: string, 1: int, 2: int, 3: int}> [periodo_inicio, plano, ordem, valor] por período */
    private function propinasDe(Matricula $matricula): array
    {
        return Propina::query()->where('matricula_id', $matricula->id)->orderBy('periodo_inicio')->get()
            ->map(fn (Propina $p) => [$p->periodo_inicio->toDateString(), (int) $p->plano_propina_id, (int) $p->ordem, $p->valor->unidadesMenores()])
            ->all();
    }

    private function planoNovo(): PlanoPropina
    {
        return PlanoPropina::query()->where('nome', 'Propina Mensal (desde 02/2027)')->firstOrFail();
    }

    public function test_depois_de_manter_as_ja_geradas_ficam_e_as_novas_matriculas_nascem_ao_valor_novo(): void
    {
        ['turma' => $turma, 'plano' => $plano, 'matricula' => $antiga] = $this->cenarioPropinas();
        $this->assertSame(10, $this->gerar($turma)->totais()['criadas']);
        $antes = $this->propinasDe($antiga);

        $this->alterarPreco($plano, '2027-02', 3_000_000, ModoAlteracaoPreco::MANTER_GERADAS);
        $nova = $this->matriculaActiva($turma);

        $this->assertSame(10, $this->gerar($turma)->totais()['criadas'], 'Só a nova: a antiga já está coberta (de qualquer plano).');
        $this->assertSame($antes, $this->propinasDe($antiga));

        $novo = $this->planoNovo();
        $daNova = $this->propinasDe($nova);
        $this->assertSame([$plano->id, 5, 2_500_000], array_slice($daNova[4], 1));      // Jan/2027, plano antigo
        $this->assertSame(['2027-02-01', $novo->id, 1, 3_000_000], $daNova[5]);         // Fev/2027, plano novo
        $this->assertSame(['2027-06-01', $novo->id, 5, 3_000_000], $daNova[9]);

        $this->assertSame(0, $this->gerar($turma)->totais()['criadas']);
        $this->assertPropinasCoerentes();
    }

    public function test_depois_de_aplicar_a_geracao_nao_recria_os_meses_reassociados(): void
    {
        ['turma' => $turma, 'plano' => $plano, 'matricula' => $antiga] = $this->cenarioPropinas();
        $this->gerar($turma);

        $this->alterarPreco($plano, '2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS);
        $novo = $this->planoNovo();

        $this->assertSame(0, $this->gerar($turma)->totais()['criadas']);
        $this->assertSame(['2027-02-01', $novo->id, 1, 3_000_000], $this->propinasDe($antiga)[5]);

        $nova = $this->matriculaActiva($turma);
        $this->assertSame(10, $this->gerar($turma)->totais()['criadas']);
        $this->assertSame($this->propinasDe($antiga), $this->propinasDe($nova), 'Antiga (reassociada) e nova (gerada) ficam iguais período a período.');
        $this->assertPropinasCoerentes();
    }

    public function test_depois_de_in_loco_as_novas_matriculas_nascem_ao_valor_novo_no_mesmo_plano(): void
    {
        ['turma' => $turma, 'plano' => $plano] = $this->cenarioPropinas();
        $this->gerar($turma);

        $this->alterarPreco($plano, '2026-09', 3_000_000, ModoAlteracaoPreco::MANTER_GERADAS);
        $nova = $this->matriculaActiva($turma);
        $this->gerar($turma);

        $this->assertSame(array_fill(0, 10, 3_000_000), array_column($this->propinasDe($nova), 3));
        $this->assertSame([$plano->id], array_values(array_unique(array_column($this->propinasDe($nova), 1))));
    }
}
```

Em `Modules/Financeiro/tests/Feature/PlanoPropinaComPropinasTest.php`, acrescentar (com `use Modules\Financeiro\Actions\AtualizarPlanoPropinaAction;` se ainda não existir):
```php
    public function test_a_mensagem_do_valor_aponta_para_a_alteracao_de_preco(): void
    {
        $this->assertStringContainsString('«Alterar preço»', AtualizarPlanoPropinaAction::MENSAGEM_VALOR);
    }
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/GeracaoDepoisDaAlteracaoPrecoTest.php Modules/Financeiro/tests/Feature/PlanoPropinaComPropinasTest.php`
Expected: os três de geração PASSAM (o comportamento resulta das Tasks 5–8 e da geração de F2); FALHA só `test_a_mensagem_do_valor_aponta_para_a_alteracao_de_preco`. Se um de geração falhar, investigar com `superpowers:systematic-debugging` antes de mexer (o mais provável é a memória do resolvedor ou a cobertura "já coberta").

- [ ] **Step 3: Implementar**

Em `Modules/Financeiro/app/Actions/AtualizarPlanoPropinaAction.php`, substituir a constante:
```php
    public const MENSAGEM_VALOR = 'Este plano já tem propinas geradas: o valor só muda em «Alterar preço», onde escolhe como tratar as propinas já geradas.';
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Feature/GeracaoDepoisDaAlteracaoPrecoTest.php Modules/Financeiro/tests/Feature/PlanoPropinaComPropinasTest.php Modules/Financeiro/tests/Feature/GerarPropinasTest.php`
Expected: PASS.

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Actions/AtualizarPlanoPropinaAction.php Modules/Financeiro/tests/Feature/PlanoPropinaComPropinasTest.php Modules/Financeiro/tests/Feature/GeracaoDepoisDaAlteracaoPrecoTest.php
```

---

### Task 12: HTTP — pedidos, rotas, controller e dados da lista de planos (D1, D11)

**Files:**
- Create: `Modules/Financeiro/app/Http/Requests/Concerns/ValidaAlteracaoPreco.php`
- Create: `Modules/Financeiro/app/Http/Requests/PreVisualizarAlteracaoPrecoRequest.php`
- Create: `Modules/Financeiro/app/Http/Requests/AlterarPrecoPlanoRequest.php`
- Modify: `Modules/Financeiro/app/Http/Controllers/PlanoPropinaController.php`
- Modify: `Modules/Financeiro/app/Services/PlanoPropinaConsultaService.php` (+ `inicios_periodo` em cada plano)
- Modify: `Modules/Financeiro/routes/web.php`
- Test: `Modules/Financeiro/tests/Feature/AlterarPrecoPlanoHttpTest.php`

**Interfaces:**
- Consumes: T5 (`AlterarPrecoPlanoDTO::fromRequest`, `AlteracaoPrecoPlanoService::preVisualizar`, `toArray()`), T8 (`AlterarPrecoPlanoAction::executar`), `ValorMonetario`, `ModoAlteracaoPreco::opcoes()`, `RotuloDePeriodo`.
- Produces:
  - `POST /financeiro/configuracao/planos-propina/{plano}/alterar-preco/pre-visualizar` → nome `financeiro.configuracao.planos-propina.alterar-preco.pre-visualizar`, middleware `can:propina.ajustar` + `can:plano-propina.editar`, corpo `{valor, mes_efeito: 'Y-m', modo}`, resposta JSON `{previsualizacao: PreVisualizacaoAlteracaoPrecoDTO::toArray()}` ou 422 JSON.
  - `POST /financeiro/configuracao/planos-propina/{plano}/alterar-preco` → nome `financeiro.configuracao.planos-propina.alterar-preco`, mesmo middleware, corpo `{valor, mes_efeito, modo, motivo, impressao_digital, confirmar_valores_diferentes?}`, redirect back com flash `success` (ou erros de sessão).
  - `PlanoPropinaController::index` passa também `modosAlteracaoPreco` (`ModoAlteracaoPreco::opcoes()`); cada plano da lista ganha `inicios_periodo: list<{valor: 'Y-m', rotulo: string}>` (para o select do mês de efeito).
  - Mensagens: `modo` ausente/inválido → "Escolha como tratar as propinas já geradas."; `motivo` 5–500 (após `trim`).

- [ ] **Step 1: Escrever o teste que falha**

`Modules/Financeiro/tests/Feature/AlterarPrecoPlanoHttpTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Financeiro\Actions\AlterarPrecoPlanoAction;
use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Financeiro\Models\AlteracaoPrecoPlano;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Tests\Concerns\ComAlteracaoPreco;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Modules\Permissao\Enums\Modulo;
use Modules\Permissao\Models\Acao;
use Modules\Permissao\Models\Modulo as ModuloRegistro;
use Modules\Permissao\Models\Role;
use Modules\Permissao\Models\RolePermissao;
use Modules\Usuario\Models\User;
use Tests\TestCase;

class AlterarPrecoPlanoHttpTest extends TestCase
{
    use ComAlteracaoPreco;
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

    private function planoComPropinas(): PlanoPropina
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

        foreach (range(1, 10) as $ordem) {
            $this->propina($matricula, $plano, $ordem);
        }

        return $plano;
    }

    /** Perfil personalizado só com as acções de UM módulo (padrão de PermissoesPorRecursoTest). */
    private function utilizadorSoCom(Modulo $modulo): User
    {
        $role = Role::create(['nome' => 100 + $modulo->value, 'descricao' => 'Só ' . $modulo->slug()]);
        $registo = ModuloRegistro::where('nome', $modulo->value)->firstOrFail();

        foreach (Acao::all() as $acao) {
            RolePermissao::create(['role_id' => $role->id, 'modulo_id' => $registo->id, 'acao_id' => $acao->id]);
        }

        $user = User::create(['name' => 'Teste', 'email' => 'so-' . $modulo->slug() . '@example.com', 'password' => Hash::make('x')]);
        $user->roles()->attach($role->id);

        return $user;
    }

    private function urlPrevia(int $plano): string
    {
        return route('financeiro.configuracao.planos-propina.alterar-preco.pre-visualizar', $plano);
    }

    private function urlConfirmar(int $plano): string
    {
        return route('financeiro.configuracao.planos-propina.alterar-preco', $plano);
    }

    public function test_previsualizar_devolve_o_contrato_json_e_nao_escreve(): void
    {
        $plano = $this->planoComPropinas();
        $this->actingAs($this->adminEscola());
        $antes = $this->fotografia();

        $resposta = $this->postJson($this->urlPrevia($plano->id), ['valor' => '30000', 'mes_efeito' => '2027-02', 'modo' => 1])->assertOk();

        $resposta->assertJsonPath('previsualizacao.modo', 1)
            ->assertJsonPath('previsualizacao.mes_efeito', '2027-02')
            ->assertJsonPath('previsualizacao.valor_novo', 3_000_000)
            ->assertJsonPath('previsualizacao.totais.afectadas', 5)
            ->assertJsonPath('previsualizacao.propinas_de_outros_anos', 0);
        $this->assertSame(64, strlen($resposta->json('previsualizacao.impressao_digital')));
        $this->assertSame($antes, $this->fotografia());
    }

    public function test_sem_modo_nao_ha_opcao_por_omissao(): void
    {
        $plano = $this->planoComPropinas();
        $this->actingAs($this->adminEscola());

        $this->postJson($this->urlPrevia($plano->id), ['valor' => '30000', 'mes_efeito' => '2027-02'])
            ->assertUnprocessable()->assertJsonValidationErrors(['modo' => 'Escolha como tratar as propinas já geradas.']);
        $this->postJson($this->urlPrevia($plano->id), ['valor' => '30000', 'mes_efeito' => '2027-02', 'modo' => 3])
            ->assertUnprocessable()->assertJsonValidationErrors(['modo' => 'Escolha como tratar as propinas já geradas.']);
        $this->postJson($this->urlPrevia($plano->id), ['valor' => '30000', 'mes_efeito' => '02/2027', 'modo' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors(['mes_efeito']);
        $this->postJson($this->urlPrevia($plano->id), ['valor' => '0', 'mes_efeito' => '2027-02', 'modo' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors(['valor']);
    }

    public function test_recusas_do_calculo_chegam_como_422_json(): void
    {
        $plano = $this->planoComPropinas();
        $this->actingAs($this->adminEscola());

        $this->postJson($this->urlPrevia($plano->id), ['valor' => '30000', 'mes_efeito' => '2027-08', 'modo' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors(['mes_efeito']);
        $this->postJson($this->urlPrevia($plano->id), ['valor' => '25000', 'mes_efeito' => '2027-02', 'modo' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors(['valor']);
    }

    public function test_confirmar_altera_regista_o_autor_e_redirecciona_com_mensagem(): void
    {
        $plano = $this->planoComPropinas();
        $admin = $this->adminEscola();
        $this->actingAs($admin);
        $impressao = $this->postJson($this->urlPrevia($plano->id), ['valor' => '30000', 'mes_efeito' => '2027-02', 'modo' => 1])->json('previsualizacao.impressao_digital');

        $this->from(route('financeiro.configuracao.planos-propina.index'))
            ->post($this->urlConfirmar($plano->id), [
                'valor' => '30000', 'mes_efeito' => '2027-02', 'modo' => 1,
                'motivo' => '  Actualização anual  ', 'impressao_digital' => $impressao,
            ])
            ->assertRedirect(route('financeiro.configuracao.planos-propina.index'))
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'Propina Mensal (desde 02/2027)') && str_contains($m, '5 propina(s)'));

        $alteracao = AlteracaoPrecoPlano::query()->sole();
        $this->assertSame([$admin->id, 'Actualização anual', 5], [$alteracao->criado_por, $alteracao->motivo, $alteracao->total_propinas_afectadas]);
    }

    public function test_confirmar_exige_motivo_e_impressao_e_recusa_impressao_desactualizada(): void
    {
        $plano = $this->planoComPropinas();
        $this->actingAs($this->adminEscola());
        $base = ['valor' => '30000', 'mes_efeito' => '2027-02', 'modo' => 1];

        $this->post($this->urlConfirmar($plano->id), $base + ['impressao_digital' => str_repeat('a', 64)])->assertSessionHasErrors('motivo');
        $this->post($this->urlConfirmar($plano->id), $base + ['motivo' => 'abc', 'impressao_digital' => str_repeat('a', 64)])->assertSessionHasErrors('motivo');
        $this->post($this->urlConfirmar($plano->id), $base + ['motivo' => 'Motivo válido'])->assertSessionHasErrors('impressao_digital');
        $this->post($this->urlConfirmar($plano->id), $base + ['motivo' => 'Motivo válido', 'impressao_digital' => str_repeat('a', 64)])
            ->assertSessionHasErrors(['impressao_digital' => AlterarPrecoPlanoAction::MENSAGEM_DESACTUALIZADA]);
        $this->assertSame(0, AlteracaoPrecoPlano::query()->count());
    }

    public function test_exige_as_duas_permissoes(): void
    {
        $plano = $this->planoComPropinas();
        $corpo = ['valor' => '30000', 'mes_efeito' => '2027-02', 'modo' => 1, 'motivo' => 'Motivo', 'impressao_digital' => str_repeat('a', 64)];

        foreach ([$this->professor(), $this->utilizadorSoCom(Modulo::PROPINA), $this->utilizadorSoCom(Modulo::PLANO_PROPINA)] as $utilizador) {
            $this->actingAs($utilizador);
            $this->postJson($this->urlPrevia($plano->id), $corpo)->assertForbidden();
            $this->post($this->urlConfirmar($plano->id), $corpo)->assertForbidden();
        }

        $this->assertSame(0, AlteracaoPrecoPlano::query()->count());
    }

    public function test_plano_de_outro_tenant_da_404(): void
    {
        $outro = $this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost');
        $planoDela = $this->noTenant($outro, fn () => $this->planoComPropinas()->id);
        $this->actingAs($this->adminEscola());

        $this->postJson($this->urlPrevia($planoDela), ['valor' => '30000', 'mes_efeito' => '2027-02', 'modo' => 1])->assertNotFound();
        $this->post($this->urlConfirmar($planoDela), ['valor' => '30000', 'mes_efeito' => '2027-02', 'modo' => 1, 'motivo' => 'Motivo', 'impressao_digital' => str_repeat('a', 64)])->assertNotFound();
    }

    public function test_a_lista_de_planos_traz_os_modos_e_os_inicios_de_periodo(): void
    {
        $plano = $this->planoComPropinas();
        $this->actingAs($this->adminEscola());

        $this->get(route('financeiro.configuracao.planos-propina.index', ['ano_lectivo_id' => $plano->ano_lectivo_id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('modosAlteracaoPreco', ModoAlteracaoPreco::opcoes())
                ->where('planos.data.0.tem_propinas', true)
                ->has('planos.data.0.inicios_periodo', 10)
                ->where('planos.data.0.inicios_periodo.0', ['valor' => '2026-09', 'rotulo' => 'Set/2026'])
                ->where('planos.data.0.inicios_periodo.5', ['valor' => '2027-02', 'rotulo' => 'Fev/2027']));
    }
}
```
(Perfis personalizados com `nome` 100 + valor do módulo, para dois perfis no mesmo teste não colidirem.)

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/AlterarPrecoPlanoHttpTest.php`
Expected: FAIL (rotas inexistentes: `Route [financeiro.configuracao.planos-propina.alterar-preco.pre-visualizar] not defined`).

- [ ] **Step 3: Implementar**

`Modules/Financeiro/app/Http/Requests/Concerns/ValidaAlteracaoPreco.php`:
```php
<?php

namespace Modules\Financeiro\Http\Requests\Concerns;

use Illuminate\Validation\Rule;
use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Financeiro\Rules\ValorMonetario;

/**
 * Regras partilhadas pela pré-visualização e pela confirmação da alteração de preço (spec §14). A escolha não tem
 * valor por omissão; as duas permissões são exigidas (Q12).
 */
trait ValidaAlteracaoPreco
{
    public function authorize(): bool
    {
        $utilizador = $this->user();

        return $utilizador !== null && $utilizador->can('propina.ajustar') && $utilizador->can('plano-propina.editar');
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('motivo'))) {
            $this->merge(['motivo' => trim($this->input('motivo'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function regrasDoPedido(): array
    {
        return [
            'valor' => ['required', new ValorMonetario(false)],
            'mes_efeito' => ['required', 'string', 'date_format:Y-m'],
            'modo' => ['required', Rule::enum(ModoAlteracaoPreco::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function mensagensDoPedido(): array
    {
        return [
            'valor.required' => 'Indique o novo valor.',
            'mes_efeito.required' => 'Indique o mês de efeito.',
            'mes_efeito.date_format' => 'O mês de efeito é inválido.',
            'modo.required' => 'Escolha como tratar as propinas já geradas.',
            'modo.enum' => 'Escolha como tratar as propinas já geradas.',
        ];
    }
}
```

`Modules/Financeiro/app/Http/Requests/PreVisualizarAlteracaoPrecoRequest.php`:
```php
<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Modules\Financeiro\Http\Requests\Concerns\ValidaAlteracaoPreco;

class PreVisualizarAlteracaoPrecoRequest extends BaseRequest
{
    use ValidaAlteracaoPreco;

    public function rules(): array
    {
        return $this->regrasDoPedido();
    }

    public function messages(): array
    {
        return $this->mensagensDoPedido();
    }
}
```

`Modules/Financeiro/app/Http/Requests/AlterarPrecoPlanoRequest.php`:
```php
<?php

namespace Modules\Financeiro\Http\Requests;

use App\Http\Requests\BaseRequest;
use Modules\Financeiro\Http\Requests\Concerns\ValidaAlteracaoPreco;

class AlterarPrecoPlanoRequest extends BaseRequest
{
    use ValidaAlteracaoPreco;

    public const MOTIVO_MINIMO = 5;

    public const MOTIVO_MAXIMO = 500;

    public function rules(): array
    {
        return array_merge($this->regrasDoPedido(), [
            'motivo' => ['required', 'string', 'min:' . self::MOTIVO_MINIMO, 'max:' . self::MOTIVO_MAXIMO],
            'impressao_digital' => ['required', 'string', 'size:64'],
            'confirmar_valores_diferentes' => ['nullable', 'boolean'],
        ]);
    }

    public function messages(): array
    {
        return array_merge($this->mensagensDoPedido(), [
            'motivo.required' => 'Indique o motivo da alteração de preço.',
            'motivo.min' => 'O motivo tem de ter pelo menos ' . self::MOTIVO_MINIMO . ' caracteres.',
            'motivo.max' => 'O motivo não pode ter mais de ' . self::MOTIVO_MAXIMO . ' caracteres.',
            'impressao_digital.required' => 'Faça a pré-visualização antes de confirmar.',
            'impressao_digital.size' => 'Faça a pré-visualização antes de confirmar.',
        ]);
    }
}
```
(Um `impressao_digital` ausente dá a mensagem "Faça a pré-visualização antes de confirmar." no campo `impressao_digital` — o teste só verifica o campo.)

Em `Modules/Financeiro/app/Http/Controllers/PlanoPropinaController.php`:
- `use` novos (ordem alfabética): `Illuminate\Http\JsonResponse`, `Modules\Financeiro\Actions\AlterarPrecoPlanoAction`, `Modules\Financeiro\DTO\AlterarPrecoPlanoDTO`, `Modules\Financeiro\Enums\ModoAlteracaoPreco`, `Modules\Financeiro\Http\Requests\AlterarPrecoPlanoRequest`, `Modules\Financeiro\Http\Requests\PreVisualizarAlteracaoPrecoRequest`, `Modules\Financeiro\Services\AlteracaoPrecoPlanoService`, `Modules\Financeiro\Support\RotuloDePeriodo`.
- Construtor: acrescentar `private AlteracaoPrecoPlanoService $alteracaoPreco,` e `private AlterarPrecoPlanoAction $alterarPrecoAction,`.
- Em `index`, acrescentar ao array do `Inertia::render`: `'modosAlteracaoPreco' => ModoAlteracaoPreco::opcoes(),`.
- Acrescentar:
```php
    public function preVisualizarAlteracaoPreco(PreVisualizarAlteracaoPrecoRequest $request, PlanoPropina $plano): JsonResponse
    {
        $this->authorize('propina.ajustar');
        $this->authorize('plano-propina.editar');

        return response()->json([
            'previsualizacao' => $this->alteracaoPreco->preVisualizar($plano, AlterarPrecoPlanoDTO::fromRequest($request))->toArray(),
        ]);
    }

    public function alterarPreco(AlterarPrecoPlanoRequest $request, PlanoPropina $plano)
    {
        $this->authorize('propina.ajustar');
        $this->authorize('plano-propina.editar');

        $alteracao = $this->alterarPrecoAction->executar($plano, AlterarPrecoPlanoDTO::fromRequest($request));
        $mes = RotuloDePeriodo::competencia($alteracao->mes_efeito->year, $alteracao->mes_efeito->month);
        $propinas = "{$alteracao->total_propinas_afectadas} propina(s) reajustada(s).";

        return redirect()->back()->with('success', $alteracao->planoNovo === null
            ? "Preço do plano alterado. {$propinas}"
            : "Preço alterado a partir de {$mes}: criado o plano «{$alteracao->planoNovo->nome}». {$propinas}");
    }
```

Em `Modules/Financeiro/app/Services/PlanoPropinaConsultaService.php`: `use Modules\Financeiro\Support\RotuloDePeriodo;` e, no `through(...)` de `listar`, a seguir a `'tem_propinas' => …`:
```php
                // Meses de efeito possíveis numa alteração de preço (spec §14): o início de cada período.
                'inicios_periodo' => array_map(fn (array $periodo) => [
                    'valor' => substr($periodo['inicio'], 0, 7),
                    'rotulo' => RotuloDePeriodo::competencia((int) substr($periodo['inicio'], 0, 4), (int) substr($periodo['inicio'], 5, 2)),
                ], $plano->periodos()),
```

Em `Modules/Financeiro/routes/web.php`, no grupo `planos-propina`, a seguir à rota `alterar-estado`:
```php
        Route::post('/{plano}/alterar-preco/pre-visualizar', [PlanoPropinaController::class, 'preVisualizarAlteracaoPreco'])
            ->middleware(['can:propina.ajustar', 'can:plano-propina.editar'])->name('alterar-preco.pre-visualizar');
        Route::post('/{plano}/alterar-preco', [PlanoPropinaController::class, 'alterarPreco'])
            ->middleware(['can:propina.ajustar', 'can:plano-propina.editar'])->name('alterar-preco');
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Feature/AlterarPrecoPlanoHttpTest.php Modules/Financeiro/tests/Feature/PlanoPropinaTest.php Modules/Financeiro/tests/Feature/PermissoesPlanoPropinaTest.php tests/Feature/RotasEscritaAutorizadasTest.php`
Expected: PASS (as rotas novas têm `can:` reconhecidas e FormRequest com `authorize`; a lista de planos continua igual com as chaves novas). Correr também o teste que verifica abilities reconhecidas nas rotas: `php artisan test --filter=RotasPermissaoReconhecida`.

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Http/Requests/Concerns/ValidaAlteracaoPreco.php Modules/Financeiro/app/Http/Requests/PreVisualizarAlteracaoPrecoRequest.php Modules/Financeiro/app/Http/Requests/AlterarPrecoPlanoRequest.php Modules/Financeiro/app/Http/Controllers/PlanoPropinaController.php Modules/Financeiro/app/Services/PlanoPropinaConsultaService.php Modules/Financeiro/routes/web.php Modules/Financeiro/tests/Feature/AlterarPrecoPlanoHttpTest.php
```

---

### Task 13: Detalhe da propina — ajustes de preço (spec §10, §14; M3)

**Files:**
- Modify: `Modules/Financeiro/app/Services/PropinaConsultaService.php` (`detalhe()` → `ajustes`)
- Test: `Modules/Financeiro/tests/Feature/PropinaDetalheAjustesTest.php`

**Interfaces:**
- Consumes: T1 (`Propina::ajustes()`, `PropinaAjuste` relações), T8 (`alterarPreco()`), `RelogioDoTenant::fuso()`, helper privado `instante(?CarbonInterface, string): ?string` já existente no serviço.
- Produces: `detalhe()['ajustes']` = `list<{id, em ('Y-m-d H:i' no fuso da escola), por, modo_descricao, mes_efeito ('Fev/2027'), valor_anterior, valor_novo, valor_pago_no_momento, plano_anterior, plano_novo, motivo}>`, do mais antigo para o mais recente (consumido pela Task 14).

- [ ] **Step 1: Escrever o teste que falha**

`Modules/Financeiro/tests/Feature/PropinaDetalheAjustesTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Financeiro\Tests\Concerns\ComAlteracaoPreco;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComUtilizadoresFinanceiro;
use Modules\Permissao\Database\Seeders\PermissaoDatabaseSeeder;
use Tests\TestCase;

class PropinaDetalheAjustesTest extends TestCase
{
    use ComAlteracaoPreco;
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

    public function test_o_detalhe_lista_os_ajustes_por_ordem_com_autor_planos_e_motivo(): void
    {
        $admin = $this->adminEscola();
        $this->actingAs($admin);
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $fevereiro = $this->propina($matricula, $plano, 6);
        $primeira = $this->alterarPreco($plano, '2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS, 'Subida anual');
        $this->alterarPreco($primeira->planoNovo, '2027-02', 2_800_000, ModoAlteracaoPreco::APLICAR_GERADAS, 'Correcção');

        $this->get(route('financeiro.propinas.show', $fevereiro->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('propina.ajustes', 2)
                ->where('propina.valor', 2_800_000)
                ->where('propina.valor_original', 2_500_000)
                ->where('propina.ajustes.0.valor_anterior', 2_500_000)
                ->where('propina.ajustes.0.valor_novo', 3_000_000)
                ->where('propina.ajustes.0.valor_pago_no_momento', 0)
                ->where('propina.ajustes.0.plano_anterior', 'Propina Mensal')
                ->where('propina.ajustes.0.plano_novo', 'Propina Mensal (desde 02/2027)')
                ->where('propina.ajustes.0.modo_descricao', 'Aplicar às propinas já geradas')
                ->where('propina.ajustes.0.mes_efeito', 'Fev/2027')
                ->where('propina.ajustes.0.motivo', 'Subida anual')
                ->where('propina.ajustes.0.por', $admin->name)
                ->where('propina.ajustes.0.em', '2026-10-16 11:00')
                ->where('propina.ajustes.1.valor_anterior', 3_000_000)
                ->where('propina.ajustes.1.valor_novo', 2_800_000)
                ->where('propina.ajustes.1.plano_novo', null)
                ->where('propina.ajustes.1.motivo', 'Correcção'));
    }
}
```
(`em` = 10:00 UTC no fuso por omissão da escola `Africa/Luanda` = 11:00, como em `PropinaDetalheTest`.)

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Feature/PropinaDetalheAjustesTest.php`
Expected: FAIL (`propina.ajustes` vazio).

- [ ] **Step 3: Implementar**

Em `Modules/Financeiro/app/Services/PropinaConsultaService.php`:
- `use Modules\Financeiro\Models\PropinaAjuste;`
- No docblock de `detalhe`, trocar "Pagamentos (Pagamentos-A) e ajustes (F4) ainda não existem: listas vazias." por "Pagamentos (Pagamentos-A) ainda não existem: lista vazia. Ajustes de preço (F4) do mais antigo para o mais recente."
- Substituir `'ajustes' => [],` por:
```php
            'ajustes' => $propina->ajustes()->with(['alteracao', 'criadoPor', 'planoAnterior', 'planoNovo'])->get()
                ->map(fn (PropinaAjuste $ajuste) => [
                    'id' => $ajuste->id,
                    'em' => $this->instante($ajuste->created_at, $fuso),
                    'por' => $ajuste->criadoPor?->name,
                    'modo_descricao' => $ajuste->alteracao?->modo_descricao,
                    'mes_efeito' => $ajuste->alteracao?->mes_efeito === null ? null
                        : RotuloDePeriodo::competencia($ajuste->alteracao->mes_efeito->year, $ajuste->alteracao->mes_efeito->month),
                    'valor_anterior' => $ajuste->valor_anterior->unidadesMenores(),
                    'valor_novo' => $ajuste->valor_novo->unidadesMenores(),
                    'valor_pago_no_momento' => $ajuste->valor_pago_no_momento->unidadesMenores(),
                    'plano_anterior' => $ajuste->planoAnterior?->nome,
                    'plano_novo' => $ajuste->planoNovo?->nome,
                    'motivo' => $ajuste->motivo,
                ])->values()->all(),
```

- [ ] **Step 4: Correr e ver passar**

Run: `php artisan test Modules/Financeiro/tests/Feature/PropinaDetalheAjustesTest.php Modules/Financeiro/tests/Feature/PropinaDetalheTest.php`
Expected: PASS (os testes de F3 continuam a ver `ajustes` vazio nas propinas sem ajustes).

- [ ] **Step 5: Stage**

```bash
git add Modules/Financeiro/app/Services/PropinaConsultaService.php Modules/Financeiro/tests/Feature/PropinaDetalheAjustesTest.php
```

---

### Task 14: Frontend — modal "Alterar preço" em dois passos, acção na lista de planos, ajustes no detalhe

**Files:**
- Create: `Modules/Financeiro/resources/js/Components/PlanosPropina/AlterarPrecoPlanoModal.vue`
- Modify: `Modules/Financeiro/resources/js/Pages/PlanosPropina/Index.vue`
- Modify: `Modules/Financeiro/resources/js/Pages/Propinas/Show.vue`
- Modify: `resources/js/Components/Shared/acoesLista.js` (+ ícone `alterar-preco`, reaproveita `ki-dollar` com 3 paths, como o menu Financeiro)
- Test: `Modules/Financeiro/tests/Unit/AlterarPrecoPlanoVueTest.php`

**Interfaces:**
- Consumes: T12 (rotas, `modosAlteracaoPreco`, `inicios_periodo`, `tem_propinas`), T13 (`propina.ajustes`), `formatarDinheiro`, `SelectSolid`, `toast`, `can()`.
- Produces: o modal emite `confirmar` com `{valor, mes_efeito, modo, motivo, impressao_digital, confirmar_valores_diferentes}`; quando o servidor devolve erro em `impressao_digital`, o modal refaz a pré-visualização sozinho e avisa (spec §14 "a pré-visualização refeita").

- [ ] **Step 1: Escrever o teste (estático, padrão `DetalhePropinaVueTest`) que falha**

`Modules/Financeiro/tests/Unit/AlterarPrecoPlanoVueTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Não há testes de frontend: lê os ficheiros Vue (sem comentários) e prova as decisões de F4 que vivem na UI — a
 * escolha nasce SEM opção marcada, a pré-visualização é obrigatória antes de confirmar, a confirmação leva a
 * impressão digital e o motivo, e uma impressão desactualizada refaz a pré-visualização.
 */
class AlterarPrecoPlanoVueTest extends TestCase
{
    private function vue(string $relativo): string
    {
        return preg_replace('/<!--.*?-->/s', '', file_get_contents(dirname(__DIR__, 2) . '/resources/js/' . $relativo));
    }

    public function test_a_escolha_nao_tem_opcao_pre_seleccionada_e_bloqueia_a_pre_visualizacao(): void
    {
        $modal = $this->vue('Components/PlanosPropina/AlterarPrecoPlanoModal.vue');

        $this->assertStringContainsString('modo: null,', $modal);
        $this->assertStringNotContainsString('checked', $modal);
        $this->assertStringContainsString('v-model="form.modo"', $modal);
        $this->assertStringContainsString('form.modo !== null', $modal);
        $this->assertStringContainsString(':disabled="!podePreVisualizar || aCalcular"', $modal);
    }

    public function test_confirma_so_depois_da_pre_visualizacao_com_impressao_e_motivo(): void
    {
        $modal = $this->vue('Components/PlanosPropina/AlterarPrecoPlanoModal.vue');

        $this->assertStringContainsString('/alterar-preco/pre-visualizar`', $modal);
        $this->assertStringContainsString('impressao_digital: previa.value.impressao_digital', $modal);
        $this->assertStringContainsString('maxlength="500"', $modal);
        $this->assertStringContainsString('form.motivo.trim().length < 5', $modal);
        $this->assertStringContainsString('{{ previa.garantia_outros_anos }}', $modal);
        $this->assertStringContainsString('v-if="previa.exige_confirmacao_valores_diferentes"', $modal);
        $this->assertStringContainsString('v-model="form.confirmar_valores_diferentes"', $modal);
    }

    public function test_uma_impressao_desactualizada_refaz_a_pre_visualizacao(): void
    {
        $modal = $this->vue('Components/PlanosPropina/AlterarPrecoPlanoModal.vue');

        $this->assertSame(1, preg_match('/watch\(\(\) => props\.errors\.impressao_digital,.*?preVisualizar\(\)/s', $modal));
    }

    public function test_a_lista_de_planos_so_oferece_a_accao_com_propinas_e_as_duas_permissoes(): void
    {
        $index = $this->vue('Pages/PlanosPropina/Index.vue');

        $this->assertStringContainsString('v-if="plano.tem_propinas && can(\'propina.ajustar\') && can(\'plano-propina.editar\')"', $index);
        $this->assertStringContainsString('router.post(`${BASE}/${planoPreco.value.id}/alterar-preco`', $index);
        $this->assertStringContainsString('<AlterarPrecoPlanoModal', $index);
    }

    public function test_o_detalhe_lista_os_ajustes(): void
    {
        $show = $this->vue('Pages/Propinas/Show.vue');

        $this->assertStringContainsString('v-for="ajuste in propina.ajustes"', $show);
        $this->assertStringContainsString('{{ ajuste.motivo }}', $show);
    }
}
```

- [ ] **Step 2: Correr e ver falhar**

Run: `php artisan test Modules/Financeiro/tests/Unit/AlterarPrecoPlanoVueTest.php`
Expected: FAIL (o modal não existe).

- [ ] **Step 3: Implementar**

Em `resources/js/Components/Shared/acoesLista.js`, a seguir à entrada `editar`:
```js
    'alterar-preco': { icone: 'ki-dollar', paths: 3, cor: 'warning', texto: 'Alterar preço' },
```

`Modules/Financeiro/resources/js/Components/PlanosPropina/AlterarPrecoPlanoModal.vue`:
```vue
<script setup>
import { computed, reactive, ref, watch } from 'vue';
import axios from 'axios';
import { toast } from 'vue-sonner';
import SelectSolid from '@/Components/Shared/SelectSolid.vue';
import { formatarDinheiro } from '../../Support/dinheiro';

const props = defineProps({
    show: { type: Boolean, default: false },
    plano: { type: Object, default: null }, // linha da lista: id, nome, valor, ano_lectivo_nome, inicios_periodo
    modos: { type: Array, required: true }, // [{ value, label }] — nenhum marcado por omissão (spec §14)
    moeda: { type: Object, required: true },
    processing: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['confirmar', 'cancelar']);

const BASE = '/financeiro/configuracao/planos-propina';
const AJUDA_MODO = {
    1: 'As propinas em aberto ou parcialmente pagas a partir do mês de efeito passam ao novo valor (o já recebido mantém-se). Pagas, canceladas e anuladas não mudam.',
    2: 'Nenhuma propina já gerada muda; o novo valor aplica-se só às que ainda não foram geradas.',
};

const vazio = () => ({
    valor: '',
    mes_efeito: '',
    modo: null,
    motivo: '',
    confirmar_valores_diferentes: false,
});

const form = reactive(vazio());
const previa = ref(null);
const aCalcular = ref(false);
const errosPrevia = ref({});

watch(() => props.show, (show) => {
    if (!show) return;
    Object.assign(form, vazio());
    previa.value = null;
    errosPrevia.value = {};
});

// Mudar um parâmetro invalida a pré-visualização: nunca se confirma algo diferente do que foi visto.
watch(() => [form.valor, form.mes_efeito, form.modo], () => {
    previa.value = null;
    form.confirmar_valores_diferentes = false;
});

// Spec §14: se os dados mudaram entre a pré-visualização e a confirmação, a pré-visualização é refeita.
watch(() => props.errors.impressao_digital, (mensagem) => {
    if (!mensagem || !props.show) return;
    toast.warning(mensagem);
    preVisualizar();
});

const opcoesMes = computed(() => (props.plano?.inicios_periodo ?? []).map((m) => ({ value: m.valor, label: m.rotulo })));
const podePreVisualizar = computed(() => form.modo !== null && form.valor !== '' && form.mes_efeito !== '');
const podeConfirmar = computed(() => previa.value !== null
    && form.motivo.trim().length >= 5
    && (!previa.value.exige_confirmacao_valores_diferentes || form.confirmar_valores_diferentes));
const erro = (campo) => errosPrevia.value[campo] ?? props.errors[campo];
const dinheiro = (valor) => formatarDinheiro(valor, props.moeda);

async function preVisualizar() {
    if (!props.plano || !podePreVisualizar.value) return;
    aCalcular.value = true;
    errosPrevia.value = {};

    try {
        const { data } = await axios.post(`${BASE}/${props.plano.id}/alterar-preco/pre-visualizar`, {
            valor: form.valor,
            mes_efeito: form.mes_efeito,
            modo: form.modo,
        });
        previa.value = data.previsualizacao;
    } catch (falha) {
        previa.value = null;
        const estado = falha.response?.status;

        if (estado === 422) {
            errosPrevia.value = Object.fromEntries(Object.entries(falha.response.data.errors ?? {}).map(([campo, mensagens]) => [campo, mensagens[0]]));
            toast.error(Object.values(errosPrevia.value)[0] ?? 'Verifique os dados.');
        } else if (estado === 403) {
            toast.error('Não tem permissão para alterar o preço deste plano.');
        } else {
            toast.error('Não foi possível pré-visualizar. Tente de novo.');
        }
    } finally {
        aCalcular.value = false;
    }
}

function confirmar() {
    if (!podeConfirmar.value || props.processing) return;

    emit('confirmar', {
        valor: form.valor,
        mes_efeito: form.mes_efeito,
        modo: form.modo,
        motivo: form.motivo.trim(),
        impressao_digital: previa.value.impressao_digital,
        confirmar_valores_diferentes: form.confirmar_valores_diferentes,
    });
}
</script>

<template>
    <div v-if="show && plano" class="modal d-block" style="background: rgba(0,0,0,0.5); overflow-y: auto;" @click.self="!processing && emit('cancelar')">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content p-6">
                <h3 class="mb-1">Alterar preço — {{ plano.nome }}</h3>
                <p class="text-muted fs-7 mb-5">
                    Ano lectivo {{ plano.ano_lectivo_nome }} · valor actual {{ dinheiro(plano.valor) }} por período.
                    Este plano já tem propinas geradas: escolha como as tratar.
                </p>

                <form v-if="!previa" @submit.prevent="preVisualizar">
                    <div class="row">
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">Novo valor por período</label>
                            <input v-model="form.valor" type="text" inputmode="decimal" class="form-control form-control-solid" />
                            <div v-if="erro('valor')" class="text-danger fs-7 mt-1">{{ erro('valor') }}</div>
                        </div>
                        <div class="col-md-6 fv-row mb-7">
                            <label class="required fw-semibold fs-6 mb-2">A partir de (mês de efeito)</label>
                            <SelectSolid v-model="form.mes_efeito" :options="opcoesMes" />
                            <div class="text-muted fs-7 mt-1">Só o início de um período deste plano. Os meses anteriores mantêm o valor actual.</div>
                            <div v-if="erro('mes_efeito')" class="text-danger fs-7 mt-1">{{ erro('mes_efeito') }}</div>
                        </div>
                    </div>

                    <label class="required fw-semibold fs-6 mb-2">Propinas já geradas</label>
                    <div class="row g-4 mb-2">
                        <div v-for="modo in modos" :key="modo.value" class="col-md-6">
                            <label :class="['btn btn-outline btn-outline-dashed btn-active-light-primary d-flex text-start p-5 h-100', { active: form.modo === modo.value }]">
                                <input v-model="form.modo" class="form-check-input me-4 mt-1" type="radio" name="modo_alteracao_preco" :value="modo.value" />
                                <span>
                                    <span class="fw-bold fs-6 d-block mb-1">{{ modo.label }}</span>
                                    <span class="text-muted fs-7">{{ AJUDA_MODO[modo.value] }}</span>
                                </span>
                            </label>
                        </div>
                    </div>
                    <div v-if="erro('modo')" class="text-danger fs-7 mb-3">{{ erro('modo') }}</div>
                    <div v-if="erro('plano')" class="alert alert-danger py-3 fs-7">{{ erro('plano') }}</div>

                    <div class="text-end mt-5">
                        <button type="button" class="btn btn-light me-2" @click="emit('cancelar')">Cancelar</button>
                        <button type="submit" class="btn btn-primary" :disabled="!podePreVisualizar || aCalcular">
                            {{ aCalcular ? 'A calcular…' : 'Pré-visualizar' }}
                        </button>
                    </div>
                </form>

                <template v-else>
                    <div class="alert alert-warning py-3 fs-7 mb-5">
                        Pré-visualização: <strong>nada foi gravado</strong>. Ao confirmar, tudo é verificado de novo; se algo mudou entretanto, a pré-visualização é refeita.
                    </div>

                    <div class="bg-body-secondary rounded p-4 mb-5 fs-7">
                        <div><strong>Escolha:</strong> {{ previa.modo_descricao }} · <strong>a partir de</strong> {{ previa.mes_efeito_rotulo }}</div>
                        <div><strong>Ano lectivo abrangido:</strong> {{ previa.ano_lectivo.nome }} (id {{ previa.ano_lectivo.id }})</div>
                        <div class="text-success">{{ previa.garantia_outros_anos }} ({{ previa.propinas_de_outros_anos }} de outros anos.)</div>
                        <div><strong>Valor:</strong> {{ dinheiro(previa.valor_anterior) }} → {{ dinheiro(previa.valor_novo) }} por período</div>
                    </div>

                    <h4 class="fs-6 fw-bold mb-3">Planos resultantes</h4>
                    <ul class="fs-7 mb-5">
                        <li v-for="p in previa.planos" :key="p.papel">
                            <strong>{{ p.nome }}</strong> — {{ dinheiro(p.valor) }} · {{ p.periodos.map((x) => x.periodo).join(', ') }}
                            <span v-if="p.papel === 'novo'" class="badge badge-light-primary ms-1">novo</span>
                        </li>
                    </ul>

                    <div v-if="previa.aviso_valores_diferentes" class="alert alert-danger py-3 fs-7 mb-5">
                        {{ previa.aviso_valores_diferentes.mensagem }}
                        <label v-if="previa.exige_confirmacao_valores_diferentes" class="form-check form-check-custom form-check-solid mt-3">
                            <input v-model="form.confirmar_valores_diferentes" class="form-check-input" type="checkbox" />
                            <span class="form-check-label">Compreendo que alunos da mesma turma vão pagar valores diferentes.</span>
                        </label>
                        <div v-if="errors.confirmar_valores_diferentes" class="text-danger mt-1">{{ errors.confirmar_valores_diferentes }}</div>
                    </div>

                    <h4 class="fs-6 fw-bold mb-3">Propinas afectadas ({{ previa.totais.afectadas }})</h4>
                    <div v-if="previa.afectadas.length" class="table-responsive mb-5">
                        <table class="table table-row-dashed align-middle fs-7 gy-2">
                            <thead>
                                <tr class="text-muted fw-bold text-uppercase">
                                    <th>Aluno</th><th>Matrícula</th><th>Período</th><th>Estado</th>
                                    <th class="text-end">Valor</th><th class="text-end">Recebido</th><th class="text-end">Saldo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="a in previa.afectadas" :key="a.propina_id">
                                    <td>{{ a.aluno_nome }}</td>
                                    <td>{{ a.matricula_numero }}</td>
                                    <td>{{ a.periodo }}</td>
                                    <td>{{ a.estado_descricao }}</td>
                                    <td class="text-end">{{ dinheiro(a.valor_antes) }} → {{ dinheiro(a.valor_depois) }}</td>
                                    <td class="text-end">{{ dinheiro(a.valor_pago) }}</td>
                                    <td class="text-end">
                                        {{ dinheiro(a.saldo_antes) }} → {{ dinheiro(a.saldo_depois) }}
                                        <span v-if="a.passa_a_paga" class="badge badge-light-warning ms-1" :title="`Recebido acima do novo valor: ${dinheiro(a.excesso)} (sem devolução).`">Passa a Paga</span>
                                    </td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="fw-bold">
                                    <td colspan="4">Total</td>
                                    <td class="text-end">{{ dinheiro(previa.totais.valor_antes) }} → {{ dinheiro(previa.totais.valor_depois) }}</td>
                                    <td class="text-end">{{ dinheiro(previa.totais.recebido) }}</td>
                                    <td class="text-end">{{ dinheiro(previa.totais.saldo_antes) }} → {{ dinheiro(previa.totais.saldo_depois) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <p v-else class="text-muted fs-7 mb-5">Nenhuma propina já gerada muda.</p>

                    <h4 class="fs-6 fw-bold mb-3">Ficam inalteradas ({{ previa.totais.inalteradas }} a partir do mês de efeito; {{ previa.totais.anteriores_ao_mes_efeito }} anteriores)</h4>
                    <div v-if="previa.inalteradas.length" class="table-responsive mb-5">
                        <table class="table table-row-dashed align-middle fs-7 gy-2">
                            <thead>
                                <tr class="text-muted fw-bold text-uppercase"><th>Aluno</th><th>Período</th><th>Estado</th><th class="text-end">Valor</th><th>Porquê</th></tr>
                            </thead>
                            <tbody>
                                <tr v-for="i in previa.inalteradas" :key="i.propina_id">
                                    <td>{{ i.aluno_nome }}</td>
                                    <td>{{ i.periodo }}</td>
                                    <td>{{ i.estado_descricao }}</td>
                                    <td class="text-end">{{ dinheiro(i.valor_antes) }}</td>
                                    <td>{{ i.motivo }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="fv-row mb-5">
                        <label class="required fw-semibold fs-6 mb-2">Motivo</label>
                        <textarea v-model="form.motivo" class="form-control form-control-solid" rows="3" maxlength="500"></textarea>
                        <div class="text-muted fs-7 mt-1">Fica registado com o seu nome, no plano e em cada propina alterada.</div>
                        <div v-if="errors.motivo" class="text-danger fs-7 mt-1">{{ errors.motivo }}</div>
                    </div>

                    <div class="text-end">
                        <button type="button" class="btn btn-light me-2" :disabled="processing" @click="previa = null">Voltar</button>
                        <button type="button" class="btn btn-primary" :disabled="!podeConfirmar || processing" @click="confirmar">
                            {{ processing ? 'A gravar…' : 'Confirmar alteração de preço' }}
                        </button>
                    </div>
                    <div v-if="form.motivo.trim().length < 5" class="text-muted fs-7 text-end mt-2">O motivo tem de ter pelo menos 5 caracteres.</div>
                </template>
            </div>
        </div>
    </div>
</template>
```

Em `Modules/Financeiro/resources/js/Pages/PlanosPropina/Index.vue`:
1. Import (a seguir a `CopiarPlanosModal`): `import AlterarPrecoPlanoModal from '../../Components/PlanosPropina/AlterarPrecoPlanoModal.vue';`
2. Prop nova: `modosAlteracaoPreco: { type: Array, required: true },`
3. Estado e funções (a seguir ao bloco da cópia):
```js
const precoAberto = ref(false);
const planoPreco = ref(null);
const precoProcessing = ref(false);
const precoErrors = ref({});

function abrirAlteracaoPreco(plano) {
    planoPreco.value = plano;
    precoErrors.value = {};
    precoAberto.value = true;
}

function alterarPreco(payload) {
    precoProcessing.value = true;
    precoErrors.value = {};

    router.post(`${BASE}/${planoPreco.value.id}/alterar-preco`, payload, {
        preserveScroll: true,
        onSuccess: () => {
            toast.success(page.props.flash?.success ?? 'Preço do plano alterado.');
            precoAberto.value = false;
        },
        onError: (erros) => {
            precoErrors.value = erros;
            // A impressão digital desactualizada é tratada pelo modal (refaz a pré-visualização).
            if (!erros.impressao_digital) toast.error(Object.values(erros)[0]);
        },
        onFinish: () => {
            precoProcessing.value = false;
        },
    });
}
```
4. No menu de acções, a seguir ao item "Editar":
```vue
                                    <div v-if="plano.tem_propinas && can('propina.ajustar') && can('plano-propina.editar')" class="menu-item px-3">
                                        <a href="#" class="menu-link px-3" @click.prevent="abrirAlteracaoPreco(plano)">
                                            <AcaoIcone acao="alterar-preco" class="me-2" /> Alterar preço
                                        </a>
                                    </div>
```
5. A seguir a `<CopiarPlanosModal …/>`:
```vue
        <AlterarPrecoPlanoModal
            :show="precoAberto"
            :plano="planoPreco"
            :modos="modosAlteracaoPreco"
            :moeda="moeda"
            :processing="precoProcessing"
            :errors="precoErrors"
            @confirmar="alterarPreco"
            @cancelar="precoAberto = false"
        />
```
6. No badge "Com propinas", trocar o `title` para: `"Já tem propinas geradas: o calendário fica bloqueado e o valor muda em «Alterar preço»."`.

Em `Modules/Financeiro/resources/js/Pages/Propinas/Show.vue`, substituir o corpo do cartão "Ajustes de preço":
```vue
                    <div class="card-body pt-0">
                        <p v-if="propina.ajustes.length === 0" class="text-muted mb-0">Sem ajustes de preço.</p>
                        <div v-else class="table-responsive">
                            <table class="table table-row-dashed align-middle fs-7 gy-2 mb-0">
                                <thead>
                                    <tr class="text-muted fw-bold text-uppercase">
                                        <th>Data</th><th>Valor</th><th>Recebido</th><th>Plano</th><th>Motivo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="ajuste in propina.ajustes" :key="ajuste.id">
                                        <td>{{ instante(ajuste.em) }}<div class="text-muted">{{ ajuste.por ?? '—' }}</div></td>
                                        <td>
                                            {{ formatarDinheiro(ajuste.valor_anterior, moeda) }} → {{ formatarDinheiro(ajuste.valor_novo, moeda) }}
                                            <div class="text-muted">{{ ajuste.modo_descricao }} · desde {{ ajuste.mes_efeito }}</div>
                                        </td>
                                        <td>{{ formatarDinheiro(ajuste.valor_pago_no_momento, moeda) }}</td>
                                        <td>{{ ajuste.plano_anterior }}<span v-if="ajuste.plano_novo"> → {{ ajuste.plano_novo }}</span></td>
                                        <td>{{ ajuste.motivo }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
```
(`instante` e `formatarDinheiro` já existem/estão importados em `Show.vue`.)

- [ ] **Step 4: Correr e ver passar; build**

Run: `php artisan test Modules/Financeiro/tests/Unit/AlterarPrecoPlanoVueTest.php Modules/Financeiro/tests/Unit/DetalhePropinaVueTest.php && npm run build`
Expected: PASS e build sem erros.

- [ ] **Step 5: Verificação visual (login local `admin@mositec.gmail.com`; nunca editar a BD à mão — usar a app)**

Com `npm run dev` e o servidor local: Configurações → Financeiro → Planos de propina → plano com o badge "Com propinas" → Acções → "Alterar preço". Verificar: nenhum cartão de escolha vem marcado e "Pré-visualizar" fica desactivado até escolher; com MANTER aparece o aviso e a caixa de confirmação; o botão "Confirmar" só activa com motivo ≥ 5; depois de confirmar, a lista mostra o plano encurtado e o "(desde MM/AAAA)"; Propinas → detalhe de uma propina reajustada mostra o ajuste. Confirmar por `getComputedStyle` (não só por captura) que os cartões de rádio não ficam com a classe `active` ao abrir. Tema claro e escuro (`bg-body-secondary` no resumo).

- [ ] **Step 6: Stage**

```bash
git add Modules/Financeiro/resources/js/Components/PlanosPropina/AlterarPrecoPlanoModal.vue Modules/Financeiro/resources/js/Pages/PlanosPropina/Index.vue Modules/Financeiro/resources/js/Pages/Propinas/Show.vue resources/js/Components/Shared/acoesLista.js Modules/Financeiro/tests/Unit/AlterarPrecoPlanoVueTest.php
```

---

### Task 15: PostgreSQL — CHECK, reversão real e concorrência com duas ligações (fases §8.3; spec §14 "Limite dos testes")

**Files:**
- Test: `Modules/Financeiro/tests/Feature/Pgsql/AlteracaoPrecoPgsqlTest.php` (`PgsqlTestCase`: CHECK, fluxo completo sob os CHECK, reversão depois de falha a meio, isolamento)
- Test: `Modules/Financeiro/tests/Feature/Pgsql/AlteracaoPrecoConcorrenciaPgsqlTest.php` (`PgsqlConcorrenciaTestCase`: duas sessões reais, dados confirmados)

**Interfaces:**
- Consumes: T1 (CHECK das tabelas novas), T2 (`reajustarValor` numa só escrita), T8 (`AlterarPrecoPlanoAction`), `ComAlteracaoPreco`, `PgsqlTestCase`/`PgsqlConcorrenciaTestCase` (`outraLigacao()`), grupo `pgsql`.
- Produces: prova em PostgreSQL de D7 (ordem e duração dos bloqueios), D8 (pagamento confirmado por outra sessão → recusa; duplo clique), D9 (aborto total), D13 (falha a meio reverte e a ligação continua utilizável) e dos CHECK. Ignorado (skipped) sem a base `mositec_escola_test`; fora da suite por omissão.

- [ ] **Step 1: Escrever os testes**

`Modules/Financeiro/tests/Feature/Pgsql/AlteracaoPrecoPgsqlTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature\Pgsql;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Financeiro\Actions\AlterarPrecoPlanoAction;
use Modules\Financeiro\Enums\EstadoCobranca;
use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Financeiro\Models\AlteracaoPrecoPlano;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Models\PropinaAjuste;
use Modules\Financeiro\Tests\Concerns\ComAlteracaoPreco;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\PgsqlTestCase;

/**
 * O que só o PostgreSQL prova em F4: os CHECK das tabelas novas, o reajuste numa só escrita sob
 * propinas_estado_coerente_check, a reversão total depois de uma falha a meio (sem transacção abortada a meio)
 * e o aborto por propina de outro ano. Corre só com a base mositec_escola_test.
 */
#[Group('pgsql')]
class AlteracaoPrecoPgsqlTest extends PgsqlTestCase
{
    use ComAlteracaoPreco;
    use ComDadosAcademicosFinanceiro;
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

    /** Corre num savepoint: um erro no PostgreSQL aborta a transacção e o savepoint deixa a do teste utilizável. */
    private function assertRecusadoPelaBase(string $restricao, Closure $operacao): void
    {
        try {
            DB::transaction($operacao);
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0] ?? null, $e->getMessage());
            $this->assertStringContainsString($restricao, $e->getMessage());

            return;
        }

        $this->fail("A base devia ter recusado ({$restricao}).");
    }

    public function test_check_das_alteracoes_e_dos_ajustes(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $propina = $this->propina($matricula, $plano, 6);
        $alteracao = $this->alterarPreco($plano, '2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS);
        $linha = fn (array $sobrepor) => array_merge([
            'tenant_id' => $alteracao->tenant_id, 'ano_lectivo_id' => $plano->ano_lectivo_id, 'plano_propina_id' => $plano->id,
            'plano_novo_id' => null, 'modo' => 1, 'modo_descricao' => 'x', 'valor_anterior' => 100, 'valor_novo' => 200,
            'mes_efeito' => '2027-02-01', 'motivo' => 'Motivo', 'total_propinas_afectadas' => 0, 'impressao_digital' => str_repeat('a', 64),
        ], $sobrepor);

        $this->assertRecusadoPelaBase('alteracoes_preco_plano_valores_check', fn () => DB::table('alteracoes_preco_plano')->insert($linha(['valor_novo' => 100])));
        $this->assertRecusadoPelaBase('alteracoes_preco_plano_mes_efeito_check', fn () => DB::table('alteracoes_preco_plano')->insert($linha(['mes_efeito' => '2027-02-15'])));
        $this->assertRecusadoPelaBase('alteracoes_preco_plano_motivo_check', fn () => DB::table('alteracoes_preco_plano')->insert($linha(['motivo' => '   '])));
        $this->assertRecusadoPelaBase('alteracoes_preco_plano_modo_check', fn () => DB::table('alteracoes_preco_plano')->insert($linha(['modo' => 3])));
        $this->assertRecusadoPelaBase('propina_ajustes_valor_pago_check', fn () => DB::table('propina_ajustes')->insert([
            'tenant_id' => $alteracao->tenant_id, 'alteracao_preco_id' => $alteracao->id, 'propina_id' => $propina->id,
            'valor_anterior' => 100, 'valor_novo' => 200, 'valor_pago_no_momento' => 300, 'plano_anterior_id' => $plano->id, 'motivo' => 'M',
        ]));
    }

    public function test_descida_abaixo_do_pago_passa_sob_os_check_numa_so_escrita(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $parcial = $this->comEstado($this->propina($matricula, $plano, 7), EstadoCobranca::PARCIALMENTE_PAGA, 2_000_000);
        $this->fontePagamentoFalsa()->definir($parcial, [[2_000_000, '2026-10-05']]);

        $this->alterarPreco($plano, '2027-02', 1_500_000, ModoAlteracaoPreco::APLICAR_GERADAS);

        $linha = DB::selectOne('select valor, valor_pago, estado, capital_liquidado_em from propinas where id = ?', [$parcial->id]);
        $this->assertSame([2_000_000, 2_000_000, 3, '2026-10-05'], [(int) $linha->valor, (int) $linha->valor_pago, (int) $linha->estado, substr((string) $linha->capital_liquidado_em, 0, 10)]);
    }

    public function test_falha_a_meio_reverte_tudo_e_a_ligacao_continua_utilizavel(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

        foreach (range(6, 10) as $ordem) {
            $this->propina($matricula, $plano, $ordem);
        }

        $impressao = $this->previa($plano, '2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS)->impressaoDigital;
        $antes = $this->fotografia();
        $vezes = 0;
        PropinaAjuste::creating(function () use (&$vezes) {
            if (++$vezes === 3) {
                throw new RuntimeException('Falha injectada: ajuste');
            }
        });

        try {
            app(AlterarPrecoPlanoAction::class)->executar($plano->fresh(), $this->pedidoAlteracao('2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS, 'Motivo', $impressao));
            $this->fail('A falha devia propagar.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame($antes, $this->fotografia(), 'Tudo revertido no PostgreSQL.');
        $this->assertSame(0, AlteracaoPrecoPlano::query()->count(), 'A ligação continua utilizável (transacção não ficou abortada).');
    }

    public function test_propina_de_outro_ano_aborta_tudo(): void
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();
        $this->propina($matricula, $plano, 6);
        $outroAno = $this->anoLectivo('2027/2028', '2027-09-01', '2028-07-31');
        $impressao = $this->previa($plano, '2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS)->impressaoDigital;
        DB::table('matriculas')->where('id', $matricula->id)->update(['ano_lectivo_id' => $outroAno->id]);
        $antes = $this->fotografia();

        $this->assertRecusa('plano', 'outro ano lectivo', fn () => app(AlterarPrecoPlanoAction::class)->executar(
            $plano->fresh(),
            $this->pedidoAlteracao('2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS, 'Motivo', $impressao),
        ));
        $this->assertSame($antes, $this->fotografia());
        $this->assertSame(1, Propina::query()->count());
    }
}
```
(`DB::table(...)->insert` só em testes, fora de `Modules/*/app`: não viola `TenancyArquitecturaTest`. O teste de CHECK de `alteracoes_preco_plano` usa a mesma base com `tenant_id` real da alteração criada.)

`Modules/Financeiro/tests/Feature/Pgsql/AlteracaoPrecoConcorrenciaPgsqlTest.php`:
```php
<?php

namespace Modules\Financeiro\Tests\Feature\Pgsql;

use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Modules\Financeiro\Actions\AlterarPrecoPlanoAction;
use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Financeiro\Models\AlteracaoPrecoPlano;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Tests\Concerns\ComAlteracaoPreco;
use Modules\Financeiro\Tests\Concerns\ComDadosAcademicosFinanceiro;
use Modules\Financeiro\Tests\Concerns\ComPropinasFinanceiro;
use PHPUnit\Framework\Attributes\Group;
use Tests\PgsqlConcorrenciaTestCase;

/**
 * D7/D8 de F4 com duas sessões reais: a alteração de preço segura matrículas → plano → propinas até ao commit
 * (a geração, um pagamento e a mudança de estado de uma matrícula esperam), e uma escrita confirmada por outra
 * sessão entre a pré-visualização e a confirmação faz recusar. SQLite ignora FOR UPDATE.
 */
#[Group('pgsql')]
class AlteracaoPrecoConcorrenciaPgsqlTest extends PgsqlConcorrenciaTestCase
{
    use ComAlteracaoPreco;
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

    /** @return array{0: PlanoPropina, 1: Propina, 2: int} plano com Fev–Jun em aberto de uma matrícula; a propina de Fev; o id da matrícula */
    private function cenario(): array
    {
        ['plano' => $plano, 'matricula' => $matricula] = $this->cenarioPropinas();

        foreach (range(6, 10) as $ordem) {
            $this->propina($matricula, $plano, $ordem);
        }

        return [$plano, Propina::query()->where('matricula_id', $matricula->id)->where('ordem', 6)->firstOrFail(), (int) $matricula->id];
    }

    public function test_segura_matricula_plano_e_propinas_ate_ao_commit(): void
    {
        [$plano, $fevereiro, $matriculaId] = $this->cenario();
        $verificado = [];
        AlteracaoPrecoPlano::creating(function () use ($plano, $fevereiro, $matriculaId, &$verificado) {
            $verificado = [
                'matricula' => $this->sqlstateNaOutraLigacao('select id from matriculas where id = ? for key share', [$matriculaId]),
                'plano_partilhado' => $this->sqlstateNaOutraLigacao('select id from planos_propina where id = ? for share', [$plano->id]),
                'propina' => $this->sqlstateNaOutraLigacao('select id from propinas where id = ? for update', [$fevereiro->id]),
            ];
        });

        $this->alterarPreco($plano, '2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS);

        $this->assertSame('55P03', $verificado['matricula'], 'Inserir propinas desta matrícula (KEY SHARE) ou mudar-lhe o estado tem de esperar.');
        $this->assertSame('55P03', $verificado['plano_partilhado'], 'A geração (FOR SHARE nos planos, L15) tem de esperar pela divisão.');
        $this->assertSame('55P03', $verificado['propina'], 'Um pagamento (FOR UPDATE na propina) tem de esperar.');
        $this->assertNull($this->sqlstateNaOutraLigacao('select id from planos_propina where id = ? for update', [$plano->id]), 'Depois do commit tudo é libertado.');
        $this->assertSame(3_000_000, (int) $this->outraLigacao()->selectOne('select valor from propinas where id = ?', [$fevereiro->id])->valor);
    }

    public function test_pagamento_confirmado_por_outra_sessao_depois_da_previsualizacao_faz_recusar(): void
    {
        [$plano, $fevereiro] = $this->cenario();
        $impressao = $this->previa($plano, '2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS)->impressaoDigital;
        // Outra sessão (ex.: Pagamentos) regista 1.000,00 e confirma — coerente com os CHECK.
        $this->outraLigacao()->update('update propinas set valor_pago = 100000, estado = 2, estado_descricao = ? where id = ?', ['Parcialmente Paga', $fevereiro->id]);

        try {
            app(AlterarPrecoPlanoAction::class)->executar($plano->fresh(), $this->pedidoAlteracao('2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS, 'Motivo', $impressao));
            $this->fail('Devia recusar.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('impressao_digital', $e->errors());
        }

        $this->assertSame(0, (int) $this->outraLigacao()->selectOne('select count(*) as n from alteracoes_preco_plano')->n);
        $this->assertSame(2_500_000, (int) $this->outraLigacao()->selectOne('select valor from propinas where id = ?', [$fevereiro->id])->valor);
    }

    public function test_duplo_clique_grava_uma_so_vez(): void
    {
        [$plano] = $this->cenario();
        $impressao = $this->previa($plano, '2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS)->impressaoDigital;
        $pedido = $this->pedidoAlteracao('2027-02', 3_000_000, ModoAlteracaoPreco::APLICAR_GERADAS, 'Motivo', $impressao);

        app(AlterarPrecoPlanoAction::class)->executar($plano->fresh(), $pedido);

        try {
            app(AlterarPrecoPlanoAction::class)->executar($plano->fresh(), $pedido);
            $this->fail('O 2.º clique devia ser recusado.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('impressao_digital', $e->errors());
        }

        $this->assertSame(1, (int) $this->outraLigacao()->selectOne('select count(*) as n from alteracoes_preco_plano')->n);
        $this->assertSame(2, (int) $this->outraLigacao()->selectOne('select count(*) as n from planos_propina')->n);
    }
}
```
(Nota sobre o "duplo clique" verdadeiramente simultâneo: o PHP de teste não corre duas Actions em paralelo. A garantia vem da combinação provada aqui — o 1.º clique segura o plano `FOR UPDATE` até ao commit (teste 1) e o 2.º, ao obter o bloqueio, vê o plano mudado e recusa (teste 3).)

- [ ] **Step 2: Correr (dono, ou executor se a base de teste existir)**

Run: `DB_CONNECTION=pgsql DB_DATABASE=mositec_escola_test vendor/bin/phpunit --group=pgsql`
Expected: PASS (grupo anterior + 7 novos). Sem a base de teste: todos *skipped* — registar no relatório que a validação em PostgreSQL fica para o dono (critério 8.2/4 do plano de fases: obrigatória antes de considerar F4 validada).

Run também: `php artisan test Modules/Financeiro/tests/Feature/Pgsql` → Expected: skipped (não falham em SQLite).

- [ ] **Step 3: Stage**

```bash
git add Modules/Financeiro/tests/Feature/Pgsql/AlteracaoPrecoPgsqlTest.php Modules/Financeiro/tests/Feature/Pgsql/AlteracaoPrecoConcorrenciaPgsqlTest.php
```

---

### Task 16: Verificação final

- [ ] **Step 1: Suite completa**

Run: `php artisan test` (timeout alargado, ~200 s; fora da janela 23:00–24:00 UTC)
Expected: 0 falhas (grupo `pgsql` *skipped*). Número de testes = baseline anotada antes da Task 1 + os de F4. Falhas fora do módulo são regressões deste plano (em especial `TenancyArquitecturaTest`, `TenancyEsquemaTest`, `MatrizIsolamentoTest`, `EscritoresDePropinaTest`, `CriadoresDePropinaTest`, `CanceladoresDePropinaTest`, `RelogioDoTenantArquitecturaTest`, `FronteiraMatriculaFinanceiroTest`, `RotasPermissaoReconhecidaTest`, `RotasEscritaAutorizadasTest`, `PlanoPropinaComPropinasTest`, `GerarPropinasTest`): corrigir seguindo a convenção, sem enfraquecer testes nem alargar listas de excepções, e documentar.

- [ ] **Step 2: Limpeza e fronteiras**

Run:
```bash
grep -rnE "insertOrIgnore|->insert\(|upsert\(|DB::raw|DB::statement|DB::table|withoutEvents|Quietly\(" Modules/Financeiro/app
grep -rnE "\bnow\(\)|\btoday\(\)|Carbon(Immutable)?::(now|today)|new \\\\?DateTime(Immutable)?\(\)" Modules/Financeiro/app
grep -rn "Modules\\\\Financeiro" Modules/Matricula/app Modules/Matricula/routes
grep -rnE "\\\\Modules\\\\|\\\\Illuminate\\\\" Modules/Financeiro/app/Actions/AlterarPrecoPlanoAction.php Modules/Financeiro/app/Services/AlteracaoPrecoPlanoService.php Modules/Financeiro/app/Support/DivisaoDePlanoPropina.php Modules/Financeiro/app/Support/ReassociacaoDePropinas.php Modules/Financeiro/tests/Feature/AlterarPreco*.php
grep -rn "try {" Modules/Financeiro/app/Actions/AlterarPrecoPlanoAction.php
grep -nE "alterar-preco" Modules/Financeiro/routes/web.php
git diff --cached --name-only | grep -E "database/migrations"
```
Expected: os quatro primeiros vazios (sem SQL cru, sem "hoje" fora do relógio, Matrícula sem Financeiro, sem FQN inline); um só `try {` na Action, **à volta** de `DB::transaction`; duas rotas `alterar-preco` com `can:propina.ajustar` e `can:plano-propina.editar`; as migrações em stage de F4 são exactamente `2026_10_16_100000_…` e `2026_10_16_100100_…` (além das que já estavam em `/tmp/f4-stage-antes.txt`).

- [ ] **Step 3: Build**

Run: `npm run build`
Expected: sucesso.

- [ ] **Step 4: Estado do git**

Run: `git status --short` e `git diff --cached --stat`
Expected: só ficheiros deste plano (mais os que já estavam em stage antes da Task 1) em stage; nada gerado (`public/build`); **nenhum commit**.

- [ ] **Step 5: Informar o dono do que lhe cabe**

1. Correr as migrações novas: `php artisan migrate` (cria `alteracoes_preco_plano` e `propina_ajustes`; sem backfill — `valor_original` já existe desde F1). **Nada a semear** e **nada a sincronizar** (`propina.ajustar` já é do ADMIN_ESCOLA desde F2).
2. Correr o grupo PostgreSQL: `DB_CONNECTION=pgsql DB_DATABASE=mositec_escola_test vendor/bin/phpunit --group=pgsql` (obrigatório para validar F4: critério 8.2/4).
3. Verificação na app (login local `admin@mositec.gmail.com`; nunca editar a BD à mão): gerar propinas de uma turma; em Planos de propina → "Alterar preço" num plano com propinas: (a) APLICAR a partir de um mês a meio do ano → ver o plano encurtado + o "(desde MM/AAAA)", as propinas em aberto reajustadas e as pagas intactas, e os ajustes no detalhe; (b) MANTER noutro plano → ver o aviso, a caixa de confirmação e que nenhuma propina mudou; (c) gerar para uma matrícula nova → nasce ao valor novo.
4. Responder às decisões abertas abaixo (O1–O4).

---

## Riscos (a ler antes de executar)

| # | Risco | Mitigação / estado |
|---|---|---|
| R-F4.1 | **Âncora do calendário (L6):** alguns `mes_efeito` legítimos em planos cujo mês de início se repete no ano civil seguinte (ex.: Out→Set) são recusados. | Mensagem clara a sugerir outro mês ou o início do plano (in loco). Correcção estrutural (âncora explícita de ano no plano) fora deste plano. |
| R-F4.2 | **MANTER + geração completa (L7, Q14 aprovado):** alunos da mesma turma pagam valores diferentes no mesmo mês. | Aviso com números reais (M1), confirmação explícita (M2), nome do plano com "(desde …)" visível na lista e no detalhe (M3), reversão auditada (M4). |
| R-F4.3 | **Propinas "órfãs"** de um MANTER anterior bloqueiam uma divisão posterior do plano antigo. | Recusa com mensagem; in loco continua possível (Review Focus 1). |
| R-F4.4 | **Divisões sucessivas multiplicam planos** "(desde …)" na lista de configuração e na cópia para o ano seguinte (L20). | Aceite em V1; agrupar por "família" fica para feedback do dono. |
| R-F4.5 | **Concorrência só provada em PostgreSQL.** | Task 15; até o dono a correr, risco aceite (spec §14 "Limite dos testes"). |
| R-F4.6 | **Volume:** um plano com milhares de propinas faz a pré-visualização e a transacção (bloqueio de todas as matrículas e propinas candidatas) pesadas. | Aceitável para escolas de V1 (≈ alunos × períodos restantes); se surgir, paginar a lista da pré-visualização (o cálculo e a impressão continuam completos). |
| R-F4.7 | **Cache `valor_pago` divergente das contribuições** (só possível com Pagamentos-A defeituoso) bloqueia a alteração dessa propina. | Por desenho (D6: o recebido nunca muda); a mensagem manda recalcular. |

## Decisões abertas para o dono (não inventadas; o plano segue a opção indicada até resposta)

- **O1 — Modo in loco (L1).** O spec §14 tem três textos incompatíveis para `mes_efeito` = início do plano ("recusada" vs `plano_novo_id` nulo "alterado in loco" vs "a edição genérica recusa"). Este plano segue a proposta L1 do plano de fases (já usada na secção F4 aprovada): a própria operação faz a alteração in loco, sem dividir, com as mesmas escolhas, pré-visualização, motivo e registo. **Confirmar** (e corrigir o texto do §14).
- **O2 — Planos de anos lectivos encerrados.** O spec permite corrigir dívidas de anos anteriores pela mesma operação sobre o plano desse ano; não diz nada sobre anos **encerrados**. Este plano **permite** (D14). Se o dono preferir proibir em anos encerrados (ou só permitir APLICAR lá, já que MANTER não tem efeito sem geração), é uma guarda de 1 linha na pré-visualização.
- **O3 — Coluna `alteracoes_preco_plano.ano_lectivo_id`** (proposta do plano de fases, não está no spec): incluída como snapshot do ano para auditoria e filtros (D9). Confirmar.
- **O4 — Nome do plano novo** `"{nome} – Fase N"` (proposta do plano de fases): confirmar o formato; o utilizador não o escolhe nesta versão.

---

## Tabelas e relações (no fim de F4)

| Tabela | Estado | Relações |
|---|---|---|
| `alteracoes_preco_plano` | **nova** | N:1 `tenants`; N:1 `ano_lectivos` (snapshot do ano do plano); N:1 `planos_propina` (`plano_propina_id`, plano alterado); N:1 `planos_propina` (`plano_novo_id`, nulo no modo in loco); N:1 `users` (`criado_por`); 1:N `propina_ajustes`. Só criação. CHECK pgsql: valores > 0 e diferentes, `modo ∈ {1,2}`, `mes_efeito` dia 1, motivo não vazio, `plano_novo_id ≠ plano_propina_id` |
| `propina_ajustes` | **nova** | N:1 `tenants`; N:1 `alteracoes_preco_plano`; N:1 `propinas`; N:1 `planos_propina` (`plano_anterior_id`); N:1 `planos_propina` (`plano_novo_id`, nulo sem reassociação); N:1 `users` (`criado_por`). `unique(alteracao_preco_id, propina_id)`. Só criação, sem `updated_at`. CHECK pgsql: valores > 0 e diferentes, `valor_pago_no_momento ≤ valor_novo`, motivo não vazio |
| `propinas` | existe (F1) | 1:N `propina_ajustes`. F4 escreve `valor` (só `RecalcularPropina::reajustarValor`, na mesma escrita que `estado`/`valor_pago`/`capital_liquidado_em`) e `plano_propina_id`/`ordem` (só `ReassociacaoDePropinas`). Invariante: `valor = valor_original + Σ(valor_novo − valor_anterior)` |
| `planos_propina` | existe | Dividido por F4: o antigo encurtado (`mes_fim`), o novo criado com os mesmos alvos/ano; 1:N `alteracoes_preco_plano` (como alterado e como novo) |
| `plano_propina_alvos` | existe | copiados para o plano novo (`substituirAlvos`) |
| `matriculas` | existe (Matrícula) | só bloqueadas (`FOR UPDATE`) e lidas (ano lectivo, aviso M1); a Matrícula continua sem conhecer o Financeiro |
| `turmas`, `ano_lectivos`, `alunos`, `dados_pessoas`, `users` | existem | só leitura (resolvedor no aviso M1; nomes na pré-visualização e no detalhe) |
