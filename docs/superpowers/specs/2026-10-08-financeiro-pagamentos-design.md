# Financeiro → Pagamentos — Design

**Data:** 2026-10-08
**Módulo:** `Modules/Financeiro` (subpastas `Pagamento`, `Credito`)
**Depende de:** configuração (métodos, regras, `Dinheiro`) e `2026-10-08-financeiro-propinas-design.md` (`RecalcularPropina`)

## 1. Objectivo

Registar o que foi efectivamente *recebido*, distribuí-lo pelas propinas e guardar o excedente como crédito do aluno, com histórico completo e anulação reversível.

## 2. Decisões fechadas

1. Dinheiro em cêntimos. Autoria, tenancy e `estado` + `estado_descricao` como no resto do módulo.
2. **V1 só tem Confirmado e Anulado.** Sem estado Pendente nem fluxo de conciliação. Um pagamento regista-se já confirmado.
3. **O crédito pertence ao aluno** (não à matrícula), para poder ser usado em qualquer matrícula futura do mesmo aluno.
4. Nenhuma linha financeira é apagada. O efeito de uma linha depende do estado do seu pai.
5. `valor_pago` das propinas e `valor_utilizado` dos créditos são caches; só serviços dedicados os escrevem.
6. Um pagamento pertence a uma matrícula. Um pagamento que cubra vários irmãos regista-se como um pagamento por matrícula.

## 3. Modelo de dados

### `pagamentos`
| Coluna | Regra |
|---|---|
| tenant_id, matricula_id | obrigatórios, mesmo tenant |
| metodo_pagamento_id | activo e do tenant no momento do registo |
| numero | sequencial por tenant e ano (`SequenciaPorTenant` / `GeradorSequencia`), base dos recibos futuros |
| valor | `Dinheiro` > 0 |
| data_pagamento | date, não pode ser futura |
| referencia, observacao | opcionais |
| estado (+descricao) | CONFIRMADO, ANULADO |
| motivo_anulacao, anulado_por, anulado_em | obrigatórios ao anular |
| criado_por, editado_por, timestamps | `RegistaAutoria` |

- `unique(tenant_id, metodo_pagamento_id, referencia)` quando `referencia` não é nula.
- Invariante: `valor = soma(distribuições) + crédito gerado`.

### `pagamento_propinas` (distribuição)
`tenant_id`, `pagamento_id`, `propina_id`, `valor` > 0, timestamps. Invariante: `propina.matricula_id == pagamento.matricula_id` e mesmo tenant. Conta como pago apenas enquanto o pagamento estiver Confirmado.

### `creditos`
`tenant_id`, `aluno_id`, `origem_pagamento_id` (único), `valor`, `valor_utilizado` (cache), `estado` (DISPONIVEL, ESGOTADO, ANULADO), timestamps. `saldo = valor - valor_utilizado` é calculado. CHECK `0 ≤ valor_utilizado ≤ valor`.

### `credito_utilizacoes`
`tenant_id`, `credito_id`, `propina_id`, `valor` > 0, `estado` (ACTIVA, ANULADA), `criado_por`, `anulado_por`, `anulado_em`, `motivo_anulacao`, timestamps. A propina tem de pertencer a uma matrícula do mesmo aluno do crédito.

## 4. Registar pagamento (`RegistarPagamentoAction`)

Uma só transacção. Primeiro ordena e bloqueia (`lockForUpdate`, por id crescente para evitar deadlocks) as propinas e os créditos envolvidos. No fim chama `RecalcularPropina` para cada propina afectada.

**Entrada:** matrícula, método, valor, data, referência, observação, distribuição explícita opcional `[(propina_id, valor)]` e `usar_credito` opcional.

**Validações de cada distribuição:**
- a propina é da mesma matrícula e tenant, e não está Cancelada nem Anulada;
- `valor ≤ saldo` da propina;
- sem `permite_pagamento_parcial`, `valor` tem de igualar o saldo (pagar o saldo remanescente é sempre permitido);
- propina futura (resolvida como Pendente) exige `permite_pagamento_antecipado`.

**Distribuição automática** (quando nenhuma é indicada): propinas da matrícula com saldo > 0, da mais antiga para a mais recente (`periodo_inicio`, `ordem`). Só inclui as já geradas, e as futuras só com pagamento antecipado. Sem pagamento parcial, só liquida propinas por inteiro.

**Excedente:** o que sobra do valor (distribuição explícita menor que o pagamento, ou automática esgotada) gera um `credito` do aluno. A UI mostra a divisão antes de confirmar.

**Combinar crédito:** `usar_credito` consome créditos DISPONIVEL do aluno (mais antigos primeiro) como `credito_utilizacoes` sobre as propinas escolhidas, **antes** do dinheiro novo. A utilização não faz parte do `valor` do pagamento e fica registada de forma independente.

**Dívidas de anos anteriores:** pagar a matrícula de um ano encerrado, ou em qualquer estado, é sempre permitido.

## 5. Anular pagamento (`AnularPagamentoAction`)
Permissão `anular`, motivo obrigatório.
- **Bloqueada** se o crédito gerado pelo pagamento tem utilizações Activas. É preciso anular primeiro essas utilizações.
- Efeito: pagamento → Anulado; crédito gerado → Anulado; `RecalcularPropina` para cada propina distribuída. As propinas regressam a Em Aberto ou Parcialmente Paga automaticamente.
- Nada é apagado.

## 6. Anular utilização de crédito
Permissão `anular`, motivo obrigatório. A utilização passa a Anulada, o crédito recupera saldo (e volta a DISPONIVEL se estava ESGOTADO), e a propina é recalculada.

## 7. Interface
Lista de pagamentos (filtros: matrícula/aluno, método, estado, intervalo de datas, referência) e detalhe com a distribuição e o crédito gerado. Formulário de registo em passos: matrícula, valor e método, distribuição (automática com edição manual), resumo com o excedente e o uso de crédito. Página do aluno com os créditos e as utilizações.

## 8. Permissões
Novo caso `Modulo::PAGAMENTO` (slug `pagamento`) com ver, listar, criar, anular e exportar. As acções de crédito reutilizam `criar` e `anular`.

## 9. Testes
- Pagamento de uma e de várias propinas, parcial permitido e proibido, antecipado permitido e proibido.
- Distribuição automática mais antiga primeiro, só propinas geradas, excedente a crédito.
- Invariantes: soma das distribuições + crédito = valor, distribuição ≤ saldo, mesma matrícula, mesmo tenant.
- Crédito: de outro aluno ou tenant rejeitado, uso parcial e total, combinação com pagamento novo.
- Anulação: reversão completa, bloqueio com crédito utilizado, anular utilização, nenhuma eliminação física.
- **Concorrência:** dois pagamentos simultâneos na mesma propina não excedem o saldo.
- Duplicados: mesma referência no mesmo método é rejeitada. Método inactivo ou de outro tenant é rejeitado. Data futura é rejeitada.
- Numeração sequencial por tenant sem lacunas em operações bem-sucedidas.
- Dívida de ano anterior e de matrícula Concluída ou Cancelada é pagável.
- Isolamento de tenant e permissões por acção.

## 10. Extensibilidade (decisão registada, não implementar)
`pagamento_propinas` é específica de propinas por desenho. As cobranças de Produtos e Serviços (por exemplo, emissão de certificado) não podem ser liquidadas por este modelo. O spec dessas cobranças decide entre uma tabela de distribuição própria e uma generalização (`pagamento_alocacoes` polimórfica, ou um supertipo `cobrancas`). Para não bloquear essa decisão, mantém-se apenas:
- `pagamentos.matricula_id` obrigatório. Uma cobrança de serviço a um antigo aluno usa a sua matrícula Concluída. Vendas a quem nunca foi aluno ficam fora de âmbito até haver spec;
- `EstadoCobranca` já é genérico (nome e valores), pensado para ser reutilizado.

## 11. Fora de âmbito
Estado Pendente e conciliação, recibos, devolução ou reembolso de crédito, pagamento multi-matrícula numa só operação, dívidas e acordos de negociação, descontos e bolsas, relatórios, fiscalidade.
