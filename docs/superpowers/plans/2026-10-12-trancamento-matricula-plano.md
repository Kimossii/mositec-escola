# Trancamento de matrícula com regularização financeira configurável — Plano (análise + desenho)

**Data:** 2026-10-12
**Estado:** PROPOSTA. **Não iniciar implementação antes da aprovação explícita do dono** (incluindo as respostas às questões em aberto da secção 2.2).
**Módulos:** `Modules/Matricula` (dono do estado e das regras académicas), `Modules/Financeiro` (regra de cobrança e verificação de dívidas), `Modules/Core` (contrato e, se aprovado, auditoria do tenant), `Modules/Permissao` (acção nova).
**Depende de:** `specs/2026-10-08-financeiro-propinas-design.md` (§7 geração, §9 eventos, §14 preço, §15 multas), `specs/2026-10-08-financeiro-pagamentos-design.md` (valor pago, créditos), `specs/2026-10-08-modulo-financeiro-configuracao-design.md` (regras de cobrança, contrato 4 "Estado da matrícula").

## 0. Decisão do dono (texto de partida)

> Trancamento de matrícula: a exigência de regularização financeira é configurável por escola. Config `exigir_regularizacao_financeira_trancamento` nas regras de cobrança do tenant, default ACTIVADA (bloqueia trancar com dívidas exigíveis, incluindo propinas parcialmente pagas e multas aplicáveis por liquidar). Desactivada: permite trancar com dívidas. Em qualquer caso dívidas, pagamentos e histórico preservados. Durante o trancamento não são geradas novas propinas. Reactivação respeita regras académicas e retoma geração conforme o plano aplicável. Alterar a configuração respeita permissões existentes e fica registada em auditoria.

## 1. O que existe hoje e lacunas

### 1.1 Financeiro — Regras de Cobrança
- `regras_cobranca` (1:1 com o tenant, `unique(tenant_id)`): `dia_vencimento`, `dias_tolerancia`, `permite_pagamento_parcial`, `permite_pagamento_antecipado`, `gerar_automaticamente`, `permite_negociacao`, `desconto_maximo_negociacao`, `multa_activa` (acrescentada por migração própria `2026_10_12_100000_add_multa_activa_to_regras_cobranca_table.php`), autoria (`criado_por`, `editado_por`).
- Model `RegraCobranca` (`PertenceAoTenant`, `RegistaAutoria`, `DEFAULTS`, `doTenant()` com `firstOrCreate`), `RegraCobrancaDTO` (campos opcionais com `null` = "não mexer", ex.: `multa_activa`), `AtualizarRegraCobrancaRequest` (`regra-cobranca.editar`; `multa_activa` é `sometimes|boolean`), `AtualizarRegraCobrancaAction` (transacção + `lockForUpdate`), `GestaoRegraCobrancaService`, `RegraCobrancaController` (`show`/`update`), página `Pages/RegrasCobranca/Edit.vue` (cartões Vencimento / Pagamentos e geração / Negociação / Multas por atraso), provisionador `ProvisionarRegrasCobranca` (ordem 50) e `financeiro:sincronizar --todos`.
- Escalões de multa (`escaloes_multa`, até 3) já existem como **configuração**; a **aplicação** de multas (§15) não existe.
- **Não existem** propinas, pagamentos, créditos nem multas operacionais (nenhuma tabela `propinas`, `pagamentos`, `propina_multas`, `creditos`). Os contratos `ReferenciaFinanceira` e `FonteDePrecos` existem (padrão de verificação por implementações etiquetadas no container).

### 1.2 Auditoria
- **Ao nível do tenant não há auditoria.** O único rasto de uma alteração a `regras_cobranca` é `editado_por` + `updated_at` (`RegistaAutoria`): diz quem editou por último, não o quê nem o valor anterior. Alterações sucessivas apagam o rasto.
- `plataforma_auditoria` + `RegistarAuditoriaAction` (Plataforma) é **só da plataforma** (autor = `SuperAdmin`, `codigo_tenant` texto, sem `tenant_id`, sem `PertenceAoTenant`). Não serve para acções de utilizadores da escola sem misturar fronteiras.
- `matricula_historicos` é um histórico de domínio (estado anterior/novo, utilizador, data) — **sem** motivo, sem data de efeito, sem detalhe.
- **Lacuna:** "fica registada em auditoria" não é satisfazível hoje. É preciso criar um mecanismo (ver 2.1-D10).

### 1.3 Matricula
- `EstadoMatriculaEnum`: PENDENTE(1), ACTIVA(2), CANCELADA(3), CONCLUIDA(4), TRANSFERIDA(5). **Não existe TRANCADA.** Transições: PENDENTE→{ACTIVA, CANCELADA}; ACTIVA→{CONCLUIDA, CANCELADA, TRANSFERIDA}; terminais preenchem `data_fim`.
- `matriculas.estado` é tinyint **sem `estado_descricao`** (viola a convenção do projecto de coluna `*_descricao` para enums inteiros).
- `AlterarEstadoMatriculaAction` (genérica, sem motivo) + `RegistarHistoricoMatriculaAction`; endpoint `PATCH alunos/{aluno}/matriculas/{matricula}/estado` com `matricula.editar`; o frontend (`Models/Estado.js`, `Pages/Index.vue`) espelha as transições e mostra um botão por transição permitida.
- **Não existe o evento `MatriculaEstadoAlterado`** (o §9 de Propinas manda criá-lo); o `EventServiceProvider` do módulo está vazio. Também não há nenhum ponto de extensão para "pré-condições" de uma transição.
- Outros consumidores do estado: `AlterarEstadoAnoLectivoAction` (ao encerrar: ACTIVA→CONCLUIDA, PENDENTE→CANCELADA), `RenovarMatriculaAction` (só ACTIVA/CONCLUIDA), `ValidadorMatriculaService::validarMatriculaNaoDuplicada` e `CriarInscricaoDisciplinaAction` (consideram PENDENTE/ACTIVA como "ocupadas"), `SituacaoAcademicaDoAlunoService` (só ACTIVA).
- Permissões: `Modulo::MATRICULA` com ver/criar/editar/eliminar. Catálogo `Acao` só tem ver, criar, editar, eliminar, listar, exportar.

### 1.4 Specs
- Configuração §5.4: "Trancada não existe hoje e fica fora deste trabalho".
- Propinas §2.6/§7.2: geração só para matrículas Activa, a partir do mês de `data_matricula` (não conhece intervalos de interrupção).
- Propinas §9: Cancelada/Transferida cancelam automaticamente propinas futuras sem pagamentos; fronteira por evento; Matricula não depende de Financeiro.
- Propinas §13: "o estado de matrícula Trancada" fora de âmbito (ponteiro para este plano acrescentado).

### 1.5 Lacunas resumidas
1. Estado TRANCADA e transições (trancar, reactivar, sair do trancamento por cancelamento/transferência).
2. Dados do trancamento: data de efeito, motivo, snapshot da verificação financeira.
3. Ponto de extensão para Financeiro bloquear sem Matricula depender de Financeiro.
4. Coluna de configuração + UI + auditoria da alteração.
5. Geração de propinas consciente de intervalos trancados (manual e automática) e retoma na reactivação.
6. Definição de "dívida exigível" (depende de Propinas, Pagamentos e Multas, que ainda não existem).

## 2. Decisões de desenho

### 2.1 Recomendações (a aprovar)

**D1 — Dependência entre módulos.** Matricula **não** importa nada de Financeiro. Novo contrato em `Modules/Core/app/Contracts/VerificaTrancamentoMatricula` (mesmo padrão de `ProcuraSituacaoAcademicaDoAluno` + etiqueta como `ReferenciasFinanceiras`): `verificar(int $matriculaId, CarbonImmutable $dataEfeito): ResultadoVerificacaoTrancamento` com `bloqueios` (impedem) e `avisos` (informativos), DTO em `Modules/Core/app/DTO`. Matricula itera todas as implementações etiquetadas (`matricula.verificacoes-trancamento`); sem implementações (Financeiro desactivado) nada bloqueia. Financeiro implementa `VerificacaoFinanceiraTrancamento`. Efeitos posteriores (cancelar propinas futuras, retomar geração) vão por evento `MatriculaEstadoAlterado` (Financeiro escuta).

**D2 — Novo estado.** `TRANCADA = 6` (label "Trancada"). Transições: ACTIVA→TRANCADA; TRANCADA→ACTIVA (reactivar); TRANCADA→CANCELADA (desistência); TRANCADA→TRANSFERIDA. **Não** permitidas: PENDENTE→TRANCADA, TRANCADA→CONCLUIDA. TRANCADA **não é terminal** (não preenche `data_fim`). A transição genérica (`AlterarEstadoMatriculaAction` / endpoint `estado`) **recusa** entrar e sair de TRANCADA→ACTIVA; só as Actions dedicadas (com motivo e data) o fazem. Aproveitar para criar `matriculas.estado_descricao` (convenção do projecto) sincronizada no `saving`.

**D3 — "Dívida exigível" (verificação feita por Financeiro, à data de efeito D):**
- **Propinas:** estado persistido Em Aberto ou Parcialmente Paga, `saldo > 0` e `periodo_inicio ≤ D` (período já iniciado = devido, mesmo que ainda não vencido nem fora da tolerância). Inclui as resolvidas como Em Atraso e as parcialmente pagas. **Excluídas:** Pendentes futuras (`periodo_inicio > D`, serão canceladas — D6), Pagas, Canceladas, Anuladas.
- **Multas:** `propina_multas` ACTIVA ou REDUZIDA com `valor − valor_pago > 0`. "Aplicáveis por liquidar" = também as que **já deviam existir** mas o comando diário ainda não aplicou: antes de verificar, Financeiro corre para as propinas da matrícula o mesmo serviço idempotente de aplicação (§15.5) — evita trancar numa janela em que o comando ainda não passou. Isentas/Anuladas não contam.
- **Créditos do aluno não compensam implicitamente.** Se houver crédito DISPONIVEL, a verificação devolve um *aviso* ("tem X de crédito que cobre Y"); a regularização faz-se registando a utilização do crédito (Pagamentos §6), que fica no histórico. Nada se abate em silêncio.
- **Produtos/serviços:** sem cobranças operacionais hoje; fora de âmbito. Quando existirem, o seu módulo regista outra implementação do mesmo contrato (D1) — sem alterar Matricula.

**D4 — Âmbito da verificação:** **por aluno** (todas as matrículas do aluno no tenant, incluindo anos anteriores, Concluídas/Canceladas/Transferidas), porque a regularização é do aluno e o crédito também é do aluno. A UI agrupa por matrícula. (Ver Q2 — alternativa: só a matrícula a trancar.)

**D5 — Configuração desactivada:** a verificação corre na mesma e devolve as dívidas como **avisos**; o operador confirma explicitamente ("trancar com dívidas em aberto") e o snapshot fica no histórico. Nenhuma dívida, pagamento, multa ou crédito é alterado.

**D6 — Propinas futuras já geradas ao trancar:** mesmo regime do §9 — propinas com `periodo_inicio > D` **sem pagamentos** são Canceladas automaticamente (motivo "Matrícula trancada"). Com pagamentos (antecipadas): **mantêm-se** (nada se devolve nem converte em crédito automaticamente); a UI assinala-as e a escola decide manualmente (anular pagamento → crédito). A propina do período em curso (`periodo_inicio ≤ D`) fica como dívida, por inteiro (sem pró-rata — Q5).

**D7 — Sem geração durante o trancamento.** Já garantido por §2.6 (só Activa), mas é preciso endurecer: (a) a geração manual de períodos anteriores (§7.2, "com permissão") passa a **recusar** períodos inteiramente contidos num intervalo trancado; (b) a elegibilidade deixa de ser só "desde `data_matricula`" e passa a ser "períodos que intersectam um intervalo em que a matrícula esteve Activa". Os intervalos vêm de Matricula (query service `IntervalosDeActividadeDaMatricula`, a partir de `matricula_historicos.data_efeito`); Financeiro → Matricula é a direcção já permitida (propinas têm FK para matrículas).

**D8 — Reactivação (TRANCADA→ACTIVA).** Regras académicas no Matricula (`ValidadorMatriculaService`): ano lectivo ACTIVO, turma activa, sem outra matrícula PENDENTE/ACTIVA/TRANCADA na mesma identidade académica, data de reactivação ≥ data do trancamento e ≤ hoje, motivo obrigatório. **Sem verificação financeira** na reactivação (a decisão do dono não a pede). Após commit, o evento leva Financeiro, se `gerar_automaticamente`, a chamar `GerarPropinasService` para a matrícula com competências cujo `periodo_fim ≥ 1.º dia do mês da reactivação`; idempotente (unicidade + sobreposição §3). Os meses entre D e a reactivação ficam **sem propina** (lacuna intencional). O plano é o aplicável **nesse momento** (`ResolvePlanoAplicavel`), ao preço vigente. Sem `gerar_automaticamente`, a geração manual já respeita os intervalos (D7).

**D9 — Multas durante o trancamento:** as dívidas que ficaram (propinas com período iniciado) **continuam a envelhecer e a sofrer multas** pelas regras normais (§15), como as dívidas de matrículas Canceladas/Transferidas. Não se cria congelamento especial (Q6 se o dono quiser congelar).

**D10 — Auditoria da configuração.** Criar uma auditoria **do tenant** mínima, no Core: tabela `auditoria_tenant` só-acrescenta (`tenant_id`, `utilizador_id`, `accao`, `entidade_tipo`, `entidade_id`, `detalhe` json `{antes, depois}` só com campos alterados, `ip`, `created_at`), model que recusa `update`/`delete`, `RegistarAuditoriaTenantAction` com a mesma recusa de chaves secretas da Plataforma. Primeiro uso: `AtualizarRegraCobrancaAction` regista o diff de **todos** os campos alterados (incluindo escalões), não só da nova flag — dentro da mesma transacção (é escrita na BD, não efeito externo). Leitura/listagem da auditoria fica fora de âmbito V1 (só consulta por BD/teste) — Q8.

**D11 — Histórico do trancamento.** `matricula_historicos` ganha `data_efeito` (date), `motivo` (text nulo; obrigatório para TRANCADA e reactivação) e `detalhe` (json nulo: `exigencia_activa`, totais de dívida/multa/crédito no momento, ids das propinas canceladas, `confirmou_com_dividas`). É o registo de domínio; não duplica a auditoria do tenant.

**D12 — Permissões.** Nova acção `trancar` no catálogo `Acao` (cobre trancar e reactivar), slug `matricula.trancar`, concedida a ADMIN_ESCOLA em `SincronizarPerfisDeSistemaAction`. Entra na **mesma extensão do catálogo `Acao`** do plano de Propinas (cancelar, anular, ajustar, isentar-multa), para a decisão de "mostrar acções só nos módulos onde fazem sentido" ser tomada uma vez. Alterar a configuração continua a exigir `regra-cobranca.editar` (nada novo). **Sem excepção/override por utilizador em V1** (Q3).

**D13 — Concorrência.** `TrancarMatriculaAction` bloqueia a matrícula (`lockForUpdate`) e as verificações correm dentro da mesma transacção. Acções financeiras que **criam ou reabrem dívida** numa matrícula (geração, anular pagamento, anular utilização de crédito) passam também a bloquear a matrícula primeiro (a geração já o faz, §3), para a verificação não ser contornada entre ler e gravar. Limite conhecido: SQLite ignora `lockForUpdate` (risco aceite como em §14).

**D14 — Robustez do evento.** O cancelamento de propinas futuras (D6) e a retoma (D8) correm no listener depois do commit. Se falharem, o comando de geração automática faz reconciliação idempotente: cancela propinas futuras sem pagamentos de matrículas não Activas e gera as em falta das Activas. Assim um listener falhado não deixa estado inconsistente permanente.

**D15 — Encerramento do ano lectivo com matrículas TRANCADAS.** Ficam TRANCADA (é a verdade histórica) e deixam de ser reactiváveis (ano não ACTIVO). A verificação de duplicado (`validarMatriculaNaoDuplicada`, `CriarInscricaoDisciplinaAction`) passa a considerar TRANCADA como ocupada **apenas no mesmo ano lectivo**, para o aluno poder matricular-se no ano seguinte (ex.: repetir a classe). `RenovarMatriculaAction` continua a recusar TRANCADA (o aluno volta com matrícula nova). Q7 confirma.

### 2.2 Questões em aberto para o dono
1. **Q1 — Dívida exigível:** confirma "período já iniciado" (D3) em vez de "vencida" ou "em atraso (após tolerância)"? Inclui multas que o comando ainda não aplicou (aplicação síncrona antes de verificar)?
2. **Q2 — Âmbito:** dívidas do **aluno** (todas as matrículas, recomendado) ou só da **matrícula** a trancar?
3. **Q3 — Excepção:** com a exigência activa, deve existir override individual (ex.: permissão `matricula.trancar-com-divida`, motivo obrigatório, registado)? Recomendação: **não em V1** — a escola que precisa de flexibilidade desactiva a configuração (fica auditado).
4. **Q4 — Créditos:** confirma que o crédito disponível **não** compensa automaticamente (só aviso + utilização explícita)?
5. **Q5 — Data de efeito:** pode ser retroactiva (até à data do último evento da matrícula) ou só hoje? Sem datas futuras/agendadas em V1. A propina do mês em curso fica por inteiro (sem pró-rata)?
6. **Q6 — Multas:** as dívidas pré-trancamento continuam a acumular multas (recomendado) ou congelam na data do trancamento?
7. **Q7 — Fim de ano:** TRANCADA permanece TRANCADA ao encerrar o ano (recomendado) ou passa a CANCELADA?
8. **Q8 — Auditoria:** aceita a auditoria genérica do tenant no Core (D10), usada primeiro pelas regras de cobrança? É precisa uma página de consulta em V1?
9. **Q9 — Propinas futuras pagas antecipadamente:** manter (recomendado) ou converter automaticamente em crédito?
10. **Q10 — Inscrições em disciplinas:** durante o trancamento ficam como estão (recomendado) ou passam a um estado próprio?

## 3. Alterações por módulo

### 3.1 Modules/Core
- `Contracts/VerificaTrancamentoMatricula` + `DTO/ResultadoVerificacaoTrancamento` (bloqueios/avisos com mensagem e detalhe estruturado) + constante de etiqueta.
- (D10, se aprovado) migração `auditoria_tenant`, model só-acrescenta com `PertenceAoTenant`, `Actions/RegistarAuditoriaTenantAction`.

### 3.2 Modules/Matricula
- **Enum:** `TRANCADA = 6`, `label()`, `podeTransitarPara()`, `eTerminal()` (false), helper `ocupaIdentidadeAcademica()` (PENDENTE, ACTIVA, TRANCADA-mesmo-ano).
- **Migrações:** `matriculas.estado_descricao` (backfill a partir de `estado`); `matricula_historicos` + `data_efeito`, `motivo`, `detalhe` (json) — todas nulas para não tocar nas linhas existentes.
- **Model:** hook `saving` para `estado_descricao`; `MatriculaHistorico` com casts novos.
- **Actions:** `TrancarMatriculaAction` (transacção, lock, validações, itera verificações etiquetadas, bloqueia ou exige confirmação, grava histórico com snapshot, dispara evento `afterCommit`); `ReactivarMatriculaAction` (regras académicas D8, histórico, evento). `AlterarEstadoMatriculaAction` passa a recusar TRANCADA e TRANCADA→ACTIVA e passa a disparar `MatriculaEstadoAlterado` em todas as transições (cumpre §9).
- **Evento:** `Events/MatriculaEstadoAlterado` (matricula_id, tenant, estado anterior/novo, data_efeito, utilizador_id), despachado depois do commit.
- **Serviço de leitura:** `IntervalosDeActividadeDaMatricula` (D7) e endpoint/serviço de pré-visualização da verificação (`GET .../matriculas/{matricula}/trancamento`) — só leitura, mesmo serviço que a Action usa.
- **Validação:** `TrancarMatriculaRequest` (`data_efeito`, `motivo` obrigatório, `confirmar_com_dividas` bool), `ReactivarMatriculaRequest`; `ValidadorMatriculaService::validarReactivacao`; duplicados com TRANCADA (D15).
- **Rotas:** `POST .../{matricula}/trancar`, `POST .../{matricula}/reactivar` com `can:matricula.trancar`.
- **UI:** `Models/Estado.js` (TRANCADA, label, badge neutro, transições, filtro); `Pages/Index.vue` — acções "Trancar"/"Reactivar" fora do menu genérico de transições; modal de trancamento com data, motivo e resultado da verificação (bloqueios em vermelho, avisos com confirmação explícita, crédito disponível), padrões existentes de modal e `bg-body-secondary`; histórico mostra motivo e data de efeito.
- **Outros consumidores:** `AlterarEstadoAnoLectivoAction` (não mexe em TRANCADA), `RenovarMatriculaAction` (mensagem para TRANCADA), `SituacaoAcademicaDoAlunoService` (sem turma activa quando trancada — confirmar texto mostrado).

### 3.3 Modules/Financeiro
- **Migração:** `add_exigir_regularizacao_financeira_trancamento_to_regras_cobranca_table` — `boolean(...)->default(true)->after('multa_activa')`. Em PostgreSQL e SQLite o `ADD COLUMN ... DEFAULT true` preenche as linhas existentes: **tenants existentes ficam com a exigência ACTIVADA**, coerente com a decisão. Sem necessidade de backfill no `financeiro:sincronizar`.
- **Model/DTO/Request/Action:** `DEFAULTS` e `$casts` com `true`; DTO com `?bool` (null = não mexer, como `multa_activa`); Request `sometimes|boolean` (compatível com payloads antigos/testes); Action grava e regista auditoria (D10) do diff completo.
- **UI:** `Pages/RegrasCobranca/Edit.vue` — novo cartão "Matrículas" com interruptor "Exigir regularização financeira para trancar matrícula" e texto curto do efeito (padrão dos cartões existentes).
- **Verificação:** `Support/VerificacaoFinanceiraTrancamento` implementa o contrato do Core (D3/D4/D5), etiquetada no `FinanceiroServiceProvider`; aplica multas em atraso de forma síncrona e idempotente antes de calcular.
- **Listener** de `MatriculaEstadoAlterado`: →TRANCADA cancela futuras sem pagamentos (D6); TRANCADA→ACTIVA retoma geração (D8); reaproveita o listener do §9 (Cancelada/Transferida).
- **Geração:** `GerarPropinasService` com elegibilidade por intervalos activos (D7); pré-visualização manual mostra "período trancado" como ignorado; reconciliação no comando (D14).
- **Locks:** `AnularPagamentoAction` e anulação de utilização de crédito bloqueiam a matrícula primeiro (D13).

### 3.4 Modules/Permissao
- Acção `trancar` no `AcaoSeeder` (na extensão do catálogo do plano de Propinas) e `Modulo::MATRICULA => [..., 'trancar']` para ADMIN_ESCOLA; seed para tenants existentes pelo mecanismo idempotente actual.

## 4. Tabelas e relações afectadas

| Tabela | Módulo | Alteração | Relações / notas |
|---|---|---|---|
| `regras_cobranca` | Financeiro | + `exigir_regularizacao_financeira_trancamento` bool default **true** | 1:1 tenant; existentes ficam `true` pelo default da coluna |
| `matriculas` | Matricula | + `estado_descricao`; novo valor `estado = 6` | N:1 aluno, turma, ano lectivo |
| `matricula_historicos` | Matricula | + `data_efeito`, `motivo`, `detalhe` (json) | N:1 matrícula, N:1 utilizador |
| `auditoria_tenant` (nova, se D10) | Core | criação, só-acrescenta | N:1 tenant, N:1 utilizador; entidade polimórfica por tipo/id sem FK |
| `acoes` / `role_permissoes` | Permissao | + acção `trancar`; concessão a ADMIN_ESCOLA | catálogo global + por tenant |
| `propinas` (do plano de Propinas) | Financeiro | sem coluna nova; Canceladas com motivo "Matrícula trancada"; geração consciente de intervalos | N:1 matrícula, N:1 plano |
| `propina_multas` (Propinas §15) | Financeiro | só lidas na verificação; aplicação síncrona idempotente | 1:1 propina |
| `pagamentos`, `pagamento_propinas`, `pagamento_multas`, `creditos`, `credito_utilizacoes` | Financeiro | só leitura (valor pago, crédito disponível); locks de matrícula nas anulações | inalteradas |
| `escaloes_multa` | Financeiro | inalterada | — |

## 5. Testes

**Financeiro — configuração**
- Migração: tenant existente fica com `true`; tenant novo (provisionamento) fica com `true`; `doTenant()` cria com `true`.
- Update com a flag a false/true; pedido sem o campo não altera (null = não mexer); sem `regra-cobranca.editar` → 403; `tenant_id` forjado ignorado.
- Auditoria: cada update grava uma linha com `antes/depois` só dos campos alterados (incluindo a flag e escalões); update sem alterações não grava (ou grava vazio — decidir); linha não actualizável nem eliminável; chaves proibidas recusadas; isolamento por tenant.

**Financeiro — verificação**
- Activa + propina vencida com saldo → bloqueia; parcialmente paga → bloqueia; período iniciado mas não vencido → bloqueia (D3); Pendente futura → não bloqueia; Paga/Cancelada/Anulada → não bloqueia.
- Multa com saldo → bloqueia; multa isenta/anulada → não; escalão devido e ainda não aplicado pelo comando → aplicado sincronamente e bloqueia; correr a verificação 2× não duplica multa.
- Dívida noutra matrícula do mesmo aluno (ano anterior) → bloqueia (D4); de outro aluno/tenant → não.
- Crédito disponível → aviso, não compensa.
- Desactivada → nunca bloqueia, devolve avisos; nada é alterado.

**Matricula — trancar/reactivar**
- Transições: ACTIVA→TRANCADA ok; PENDENTE→TRANCADA, TRANCADA→CONCLUIDA recusadas; TRANCADA→CANCELADA/TRANSFERIDA ok; endpoint genérico recusa TRANCADA.
- Motivo obrigatório, data de efeito válida (≥ data_matricula, ≤ hoje, ≥ último evento), permissão `matricula.trancar`.
- Sem implementações etiquetadas (Financeiro desligado) → tranca; implementação falsa que bloqueia → recusa e nada muda; com avisos exige `confirmar_com_dividas`.
- Histórico com `data_efeito`, `motivo`, `detalhe`; `estado_descricao` sincronizada.
- Evento disparado só depois do commit; transacção revertida não dispara.
- Reactivação: ano lectivo encerrado, turma inactiva, duplicado na mesma identidade → recusadas; ok → ACTIVA + histórico + evento.
- Duplicados: TRANCADA ocupa no mesmo ano; não bloqueia matrícula no ano seguinte (D15). Encerramento do ano não altera TRANCADA.

**Integração Financeiro ↔ Matricula**
- Trancar cancela futuras sem pagamentos, mantém as com pagamentos e as do período em curso; dívidas, pagamentos e multas intactos (contagens e somas iguais antes/depois).
- Geração automática e manual não geram durante o trancamento; manual de período trancado recusada.
- Reactivação com `gerar_automaticamente` gera a partir do mês da reactivação, ao plano aplicável nesse momento (incluindo plano novo após §14 opção 2); 2× não duplica; meses intermédios sem propina.
- Reconciliação do comando repara listener falhado (D14).
- Multas continuam a aplicar-se às dívidas pré-trancamento (D9).
- Isolamento de tenant em todos os caminhos; build frontend e suite existente verdes.

## 6. Ordem de entrega, dependências e prioridade

**Pré-requisitos (têm de existir antes):**
1. **Propinas** (spec de Propinas §3–§9): tabela `propinas`, `GerarPropinasService`, cancelamento, extensão do catálogo `Acao` e o evento `MatriculaEstadoAlterado` (§9 já o exige).
2. **Pagamentos** (valor pago, créditos): sem eles "parcialmente paga" e "crédito" não existem.
3. **Multas §15** (aplicação idempotente): sem ela a parte "multas por liquidar" é vazia.

Sem 1–3 a configuração seria um interruptor sem efeito (não há dívida para verificar), e o desenho da verificação teria de ser refeito quando os modelos reais chegassem.

**Fases propostas (depois dos pré-requisitos):**
- **T1 — Core:** contrato + DTO; auditoria do tenant (se Q8 aprovada).
- **T2 — Matricula:** enum TRANCADA, migrações (`estado_descricao`, histórico), Actions trancar/reactivar, validações, rotas, permissão, UI. Testável com implementação falsa do contrato.
- **T3 — Financeiro (configuração):** coluna default true, DTO/Request/Action, auditoria do diff, cartão na UI.
- **T4 — Financeiro (operação):** verificação real, listener (cancelar futuras / retomar geração), geração por intervalos, locks, reconciliação.
- **T5 — Testes de integração** e verificação visual.

**Prioridade recomendada: ADIAR.** Concordo com a inclinação do dono: implementar **depois** de Propinas, Pagamentos e Multas estarem entregues. Única ressalva: ao implementar o §9 no plano de Propinas, criar já o evento `MatriculaEstadoAlterado` com `data_efeito` e não assumir que só existem os 5 estados actuais (o listener deve ignorar estados desconhecidos), para T2/T4 não obrigarem a refazer código. Se a escola precisar do trancamento **académico** antes do financeiro, T1+T2 podem sair isolados (sem implementações do contrato, nada bloqueia) — só por decisão explícita do dono.

**Nenhuma implementação (código, migrações, testes) começa antes de o dono aprovar este plano e responder às questões da secção 2.2.**
