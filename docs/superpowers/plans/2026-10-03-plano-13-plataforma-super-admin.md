# Plano 13 — Plataforma MosiTec (Super Admin), V1

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Estado:** desenho para revisão. Nada deste plano está implementado. Só se executa depois de o dono do produto o aprovar.

**Goal:** Um painel central, separado da aplicação escolar, onde a equipa MosiTec gere o ciclo de vida técnico dos tenants (listar, criar, consultar, suspender, reactivar, encerrar, gerir domínios, recuperar o administrador de uma escola), sem abrir implicitamente o contexto de nenhum tenant e sem aceder a dados académicos.

**Architecture:** Um módulo novo, `Modules/Plataforma`, com modelo, guard, sessão e grupo de middleware próprios, servido apenas nos `tenancy.hosts_centrais`. É uma **segunda interface** sobre as Actions que já existem em `Modules/Tenant` (`Controller → Action existente`); os comandos Artisan ficam como estão. O mundo da escola (grupos `web` e `api`) passa a exigir um tenant resolvido, e o mundo da Plataforma (grupo `plataforma`) passa a exigir host central: cada host serve só um dos dois mundos. As operações que precisam de dados de uma escola (recuperar o administrador) passam por um contrato do Core, implementado no módulo da escola, que abre o contexto **dentro da Action**, de forma curta e restaurada em `finally`.

**Tech Stack:** PHP 8.2, Laravel 12, nwidart/laravel-modules, Inertia + Vue 3, PHPUnit 11, SQLite em memória nos testes (PostgreSQL em desenvolvimento).

**Spec:** `docs/superpowers/specs/2026-09-30-fundacao-tenancy-design.md` — "Fora do âmbito" (Plataforma e Super Admin; este plano trata exactamente esse ponto), §3 (módulo Tenant), §5 (resolução, host central), §6 (ciclo de vida), §13 (jobs e comandos), §16 (provisioning), §18 (licenças e Plataforma, só como fronteira futura), §19. Depende de `docs/tenancy.md` e das etapas 1 a 12, concluídas.

## Decisões de desenho (para aprovação)

| # | Decisão | Alternativa rejeitada e porquê |
|---|---|---|
| D1 | Módulo novo `Modules/Plataforma`, que depende de `Modules/Tenant` e do Core | Pôr o painel dentro de `Modules/Tenant`: o módulo Tenant tem de continuar extraível (spec §3) e não deve misturar autenticação e interface de operadores com a lógica de domínio |
| D2 | O Super Admin é um `SuperAdmin` próprio, em `super_admins`, sem `tenant_id`, com guard `plataforma` | Um `User` com `tenant_id` artificial: viola `NOT NULL`, o scope e o isolamento |
| D3 | Os pedidos do painel correm **sem contexto de tenant**; o contexto só se abre dentro de uma Action (contrato do Core), nunca num controller | Abrir o contexto "para ler" no controller: é a fuga acidental que o plano existe para impedir |
| D4 | Mundos separados por middleware: `web`/`api` exigem tenant (404 sem ele); `plataforma` exige host central (404 fora dele) | Prefixo de rota ou `Route::domain`: o host central é configurável e pode haver vários |
| D5 | Sessão e cookie próprios da Plataforma; guard por omissão continua `web`, por isso `sessions.user_id` fica `NULL` nas sessões da Plataforma | Mudar o guard por omissão: `user_id` das sessões passaria a colidir com ids de utilizadores das escolas, e a revogação de sessões por tenant podia apagar sessões da Plataforma |
| D6 | Recuperar o administrador por um contrato do Core (`RecuperaAdministradorDoTenant`), à semelhança de `RevogaAcessosDoTenant` | A Plataforma importar `Modules\Autenticacao`: quebra a regra de que a Plataforma não conhece módulos de negócio |
| D7 | V1 inclui auditoria mínima das acções da Plataforma (`plataforma_auditoria`) | Sem auditoria, quem suspende ou encerra uma escola não deixa rasto. É pequena e pode sair se discordares |
| D8 | Todos os super admins activos têm o mesmo poder (sem perfis nem permissões finas) na V1 | Perfis e permissões na Plataforma: sem necessidade comercial ou técnica agora |
| D9 | Sem 2FA na V1 (recomendado numa V2); em troca: senha longa, limitador de login próprio, troca obrigatória no primeiro acesso, sessões curtas | 2FA já: o Fortify existente é do mundo da escola e não se reaproveita sem acoplar |
| D10 | O painel só funciona se `tenancy.hosts_centrais` tiver pelo menos um host; vazio = painel desactivado (404 em tudo) | Assumir um host por omissão: o domínio real é decisão de configuração e deploy, e este plano não o fixa |

> **Decisão: prefixo `/plataforma` (colisão de rotas com a escola).** O Laravel indexa as rotas por método + caminho, sem olhar ao host: duas rotas `GET /` (ou `GET /login`) não coexistem, a registada por último substitui a outra. Por isso todas as rotas da Plataforma levam o prefixo `/plataforma` (`PlataformaServiceProvider::PREFIXO`, aplicado no `RouteServiceProvider` do módulo; `routes/web.php` usa caminhos relativos): `/plataforma`, `/plataforma/login`, `/plataforma/escolas`, etc. Os nomes das rotas mantêm-se `plataforma.*`. O host continua a ser a barreira real (`ApenasHostCentral`); o prefixo só elimina a colisão e torna o routing determinístico. Nas Tasks 3 a 6, onde se lê `/login`, `/escolas`... do painel, leia-se com o prefixo. Aprovada pelo dono; rejeitados `Route::domain()` dinâmico e domínio-parâmetro.

## Global Constraints

- **Nunca fazer commit.** O dono do repositório faz os commits. Cada tarefa termina com `git add` dos ficheiros tocados (nunca `.superpowers`). Nunca `git stash`, nunca `git checkout` para reverter.
- **Nunca `migrate:fresh`** nem escrita na BD de desenvolvimento por tinker ou SQL. Este plano só acrescenta migrations (tabelas novas): basta `php artisan migrate`, que corre o utilizador.
- **Regra de ouro:** nenhum controller, FormRequest, middleware ou Vue da Plataforma abre contexto de tenant. Só `TenantContext::executarComo` dentro de uma Action ou implementação de contrato fora de `Modules/Plataforma`. Documentada em `docs/tenancy.md` e provada por teste de arquitectura e por testes de comportamento (Task 2 e Task 7).
- **Sem lógica de negócio nos controllers.** Cada acção do painel chama uma Action existente (tabela em Task 4 a 6). Regras e validação de domínio ficam nas Actions e Services do módulo Tenant; os FormRequests da Plataforma só validam formato.
- A Plataforma **não importa módulos de negócio** (`Usuario`, `Permissao`, `Autenticacao`, `Estabelecimento`, módulos académicos). Só `Core` (contratos e tipos) e `Tenant`. O módulo `Tenant` e o Core não importam `Plataforma`.
- A Plataforma **não lê tabelas tenant-scoped**: nada de models de escola, `DB::table` ou `withoutGlobalScopes`. As tabelas `tenants`, `domains`, `super_admins` e `plataforma_auditoria` são globais.
- Sem planos, subscrições, facturação, pagamentos, limites ou direitos comerciais; sem acesso a dados académicos individuais; **sem impersonação** nem login como administrador de uma escola.
- O host da Plataforma nunca é fixado em código, testes de produção ou docs como valor real: só em `TENANCY_HOSTS_CENTRAIS` (config). Os testes definem o seu próprio host central.
- A palavra-passe temporária: só hash na BD, mostrada **uma única vez** (flash cifrado com `Crypt`, `encryptHistory` na resposta, `clearHistory` ao fechar o modal), nunca em logs, exceptions, auditoria nem cache. Mesmo padrão e mesmos testes do Plano 3b.
- Em PHP, `use` no topo, nunca FQN inline; controllers finos; escrita em Actions, leitura em Services; mensagens e comentários em português; reaproveitar o padrão visual existente (Metronic, `Loader`, `vue-sonner`, `bg-body-secondary`).
- Comando de testes: `php artisan test`. A suite completa tem de passar no fim de cada tarefa (baseline à entrada: 1312 passed, 0 falhas) e `npm run build` compila nas tarefas que tocam em Vue.

## Review Focus

1. **Contexto aberto acidentalmente:** qualquer pedido do painel acaba com `TenantContext::temTenant() === false`, e uma leitura de model tenant-scoped feita a partir do pedido lança `TenantNaoResolvido` → Task 2 e Task 7.
2. **Cookie e sessão partilhados entre mundos:** um `SESSION_DOMAIN` de domínio-pai (como o do `.env` de desenvolvimento) faria o cookie da escola valer na Plataforma e vice-versa → Task 2 (cookie próprio, `domain` nulo, teste do nome do cookie e da separação).
3. **Colisão de `sessions.user_id`:** revogar acessos de uma escola não pode apagar sessões da Plataforma → Task 2 e Task 5.
4. **Senha temporária:** flash, histórico do browser, logs, auditoria e payload da sessão → Task 4 e Task 6.
5. **Acções destrutivas e abuso:** encerrar sem confirmação, CSRF, repetição de pedidos, enumeração de escolas e de administradores, super admin desactivado com sessão aberta → Task 3, Task 5 e Task 7.

## Mapa de ficheiros

| Área | Ficheiros |
|---|---|
| Módulo | `Modules/Plataforma/{module.json, composer.json, app/Providers/{PlataformaServiceProvider,RouteServiceProvider}.php}`; `modules_statuses.json` (`"Plataforma": true`) |
| Dados | `Modules/Plataforma/database/migrations/*_create_super_admins_table.php`, `*_create_plataforma_auditoria_table.php`; models `SuperAdmin`, `RegistoDeAuditoria` |
| Auth | `config/auth.php` (guard e provider `plataforma`); `Http/Controllers/Auth/*`; `Http/Middleware/{SuperAdminActivo,ExigirTrocaDeSenhaPlataforma}.php`; limitador `plataforma.login` |
| Fronteira | `Modules/Core/app/Tenancy/Http/Middleware/ExigirTenantNoContexto.php`; `Modules/Plataforma/app/Http/Middleware/{ApenasHostCentral,ConfigurarSessaoPlataforma,HandleInertiaPlataforma}.php`; `bootstrap/app.php` (grupo `plataforma` e `ExigirTenantNoContexto` nos grupos `web` e `api`); `resources/views/layouts/plataforma.blade.php` |
| Controllers | `EscolaController` (listar, criar, detalhe), `CicloDeVidaController`, `DominioController`, `AdministradorEscolaController` |
| Contrato | `Modules/Core/app/Tenancy/Contracts/RecuperaAdministradorDoTenant.php` (+ `AdministradorDaEscola`); implementação em `Modules/Autenticacao/app/Actions/RecuperaAdministradorDoTenantAction.php` e bind no `AutenticacaoServiceProvider` |
| Tenant | `Modules/Tenant/app/Actions/RevogarAcessosAposSuspensaoAction.php` (extraída do comando) e `SuspenderTenantCommand` a usá-la; `Modules/Tenant/app/Services/TenantConsultaService.php` (listagem com filtros) |
| Comandos | `mosi:plataforma:admin:create`, `mosi:plataforma:admin:reset`; `PlataformaDesenvolvimentoSeeder` (só local/testing) |
| UI | `Modules/Plataforma/resources/js/Pages/{Login,AlterarSenha}.vue`, `Pages/Escolas/{Index,Show,Nova}.vue`, `Components/{LayoutPlataforma,EstadoBadge,DominiosCard,AcoesCicloDeVida,SenhaTemporariaModal,ConfirmarEncerramentoModal}.vue` |
| Testes | `Modules/Plataforma/tests/Feature/*`; `tests/Feature/Arquitectura/TenancyArquitecturaTest.php` (regras novas e whitelist); `tests/Feature/Tenancy/MatrizIsolamentoTest.php` (linha Plataforma); `docs/tenancy.md` |

---

### Task 1: Núcleo da Plataforma (módulo, Super Admin, auditoria, comandos)

**Files:**
- Create: o módulo `Modules/Plataforma` (via o gerador de módulos do projecto; confirmar a estrutura copiando a de `Modules/Tenant`), e registar `"Plataforma": true` em `modules_statuses.json` (**falha silenciosa se faltar**: sem rotas, sem erro)
- Create: migrations `super_admins` e `plataforma_auditoria`; models `SuperAdmin` e `RegistoDeAuditoria`
- Modify: `config/auth.php` (guard `plataforma`, provider `super_admins`), `config/tenancy.php` (`tabelas_globais` += `super_admins`, `plataforma_auditoria`)
- Create: `Modules/Plataforma/app/Console/{CriarSuperAdminCommand,RedefinirSuperAdminCommand}.php`, `Actions/{CriarSuperAdminAction,RedefinirSuperAdminAction,RegistarAuditoriaAction}.php`, `database/seeders/PlataformaDesenvolvimentoSeeder.php`
- Modify: `tests/Feature/Arquitectura/TenancyArquitecturaTest.php`

**Interfaces:**
- Produces: `SuperAdmin` (campos `name`, `email` único, `password`, `estado`, `estado_descricao`, `deve_alterar_senha`, `ultimo_login_em`, `remember_token`; sem `PertenceAoTenant`; `estado` sincronizado como nos outros models) ; guard `plataforma`; `RegistarAuditoriaAction::executar(?SuperAdmin $autor, string $accao, ?string $codigoTenant, array $detalhe = []): void` (grava `ip` do pedido quando existir; o `detalhe` nunca leva segredos, validado por teste); `CriarSuperAdminAction::executar(string $nome, string $email): CredencialInicial` (senha temporária com `GeradorSenhaTemporaria`, `deve_alterar_senha = true`).

- [ ] **Step 1: Testes primeiro**
  - `SuperAdminTest`: `super_admins` sem `tenant_id`; `email` único (global, não por tenant); `password` só com hash; `estado_descricao` sincronizado; as duas tabelas estão em `tabelas_globais` e passam o teste de esquema.
  - `CriarSuperAdminCommandTest`: cria com senha temporária mostrada **uma vez** em modo RAW (`OutputInterface::OUTPUT_RAW`; usar a técnica do teste de `mosi:tenant:create` para forçar uma senha cheia de `<`, `>`, `\`, `/`), `deve_alterar_senha = true`, não aceita `--password`, e-mail duplicado ou inválido recusado, senha nunca em logs (`Log::listen`). `mosi:plataforma:admin:reset` gera nova senha temporária, invalida as sessões do super admin e recusa conta desactivada.
  - `AuditoriaTest`: `RegistarAuditoriaAction` grava autor, acção, código do tenant e IP; recusa `detalhe` com chaves `senha`, `password`, `token`, `credencial` (lança excepção) para o segredo nunca ir parar à tabela.
  - Arquitectura: `Modules/Plataforma` só importa Core e Tenant (não importa módulos de negócio); o Core e o Tenant não importam `Plataforma`; a regra existente "só o módulo Tenant importa `Modules\Tenant`" ganha `Modules/Plataforma/` na lista explícita de excepções, comentada.
  - `PlataformaDesenvolvimentoSeederTest`: só corre em local/testing; em `production` não cria nada.
- [ ] **Step 2: Correr para ver falhar.**
- [ ] **Step 3: Implementar** (migrations novas, sem editar as antigas; guard e provider em `config/auth.php`; seeder de desenvolvimento com credenciais conhecidas e flag `false`, no padrão do `AdminUserSeeder`).
- [ ] **Step 4: Suite completa.**
- [ ] **Step 5: Mutação (backup por `cp`):** `tenant_id` acrescentado ao model → o teste de esquema ou o de modelo falha; senha em claro → falha; tirar a lista negra do `detalhe` → falha.
- [ ] **Step 6: `git add`.**

---

### Task 2: A fronteira entre mundos (runtime, host, sessão, contexto)

Esta tarefa é o coração do plano. Sem ela, o painel não é seguro.

**Files:**
- Create: `ExigirTenantNoContexto` (Core), `ApenasHostCentral`, `ConfigurarSessaoPlataforma`, `HandleInertiaPlataforma` (Plataforma), `layouts/plataforma.blade.php`, `RouteServiceProvider` do módulo
- Modify: `bootstrap/app.php`, `docs/tenancy.md`
- Test: `Modules/Plataforma/tests/Feature/FronteiraTest.php`, mais uma rota **de teste** (fixture em `tests/Fixtures`) dentro do grupo `plataforma` que lê um model tenant-scoped

**Interfaces:**
- Consumes: `tenancy.hosts_centrais`, `NormalizadorHost` (já usado pelo `ResolverTenant`), `TenantContext`.
- Produces:
  - grupo de middleware `plataforma`, por esta ordem: `ApenasHostCentral`, `ConfigurarSessaoPlataforma`, `EncryptCookies`, `AddQueuedCookies`, `StartSession`, `ShareErrorsFromSession`, `ValidateCsrfToken`, `SubstituteBindings`, `HandleInertiaPlataforma`. **Nunca** inclui `VerificarTenantDaSessao`, `ExigirTrocaDeSenha` (da escola), `ExigirConfiguracaoInicial` nem `HandleInertiaRequests`.
  - `ExigirTenantNoContexto`, o primeiro middleware dos grupos `web` e `api`: se não há tenant em contexto (`! TenantContext::temTenant()`), responde 404, igual a um host desconhecido. Rotas fora destes grupos (`/up`) não são afectadas.
  - `ApenasHostCentral`: 404 se `hosts_centrais` está vazio ou o host do pedido não é central.
  - `ConfigurarSessaoPlataforma`, antes do `StartSession`: `session.cookie` = nome próprio (ex.: derivado de `config('app.name')` + `_plataforma_session`), `session.domain` = `null` (nunca o domínio-pai, mesmo que `SESSION_DOMAIN` o defina), `session.lifetime` = `config('plataforma.sessao_minutos')` (por omissão, mais curta que a das escolas).
  - Todas as respostas da Plataforma levam `Cache-Control: no-store, private` e `X-Robots-Tag: noindex`.

- [ ] **Step 1: Testes primeiro (`FronteiraTest`)**, cada um com controlo positivo e negativo:
  - `test_host_central_serve_a_plataforma_e_nao_a_escola`: no host central, `GET /plataforma/login` da Plataforma é 200 (na Task 2, `GET /plataforma`) e uma rota da escola (`/cursos`, `/login` da escola, `/api/v1/turmas`) dá 404; o contexto de tenant está vazio.
  - `test_host_de_tenant_serve_a_escola_e_nao_a_plataforma`: num host de tenant, as rotas da Plataforma dão 404 e as da escola funcionam como antes.
  - `test_sem_hosts_centrais_a_plataforma_esta_desactivada`: `hosts_centrais = []` → toda a Plataforma 404; a escola intacta.
  - `test_host_central_nunca_abre_contexto`: depois de cada pedido ao painel, `TenantContext::temTenant()` é `false`; um middleware espião no grupo confirma-o durante o pedido.
  - `test_leitura_tenant_scoped_no_painel_falha_alto`: a rota-fixture que faz `Curso::count()` dentro do grupo `plataforma` lança `TenantNaoResolvido` (prova de que nenhum contexto está aberto e de que a falha é fechada).
  - `test_cookie_da_plataforma_e_proprio`: o nome do cookie da resposta da Plataforma difere do da escola e não leva `Domain` do domínio-pai, **mesmo com `session.domain` configurado para um domínio-pai**.
  - `test_sessao_da_plataforma_nao_vale_na_escola_nem_vice_versa`: um cookie de sessão de um mundo, enviado ao outro, não autentica.
  - `test_sessions_user_id_fica_nulo_para_a_plataforma`: depois de um login do Super Admin (Task 3), a linha de `sessions` tem `user_id` nulo; a revogação de acessos de uma escola (`RevogarAcessosDoTenantAction`) não apaga essa sessão.
  - `test_o_grupo_web_ja_nao_responde_sem_tenant`: regressão: o host central dá 404 em rotas da escola em todos os métodos HTTP; `/up` continua 200.
  - Arquitectura: nenhuma rota registada pelo módulo Plataforma tem o grupo `web` ou `api`; nenhuma rota fora do módulo tem o grupo `plataforma`; nenhum ficheiro em `Modules/Plataforma/app/Http` referencia `TenantContext::definir`, `limpar` ou `executarComo`.
- [ ] **Step 2: Correr para ver falhar.**
- [ ] **Step 3: Implementar** os middlewares e o grupo, e acrescentar `ExigirTenantNoContexto` como primeiro dos grupos `web` e `api`. Ajustar só a **preparação** de testes existentes que pedissem rotas da escola num host central (anotar cada caso).
- [ ] **Step 4: Suite completa e `npm run build`.**
- [ ] **Step 5: Mutação (backup por `cp`):** tirar `ExigirTenantNoContexto` → `test_host_central_serve_a_plataforma_e_nao_a_escola` falha; tirar `ConfigurarSessaoPlataforma` → o teste do cookie falha; pôr `ExigirTenantNoContexto` depois de `VerificarTenantDaSessao` → o teste de regressão falha.
- [ ] **Step 6: `git add`.**

---

### Task 3: Autenticação do painel

**Decisão aprovada na revisão da Task 1:** a invalidação das sessões de um Super Admin depois de uma alteração ou reset de credencial **não** apaga linhas de `sessions` por `user_id` (as sessões da Plataforma têm `user_id` nulo e o id pode colidir com o de um utilizador de escola). Usa um mecanismo próprio da sessão da Plataforma, baseado na credencial: a sessão guarda uma impressão da palavra-passe actual do Super Admin e o middleware `SuperAdminActivo` (ou um colaborador único chamado por ele, nunca os controllers) recusa e termina a sessão quando a impressão deixa de coincidir. A lógica fica centralizada num só componente, com testes: reset por comando invalida as sessões abertas; troca própria invalida as outras sessões e mantém a actual.

**Files:**
- Create: `Http/Controllers/Auth/{LoginController,AlterarSenhaController}.php`, `Http/Requests/{LoginRequest,AlterarSenhaRequest}.php`, `Http/Middleware/{SuperAdminActivo,ExigirTrocaDeSenhaPlataforma}.php`, `Actions/{AutenticarSuperAdminAction,AlterarPropriaSenhaSuperAdminAction}.php`, limitador `plataforma.login`
- Create (Vue): `Pages/Login.vue`, `Pages/AlterarSenha.vue`, `Components/LayoutPlataforma.vue`
- Test: `Modules/Plataforma/tests/Feature/AutenticacaoPlataformaTest.php`

**Interfaces:**
- Produces: rotas `plataforma.login`, `plataforma.login.store`, `plataforma.logout`, `plataforma.senha.alterar`, `plataforma.senha.alterar.store`; middleware de rota `auth:plataforma` + `SuperAdminActivo` + `ExigirTrocaDeSenhaPlataforma` aplicados a todas as rotas autenticadas do painel.

- [ ] **Step 1: Testes primeiro:**
  - login com credenciais certas entra e regenera a sessão; erradas dão mensagem **genérica** (não distingue e-mail inexistente de senha errada); conta desactivada não entra e a mensagem é a mesma;
  - limitador por e-mail+IP e por IP (`plataforma.login`), independente do `LimitadorLogin` das escolas: falhas na Plataforma não bloqueiam o login de uma escola e vice-versa;
  - `deve_alterar_senha` obriga à troca: só `senha.alterar`, o seu POST e `logout` passam; a troca valida senha actual, `Password::min(12)` com mistura, `confirmed`, `different`; limpa a flag, roda `remember_token` e invalida as **outras** sessões do Super Admin (por id de sessão actual, como no Plano 3b);
  - super admin desactivado com sessão aberta é expulso no pedido seguinte (`SuperAdminActivo` verifica o estado a cada pedido);
  - um utilizador de escola autenticado (guard `web`) não passa em `auth:plataforma`, e um Super Admin autenticado não passa na escola; o guard por omissão continua `web`;
  - logout invalida a sessão e regenera o token; `Cache-Control: no-store`;
  - nenhum registo público (`/plataforma/register` e `/plataforma/forgot-password` dão 404).
- [ ] **Step 2: Ver falhar. Step 3: Implementar** (Actions para a escrita; controllers finos). **Step 4: Suite e build.**
- [ ] **Step 5: Mutação:** sem `SuperAdminActivo` → o teste do desactivado falha; mensagem de login que distingue casos → falha; sem a flag de troca → falha.
- [ ] **Step 6: `git add`.**

---

### Task 4: Escolas: listagem, detalhe e criação

**Files:**
- Modify: `Modules/Tenant/app/Services/TenantConsultaService.php` (listagem paginada com filtros `estado` e pesquisa por nome, código ou domínio; só lê `tenants` e `domains`)
- Create: `Http/Controllers/EscolaController.php`, `Http/Requests/CriarEscolaRequest.php`, `Pages/Escolas/{Index,Show,Nova}.vue`, `Components/{EstadoBadge,SenhaTemporariaModal}.vue`
- Test: `Modules/Plataforma/tests/Feature/EscolasTest.php`

**Interfaces:**
- Consumes: `TenantConsultaService`, `CriarTenantAction::executar(CriarTenantDTO): TenantCriado`.
- Produces: rotas `plataforma.escolas.index`, `.show`, `.nova`, `.store`; o `{tenant}` resolve-se **pelo código** (`Route::bind` restrito ao grupo da Plataforma, via `TenantConsultaService::porCodigo`; 404 se não existir; escolas encerradas continuam visíveis aqui); flash `senha_temporaria` = `['codigo' => string, 'email' => string, 'senha' => cifrada]`.

Mapa do controller → Action:

| Pedido | Chama |
|---|---|
| `POST /plataforma/escolas` | `CriarTenantAction` (nome, administrador, domínio principal, código opcional). A Plataforma **não** valida regras de domínio nem de código: mostra os erros que a Action devolve (`DadosDeTenantInvalidos`) |
| `GET /plataforma/escolas`, `GET /plataforma/escolas/{codigo}` | `TenantConsultaService` (estado, datas, motivo da suspensão, domínios e as últimas acções de auditoria) |

- [ ] **Step 1: Testes primeiro:**
  - a listagem mostra todos os tenants com estado e domínio principal, filtra por estado e pesquisa, pagina; **não executa nenhuma consulta a tabelas tenant-scoped** (asserção sobre as queries registadas: só `tenants`, `domains`, `super_admins`, `plataforma_auditoria`);
  - o detalhe mostra nome, código, estado (Activa, Suspensa, Encerrada), motivo e datas da suspensão, domínios, auditoria; código inexistente dá 404;
  - criar escola: chama `CriarTenantAction`, redirecciona para o detalhe, mostra a senha temporária **uma vez** (a página seguinte já não a tem; o payload bruto da sessão não a contém em claro; a resposta leva `encryptHistory`; nada em logs); erros da Action (domínio duplicado ou reservado, e-mail inválido, código duplicado) aparecem nos campos e **nada** é criado; atomicidade preservada;
  - auditoria `escola.criada` com código, sem a senha;
  - sem autenticação ou com conta desactivada → redirect para o login.
- [ ] **Step 2: Ver falhar. Step 3: Implementar. Step 4: Suite e build.**
- [ ] **Step 5: Mutação:** controller a validar domínio por conta própria (cópia da regra) → o teste "regras vêm da Action" falha; senha no detalhe → falha; consulta a tabela tenant-scoped → falha.
- [ ] **Step 6: `git add`.**

---

### Task 5: Ciclo de vida e domínios

**Files:**
- Create: `Modules/Tenant/app/Actions/RevogarAcessosAposSuspensaoAction.php` (a lógica que hoje vive em `SuspenderTenantCommand`: `executarComo` + `RevogaAcessosDoTenant`); alterar `SuspenderTenantCommand` para a usar **sem mudar o seu comportamento** (os testes do comando mantêm-se)
- Create: `Http/Controllers/{CicloDeVidaController,DominioController}.php`, `Http/Requests/{SuspenderEscolaRequest,EncerrarEscolaRequest,DominioRequest}.php`, `Components/{AcoesCicloDeVida,DominiosCard,ConfirmarEncerramentoModal}.vue`
- Test: `Modules/Plataforma/tests/Feature/CicloDeVidaTest.php`, `DominiosTest.php`

Mapa do controller → Action:

| Pedido | Chama |
|---|---|
| `POST /plataforma/escolas/{codigo}/suspender` (motivo obrigatório, `revogar_acessos` opcional) | `SuspenderTenantAction`; com a opção, `RevogarAcessosAposSuspensaoAction` |
| `POST /plataforma/escolas/{codigo}/reactivar` | `ReactivarTenantAction` |
| `POST /plataforma/escolas/{codigo}/encerrar` (exige escrever o código da escola como confirmação) | `EncerrarTenantAction` |
| `POST /plataforma/escolas/{codigo}/dominios` | `AdicionarDominioAction` |
| `DELETE /plataforma/escolas/{codigo}/dominios/{dominio}` | `RemoverDominioAction` |

- [ ] **Step 1: Testes primeiro:**
  - cada transição válida e inválida **produz o mesmo resultado que a Action** (estado, datas, motivo) e uma linha de auditoria; transição recusada (`OperacaoDeTenantRecusada`) mostra a mensagem da Action e não audita sucesso;
  - suspender sem motivo falha; encerrar sem escrever o código correcto falha e nada muda; encerrado é terminal e a UI não oferece reactivar;
  - efeito real: depois de suspender pelo painel, o domínio da escola dá 403 e depois de encerrar dá 404 (reusa as asserções do ciclo de vida da Etapa 10);
  - `revogar_acessos` apaga sessões e tokens **só da escola alvo** e roda o `remember_token`; **as sessões da Plataforma e as de outra escola ficam intactas** (cobre o Review Focus 3);
  - domínios: adicionar válido, inválido, duplicado, reservado e igual a um host central é recusado (mensagem da Action); remover recusa o principal e o único, e recusa qualquer remoção numa escola encerrada; a UI esconde o botão do principal; o `TipoDominio` é o decidido pelo `ClassificadorDominio`;
  - CSRF obrigatório; repetir o POST de suspender dá erro claro (não duplica); o comando `mosi:tenant:suspend --revogar-sessoes` continua a passar nos seus testes;
  - nenhum controller abre contexto (arquitectura da Task 2) e todo o fluxo corre com o contexto vazio.
- [ ] **Step 2: Ver falhar. Step 3: Implementar. Step 4: Suite e build.**
- [ ] **Step 5: Mutação:** controller com transição directa no model em vez de Action → o teste "mesmo resultado que a Action" ou a regra de arquitectura falha; sem confirmação de código no encerramento → falha; `revogar_acessos` sem filtro → o teste de sessões intactas falha.
- [ ] **Step 6: `git add`.**

---

### Task 6: Recuperar o administrador de uma escola

**Files:**
- Create: `Modules/Core/app/Tenancy/Contracts/RecuperaAdministradorDoTenant.php` e o DTO `AdministradorDaEscola` (`nome`, `email`, `activo`)
- Create: `Modules/Autenticacao/app/Actions/RecuperaAdministradorDoTenantAction.php` (implementa o contrato: abre o contexto **ela própria** com `executarComo($tenant, …)`, delega em `RecuperarAdministradorAction`, restaura o contexto em `finally`); bind no `AutenticacaoServiceProvider`
- Create: `Http/Controllers/AdministradorEscolaController.php`, `Components/SenhaTemporariaModal.vue` (partilhado com a Task 4)
- Test: `Modules/Autenticacao/tests/Feature/RecuperaAdministradorDoTenantTest.php`, `Modules/Plataforma/tests/Feature/RecuperarAdministradorTest.php`

**Interfaces:**
- Produces: `RecuperaAdministradorDoTenant::administradores(TenantAtual $tenant): array` (lista de `AdministradorDaEscola`, só administradores **activos** com perfil de Administrador da Escola; mínimo necessário: nome, e-mail, estado) e `::recuperar(TenantAtual $tenant, ?string $email = null): CredencialInicial`. O `mosi:tenant:admin:reset` existente **não muda**.
- Rotas: `GET /plataforma/escolas/{codigo}/administradores` (carregado a pedido ao abrir o modal, para o detalhe da escola **não** abrir o contexto em cada visita) e `POST /plataforma/escolas/{codigo}/administrador/recuperar` (`email` opcional; obrigatório se houver mais de um administrador activo).

- [ ] **Step 1: Testes primeiro:**
  - contrato: `administradores` devolve só administradores activos da escola alvo (a outra escola não aparece); `recuperar` gera nova senha temporária, grava só hash, `deve_alterar_senha = true`, invalida sessões, tokens e `remember_token` **só desse utilizador**, deixa `senha_redefinida_por` nulo, recusa escola não activa e administrador desactivado, e **restaura o contexto anterior** (ao entrar com contexto vazio, sai vazio; ao entrar com outro tenant aberto, volta a esse);
  - painel: o pedido corre com contexto vazio (o controller nunca o abre), e a Action do contrato é a única a abri-lo; a senha aparece **uma vez** (flash cifrado, payload da sessão sem claro, `encryptHistory`, segunda visita sem flash, nada em logs, auditoria `administrador.recuperado` com código e e-mail mas **sem** senha);
  - vários administradores sem `email` → erro claro e lista; e-mail de outra escola ou inexistente → erro genérico, sem revelar se existe noutra escola;
  - o painel não consegue obter dados que não sejam os campos do `AdministradorDaEscola` (nada de perfis, permissões ou dados académicos);
  - Plataforma não importa Autenticacao (arquitectura da Task 1) e o contrato está ligado quando o módulo Autenticacao está activo; com o módulo desactivado, falha alto (excepção), nunca "lista vazia".
- [ ] **Step 2: Ver falhar. Step 3: Implementar. Step 4: Suite e build.**
- [ ] **Step 5: Mutação:** `recuperar` sem `executarComo` → o teste de contexto falha; senha em claro na sessão → falha; sem `finally` do contexto → falha.
- [ ] **Step 6: `git add`.**

---

### Task 7: Matriz de isolamento, documentação e fecho

**Files:**
- Modify: `tests/Feature/Tenancy/MatrizIsolamentoTest.php` (nova linha "Plataforma / Super Admin" no mapa, referindo os testes das Tasks 2, 3, 5 e 6), `tests/Feature/Arquitectura/TenancyArquitecturaTest.php`, `docs/tenancy.md`, `.env.example`
- Test: `tests/Feature/Tenancy/PlataformaIsolamentoTest.php`

- [ ] **Step 1: Teste de ponta a ponta (`PlataformaIsolamentoTest`)** com dois tenants A e B e um Super Admin, num host central de teste: o Super Admin cria a escola C, suspende A, reactiva A, encerra C, adiciona e remove um domínio de B, e recupera o administrador de B; **depois de cada passo** `TenantContext::temTenant()` é `false`; a escola B não vê alterações em A; as sessões de B e as da Plataforma ficam intactas quando se revogam os acessos de A; um utilizador de escola nunca alcança o painel e o Super Admin nunca alcança a escola; **nenhum dado académico** (cursos, alunos, matrículas, nomes de utilizadores, excepto o e-mail e nome do administrador da Task 6) aparece em qualquer resposta do painel (varrimento de props e HTML com marcadores AAA/BBB, como no teste dos Services).
- [ ] **Step 2: Arquitectura final:** o módulo Plataforma só importa Core e Tenant; nenhum controller chama `executarComo`; nenhuma rota da Plataforma está nos grupos `web` ou `api`; `super_admins` e `plataforma_auditoria` estão em `tabelas_globais` e nenhum model da Plataforma usa `PertenceAoTenant`; o scanner de bypass do Eloquent (Etapa 12) cobre o módulo novo sem excepções.
- [ ] **Step 3: Documentação:** em `docs/tenancy.md`, secção "Plataforma": a regra de ouro do contexto, os dois mundos, `TENANCY_HOSTS_CENTRAIS` (obrigatório para o painel existir; vazio = desactivado), o cookie próprio, `SESSION_DOMAIN` com cuidado (não usar domínio-pai em produção), como criar o primeiro super admin (`mosi:plataforma:admin:create`), HTTPS e `SESSION_SECURE_COOKIE` em produção, e o modelo de ameaça (quem tem acesso ao painel gere todas as escolas; sem 2FA na V1). `.env.example`: `TENANCY_HOSTS_CENTRAIS=` com comentário, **sem valor real**.
- [ ] **Step 4: Suite completa, `npm run build`, mutações** (tirar `ExigirTenantNoContexto`, tirar o cookie próprio, controller a abrir contexto → a bateria falha).
- [ ] **Step 5: Verificação em desenvolvimento (a pedido):** o utilizador corre `php artisan migrate` (só tabelas novas) e define `TENANCY_HOSTS_CENTRAIS` para o host que escolher; o agente verifica por HTTP, com cookie jar: login da Plataforma, listagem, detalhe, e que as rotas da escola dão 404 no host central e as da Plataforma 404 no host de uma escola. Nada se escreve na BD fora da aplicação.
- [ ] **Step 6: `git add`** e relatório final.

## Execução recomendada

Subagentes, uma tarefa de cada vez, com a cadência de revisão dos planos anteriores: **revisão forte (opus) depois da Task 2**, porque é a fronteira de segurança; uma revisão conjunta das Tasks 3 a 6; revisão final de toda a Plataforma depois da Task 7. Cada tarefa vê os testes falhar antes de implementar. O `.superpowers/sdd/<plano>/progress.md` guarda o ledger.

## Auto-revisão

**Cobertura do pedido (16 pontos):** 1 modelo e migration → Task 1; 2 guard, provider, sessão → Tasks 1 e 2; 3 autenticação → Task 3; 4 isolamento entre contextos → Task 2; 5 middleware e autorização → Tasks 2 e 3; 6 integração com `hosts_centrais` → Task 2 (D4 e D10); 7 rotas e estrutura → Mapa de ficheiros e Tasks 3 a 6; 8 controllers e Actions → tabelas das Tasks 4 e 5 e contrato da Task 6; 9 criação, listagem, detalhes → Task 4; 10 ciclo de vida → Task 5; 11 domínios → Task 5; 12 recuperação do administrador → Task 6; 13 senha temporária → Global Constraints, Tasks 4 e 6; 14 testes de isolamento e autorização → Tasks 2, 3, 5, 6 e 7; 15 UI → Tasks 3 a 6, `Modules/Plataforma/resources/js`; 16 alterações ao runtime → Task 2 (`ExigirTenantNoContexto`, grupo `plataforma`, cookie próprio) e Task 1 (`tabelas_globais`, whitelist da arquitectura 3).

**A regra pedida, "o Super Admin gere tenants sem que o contexto de tenant seja aberto acidentalmente":** documentada (Global Constraints e `docs/tenancy.md`), imposta por arquitectura (nenhum controller chama `executarComo`; nenhuma rota da Plataforma está nos grupos da escola) e provada por comportamento (`temTenant()` falso depois de cada passo; leitura tenant-scoped a partir do painel lança `TenantNaoResolvido`).

**Alterações ao que já existe (todas pequenas e testadas):** `bootstrap/app.php` (grupo `plataforma`, `ExigirTenantNoContexto` nos grupos `web` e `api`), `config/auth.php`, `config/tenancy.php` (`tabelas_globais`), `SuspenderTenantCommand` (passa a usar a Action extraída, comportamento igual), regra de arquitectura 3 (whitelist de `Plataforma`), `TenantConsultaService` (listagem).

**Pontos para a tua decisão (não bloqueiam o desenho):**
1. **Auditoria na V1 (D7):** incluída porque quem suspende ou encerra escolas deve deixar rasto. Posso removê-la.
2. **Listar os e-mails dos administradores da escola (Task 6):** necessário para escolher qual recuperar quando há vários; é o único dado de escola que o painel lê, e só nome, e-mail e estado.
3. **Sem 2FA na V1 (D9):** o risco de uma conta com poder sobre todas as escolas é real; a mitigação da V1 é senha longa, limitador, troca obrigatória e sessões curtas. Recomendo 2FA como primeira melhoria depois da V1.
4. **Efeito colateral aceite de D4:** o host central deixa de servir qualquer rota da escola (hoje respondia, com 500 ou 404 conforme a rota). É um endurecimento, e é coberto por teste de regressão.
5. **`sessions.user_id` nulo para a Plataforma (D5):** impede revogar por utilizador as sessões da Plataforma; a invalidação faz-se por id de sessão (como na troca de senha) e por `SuperAdminActivo` a cada pedido.

**Fica fora e registado:** planos, subscrições, facturação, direitos comerciais, indicadores, impersonação, 2FA, perfis de Super Admin e acesso a dados académicos. Qualquer um deles é decisão explícita e separada.
