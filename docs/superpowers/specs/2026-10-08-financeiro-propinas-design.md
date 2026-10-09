# Financeiro → Propinas — Design

**Data:** 2026-10-08
**Módulo:** `Modules/Financeiro` (subpastas `Propina`)
**Depende de:** `2026-10-08-modulo-financeiro-configuracao-design.md` (planos, alvos, regras, `Dinheiro`, `EstadoCobranca`)
**Alimenta:** `2026-10-08-financeiro-pagamentos-design.md`

## 1. Objectivo

Gerir a propina operacional: a obrigação financeira concreta de uma **matrícula** num **período**, gerada a partir de um Plano de Propina. Este módulo representa o que é *devido*. O que foi *recebido* pertence a Pagamentos.

## 2. Decisões fechadas

1. A propina pertence sempre a uma matrícula (`matricula_id` obrigatório) e referencia o plano que a originou.
2. Dinheiro em cêntimos (`Dinheiro`). Estado com `estado` + `estado_descricao`. Tenancy via `PertenceAoTenant`.
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
Registo e distribuição de pagamentos, crédito, acordos de pagamento (prazos e prestações), descontos individuais e bolsas, recibos, relatórios, multas e juros, e o estado de matrícula "Trancada".

## 14. Alteração de preço de um plano e tratamento das propinas em aberto (módulo de Propinas; decisão registada, ainda não implementada)

**Princípio.** O plano é o preço de agora em diante; o que foi cobrado antes fica registado. Os planos **nunca** alteram propinas retroactivamente de forma silenciosa: quando o valor de um plano que já tem propinas geradas é alterado, a escola decide **nesse momento**, numa única operação explícita (`AlterarPrecoPlanoAction`), como tratar as propinas em aberto. Se o plano ainda não tem propinas, a edição é simples e não pergunta nada. A decisão nunca é uma regra automática global.

**Âmbito.** A operação aplica-se às propinas **elegíveis** do plano seleccionado: persistidas como Em Aberto ou Parcialmente Paga (incluindo as que se resolvem como Em Atraso/Pendente). Paga, Cancelada e Anulada **nunca** são alteradas.

**Duas opções**
1. **Actualizar o preço e reajustar as dívidas.** O `valor` do plano muda e as propinas elegíveis passam ao valor novo. Cada reajuste gera um registo em `propina_ajustes` (valor anterior, valor novo, motivo, autor, data). Pagamentos parciais: o valor já recebido (`valor_pago`) é preservado; o novo `valor` da propina é o preço novo, o saldo passa a `valor_novo − valor_pago`; se o preço novo for inferior ao `valor_pago`, o `valor` fica igual ao `valor_pago` (propina Paga, sem devolução, sinalizado na pré-visualização). As propinas pagas ficam com o valor que foi pago.
2. **Manter o preço antigo para as dívidas existentes.** O plano antigo é preservado para os meses anteriores e o sistema cria automaticamente um **plano novo** com os mesmos alvos (`plano_propina_alvos` copiados), o mesmo ano lectivo, periodicidade e valor novo, a começar no mês indicado (`mes_efeito`, por omissão o mês seguinte ao último mês já vencido) até ao fim do plano original; o plano antigo passa a terminar no mês anterior ao `mes_efeito`. As propinas em aberto dos meses anteriores ao `mes_efeito` ficam no plano antigo, ao preço antigo. As propinas em aberto dos meses a partir de `mes_efeito` (se já geradas) são reassociadas ao plano novo e reajustadas, com registo em `propina_ajustes`. A escola não faz estes passos à mão.

**Regras fechadas da opção 2 (reassociação)**
- **Fronteira de período.** `mes_efeito` tem de coincidir com o início de um período do plano antigo (em planos de vários meses não se parte uma propina). Sem fronteira válida a partir do mês pedido, ou se o plano antigo ficasse sem nenhum mês (`mes_efeito` = início do plano), só a opção 1 é oferecida.
- **Meses anteriores ao `mes_efeito`:** propinas, plano, valor, `ordem` e vencimento não são tocados, qualquer que seja o estado.
- **Meses a partir do `mes_efeito`, em aberto ou parcialmente pagas:** reassociadas ao plano novo (`plano_propina_id`), com `ordem` recalculada pelo `periodo_inicio` no plano novo (se algum `periodo_inicio` não existir no plano novo, a operação é recusada na pré-visualização), `valor` reajustado e vencimento original mantido. Reassociar não gera propina nova: não há cobrança dupla.
- **Meses a partir do `mes_efeito`, pagas, canceladas ou anuladas:** ficam no plano antigo, intactas. A propina é o seu próprio snapshot de período: **nunca se valida contra as competências do plano actual**. O gerador trata o mês como já coberto sempre que existe propina activa (Em Aberto, Parcialmente Paga ou Paga) para essa matrícula e `periodo_inicio`, seja de que plano for, sem erro.
- **Meses ainda não gerados:** serão gerados pelo plano novo ao preço novo.
- **Ordem na transacção:** (1) bloquear as propinas elegíveis (`lockForUpdate`, na mesma ordem do `RecalcularPropina`); (2) encurtar o plano antigo; (3) criar o plano novo (nome gerado, único no ano lectivo, e alvos copiados); (4) reassociar e reajustar; (5) registar `alteracoes_preco_plano` e `propina_ajustes`.
- **Pagamento parcial.** O recebido (`valor_pago`) nunca muda. Preço novo ≥ `valor_pago`: `valor` = preço novo e saldo = `valor − valor_pago` (a pré-visualização mostra-o propina a propina). Preço novo < `valor_pago` (descida): `valor` = `valor_pago`, a propina passa a Paga via `RecalcularPropina`, sem devolução, e a pré-visualização assinala o excesso.
- **Concorrência.** Se entre a pré-visualização e a confirmação o conjunto elegível ou algum `valor_pago` mudou, a confirmação é recusada e a pré-visualização refeita.
- **Permissão.** A acção `ajustar` obriga a estender o catálogo `Acao` (dívida conhecida; entra no plano de Propinas).

**Pré-visualização obrigatória (sem escrita).** Antes de confirmar, o sistema mostra: opção escolhida, plano(s) resultante(s) e períodos, nº de propinas em aberto afectadas, por propina o valor antes e depois e o saldo antes e depois, total antes/depois, parciais com valor recebido e as que ficam inalteradas (e porquê). A confirmação exige motivo.

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
- `alteracoes_preco_plano`: `tenant_id`, `plano_propina_id` (plano alterado), `plano_novo_id` (nulo na opção 1), `modo` (ACTUALIZAR | MANTER_ANTIGO), `valor_anterior`, `valor_novo`, `mes_efeito` (opção 2), `motivo`, `total_propinas_afectadas`, `criado_por`, timestamps.
- `propina_ajustes`: `tenant_id`, `alteracao_preco_id` (FK), `propina_id` (FK), `valor_anterior`, `valor_novo`, `valor_pago_no_momento`, `plano_anterior_id`, `plano_novo_id` (quando há reassociação), `motivo`, `criado_por`, `created_at`.
- `propinas` ganha `valor_original` (imutável).

Fora de âmbito: acordos de prazo/prestações, multas e juros (plano de Dívidas e Negociação) e desconto individual por aluno (Descontos e Bolsas).
