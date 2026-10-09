# Módulo Financeiro — Configuração (Propinas, Cobrança, Pagamentos, Catálogo) — Design

**Data:** 2026-10-08
**Módulo novo:** `Modules/Financeiro`
**Âmbito:** só configuração. Complementa `2026-10-08-configuracao-produtos-servicos.md`, que continua a ser a fonte do domínio Produto/Serviço.

## 1. Objectivo

Permitir que cada Tenant configure **como a escola cobra**: planos de propina, regras de cobrança, métodos de pagamento e catálogo de produtos/serviços. A operação (propinas, pagamentos, dívidas, recibos) é feita noutros issues, mas este modelo tem de a suportar sem retrabalho.

## 2. Decisões fechadas

1. Um único módulo `Financeiro` (registar em `modules_statuses.json`). `Pages` e `Components` em subpastas por entidade; `Models` e `Services` planos, como nos restantes módulos.
2. Menu: novo grupo **Financeiro** em `useConfiguracoesMenu.js` com 4 links: Planos de Propina, Regras de Cobrança, Métodos de Pagamento, Produtos / Serviços. Cada link tem a sua permissão.
3. Estado: `estado` + `estado_descricao` (`SincronizaEstadoDescricao`) e `RegistaAutoria`. Não se usa `activo`.
4. Tenancy: só `tenant_id`, via `PertenceAoTenant`. Não há `estabelecimento_id`.
5. Enums inteiros têm sempre a coluna irmã `*_descricao`.
6. Dinheiro e moeda: o sistema **não assume uma moeda nem um número de casas decimais**. Os valores guardam-se como `bigInteger` em **unidades menores** da moeda (para o AOA, cêntimos; para o XOF, que não tem casas, a própria unidade). Razões: descontos percentuais e divisão em parcelas geram fracções, e a fiscalidade futura trabalha em unidades menores. Value object `Modules\Financeiro\Support\Dinheiro` (sem moeda embutida) + cast `Modules\Financeiro\Casts\DinheiroCast` (int ≥ 0, nunca float). A **moeda é da escola** (código ISO 4217, ver "Moeda e Câmbio"); o número de casas, o símbolo e o nome vêm de um registo de moedas (`Support\Moeda`), nunca de constantes espalhadas. Parsing e formatação recebem a moeda: `Dinheiro::deDecimal(valor, Moeda)` e `formatar(Moeda)`.
   - **Arredondamento (regra única do sistema):** percentagens arredondam para baixo à unidade menor (`intdiv`). Em divisões por N parcelas, as primeiras N-1 usam `intdiv` e a última absorve o resto, de modo que a soma é sempre exacta. Implementado em `Dinheiro::percentagem()` e `Dinheiro::dividir()`, com testes.
7. `PlanoPropina` pertence a um `AnoLectivo` (`ano_lectivo_id` obrigatório).
8. Sem soft delete. Eliminar é permitido enquanto não houver referências. O módulo operacional bloqueia a eliminação quando houver (padrão Curso/Nível/Turma).

## 3. Modelo de dados

Todas as tabelas têm `tenant_id` (FK + índice), `estado`, `estado_descricao`, `criado_por`, `editado_por` e timestamps, excepto `regras_cobranca` (sem `estado`).

### `planos_propina`
| Coluna | Regra |
|---|---|
| ano_lectivo_id | FK obrigatória |
| nome, descricao | nome obrigatório |
| periodicidade (+descricao) | enum `Periodicidade`: Mensal(1), Bimestral(2), Trimestral(3), Semestral(6), Anual(12), Outra |
| intervalo_meses | tinyint 1–12. Derivado da periodicidade, excepto em `Outra` (explícito) |
| valor | `Dinheiro`, valor **por período de cobrança**, obrigatório, > 0 |
| mes_inicio, mes_fim | tinyint 1–12 |

- `unique(tenant_id, ano_lectivo_id, nome)`. O `ano_lectivo_id` tem de pertencer ao tenant (validado por regra `exists` com scope de tenant).
- **Período:** `meses = ((mes_fim - mes_inicio + 12) % 12) + 1`. `mes_inicio > mes_fim` é válido (Set→Jun atravessa o ano civil). A validação aceita apenas a faixa 1–12, e o teste documenta o caso Set→Jun.
- **Periodicidade e período:** o plano gera `ceil(meses / intervalo_meses)` períodos de cobrança. **O último período pode ser mais curto** (Set→Jun trimestral dá 3+3+3+1). Só se rejeita `intervalo_meses > meses`. Se o último período curto cobra o valor inteiro ou proporcional é decisão do spec de Propinas. Este spec só garante que o modelo suporta ambas.
- **Competências:** `PlanoPropina::competencias()` é uma função pura que devolve a lista `(ano, mês)` percorrendo os meses em sequência. A primeira competência é a **primeira ocorrência de `mes_inicio` igual ou posterior ao mês de `ano_lectivo.data_inicio`**, e as seguintes avançam mês a mês. A âncora é a data real do ano lectivo (o modelo não tem `ano_inicio`), o que resolve de forma única os planos que atravessam o ano civil e os que começam depois do arranque do ano. Exemplos com ano lectivo a começar em 2026-09-01: Set→Jun dá Set/2026 … Jun/2027. Jan→Dez dá Jan/2027 … Dez/2027. Não se exige que as competências caibam em `data_fim`, porque o período é uma escolha da escola.
- Alterar o valor ou o período do plano afecta apenas gerações futuras.
- Valor 0 não é permitido. Isenções e bolsas totais pertencem a Descontos/Bolsas (fora de âmbito).

### `plano_propina_alvos` (aplicabilidade do plano)
Define a que turmas o plano se aplica. Cada linha é um alvo com campos opcionais:

| Coluna | Regra |
|---|---|
| plano_propina_id | FK obrigatória (`cascadeOnDelete`) |
| nivel_academico_id | FK nula |
| curso_id | FK nula |
| turno_id | FK nula |
| turma_id | FK nula |

- Um plano **sem alvos** é o plano **geral**: aplica-se a todas as turmas do seu ano lectivo.
- Todos os FKs pertencem ao tenant (e não estão apagados). Uma turma sem turno nunca casa um alvo de turno.
- **`turma_id` é exclusivo**: se definido, nível, curso e turno têm de ser nulos, e a turma tem de pertencer ao **ano lectivo do plano**.
- Linhas de alvo totalmente vazias são ignoradas. Um plano pode ter vários alvos (cada um com o mesmo preço).

**Precedência (ordem total).** Entre os planos que correspondem à turma, ganha o de maior especificidade: **(a)** turma específica; **(b)** maior número de dimensões casadas (curso, nível, turno); **(c)** desempate fixo entre dimensões: **curso > nível > turno**.

| # | Alvo | Exemplo |
|---|---|---|
| 1 | Turma específica | 11.ª-A Informática |
| 2 | Curso + Nível + Turno | Informática, 11.ª, Noite |
| 3 | Curso + Nível | Informática, 11.ª |
| 4 | Curso + Turno | Informática, Noite |
| 5 | Nível + Turno | 11.ª, Noite |
| 6 | Só curso | Informática |
| 7 | Só nível | 11.ª |
| 8 | Só turno | Noite |
| 9 | Geral | sem alvos |

- Num plano com vários alvos conta o alvo que casa com maior precedência.
- **A regra "curso vence nível" é uma decisão de negócio** (o curso define a família de preço e o nível refina), documentada e testada. Está visível na interface (coluna "Precedência").
- **Conflito:** só quando dois planos distintos têm exactamente a mesma precedência para a turma. A validação ao gravar impede-o; a resolução mantém a deteção como rede de segurança (por exemplo, ao reactivar um plano). Nesse caso devolve *conflito*, sem escolher vencedor.
- **Colisão ao gravar** = mesmos alvos **e** competências sobrepostas no mesmo ano lectivo. Alvos iguais com períodos disjuntos coexistem (fases de preço: Set–Dez a um valor, Jan–Jun a outro). Planos inactivos contam.
- **Resolução por competência:** `ResolvePlanoAplicavel::paraTurma(Turma, ?competência)`. Com competência (`['ano' => 2027, 'mes' => 2]`) só entram planos cujo período a contém; sem ela o tempo é ignorado (serve a validação e a interface; com fases de preço o resultado é conflito).
- Cada alvo tem uma descrição de precedência ("Curso + Nível", "Geral"…), usada na interface.

#### Copiar planos de outro ano
- Acção `POST /financeiro/configuracao/planos-propina/copiar` (permissão `plano-propina.criar`) com `ano_origem_id` e `ano_destino_id`: ambos do tenant, não apagados e diferentes. Corre numa única transacção.
- Copia, dos planos **activos** da origem (por ordem de id): nome, descrição, periodicidade, intervalo, valor, período e alvos de nível, curso e turno. O novo plano fica no ano de destino e **inactivo**, para a escola o rever e activar.
- Um plano é **ignorado** (sem falhar a operação, com motivo) se: está inactivo na origem; o nome já existe no destino; colide com um plano do destino ou com outro copiado antes (mesma lógica de `colisaoDeAlvos`, com as competências do calendário do **destino**); tem alvo de turma (as turmas pertencem ao ano de origem); ou um alvo aponta para nível, curso ou turno eliminado.
- O resumo (`copiados` e `ignorados`) segue como flash `copia_planos` e é mostrado na interface. Repetir a operação não copia nada e reporta os conflitos de nome.

#### Edição do valor de um plano (desenho)
- **Hoje (sem propinas):** editar o `valor` apenas actualiza o plano. O ano lectivo continua imutável.
- **Quando o plano já tem propinas geradas** (módulo de Propinas, ainda não implementado): alterar o `valor` abre um passo de decisão com **actualizar o preço e reajustar as dívidas** ou **manter o preço antigo para as dívidas existentes** (cria automaticamente um plano novo com os mesmos alvos a partir de `mes_efeito`), pré-visualização dos efeitos e confirmação com motivo. Pagas, canceladas e anuladas nunca mudam; pagamentos parciais preservam o valor recebido. Regra completa, tabelas (`alteracoes_preco_plano`, `propina_ajustes`) e invariantes: spec de Propinas, secção 14.
- O módulo de Planos de Propina fornece apenas o gancho: a Action de edição do plano delega no módulo de Propinas (através de um contrato a definir) quando existirem propinas do plano; sem módulo de Propinas, o comportamento é o simples.

### `regras_cobranca` (1:1 com o tenant)
| Coluna | Regra |
|---|---|
| dia_vencimento | tinyint 1–28 (evita fim de mês) |
| dias_tolerancia | tinyint 0–90 |
| permite_pagamento_parcial | bool |
| permite_pagamento_antecipado | bool |
| gerar_automaticamente | bool |
| permite_negociacao | bool, default false |
| desconto_maximo_negociacao | tinyint 0–100 (%), default 0 |

- **Semântica:** `dias_tolerancia` são dias corridos. Uma cobrança está em atraso quando `hoje > data_vencimento + dias_tolerancia` e ainda tem saldo.
- `unique(tenant_id)`. O registo é criado com defaults no provisioning do tenant (provisionador `ProvisionarRegrasCobranca`, ordem 50), de forma **idempotente** (`firstOrCreate`). Os tenants existentes são postos em dia pelo comando `financeiro:sincronizar --todos` (convenção `ParaTodosOsTenants` do projecto, no lugar de uma migração). Como rede de segurança, o `show` usa `firstOrCreate`, para a tela nunca rebentar. A tela é só `show`/`update`, sem CRUD.
- Se `permite_negociacao` for false, `desconto_maximo_negociacao` é forçado a 0 no servidor.

### Moeda e Câmbio (configuração monetária da escola)

**`configuracoes_monetarias`** (1:1 com o tenant, `unique(tenant_id)`):

| Coluna | Regra |
|---|---|
| moeda | ISO 4217 (3 letras), tem de existir no registo `Moeda`; default `AOA` |
| cambio_manual | bool, default false: usa o câmbio da própria escola em vez do da plataforma |

- Criada com os defaults no provisioning e, para tenants existentes, por `financeiro:sincronizar`.
- **A moeda só pode mudar enquanto não existirem preços configurados nem registos financeiros** (produtos, serviços, planos, e tudo o que os módulos operacionais declararem). Trocar a moeda reinterpretaria os valores sem os converter. O bloqueio usa os contratos `FonteDePrecos` (preços de configuração) e `ReferenciaFinanceira` (registos operacionais).

**Câmbio de referência.** Cotação entre a moeda **base** (USD) e a moeda **cotada** (a da escola), no sentido **1 USD = X unidades da moeda cotada** (ex.: 1 USD = 910,00 AOA), guardada como inteiro escalado (6 casas, `taxa × 1.000.000`), nunca float. As colunas chamam-se `moeda_base` (USD na interface; genérica para o futuro) e `moeda_cotada`.

- **`cambios_plataforma`** (global, sem tenant): `moeda_cotada`, `moeda_base`, `data`, `taxa`, `fonte` (`padrao` | `manual` | `api`). `unique(moeda_cotada, moeda_base, data)`: no máximo uma linha por dia por par. É o câmbio **por defeito**, alimentado hoje por comando (`financeiro:cambio-plataforma`) e, no futuro, por uma API agendada. Vem com um valor padrão (AOA: 1 USD = 910,00, `fonte = padrao`, com data antiga para valer em qualquer data): é configurável e **não é um câmbio de mercado**.
- **`cambios`** (por escola): `tenant_id`, `moeda_cotada`, `moeda_base`, `data`, `taxa`, autoria. `unique(tenant_id, moeda_cotada, moeda_base, data)`. Só conta se `cambio_manual = true`.
- **Pesquisa `CambioDoDia::para(data)`:** moeda da escola = USD → taxa 1; modo manual → o último câmbio da escola até à data; senão → o último da plataforma até à data; nada encontrado → **nulo**. Nunca bloqueia.
- **Conversão (definição; implementada no plano operacional, que declara `ext-bcmath`):** `valor_usd = valor_cotada ÷ taxa` e `valor_cotada = valor_usd × taxa`, em aritmética de precisão arbitrária sobre unidades menores, escalando pelas casas de cada moeda e pelo `ESCALA` da taxa, com arredondamento **para o mais próximo, metade para cima**, uma única função no sistema. Nunca float. O resultado nunca substitui o valor original.
- **Snapshot (contrato para os módulos operacionais):** cada registo operacional (propina, pagamento, recibo…) guarda o **valor original em unidades menores da moeda da escola**, a `moeda` e o `cambio_usd` (nulo se não houver câmbio) no momento da criação. O valor original **nunca é substituído** por um valor convertido: o equivalente em USD calcula-se a partir de `valor` e `cambio_usd` do próprio registo. Mudanças posteriores de moeda ou de câmbio nunca alteram registos existentes.
- Não há conversão nem facturação em várias moedas neste spec: só moeda por escola + câmbio guardado.

### `metodos_pagamento`
- `nome` (obrigatório), `tipo` (+descricao) com o enum `TipoMetodoPagamento`: Numerário, Transferência Bancária, TPA, Multicaixa, Outro.
- `unique(tenant_id, nome)`. Nada é pré-criado: cada escola define os seus.

### `produtos` e `servicos`
Conforme o spec de Produtos/Serviços, adaptado a `estado` e `Dinheiro`. Colunas: `nome` (obrigatório), `descricao`, `codigo`, `preco`.
- `unique(tenant_id, codigo)` por tabela, com `codigo` opcional. Os índices são `(tenant_id, estado)` e `(tenant_id, codigo)`.
- `preco` ≥ 0 (`Dinheiro`). O zero é permitido: a regra de negócio não o exclui, e o spec só exige preço obrigatório.
- Uma entrada de menu "Produtos / Serviços", uma página com tabs Todos/Produtos/Serviços, e dois Services com rotas de escrita separadas (`/produtos`, `/servicos`).
- Cada model expõe o scope `activos()`. Os módulos futuros só o usam para listar itens seleccionáveis.
- A eliminação usa o mesmo contrato `ReferenciaFinanceira` dos restantes recursos. Desactivar é o caminho preferido.

## 4. `EstadoCobranca` (enum do sistema)
Sem tabela e sem UI. Estados: Pendente, Em Aberto, Parcialmente Paga, Paga, Em Atraso, Cancelada, Anulada.

**Pendente e Em Atraso são derivados, nunca persistidos.** Pendente é um período ainda não iniciado sem pagamentos. Em Atraso verifica a regra da secção 3. Os estados persistidos são cinco: Em Aberto, Parcialmente Paga, Paga, Cancelada e Anulada. Assim não é preciso job de transição nem há estados desactualizados. A assinatura e a precedência de `EstadoCobranca::resolver()` são definidas no spec de Propinas.

O enum expõe `transicoesPermitidas()` (apenas estados persistidos), com testes unitários:
- Em Aberto ⇄ Parcialmente Paga ⇄ Paga: transições **automáticas**, resultado do recálculo do valor pago (inclui o regresso quando um pagamento é anulado). Nunca manuais.
- Em Aberto → Cancelada: manual, só sem pagamentos confirmados no histórico.
- Em Aberto → Anulada: manual, só com `valor_pago = 0` e histórico de pagamentos (todos já anulados).
- Cancelada e Anulada são terminais.

## 5. Contratos para o módulo operacional (não implementar aqui)

1. **Snapshot.** Ao criar uma cobrança, o módulo operacional copia vencimento, tolerância, valor e `ano_lectivo_id`. Mudanças posteriores de plano, regra ou preço nunca alteram cobranças existentes. A cobrança copia também a **moeda** e o **câmbio** (`cambio_usd`) vigentes, ver "Moeda e Câmbio".
2. **Dívidas de anos anteriores.** Ficam sempre pagáveis. Planos de anos lectivos encerrados continuam legíveis. `estado = inactivo` bloqueia apenas novas gerações. Métodos inactivos continuam referenciáveis por recibos antigos.
3. **Negociação.** Só é possível se `permite_negociacao` for true. O desconto não pode exceder `desconto_maximo_negociacao`. Negociar **nunca edita** a cobrança original: cria um *acordo* ligado a ela, com parcelas e desconto próprios, e a original fica intacta para auditoria. A acção de permissão `negociar` será acrescentada no spec de Dívidas.
4. **Estado da matrícula.** A geração considera apenas matrículas **Activa**. O estado "Trancada" não existe hoje em `EstadoMatriculaEnum` e fica fora deste trabalho. Cobranças já geradas permanecem como dívida. As regras de Cancelada e Transferida estão no spec de Propinas.
5. **Catálogo.** Uma operação futura que use um Produto ou Serviço copia `nome`, `codigo` e `preco` no momento da operação (snapshot). Alterar ou desactivar o item nunca afecta operações existentes.
6. **Aplicação do plano** é resolvida por `plano_propina_alvos` (secção 3). O plano não tem FK para matrícula.

7. **Eliminação.** O módulo define o contrato `Modules\Financeiro\Contracts\ReferenciaFinanceira` com `existeReferenciaA(Model $configuracao): bool`. Os módulos operacionais registam as suas implementações com a tag `financeiro.referencias` no container. As Actions de eliminação consultam todas e bloqueiam se alguma devolver true. Hoje não há implementações, por isso nada bloqueia. O teste usa uma implementação falsa para provar o mecanismo.

## 6. Permissões
Granularidade por recurso, como já existe para `documento-pessoa` e `senha-utilizador`. Novos casos no enum `Modulo`, cada um com slug real, as acções existentes (ver, criar, editar, eliminar) e seed em `RolePermissaoSeeder`:

| Caso | Slug | Cobre |
|---|---|---|
| PLANO_PROPINA | `plano-propina` | Planos de Propina |
| MOEDA_CAMBIO | `moeda-cambio` | Moeda da escola e câmbio |
| REGRA_COBRANCA | `regra-cobranca` | Regras de Cobrança (só ver/editar) |
| METODO_PAGAMENTO | `metodo-pagamento` | Métodos de Pagamento |
| CATALOGO_FINANCEIRO | `catalogo-financeiro` | Produtos e Serviços |

Nenhum mecanismo paralelo de autorização.

**Catálogo `Acao`:** hoje só tem ver, criar, editar, eliminar, listar e exportar. `confirmar`, `anular` e `cancelar` são acrescentadas no plano de **Propinas**, onde são usadas pela primeira vez. As grelhas de permissões listam todas as acções do catálogo para todos os módulos (`Acao::orderBy('numero')`), por isso esse plano tem de decidir como as mostrar só nos módulos onde fazem sentido.

## 7. Arquitectura
Seguir a camada fina: Controller → Service (leitura) / Action (escrita), com DTO e FormRequest. O `tenant_id` nunca vem do cliente. Os IDs de outro tenant dão 404 via `TenantScope`. Páginas Inertia/Vue em `Pages/PlanosPropina`, `Pages/RegrasCobranca`, `Pages/MetodosPagamento`, `Pages/ProdutosServicos`, com cartões `bg-body-secondary` e padrões de lista/modal existentes.

## 8. Testes (obrigatórios em cada fase)
- CRUD e validação de cada entidade.
- Isolamento de tenant (A não vê B, por ID e por URL) e permissões.
- `competencias()` (Set→Jun, Jan→Dez, mês único) e coerência período/periodicidade.
- `transicoesPermitidas()`.
- `Dinheiro` (rejeita negativos, sem float, `percentagem()` e `dividir()` com soma exacta, ex.: 10% de 25.333,33 Kz e 25.000,00 ÷ 3).
- `competencias()` ancorada em `data_inicio`: Set→Jun (atravessa), Jan→Dez (começa em Jan/2027 num ano lectivo que arranca em Set/2026), mês único e trimestral em 10 meses (3+3+3+1).
- `EstadoCobranca::resolver()` (Em Atraso derivado, tolerância em dias corridos).
- `permite_negociacao=false` força desconto 0.
- Regras criadas no provisioning do tenant, idempotência (correr duas vezes não duplica), backfill de tenants existentes e `show` com `firstOrCreate`.
- Unicidade por tenant: o mesmo nome/código em dois tenants é permitido, no mesmo tenant não.
- `tenant_id` forjado no payload é ignorado.
- `ano_lectivo_id` de outro tenant é rejeitado na criação e edição de plano.
- Permissões por recurso: quem só tem `metodo-pagamento` não acede a planos nem regras.
- Contrato `ReferenciaFinanceira`: uma implementação falsa bloqueia a eliminação.
- A suite existente continua verde e o build frontend funciona.

## 9. Fora de âmbito
Propinas operacionais, pagamentos, recibos, dívidas e acordos de negociação, descontos/bolsas/isenções (incluindo plano com valor 0, que não é um bug mas um caso de Descontos/Bolsas), multas e juros de mora, vendas, stock, fiscalidade, e qualquer FK para `matriculas`.

## 10. Entrega em 3 planos
1. **Fundação:** módulo, permissões, menu, `Dinheiro` (value object `Support\Dinheiro` + cast `Casts\DinheiroCast`), `EstadoCobranca` e Regras de Cobrança. O plano detalhado está em `docs/superpowers/plans/2026-10-08-financeiro-fundacao-regras-cobranca.md`.
2. **Métodos de Pagamento** e **Produtos / Serviços**.
3. **Planos de Propina** (competências, validações, ligação ao Ano Lectivo, `plano_propina_alvos` e `ResolvePlanoAplicavel`).

Depois: specs `2026-10-08-financeiro-propinas-design.md` e `2026-10-08-financeiro-pagamentos-design.md`.
