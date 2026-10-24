# Delegação ao DeepSeek (VS Code) — protocolo de equipa

Contexto: o Claude (mestre/revisor) tem a quota de sessão quase esgotada esta semana e só restaura na **terça-feira às 20h**. Até lá o **DeepSeek implementa** as tarefas delegadas e o Claude **revê** com poucos tokens. Este documento define quem faz o quê e como se entrega trabalho para revisão.

Leitura obrigatória antes de qualquer tarefa: `docs/superpowers/HANDOFF-financeiro.md` (regras do dono, convenções, comandos, armadilhas) e o plano da fase em curso.

## 1. Papéis

| Papel | Quem | Faz | Não faz |
|---|---|---|---|
| Implementador | DeepSeek | executa tarefas de um plano já escrito, uma de cada vez, em TDD; corre testes; escreve relatório de entrega | decide política de negócio; muda desenho/plano sem registar; faz commit; toca em `mositec_escola` |
| Mestre/revisor | Claude | desenha e escreve planos de fases críticas (dinheiro), decide Críticos/Importantes, faz a revisão final de cada grupo, fecha a fase | implementar em volume (poupa tokens) |
| Dono | utilizador | decisões de política, `migrate`/`seed`/`sincronizar`, `--group=pgsql` no servidor dele, verificação visual, **commits** | — |

## 2. O que se delega (bom para o DeepSeek) e o que fica com o Claude

Delegar:
- Implementar tarefas de um plano **já escrito e pré-voado** (o código quase todo está no plano).
- Testes (incluindo testes `pgsql` na instância descartável), correcções de menores listados numa revisão, frontend segundo o plano, actualização de documentação.
- Corridas de verificação (suite, build, greps) e relatórios.

Fica com o Claude (não delegar; esperar pela terça às 20h ou por revisão):
- Escrever planos novos de fases com dinheiro (F5 multas, Pagamentos-B) e o pré-voo desses planos.
- Decidir se um achado é Crítico/Importante e se uma fase pode ser dada por fechada.
- Qualquer mudança de **política** (O10, confirmações F4, segregação de funções): é do dono.

## 3. Protocolo de entrega (para o Claude gastar poucos tokens)

Para cada **grupo de tarefas** concluído, o DeepSeek cria o ficheiro `.superpowers/entregas/AAAA-MM-DD-<fase>-<grupo>.md` (pasta ignorada pelo git; cria-a) com, **exactamente**:

1. Tarefas feitas (números do plano) e o que ficou por fazer.
2. `git status --short` (nada fora do stage; só `git add`, nunca commit).
3. Saída resumida dos testes: `php artisan test` (últimas linhas: nº de testes/falhas), `php artisan test Modules/Financeiro tests/Feature/Arquitectura`, `npm run build` (se tocou em JS), e (se aplicável) o grupo pgsql com a ordem usada.
4. Para **cada teste novo importante**: a mutação que fez para o provar (o que alterou no código, que teste falhou, e que repôs). Um teste que nunca falhou não conta.
5. Desvios ao plano (cada um com a razão) e dúvidas.
6. O diff do grupo: `git diff-tree -p $(cat .superpowers/sdd/<pasta>/tree-base-X) $(git write-tree) > .superpowers/entregas/<nome>.diff` (guardar `git write-tree > tree-base-X` **antes** de começar o grupo).

O Claude revê lendo só: o relatório, `git status`, as últimas linhas dos testes e o diff. Responde com Aprovado ou lista Críticos/Importantes/Menores (ficheiro:linha). O DeepSeek corrige Críticos/Importantes **antes** do grupo seguinte e acumula os Menores para uma onda final.

Regra de ouro: **não avançar de grupo sem revisão aprovada** quando a revisão for possível; se o Claude estiver indisponível, o DeepSeek pára no fim de cada grupo e deixa a entrega pronta (não encadear vários grupos sem revisão em fases com dinheiro).

## 4. Trabalho delegável agora (por ordem)

### A. Terminar Pagamentos-A, Grupo G — Tarefas 18–19 (PostgreSQL)

Estado: o agente anterior foi cortado por limite de sessão a meio.
- Ficheiros **já criados mas NÃO em stage**: `Modules/Financeiro/tests/Feature/Pgsql/PagamentoPgsqlTest.php`, `PagamentoConcorrenciaPgsqlTest.php` e uma alteração em `ComProcessosConcorrentes.php`. Rever o que lá está, completar conforme as Tasks 18–19 do plano (`docs/superpowers/plans/2026-10-17-financeiro-pagamentos-a.md`) e `git add`.
- **Há uma instância PostgreSQL 16 descartável ainda a correr** em `127.0.0.1:55432` (datadir em `/tmp/claude-1000/-home-eluckimossi-code-mositec-escola/c6755bc4-4fa5-45e9-befc-7d3e987e3449/scratchpad/pgdata`, sockets em `/tmp/pgs55432`). Podes reutilizá-la (base `mositec_escola_test`; cria o papel do `.env` se faltar). **No fim: pará-la e apagá-la** (`pg_ctl -D <datadir> stop`; apagar datadir e `/tmp/pgs55432`) e confirmar `ss -ltn | grep 55432` vazio. Se a instância tiver desaparecido (reboot), cria outra em `/tmp`.
- Comando: `DB_CONNECTION=pgsql DB_DATABASE=mositec_escola_test DB_PORT=55432 vendor/bin/phpunit --group=pgsql` (**não** `artisan test`); correr o grupo **completo** (inclui F2–F4) em ordem normal, `--order-by=reverse` e `--order-by=random`; **uma execução de cada vez** (duas em simultâneo na mesma base geram deadlocks falsos).
- Concorrência a provar (processos filhos, helper `ComProcessosConcorrentes`): duplo pagamento da mesma propina; parciais simultâneos; pagamento × alteração de preço nos dois sentidos; pagamento × cancelamento manual; pagamento × anulação de propina; numeração sem lacunas; referência duplicada → 422; anular pagamento × usar o seu crédito; stress sem deadlocks (contador do servidor). Se um teste revelar um defeito **real** do código de app, **não ajustes o teste**: documenta o cenário e pára; é revisão do Claude.
- Entrega: `.superpowers/entregas/…-pagamentos-a-G.md`.

### B. Verificação final do Pagamentos-A — Tarefa 20

Seguir a Task 20 do plano (suite completa, `npm run build`, procuras de código proibido, `git status`, porta 55432 livre) e escrever o resumo ao dono. Depois disto o **Claude faz a revisão final** da fase (incluindo o frontend, que ainda não teve revisão independente) e actualiza memória/HANDOFF.

### A2. Onda final do Pagamentos-A (revisão final do Claude, 2026-10-15) — FAZER AGORA

Veredicto do revisor (`.superpowers/sdd/2026-10-17-financeiro-pagamentos-a/review-final-result.md`, lê-o inteiro): **sem Críticos; 3 Importantes; menores**. Locks e invariantes de dinheiro estão correctos. Corrige, em TDD, só `git add`:

**I1 — testes de concorrência do Grupo G que passam mesmo sem locks** (`Modules/Financeiro/tests/Feature/Pgsql/PagamentoConcorrenciaPgsqlTest.php`): parciais simultâneos (~l.135), pagamento × cancelamento (~l.218), pagamento × anulação de propina (~l.284), anular pagamento × usar crédito (~l.269) e stress (~l.305). O revisor removeu todos os `lockForUpdate()` de `Support/BloqueioDePagamentos.php` (l.24,31,44) e `Support/BloqueioDePropinas.php` (l.34) e continuaram verdes: o perdedor sai com uma `LogicException` interna do RecalcularPropina/RecalcularCredito (500 para o utilizador) em vez de um 422. Corrige os testes para afirmarem o resultado correcto: o perdedor tem de falhar com `tipo === 'validacao'` e o campo esperado (422), **nunca** com `LogicException`/`QueryException`; no stress rejeita `LogicException`. **Depois prova cada um por mutação** (remove os `lockForUpdate()`, confirma que o teste falha, repõe) e regista isso na entrega. Se, com o teste correcto, o código de app falhar mesmo com locks (perdedor a dar 500), isso é defeito real: **pára e documenta**, não ajustes o teste.

**I2 — falta o caso que só o lock do crédito protege**: aplicar o crédito de um aluno **noutra matrícula do mesmo aluno** enquanto se anula o pagamento que o gerou (D12). Acrescenta o teste pgsql com duas matrículas do mesmo aluno, prova por mutação (remove o lock do crédito em `DistribuicaoDePagamentoService.php:153-154` / `AnularPagamentoAction.php:47-51`) e completa `assertCoerenteNaOutraLigacao` (~l.103-113) com a coerência dos créditos (`valor_utilizado == Σ utilizações Activas`).

**I3 — `Modules/Financeiro/resources/js/Pages/Pagamentos/Create.vue` (~l.156-161)**: um 422 ao confirmar manda sempre o utilizador para o passo 3. Os erros de `referencia` (corrida do índice único), `data_pagamento` e método só se mostram no passo 2, por isso ficam só no toast; em modo automático os erros `distribuicao.N.*` ficam invisíveis (a coluna por propina não é desenhada; o filtro ~l.61-64 exclui essas linhas); e a `situacao` não é recarregada depois do 422 (saldos obsoletos). Corrige: ao receber 422 leva ao passo onde está o primeiro campo com erro; mostra erros de `distribuicao.N` também em modo automático (aviso junto do resumo); recarrega a situação. Teste Vue no estilo de `Modules/Financeiro/tests/Unit/PagamentosVueTest.php` (que só lê texto: complementa descrevendo o comportamento por asserções sobre o código).

**Menores** (faz os baratos): M2 mostrar também o flash `success` do registo (`Show.vue` ~l.243); M3 `data_matricula` futura → mensagem explicativa em vez de mínimo > máximo (R-PA.9); M5 a pesquisa de matrículas ignora respostas fora de ordem e `escolher` trata erros (`Create.vue` ~l.68-82); M1 (`futura` deve comparar com a data do pagamento, O6) só se for simples. Não faças M4 nem M6 (ficam registados).

Entrega: `.superpowers/entregas/2026-10-15-pagamentos-a-onda-final.md` (formato do §3). No fim: suite completa, `npm run build`, grupo pgsql completo (instância descartável 55432, uma execução de cada vez, apagar no fim), `git status`, porta livre. Depois **pára** para revisão do Claude.

### C. Não iniciar sem o Claude

F5 (multas) e Pagamentos-B precisam de plano detalhado e pré-voo. Depende também das decisões do dono (O10). Não começar.

## 5. Prompt para colar no DeepSeek (início de cada sessão)

> Vais continuar um projecto Laravel 12 + Inertia/Vue (módulo `Modules/Financeiro`, ramo `feat/modulo-financeiro`). Lê primeiro, por esta ordem: `docs/superpowers/HANDOFF-financeiro.md`, `docs/superpowers/DELEGACAO-DEEPSEEK.md` e o plano indicado. Fala **português (PT-PT)**. Regras invioláveis: só `git add` (nunca commit, nunca `git reset/checkout` de ficheiros que não sejam teus, nunca Pint); nunca toques na base `mositec_escola` nem corras `migrate/seed/sincronizar` contra ela; PostgreSQL só na instância descartável da porta 55432; nenhum `now()`/`today()` em `Modules/Financeiro/app` (usa `RelogioDoTenant`); dinheiro em unidades menores; os escritores únicos e a ordem de locks do HANDOFF não se contornam. Trabalha em TDD: escreve o teste, **vê-o falhar (RED)**, só depois implementa; depois prova cada teste crítico por mutação e repõe. Uma tarefa de cada vez, na ordem do plano. No fim de cada grupo escreve a entrega descrita em §3 do DELEGACAO-DEEPSEEK.md e **pára** para revisão. Não decidas política de negócio: se faltar uma decisão, regista-a em "dúvidas" na entrega e segue pela opção por omissão do plano, marcada como assunção. Termina sempre a resposta com as tabelas envolvidas e as suas relações.

## 6. Quando o Claude voltar (terça, 20h)

Retoma como mestre: revê as entregas pendentes, faz a revisão final do Pagamentos-A, e escreve/pré-voa os planos da F5 e dos Pagamentos-B. Até lá, tudo o que o DeepSeek fizer deve ficar **em stage, com entrega escrita**, para o Claude poder rever em poucos passos.
