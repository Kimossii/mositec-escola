# Financeiro → Propinas operacionais — Plano por fases (PARA REVISÃO DO DONO)

> **Estado:** proposta. Nada implementado. Nenhuma fase arranca sem o dono responder à secção 3 ("Decisões antes de F1") e aprovar a ordem da secção 0.
> Cada fase, depois de aprovada, ganha o seu plano detalhado (tarefas com checkboxes, como `2026-10-10-financeiro-planos-propina.md`) para execução por `superpowers:subagent-driven-development` ou `superpowers:executing-plans`.

**Specs (fontes de verdade):**
- `docs/superpowers/specs/2026-10-08-financeiro-propinas-design.md` (todas as secções, em especial §3, §4, §6, §7, §8, §9, §14, §15).
- `docs/superpowers/specs/2026-10-08-financeiro-pagamentos-design.md` (consumidor de `RecalcularPropina`, `pagamento_multas`).
- `docs/superpowers/specs/2026-10-08-modulo-financeiro-configuracao-design.md` (planos, alvos, `EstadoCobranca`, contratos §5, "Moeda e Câmbio", escalões de multa).
- Planos anteriores relacionados: `2026-10-12-divida-catalogo-acoes-fk.md` (catálogo `Acao` com 12 acções e `Modulo::acoesAplicaveis()`), `2026-10-12-trancamento-matricula-plano.md` (consome a geração e o evento de matrícula), `2026-10-12-financeiro-escaloes-multa.md` (configuração das multas).

## Restrições globais (valem para todas as fases)

- Texto de UI, mensagens e comentários em PT-PT com acentos; identificadores, rotas e tabelas sem acento.
- Camada fina: Controller → Service (leitura) / Action (escrita), com DTO e FormRequest. `use` no topo, nunca FQN inline.
- Dinheiro: `bigInteger` em unidades menores da moeda da escola, `Dinheiro` + `DinheiroCast`, nunca float. Moeda vem de `MoedaDoTenant`.
- Tenancy: `PertenceAoTenant` em todos os models novos; `tenant_id` nunca vem do cliente; IDs de outro tenant dão 404 (`TenantScope`). Toda a tabela nova tem `tenant_id` NOT NULL com FK (`TenancyEsquemaTest`).
- **Proibido** em `Modules/*/app`: `insert`, `insertOrIgnore`, `upsert`, `DB::table`, `DB::raw`, `*Quietly`, `withoutEvents` (`TenancyArquitecturaTest::violacoesDeBypass`). SQL cru só em migrations.
- `estado` + `estado_descricao` em todos os enums inteiros; hooks `saving` **null-safe** (a matriz de isolamento cria models em cru).
- Dentro de `DB::transaction` não se apanham excepções para continuar (PostgreSQL aborta a transacção). Conversões de erro (`ViolacaoDeChave`) só **à volta** da transacção.
- Efeitos fora da BD (cache, notificações, despacho de jobs) só depois do commit (`DB::afterCommit`).
- Testes correm em SQLite; produção é PostgreSQL. Cada fase lista o que **não** fica provado em SQLite.
- Menus: acrescentar nos **dois** sítios quando houver equivalente (sidebar `SidebarMenuWrapper.vue` + composable do header).
- `migrate`, `db:seed --force` e `financeiro:sincronizar --todos` são corridos pelo dono.
- **Git: só `git add`. Nunca `git commit` sem pedido explícito do dono.**

---

## 0. Resumo e ordem de entrega proposta

| Fase | Objectivo numa linha | Depende de |
|---|---|---|
| **F1** | Modelo `propinas`, estado resolvido (PHP + SQL), `RecalcularPropina` como ponto único, referências financeiras, permissão `propina` | Configuração (feita) |
| **F2** | Geração (`GerarPropinasService`) manual com pré-visualização e automática por comando/job; lista de propinas e menu Financeiro (operação) | F1 |
| **F3** | Detalhe da propina, cancelar, evento `MatriculaEstadoAlterado` (§9) e contrato que impede mudar turma/ano de uma matrícula com propinas | F2 |
| **F4** | Alteração de preço de plano com propinas (§14): pré-visualização com impressão digital, divisão do plano, ajustes imutáveis | F2 (F3 recomendado) |
| *Pagamentos-A* | *(plano próprio)* pagamentos, distribuições, créditos; liga `RecalcularPropina`; activa `capital_liquidado_em` e **anular propina** | F1–F3 |
| **F5** | Multas §15: `propina_multas`, revisões, comando diário, isenção/redução | **Pagamentos-A** |
| *Pagamentos-B* | *(plano próprio)* `pagamento_multas`, imputação capital → multa, crédito sobre multa, `RecalcularMulta` ligado | F5 |

**Alterações à sugestão inicial (justificadas):**
1. **A lista de propinas passa de F3 para F2.** O botão "Gerar" e o resultado da geração vivem na lista (§10); sem lista, F2 não é verificável na app. F3 fica com o detalhe e as acções.
2. **"Anular propina" sai de F3 para Pagamentos-A.** §8 exige "valor_pago = 0 **com histórico** (todos os pagamentos anulados)": sem pagamentos esse estado é impossível, e uma acção que falha sempre é código morto e não testável com dados reais. F1/F3 deixam o ponto de extensão (contrato `HistoricoDePagamentosDaPropina`) e a acção nasce com Pagamentos.
3. **`propinas.valor_original` nasce em F1** (não em F4): evita uma migração `ALTER` com backfill em tabela já com dados, e a invariante de reconciliação (§3) pode ser testada desde o início.
4. **Pagamentos é partido em A e B à volta de F5.** `pagamento_multas` tem FK para `propina_multas` (F5), e as multas precisam das datas de pagamento (`saldo_em(d)`) de Pagamentos-A. Ver F5.
5. **Evento de matrícula em F3 (não em F2).** O evento existe para cancelar (§9), que precisa da lógica de cancelamento de F3. A geração ao activar uma matrícula é uma questão em aberto (Q7).

---

## 1. Arquitectura e fronteiras

### 1.1 Direcção das dependências

```
Matricula ──(evento MatriculaEstadoAlterado, contrato DependenciasDaMatricula)──▶  ninguém (não importa Financeiro)
Financeiro ──importa──▶ Matricula (Matricula, EstadoMatriculaEnum, evento, contrato)
Financeiro ──importa──▶ Turma, AnoLectivo, Aluno (só leitura)
AnoLectivo ──contrato DependenciasDoAnoLectivo──▶ implementado por Financeiro (já existe)
Pagamentos (mesmo módulo Financeiro) ──chama──▶ RecalcularPropina / RecalcularMulta
```

- **Matricula nunca importa `Modules\Financeiro\`.** Novo teste `tests/Feature/Arquitectura/FronteiraMatriculaFinanceiroTest.php`, cópia de `FronteiraAnoLectivoFinanceiroTest` (varre `Modules/Matricula/app` e `routes`).
- Matricula **emite** um evento (factos: "esta matrícula mudou de estado") e **consulta** um contrato próprio (`Modules\Matricula\Contracts\DependenciasDaMatricula`, etiqueta `matricula.dependencias`), mesmo padrão de `DependenciasDoAnoLectivo` / `DependenciasRegistadasDoAnoLectivo`. Financeiro escuta e implementa. Sem Financeiro activo, Matricula funciona igual.
- O payload do evento é de **primitivos** (`matriculaId`, `estadoAnterior ?int`, `estadoNovo int`, `dataEfeito string Y-m-d`, `utilizadorId ?int`), não models: serializável, sem acoplar a estado em memória.
- Propinas, Pagamentos e Multas ficam **no mesmo módulo `Financeiro`** (não há módulo novo; não mexer em `modules_statuses.json`). Convenção do spec de Configuração §2.1: `Models`, `Services`, `Actions` planos; `Pages/Propinas`, `Components/Propinas` por entidade. (O spec de Propinas diz "subpastas `Propina`": ver lacuna L13.)

### 1.2 Pontos únicos (não duplicar)

| Responsabilidade | Ponto único | Quem chama |
|---|---|---|
| Plano aplicável a turma+competência | `ResolvePlanoAplicavel::paraTurma` (existe) | `GerarPropinasService` |
| Calendário do plano | `CalendarioDePlano` / `PlanoPropina::periodos()` (existe) | geração, §14 |
| Escrita de `valor_pago`, `estado` (Em Aberto/Parcial/Paga), `capital_liquidado_em` | `RecalcularPropina` (F1) | Pagamentos, §14, F3 |
| Ordem de bloqueio de propinas | `BloqueioDePropinas::bloquear(ids)` (F1; `lockForUpdate` por id crescente) | Recalcular, Pagamentos, §14, multas |
| Estado mostrado | `EstadoCobranca::resolver()` + scope SQL equivalente (F1) | lista, detalhe, filtros, Pagamentos |
| Criação de propinas | `GerarPropinasService` (F2) | manual, comando, evento (Q7), trancamento |
| `valor` de propina já gerada | `AlterarPrecoPlanoAction` (F4) | — |

### 1.3 Contratos de extensão (todos com etiqueta no container, padrão `ReferenciasFinanceiras`)

| Contrato (novo) | Onde | Implementado por | Fase |
|---|---|---|---|
| `Contracts\ContribuiParaValorPago` — `contribuicoes(Propina): list<{valor:int, data:Y-m-d}>` | Financeiro | Pagamentos-A (distribuições Confirmadas; utilizações de crédito Activas) | F1 (sem implementações) |
| `Contracts\HistoricoDePagamentosDaPropina` — `temHistorico(Propina): bool`, `temConfirmados(Propina): bool` | Financeiro | Pagamentos-A | F1 (sem implementações) |
| `Contracts\CobrancaResolvivel` — dados mínimos para `EstadoCobranca::resolver` | Financeiro | `Propina` | F1 |
| `Modules\Matricula\Contracts\DependenciasDaMatricula` — `bloqueiaAlteracao(Matricula, int $turmaNovaId, string $dataMatriculaNova): ?string` | Matricula | Financeiro (`DependenciasDePropinas`) | F3 |
| `ReferenciaFinanceira` (existe) | Financeiro | `PropinasReferenciam` | F1 |

> Testes usam implementações falsas destes contratos para provar Recalcular, cancelar e §14 com pagamentos parciais **antes** de Pagamentos existir.

---

## 2. O que existe hoje (verificado no código)

| Peça | Estado | Nota para o plano |
|---|---|---|
| `Support\Dinheiro` | `deUnidadesMenores`, `somar`, `subtrair` (lança se negativo), `percentagem(int 0–100)`, `dividir` | **Não** tem percentagem em pontos-base (multas): ver L5 |
| `Enums\EstadoCobranca` | 7 casos (1–5 persistidos, 6 Pendente, 7 Em Atraso), `transicoesPermitidas()`, `persistido()` | **Não** tem `resolver()`: nasce em F1 |
| `Services\ResolvePlanoAplicavel::paraTurma(Turma, ?competencia)` | carrega **todos** os planos activos do ano a cada chamada | N+1 na geração em massa: ver risco R6 |
| `Support\CalendarioDePlano` | `competencias`, `periodos` (ordem, meses, inicio, fim), `sobrepoem`, `contem` | âncora "1.ª ocorrência de `mes_inicio` ≥ mês de `data_inicio`": ver L6 (bug na divisão do plano) |
| `ReferenciasFinanceiras` / `ReferenciaFinanceira` | sem implementações | F1 regista `PropinasReferenciam` |
| `FontesDePrecos` / `PrecosDosPlanos`, `PrecosDoCatalogo`, `PrecosDasMultas` | etiquetadas no provider | Propinas são **registos operacionais** → `ReferenciaFinanceira`, não `FonteDePrecos` |
| `DependenciasDePlanosPropina` (contrato do AnoLectivo) | bloqueia mudar mês/ano de `data_inicio` e eliminar ano com planos | Suficiente para propinas (não há propina sem plano); nada novo |
| `AtualizarPlanoPropinaAction` | actualiza tudo (incl. `valor`) sem olhar a propinas | F4 acrescenta o gancho "valor com propinas → recusado" |
| `EliminarPlanoPropinaAction` | consulta `ReferenciasFinanceiras` + `ViolacaoDeChave` | Fica protegida a partir de F1 |
| `RegraCobranca` | `dia_vencimento`, `dias_tolerancia`, `gerar_automaticamente`, `multa_activa`, `escaloes()` | `doTenant()` com `firstOrCreate` |
| Provisionadores / `financeiro:sincronizar` | `ParaTodosOsTenants`, chama `SincronizarPerfisDeSistemaAction` | Concede permissões novas e invalida `PermissaoCache` sozinho |
| `Modulo` enum | 0–21 (`MOEDA_CAMBIO = 21`); `acoesAplicaveis()` só `default => ACOES_BASE` | F1 acrescenta `PROPINA = 22` |
| `AcaoSeeder` | 12 acções: base + `confirmar`(6) `anular`(7) `cancelar`(8) `ajustar`(9) `negociar`(10) `isentar-multa`(11) | Nada a acrescentar ao catálogo |
| `ModuloSeeder` | lista explícita 0–21 | F1 acrescenta `22 => 'Propina'` |
| `SincronizaEstadoDescricao` (Core) | usa `Core\Enums\Estado` (Activo/Inactivo) | **Não serve** para `EstadoCobranca`: hook próprio |
| `RegistaAutoria` (Core) | `criado_por`/`editado_por` de `auth()->id()` | Em comandos fica nulo (aceitável) |
| `ParaTodosOsTenants`, `ComTenant`, `UnicoPorTenant` | existem | comando + job por tenant |
| `routes/console.php` | só `mosi:tenant:tokens:prune --todos` agendado | F2/F5 acrescentam agendamentos |
| **Matricula**: `EstadoMatriculaEnum` | PENDENTE(1) ACTIVA(2) CANCELADA(3) CONCLUIDA(4) TRANSFERIDA(5); Activa → Concluída/Cancelada/Transferida | — |
| `Matricula` model | `aluno_id`, `turma_id`, `ano_lectivo_id`, `numero_registo_matricula`, `data_matricula`, `data_fim`, SoftDeletes | O "número de matrícula" oficial é `alunos.numero_matricula`; a matrícula tem `numero_registo_matricula` |
| `MatriculaEstadoAlterado` | **não existe**; `EventServiceProvider` vazio (discovery activo) | F3 cria |
| `AlterarEstadoMatriculaAction` | **sem `DB::transaction`** (save + histórico separados); `data_fim` livre (pode ser retroactiva) | F3 envolve em transacção e despacha o evento |
| `CriarMatriculaAction` | pode criar **já Activa** (`dto->estado`) | Evento também na criação (`estadoAnterior = null`) |
| `AtualizarMatriculaAction` | permite mudar `turma_id`, `ano_lectivo_id` e `data_matricula` com a matrícula Activa | Quebra a invariante plano/ano: ver L10 e contrato F3 |
| `RenovarMatriculaAction` | Activa → **Concluída** antes de criar a nova | §9 não cobre Concluída: Q6 |
| Menus | Sidebar: grupo "Financeiro" em `configuracoesMenu` (linhas ~902–910) + **placeholder comentado** `const financeiro` (ícone `ki-dollar`, itens Propinas, Pagamentos, …) e `<SidebarAccordion :item="financeiro" heading="Financeiro" />` comentado. Header: `useConfiguracoesMenu.js` (grupo Financeiro de configuração); **não existe** `useFinanceiroMenu.js` nem dropdown Financeiro no `HeaderMenu.vue` | F2: Q13 |
| Endpoints JSON + axios | precedentes em `MatriculaController`, `AlunoController`, `MatriculaFormModal.vue` | pré-visualizações de F2/F4 seguem este padrão |
| Testes | `ComDadosAcademicosFinanceiro` (ano, nível, curso, turno, turma, plano), `ComUtilizadoresFinanceiro`, `FinanceiroTenancyTest` | F1 acrescenta helper de aluno + matrícula |
| CHECK constraints | **nenhum precedente** no projecto; Laravel 12.69 não tem `->check()` fluente; SQLite não aceita `ALTER TABLE … ADD CONSTRAINT` | CHECK só em pgsql (`DB::statement` condicionado ao driver) |
| Índice parcial com SQL cru | precedente: `domains_tenant_principal_unique` (`DB::statement` em migration) | Funciona em SQLite e pgsql |

---

## 3. Decisões a tomar ANTES de F1 (perguntas ao dono)

Cada uma tem uma recomendação; o dono confirma ou escolhe outra.

| # | Pergunta | Recomendação |
|---|---|---|
| **Q1** | O contrato de snapshot do spec de Configuração ("Moeda e Câmbio", §5.1) manda cada registo operacional guardar `moeda` e `cambio_usd`. A tabela `propinas` do spec de Propinas não os tem. Acrescentar? | **Sim**: `moeda char(3)` e `cambio_usd bigint null` (micros de `TaxaCambio`, de `CambioDoDia::para(hoje)` no momento da geração). Custo baixo agora, caro depois. |
| **Q2** | Acrescentar colunas desnormalizadas `ano_lectivo_id` (spec Configuração §5.1 manda copiar) e `data_limite` (= `data_vencimento + dias_tolerancia`)? | **Sim** às duas. `data_limite` torna "Em Atraso" uma comparação de datas simples e portável (SQLite e pgsql calculam datas de forma diferente e `DB::raw` é proibido); `ano_lectivo_id` serve o filtro principal da lista sem join. Ambas imutáveis (snapshot). |
| **Q3** | Último período mais curto (ex.: Set→Jun trimestral = 3+3+3+**1**): cobra o valor inteiro ou proporcional? Nenhum spec decide (o de Configuração delega no de Propinas, que é omisso). | **Valor inteiro** (o plano é "valor por período"). Se for proporcional, a regra de arredondamento tem de ser fixada (`Dinheiro::dividir`?) e testada. |
| **Q4** | Matrícula a meio de um período (ex.: entra a 15/Out num trimestre Set–Nov): paga o período inteiro? (§7.2 já implica que sim: `periodo_fim ≥ 1.º dia do mês da matrícula`.) | **Sim, inteiro**, sem pró-rata. Confirmar explicitamente. |
| **Q5** | Cancelamento automático por evento (§9): corre **dentro** da transacção da mudança de estado da matrícula (atómico: ou muda tudo ou nada) ou **depois do commit** com reconciliação no comando diário (como propõe o plano de trancamento D14)? | **Dentro da transacção** para o cancelamento (operação local, determinística, barata; falhar deve impedir o estado inconsistente). Reconciliação diária como rede de segurança em qualquer caso. A geração (Q7), se existir, **depois do commit**. |
| **Q6** | Matrícula **Concluída** (ex.: renovação antes do fim do plano, ou conclusão antecipada): as propinas futuras sem pagamentos ficam como dívida ou são canceladas como em §9? | **Cancelar como §9** quando `periodo_inicio > data_fim` (ninguém deve pagar meses depois de concluir). Se o dono preferir manter, documentar como decisão. |
| **Q7** | Ao passar a **Activa** (criação já Activa ou Pendente → Activa), com `gerar_automaticamente = true`, gerar logo as propinas (via evento, depois do commit) ou esperar pelo comando diário? | **Gerar logo** (após commit, falha não bloqueia a matrícula; o comando diário apanha o que falhou). Sem `gerar_automaticamente`, nada. |
| **Q8** | Mudar `turma_id`/`ano_lectivo_id`/`data_matricula` de uma matrícula que já tem propinas activas: bloquear? | **Bloquear** turma e ano (mensagem: "cancele primeiro as propinas" ou "use transferência"); `data_matricula` só se o **mês** não mudar. Via contrato `DependenciasDaMatricula` (F3). |
| **Q9** | Gerar períodos anteriores ao mês da matrícula (§7.2 "só manualmente, com permissão"): que permissão? | Opção `incluir_periodos_anteriores` na geração manual, exigindo **`propina.ajustar`** (acção já existe, excepcional por natureza). |
| **Q10** | Editar um plano que já tem propinas: além do `valor` (§14), bloquear `periodicidade`, `intervalo_meses`, `mes_inicio`, `mes_fim`? | **Bloquear** os campos de calendário (mudariam `ordem`/períodos de propinas futuras e criariam sobreposições). Alvos, nome e descrição continuam editáveis (afectam só gerações futuras). |
| **Q11** | Acções aplicáveis a `PROPINA`. O plano `divida-catalogo-acoes-fk` previa `ver, listar, criar, cancelar, anular, exportar, ajustar, negociar, isentar-multa`. | Declarar **sem `negociar`** (pertence ao spec de Dívidas, ainda inexistente; declarar já mostraria uma célula sem efeito). `listar` declarado mas as rotas usam `ver` (convenção do projecto). ADMIN_ESCOLA: `ver, listar, criar, cancelar, exportar` em F1; `ajustar` em F4; `anular` em Pagamentos-A; `isentar-multa` em F5. |
| **Q12** | §14 exige `propina.ajustar`. Exigir também `plano-propina.editar` (a operação altera e divide o plano)? | **Sim, as duas.** |
| **Q13** | Menu de operação: activar o placeholder `financeiro` da sidebar (só "Propinas" descomentado). No header não há dropdown Financeiro: criar `useFinanceiroMenu.js` + `menus/FinanceiroMenu.vue` + entrada no `HeaderMenu.vue` (padrão Académico)? | **Sim** (paridade header/sidebar, como Académico e Pedagogia). |
| **Q14** | Horizonte de geração: o spec gera **todas** as competências de uma vez (§7). Com §14 "Manter as já geradas", alunos já matriculados ficam ao preço antigo e novas matrículas pagam o novo **no mesmo mês e turma**. Manter o "tudo de uma vez"? | **Manter** (é o spec) + avisos fortes na UI (ver F4, mitigações M1–M4). Alternativa a ponderar: horizonte deslizante (só gerar até N períodos à frente), que muda a natureza de §7 e do trancamento: não recomendado para V1. |
| **Q15** | Confirmar a mudança de ordem da secção 0 (lista em F2, anular em Pagamentos-A, Pagamentos partido em A/B à volta de F5). | Aprovar. |

---

## 4. Lacunas e contradições encontradas nos specs (com proposta)

- **L1 — §14, `mes_efeito` = início do plano: três textos incompatíveis.** "Regras fechadas" diz que a operação é **recusada** e "a escola altera o valor do plano directamente"; a tabela diz que `plano_novo_id` é nulo "quando `mes_efeito` = início do plano e o valor é alterado in loco"; "Única porta de entrada" diz que a edição genérica **recusa** mudar o valor de um plano com propinas. *Proposta:* `AlterarPrecoPlanoAction` aceita `mes_efeito` = início do plano como **modo "in loco"** (sem divisão, `plano_novo_id = null`, escolha 1 reajusta no próprio plano, escolha 2 só muda o valor do plano). A edição genérica continua a recusar. Corrigir o texto de §14.
- **L2 — Snapshot incompleto.** `propinas` (§3) não tem `moeda`, `cambio_usd` nem `ano_lectivo_id`, exigidos pelo contrato de snapshot da Configuração. → Q1/Q2.
- **L3 — Último período curto** sem decisão (inteiro vs proporcional). → Q3.
- **L4 — `insertOrIgnore` (§15.5) e "inserção protegida pela unicidade" (§7.4) chocam com `TenancyArquitecturaTest`** (proíbe `insertOrIgnore`/`insert` fora de excepções declaradas). *Proposta:* sob bloqueio (`lockForUpdate` da matrícula na geração; da propina nas multas) verificar existência e criar com Eloquent (`create`, que passa por `PertenceAoTenant`); o índice único (parcial) fica como rede de segurança. Uma violação aborta a transacção **dessa matrícula/propina** e é contada como erro fora da transacção. Não alargar as excepções do teste de arquitectura.
- **L5 — `Dinheiro::percentagem()` só aceita percentagens inteiras (0–100)**, mas os escalões guardam **pontos-base** e §15.3 manda arredondar "como `Dinheiro::percentagem`". *Proposta (F5):* `Dinheiro::pontosBase(int $pb)` = `intdiv(u × pb, 10000)`, 0 ≤ pb ≤ 10000, com teste de overflow (máximo `999_999_999_999 × fator 100 × 10000` ≈ 1e18 < `PHP_INT_MAX` ≈ 9,22e18; margem pequena, testar no limite).
- **L6 — Bug de âncora na divisão do plano (§14).** O plano novo é ancorado de novo por `CalendarioDePlano::competencias` ("1.ª ocorrência de `mes_inicio` ≥ mês de `data_inicio`"). Exemplo: ano lectivo começa 2026-09-01, plano Out→Set (Out/2026 … Set/2027), `mes_efeito` = Set/2027 → plano novo Set→Set calcula **Set/2026**, não Set/2027. O plano novo não colide com o antigo (meses disjuntos), e a geração criaria uma propina em Set/2026 ao preço novo. O mesmo acontece com Jan→Dez num ano que começa em Set e `mes_efeito` ≥ Set/2027. *Proposta (F4):* a pré-visualização calcula as competências do plano novo e exige que sejam **exactamente** o sufixo das competências do plano antigo a partir de `mes_efeito`; se não forem, recusa com mensagem clara. (Correcção estrutural, fora deste plano: o plano ganhar âncora explícita de ano.) Afecta também a escolha 2 (sem propinas a reassociar, o §14 não apanharia o erro).
- **L7 — "Manter as já geradas" + geração completa (§7).** Já reconhecido no §14; ver mitigações M1–M4 em F4 e Q14.
- **L8 — Geração com fases de preço.** §7.1 diz que o resolvedor escolhe "o plano da matrícula pela turma"; sem competência, fases de preço (incluindo as criadas pela divisão de §14) dão **conflito**. *Proposta:* resolver **por competência** (ver algoritmo em F2).
- **L9 — Geração manual "com plano e âmbito" (§7.5) vs resolvedor.** Se o dono escolhe o plano P mas, para uma matrícula do âmbito, o resolvedor devolve outro plano (mais específico), o que acontece? *Proposta:* nunca gerar contra o resolvedor; essa matrícula aparece como "ignorada — aplica-se outro plano (nome)".
- **L10 — Matricula sem suporte ao §9 e com edições perigosas.** Sem evento; `AlterarEstadoMatriculaAction` sem transacção; criação directa em Activa não é uma "transição"; `AtualizarMatriculaAction` muda turma/ano/data com a matrícula Activa (quebra `plano.ano_lectivo_id == matricula.ano_lectivo_id` e deixa propinas de outra turma/plano). → F3 + Q8.
- **L11 — Concluída fora do §9.** → Q6.
- **L12 — Anular é impossível sem Pagamentos** (§8). → movido para Pagamentos-A.
- **L13 — Organização de pastas.** Propinas diz "subpastas `Propina`"; Configuração §2.1 diz `Models`/`Services` planos, `Pages`/`Components` por entidade, e o código segue a Configuração. *Proposta:* seguir a Configuração.
- **L14 — `SincronizaEstadoDescricao` não serve** (assume `Core\Enums\Estado`). Hook `saving` próprio null-safe com `EstadoCobranca::label()`.
- **L15 — Corrida geração × divisão de plano (§14).** A geração lê o plano antigo; se a divisão fizer commit entre a leitura e a escrita, nasce uma propina no plano antigo, ao preço antigo, num mês que já é do plano novo. *Proposta:* a geração bloqueia em modo partilhado (`sharedLock`) as linhas dos planos que vai usar e reconfirma, já com o bloqueio, que o período ainda pertence ao plano; a divisão bloqueia o plano com `lockForUpdate`. Não demonstrável em SQLite.
- **L16 — Impressão digital (§14) só sobre propinas é insuficiente.** Na escolha 2 o conjunto elegível é vazio; dois cliques ou duas pessoas dividiriam o plano duas vezes. *Proposta:* o hash inclui também `plano.id`, `valor`, `mes_inicio`, `mes_fim`, `updated_at` e os parâmetros (`modo`, `mes_efeito`, `valor_novo`).
- **L17 — Multas antes de Pagamentos multam toda a gente.** Sem pagamentos registados, `saldo_em(d) = valor` para todas as propinas: ligar `financeiro:aplicar-multas` antes de Pagamentos-A multaria todos os alunos com propinas vencidas. → F5 só depois de Pagamentos-A (e com `multa_activa` desligado por omissão, como já está).
- **L18 — "Plano tem propinas" não está definido** para efeitos de §14. *Proposta:* qualquer propina do plano, **em qualquer estado** (uma Paga basta: alterar o valor in loco tornaria o plano incoerente com o que foi cobrado).
- **L19 — Trancamento** (plano de 2026-10-12) espera elegibilidade por intervalos de actividade. *Proposta:* em F2, a elegibilidade fica numa classe própria (`ElegibilidadeDePeriodos`) para o trancamento a estender sem reescrever o gerador.
- **L20 — `CopiarPlanosPropinaAction`** copia também os planos resultantes de divisões (nomes gerados como "… (desde 01/2027)") para o ano seguinte. Cosmético; mencionar na UI de cópia ou ignorar.
- **L21 — Unicidade `(tenant_id, matricula_id, plano_propina_id, ordem)` total contradiz "regerar depois de cancelar"** (spec §3 vs F2/F3). *Resolvido em F1:* as duas unicidades de `propinas` são parciais (`WHERE estado IN (1, 2, 3)`); Cancelada e Anulada ficam fora de ambas. Spec §3 corrigido.

---

## 5. Fases

### F1 — Fundação do domínio Propina (sem UI)

**Objectivo.** Ter a tabela `propinas`, o model com invariantes, o estado resolvido em PHP e em SQL, `RecalcularPropina` como único escritor de `valor_pago`/`estado`, a protecção de planos e moeda por referências, e a permissão `propina`. Nenhuma propina é criada por utilizadores nesta fase (só testes).

**Entregáveis**
- Migration `Modules/Financeiro/database/migrations/2026_10_13_100000_create_propinas_table.php`.
- `Models/Propina.php`, `Contracts/{CobrancaResolvivel,ContribuiParaValorPago,HistoricoDePagamentosDaPropina}.php`.
- `EstadoCobranca::resolver(CobrancaResolvivel $c, CarbonInterface $hoje): self` + `Propina::scopeComEstadoResolvido(Builder, EstadoCobranca, CarbonInterface)`.
- `Services/RecalcularPropina.php`, `Support/BloqueioDePropinas.php`, `Support/ContribuicoesParaValorPago.php` (agrega as etiquetadas `financeiro.contribuicoes-valor-pago`), `Support/HistoricosDePagamentos.php` (etiqueta `financeiro.historicos-pagamento`).
- `Support/PropinasReferenciam.php` (`ReferenciaFinanceira`), etiquetada em `FinanceiroServiceProvider`.
- Permissões (ver abaixo). Helper de testes `tests/Concerns/ComMatriculasFinanceiro.php` (aluno + matrícula Activa numa turma; reaproveitar o que os testes de Matricula já fazem).

**Tabela `propinas`**

| Coluna | Tipo / regra |
|---|---|
| id | |
| tenant_id | FK `tenants` restrict |
| matricula_id | FK `matriculas` restrict |
| ano_lectivo_id | FK `ano_lectivos` restrict (Q2; invariante = matrícula = plano) |
| plano_propina_id | FK `planos_propina` restrict |
| ordem | unsignedSmallInteger ≥ 1 |
| periodo_inicio, periodo_fim | date (1.º dia do 1.º mês; último dia do último mês) |
| valor_original | unsignedBigInteger, imutável, = `valor` na criação (hook `creating`) |
| valor | unsignedBigInteger > 0 (corrente; só §14 o altera) |
| valor_pago | unsignedBigInteger default 0 (cache; só `RecalcularPropina`) |
| moeda, cambio_usd | char(3); bigint null (Q1) |
| data_vencimento | date (snapshot, §5) |
| dias_tolerancia | unsignedTinyInteger (snapshot) |
| data_limite | date = vencimento + tolerância (Q2; snapshot) |
| capital_liquidado_em | date null (só `RecalcularPropina`) |
| estado, estado_descricao | tinyint (1–5), string |
| origem, origem_descricao | tinyint: MANUAL(1), AUTOMATICA(2), EVENTO_MATRICULA(3) — *proposta, para auditoria; dispensável* |
| motivo_cancelamento, cancelado_por (FK users null), cancelado_em | |
| motivo_anulacao, anulado_por (FK users null), anulado_em | |
| criado_por, editado_por, timestamps | `RegistaAutoria` |

Índices:
- `unique(tenant_id, matricula_id, plano_propina_id, ordem)`.
- `index(tenant_id, matricula_id, periodo_inicio)`; `index(tenant_id, estado, data_vencimento)` (spec); `index(tenant_id, estado, data_limite)` (filtro Em Atraso); `index(tenant_id, plano_propina_id, estado)` (§14, referências); `index(tenant_id, ano_lectivo_id, estado)` (lista).
- **Índice único parcial (cobrança dupla, camada 1)**, SQL cru na migration:
  `CREATE UNIQUE INDEX propinas_periodo_activo_unico ON propinas (tenant_id, matricula_id, periodo_inicio) WHERE estado IN (1, 2, 3)`. Funciona em SQLite e pgsql; Cancelada(4)/Anulada(5) ficam de fora para permitir regerar.
- **CHECK (só pgsql**, `if (DB::getDriverName() === 'pgsql')`): `valor > 0`, `valor_original > 0`, `valor_pago BETWEEN 0 AND valor`, `periodo_fim >= periodo_inicio`, `ordem >= 1`, `estado BETWEEN 1 AND 5`, `data_limite >= data_vencimento`, `(estado = 4) = (cancelado_em IS NOT NULL)`, `(estado = 5) = (anulado_em IS NOT NULL)`.
- **Sobreposição (camada 2)** fica na aplicação (F2): `periodo_inicio <= :fim AND periodo_fim >= :inicio AND estado IN (1,2,3)` sob `lockForUpdate` da matrícula. *Opção a decidir mais tarde:* restrição `EXCLUDE USING gist (tenant_id WITH =, matricula_id WITH =, daterange(periodo_inicio, periodo_fim, '[]') WITH &&) WHERE (estado IN (1,2,3))`, só pgsql, exige a extensão `btree_gist` (depende do fornecedor de BD). Não recomendada em V1.

**Model `Propina`**
- `PertenceAoTenant`, `RegistaAutoria`; **não** `SincronizaEstadoDescricao` (L14): hook `saving` null-safe `estado_descricao = EstadoCobranca::tryFrom($this->estado)?->label()` (e `origem_descricao`).
- Casts: `valor`, `valor_original`, `valor_pago` → `DinheiroCast`; datas `date`; `estado` → `EstadoCobranca`.
- `$fillable` **sem** `valor_original`, `valor_pago`, `estado`, `capital_liquidado_em`. Hook `updating`: lança `LogicException` se `valor_original` estiver *dirty*. `deleting`: lança sempre (nenhuma propina é apagada, §8).
- Relações: `matricula`, `plano`, `anoLectivo`, `ajustes` (F4), `multa` (F5).
- `saldo(): Dinheiro` = `valor − valor_pago` (não é coluna).
- Implementa `CobrancaResolvivel`.

**Estado resolvido (§4)** — precedência: Cancelada/Anulada → Paga → Em Atraso (`saldo > 0` e `hoje > data_limite`) → Parcialmente Paga → Pendente (`periodo_inicio > hoje` e `valor_pago = 0`) → Em Aberto.
Tradução SQL (sem aritmética de datas, graças a `data_limite`):

| Resolvido | WHERE |
|---|---|
| Cancelada / Anulada / Paga | `estado = 4 / 5 / 3` |
| Em Atraso | `estado IN (1,2) AND data_limite < :hoje` |
| Parcialmente Paga | `estado = 2 AND data_limite >= :hoje` |
| Pendente | `estado = 1 AND periodo_inicio > :hoje` |
| Em Aberto | `estado = 1 AND periodo_inicio <= :hoje AND data_limite >= :hoje` |

(Em Atraso e Pendente são disjuntos porque `data_limite ≥ data_vencimento ≥ periodo_inicio`. A tradução depende de o estado persistido estar sempre coerente com `valor_pago`, que é exactamente o que `RecalcularPropina` garante.)

**`RecalcularPropina::executar(Propina)`**
- Exige transacção aberta (`DB::transactionLevel() > 0`, senão `LogicException`); relê a propina com `lockForUpdate`.
- `valor_pago` = Σ `ContribuicoesParaValorPago` (F1: nenhuma → 0).
- Novo estado persistido: Paga se `valor_pago = valor`; Parcial se `0 < valor_pago < valor`; Em Aberto se 0. Valida com `podeTransitarPara`. Nunca toca em Cancelada/Anulada (lança se houver contribuições numa delas: invariante de Pagamentos).
- `capital_liquidado_em` = data da contribuição que levou o saldo a 0 (ordenadas por data, depois id); nulo se `saldo > 0`.
- Falha (lança) se `valor_pago > valor`. Gancho para `RecalcularMulta` (F5): ponto vazio documentado.
- `BloqueioDePropinas::bloquear(array $ids)`: `Propina::whereKey($ids)->orderBy('id')->lockForUpdate()->get()`. Usado por todos.

**Referências financeiras (`PropinasReferenciam`)**
- `PlanoPropina` → existe propina com `plano_propina_id` (impede eliminar o plano).
- `ConfiguracaoMonetaria` → existe **qualquer** propina (impede mudar a moeda; redundante com `PrecosDosPlanos` hoje, mas é o contrato correcto para registos operacionais e protege se um dia um plano puder desaparecer).
- Matrícula, Turma, AnoLectivo: não passam por `ReferenciasFinanceiras` (não são configuração financeira). Matrícula: FK restrict + só Pendentes se eliminam (e Pendentes nunca têm propinas). AnoLectivo: já protegido pelos planos.

**Permissões**
- `Modulo::PROPINA = 22`, `slug()` `propina`, `label()` "Propinas"; `acoesAplicaveis()`: `PROPINA => ['ver','listar','criar','cancelar','anular','exportar','ajustar','isentar-multa']` (Q11; sem `editar`/`eliminar`, listados explicitamente).
- `ModuloSeeder`: `['nome' => 22, 'descricao' => 'Propina']`.
- `SincronizarPerfisDeSistemaAction::PERMISSOES_POR_PERFIL[ADMIN_ESCOLA]`: `Modulo::PROPINA->value => ['ver','listar','criar','cancelar','exportar']` (F4 acrescenta `ajustar`; Pagamentos-A `anular`; F5 `isentar-multa`). `PermissaoCache::invalidarTudo()` já é chamado quando há concessões novas.
- Dono corre: `migrate`, `db:seed --force` (ModuloSeeder/AcaoSeeder), `financeiro:sincronizar --todos`.

**Testes**
- Unit: `EstadoCobrancaResolverTest` — cada ramo; fronteira `hoje = data_limite` (não atrasa) e `+1 dia` (atrasa); Pendente vs Em Aberto no dia `periodo_inicio`; Em Atraso com pagamento parcial; Paga futura.
- Feature `PropinaModelTest`: `valor_original` imutável (update lança), `delete` lança, `valor_original = valor` na criação, `estado_descricao` sincronizado, unicidade `(matricula, plano, ordem)`, **índice parcial**: duas activas no mesmo `periodo_inicio` falham; após cancelar a primeira, a segunda entra.
- Feature `EstadoResolvidoConsultaTest`: matriz de propinas fixas × `hoje` — o scope SQL devolve exactamente o mesmo conjunto que `resolver()` em PHP para os 7 estados.
- Feature `RecalcularPropinaTest` (com `ContribuiParaValorPago` falsa): 0 → parcial → paga → volta a aberta; `capital_liquidado_em` preenchido e limpo; excesso lança e a transacção reverte; Cancelada/Anulada intocadas; sem transacção lança.
- Feature `PropinasReferenciamTest`: plano com propina não se elimina (mensagem de `EliminarPlanoPropinaAction`); moeda não muda com propinas.
- Tenancy (`FinanceiroTenancyTest`): propina do tenant B invisível em A; `tenant_id` não muda; sem contexto lança `TenantNaoResolvido`. `TenancyEsquemaTest` passa a cobrir `propinas` automaticamente.
- Permissão: `ModuloEnumTest` (novo caso/slug), `AcoesAplicaveisTest` (PROPINA tem as 8, não tem `editar`/`eliminar`/`negociar`), concessões de ADMIN_ESCOLA.
- Arquitectura: `FronteiraMatriculaFinanceiroTest` (novo). **Novo `EscritoresDePropinaTest`**: em `Modules/Financeiro/app`, só `RecalcularPropina` atribui `valor_pago`/`capital_liquidado_em`, e só `RecalcularPropina`, `CancelarPropinaAction`, `AnularPropinaAction` e `AlterarPrecoPlanoAction` atribuem `estado`/`valor` de `Propina` (varrimento por regex, padrão de `TenancyArquitecturaTest`).
- **Não provado em SQLite:** CHECKs; `lockForUpdate`.

**Critérios de aceitação.** Suite completa verde; `php artisan migrate:fresh --seed` funciona; a grelha de permissões mostra "Propinas" só com as acções declaradas; nenhuma rota nova.

**Riscos.** (a) Excesso de colunas snapshot se Q1/Q2 forem rejeitadas (fácil de retirar antes de F2). (b) O teste de "escritores" por regex pode ter falsos positivos; manter os padrões estreitos.

**Dependências.** Nenhuma além da Configuração. Bloqueia F2–F5 e Pagamentos.

---

### F2 — Geração (manual com pré-visualização + automática) e lista

**Objectivo.** Criar propinas de forma idempotente, sem cobrança dupla, para matrículas Activas, pelo plano aplicável a cada competência; e ver o resultado numa lista com filtros.

**Backend**
- `DTO/PedidoGeracaoPropinasDTO` (`ano_lectivo_id`, `plano_propina_id ?int`, `ambito` enum MATRICULA|TURMA|NIVEL|PLANO|TODAS, `alvo_id ?int`, `incluir_periodos_anteriores bool`, `origem`), `DTO/ResultadoGeracaoDTO` (por matrícula: `criadas[]`, `ignoradas[]` com motivo: *já coberta*, *antes da matrícula*, *outro plano aplicável*; `conflitos[]` (competência + planos), `sem_plano[]` (competências), `erros[]`; e totais).
- `Services/GerarPropinasService`: `preVisualizar(dto)` (só leitura) e `gerar(dto)` (escreve). **O mesmo cálculo** (`calcular(Matricula, contexto)`) nos dois.
- `Support/ElegibilidadeDePeriodos` (L19): hoje, `periodo_fim ≥ 1.º dia do mês de data_matricula` (ou tudo, com `incluir_periodos_anteriores`).
- `Support/ResolucaoEmCache` (R6): memoiza `ResolvePlanoAplicavel::paraTurma(turma, competência)` por `(turma_id, ano, mes)` durante uma execução.
- **Algoritmo por matrícula** (L8, L9):
  1. Transacção própria. `Matricula::lockForUpdate()`; se não Activa → ignorada (motivo). Invariante `matricula.ano_lectivo_id == turma.ano_lectivo_id`.
  2. Meses candidatos = união das competências dos planos activos do ano lectivo.
  3. Para cada mês m: `resolver(turma, m)` → plano P, conflito ou sem plano.
  4. Para cada plano P resolvido, para cada período de `P->periodos()` cujo **1.º mês** resolve para P: se modo manual com plano escolhido e P ≠ escolhido → ignorada "outro plano aplicável"; se não elegível → ignorada; se já existe propina activa com intervalo sobreposto (qualquer plano, §14 "regras fechadas") → ignorada "já coberta"; senão cria.
  5. Antes de criar: `PlanoPropina::whereKey(P)->sharedLock()` e reconfirmar que o período ainda pertence a P (L15). Invariantes `plano.ano_lectivo_id == matricula.ano_lectivo_id` e mesmo tenant (asserção; o scope já o garante).
  6. Snapshot: `valor` (Q3), `dias_tolerancia`, `data_vencimento = periodo_inicio` com dia `regras.dia_vencimento`, `data_limite`, `moeda`, `cambio_usd`, `origem`.
- Erros: uma violação do índice único aborta **só** a transacção dessa matrícula; é apanhada **fora** dela (`ViolacaoDeChave`-like para unique) e reportada em `erros`. Nenhum `try/catch` dentro da transacção.
- `Actions/GerarPropinasAction` (manual): chama `gerar`, devolve resumo (flash `geracao_propinas`, padrão `copia_planos`).
- **Automática:** `Console/GerarPropinasCommand` `financeiro:gerar-propinas` (`ParaTodosOsTenants`) → por tenant despacha `Jobs/GerarPropinasDoTenantJob` (`ComTenant`, `ShouldBeUnique` + `UnicoPorTenant`); o job sai sem fazer nada se `gerar_automaticamente = false`; senão `ambito = TODAS` para cada matrícula Activa. Agendamento em `routes/console.php`: `Schedule::command('financeiro:gerar-propinas --todos')->dailyAt('02:00')->withoutOverlapping()`.
- Requests: `PreVisualizarGeracaoPropinasRequest` / `GerarPropinasRequest` (mesmas regras; `ano_lectivo_id`, plano, turma, nível, matrícula `exists` com scope do tenant e sem soft delete; `incluir_periodos_anteriores` só com `propina.ajustar` — Q9).
- `Services/PropinaConsultaService::listar(filtros, hoje)`: paginação no servidor; filtros ano lectivo, estado resolvido (scope F1), aluno (nome, `alunos.numero_matricula`) / matrícula (`numero_registo_matricula`), plano, intervalo de vencimento; colunas com `saldo` e estado resolvido; `eager load` de matrícula.aluno, turma, plano.
- `Http/Controllers/PropinaController`: `index`, `preVisualizarGeracao` (JSON), `gerar` (redirect + flash).
- Rotas (`routes/web.php`, novo grupo `prefix('financeiro/propinas')->name('financeiro.propinas.')`):
  `GET /` (`can:propina.ver`), `POST /geracao/pre-visualizar` (`can:propina.criar`), `POST /geracao` (`can:propina.criar`).

**UI**
- `Pages/Propinas/Index.vue`: cartão de filtros (`bg-body-secondary`), tabela (Aluno + nº, Matrícula nº, Turma, Plano, Período — "Set/2026" ou "Set–Nov/2026" —, Vencimento, Valor, Pago, Saldo, Estado). Em Atraso com pagamentos mostra também o pago (§4). Botão "Gerar propinas" (só com `propina.criar`).
- `Components/Propinas/GerarPropinasModal.vue`: passo 1 parâmetros (ano lectivo, plano opcional, âmbito); passo 2 pré-visualização (totais + tabela por matrícula com criadas/ignoradas/conflitos/sem plano); passo 3 confirmar. Padrão de modal existente (`CopiarPlanosModal.vue`) e de axios (`MatriculaFormModal.vue`).
- `Components/Shared/EstadoCobrancaBadge.vue` (ao lado de `EstadoBadge.vue`; cores por estado resolvido; reaproveitar as classes de badge já usadas).
- `Models/EstadoCobranca.js` (labels/cores, espelho do enum).

**Menu (operação, separado de Configurações)**
- `SidebarMenuWrapper.vue`: descomentar `const financeiro` (manter `ki-dollar`, `paths: 2`) com **apenas** `{ href: '/financeiro/propinas', title: 'Propinas', permissao: 'propina.ver' }` activo (os restantes itens ficam comentados) e descomentar **só** a linha `<SidebarAccordion :item="financeiro" heading="Financeiro" />` no bloco "Sections por implementar". O grupo "Financeiro" dentro de `configuracoesMenu` não muda.
- Header (Q13): `resources/js/Composables/useFinanceiroMenu.js` + `resources/js/Components/Layout/menus/FinanceiroMenu.vue` + entrada `v-if` no `HeaderMenu.vue` (padrão `useAcademicoMenu`). `useConfiguracoesMenu.js` não muda.
- `useActiveMenu`: prefixo `/financeiro/propinas` activo também no detalhe (F3).

**Testes**
- `GerarPropinasServiceTest`: Set→Jun mensal = 10; trimestral = 3+3+3+1 (valor do último segundo Q3); atravessa o ano civil; a partir do mês da matrícula (15/Nov → Nov…; trimestral Set–Nov incluído); `incluir_periodos_anteriores`; só Activa (Pendente, Cancelada, Concluída ignoradas); precedência (turma > curso+nível > …); fases de preço (Set–Dez a 10 000, Jan–Jun a 12 000 geram com os valores certos); conflito reportado sem criar; sem plano reportado; plano inactivo não gera; **idempotência** (2× = 0 criadas na 2.ª, contagens iguais); sobreposição (mensal existente bloqueia trimestral e vice-versa, "já coberta"); regerar depois de cancelar; vencimento com `dia_vencimento`; geração retroactiva fica Em Atraso de imediato; snapshot (mudar valor do plano, `dias_tolerancia`, `dia_vencimento` e câmbio depois não mexe nas existentes); `moeda`/`cambio_usd` gravados; pré-visualização não escreve (contagem antes/depois); manual com plano escolhido ignora matrículas de outro plano (L9).
- `GerarPropinasControllerTest`: âmbitos matrícula/turma/nível/plano; 403 sem `propina.criar`; `incluir_periodos_anteriores` sem `propina.ajustar` → 403/422; ids de outro tenant → 404/422; flash do resumo.
- `GerarPropinasCommandTest`: `--tenant`, `--todos`, flag desligada = nada; dois tenants isolados; job corre com contexto (fila `sync`); job único por tenant.
- `PropinaConsultaServiceTest`: cada filtro; estado resolvido por SQL bate com o PHP; paginação; outro tenant invisível.
- Unit `ElegibilidadeDePeriodosTest`.
- **Não provado em SQLite:** duas gerações simultâneas na mesma matrícula (o índice parcial é a rede de segurança provada); `sharedLock` vs divisão de plano (L15).

**Critérios de aceitação.** Na app: gerar para uma turma, ver a pré-visualização, confirmar, ver a lista; correr de novo e ver "0 criadas"; menu "Financeiro → Propinas" visível só com `propina.ver`; header e sidebar coerentes. Suite verde; `npm run build` verde.

**Riscos.** R6 (desempenho: matrículas × meses × planos; mitigado pela cache por turma e por consultar matrículas em lotes); geração em massa demorada no pedido HTTP (se > ~2 000 matrículas, mover o "gerar" manual para job com notificação; fora de V1); o "tudo de uma vez" (Q14).

**Dependências.** F1.

---

### F3 — Detalhe, cancelar e eventos de matrícula (§8, §9)

**Objectivo.** Ver uma propina em detalhe, cancelá-la com motivo, e reagir a mudanças de estado da matrícula sem que Matricula dependa de Financeiro.

**Matricula (alterações mínimas, sem importar Financeiro)**
- `Modules/Matricula/app/Events/MatriculaEstadoAlterado.php` (payload de primitivos, §1.1). `ShouldDispatchAfterCommit` **não** (ver Q5: o listener de cancelamento corre na transacção).
- `AlterarEstadoMatriculaAction`: envolver em `DB::transaction` (save + histórico + evento). `dataEfeito` = `data_fim` (terminais) ou hoje.
- `CriarMatriculaAction`: despacha o evento quando a matrícula nasce **Activa** (`estadoAnterior = null`).
- `Contracts/DependenciasDaMatricula.php` + `Support/DependenciasRegistadasDaMatricula.php` (etiqueta `matricula.dependencias`); `AtualizarMatriculaAction` consulta-as antes de gravar (Q8) e lança `ValidationException` com o motivo.

**Financeiro**
- `Listeners/CancelarPropinasFuturasDaMatricula` (síncrono, na transacção — Q5): para CANCELADA, TRANSFERIDA (e CONCLUIDA se Q6): propinas da matrícula com `periodo_inicio > dataEfeito`, `estado = EM_ABERTO`, `valor_pago = 0` e sem histórico (`HistoricosDePagamentos`) → Cancelada, motivo "Matrícula cancelada/transferida em dd/mm/aaaa", `cancelado_por = utilizadorId`. As restantes ficam como dívida.
- `Listeners/GerarPropinasAoActivarMatricula` (Q7; implementa `ShouldHandleEventsAfterCommit` ou usa `DB::afterCommit`; confirmar o suporte para listeners síncronos em Laravel 12): se `estadoNovo = ACTIVA` e `gerar_automaticamente`, chama `GerarPropinasService` (âmbito MATRICULA, `origem = EVENTO_MATRICULA`). Falha registada, não propaga.
- Registo explícito no `FinanceiroServiceProvider::boot()` (`Event::listen`); o Financeiro não tem `EventServiceProvider`.
- `Support/DependenciasDePropinas` implementa `DependenciasDaMatricula`.
- **Reconciliação** no job diário de F2 (sempre, mesmo com `gerar_automaticamente = false`): aplica a regra do listener a matrículas não Activas com propinas futuras canceláveis. Idempotente.
- `Actions/CancelarPropinaAction` (`DTO/CancelarPropinaDTO` com motivo): bloqueia a propina; exige `EM_ABERTO`, `valor_pago = 0`, sem pagamentos confirmados no histórico (contrato); grava motivo/por/em e `estado = CANCELADA`. Ponto de extensão para multas (F5, §8).
- `Services/PropinaConsultaService::detalhe(Propina)`: plano de origem, snapshot (valor original, valor actual, vencimento, tolerância, data limite, moeda, câmbio), matrícula/aluno/turma, ajustes (F4, vazio até lá), distribuições (vazio até Pagamentos; lista vinda de contrato), estado resolvido.
- Rotas: `GET /{propina}` (`can:propina.ver`), `PATCH /{propina}/cancelar` (`can:propina.cancelar`). **Sem** rota de eliminar.

**UI**
- `Pages/Propinas/Show.vue` (cartões `bg-body-secondary`: Propina, Snapshot, Matrícula, Ajustes, Pagamentos).
- `Components/Propinas/CancelarPropinaModal.vue` (motivo obrigatório; botão só com `propina.cancelar` e quando a propina é cancelável — o servidor decide via flag `pode_cancelar`).
- Link na lista para o detalhe.

**Testes**
- `CancelarPropinaTest`: Em Aberto sem pagamentos cancela; Parcial, Paga, Cancelada, Anulada → 422; com histórico (contrato falso) → 422; motivo obrigatório/limite; `estado_descricao`; não há eliminação (DELETE → 405); regerar depois de cancelar cria nova propina.
- `MatriculaEstadoAlteradoTest`: Cancelada cancela só `periodo_inicio > data_fim` sem pagamentos; período em curso fica; com pagamento (contrato falso) fica; Transferida igual; Concluída segundo Q6; `data_fim` retroactiva; falha no listener reverte a mudança de estado da matrícula (Q5); activar gera (Q7) e uma falha da geração não impede activar; matrícula criada já Activa dispara o evento.
- `DependenciasDaMatriculaTest`: mudar turma com propinas activas → 422; sem propinas → livre; com propinas todas canceladas → livre.
- Reconciliação: listener "perdido" (simulado) é corrigido pelo job.
- Arquitectura: `FronteiraMatriculaFinanceiroTest` continua verde (o evento e o contrato vivem em Matricula).
- Tenancy: detalhe/cancelar de B → 404; listener só toca no tenant da matrícula.
- Testes existentes de Matricula continuam verdes (o evento sem listeners não muda comportamento).
- **Não provado em SQLite:** concorrência cancelar × pagamento (Pagamentos-A acrescenta o teste em pgsql, se existir).

**Critérios de aceitação.** Cancelar na app com motivo; cancelar uma matrícula na app e ver as propinas futuras canceladas com o motivo correcto; suite verde.

**Riscos.** Mexer em Matricula (módulo estável): mudanças mínimas, cobertas pelos testes existentes. Q5 errada (atómico) pode impedir cancelar matrículas se Financeiro falhar: mitigado por o listener ser simples e testado.

**Dependências.** F2.

---

### F4 — Alteração de preço de um plano com propinas (§14, semântica FINAL do dono)

**Objectivo.** Uma única operação explícita, transaccional e auditável para mudar o preço de um plano que já tem propinas (L18), com escolha obrigatória **sem opção pré-seleccionada** entre APLICAR_GERADAS e MANTER_GERADAS, novo valor e `mes_efeito` explícitos, divisão do plano, ajustes imutáveis e pré-visualização com impressão digital.

**Tabelas**
- `2026_10_13_110000_create_alteracoes_preco_plano_table`: `id`, `tenant_id`, `plano_propina_id` (FK restrict; plano alterado), `plano_novo_id` (FK null restrict; nulo só no modo in loco, L1), `modo` + `modo_descricao` (enum `ModoAlteracaoPreco`: APLICAR_GERADAS=1, MANTER_GERADAS=2), `valor_anterior`, `valor_novo` (bigint), `mes_efeito` (date, 1.º dia), `motivo` (text), `total_propinas_afectadas` (int), `impressao_digital` (char 64, auditoria), `criado_por`, timestamps. Índices `(tenant_id, plano_propina_id)`, `(tenant_id, plano_novo_id)`. CHECK pgsql: `valor_novo > 0`, `valor_novo <> valor_anterior`.
- `2026_10_13_110100_create_propina_ajustes_table`: `id`, `tenant_id`, `alteracao_preco_id` (FK restrict), `propina_id` (FK restrict), `valor_anterior`, `valor_novo`, `valor_pago_no_momento`, `plano_anterior_id` (FK), `plano_novo_id` (FK null), `motivo`, `criado_por`, `created_at` (sem `updated_at`). Índices `(tenant_id, propina_id, id)`, `(alteracao_preco_id)`. Model `PropinaAjuste` só aceita `create` (`updating`/`deleting` lançam).
- `propinas.valor_original` já existe (F1).

**Backend**
- `Enums/ModoAlteracaoPreco`.
- `DTO/AlterarPrecoPlanoDTO` (`valorNovo Dinheiro`, `mesEfeito {ano, mes}`, `modo ModoAlteracaoPreco`, `motivo ?string`, `impressaoDigital ?string`).
- `Http/Requests/PreVisualizarAlteracaoPrecoRequest` e `AlterarPrecoPlanoRequest`: `valor` (`ValorMonetario(false)`, ≠ actual), `mes_efeito` (`Y-m`, tem de ser início de período do plano), `modo` **required** `Rule::enum` (sem default; a ausência dá 422 "Escolha como tratar as propinas já geradas"), `motivo` required na confirmação, `impressao_digital` required na confirmação. `authorize`: `propina.ajustar` **e** `plano-propina.editar` (Q12).
- `Services/PrecoDoPlanoService::preVisualizar(plano, dto)` (só leitura) → `PreVisualizacaoAlteracaoPrecoDTO`:
  - validações: `mes_efeito` é fronteira de período; dentro do plano; modo in loco se `mes_efeito` = 1.º período (L1); **competências do plano novo = sufixo das do antigo** (L6); nome gerado único no ano (`"{nome} (desde MM/AAAA)"`, truncado a 100, com sufixo numérico se colidir);
  - planos resultantes e respectivos períodos;
  - APLICAR: propinas elegíveis (`plano_propina_id = plano`, `estado IN (1,2)`, `periodo_inicio ≥ mes_efeito`), por propina: valor antes/depois, saldo antes/depois, `valor_pago`, sinal de **descida abaixo do pago** (`valor = valor_pago`, passa a Paga, sem devolução); `periodo_inicio` sem correspondência no plano novo → recusa;
  - inalteradas, com motivo: anteriores a `mes_efeito`; Pagas/Canceladas/Anuladas; (F5) multas "não alteradas";
  - totais antes/depois;
  - MANTER: **aviso** (M1) com contagens: matrículas com propinas futuras já geradas ao valor antigo; matrículas Activas do âmbito do plano **sem** propinas nesses meses (pagarão o novo); exemplo concreto de mesma turma com os dois valores, se existir;
  - `impressao_digital` = sha256 de: parâmetros (`modo`, `mes_efeito`, `valor_novo`), estado do plano (`id`, `ano_lectivo_id`, `valor`, `mes_inicio`, `mes_fim`, `updated_at`) e, por propina elegível ordenada por id, (`id`, `valor`, `valor_pago`, `estado`, `plano_propina_id`) (L16).
- `Actions/AlterarPrecoPlanoAction::executar(plano, dto)` — **uma** `DB::transaction`, sem `try/catch` dentro:
  1. `PlanoPropina::lockForUpdate()` e `BloqueioDePropinas::bloquear(elegíveis)` (mesma ordem de `RecalcularPropina`).
  2. Verificação defensiva do ano lectivo (ver "Regra obrigatória: isolamento por ano lectivo" abaixo; aborta tudo). Recalcular a pré-visualização sobre o estado bloqueado; impressão diferente → `ValidationException('impressao_digital' => 'Os dados mudaram desde a pré-visualização. Reveja e confirme de novo.')` (rollback; um 2.º clique cai aqui).
  3. Encurtar o plano antigo (`mes_fim` = mês anterior a `mes_efeito`) — escrita directa no model, não pela `AtualizarPlanoPropinaAction`.
  4. Criar o plano novo (mesmo ano, periodicidade, intervalo, estado, alvos copiados incluindo alvos de turma; `mes_inicio` = mês de efeito; `mes_fim` original; valor novo). Validar colisão com `AlvosDoPlano`/lógica de `colisaoDeAlvos` (não deve haver; asserção).
  5. APLICAR: por propina, `plano_propina_id` = novo, `ordem` = posição do `periodo_inicio` nos períodos do plano novo, `valor = max(valor_novo, valor_pago)`, vencimento mantido; `RecalcularPropina`.
  6. Registar `alteracoes_preco_plano` e um `propina_ajustes` por propina alterada.
  7. Nada fora da BD; se houver efeitos (ex.: invalidar cache de listagens), `DB::afterCommit`.
  - Modo in loco: passos 3–4 substituídos por `plano.valor = valor_novo`; APLICAR reajusta no próprio plano (sem reassociar).
- `AtualizarPlanoPropinaAction` (gancho "única porta de entrada"): se o plano tem propinas (L18) e o `valor` muda → `ValidationException('valor' => 'Este plano já tem propinas geradas. Use "Alterar preço" para escolher como tratar as propinas existentes.')`; o mesmo para campos de calendário (Q10). `PlanoPropinaConsultaService` passa a devolver `total_propinas` por plano.
- `Services/PlanoPropinaConsultaService::historicoDePrecos(plano)` (lista de `alteracoes_preco_plano`).
- Rotas (no grupo `financeiro/configuracao/planos-propina`): `POST /{plano}/alterar-preco/pre-visualizar` (JSON) e `POST /{plano}/alterar-preco`, ambas com `can:propina.ajustar` e `can:plano-propina.editar`.
- Permissões: ADMIN_ESCOLA ganha `propina.ajustar`; dono corre `financeiro:sincronizar --todos`.

**UI**
- `Pages/PlanosPropina/Index.vue`: com `total_propinas > 0`, o campo valor (e calendário) fica só leitura no `PlanoPropinaFormModal.vue` com a explicação; nova acção "Alterar preço".
- `Components/PlanosPropina/AlterarPrecoPlanoModal.vue`: passo 1 — novo valor, mês de efeito (select só com inícios de período), **dois cartões de rádio sem nenhum marcado** ("Aplicar às propinas já geradas" / "Manter as já geradas"), botão "Pré-visualizar" desactivado até haver escolha; passo 2 — pré-visualização (planos resultantes, tabela por propina, inalteradas e porquê, totais, aviso MANTER em destaque), motivo obrigatório, "Confirmar"; erro de impressão digital volta ao passo 2 com a pré-visualização refeita.
- `Pages/Propinas/Show.vue`: cartão "Ajustes" (lista de `propina_ajustes`).

**Regra obrigatória: isolamento por ano lectivo (spec §14)**
Uma alteração de preço pertence a **um só** ano lectivo, o do plano alterado (`planos_propina.ano_lectivo_id`, imutável), e só toca no plano e nas propinas desse ano. Cobertura neste plano:
- **Origem do conjunto:** a Action e a pré-visualização partem **sempre** de `propinas.plano_propina_id = plano.id` (nunca de "matrículas da turma", "alvos do plano" ou "propinas do aluno"). Nenhuma consulta de F4 filtra por alvos ou turmas.
- **Verificação defensiva antes de escrever** (passo 2 da transacção, sobre as propinas já bloqueadas): toda a propina candidata cumpre `matricula.ano_lectivo_id = plano.ano_lectivo_id` **e** `propinas.ano_lectivo_id = plano.ano_lectivo_id` (coluna de Q2; se Q2 for rejeitada, só a primeira). Uma única violação → `ValidationException('plano' => 'Foram encontradas propinas de outro ano lectivo associadas a este plano. A operação foi cancelada; contacte o suporte.')` lançada **dentro** da transacção, que reverte tudo (nada escrito, nada apanhado lá dentro). Registar no log com os ids (é uma violação da invariante da geração, §3).
- **Plano novo** da divisão herda **o mesmo** `ano_lectivo_id`; F4 nunca cria nem altera planos de outro ano (asserção no passo 4).
- **`mes_efeito`** tem de ser um mês do calendário do ano lectivo do plano, isto é, o início de um período de `plano->periodos()` (calculado sobre `ano_lectivo.data_inicio`); qualquer outro mês, incluindo o mesmo mês de outro ano civil, é 422.
- **Impressão digital** inclui `ano_lectivo_id` (do plano) além do que está em L16; mudar o ano (ou o plano ser de outro ano) invalida-a.
- **Pré-visualização** mostra o ano lectivo abrangido (nome e id) e a linha de garantia "Nenhuma propina de outros anos lectivos será alterada", com a contagem **verificada** de propinas de outros anos no conjunto (= 0; se ≠ 0 a pré-visualização devolve o mesmo erro da verificação defensiva e o botão de confirmar não aparece).
- **Dívidas de anos anteriores** só se corrigem com uma operação separada, aberta explicitamente sobre o plano desse ano (mesmo ecrã "Alterar preço" desse plano, com pré-visualização, motivo e registo próprios). A UI nunca oferece "aplicar também a anos anteriores".
- `alteracoes_preco_plano` regista implicitamente o ano via `plano_propina_id`; *proposta:* coluna `ano_lectivo_id` (FK, snapshot) para auditoria e filtros, com CHECK de coerência na Action.

**Mitigações para MANTER_GERADAS + geração completa (L7, Q14)**
- **M1** Aviso obrigatório na pré-visualização com números reais (quantas matrículas ficam com o valor antigo, quantas novas pagarão o novo, exemplo na mesma turma).
- **M2** Na confirmação MANTER, caixa de verificação "Compreendo que alunos da mesma turma vão pagar valores diferentes" (exigida pelo servidor quando a contagem de M1 for > 0).
- **M3** A lista de propinas e o detalhe mostram o plano de origem (nome com "(desde MM/AAAA)"), para a diferença ser explicável ao balcão.
- **M4** Reversão = nova alteração APLICAR com `mes_efeito` igual, auditada (nunca editar ajustes).

**Testes**
- `AlterarPrecoPlanoTest`: sem `modo` → 422; `mes_efeito` não-fronteira → 422; plano antigo sem meses → modo in loco; APLICAR (Em Aberto e Parcial a partir de `mes_efeito` reassociadas com `ordem` nova, valor novo, vencimento mantido; Pagas/Canceladas/Anuladas e anteriores intocadas); MANTER (nenhuma propina muda, plano dividido, nova geração usa o plano novo e não duplica meses cobertos); parcial com subida (saldo = novo − pago); **descida abaixo do pago** (valor = pago, Paga, sem devolução, ajuste registado); plano novo com alvos copiados e nome único; **âncora L6** recusada; impressão digital desactualizada (pagamento falso entre pré-visualização e confirmação) recusada; **2.º clique** recusado; pré-visualização não escreve.
- **Injecção de falhas** (obrigatória pelo spec): falha forçada em cada etapa (criar plano novo, reassociar, reajustar, `alteracoes_preco_plano`, `propina_ajustes`) — por listener de model que lança, registado no teste — e prova de que plano antigo, propinas e tabelas de registo ficam exactamente iguais.
- **Isolamento por ano lectivo** (`AlterarPrecoPlanoIsolamentoAnoLectivoTest`, obrigatórios pelo spec): (1) duas operações em anos diferentes: as propinas do outro ano, em aberto, ficam intactas (`valor`, `valor_pago`, `plano_propina_id`, `ordem`, sem `propina_ajustes`); (2) ano **anterior** com dívida em aberto (mesma matrícula/aluno renovado) não é tocado em APLICAR nem MANTER; (3) ano **futuro** com plano de alvos iguais não é tocado e a divisão não colide com ele (nem nome, nem `colisaoDeAlvos`); (4) `mes_efeito` fora do calendário do ano (ex.: mesmo mês de outro ano civil, mês posterior ao fim do plano) → 422; (5) **fixture** com propina de outro ano injectada no conjunto (criada directamente com `plano_propina_id` do plano e matrícula de outro ano) faz abortar a operação inteira: plano, propinas, `alteracoes_preco_plano` e `propina_ajustes` inalterados; (6) pré-visualização inclui nome e id do ano e contagem 0 de outros anos (e erro quando a fixture de (5) existe); (7) impressão digital inválida se o ano mudar; (8) o plano novo tem o mesmo `ano_lectivo_id`.
- **Invariante auditável**: após cada cenário, para todas as propinas `valor = valor_original + Σ(valor_novo − valor_anterior)` e encadeamento (`valor_anterior` = `valor_novo` do ajuste precedente).
- Imutabilidade: `valor_original` (F1) e `PropinaAjuste` update/delete lançam.
- `AtualizarPlanoPropinaAction`: valor/calendário com propinas → 422; nome/alvos → OK; sem propinas → edição simples (testes existentes verdes).
- Permissões: sem `propina.ajustar` ou sem `plano-propina.editar` → 403. Tenancy: plano de B → 404.
- **Não provado em SQLite:** `lockForUpdate` e a corrida §14 × geração (L15) × pagamento; aborto de transacção em pgsql após erro SQL. Risco aceite (spec §14) até haver testes em pgsql.

**Critérios de aceitação.** Na app: alterar o preço de um plano com propinas nas duas escolhas, ver pré-visualização, confirmar, ver o plano dividido, as propinas reajustadas e o histórico de ajustes no detalhe. Suite verde.

**Riscos.** O mais complexo do módulo. L6 (âncora) pode recusar casos legítimos raros: documentar a mensagem. Divisões sucessivas multiplicam planos ("(desde …)") na lista de configuração: aceitável, mas pedir feedback do dono (agrupar visualmente por "família" no futuro).

**Dependências.** F2 (precisa de propinas); F3 recomendado (detalhe para mostrar ajustes).

---

### Pagamentos-A (plano próprio, fora deste documento; só o que toca Propinas)

- Implementa `ContribuiParaValorPago` (distribuições de pagamentos Confirmados + utilizações de crédito Activas, **com data**) e `HistoricoDePagamentosDaPropina`.
- `RegistarPagamentoAction`/`AnularPagamentoAction` chamam `BloqueioDePropinas` + `RecalcularPropina`.
- **Anular propina** (§8): `AnularPropinaAction` (`propina.anular`, motivo; `valor_pago = 0` **e** histórico com todos anulados), rota `PATCH /financeiro/propinas/{propina}/anular`, modal no detalhe; ADMIN_ESCOLA ganha `propina.anular`.
- O detalhe da propina passa a listar distribuições.
- Teste em pgsql recomendado para a concorrência (spec Pagamentos §9 "dois pagamentos simultâneos").

---

### F5 — Multas por atraso (§15) — **só depois de Pagamentos-A**

**Porque depois de Pagamentos-A.** `saldo_em(d)` precisa das **datas** dos pagamentos (L17: sem elas, todas as propinas vencidas seriam multadas). `capital_liquidado_em` também só ganha valor real com pagamentos.

**O que pode ser construído antes (se o dono quiser adiantar sem activar):** tabelas, models, cálculo puro de escalões (`Support/CalculoDeMulta`, testável com contribuições falsas), `Dinheiro::pontosBase` (L5). **O que só pode existir depois:** o agendamento do comando em produção, `RecalcularMulta` com valores reais (depende de `pagamento_multas`, Pagamentos-B), imputação capital → multa (Pagamentos-B).

**Onde ficam as tabelas:** no módulo `Financeiro` (Models planos `PropinaMulta`, `PropinaMultaRevisao`), migrations `Modules/Financeiro/database/migrations/2026_10_xx_*`, **antes** de `pagamento_multas` (Pagamentos-B, FK para `propina_multas`).

**Tabelas**
- `propina_multas` (1:1 com `propinas`): `tenant_id`, `propina_id` (FK restrict), `escalao_ordem` (tinyint), `tipo` + `tipo_descricao` (`TipoMulta`), `valor_regra` (bigint: pontos-base ou unidades menores), `base_capital` (bigint, congelada em L₁), `valor`, `valor_pago` (cache), `estado` + `estado_descricao` (`EstadoMulta`: ACTIVA, REDUZIDA, ISENTA, ANULADA), `bloqueada_automatico` (bool), `criado_por`/`editado_por` (nulos pelo comando), timestamps. `unique(tenant_id, propina_id)`; `index(tenant_id, estado)`. CHECK pgsql `valor_pago BETWEEN 0 AND valor`, `escalao_ordem BETWEEN 1 AND 3`. Sem FK para `escaloes_multa`.
- `propina_multa_revisoes` (só acrescenta): `tenant_id`, `propina_multa_id` (FK), `origem` + `origem_descricao` (AUTOMATICA=1, MANUAL=2), `escalao_ordem` null, `valor_anterior`, `valor_novo`, `data_referencia` (date null), `motivo` (text null; obrigatório se MANUAL), `criado_por` null, `created_at`. Índice parcial SQL cru: `CREATE UNIQUE INDEX propina_multa_revisoes_automatica_unica ON propina_multa_revisoes (propina_multa_id, escalao_ordem) WHERE origem = 1`.

**Backend**
- `Dinheiro::pontosBase(int)` (L5).
- `Services/AplicarMultasService::paraPropina(Propina, hoje)` — transacção por propina, `BloqueioDePropinas`; para cada escalão `k > escalao_ordem` da regra em vigor: `L_k = data_vencimento + dias_tolerancia + dias_atraso_k`; aplica se `L_k ≤ hoje` e `saldo_em(L_k) > 0`; `base_capital = saldo_em(L₁)` fixa na criação; `valor_novo = max(valor_actual, valor_k)`; criação por verificação + `create` sob bloqueio (L4); subida por `PropinaMulta::whereKey($id)->where('escalao_ordem', '<', $k)->where('bloqueada_automatico', false)->update([...])` e revisão só se `affected = 1`.
- `Console/AplicarMultasCommand` `financeiro:aplicar-multas` (`ParaTodosOsTenants`) → `Jobs/AplicarMultasDoTenantJob` (`ComTenant`, `UnicoPorTenant`); só tenants com `multa_activa`; candidatas: propinas não Canceladas/Anuladas com `data_limite + min(dias_atraso) ≤ hoje`. Agendado diário, `withoutOverlapping`, **depois** de `financeiro:gerar-propinas`.
- `Actions/IsentarMultaAction` (`propina.isentar-multa`, motivo obrigatório; novo `valor ≥ valor_pago`; REDUZIDA ou ISENTA; `bloqueada_automatico = true`; revisão MANUAL).
- `Services/RecalcularMulta` (esqueleto; Pagamentos-B liga `pagamento_multas` e utilizações de crédito).
- §8 com multas: `CancelarPropinaAction`/`AnularPropinaAction` passam a anular a multa sem pagamentos e a bloquear com pagamentos de multa Confirmados.
- §14: pré-visualização lista multas como "não alteradas".
- Permissões: ADMIN_ESCOLA ganha `propina.isentar-multa`; ver multas segue `propina.ver`.

**UI.** Coluna "Multa pendente" na lista e total por aluno; cartão "Multa" no detalhe (valor, pago, pendente, escalão, revisões); `Components/Propinas/IsentarMultaModal.vue`.

**Testes (§15.7, todos).** Percentagem e valor fixo; arredondamento; moeda sem decimais; base congelada; `max()` nunca baixa; capital pago antes de L₂ congela; depois de L₂ aplica; catch-up com o comando parado vários dias; diferença com multa parcialmente paga; isenção bloqueia o automático; revisões imutáveis; comando 2× sem duplicar; regras alteradas não mexem nas existentes; `multa_activa` desligada; cancelar propina com/sem multa paga; reconciliação `valor_pago` = Σ `pagamento_multas` (Pagamentos-B); tenancy; arquitectura (sem `insertOrIgnore`).
**Não provado em SQLite:** execução concorrente do comando (camada 3 de §15.5); CHECKs.

**Critérios de aceitação.** Com dados de pagamento reais (Pagamentos-A), correr o comando duas vezes e ver as multas certas e nenhuma duplicada; isentar com motivo; suite verde.

**Riscos.** L17 (activar antes de Pagamentos); volume diário (índice `(tenant_id, estado, data_limite)` já criado em F1); interpretação "regra em vigor" vs snapshot (spec §15.6: só escalões ainda não atingidos usam a regra nova — testado).

**Dependências.** Pagamentos-A. Pagamentos-B depende de F5.

---

## 6. Riscos transversais

| # | Risco | Mitigação |
|---|---|---|
| R1 | Concorrência (lockForUpdate, sharedLock) não testável em SQLite | Testes de concorrência numa configuração pgsql opcional (`phpunit.pgsql.xml`), recomendada antes de Pagamentos; até lá risco aceite (spec §14) |
| R2 | CHECK só em pgsql | Invariantes repetidas no model/serviço e testadas em SQLite |
| R3 | `try/catch` dentro de transacções funciona em SQLite e falha em pgsql | Regra de revisão de código em cada fase; conversões de erro sempre fora da transacção |
| R4 | MANTER_GERADAS cria preços diferentes na mesma turma | M1–M4; Q14 |
| R5 | Âncora do calendário na divisão (L6) | Validação de sufixo de competências; correcção estrutural futura |
| R6 | Desempenho da geração (resolvedor carrega todos os planos por chamada) | Cache por `(turma, competência)` e lotes |
| R7 | Mudanças em Matricula (evento, transacção, contrato) | Mínimas, cobertas pelos testes existentes + teste de fronteira |
| R8 | Multas activadas antes de Pagamentos (L17) | F5 depois de Pagamentos-A; `multa_activa` desligada por omissão |
| R9 | Corrida geração × divisão de plano (L15) | `sharedLock` no plano + reconfirmação; teste pgsql |
| R10 | Alteração de preço (§14) contaminar outro ano lectivo (dívidas anteriores, planos futuros com alvos iguais), por consulta mal delimitada ou por propina incoerente com o ano do plano | Regra obrigatória de isolamento (F4): conjunto só por `plano_propina_id`, verificação defensiva `matricula.ano_lectivo_id = plano.ano_lectivo_id` com aborto total, plano novo no mesmo ano, `mes_efeito` no calendário do ano, `ano_lectivo_id` na impressão digital, contagem 0 visível na pré-visualização, 8 testes obrigatórios; invariante da geração (F2) e coluna `propinas.ano_lectivo_id` (Q2) como primeira linha |

## 7. Tabelas e relações (no fim de F5 + Pagamentos)

| Tabela | Fase | Relações |
|---|---|---|
| `propinas` | F1 | N:1 `matriculas` (restrict), N:1 `planos_propina` (restrict), N:1 `ano_lectivos`, N:1 `tenants`; 1:N `propina_ajustes`; 1:1 `propina_multas`; 1:N `pagamento_propinas` e `credito_utilizacoes` (Pagamentos) |
| `alteracoes_preco_plano` | F4 | N:1 `planos_propina` (plano alterado), N:1 `planos_propina` (`plano_novo_id`, nulo no modo in loco); 1:N `propina_ajustes` |
| `propina_ajustes` | F4 | N:1 `alteracoes_preco_plano`, N:1 `propinas`, N:1 `planos_propina` (anterior e novo) |
| `propina_multas` | F5 | 1:1 `propinas` (`unique(tenant_id, propina_id)`); 1:N `propina_multa_revisoes`; 1:N `pagamento_multas` (Pagamentos-B); **sem** FK para `escaloes_multa` (snapshot) |
| `propina_multa_revisoes` | F5 | N:1 `propina_multas`; único parcial `(propina_multa_id, escalao_ordem) WHERE origem = AUTOMATICA` |
| `planos_propina` (existe) | — | 1:N `propinas`; 1:N `plano_propina_alvos`; dividido por F4 |
| `regras_cobranca`, `escaloes_multa` (existem) | — | lidas na geração (snapshot de vencimento/tolerância) e no comando de multas; nunca referenciadas por FK |
| `matriculas` (existe, Matricula) | — | 1:N `propinas`; não conhece o Financeiro (evento + contrato) |

---

## 8. Decisões do dono (aprovadas) e critérios de aceitação transversais

**Aprovado:** todas as recomendações Q1–Q15 e a nova ordem das fases (F1 modelo → F2 geração e lista → F3 detalhe/cancelar/evento → F4 alteração de preço → Pagamentos-A → F5 multas → Pagamentos-B). **Regra de processo:** não se avança para a fase seguinte sem concluir e validar a fase actual (suite completa verde, revisão, validação em PostgreSQL quando a fase o exige). Sem commits; só `git add`.

### 8.1 Clarificações às respostas
- **Q3 (último período curto):** cobra-se o valor integral, mas o **formulário do plano** (e a lista) tem de deixar claro que o valor configurado é **por período de cobrança** e mostrar a **duração** de cada período e o **valor total** do plano (ex.: "3 meses por período · 4 períodos (3+3+3+1) · total 4 × 25 000"), com aviso quando o último período é mais curto. Entrega em F1 (UI de planos, sem nova tabela).
- **Q4 (matrícula a meio de período):** cobrança integral para **novas matrículas**, sem pró-rata. A **geração tardia** (propina criada depois do respectivo vencimento ou para períodos anteriores ao mês da matrícula) distingue-se de uma matrícula nova: `propinas.origem_geracao` (+ `origem_geracao_descricao`): MATRICULA, COMANDO, MANUAL, RETROACTIVA (a última exige `propina.ajustar`, regista autor e motivo); a pré-visualização e a lista mostram a origem e a UI sinaliza "geração tardia".
- **Q6 (Concluída):** cancelam-se as propinas futuras elegíveis (sem pagamentos, `periodo_inicio` > data de conclusão), sem apagar dívidas, pagamentos nem histórico anterior.
- **Q8 (turma/ano):** bloqueia-se a alteração directa de `turma_id` e `ano_lectivo_id` com propinas (via `DependenciasDaMatricula`). A **transferência** segue o fluxo próprio: preserva as cobranças antigas da matrícula de origem e trata as futuras à parte (cancelamento das futuras sem pagamentos na origem; geração na matrícula de destino pelo seu plano). Mensagem de bloqueio indica o fluxo correcto.
- **Q10 (calendário de plano):** bloqueados `periodicidade`, `intervalo_meses`, `mes_inicio`, `mes_fim` (e `ano_lectivo_id`, já imutável) quando o plano tem propinas. `nome` e alvos editáveis desde que **não alterem retroactivamente** as propinas existentes (que guardam o seu plano e valor): a alteração de alvos só afecta gerações futuras e é re-validada contra colisão; a UI avisa disso.

### 8.2 Quatro confirmações obrigatórias (critérios de aceitação)
1. **Estado e saldo coerentes.** `valor_pago` só é escrito por `RecalcularPropina` (= Σ distribuições de pagamentos Confirmados + utilizações de crédito Activas); `saldo = valor − valor_pago`; `valor = valor_original + Σ(ajustes)`; os ajustes mudam `valor`, nunca `valor_pago`; o estado persistido (Em Aberto/Parcialmente Paga/Paga) deriva dos dois. Teste de **reconciliação global** (todas as propinas depois de cada operação) em F1, F3, F4 e Pagamentos-A, incluindo a descida de preço abaixo do já recebido (`valor = valor_pago`, sem devolução).
2. **Cancelar ≠ anular.** *Cancelar*: propina Em Aberto **sem qualquer pagamento confirmado nem histórico** (inclui cancelamentos automáticos por evento); estado Cancelada, `motivo_cancelamento`, autor e data. *Anular*: propina que **já recebeu dinheiro** e cujos pagamentos foram todos anulados (`valor_pago = 0` com histórico); estado Anulada, `motivo_anulacao`, autor e data; só em Pagamentos-A. Uma propina com `valor_pago > 0` não pode ser cancelada nem anulada: primeiro anulam-se os pagamentos. Acções, permissões, estados, motivos e mensagens distintos.
3. **Geração recuperável.** A geração pós-activação corre **depois do commit** (listener em fila com tentativas e backoff, ou execução síncrona protegida por `try/catch` fora da transacção) e **falhar nunca desfaz nem bloqueia a matrícula**. Rede de segurança: o comando diário `financeiro:gerar-propinas` (idempotente) gera o que faltar para matrículas Activas, e a falha fica registada (log estruturado com matrícula e tenant). Teste: simular falha após o commit → matrícula Activa sem propinas → o comando gera-as; segunda execução não duplica.
4. **Isolamento por ano lectivo na alteração de preço (F4)** na pré-visualização, na revalidação transaccional e nos testes **PostgreSQL** (secção 8.3).

### 8.2b Fuso horário da escola (decisão do dono, 2026-10-13)
O "hoje" de negócio (vencimento, Em Atraso, data de pagamento, câmbio) é o dia civil do fuso da escola (Configurações → Dados da Escola; por omissão `Africa/Luanda`), não o de UTC. A zona da aplicação e os timestamps guardados não mudam. **F2, Pagamentos e fases seguintes têm de usar `RelogioDoTenant::hoje()` (e `agora()` para instantes)**; `now()`/`today()`/`Carbon::now()` são proibidos em `Modules/Financeiro/app` (teste de arquitectura). Plano: `2026-10-13-fuso-horario-escola.md`.

### 8.3 Infra de testes PostgreSQL (nova; exigida por F1 e F4)
A suite normal corre em SQLite (`phpunit.xml`), que não executa `lockForUpdate`, não aplica CHECK/índices parciais como o PostgreSQL e não permite provar concorrência. Cria-se um grupo `#[Group('pgsql')]`: corre com `DB_CONNECTION=pgsql` numa **base de dados de teste separada** (`mositec_escola_test`, nunca a base de desenvolvimento nem dados reais), é **ignorado** (skipped) quando a ligação não existe, e exclui-se da suite por omissão. Conteúdo: (F1) CHECK `0 ≤ valor_pago ≤ valor`, índice único parcial de cobrança dupla e rejeição de duplicados; (F4) isolamento por ano lectivo com propinas de outros anos, aborto total quando uma propina de outro ano é injectada, e **concorrência** com duas ligações (confirmação duplicada, pagamento entre pré-visualização e confirmação, geração × divisão de plano). Migrações correm no PostgreSQL de teste via `migrate:fresh` apenas nessa base.

