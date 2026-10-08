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
- **Cobrança dupla:** a Action rejeita uma propina activa (Em Aberto, Parcialmente Paga ou Paga) para a mesma matrícula e `periodo_inicio` com outro plano.

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
Registo e distribuição de pagamentos, crédito, dívidas e negociação, descontos e bolsas, recibos, relatórios, multas e juros, e o estado de matrícula "Trancada".
