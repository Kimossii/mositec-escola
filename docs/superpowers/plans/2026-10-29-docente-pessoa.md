# Docente como pessoa — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Docente como entidade (Pessoal → Docentes) com conta opcional; atribuições passam a apontar para o docente.

**Architecture:** Entidade `Docente` no módulo `Docente`, espelhando o módulo Aluno (`dados_pessoa_id` único; a conta é o `User` com a mesma pessoa). Migração nova troca `professor_id` por `docente_id`. O contrato `VerificaAtribuicaoDocente` mantém assinaturas e resolve utilizador → pessoa → docente.

**Tech Stack:** Laravel 12, PHP 8.3, Vue 3 + Inertia, PostgreSQL 16 / SQLite (testes).

**Spec:** `docs/superpowers/specs/2026-10-29-docente-pessoa-design.md` (altera `2026-10-28-atribuicao-docente-design.md`, que continua válido no resto)

## Global Constraints

- Mesmas do plano `2026-10-28-atribuicao-docente.md` (Global Constraints): português de Portugal; Actions com `LogService`/`comLog`; `use` no topo; controllers finos; tenant em tudo; `*_descricao` sincronizada; nada de `DB::raw`/`insert`/`withoutEvents` em código de aplicação (migrações excluídas); padrões visuais existentes.
- **Não fazer commit** (regra do dono): só `git add`. Nunca tocar na BD de desenvolvimento nem correr migrate contra ela (o dono corre as migrações); testes usam `RefreshDatabase`/`mositec_escola_test`.
- A migração `2026_10_28_100000_create_atribuicoes_docentes_table` e as 4 de unique JÁ correram em desenvolvimento: não as editar.
- Disponíveis do trabalho anterior: módulo `Docente` completo (atribuições, contrato, página `Docente/Atribuicoes/Index`), `Modulo::ATRIBUICAO_DOCENTE = 28`.

## Review Focus

1. Docente sem conta é atribuível; docente inactivo ou de outro tenant rejeita-se. Task 2.
2. Contrato: utilizador sem docente ligado devolve `false`/lista vazia; resolução por `dados_pessoa_id` não cruza tenants. Task 2.
3. Migração `professor_id` → `docente_id` preserva atribuições existentes (preenche docentes) e a constraint de exclusão do mesmo professor continua a funcionar em PostgreSQL. Task 2.
4. Criar acesso: docente que já tem conta não ganha segunda; o servidor ignora nome/email do cliente; sem `usuario.criar` falha. Task 4.
5. Eliminar docente com atribuições (inclusive terminadas) é bloqueado com mensagem, nunca 500. Task 3.

---

### Task 1: Entidade Docente, permissões

**Files:**
- Create: `Modules/Docente/database/migrations/2026_10_29_100000_create_docentes_table.php`, `Modules/Docente/app/Models/Docente.php`, `Modules/Docente/tests/Feature/DocenteModelTest.php`, `Modules/Docente/tests/Feature/DocentePermissoesTest.php`
- Modify: `Modules/Permissao/app/Enums/Modulo.php`, `Modules/Permissao/database/seeders/ModuloSeeder.php`, `Modules/Permissao/app/Actions/SincronizarPerfisDeSistemaAction.php`, `Modules/Permissao/resources/js/Support/menusDosModulos.js`, testes de contagem de módulos (`Modules/Permissao/tests/Feature/{ModuloSeederTest,AcoesAplicaveisTest}.php`)
- Modelo a seguir: `Modules/Aluno/app/Models/Aluno.php` e `.../create_alunos_table.php`.

**Interfaces:**
- Produces: tabela `docentes` (`tenant_id`, `estabelecimento_id`, `dados_pessoa_id` único, `estado`, `estado_descricao`, autoria, timestamps, FKs compostas `(tenant_id, …)`, `unique(tenant_id, id)`); `Modules\Docente\Models\Docente` com traits `PertenceAoTenant, RegistaAutoria, SincronizaEstadoDescricao`, relações `dadosPessoa()`, `estabelecimento()`, `atribuicoes()` e método `temAcesso(): bool` (existe `User` com o mesmo `dados_pessoa_id`) e `utilizador(): ?User`.
- Produces: `Modulo::DOCENTE = 29`, slug `docente`, label `Docente`, acções base (`ver, listar, criar, editar, eliminar, exportar`); admin todas, funcionário `ver, listar, criar, editar`; professor/aluno/encarregado nenhuma; `menusDosModulos.js`: `docente: { menu: 'Académico', entrada: 'Docentes' }`.

- [ ] **Step 1: Write failing tests**: `test_docente_pertence_ao_tenant`, `test_dados_pessoa_unica_por_docente`, `test_tem_acesso_quando_existe_user_com_a_mesma_pessoa` (e `utilizador()`), `test_estado_descricao_sincroniza`; permissões por perfil (admin as 6 acções, funcionário exactamente 4, outros nenhuma). Sem acesso se o user for de outro tenant.
- [ ] **Step 2: Run** `php artisan test Modules/Docente/tests/Feature/DocenteModelTest.php Modules/Docente/tests/Feature/DocentePermissoesTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** migração, model, enum/seeders/grants/menu e ajustar as contagens dos testes existentes (módulos 29→30) só pelo que a mudança exige.
- [ ] **Step 4: Run** os dois ficheiros, `php artisan test Modules/Permissao tests/Feature/Arquitectura` — Expected: PASS.
- [ ] **Step 5: Stage.**

---

### Task 2: Atribuição passa a `docente_id`

**Files:**
- Create: `Modules/Docente/database/migrations/2026_10_29_110000_troca_professor_por_docente_em_atribuicoes.php`
- Modify: `AtribuicaoDocente.php` (relação `docente()`, remove `professor()`/`professor_id`), `AtribuicaoDocenteDTO.php`, `SubstituicaoTitularDTO.php`, `ValidadorAtribuicaoDocenteService.php`, as 5 Actions, `SobreposicaoDeAtribuicao.php`, `VerificaAtribuicaoDocenteService.php`, `AtribuicaoDocenteConsultaService.php`, `ExportacaoDeAtribuicoesService.php`, os 4 Requests, `AtribuicaoDocenteController.php`, `Pages/Atribuicoes/Index.vue` e componentes que usam `professor_id`/`professores`, `CenarioDeDocencia.php`, e todos os testes do módulo que usam `professor_id`/`criarProfessor`.
- Remove (código morto): `Modules/Core/app/Contracts/DependenciasDoUtilizador.php`, `Modules/Core/app/Support/DependenciasRegistadasDoUtilizador.php`, `Modules/Docente/app/Support/DependenciasDoUtilizadorDocente.php`, o registo no `DocenteServiceProvider`, o gancho em `Modules/Usuario/app/Actions/EliminarUsuarioAction.php`, `EliminarProfessorComAtribuicoesTest.php` (substituído pelo teste de Eliminar docente na Task 3).

**Interfaces:**
- Consumes: `Docente` (Task 1).
- Produces: `atribuicoes_docentes.docente_id` (FK composta `(tenant_id, docente_id)` → `docentes (tenant_id, id)`); DTOs com `docente_id` (substitui `professor_id` / `novo_professor_id` → `novo_docente_id`); props `opcoes.docentes` `{id, nome, tem_acesso}` (substitui `opcoes.professores`); rotas e permissões inalteradas.
- Produces: `VerificaAtribuicaoDocente::podeGerir(int $professorId, …)` e `atribuicoesEmVigorDoProfessor(int $professorId, …)` **mantêm assinaturas**; `$professorId` continua a ser o id do UTILIZADOR; o serviço resolve `users.dados_pessoa_id` → `docentes.dados_pessoa_id` no tenant; sem docente → `false` / `[]`.
- Produces: `CenarioDeDocencia::criarDocente(string $nome = 'Docente Teste', bool $comAcesso = false): Docente`.

- [ ] **Step 1: Write failing tests** (ajustar os existentes e acrescentar): `test_atribui_docente_sem_conta`, `test_rejeita_docente_inactivo`, `test_rejeita_docente_de_outro_tenant`, `test_contrato_resolve_utilizador_para_docente`, `test_contrato_utilizador_sem_docente_devolve_false_e_lista_vazia`, `test_contrato_nao_cruza_tenants`; pgsql: `test_pg_rejeita_mesmo_docente_sobreposto` e `test_pg_fk_composta_rejeita_docente_de_outro_tenant`; migração: `test_migracao_preenche_docentes_para_professores_ja_atribuidos` (cria atribuição com o esquema antigo, corre a migração, confirma `docente_id` e que `dados_pessoa` é a do utilizador; utilizador sem `dados_pessoa_id` ganha `DadosPessoa` criada a partir de `users.name`/`email`).
- [ ] **Step 2: Run** `php artisan test Modules/Docente` — Expected: FAIL.
- [ ] **Step 3: Implement** a migração em duas fases dentro da mesma migração (query builder; sem Eloquent): (1) por cada `professor_id` distinto, garantir `docentes` (reutilizando `docentes.dados_pessoa_id` se já existir; criar `dados_pessoas` quando o utilizador não tem) e preencher `docente_id`; (2) trocar a coluna e as FKs; em PostgreSQL recriar a constraint de exclusão do mesmo professor com `docente_id` e manter a do titular. `down()` reverte na medida do possível (restaura `professor_id` a partir do utilizador ligado à pessoa do docente, falha explícita se algum docente não tiver conta). Depois o código da lista Modify/Remove.
- [ ] **Step 4: Run** `php artisan test Modules/Docente Modules/Usuario tests/Feature/Arquitectura tests/Feature/RotasEscritaAutorizadasTest.php tests/Feature/RotasPermissaoReconhecidaTest.php`, e `DB_CONNECTION=pgsql DB_DATABASE=mositec_escola_test vendor/bin/phpunit --group=pgsql Modules/Docente/tests/Feature/Pgsql` — Expected: PASS; `npm run build` sem erros.
- [ ] **Step 5: Stage.**

---

### Task 3: Cadastro de docentes (backend) e sincronização

**Files:**
- Create: `Modules/Docente/app/DTO/DocenteDTO.php`; Actions `CriarDocenteAction`, `AtualizarDocenteAction`, `AlterarEstadoDocenteAction`, `EliminarDocenteAction`, `GarantirDocenteDeUtilizadorAction`; Services `DocenteConsultaService`, `GestaoDocenteService`, `ExportacaoDeDocentesService`; `DocenteController`; Requests `CriarDocenteRequest`, `AtualizarDocenteRequest`, `AlterarEstadoDocenteRequest`; Listener `GarantirDocenteAoCriarProfessor` (registado em `EventServiceProvider` ou como o projecto liga eventos de utilizador); comando `Modules/Docente/app/Console/SincronizarDocentesCommand.php` (`docentes:sincronizar`); testes por componente.
- Modify: `Modules/Docente/routes/web.php`.
- Modelos a seguir: `Modules/Aluno` (Actions/Services/Controller de CRUD), `Modules/Curso/app/Http/Controllers/CursoController.php`.

**Interfaces:**
- Consumes: `Docente`, `Modulo::DOCENTE`.
- Produces: `CriarDocenteAction::executar(DocenteDTO): Docente` (cria `DadosPessoa` com `tipo_pessoa = TIPO_PROFESSOR` + `Docente`; `estabelecimento_id` do contexto; sem conta); `AtualizarDocenteAction::executar(Docente, DocenteDTO): Docente`; `AlterarEstadoDocenteAction::executar(Docente, int $estado): Docente`; `EliminarDocenteAction::executar(Docente): void` (bloqueado se existir qualquer atribuição, incluindo terminadas ou eliminadas, com `ValidationException` em português chave `docente`; senão apaga o docente e, se a `DadosPessoa` ficar sem ninguém a usá-la, também); `GarantirDocenteDeUtilizadorAction::executar(User): Docente` idempotente (por `dados_pessoa_id`; cria a pessoa se o utilizador não tiver).
- Produces rotas (prefixo `/docentes`, nomes `docentes.*`): `GET /` index (`docente.ver`), `POST /` store (`docente.criar`), `PUT /{docente}` update (`docente.editar`), `PATCH /{docente}/estado` (`docente.editar`), `DELETE /{docente}` (`docente.eliminar`), `GET /exportar` (`docente.exportar`, declarada antes das rotas com parâmetro). **Atenção:** a rota existente `/docentes/atribuicoes…` não pode colidir com `/docentes/{docente}`: declarar as rotas de atribuições antes e restringir `{docente}` a numérico.
- Produces props do `index` (página `Docente/Docentes/Index`): `docentes` (paginador com `id, nome, bi, telefone, email, estado, tem_acesso`), `indicadores` (diferido: `total, activos, com_acesso, sem_acesso`), `filtros` (`pesquisa, estado, acesso`).

- [ ] **Step 1: Write failing tests**: criar docente sem email e sem conta; `dados_pessoa` com tipo professor; pessoa duplicada rejeitada; editar; activar/desactivar; eliminar sem atribuições OK; eliminar com atribuição terminada/eliminada bloqueado (sem 500); sincronização: criar utilizador com perfil Professor pelo fluxo existente cria docente (idempotente, uma só vez); `docentes:sincronizar` cria docentes para professores existentes e é idempotente; HTTP: index/store/update/estado/destroy/exportar com Inertia; autorização (funcionário sem eliminar/exportar, professor/aluno/encarregado 403); isolamento de tenant; ids de outro tenant → 404; `LogService` nas Actions (teste de arquitectura existente).
- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement** seguindo os padrões indicados. A listagem sem N+1 (`tem_acesso` por subconsulta/`exists` agregada).
- [ ] **Step 4: Run** `php artisan test Modules/Docente Modules/Usuario tests/Feature/Arquitectura tests/Feature/RotasEscritaAutorizadasTest.php tests/Feature/RotasPermissaoReconhecidaTest.php` — Expected: PASS.
- [ ] **Step 5: Stage.**

---

### Task 4: Criar acesso (backend)

**Files:**
- Create: `Modules/Docente/app/Actions/CriarAcessoDocenteAction.php`, `Modules/Docente/app/Http/Requests/CriarAcessoDocenteRequest.php`, `Modules/Docente/tests/Feature/CriarAcessoDocenteTest.php`
- Modify: `DocenteController.php`, `routes/web.php`; o fluxo de criação de professor em Utilizadores (`CriarUsuarioRequest`/`UsuarioController::store`/Action) para aceitar `docente_id` (ver `CriarUtilizadorAlunoAction` e como o servidor ignora nome/email do cliente para o aluno).
- Modelo a seguir: `Modules/Usuario/app/Actions/CriarUtilizadorAlunoAction.php`, `Modules/Aluno/app/Services/ProcuraAlunoParaContaService.php` e contrato `Core\Contracts\ProcuraAlunoParaConta` (padrão de procura: o módulo `Usuario` não deve importar `Docente`; se precisar, criar o contrato análogo no Core `ProcuraDocenteParaConta` implementado por `Docente`).

**Interfaces:**
- Produces: `POST /docentes/{docente}/acesso` (nome `docentes.acesso`, `docente.editar` **e** `usuario.criar`): body `email`, `password` (+`password_confirmation`), cria `User` (perfil Professor, `tipo_login = email`, `dados_pessoa_id` do docente), nome e dados vêm do docente (cliente ignorado); rejeita docente que já tem conta, docente inactivo, email repetido.
- Produces: no formulário de novo professor de Utilizadores aceitar `docente_id` de um docente sem conta, mesmo comportamento, via o contrato acima; o frontend usa `GET` de procura de docentes sem conta (rota e props a definir pelo implementador seguindo a procura do aluno).

- [ ] **Step 1: Write failing tests**: cria conta com perfil Professor ligada à mesma pessoa; `tem_acesso` passa a verdadeiro e o contrato `podeGerir` passa a responder pelo utilizador; segundo acesso rejeitado; cliente a enviar nome/email alheios é ignorado; sem `usuario.criar` → 403; docente inactivo rejeitado; outro tenant → 404; pelo fluxo de Utilizadores com `docente_id` equivale ao botão.
- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement** reutilizando a Action de criação de utilizador existente, sem duplicar a regra de perfis.
- [ ] **Step 4: Run** `php artisan test Modules/Docente Modules/Usuario tests/Feature/Arquitectura` — Expected: PASS.
- [ ] **Step 5: Stage.**

---

### Task 5: Frontend — Pessoal → Docentes e ajustes

**Files:**
- Create: `Modules/Docente/resources/js/Pages/Docentes/Index.vue`, `Components/Docentes/{DocenteFormModal,CriarAcessoModal,EstadoBadge}.vue` (reutilizar o que existir), `Models/` conforme padrão.
- Modify: `Pages/Atribuicoes/Index.vue` e componentes (selector de docente com `tem_acesso`), `resources/js/Components/Layout/SidebarMenuWrapper.vue` (item «Docentes» → `/docentes`, `docente.ver`, antes de «Atribuições docentes» na secção Pessoal), `resources/js/Composables/useAcademicoMenu.js` (link «Docentes»), formulário de novo professor em `Modules/Usuario/resources/js/Forms/Professores/ProfessorForm.vue` (escolher docente sem conta), teste de menus (`tests/Feature/MenuAtribuicoesDocentesTest.php` ajustado/ampliado).
- Padrões: `Modules/Docente/resources/js/Pages/Atribuicoes/Index.vue` e `Modules/Curso/resources/js/Pages/Index.vue`; cartões internos `bg-body-secondary`; `Loader` só pontual.

**Interfaces:**
- Consumes: props e rotas das Tasks 3 e 4 (`docentes.*`, `docentes.acesso`).
- Produces: lista com pesquisa, filtros (estado, acesso), indicadores diferidos, badge «Com acesso / Sem acesso», criar/editar (nome, BI, telefone, email, sexo, nascimento), activar/desactivar, eliminar (erro do servidor mostrado), exportar, botão «Criar acesso» (só sem conta e com `usuario.criar` + `docente.editar`); selector das atribuições com docentes activos e indicação de acesso; ProfessorForm com modo «a partir de docente».

- [ ] **Step 1: Write failing test** de menus: admin vê «Docentes» e «Atribuições docentes»; professor não vê nenhum.
- [ ] **Step 2: Run** `php artisan test tests/Feature/MenuAtribuicoesDocentesTest.php` — Expected: FAIL.
- [ ] **Step 3: Implement** páginas, componentes e menus.
- [ ] **Step 4: Run** o teste e `npm run build` — Expected: PASS e build sem erros.
- [ ] **Step 5: Verificação visual** só se houver BD descartável viável (`mositec_escola_verif_docente`, criada e apagada pelo implementador, nunca a BD de desenvolvimento); senão declarar que não foi feita.
- [ ] **Step 6: Stage.**

---

### Task 6: Verificação final

- [ ] **Step 1:** revisão do conjunto (isolamento de tenant, migração com preenchimento, contrato, criar acesso, limpeza do código morto, higiene do stage).
- [ ] **Step 2:** resumir ao dono o que correr (migrações novas `2026_10_29_*`, `php artisan db:seed --force`, sincronizar permissões dos perfis, `php artisan docentes:sincronizar`).
