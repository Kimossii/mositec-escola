# 2FA Fase 2 (super admin) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dar ao super admin 2FA TOTP opcional (activar/desactivar, desafio no login, recuperação por comando, auditoria).

**Architecture:** Fluxo próprio no módulo Plataforma. O modelo `SuperAdmin` ganha o trait `Laravel\Fortify\TwoFactorAuthenticatable` e as colunas `two_factor_*`; as Actions usam `TwoFactorAuthenticationProvider` e `RecoveryCode` do Fortify, sem eventos nem rotas do Fortify. O login passa a ter um "desafio pendente" na sessão entre a senha e `AbrirSessaoDoSuperAdminAction`.

**Tech Stack:** Laravel 12, Fortify (só as classes), Inertia + Vue, Google2FA, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-25-2fa-fase2-super-admin-design.md` (referência de padrões: `docs/superpowers/specs/2026-11-22-2fa-fase1-design.md`, `Modules/Autenticacao/resources/js/Components/DoisFactores.vue`, `Modules/Autenticacao/resources/js/Pages/DesafioDoisFactores.vue`, `Modules/Autenticacao/tests/Support/ComDoisFactores.php`)

## Global Constraints

- **Nunca obrigatório**: sem middleware de bloqueio, sem 403 forçado. Quem não activou entra como hoje.
- Só TOTP offline: sem email/SMS/push. Não tocar no Fortify nem no login da escola.
- Controllers finos (Action para escrita, Service para leitura), `use` no topo (nunca FQN inline), uma alteração de tabela por migração, Cancelar em `btn-light-danger`, reaproveitar classes/padrões visuais de `AlterarSenha.vue` e `LayoutPlataforma.vue`.
- Auditoria via `RegistarAuditoriaAction` (nunca segredos nem códigos no `detalhe`; a Action recusa chaves como `secret`, `password`, `token`).
- Texto de interface em português europeu/angolano ("senha", "código", "activar").
- **Sem commit**: só `git add`; o commit é do dono.
- Testes novos em `Modules/Plataforma/tests/Feature/`, com `RefreshDatabase`, `ComPainelDaPlataforma` e `config(['tenancy.hosts_centrais' => [self::CENTRAL]])` no `setUp`, como `AutenticacaoPlataformaTest`.

## Review Focus

1. 2FA iniciado mas **não confirmado** (utilizador abandonou a activação): o login não pede desafio.
2. Super admin desactivado (ou com 2FA reposto) **entre** a senha e o código: o desafio é recusado e a sessão não abre.
3. Código TOTP já usado repetido (replay) e código de recuperação já consumido: segunda tentativa falha.
4. Desafio pendente expirado (5 min) ou acesso directo a `/plataforma/desafio` sem pendente: volta ao login, sem 500.
5. Segredo e códigos de recuperação nunca em props Inertia, JSON de listagem, `toArray()`, logs ou auditoria.

---

## File Structure

- Create `Modules/Plataforma/database/migrations/2026_11_25_000000_add_two_factor_columns_to_super_admins_table.php`
- Modify `Modules/Plataforma/app/Models/SuperAdmin.php`
- Create `Modules/Plataforma/app/Actions/IniciarDoisFactoresSuperAdminAction.php`, `ConfirmarDoisFactoresSuperAdminAction.php`, `DesactivarDoisFactoresSuperAdminAction.php`, `VerificarDesafioDoisFactoresAction.php`
- Create `Modules/Plataforma/app/Support/DesafioDoisFactores.php` (desafio pendente na sessão), `LimitadorDesafioPlataforma.php`
- Create `Modules/Plataforma/app/Http/Controllers/Auth/DesafioDoisFactoresController.php`, `Modules/Plataforma/app/Http/Controllers/SegurancaController.php`
- Create `Modules/Plataforma/app/Http/Requests/ConfirmarSenhaRequest.php`, `CodigoDoisFactoresRequest.php`, `DesafioDoisFactoresRequest.php`
- Modify `Modules/Plataforma/app/Http/Controllers/Auth/LoginController.php`, `Concerns/AuditaSemFalhar.php`, `Modules/Plataforma/routes/web.php`
- Create `Modules/Plataforma/app/Console/ReporDoisFactoresSuperAdminCommand.php` e registá-lo onde `RedefinirSuperAdminCommand` está registado
- Create `Modules/Plataforma/resources/js/Pages/DesafioDoisFactores.vue`, `Seguranca.vue`; Modify `Components/LayoutPlataforma.vue`
- Tests: `Modules/Plataforma/tests/Feature/DoisFactoresPlataformaTest.php`, `ReporDoisFactoresCommandTest.php`
- Docs: `docs/2fa.md`, `ROADMAP.md` (linha F9)

---

### Task 1: Colunas e modelo

**Files:**
- Create: migração acima
- Modify: `Modules/Plataforma/app/Models/SuperAdmin.php`
- Test: `Modules/Plataforma/tests/Feature/DoisFactoresPlataformaTest.php`

**Interfaces:**
- Produces: `SuperAdmin` com `hasEnabledTwoFactorAuthentication(): bool` (trait Fortify; exige `two_factor_secret` não nulo **e** `two_factor_confirmed_at` não nulo, porque o `confirm` do Fortify está activo), `recoveryCodes(): array`, `replaceRecoveryCode(string): void`, `twoFactorQrCodeSvg(): string`.

- [ ] **Step 1: Teste (falha)** — `test_super_admin_tem_colunas_de_2fa_e_nao_as_expoe`: cria um `SuperAdmin`, faz `forceFill` de `two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at`; assert `hasEnabledTwoFactorAuthentication()` é `true`; assert `array_keys($admin->toArray())` não contém `two_factor_secret` nem `two_factor_recovery_codes`. Segundo teste `test_2fa_sem_confirmar_nao_conta_como_activo`: só `two_factor_secret` preenchido → `false`.
- [ ] **Step 2: Correr** `php artisan test --filter=DoisFactoresPlataformaTest` → FAIL (coluna inexistente).
- [ ] **Step 3: Migração** `Schema::table('super_admins')` com `two_factor_secret` (`text`, nullable), `two_factor_recovery_codes` (`text`, nullable), `two_factor_confirmed_at` (`timestamp`, nullable); `down()` remove-as. Copiar o estilo de `Modules/Usuario/database/migrations/2026_03_31_145507_add_two_factor_columns_to_users_table.php`.
- [ ] **Step 4: Modelo** — `use TwoFactorAuthenticatable;` (Fortify); acrescentar `two_factor_secret` e `two_factor_recovery_codes` a `$hidden`; cast `two_factor_confirmed_at => 'datetime'`.
- [ ] **Step 5: Correr** o filtro → PASS. `git add` dos ficheiros.

---

### Task 2: Activar e desactivar (backend)

**Files:**
- Create: as 3 Actions de gestão, `SegurancaController`, `ConfirmarSenhaRequest`, `CodigoDoisFactoresRequest`
- Modify: `AuditaSemFalhar.php` (parâmetro opcional `?SuperAdmin $autor = null` no fim de `auditar()`, usado em vez de `$request->user('plataforma')` quando dado), `routes/web.php`
- Test: `DoisFactoresPlataformaTest.php`

**Interfaces:**
- Produces (Actions, todas com `LogService` + `comLog` como as existentes):
  - `IniciarDoisFactoresSuperAdminAction::executar(SuperAdmin $admin): array{qr: string, chave: string}` — grava `two_factor_secret` cifrado (`encrypt($provider->generateSecretKey())`), limpa `confirmed_at` e códigos; recusa (`DomainException`) se já está activo.
  - `ConfirmarDoisFactoresSuperAdminAction::executar(SuperAdmin $admin, string $codigo): array` — valida com `TwoFactorAuthenticationProvider::verify`; ao acertar grava `confirmed_at` e 8 códigos (`RecoveryCode::generate()`, cifrados em JSON) e devolve-os em claro **uma única vez**; código errado lança `ValidationException` (`code`).
  - `DesactivarDoisFactoresSuperAdminAction::executar(SuperAdmin $admin): void` — põe as 3 colunas a `null`.
- Rotas (grupo autenticado, mais `throttle:6,1`): `GET /seguranca` (`plataforma.seguranca`, Inertia `Plataforma/Seguranca`, prop `doisFactores: {activo: bool}`), `POST /seguranca/dois-factores` (`plataforma.seguranca.iniciar`, JSON `{qr, chave}`), `POST /seguranca/dois-factores/confirmar` (JSON `{codigos: string[]}`), `DELETE /seguranca/dois-factores` (204). Iniciar e desactivar exigem `password` (senha actual, `Hash::check`; errada → 422 com erro em `password`). Auditoria: `super_admin.2fa_activado` (ao confirmar) e `super_admin.2fa_desactivado`, `codigoTenant` nulo.

- [ ] **Step 1: Testes (falham)**, via HTTP com `entrarNoPainel`/`noPainel` e cabeçalhos JSON: `test_iniciar_exige_a_senha_actual` (errada → 422 `password`); `test_iniciar_devolve_qr_e_chave_e_guarda_o_segredo_cifrado_sem_confirmar` (coluna ≠ chave em claro, `confirmed_at` nulo); `test_confirmar_com_codigo_errado_falha_e_nao_activa`; `test_confirmar_com_codigo_certo_activa_e_devolve_8_codigos_uma_vez` (usar `Google2FA::getCurrentOtp(decrypt($admin->two_factor_secret))`; assert auditoria `super_admin.2fa_activado` sem segredo no `detalhe`); `test_desactivar_exige_senha_e_limpa_as_colunas` (+ auditoria); `test_pagina_seguranca_nao_expoe_segredo` (props Inertia sem `two_factor`); `test_iniciar_com_2fa_ja_activo_e_recusado`.
- [ ] **Step 2: Correr** → FAIL (rotas inexistentes).
- [ ] **Step 3: Implementar** Requests, Actions, `SegurancaController` fino (só delega e audita via `AuditaSemFalhar`) e rotas, conforme as Interfaces.
- [ ] **Step 4: Correr** o filtro → PASS. `git add`.

---

### Task 3: Desafio no login

**Files:**
- Create: `Support/DesafioDoisFactores.php`, `Support/LimitadorDesafioPlataforma.php`, `VerificarDesafioDoisFactoresAction.php`, `DesafioDoisFactoresController.php`, `DesafioDoisFactoresRequest.php`
- Modify: `LoginController::store`, `routes/web.php` (rotas fora do grupo `auth:plataforma`, ao lado de `/login`)
- Test: `DoisFactoresPlataformaTest.php`

**Interfaces:**
- Produces:
  - `DesafioDoisFactores::abrir(Session $s, SuperAdmin $a): void` (chave de sessão `plataforma.desafio` com id e validade de 5 min); `::adminPendente(Session $s): ?SuperAdmin` (devolve `null` e limpa a chave se: não há pendente, expirou, a conta já não existe/está inactiva, ou já não tem 2FA activo); `::fechar(Session $s): void`.
  - `LimitadorDesafioPlataforma` com `segundosDeBloqueio(SuperAdmin, Request): ?int`, `registarFalha(SuperAdmin, Request): void`, `limpar(SuperAdmin, Request): void`; 5 falhas/minuto por conta+IP, chaves com prefixo `p|desafio:`.
  - `VerificarDesafioDoisFactoresAction::executar(SuperAdmin $admin, ?string $codigo, ?string $codigoDeRecuperacao): bool` — TOTP via `TwoFactorAuthenticationProvider::verify(decrypt(secret), codigo)` (já impede replay); recuperação via `hash_equals` sobre `$admin->recoveryCodes()` e, ao acertar, `$admin->replaceRecoveryCode($codigo)` (consome-o).
- Rotas: `GET /desafio` (`plataforma.desafio`, Inertia `Plataforma/DesafioDoisFactores`; sem pendente → redirect `plataforma.login`), `POST /desafio` (`plataforma.desafio.store`; body `code` **ou** `recovery_code`).
- Comportamento: `LoginController::store`, depois de `autenticar`, se `$admin->hasEnabledTwoFactorAuthentication()` → `DesafioDoisFactores::abrir` + redirect `plataforma.desafio` (sem `AbrirSessaoDoSuperAdminAction`); senão, como hoje. No POST: pendente inválido → redirect login; bloqueado → erro com os segundos; falha → `registarFalha`, auditoria `super_admin.2fa_desafio_falhado` (autor = o admin pendente, via parâmetro novo de `auditar`), erro em `code`/`recovery_code`; sucesso → `limpar`, `fechar`, `AbrirSessaoDoSuperAdminAction`, redirect `plataforma.inicio`.

- [ ] **Step 1: Testes (falham)** — `test_conta_sem_2fa_entra_como_hoje`; `test_conta_com_2fa_vai_para_o_desafio_sem_sessao_aberta` (GET `/plataforma` seguinte redirecciona ao login); `test_codigo_certo_abre_a_sessao_e_regenera_o_id`; `test_codigo_errado_nao_abre_e_audita_a_falha`; `test_codigo_totp_repetido_e_recusado` (usar o mesmo código em dois logins completos); `test_codigo_de_recuperacao_serve_uma_so_vez`; `test_cinco_falhas_bloqueiam_o_desafio` (6.ª tentativa com código certo também recusada); `test_desafio_sem_pendente_volta_ao_login` (GET e POST); `test_desafio_expirado_e_recusado` (`Carbon::setTestNow` +6 min); `test_conta_desactivada_entre_passos_e_recusada`; `test_2fa_nao_confirmado_nao_pede_desafio`; `test_desafio_so_aceita_super_admin_e_nao_toca_no_login_da_escola` (rota `/two-factor-challenge` do Fortify não abre sessão da Plataforma).
- [ ] **Step 2: Correr** → FAIL.
- [ ] **Step 3: Implementar** conforme Interfaces/Comportamento; controller fino, lógica em Action/Support.
- [ ] **Step 4: Correr** o filtro e depois `php artisan test Modules/Plataforma` → PASS (os testes de login existentes não podem partir). `git add`.

---

### Task 4: Reposição por comando

**Files:**
- Create: `Console/ReporDoisFactoresSuperAdminCommand.php`; registar junto de `RedefinirSuperAdminCommand` (procurar com `grep -rn RedefinirSuperAdminCommand Modules/Plataforma app`)
- Test: `Modules/Plataforma/tests/Feature/ReporDoisFactoresCommandTest.php`

**Interfaces:**
- Consumes: `DesactivarDoisFactoresSuperAdminAction::executar(SuperAdmin): void` (Task 2).
- Produces: comando `mosi:plataforma:admin:2fa-reset {--email=}` (pergunta o e-mail se faltar, como o `RedefinirSuperAdminCommand`); e-mail inexistente → erro e `FAILURE`; sem 2FA activo → aviso e `SUCCESS` sem alterar; sucesso grava auditoria `super_admin.2fa_reposto` com autor nulo e `detalhe` `['super_admin_id' => id]`.

- [ ] **Step 1: Testes (falham)**: `test_repoe_o_2fa_e_audita_com_autor_nulo`; `test_email_inexistente_falha_sem_alterar_nada`; `test_sem_2fa_activo_avisa_e_nao_audita`; `test_depois_da_reposicao_o_login_nao_pede_desafio`.
- [ ] **Step 2: Correr** → FAIL. **Step 3: Implementar** (comando sem lógica, delega na Action; auditar com `RegistarAuditoriaAction`). **Step 4: Correr** → PASS. `git add`.

---

### Task 5: Interface

**Files:**
- Create: `Pages/DesafioDoisFactores.vue`, `Pages/Seguranca.vue`
- Modify: `Components/LayoutPlataforma.vue` (item "Segurança" ao lado de "Terminar sessão" ou na navegação, com o mesmo estilo dos outros)

- [ ] **Step 1: `DesafioDoisFactores.vue`** — copiar a lógica de `Modules/Autenticacao/resources/js/Pages/DesafioDoisFactores.vue` (código ↔ código de recuperação), a postar em `/plataforma/desafio` e com o mesmo layout/marca de `Pages/Login.vue` da Plataforma (não o `PainelDeMarca` da escola).
- [ ] **Step 2: `Seguranca.vue`** (layout `LayoutPlataforma`, card como `AlterarSenha.vue`) — fluxo de `Modules/Autenticacao/resources/js/Components/DoisFactores.vue` adaptado aos 4 endpoints da Task 2 (senha → QR + chave → código → mostrar os códigos de recuperação uma vez com aviso para os guardar; desactivar pede senha). Sem o banner de sugestão. Cancelar = `btn-light-danger`. Pedidos com `window.axios` e o CSRF da Plataforma (ver `Composables/useCsrfPlataforma.js` e como `Sistema.vue` faz os POST).
- [ ] **Step 3: Compilar** `npm run build` → sem erros.
- [ ] **Step 4: Fluxo do ecrã por HTTP**: acrescentar a `DoisFactoresPlataformaTest` um teste `test_fluxo_completo_com_os_payloads_do_vue` (login → iniciar → confirmar → terminar sessão → login → desafio com código → entra → desactivar). Correr → PASS. `git add`.

---

### Task 6: Documentação e verificação final

- [ ] **Step 1:** Acrescentar a `docs/2fa.md` a secção "Super admin (Fase 2)": opcional, onde activar (`/plataforma/seguranca`), comando `mosi:plataforma:admin:2fa-reset`, chaves de auditoria. Actualizar a linha F9 do `ROADMAP.md` para Fase 2 feita (mantendo o formato `[x] data (...)`).
- [ ] **Step 2:** `php artisan test` (suíte completa) → sem falhas novas; reportar qualquer falha pré-existente em vez de a esconder. `git add` de tudo (sem commit).
- [ ] **Step 3:** Informar o dono de que tem de correr `php artisan migrate` e de que a validação no browser (activar, sair, entrar com código) fica por fazer.
