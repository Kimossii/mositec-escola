# Passagem de testemunho — Módulo Financeiro (Propinas e Pagamentos)

Documento para qualquer pessoa ou modelo de IA (por exemplo, DeepSeek) que continue o trabalho **sem acesso ao histórico da conversa**. Está escrito para ser autónomo. Lê-o inteiro antes de tocar em código. Língua do projecto e das respostas ao dono: **português (PT-PT)**.

Projecto: Laravel 12 + Inertia/Vue 3, módulos `nwidart`, multi-tenant (1 tenant = 1 escola). Ramo: `feat/modulo-financeiro`. Módulo: `Modules/Financeiro`.

---

## 1. Regras do dono (invioláveis)

1. **Nunca fazer commit.** Só `git add`. O dono faz os commits.
2. **Nunca tocar na base de dados `mositec_escola`** (a de desenvolvimento do dono) nem correr `migrate`, `db:seed` ou `financeiro:sincronizar` contra ela. Quem os corre é o dono.
3. **PostgreSQL só numa instância descartável**, em `127.0.0.1:55432`, base `mositec_escola_test`, criada e apagada por ti. Antes: confirmar a porta livre (`ss -ltn | grep 55432`). No fim: parar, apagar, confirmar a porta livre. Nunca correr duas execuções do grupo `pgsql` ao mesmo tempo na mesma base.
4. **Não correr Pint** (reformata ficheiros inteiros fora do estilo do projecto).
5. **Não avançar de fase sem a fase actual estar concluída e validada** (testes + revisão).
6. **Ser crítico**: ao fechar cada tarefa, tenta quebrar o teu próprio teste mentalmente. Um teste que nunca falhou não prova nada.
7. **Ser objectivo e económico em tokens.** Sem voltas exploratórias; diagnosticar tudo de uma vez.
8. **No fim de cada resposta de implementação/desenho**, mostrar as **tabelas envolvidas e as suas relações**.
9. Só se executa a remoção/alteração de coisas irreversíveis ou decisões de política com **autorização expressa** do dono. As decisões de política em aberto (secção 6) são **dele**, não inventes.

## 2. Convenções de arquitectura

- **Controllers finos**: leitura num `Service`, escrita numa `Action`. Nada de query ou regra de negócio inline no controller.
- Em PHP: `use` no topo e nome curto; nunca FQN inline.
- **Dinheiro em unidades menores** (`bigInteger`), via `Dinheiro` + `DinheiroCast`; moeda por tenant (`Moeda`, `configuracoes_monetarias`), câmbio `TaxaCambio` ×1.000.000. Nada de moeda "Kz" hard-coded.
- Estado: coluna inteira `estado` + coluna irmã legível `estado_descricao` (hook `saving`).
- **Datas de negócio**: sempre `RelogioDoTenant::hoje()` / `agora()` (módulo Estabelecimento; fuso da escola, por omissão `Africa/Luanda`). **Proibido `now()`/`today()`** em `Modules/Financeiro/app` (há teste de arquitectura que varre).
- **Tenancy**: models com `PertenceAoTenant`; `tenant_id` NOT NULL com FK; hooks `saving` null-safe (a `MatrizIsolamentoTest` cria models em cru). Tabela global nova → `config/tenancy.php` `tabelas_globais`.
- **Independência de módulos**: o módulo `Matricula` **nunca importa** `Financeiro`. Comunicação por contratos/etiquetas (`DependenciasDaMatricula`, `DependenciasDoAnoLectivo`, `ReferenciasFinanceiras`, `FontesDePrecos`, `FonteDePagamentoDePropina`, `HistoricoDePagamentosDaPropina`) e pelo evento `MatriculaEstadoAlterado`.
- **Menu**: editar **dois** sítios (`SidebarMenuWrapper.vue` e `useXxxMenu.js`; descomentar os placeholders existentes, não inventar).
- Permissões: enum `Modulo` × tabela `Acao`; `Modulo::PROPINA=22`, `Modulo::PAGAMENTO=23`; cache de permissões invalida-se no sync.
- Frontend: reutilizar o padrão visual existente (modais `d-block`, `bg-body-secondary` para cartões internos, `AcaoIcone`); modais de referência: `GerarPropinasModal.vue`, `CancelarPropinaModal.vue`, `AlterarPrecoPlanoModal.vue`. Compilar com `npm run build`.

### Escritores únicos (garantidos por testes de arquitectura — não contornar)

| Dado | Único escritor |
|---|---|
| `propinas.valor_pago`, `estado`, `capital_liquidado_em` | `RecalcularPropina` |
| `propinas.valor` (propina existente) | `RecalcularPropina::reajustarValor` |
| `propinas.plano_propina_id`, `ordem` | `ReassociacaoDePropinas` |
| estado Cancelada de uma propina | `CancelamentoDePropinas` |
| `creditos.valor_utilizado`, estado | `RecalcularCredito` |
| estado Anulada de uma propina e campos de anulação (`motivo_anulacao`, `anulado_por`, `anulado_em`) | `AnulacaoDePropinas` (propinas); `AnularPropinaAction` é a única que a chama; `AnularPagamentoAction` e `AnularUtilizacaoCreditoAction` escrevem os seus próprios registos (`AnuladoresDePropinaTest`) |
| regra de "pagamento anulável" (só leitura) | `AnulacaoDePagamentos::motivoParaNaoAnular` (usada por `AnularPagamentoAction` e `PagamentoConsultaService`; o serviço de leitura já não depende de Actions) |
| `alteracoes_preco_plano`, `propina_ajustes`, pagamentos confirmados | só criação (imutáveis; trigger pgsql) |

### Ordem global de bloqueios (evita deadlocks)

`matrícula → (pagamento) → plano → propinas (por id crescente) → créditos → sequência`. A geração, o cancelamento, a alteração de preço e os pagamentos seguem esta ordem. Os pagamentos nunca bloqueiam planos.

## 3. Como verificar (comandos)

```bash
php artisan test                                  # suite completa (SQLite em memória) ~4 min
php artisan test Modules/Financeiro               # só o módulo
php artisan test tests/Feature/Arquitectura       # regras de arquitectura
npm run build                                     # frontend
# PostgreSQL (instância descartável, ver regra 3), NÃO via artisan:
DB_CONNECTION=pgsql DB_DATABASE=mositec_escola_test DB_PORT=55432 vendor/bin/phpunit --group=pgsql
```

- Os testes `pgsql` ficam ignorados em SQLite e só correm com `vendor/bin/phpunit` contra a base `mositec_escola_test` (guarda em `tests/PgsqlTestCase.php`: rejeita `DB_URL`, host não local e verifica `current_database()`).
- A concorrência real (`FOR UPDATE`, deadlocks, CHECKs e triggers) só se prova em PostgreSQL.
- Em testes com `RefreshDatabase` a transacção já está aberta: testes de "exige transacção" precisam de `DB::rollBack()` antes (ver `RecalcularPropinaTest`).
- Linhas de teste novas: escrever o teste, **vê-lo falhar (RED)**, só depois implementar (GREEN). Não juntar teste e código.

## 4. Estado do trabalho

Feito e validado (suite verde, revisões aprovadas; código em commit `3296817` ou em stage):

| Fase | Conteúdo |
|---|---|
| Config. Financeiro, Moeda e Câmbio, Planos de Propina | planos 1–3, multas só configuração |
| Propinas F1 | tabela `propinas`, `RecalcularPropina`, estados |
| F2 | geração de propinas (manual + comando diário), lista |
| F3 | detalhe, cancelar, evento da matrícula, reconciliação diária, chave de segurança **removida** (autorizado) |
| F4 | alteração de preço de plano com propinas (§14), modal 2 passos, `propina_ajustes` |

**Em curso — Pagamentos-A** (plano `docs/superpowers/plans/2026-10-17-financeiro-pagamentos-a.md`, 20 tarefas, já **pré-voado** com os erros corrigidos no plano e emendado com as decisões do dono):

| Grupo | Tarefas | Estado |
|---|---|---|
| A — Permissões e dados | 1–3 | feito, revisto, aprovado (+ correcções) |
| B — Ligação às propinas | 4–5 | feito, revisto, aprovado (+ correcções) |
| C — Motor de distribuição (leitura) | 6 | feito, revisto (3 importantes de testes corrigidos) |
| D — Escrita transaccional | 7–11 | feito (T7–11); revisão do grupo D feita (sem Críticos; Importantes corrigidos: revalidação do estado sob lock, violação da referência em corrida, tenancy da matrícula) |
| E — Leitura e HTTP | 12–14 | feito, revisto; menores corrigidos na onda final |
| F — Frontend | 15–17 | feito; menores corrigidos na onda final (erros `distribuicao.N` mapeados por `propina_id`) |
| G — PostgreSQL | 18–19 | a decorrer (testes em `Modules/Financeiro/tests/Feature/Pgsql/`) |
| H — Verificação final | 20 | a fazer |

Dependências: 7 depende de 4, 6; 8 de 7; 9 de 7 e 8; 10 de 4; 11 de 7–10; 12 de 3; 13 de 7–9 e 12; 14 de 10 e 12; 15–17 de 13 e 14; 18 de 3 e 7; 19 de 7–10; 20 no fim.

Ledger e relatórios locais (ignorados pelo git): `.superpowers/sdd/2026-10-17-financeiro-pagamentos-a/` (`progress.md`, `task-*-report.md`, `review-*.diff`). Se a pasta não existir na tua máquina, o plano e este documento chegam.

### Decisões do dono já tomadas para Pagamentos-A

O1 referência única só entre pagamentos Confirmados (+ aviso se foi usada num anulado); O2 numeração `AAAA-NNNN` por escola com o ano do dia de registo; **O3 `data_pagamento` nunca futura nem anterior à `data_matricula`**; O4 pagamento sem dívida → tudo para crédito com confirmação explícita; O5 distribuição automática pára na primeira propina que não cabe (sem salto); O6 antecipado compara início do período com a data do pagamento; O7 utilização de crédito com a data em que é aplicada; **O8 `credito_utilizacoes.pagamento_id` nullable e imutável**, utilizações do mesmo acto ficam Activas ao anular o pagamento; O9 (V1) o ADMIN_ESCOLA regista e anula; funcionário sem permissões.

## 5. Como continuar (processo recomendado)

1. Ler o plano da fase e a secção "Global Constraints / Decisões / Review Focus / Riscos". Executar uma tarefa de cada vez, por ordem de dependência.
2. Por tarefa: RED → GREEN → correr o módulo + arquitectura. No fim de cada **grupo**: suite completa, e uma **revisão crítica independente** do diff do grupo (outra pessoa/modelo, só leitura): invariantes, concorrência, tenancy, testes falsificáveis. Corrigir os pontos Críticos/Importantes antes do grupo seguinte; os Menores acumulam para uma onda final.
3. Guardar uma referência do estado antes de cada grupo para gerar o diff: `git write-tree > .superpowers/sdd/<pasta>/tree-base-X`, e depois `git diff-tree -p $(cat tree-base-X) $(git write-tree)`.
4. No fim do plano: suite completa, `npm run build`, procurar `now()`/`today()` em `Modules/Financeiro/app`, `git status` sem alterações fora do stage, porta 55432 livre, **revisão final**, e só então dar a fase por fechada. Parar e deixar ao dono: `php artisan migrate`, `db:seed --force`, `php artisan financeiro:sincronizar --todos`, correr `--group=pgsql` no servidor dele, verificação visual, commit.
5. Depois de Pagamentos-A **validado**: F5 (aplicação das multas; spec propinas §15) e Pagamentos-B (`pagamento_multas`, imputação capital→multa, `RecalcularMulta`) — precisam de planos detalhados (usar a skill `superpowers:writing-plans` se existir, ou seguir o formato de `docs/superpowers/plans/2026-10-16-financeiro-propinas-f4.md`) e pré-voo antes de executar.

## 6. Decisões em aberto (só o dono decide — não inventar)

- **O10** (Pagamentos-A): limite de "N dias de atraso" para registar pagamentos retroactivos, com permissão própria. Decidir **antes de F5**.
- **F4**: confirmar (a) anos lectivos **encerrados bloqueados** na alteração de preço (assunção nossa; o plano permitia), (b) modo "no próprio plano" quando `mes_efeito` = início do plano (corrigir o texto do §14 da spec propinas), (c) coluna `ano_lectivo_id` em `alteracoes_preco_plano`, (d) nome do plano novo `"{nome} (desde MM/AAAA)"`.
- **O9** ressalva: segregação de funções (perfil de tesouraria sem "anular"; quem anula ≠ quem registou).
- Adiados: trancamento de matrícula (4 perguntas por responder em `docs/superpowers/plans/2026-10-12-trancamento-matricula-plano.md`), limite de negociação por perfil, conversão de moedas (precisa de `bcmath` declarado no composer).
- Cópia de planos de ano lectivo copia os dois planos resultantes de uma divisão (UX a ter em conta).

## 7. Armadilhas conhecidas

- `MatrizIsolamentoTest` cria models em cru: hooks `saving` têm de ser null-safe; módulo novo precisa de entrada em `TESTE_DE_TENANCY_POR_MODULO`.
- Comando de plataforma novo → `COMANDOS_DA_PLATAFORMA` em `TenancyArquitecturaTest`.
- Regex em testes de arquitectura: usar o modificador `D`.
- `sed` às cegas perde acentos PT-PT; preferir edição por ferramenta.
- Em pgsql, `insert` com `'id' => null` dá erro 23502; omitir o `id`.
- Um UPDATE pode violar dois CHECKs; o PostgreSQL nomeia o primeiro que avalia — asserções de testes devem aceitar ambos (23514).
- Uma regra de arquitectura nova deve ter exemplos positivos e negativos (DataProvider) e um teste de sanidade que prove que falha com código violador.
- Módulo só carrega se estiver em `modules_statuses.json`.
- `.superpowers/` está no `.gitignore`: um `git add` que o inclua falha por inteiro.
- Agentes automáticos bateram várias vezes em limites de sessão: escrever progresso em disco por tarefa para a retoma poder continuar.

## 8. Documentos de referência

- Specs: `docs/superpowers/specs/2026-10-08-modulo-financeiro-configuracao-design.md` (fonte), `...-financeiro-propinas-design.md` (§3, §6, §14 alteração de preço, §15 multas), `...-financeiro-pagamentos-design.md`.
- Plano de fases: `docs/superpowers/plans/2026-10-12-financeiro-propinas-fases.md` (decisões do dono em §8).
- Planos executados: F1 `2026-10-13-...-f1.md`, F2 `...-f2.md`, F3 `2026-10-15-...-f3.md`, F4 `2026-10-16-...-f4.md`, Pagamentos-A `2026-10-17-financeiro-pagamentos-a.md`.
- Credenciais de login local para testes visuais: ver a memória do projecto do dono (não ficam neste ficheiro).

---

## 9. Delegação a outro modelo

Ver `docs/superpowers/DELEGACAO-DEEPSEEK.md`: papéis, protocolo de entrega para revisão, tarefas delegáveis agora (Pagamentos-A grupo G e verificação final) e prompt de arranque. Nota: há uma instância PostgreSQL descartável ainda a correr na porta 55432 (ver aquele documento) que tem de ser parada e apagada no fim do grupo G.
