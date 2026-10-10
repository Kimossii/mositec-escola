# Financeiro → Propinas — Design

**Data:** 2026-10-08
**Módulo:** `Modules/Financeiro` (subpastas `Propina`)
**Depende de:** `2026-10-08-modulo-financeiro-configuracao-design.md` (planos, alvos, regras, `Dinheiro`, `EstadoCobranca`)
**Alimenta:** `2026-10-08-financeiro-pagamentos-design.md`

## 1. Objectivo

Gerir a propina operacional: a obrigação financeira concreta de uma **matrícula** num **período**, gerada a partir de um Plano de Propina. Este módulo representa o que é *devido*. O que foi *recebido* pertence a Pagamentos.

## 2. Decisões fechadas

1. A propina pertence sempre a uma matrícula (`matricula_id` obrigatório) e referencia o plano que a originou.
2. Dinheiro em unidades menores da moeda da escola (`Dinheiro`). Estado com `estado` + `estado_descricao`. Tenancy via `PertenceAoTenant`.
3. **Pendente e Em Atraso são derivados.** Os estados persistidos são cinco: Em Aberto, Parcialmente Paga, Paga, Cancelada e Anulada.
4. A propina guarda um **snapshot** do plano e das regras no momento da geração. Alterações posteriores nunca a afectam.
5. `saldo` não é coluna: é `valor - valor_pago`. `valor_pago` é um cache mantido apenas por `RecalcularPropina` (secção 6).
6. Geração só para matrículas **Activa**.

## 3. Modelo de dados — `propinas`

| Coluna | Regra |
|---|---|
| tenant_id | via trait |
| matricula_id | FK obrigatória |
| plano_propina_id | FK obrigatória (referência de origem) |
| ordem | smallint ≥ 1: posição do período no plano |
| periodo_inicio, periodo_fim | date: primeiro e último dia dos meses cobertos, vindos de `competencias()` |
| valor | `Dinheiro`, snapshot do valor do plano |
| valor_pago | `Dinheiro`, cache, `0 ≤ valor_pago ≤ valor` (CHECK) |
| capital_liquidado_em | date nulo, cache mantido só por `RecalcularPropina`: `data_pagamento` do pagamento (ou utilização de crédito) que levou o saldo de capital a 0; nulo enquanto `saldo > 0` (volta a nulo se o pagamento for anulado ou o valor reajustado) |
| data_vencimento | date, snapshot (secção 5) |
| dias_tolerancia | tinyint, snapshot de `regras_cobranca` |
| estado (+descricao) | enum persistido de 5 valores |
| motivo_cancelamento / motivo_anulacao, *_por, *_em | preenchidos ao cancelar/anular |
| criado_por, editado_por, timestamps | `RegistaAutoria` |

- `unique(tenant_id, matricula_id, plano_propina_id, ordem)`.
- Índices: `(tenant_id, matricula_id, periodo_inicio)` e `(tenant_id, estado, data_vencimento)`.
- Invariantes validadas na geração: `plano.ano_lectivo_id == matricula.ano_lectivo_id`, e plano e matrícula do mesmo tenant.
- **Cobrança dupla (garantia em duas camadas):** (1) a BD impõe um **índice único parcial** `unique(tenant_id, matricula_id, periodo_inicio) WHERE estado IN (Em Aberto, Parcialmente Paga, Paga)` — Cancelada e Anulada ficam de fora para permitir regerar; criado com SQL cru (sem sintaxe fluente; suportado por PostgreSQL e SQLite); (2) a Action verifica a **sobreposição de intervalos** `[periodo_inicio, periodo_fim]` com outras propinas activas da mesma matrícula (o índice só apanha inícios iguais, não um trimestre contra um mês), sob bloqueio da matrícula (`lockForUpdate`).
- **Imutabilidade:** `valor_original` não está em `$fillable` e o model lança excepção se for alterado; `propina_ajustes` só aceita `create` (o model recusa `update`/`delete`). Teste obrigatório para ambos.
- **Invariante auditável:** `valor = valor_original + Σ(valor_novo − valor_anterior)` dos ajustes, e cada ajuste continua o anterior (`valor_anterior` = `valor_novo` do ajuste precedente da mesma propina). Teste que reconcilia todas as propinas depois de qualquer operação.

## 4. Estado resolvido

`EstadoCobranca::resolver(propina, hoje)` devolve o estado mostrado e filtrado. Precedência:

1. Cancelada ou Anulada (persistidos, terminais).
2. Paga (`saldo = 0`).
3. **Em Atraso**: `saldo > 0` e `hoje > data_vencimento + dias_tolerancia` (dias corridos).
4. Parcialmente Paga (`valor_pago > 0`).
5. **Pendente**: `periodo_inicio > hoje` e `valor_pago = 0`.
6. Em Aberto.

Quando uma propina em atraso já tem pagamentos, o estado mostrado é Em Atraso e a UI mostra também o valor pago. Os filtros por estado resolvido traduzem-se em queries por data (nunca em job).

## 5. Vencimento
`data_vencimento` = `regras_cobranca.dia_vencimento` aplicado ao mês de `periodo_inicio`. Como o dia é 1–28, a data é sempre válida. Numa geração retroactiva a data pode já estar no passado, e a propina fica em atraso de imediato. É o comportamento esperado.

## 6. Recálculo (ponto único)
`RecalcularPropina::executar(propina)` corre dentro da transacção do chamador, com a propina bloqueada (`lockForUpdate`):
- `valor_pago` = soma das distribuições de pagamentos **Confirmado** + soma das utilizações de crédito **Activa**.
- Ajusta o estado persistido entre Em Aberto, Parcialmente Paga e Paga. Nunca altera Cancelada ou Anulada.
- Falha se o resultado violar `0 ≤ valor_pago ≤ valor`.
- Actualiza `capital_liquidado_em` e, se existir multa, delega em `RecalcularMulta` (Propinas §15) para o seu `valor_pago`.

Mais nenhum código escreve `valor_pago` ou `estado`. Pagamentos chama este serviço, e é esta a "estrutura para integração" exigida.

## 7. Geração

`GerarPropinasService`, usado pela geração manual e pela automática.

1. **Plano aplicável:** `ResolvePlanoAplicavel` (configuração) escolhe o plano da matrícula pela turma, nível e curso. É o único ponto que decide isto.
2. **Elegibilidade:** matrícula Activa. São geradas as competências cujo `periodo_fim` é igual ou posterior ao primeiro dia do mês de `data_matricula`. Períodos anteriores só se criam manualmente, com permissão.
3. **Valores:** `valor`, `dias_tolerancia` e `data_vencimento` copiados no momento da geração.
4. **Idempotência:** inserção protegida pela unicidade, dentro de uma transacção por matrícula. Executar duas vezes não duplica. O resultado devolve `criadas`, `ignoradas` (já existiam) e `conflitos` (outro plano para o mesmo período).
5. **Manual:** modal com plano e âmbito (uma matrícula, uma turma, um nível ou todas as do plano), com pré-visualização do que será criado e ignorado antes de confirmar.
6. **Automática:** comando agendado por tenant (job com contexto de tenant), só se `gerar_automaticamente` for true. Usa exactamente o mesmo serviço.

## 8. Cancelar e anular
- **Cancelar** (permissão `cancelar`, motivo obrigatório): só Em Aberto, sem `valor_pago` e sem histórico de pagamentos confirmados.
- **Anular** (permissão `anular`, motivo obrigatório): só com `valor_pago = 0` mas com histórico (todos os pagamentos que a tocaram já anulados).
- Se a propina tem multa: sem pagamentos de multa, a multa passa a ANULADA com a propina; com pagamentos de multa Confirmados, cancelar/anular é bloqueado até se anularem esses pagamentos.
- Nenhuma propina é apagada fisicamente. Eliminar não existe.

## 9. Eventos de matrícula
Quando a matrícula passa a **Cancelada** ou **Transferida**, as propinas com `periodo_inicio` posterior à data da mudança, sem pagamentos, são Canceladas automaticamente (motivo: estado da matrícula). As outras ficam como dívida. A fronteira é por evento (`MatriculaEstadoAlterado`, criado no módulo Matricula se não existir): Financeiro escuta, Matricula não depende de Financeiro. Dívidas de matrículas Concluídas, Canceladas ou Transferidas continuam pagáveis.

## 10. Interface
Lista com filtros (ano lectivo, estado resolvido, aluno/matrícula, plano, intervalo de vencimento) e detalhe da propina com plano de origem, snapshot, matrícula e distribuições (só leitura, preenchidas por Pagamentos). Botão Gerar com o modal da secção 7. Padrões existentes de lista e `bg-body-secondary`.

## 11. Permissões
Novo caso `Modulo::PROPINA` (slug `propina`) com ver, listar, criar (gerar), cancelar, anular e exportar. Menu **Financeiro** (operação), separado de Configurações.

## 12. Testes
- Geração: idempotência, competências correctas (incluindo atravessamento do ano), a partir do mês da matrícula, só Activa, plano aplicável por especificidade, conflito de plano, invariante de ano lectivo e de tenant.
- Snapshot: mudar valor, regra ou plano não altera propinas existentes.
- Estado resolvido: cada ramo da precedência, fronteira de tolerância e Pendente.
- `RecalcularPropina`: transições automáticas, CHECK, reversão e bloqueio de Cancelada/Anulada.
- Cancelar e anular: pré-condições, motivo obrigatório, não existe eliminação.
- Evento de matrícula: cancela só as futuras sem pagamentos.
- Isolamento de tenant (A não vê, não gera e não cancela as de B) e permissões por acção.

## 13. Fora de âmbito
Registo e distribuição de pagamentos, crédito, acordos de pagamento (prazos e prestações), descontos individuais e bolsas, recibos, relatórios, juros de mora e o estado de matrícula "Trancada".
- Trancamento de matrícula (exigência de regularização financeira configurável por escola): ver `docs/superpowers/plans/2026-10-12-trancamento-matricula-plano.md` (proposta, a implementar depois de Propinas, Pagamentos e Multas; aguarda aprovação do dono).

## 14. Alteração de preço de um plano e tratamento das propinas em aberto (módulo de Propinas; decisão registada, ainda não implementada)

**Princípio.** O plano é o preço de agora em diante; o que foi cobrado antes fica registado. Os planos **nunca** alteram propinas retroactivamente de forma silenciosa: quando o valor de um plano que já tem propinas geradas é alterado, a escola decide **nesse momento**, numa única operação explícita (`AlterarPrecoPlanoAction`), como tratar as propinas já geradas. Se o plano ainda não tem propinas, a edição é simples e não pergunta nada. A decisão nunca é uma regra automática global: **não existe política predefinida em `regras_cobranca` nem opção pré-seleccionada** no ecrã; o utilizador tem de escolher explicitamente a cada alteração, e a escolha, o motivo (obrigatório) e o autor ficam em `alteracoes_preco_plano`.

**Âmbito.** A operação aplica-se às propinas **elegíveis** do plano seleccionado: persistidas como Em Aberto ou Parcialmente Paga (incluindo as que se resolvem como Em Atraso/Pendente). Paga, Cancelada e Anulada **nunca** são alteradas.

**Parâmetros e escolha (sem opção pré-seleccionada).** A operação pede, explicitamente: o **novo valor**, o **mês de efeito** (`mes_efeito`, obrigatório, tem de coincidir com o início de um período do plano) e **uma de duas escolhas**:
1. **Aplicar às propinas já geradas.** As propinas do plano com competência **igual ou posterior** ao `mes_efeito` que estejam **em aberto ou parcialmente pagas** passam ao novo valor (`valor` actualizado, `valor_pago` intacto, saldo recalculado `valor − valor_pago`). As propinas Pagas, Canceladas e Anuladas não mudam. As ainda não geradas nascem ao novo valor.
2. **Manter as já geradas.** Nenhuma propina já gerada é alterada; o novo valor aplica-se apenas às propinas **ainda não geradas** (novas matrículas, geração manual pendente, períodos futuros por gerar). A pré-visualização avisa que, na geração por competências completas (§7), isto deixa alunos já matriculados ao valor antigo e novas matrículas ao valor novo **no mesmo mês e turma**.
Em **ambas** as escolhas: as propinas com competência **anterior** ao `mes_efeito` mantêm o valor antigo (para reajustar dívidas antigas, a escola escolhe um `mes_efeito` igual ao primeiro mês em dívida); o plano é dividido em `mes_efeito` (plano antigo termina no mês anterior, plano novo com os mesmos alvos, mesmo ano lectivo, periodicidade e novo valor, até ao fim do plano original).

**Regras fechadas (divisão do plano e reassociação)**
- **Fronteira de período.** `mes_efeito` tem de coincidir com o início de um período do plano antigo (em planos de vários meses não se parte uma propina). Se o `mes_efeito` não for fronteira de período, ou se o plano antigo ficasse sem nenhum mês (`mes_efeito` = início do plano), a operação é recusada com mensagem clara; nesse último caso a escola altera o valor do plano directamente (a regra de reassociação aplica-se com a escolha 1 sobre todo o plano).
- **Meses anteriores ao `mes_efeito`:** propinas, plano, valor, `ordem` e vencimento não são tocados, qualquer que seja o estado.
- **Escolha 1 — meses a partir do `mes_efeito`, em aberto ou parcialmente pagas:** reassociadas ao plano novo (`plano_propina_id`), com `ordem` recalculada pelo `periodo_inicio` no plano novo (se algum `periodo_inicio` não existir no plano novo, a operação é recusada na pré-visualização), `valor` reajustado e vencimento original mantido. Reassociar não gera propina nova: não há cobrança dupla.
- **Escolha 2 — meses a partir do `mes_efeito` já gerados:** ficam no plano antigo, intactos, qualquer que seja o estado. **Escolha 1, pagas, canceladas ou anuladas:** ficam no plano antigo, intactas. A propina é o seu próprio snapshot de período: **nunca se valida contra as competências do plano actual**. O gerador trata o mês como já coberto sempre que existe propina activa (Em Aberto, Parcialmente Paga ou Paga) para essa matrícula e `periodo_inicio`, seja de que plano for, sem erro.
- **Meses ainda não gerados:** serão gerados pelo plano novo ao preço novo.
- **Ordem na transacção:** (1) bloquear as propinas elegíveis (`lockForUpdate`, na mesma ordem do `RecalcularPropina`); (2) encurtar o plano antigo; (3) criar o plano novo (nome gerado, único no ano lectivo, e alvos copiados); (4) reassociar e reajustar; (5) registar `alteracoes_preco_plano` e `propina_ajustes`.
- **Pagamento parcial.** O recebido (`valor_pago`) nunca muda. Preço novo ≥ `valor_pago`: `valor` = preço novo e saldo = `valor − valor_pago` (a pré-visualização mostra-o propina a propina). Preço novo < `valor_pago` (descida): `valor` = `valor_pago`, a propina passa a Paga via `RecalcularPropina`, sem devolução, e a pré-visualização assinala o excesso.
- **Concorrência.** Se entre a pré-visualização e a confirmação o conjunto elegível ou algum `valor_pago` mudou, a confirmação é recusada e a pré-visualização refeita.
- **Permissão.** A acção `ajustar` obriga a estender o catálogo `Acao` (dívida conhecida; entra no plano de Propinas).

**Regra obrigatória: isolamento por ano lectivo.** Uma alteração de preço pertence a **um único ano lectivo**, o do plano alterado (`planos_propina.ano_lectivo_id`, imutável), e afecta **exclusivamente** o plano e as propinas desse ano.
- Propinas e dívidas de **anos anteriores** ficam inalteradas, mesmo por pagar; planos e propinas de **anos futuros** também. O `mes_efeito` e a escolha (APLICAR_GERADAS | MANTER_GERADAS) só valem dentro desse ano: `mes_efeito` é um mês do calendário do ano lectivo do plano.
- O plano novo criado na divisão herda o **mesmo** `ano_lectivo_id`; nunca se cria nem se altera um plano de outro ano.
- **Garantia na Action (defesa em profundidade):** o conjunto a alterar parte sempre de `propinas.plano_propina_id` do plano alterado e é verificado antes de escrever: toda a propina candidata tem `matricula.ano_lectivo_id = plano.ano_lectivo_id` (invariante da geração, §3); se alguma violar, a operação aborta inteira sem escrever. A impressão digital da pré-visualização inclui o `ano_lectivo_id`.
- Corrigir dívidas de um ano anterior é uma **operação separada**, feita explicitamente sobre o plano desse ano, com a sua própria pré-visualização, motivo e registo; nunca um efeito colateral de alterar o plano do ano corrente.
- A **pré-visualização** mostra de forma explícita o ano lectivo abrangido (nome e id) e uma linha de garantia: "Nenhuma propina de outros anos lectivos será alterada" (com a contagem de propinas de outros anos afectadas = 0, verificada).
- **Testes obrigatórios:** duas operações em anos diferentes (propinas do outro ano, em aberto, intactas, incluindo `valor`, `valor_pago`, plano e ajustes); ano anterior com dívida em aberto não é tocado; ano futuro com plano de alvos iguais não é tocado nem colide; `mes_efeito` fora do calendário do ano é recusado; propina de outro ano injectada no conjunto (fixture) faz abortar a operação; pré-visualização inclui ano e contagem 0 de outros anos; fingerprint inválida se o ano mudar.

**Pré-visualização obrigatória (sem escrita).** Antes de confirmar, o sistema mostra: a escolha feita e o `mes_efeito`, plano(s) resultante(s) e períodos, nº de propinas em aberto afectadas, por propina o valor antes e depois e o saldo antes e depois, total antes/depois, parciais com valor recebido e as que ficam inalteradas (e porquê). A confirmação exige motivo.

**Invariantes**
- Uma só transacção (tudo ou nada). Se, entre a pré-visualização e a confirmação, o conjunto elegível mudou (pagamento, anulação), a operação é recusada e a pré-visualização refeita.
- O valor original nunca se perde: `propinas.valor_original` é imutável (snapshot da geração); `propinas.valor` é o valor corrente, só alterado por esta operação; `saldo = valor − valor_pago`.
- `propina_ajustes` só se acrescenta (sem editar nem eliminar); reverter é uma nova alteração com o seu motivo.
- Permissão própria (`propina` com acção `ajustar`); mesma moeda do tenant em todos os registos.
- A propina nunca é recalculada em leitura a partir do plano.
- **Única porta de entrada:** o `valor` de um plano com propinas só muda por esta Action; a edição genérica do plano recusa essa alteração.
- **Atomicidade de ponta a ponta:** nenhum efeito fora da BD (cache, eventos, notificações) corre dentro da transacção (`DB::afterCommit`); dentro dela não se apanham excepções para continuar (em PostgreSQL abortariam a transacção) — qualquer falha reverte tudo. Teste obrigatório que injecta uma falha em cada etapa (plano novo, reassociação, reajuste, registos) e prova que plano antigo, propinas e tabelas de registo ficam inalterados.
- **Confirmação idempotente:** a confirmação leva a **impressão digital** (hash) da pré-visualização (ids, valores, `valor_pago` e estados das propinas elegíveis); se não coincidir com o estado bloqueado, é recusada. Um segundo clique não repete a operação.
- **Limite dos testes:** a suite corre em SQLite, que ignora `lockForUpdate`; a protecção contra concorrência só se valida em PostgreSQL. Fica registado como risco aceite até haver teste em pgsql.

**Tabelas e relações (a criar no plano de Propinas)**
- `alteracoes_preco_plano`: `tenant_id`, `plano_propina_id` (plano alterado), `plano_novo_id` (plano criado em `mes_efeito`; nulo só quando `mes_efeito` = início do plano e o valor do plano é alterado in loco), `modo` (APLICAR_GERADAS | MANTER_GERADAS), `valor_anterior`, `valor_novo`, `mes_efeito` (obrigatório), `motivo`, `total_propinas_afectadas`, `criado_por`, timestamps.
- `propina_ajustes`: `tenant_id`, `alteracao_preco_id` (FK), `propina_id` (FK), `valor_anterior`, `valor_novo`, `valor_pago_no_momento`, `plano_anterior_id`, `plano_novo_id` (quando há reassociação), `motivo`, `criado_por`, `created_at`.
- `propinas` ganha `valor_original` (imutável).

Fora de âmbito: acordos de prazo/prestações e juros de mora (plano de Dívidas e Negociação) e desconto individual por aluno (Descontos e Bolsas).

## 15. Multas por atraso (decisão fechada; ainda não implementada)

Configuração: spec de Configurações (`regras_cobranca.multa_activa` + `escaloes_multa`). Aqui definem-se o modelo, a aplicação e a isenção.

### 15.1 Decisões
1. **Base:** saldo de **capital** em dívida, **congelado** no registo da multa (nunca inclui multas).
2. **Imputação:** primeiro o capital da propina, depois a sua multa. O capital pode ficar Pago com multa pendente; o **estado da propina depende só do capital** e a multa pendente é mostrada à parte.
3. **Aplicação:** automática, por comando diário idempotente `financeiro:aplicar-multas` (convenção `ParaTodosOsTenants`). **Isenção ou redução:** manual, permissão própria `propina.isentar-multa`, motivo obrigatório, com histórico.
4. **Tipo por escalão:** percentagem (pontos-base) ou valor fixo.
5. **Uma única multa por propina.** Quando o escalão sobe, o `valor` da multa passa ao novo valor e só é exigida a **diferença** para o já pago.
6. **Histórico:** multas aplicadas não são alteradas por mudanças nas Regras de Cobrança.
7. Os juros de mora continuam fora de âmbito.

### 15.2 Tabelas
**`propina_multas`** (1:1 com `propinas`) — `tenant_id`, `propina_id` (**`unique(tenant_id, propina_id)`**), `escalao_ordem` (último escalão aplicado), `tipo` e `valor_regra` (snapshot do escalão: pontos-base ou unidades menores), `base_capital` (congelada em L₁, ver 15.3), `valor` (multa vigente), `valor_pago` (cache, `0 ≤ valor_pago ≤ valor`, CHECK), `estado` (+ `estado_descricao`: ACTIVA, REDUZIDA, ISENTA, ANULADA), `bloqueada_automatico` (bool: true após qualquer revisão manual), `criado_por`/`editado_por` (nulos quando criada pelo comando), timestamps. Sem FK para `escaloes_multa`.
**`propina_multa_revisoes`** (N:1 com `propina_multas`; **só acrescenta**, o model recusa `update`/`delete`) — `tenant_id`, `propina_multa_id`, `origem` (AUTOMATICA | MANUAL), `escalao_ordem` (nulo se manual), `valor_anterior`, `valor_novo`, `data_referencia` (L_k, para as automáticas), `motivo` (obrigatório se manual), `criado_por` (nulo se automática), `created_at`. **`unique(propina_multa_id, escalao_ordem)` onde `origem = AUTOMATICA`** (índice parcial, SQL cru).
**`pagamento_multas`** (Pagamentos): distribui parte de um pagamento para uma multa.

### 15.3 Cálculo (determinístico, independente do dia em que o comando corre)
Para cada propina com `multa_activa`, não Cancelada nem Anulada (inclui as já Pagas: o atraso pode ter existido antes de o capital ser pago), e cada escalão k da regra em vigor:
- `L_k = data_vencimento + dias_tolerancia + dias_atraso_k` (dias corridos; datas da propina, snapshot).
- `saldo_em(d) = valor − Σ(distribuições Confirmadas e utilizações de crédito Activas com data_pagamento < d)`. O escalão k **aplica-se** se `L_k ≤ hoje` e `saldo_em(L_k) > 0`, i.e. o capital ainda estava em dívida no início do dia L_k.
- `base_capital = saldo_em(L₁)`, **fixada ao criar a multa e nunca recalculada**. Valor do escalão: PERCENTAGEM → `base_capital × pontos_base / 10000` (arredondado para baixo, como `Dinheiro::percentagem`); VALOR_FIXO → o próprio valor.
- `valor_novo = max(valor_actual, valor_k)`: **a multa nunca baixa automaticamente** (mesmo se um escalão fixo for menor que o anterior).
- Se o capital foi liquidado antes de L_k (`saldo_em(L_k) = 0`), os escalões seguintes **não se aplicam**: a multa congela no valor vigente à data em que o capital foi pago.
- **Decisão explícita (confirmada pelo dono):** um pagamento registado com atraso, mas com `data_pagamento` efectiva anterior a um L_k cujo escalão já foi aplicado, **não reverte automaticamente** a multa. A multa mantém-se e a correcção é uma **redução ou isenção manual** (`propina.isentar-multa`, motivo obrigatório, registada em `propina_multa_revisoes`). Razão: reverter automaticamente permitiria alterar multas já aplicadas, e possivelmente já cobradas e recibadas, por um registo tardio; a decisão de perdoar é humana e auditável.
- Se o capital reabre (pagamento anulado, ou reajuste que cria saldo), o comando retoma na execução seguinte, aplicando em atraso os escalões cujo L_k já passou com `saldo_em(L_k) > 0`.

### 15.4 Subida de escalão, com capital já pago, e cálculo da diferença
- **Capital totalmente pago antes de L_k:** o escalão k **não sobe** a multa (ver acima). Capital pago só **depois** de L_k: o escalão k aplicou-se (o aluno esteve em dívida nesse dia) e a multa fica no valor k, mesmo que o capital já esteja pago quando o comando corre (catch-up por L_k, não pela data de execução).
- **Diferença exigida** = `valor_vigente − valor_pago_da_multa` (nunca negativa, porque `valor` só sobe e a redução manual não desce abaixo de `valor_pago`). Exemplo: base 20 000; escalão 1 = 5 % → multa 1 000, paga na íntegra; escalão 2 = 10 % → `valor = 2 000`, `valor_pago = 1 000` → exigido 1 000. Se o escalão 1 estiver só parcialmente pago (400), o saldo da multa passa a 1 600.
- A multa vigente só sobe por escalões; isenção/redução manual define `valor` (≥ `valor_pago`), passa a REDUZIDA (ou ISENTA se `valor = valor_pago`) e põe `bloqueada_automatico = true`: o comando deixa de a alterar.

### 15.5 Prevenção de aplicação duplicada (4 camadas)
1. **Esquema:** `unique(tenant_id, propina_id)` em `propina_multas` (uma só multa) e `unique(propina_multa_id, escalao_ordem)` parcial nas revisões automáticas.
2. **Escrita condicional:** criação por `INSERT … ON CONFLICT DO NOTHING` (`insertOrIgnore`, sem apanhar excepções, para não abortar a transacção em PostgreSQL); subida por `UPDATE propina_multas SET escalao_ordem = k, valor = … WHERE id = ? AND escalao_ordem < k AND NOT bloqueada_automatico` e só se `affected = 1` se escreve a revisão. Duas execuções simultâneas: a segunda afecta 0 linhas e não faz nada.
3. **Concorrência:** uma transacção por propina, com a propina bloqueada (`lockForUpdate`, mesma ordem do `RecalcularPropina`); comando com `withoutOverlapping` e *lock* por tenant.
4. **Idempotência por construção:** o comando só avalia escalões com `ordem > escalao_ordem` da multa; correr 2× no mesmo dia ou reprocessar um período não produz efeitos novos. Teste obrigatório.

### 15.6 Regras de interacção
- **Reajuste de preço (§14):** multas aplicadas não se recalculam; a pré-visualização lista-as como "não alteradas".
- **Regras de Cobrança alteradas:** só afectam escalões ainda não atingidos; multas e snapshots existentes mantêm-se. Desactivar `multa_activa` pára novas aplicações.
- **Estado resolvido (§4):** inalterado, depende do capital. A UI mostra "multa pendente" = `valor − valor_pago` por propina e no total do aluno.
- **Permissões:** `propina.isentar-multa` (acção nova no catálogo `Acao`, dívida já conhecida) para isentar/reduzir; ver multas segue `propina.ver`.

### 15.7 Testes obrigatórios
Percentagem e valor fixo; arredondamento; moeda sem decimais; base congelada (pagamento parcial depois de L₁ não altera a base); `max()` nunca baixa; capital pago antes de L₂ congela; capital pago depois de L₂ aplica; catch-up com comando parado vários dias; diferença com multa parcialmente paga; isenção/redução bloqueia o automático; revisões imutáveis; comando 2× e concorrente sem duplicar (a concorrência real só se valida em PostgreSQL); regras alteradas não mexem em multas existentes; `multa_activa` desligada; cancelar propina com e sem multa paga; reconciliação `valor_pago` da multa = Σ `pagamento_multas`; isolamento de tenant.
