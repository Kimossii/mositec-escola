# Auditoria da escola Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Gravar em `auditoria_tenant` as acções de permissões, justificação de faltas e matrículas, e dar à escola um ecrã `/auditoria` para consultar o rasto.

**Architecture:** Cada Action injecta `RegistarAuditoriaTenantAction` e regista dentro da sua transacção (padrão de `AtualizarRegraCobrancaAction`). A consulta segue o precedente `/acessos-documentos` (módulo de permissão só de leitura, service paginado, controller fino, página Inertia, entrada no menu Sistema).

**Tech Stack:** Laravel + nwidart Modules, Inertia/Vue, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-25-auditoria-escola-design.md`

## Global Constraints

- Controllers finos: leitura em Service, escrita em Action. `use` no topo, nunca FQN inline.
- Toda a Action usa `LogService` (`ActionsComRegistoTest`). Não há migrações novas (`auditoria_tenant` já existe).
- Notas, pautas, presenças e estado da matrícula **não** se tocam (têm histórico próprio).
- Só ids e campos de negócio no `detalhe`; nunca segredos.
- Cancelar/botões e cartões seguem o padrão existente (`bg-body-secondary`, cards < 768px). Sem commit sem pedido: só `git add`.
- Novo módulo de permissão: enum `Modulo` + slug + seed em `SincronizarPerfisDeSistemaAction` + `menusDosModulos.js`.

## Review Focus

- `RegistarAuditoriaTenantAction` só compara as chaves de `depois`: um evento de remoção com `depois = []` **não grava nada**. Remoções devem pôr as chaves a `null` em `depois`.
- Reexecutar uma acção sem diferenças não duplica rasto (já garantido pelo diff; testar nas permissões).
- Utilizador sem `auditoria.ver` recebe 403; o rasto de uma escola não aparece noutra (tenant).
- Falha da Action (rollback) não deixa linha de auditoria.
- `utilizador_id` nulo (utilizador apagado/`nullOnDelete`): a tabela mostra "—", não rebenta.

---

### Task 1: Consulta do rasto (backend + permissão)

**Files:**
- Modify: `Modules/Permissao/app/Enums/Modulo.php` (case `AUDITORIA = 34`, slug `'auditoria'`, `acoesAplicaveis` → `['ver', 'listar']`)
- Modify: `Modules/Permissao/app/Actions/SincronizarPerfisDeSistemaAction.php:42` (mesmo mapa: `Modulo::AUDITORIA->value => ['ver', 'listar']`)
- Modify: `Modules/Core/app/Models/AuditoriaTenant.php` (relação `utilizador(): BelongsTo` para `App\Models\User`, `select id,name` usado no service)
- Create: `Modules/Core/app/Services/AuditoriaTenantConsultaService.php`
- Create: `Modules/Core/app/Http/Requests/FiltrarAuditoriaRequest.php`
- Create: `Modules/Core/app/Http/Controllers/Auditoria/AuditoriaController.php`
- Modify: `Modules/Core/routes/web.php` (`GET /auditoria` → `auditoria.index`, `can:auditoria.ver`)
- Test: `Modules/Core/tests/Feature/AuditoriaConsultaHttpTest.php`

**Interfaces:**
- Produces: `AuditoriaTenantConsultaService::listar(array $filtros, int $porPagina = 25): LengthAwarePaginator` — linhas `{id, quando, utilizador, accao, entidade_tipo, entidade_id, detalhe, ip}`; filtros `accao` (prefixo, `like 'x%'`), `utilizador` (nome contém), `entidade_tipo`, `de`, `ate` (datas).
- Produces: página Inertia `Core/Auditoria/Index` com props `registos` (paginador), `filtros`.

- [ ] **Step 1: Escrever os testes** (copiar o esqueleto de `Modules/Usuario/tests/Feature/AcessosDocumentosHttpTest.php`, `CenarioDeAvaliacao`): `test_admin_ve_lista_paginada` (cria 2 linhas via `RegistarAuditoriaTenantAction`; espera `component('Core/Auditoria/Index')`, `has('registos.data', 2)`, mais recente primeiro); `test_funcionario_e_professor_recebem_403`; `test_filtros_aplicam_se` (`accao=perfil.` , `utilizador=`, `de`/`ate`); `test_utilizador_apagado_mostra_traco` (`utilizador` nulo → `null` no JSON); `test_nao_mostra_registos_de_outra_escola` (padrão de teste multi-tenant já usado nos testes do projecto).
- [ ] **Step 2: Correr** `php artisan test Modules/Core/tests/Feature/AuditoriaConsultaHttpTest.php` — esperado: FAIL (rota inexistente).
- [ ] **Step 3: Implementar** os ficheiros acima, espelhando `GestaoAcessoDocumentoPessoaService`, `FiltrarAcessosDocumentosRequest` (com `filtros()`) e `AcessoDocumentoPessoaController` (`$this->authorize('auditoria.ver')`).
- [ ] **Step 4: Correr** o teste novo + `php artisan test Modules/Permissao` + `tests/Feature/Arquitectura` — esperado: PASS (ajustar contagens de `Modulo` em testes existentes se falharem).
- [ ] **Step 5:** `git add` dos ficheiros.

### Task 2: Ecrã e menu

**Files:**
- Create: `Modules/Core/resources/js/Pages/Auditoria/Index.vue` (modelo: `Modules/Usuario/resources/js/Pages/AcessosDocumentos/Index.vue`)
- Modify: `resources/js/Composables/useSistemaMenu.js:11` e `resources/js/Components/Layout/SidebarMenuWrapper.vue:559` (link `{ href: '/auditoria', label/title: 'Auditoria', permissao: 'auditoria.ver' }`)
- Modify: `Modules/Permissao/resources/js/Support/menusDosModulos.js:45` (`auditoria: { menu: 'Sistema', entrada: 'Auditoria' }`)

**Interfaces:** Consumes `registos`, `filtros` da Task 1.

- [ ] **Step 1:** Página com filtros (acção, utilizador, de/ate), tabela em cards < 768px, linha expansível a mostrar `detalhe.antes`/`detalhe.depois` campo a campo; reaproveitar componentes e classes da página de acessos.
- [ ] **Step 2:** Ligar o menu nos dois ficheiros (e no mapa de módulos).
- [ ] **Step 3:** `npm run build` sem erros; abrir `/auditoria` como admin (playwright-core, credenciais do `reference_dev_login_credentials`) e confirmar tabela, filtros e vista móvel. Sem gravar dados.
- [ ] **Step 4:** `git add`.

### Task 3: Rasto de permissões

**Files:**
- Modify: `AtribuirPerfilAction`, `RemoverPerfilAction`, `SincronizarPermissoesUtilizadorAction`, `SincronizarPermissoesPerfilAction`, `CriarPerfilAction`, `AtualizarPerfilAction`, `EliminarPerfilAction`, `AlternarEstadoPerfilAction` (todas em `Modules/Permissao/app/Actions/`)
- Test: `Modules/Permissao/tests/Feature/AuditoriaPermissoesTest.php`

**Interfaces:** Consumes `RegistarAuditoriaTenantAction::executar(AuditoriaTenantDTO)`; `AuditoriaTenantDTO(accao, entidadeTipo, entidadeId, antes, depois)`.

Acções e entidades: `perfil.atribuido` / `perfil.removido` (`users`, id do utilizador; `antes/depois = ['perfis' => [nomes]]`), `permissoes_utilizador.sincronizadas` (`users`; `['permissoes' => ['modulo.acao' => valor]]` antes/depois), `permissoes_perfil.sincronizadas` (`roles`), `perfil.criado|actualizado|eliminado|estado_alterado` (`roles`; campos de negócio do perfil). Para criado: `antes = []`; para eliminado: `depois` com as chaves a `null`.

- [ ] **Step 1: Testes**: para cada Action, executar e afirmar uma linha `AuditoriaTenant` com `accao`, `entidade_tipo`, `entidade_id` e `detalhe.antes/depois` exactos; `test_remover_perfil_regista_com_depois_nulo`; `test_sincronizar_sem_alteracoes_nao_regista_de_novo`; `test_falha_da_action_nao_deixa_rasto` (ex.: remover o último administrador efectivo lança e não grava).
- [ ] **Step 2:** Correr — esperado FAIL.
- [ ] **Step 3:** Injectar `RegistarAuditoriaTenantAction` no construtor de cada Action e chamar dentro do `DB::transaction` (nas que não têm transacção, envolver a escrita + auditoria numa).
- [ ] **Step 4:** Correr `php artisan test Modules/Permissao` — esperado PASS.
- [ ] **Step 5:** `git add`.

### Task 4: Rasto de justificação de faltas

**Files:**
- Modify: `Modules/Frequencia/app/Actions/JustificarFaltaAction.php`, `RemoverJustificacaoAction.php`
- Test: `Modules/Frequencia/tests/Feature/AuditoriaJustificacaoTest.php` (reaproveitar o cenário dos testes existentes de justificação)

Eventos: `falta.justificada` e `justificacao.removida`, entidade `frequencia_registos`, id do `Registo`; `detalhe`: `motivo`, `documento_referencia`, estado antes/depois. Remoção: `depois` com chaves a `null`. `utilizador_id` = `$utilizador->id` (passar no DTO, porque a Action recebe o utilizador explicitamente).

- [ ] **Step 1: Testes:** justificar grava 1 linha com o motivo; remover grava 1 linha com `depois` nulo e o motivo da remoção; falha de autorização (`autorizar`) não grava.
- [ ] **Step 2:** Correr — FAIL.
- [ ] **Step 3:** Injectar e registar dentro da transacção existente.
- [ ] **Step 4:** Correr `php artisan test Modules/Frequencia` — PASS.
- [ ] **Step 5:** `git add`.

### Task 5: Rasto de matrículas

**Files:**
- Modify: `Modules/Matricula/app/Actions/CriarMatriculaAction.php`, `AtualizarMatriculaAction.php`, `EliminarMatriculaAction.php`, `TrancarMatriculaAction.php`
- Test: `Modules/Matricula/tests/Feature/AuditoriaMatriculaTest.php`

Eventos: `matricula.criada` (antes `[]`), `matricula.actualizada` (diff dos campos editáveis), `matricula.eliminada` (depois com chaves a `null`), `matricula.trancada` (data efeito, motivo, `confirmar_com_dividas`). Entidade `matriculas`. O histórico de estado existente mantém-se; **não** registar mudanças de estado puras aqui.

- [ ] **Step 1: Testes:** um por evento (assert da linha e do diff); `test_atualizar_sem_alteracoes_nao_regista`; `test_falha_de_validacao_nao_regista`.
- [ ] **Step 2:** Correr — FAIL.
- [ ] **Step 3:** Injectar e registar dentro de cada transacção (`EliminarMatriculaAction` não tem; envolver em `DB::transaction`).
- [ ] **Step 4:** Correr `php artisan test Modules/Matricula Modules/Financeiro` (trancamento) + `tests/Feature/Arquitectura` — PASS.
- [ ] **Step 5:** `git add`.

### Task 6: Verificação final

- [ ] Correr a suite completa (`php artisan test`, ~9 min) e confirmar sem falhas novas.
- [ ] Fechar a resposta com as tabelas envolvidas e relações (`auditoria_tenant` → `tenants`, `users`).
