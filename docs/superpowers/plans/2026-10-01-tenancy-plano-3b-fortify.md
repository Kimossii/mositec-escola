# Tenancy — Plano 3b: Fortify limpo e redefinição manual de palavra-passe

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Revisto em 2026-10-01.** A versão inicial deste plano ligava a recuperação automática por e-mail (`forgot-password` / `reset-password`) ao tenant. Essa política foi **abandonada** por decisão do dono do produto (spec §10.4): não há recuperação por e-mail. O utilizador que esquece a palavra-passe contacta o Administrador da Escola, que a redefine manualmente. As antigas Tarefas 2 e 3 (páginas e fluxo de recuperação) foram substituídas pelas Tarefas 2 a 6 abaixo.

**Goal:** Deixar o Fortify ligado sem superfície de recuperação nem de registo; remover tudo o que serve a recuperação automática (broker de tokens, tabela, rotas, páginas, testes); e entregar a redefinição manual: o Administrador da Escola redefine a palavra-passe de um utilizador **do seu tenant**, com permissão própria, palavra-passe temporária aleatória mostrada uma única vez, troca obrigatória no primeiro acesso, invalidação de sessões e tokens e registo de quem redefiniu e quando.

**Architecture:**
- O `FortifyServiceProvider` fica registado (Tarefa 1, feita). Sem `Features::resetPasswords()`, sem `password_reset_tokens` e sem broker tenant-aware (Tarefa 2).
- `users` ganha `deve_alterar_senha`, `senha_redefinida_por` e `senha_redefinida_em` (migration original editada, BD recriada pelo utilizador). Um novo `Modulo::SENHA_UTILIZADOR` (slug `senha-utilizador`) com a acção `editar` dá a permissão `senha-utilizador.editar`, concedida por seed a `ADMIN_ESCOLA`.
- A escrita vive numa Action do módulo Usuario (`RedefinirSenhaUsuarioAction`); o controller só autoriza e responde. A senha temporária volta ao frontend por flash de sessão (vive um pedido) e nunca vai para logs.
- Um middleware (`ExigirTrocaDeSenha`) nos grupos `web` e `api` limita quem tem `deve_alterar_senha = true` à página/acção de troca de palavra-passe e ao logout. A troca é uma Action do módulo Usuario e uma página Inertia no módulo Autenticacao.
- O isolamento por tenant vem do `TenantScope` (binding de `User` de outro tenant dá 404); este plano prova-o por HTTP.

**Tech Stack:** PHP 8.2, Laravel 12, Laravel Fortify 3.x, Laravel Sanctum, Inertia + Vue 3, PHPUnit 11, SQLite em memória nos testes.

**Spec:** `docs/superpowers/specs/2026-09-30-fundacao-tenancy-design.md` — §10.4 (redefinição manual), §10.5 (limitador de login), §10.6 e §22 ponto 1 (registo público desactivado), §15 (permissões). Dá seguimento ao Plano 3 (etapa 5).

**Esquema alterado:** as Tarefas 2 e 3 editam a migration original de `users` (retiram `password_reset_tokens`, acrescentam as três colunas). A BD de desenvolvimento tem de ser recriada: a Tarefa 6 pára e pede ao utilizador para correr `php artisan migrate:fresh --seed`.

## Global Constraints

- **Nunca fazer commit.** O dono do repositório faz os commits. Cada tarefa termina com `git add` dos ficheiros tocados (incluindo ficheiros apagados), nada mais. Nunca usar `git stash`.
- **Nunca escrever na BD de desenvolvimento por tinker ou SQL.** Só testes (SQLite em memória). Nunca `migrate:fresh --seed` sem confirmação do utilizador: o comando é bloqueado pelo sistema de permissões do agente; o utilizador corre-o e cola o resultado.
- Fail-closed: sem tenant resolvido, o código tenant-scoped lança `TenantNaoResolvido`.
- **Não existe recuperação automática de palavra-passe.** Nenhum código, rota, página, tabela, config de broker, notificação ou teste pode ficar a servi-la. Um teste de arquitectura/ligação afirma que `/forgot-password`, `/reset-password/{token}` e `POST /reset-password` dão 404 e que `password_reset_tokens` não existe.
- `/register` continua desactivado: `Features::registration()` fora de `config/fortify.php`; `GET`/`POST /register` dão 404.
- A rota efectiva de `POST /login` e `POST /logout` é a do módulo Autenticacao (limitador `LimitadorLogin`, chaves `t{tenant}|…`).
- **A palavra-passe temporária**: gerada sempre no servidor com `Str::password()` (nunca recebida do pedido), guardada **só com hash**, devolvida em claro **uma única vez** (flash), **nunca** em logs, exceptions, eventos, auditoria, cache nem na BD em claro. Nenhum `Log::`, `report()`, `dump` ou `info` pode receber a variável em claro.
- **Quem redefine**: só com `senha-utilizador.editar`; só utilizadores do tenant corrente; nunca a si próprio; uma conta `ADMIN_ESCOLA` só é redefinida por outro `ADMIN_ESCOLA`.
- `tenant_id` nunca em `$fillable` nem em DTOs. As três colunas novas também não vão em `$fillable`: escrevem-se com `forceFill` dentro da Action.
- Em `Modules/*/app`, `routes` e `database/seeders` não se usa `withoutGlobalScopes`, `DB::table(` (salvo as duas excepções dos geradores de sequência) nem a fachada `Cache`; nada fora do runtime de tenancy chama `definir()`/`limpar()` do `TenantContext`.
- Controllers finos: sem consulta nem regra de negócio inline; leitura em Service, escrita em Action. Em PHP, `use` no topo, nunca FQN inline.
- Reaproveitar o padrão visual existente (Metronic/Bootstrap, `Loader`, `vue-sonner`, `PainelDeMarca.vue`, menu de acções das listas de utilizadores); mensagens de erro vêm do backend.
- Reaproveitar traits do Core (`RegistaAutoria`) em vez de hooks próprios; colunas-enum ficam fora deste plano (a flag é booleana).
- Identificadores, mensagens e comentários em português.
- Comando de testes: `php artisan test`. A suite completa tem de passar no fim de cada tarefa (baseline à entrada da Tarefa 2: 841 passed, 1 incomplete; desce com a remoção dos testes de recuperação). `npm run build` tem de compilar nas tarefas que tocam em Vue.
- Nomes exactos: `RedefinirSenhaUsuarioAction`, `AlterarPropriaSenhaAction`, `ExigirTrocaDeSenha`, `Modulo::SENHA_UTILIZADOR` (slug `senha-utilizador`, acção `editar`), colunas `deve_alterar_senha`, `senha_redefinida_por`, `senha_redefinida_em`; rotas `usuario.redefinirSenha` (`PATCH /usuarios/{user}/redefinir-senha`), `senha.alterar` (`GET /alterar-senha`) e `senha.alterar.store` (`PUT /alterar-senha`); página `Autenticacao/AlterarSenha`; chave de flash `senha_temporaria`.

## Review Focus

Comportamentos que mais facilmente ficam sem teste:

1. **Isolamento**: o admin do tenant A não redefine (404) um utilizador do B, mesmo com o mesmo email; redefinir em A não altera hash, flag, tokens nem sessões do utilizador homónimo em B → Tarefa 3 e 6.
2. **A senha temporária nunca aparece em log, nem na resposta seguinte, nem na BD em claro**; é aleatória (duas redefinições dão senhas diferentes) → Tarefa 3.
3. **Invalidação real**: as sessões (`sessions.user_id`), os tokens Sanctum e o `remember_token` do alvo mudam; a senha antiga deixa de entrar; a nova entra → Tarefa 3.
4. **A flag bloqueia de facto**: com `deve_alterar_senha`, qualquer rota autenticada (web e API) menos troca e logout é bloqueada; o POST de outra rota de escrita também; depois da troca, a flag limpa e o acesso volta → Tarefa 4.
5. **Escalada de privilégio**: quem só tem `senha-utilizador.editar` não redefine um `ADMIN_ESCOLA`; ninguém redefine a própria → Tarefa 3.
6. **Recuperação por e-mail inexistente**: rotas 404, tabela inexistente, `Features::resetPasswords()` fora de `config/fortify.php` → Tarefa 2.

## Mapa de ficheiros

| Ficheiro | Responsabilidade |
|---|---|
| `bootstrap/providers.php`, `app/Providers/FortifyServiceProvider.php`, `config/fortify.php` | Fortify registado, sem reposição, sem registo, sem alteração de senha própria do Fortify (Tarefa 4) |
| `Modules/Autenticacao/app/Passwords/*`, `AutenticacaoServiceProvider.php` | **Apagar** broker e repositório; tirar o `extend('auth.password')` |
| `Modules/Autenticacao/routes/web.php`, `AutenticacaoController.php`, `Pages/EsqueciSenha.vue`, `RedefinirSenha.vue`, `Login.vue` | **Apagar** rotas, métodos, páginas e a ligação "Esqueceu a senha?" |
| `Modules/Usuario/database/migrations/2026_03_31_104100_create_users_table.php` | Sem `password_reset_tokens`; colunas novas em `users` |
| `Modules/Usuario/app/Models/User.php` | Cast e relação das colunas novas |
| `Modules/Permissao/app/Enums/Modulo.php`, `ModuloSeeder.php`, `RolePermissaoSeeder.php` | `SENHA_UTILIZADOR` + permissão a `ADMIN_ESCOLA` |
| `Modules/Usuario/app/Actions/RedefinirSenhaUsuarioAction.php`, `AlterarPropriaSenhaAction.php` | Escrita |
| `Modules/Usuario/app/Policies/UserPolicy.php` | `redefinirSenha(actor, alvo)` |
| `Modules/Usuario/app/Http/Controllers/UsuarioController.php`, `routes/web.php` | Acção `redefinirSenha` e rota |
| `Modules/Autenticacao/app/Http/Middleware/ExigirTrocaDeSenha.php`, `bootstrap/app.php` | Bloqueio por flag (web e api) |
| `Modules/Autenticacao/app/Http/Controllers/AlterarSenhaController.php`, `Requests/AlterarSenhaRequest.php`, `routes/web.php`, `Pages/AlterarSenha.vue`, `Components/PainelDeMarca.vue` | Troca obrigatória |
| `app/Http/Middleware/HandleInertiaRequests.php` | Partilha o flash `senha_temporaria` |
| Frontend das listas de utilizadores (`Modules/Usuario/resources/js/...`) | Botão e modal "Redefinir senha" |
| `tests/Feature/Tenancy/FortifyLigacaoTest.php`, `RedefinicaoSenhaIsolamentoTest.php`; testes de Usuario e Autenticacao; `TenancyEsquemaTest.php`; `RotasEscritaAutorizadasTest.php` | Ver cada tarefa |

---

### Task 1: Ligar o Fortify ao `User` do módulo e limpar o que está morto

> **Estado: executada e aprovada (2026-10-01).** O registo do `FortifyServiceProvider`, a remoção de `CreateNewUser` e dos limitadores `login` mortos mantêm-se. Só um teste e uma ligação ficam **revistos pela Tarefa 2**: o teste `test_o_contrato_de_reposicao_de_palavra_passe_esta_ligado` e o binding de `ResetUserPassword` deixam de existir (já não há reposição). A sugestão menor da revisão (afirmar também método e URI do login/logout do módulo) é aplicada na Tarefa 2.

**Files:**
- Modify: `bootstrap/providers.php`
- Modify: `app/Providers/FortifyServiceProvider.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Modify: `config/fortify.php`
- Delete: `app/Actions/Fortify/CreateNewUser.php`
- Test: `tests/Feature/Tenancy/FortifyLigacaoTest.php`

**Interfaces:**
- Consumes: `App\Actions\Fortify\{ResetUserPassword, UpdateUserPassword, UpdateUserProfileInformation}` (já usam `Modules\Usuario\Models\User`), `Modules\Autenticacao\Service\LimitadorLogin::NOME`.
- Produces: `Laravel\Fortify\Contracts\ResetsUserPasswords` ligado a `ResetUserPassword`; `RateLimiter::limiter('login')` deixa de existir; `config('fortify.limiters.login')` nulo.

- [ ] **Step 1: Escrever os testes**

`tests/Feature/Tenancy/FortifyLigacaoTest.php`:

```php
<?php

namespace Tests\Feature\Tenancy;

use App\Actions\Fortify\ResetUserPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Contracts\ResetsUserPasswords;
use Modules\Autenticacao\Http\Controllers\AutenticacaoController;
use Modules\Autenticacao\Service\LimitadorLogin;
use Tests\TestCase;

class FortifyLigacaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_o_contrato_de_reposicao_de_palavra_passe_esta_ligado(): void
    {
        $this->assertInstanceOf(ResetUserPassword::class, app(ResetsUserPasswords::class));
    }

    public function test_o_registo_publico_continua_desactivado(): void
    {
        $this->get($this->urlDoTenant($this->tenant, '/register'))->assertNotFound();
        $this->post($this->urlDoTenant($this->tenant, '/register'), [
            'name' => 'X', 'email' => 'x@example.com', 'password' => 'segredo1234', 'password_confirmation' => 'segredo1234',
        ])->assertNotFound();
    }

    public function test_login_e_logout_efectivos_sao_os_do_modulo_autenticacao(): void
    {
        $this->assertStringStartsWith(AutenticacaoController::class, app('router')->getRoutes()->getByName('login.store')->getActionName());
        $this->assertStringStartsWith(AutenticacaoController::class, app('router')->getRoutes()->getByName('logout')->getActionName());
    }

    public function test_so_existe_o_limitador_de_login_do_modulo(): void
    {
        $this->assertNull(RateLimiter::limiter('login'));
        $this->assertNotNull(RateLimiter::limiter(LimitadorLogin::NOME));
        $this->assertNull(config('fortify.limiters.login'));
    }
}
```

- [ ] **Step 2: Correr para ver falhar**

Run: `php artisan test --filter=FortifyLigacaoTest`
Expected: FAIL em `test_o_contrato_de_reposicao_de_palavra_passe_esta_ligado` (contrato não instanciável) e em `test_so_existe_o_limitador_de_login_do_modulo` (o limitador `login` existe, ou a config aponta para ele). Os outros dois passam já: registá-los fixa o comportamento actual.

- [ ] **Step 3: Registar o provider**

`bootstrap/providers.php`:

```php
<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
];
```

- [ ] **Step 4: Limpar o `FortifyServiceProvider`**

Em `app/Providers/FortifyServiceProvider.php`: remover `Fortify::createUsersUsing(CreateNewUser::class);` e o `use App\Actions\Fortify\CreateNewUser;`; remover o `RateLimiter::for('login', …)` (o limitador de login em uso é o `LimitadorLogin`, definido no módulo Autenticacao) e, com ele, os `use` que ficarem sem uso (`Str`, `Limit`, e `Request`/`RateLimiter` só se deixarem de ser usados: o limitador `two-factor` continua e precisa deles). Manter as outras ligações (`updateUserProfileInformationUsing`, `updateUserPasswordsUsing`, `resetUserPasswordsUsing`, `redirectUserForTwoFactorAuthenticationUsing`) e o limitador `two-factor`.

Em `app/Providers/AppServiceProvider.php`: remover o `RateLimiter::for('login', …)` duplicado (manter o `api`).

Em `config/fortify.php`, em `limiters`, retirar a linha `'login' => 'login',` (o Fortify só aplica um `throttle:` quando a chave existe e não é nula).

Apagar `app/Actions/Fortify/CreateNewUser.php` (o registo está desactivado por decisão, spec §22 ponto 1; sem o provider a registá-lo, ficaria uma acção ligada mas inalcançável).

- [ ] **Step 5: Confirmar**

Run: `grep -rn "CreateNewUser\|RateLimiter::for('login'" app Modules config bootstrap` — não pode restar nenhuma referência.
Run: `php artisan test --filter=FortifyLigacaoTest` e depois `php artisan test`.
Expected: PASS em tudo. Se algum teste existente dependia do contrato por `app->bind` (os testes HTTP de recuperação do Plano 3), continua a passar: a limpeza deles é da Tarefa 3.

- [ ] **Step 6: Stage**

```bash
git add bootstrap/providers.php app config/fortify.php tests
```

---


---

### Task 2: Remover a recuperação automática e fechar a superfície do Fortify

Substitui a antiga Tarefa 2 deste plano (nunca commitada) e a Task 5 do Plano 3.

**Files:**
- Delete: `Modules/Autenticacao/app/Passwords/TokenRepositoryTenant.php`, `PasswordBrokerManagerTenant.php`
- Modify: `Modules/Autenticacao/app/Providers/AutenticacaoServiceProvider.php` (tirar o `extend('auth.password', …)` e os `use`)
- Modify: `Modules/Autenticacao/routes/web.php` (tirar `password.request` e `password.reset`)
- Modify: `Modules/Autenticacao/app/Http/Controllers/AutenticacaoController.php` (tirar `esqueciSenha` e `redefinirSenha`; o `use Request` só se ainda for usado)
- Delete: `Modules/Autenticacao/resources/js/Pages/EsqueciSenha.vue`, `RedefinirSenha.vue`
- Modify: `Modules/Autenticacao/resources/js/Pages/Login.vue` (tirar a ligação "Esqueceu a senha?"; manter o uso de `<PainelDeMarca />`)
- Keep: `Modules/Autenticacao/resources/js/Components/PainelDeMarca.vue` (reutilizado na Tarefa 4)
- Delete: `app/Actions/Fortify/ResetUserPassword.php`; Modify: `app/Providers/FortifyServiceProvider.php` (tirar `resetUserPasswordsUsing` e o `use`); Modify: `config/fortify.php` (tirar `Features::resetPasswords()`)
- Modify: `Modules/Usuario/database/migrations/2026_03_31_104100_create_users_table.php` (tirar o `Schema::create('password_reset_tokens', …)` e o `dropIfExists` correspondente)
- Delete: `Modules/Autenticacao/tests/Feature/RecuperacaoPalavraPasseTenancyTest.php`, `PaginasRecuperacaoTest.php`, `tests/Feature/Tenancy/RecuperacaoPalavraPasseHttpTest.php`
- Modify: `tests/Feature/Arquitectura/TenancyEsquemaTest.php` (tirar `password_reset_tokens` da lista e o teste/asserção da chave primária `(tenant_id, email)`)
- Modify: `tests/Feature/Tenancy/FortifyLigacaoTest.php`
- Verify (sem alterar se já estiver limpo): `config/tenancy.php` (nenhuma lista menciona `password_reset_tokens`), `config/auth.php` (fica como está: é o default do framework e o broker já não é resolvido por ninguém)

**Interfaces:**
- Produces: nenhuma rota, tabela, notificação ou binding de recuperação de palavra-passe; Fortify sem `resetPasswords`.

- [ ] **Step 1: Escrever os testes (a inverter o que existe)**

Em `FortifyLigacaoTest`, substituir `test_o_contrato_de_reposicao_de_palavra_passe_esta_ligado` por:

- `test_nao_existe_recuperacao_automatica_de_palavra_passe`: `GET /forgot-password`, `POST /forgot-password`, `GET /reset-password/abc?email=x@example.com` e `POST /reset-password` no host do tenant dão 404; nenhuma rota tem nome `password.request|email|reset|update`; `Schema::hasTable('password_reset_tokens')` é `false`; `app()->bound(ResetsUserPasswords::class)` é `false`.
- Reforçar `test_login_e_logout_efectivos_sao_os_do_modulo_autenticacao` (sugestão da revisão da Tarefa 1): além do nome, `app('router')->getRoutes()->match(Request::create('/login', 'POST'))->getActionName()` e o mesmo para `/logout` começam por `AutenticacaoController`.
- `test_nenhum_codigo_usa_o_broker_de_palavras_passe`: varre `app/` e `Modules/*/app` (excluindo vendor e este teste) e falha se encontrar `Password::broker`, `Password::sendResetLink`, `Password::reset(`, `auth.password`, `PasswordBroker` ou `sendPasswordResetNotification`; impede regressão para o broker antigo. `config/auth.php` mantém o default do framework.
- Manter `test_o_registo_publico_continua_desactivado` e `test_so_existe_o_limitador_de_login_do_modulo`.

- [ ] **Step 2: Correr para ver falhar**

Run: `php artisan test --filter=FortifyLigacaoTest`
Expected: FAIL em `test_nao_existe_recuperacao_automatica_de_palavra_passe` (as rotas e a tabela ainda existem).

- [ ] **Step 3: Remover**

Apagar/editar os ficheiros listados. No fim, `grep -rniE "forgot-password|reset-password|password\.(request|reset|email|update)|password_reset|PasswordBroker|TokenRepositoryTenant|ResetUserPassword|ResetsUserPasswords|EsqueciSenha|RedefinirSenha\b" app Modules config bootstrap database routes tests resources` não pode devolver nada (excepto este teste, que as nomeia para as proibir, e a Task 5 histórica do Plano 3 em `docs/`).

- [ ] **Step 4: Correr os testes e a suite**

Run: `php artisan test --filter=FortifyLigacaoTest` e depois `php artisan test`
Expected: PASS; a suite desce dos 841 por causa dos 11 testes removidos (5 + 2 + 4) e sobe pelo teste novo.

- [ ] **Step 5: Verificação por mutação**

Repor temporariamente `Features::resetPasswords()` em `config/fortify.php`: `test_nao_existe_recuperacao_automatica_de_palavra_passe` tem de falhar. Reverter.

- [ ] **Step 6: Stage**

`git add -A` dos ficheiros tocados (incluindo os apagados). Sem commit.

---

### Task 3: Redefinição manual de palavra-passe (esquema, permissão, Action, endpoint)

**Files:**
- Modify: `Modules/Usuario/database/migrations/2026_03_31_104100_create_users_table.php` (em `users`: `deve_alterar_senha` boolean default `false`; `senha_redefinida_por` FK nullable para `users` com `nullOnDelete`; `senha_redefinida_em` timestamp nullable)
- Modify: `Modules/Usuario/app/Models/User.php` (cast `deve_alterar_senha` boolean e `senha_redefinida_em` datetime; relação `senhaRedefinidaPor()`; **não** em `$fillable`)
- Modify: `Modules/Permissao/app/Enums/Modulo.php` (`SENHA_UTILIZADOR = 16`, slug `senha-utilizador`, rótulo), `database/seeders/ModuloSeeder.php` e `RolePermissaoSeeder.php` (`ADMIN_ESCOLA` => `['editar']`); procurar tudo o que enumera módulos (matriz de permissões no frontend, testes que contam módulos, `RotasPermissaoReconhecidaTest`) e acompanhar
- Create: `Modules/Usuario/app/Actions/RedefinirSenhaUsuarioAction.php`
- Create: um model fino para a tabela `sessions` (sem `PertenceAoTenant`: `sessions` é infra-estrutura, ver spec §10.2), no módulo Autenticacao, para apagar sessões por `user_id` sem `DB::table(`; registar na lista de infra-estrutura do teste de esquema se este o exigir
- Modify: `Modules/Usuario/app/Policies/UserPolicy.php` (`redefinirSenha`)
- Modify: `Modules/Usuario/app/Http/Controllers/UsuarioController.php` e `Modules/Usuario/routes/web.php`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php` (`flash.senha_temporaria`)
- Test: `Modules/Usuario/tests/Feature/RedefinirSenhaUsuarioTest.php`

**Interfaces:**
- Consumes: `TokenDeAcesso` (`$user->tokens()`), `RegistaAutoria`, `PermissionResolver`/`can:`, `TenantScope`.
- Produces: `RedefinirSenhaUsuarioAction::executar(User $alvo, User $autor): string` (devolve a senha temporária em claro, e só isso); rota `PATCH /usuarios/{user}/redefinir-senha` (nome `usuario.redefinirSenha`, `can:senha-utilizador.editar`); flash de sessão `senha_temporaria` = `['user_id' => int, 'nome' => string, 'senha' => string]`.

- [ ] **Step 1: Escrever os testes**

`RedefinirSenhaUsuarioTest` (nomes indicativos; cada um prova uma regra):
- `test_admin_redefine_a_senha_de_um_utilizador_do_seu_tenant`: 302 de volta; `deve_alterar_senha` passa a `true`; `senha_redefinida_por` = admin; `senha_redefinida_em` ≈ agora; hash mudou; `Hash::check` da senha do flash confirma; a coluna `password` não contém a senha em claro.
- `test_a_senha_temporaria_e_aleatoria_e_diferente_em_cada_redefinicao`.
- `test_a_senha_temporaria_aparece_uma_so_vez`: o pedido seguinte (GET da lista) não tem `flash.senha_temporaria`.
- `test_a_senha_temporaria_nao_e_registada_em_logs`: `Log::listen` recolhe mensagem e contexto de tudo o que for logado durante o pedido; a senha não aparece em nenhum.
- `test_sessoes_tokens_e_remember_token_sao_invalidados`: inserir linhas em `sessions` para o alvo e para outro utilizador (driver `database` só nesse teste ou via o model fino) e criar tokens Sanctum; depois da redefinição o alvo não tem sessões nem tokens, o `remember_token` mudou, e os do outro utilizador ficam intactos.
- `test_senha_antiga_deixa_de_entrar_e_a_temporaria_entra`: login por `POST /login` (campo `login`).
- `test_funciona_para_utilizador_so_com_matricula_sem_email`.
- `test_sem_permissao_da_403`: perfil sem `senha-utilizador.editar` (ex.: FUNCIONARIO) recebe 403; nada muda.
- `test_nao_redefine_utilizador_de_outro_tenant`: 404; hash e flag do alvo em B intactos; com o **mesmo email** em A e B, redefinir o de A não toca no de B.
- `test_nao_redefine_a_propria_senha`.
- `test_so_admin_redefine_conta_de_admin`: utilizador com a permissão mas sem perfil `ADMIN_ESCOLA` não redefine um `ADMIN_ESCOLA`; outro `ADMIN_ESCOLA` redefine.
- `test_admin_inicial_recebe_a_permissao_por_seed`: `RolePermissaoSeeder` concede `senha-utilizador.editar` a `ADMIN_ESCOLA` e a mais ninguém.

- [ ] **Step 2: Correr para ver falhar**

Run: `php artisan test --filter=RedefinirSenhaUsuarioTest`
Expected: FAIL (colunas, módulo, rota e Action ainda não existem).

- [ ] **Step 3: Implementar**

Esquema, enum e seeders conforme os ficheiros acima. Policy: `redefinirSenha(User $autor, User $alvo)` nega se `$autor->is($alvo)` e, se o alvo tiver o perfil `ADMIN_ESCOLA`, exige o mesmo no autor (usar o helper de perfil que o projecto já tenha; o placeholder `isAdministrador` não é fonte fiável).

A Action, dentro de `DB::transaction`:
1. gera `$senha = Str::password(14)`;
2. `forceFill` do alvo: `password` (hash), `deve_alterar_senha = true`, `senha_redefinida_por = $autor->id`, `senha_redefinida_em = now()`, `remember_token = Str::random(60)`; `save()` (a trait `RegistaAutoria` preenche `editado_por`);
3. `$alvo->tokens()->delete()`;
4. apaga as sessões do alvo pelo model fino;
5. devolve `$senha`. Não loga, não dispara evento com a senha.

Controller: `$this->authorize('redefinirSenha', $user)`; `$senha = $action->executar($user, $request->user())`; `return back()->with('senha_temporaria', [...])`. `HandleInertiaRequests` passa a partilhar `flash.senha_temporaria` (closure, como o `success`). A rota entra em `RotasEscritaAutorizadasTest` pelo `can:` (nada a isentar).

- [ ] **Step 4: Correr os testes e a suite**

Run: `php artisan test --filter=RedefinirSenhaUsuarioTest` e `php artisan test`

- [ ] **Step 5: Verificação por mutação**

Cada uma tem de fazer falhar pelo menos um teste (e reverter): tirar o `tokens()->delete()`; tirar o apagar das sessões; tirar a rotação do `remember_token`; tirar a regra "nunca a si próprio"; tirar a regra de admin; guardar a senha em claro; pôr a senha no log.

- [ ] **Step 6: Stage**

`git add` dos ficheiros tocados. Sem commit. (Esquema alterado: a BD local só é recriada na Tarefa 6.)

---

### Task 4: Troca obrigatória de palavra-passe

**Files:**
- Create: `Modules/Autenticacao/app/Http/Middleware/ExigirTrocaDeSenha.php`; Modify: `bootstrap/app.php` (no grupo `web`, **antes** de `ExigirConfiguracaoInicial`; no grupo `api`, depois do `EnsureFrontendRequestsAreStateful`)
- Create: `Modules/Autenticacao/app/Http/Controllers/AlterarSenhaController.php`, `Http/Requests/AlterarSenhaRequest.php`; Modify: `Modules/Autenticacao/routes/web.php` (`senha.alterar`, `senha.alterar.store`, grupo `auth`)
- Create: `Modules/Usuario/app/Actions/AlterarPropriaSenhaAction.php`
- Create: `Modules/Autenticacao/resources/js/Pages/AlterarSenha.vue` (usa `PainelDeMarca`, `Loader`, ligação de logout)
- Delete/Modify (a alteração de senha do Fortify é substituída): `app/Actions/Fortify/UpdateUserPassword.php`, `Features::updatePasswords()` em `config/fortify.php`, o `updateUserPasswordsUsing` no `FortifyServiceProvider`; apagar `PasswordValidationRules` se ficar sem uso (senão reaproveitar as regras na Action); remover `user/password` das `ISENTAS` de `tests/Feature/RotasEscritaAutorizadasTest.php` e acrescentar `alterar-senha` se o teste o pedir
- Test: `Modules/Autenticacao/tests/Feature/TrocaObrigatoriaDeSenhaTest.php`

**Interfaces:**
- Consumes: `deve_alterar_senha` (Tarefa 3), `Inertia::location('/')` como no login.
- Produces: o utilizador com `deve_alterar_senha = true` só alcança `senha.alterar`, `senha.alterar.store` e `logout`; `AlterarPropriaSenhaAction::executar(User $user, string $nova): void`.

- [ ] **Step 1: Escrever os testes**

- `test_login_com_senha_temporaria_leva_a_troca`: depois do login, `GET /` redirecciona para `/alterar-senha`.
- `test_com_a_flag_activa_so_a_troca_e_o_logout_estao_acessiveis`: GET de várias páginas (dashboard, `/usuarios`, `/estabelecimento`) redirecciona; um POST/PATCH de escrita noutra rota é bloqueado e não executa; `logout` e `GET /alterar-senha` respondem.
- `test_api_bloqueia_pedidos_enquanto_a_flag_estiver_activa`: pedido autenticado por token a uma rota da API devolve 403 JSON; sem flag, normal.
- `test_troca_limpa_a_flag_e_devolve_o_acesso`: `PUT /alterar-senha` com senha actual (a temporária), nova senha e confirmação válidas; `deve_alterar_senha` passa a `false`; o hash muda; `GET /` já não redirecciona.
- `test_validacoes_da_troca`: senha actual errada, confirmação diferente, regra mínima de `Password::default()` e **nova igual à actual** falham com erros por campo e a flag mantém-se.
- `test_troca_roda_o_remember_token_e_mantem_a_sessao_actual`.
- `test_administrador_inicial_sem_flag_nao_e_afectado` e `test_utilizador_sem_flag_pode_aceder_a_troca_voluntaria` (a página não exige a flag).
- `test_o_tenant_da_flag_e_o_do_utilizador`: a flag de um utilizador em A não afecta o homónimo em B.
- `test_a_troca_obrigatoria_tem_prioridade_sobre_a_configuracao_inicial`: administrador com flag e estabelecimento por configurar vai primeiro a `/alterar-senha`.

- [ ] **Step 2: Correr para ver falhar**

Run: `php artisan test --filter=TrocaObrigatoriaDeSenhaTest`

- [ ] **Step 3: Implementar**

Middleware: se `$request->user()` tem `deve_alterar_senha`, e a rota não está na lista **explícita** de permitidas (`senha.alterar`, `senha.alterar.store`, `logout`, logout da API e os endpoints mínimos que o fluxo precise para funcionar: por exemplo os ficheiros estáticos/Inertia da própria página, nunca rotas de negócio). Um utilizador com a flag nunca pode ficar sem conseguir trocar a senha nem terminar sessão (teste dedicado), responde: pedido web → `redirect()->route('senha.alterar')` (Inertia → `Inertia::location`, se necessário); API (`expectsJson()`/grupo `api`) → 403 JSON com mensagem em português. Controller fino: GET renderiza `Autenticacao/AlterarSenha` (`rootView('layouts.guest')`); PUT valida no FormRequest (`current_password`, `password` com `Password::default()`, `confirmed`, `different:current_password`), chama a Action e termina com `Inertia::location('/')` (a vista de raiz muda, como no login). Action: `forceFill` de `password` (hash), `deve_alterar_senha = false`, `remember_token` novo; `save()`. Nada de lógica no controller.

Frontend: `AlterarSenha.vue` com o mesmo esqueleto do `Login.vue`; submete para `PUT /alterar-senha` com erros por campo vindos do backend.

- [ ] **Step 4: Correr os testes, `npm run build` e a suite**

- [ ] **Step 5: Verificação por mutação**

Tirar o middleware do grupo `web`; tirar do grupo `api`; deixar de limpar a flag; deixar passar `different:`: cada uma falha pelo menos um teste. Reverter.

- [ ] **Step 6: Stage**

---

### Task 5: Interface de redefinição nas listas de utilizadores

**Files:**
- Frontend das listas de utilizadores (5 páginas: Alunos, Professores, Funcionários, Administradores, Encarregados): acção "Redefinir senha" no menu de acções de cada linha, reaproveitando o menu, ícones e modais existentes (procurar o equivalente já no projecto antes de estilizar); um componente de modal partilhado em `Components/` (subpasta da entidade, como nos outros módulos)
- Modify: model/serviço JS do utilizador (`Models/Usuario.js`) para `PATCH /usuarios/{id}/redefinir-senha`
- Test: PHP já cobre o backend (Tarefa 3); aqui é `npm run build` e a verificação manual descrita no passo 4

**Interfaces:**
- Consumes: `usuario.redefinirSenha`, `flash.senha_temporaria`, a lista de permissões partilhada (`permissoes`) para mostrar/esconder a acção.

- [ ] **Step 1: Comportamento**

- A acção só aparece a quem tem `senha-utilizador.editar` e não aparece na linha do próprio utilizador (nem para contas de admin, se quem vê não for admin).
- Confirmação antes de enviar ("A senha actual deixará de funcionar e as sessões do utilizador serão terminadas"); botão com `Loader` pequeno enquanto envia.
- No sucesso, abre o modal com nome do utilizador, a senha temporária (fonte monoespaçada, botão "Copiar") e o aviso de que só é mostrada **agora** e de que o utilizador terá de a alterar no primeiro acesso.
- Fechar o modal descarta a senha do estado do componente; não é guardada em `localStorage`, store global nem URL; reabrir não a volta a mostrar.
- Erros 403/404 do backend aparecem pelo canal habitual (`errors`), sem texto de fallback no frontend.

- [ ] **Step 2: Implementar** reaproveitando as classes do tema (cartões internos com `bg-body-secondary`, não `bg-white`), verificar o estilo com `getComputedStyle` e não só por imagem.

- [ ] **Step 3: `npm run build` compila.**

- [ ] **Step 4: Verificação manual (após a Tarefa 6, BD recriada):** redefinir um utilizador de teste pelo formulário real, copiar a senha, fechar o modal, confirmar que não volta, entrar com a senha temporária noutra janela e ser forçado à troca.

- [ ] **Step 5: Stage**

---

### Task 6: Matriz de isolamento, fecho e BD de desenvolvimento

**Files:**
- Create: `tests/Feature/Tenancy/RedefinicaoSenhaIsolamentoTest.php`
- Modify: `tests/Feature/Tenancy/IdentidadeIsolamentoTest.php` (acrescentar a linha da redefinição à matriz), se aplicável
- Modify: `tests/Feature/Tenancy/FortifyLigacaoTest.php` (se faltar algum caso)
- Teste do limitador de login da API por tenant em `LimiteTentativasLoginTest.php` (mantém-se do plano anterior: falhas em A não bloqueiam B)

- [ ] **Step 1: Teste de ponta a ponta (RedefinicaoSenhaIsolamentoTest)**

Dois tenants, o **mesmo email** em ambos: (a) admin A redefine o utilizador de A: a flag, o hash, as sessões e os tokens de B não mudam; (b) admin A recebe 404 ao tentar o de B; (c) o utilizador de A entra com a senha temporária e é forçado à troca; o de B entra com a senha antiga, sem troca; (d) `senha_redefinida_por` aponta para o admin de A e nunca para um utilizador de B; (e) `/forgot-password`, `/reset-password/x`, `POST /reset-password` e `/register` dão 404 nos dois hosts; (f) falhas de login em A não bloqueiam o login em B (limitador por tenant, web e API).

- [ ] **Step 2: Suite completa, `npm run build`, e o grep proibitivo do Step 3 da Tarefa 2 mais `grep -rn "senha_temporaria" app Modules` (só pode aparecer onde se escreve e lê o flash).**

- [ ] **Step 3: BD de desenvolvimento.** `users` mudou: **parar e pedir ao utilizador para correr** `php artisan migrate:fresh --seed`. Depois da confirmação dele, verificar por HTTP com cookie jar (sem escrever na BD por fora da app): `/login` nos três hosts; login do admin → `/estabelecimento`; `/register`, `/forgot-password` e `/reset-password/x` dão 404; o admin tem `senha-utilizador.editar` (rota `PATCH /usuarios/{id}/redefinir-senha` responde ao admin e não a um perfil sem a permissão).

- [ ] **Step 4: Stage** e relatório final.

## Auto-revisão

**Cobertura das decisões de 2026-10-01:**
- Sem `forgot-password`/`reset-password` (código, rotas, tabela, testes) → Tarefa 2.
- Só dentro do tenant do administrador → Tarefa 3 (404 cross-tenant) e Tarefa 6.
- Senha temporária aleatória, só com hash, mostrada uma vez, nunca em logs → Tarefa 3 (testes) e Tarefa 5 (UI).
- `deve_alterar_senha` obriga a troca no primeiro login e limita ao fluxo de troca → Tarefa 4.
- Redefinição invalida sessões e tokens Sanctum → Tarefa 3.
- `senha_redefinida_por` / `senha_redefinida_em` → Tarefa 3.
- Permissão própria, dada ao admin inicial por seed, não acoplada ao perfil → Tarefa 3 (`Modulo::SENHA_UTILIZADOR`, `RolePermissaoSeeder`).
- Utilizadores sem email → Tarefa 3 (teste).
- Registo continua desactivado e limitador de login por tenant → Tarefas 1, 2 e 6.

**Regras confirmadas pelo dono (2026-10-01):** `ADMIN_ESCOLA` pode redefinir outro `ADMIN_ESCOLA`; ninguém redefine a própria senha por este fluxo; tenant + permissão sempre verificados. Utilizadores criados pelo formulário com senha digitada pelo administrador não recebem `deve_alterar_senha`. `profile-information` e 2FA do Fortify mantêm-se nesta fase. A API devolve 403 JSON quando a troca é obrigatória (excepto logout) e não ganha endpoint de alteração.

**Fica deliberadamente fora:**
- **Etapa 9 (provisioning):** o administrador inicial criado por `CriarTenantAction` recebe senha temporária aleatória mostrada uma vez a quem corre o comando, com `deve_alterar_senha = true` (spec §16.4). Aqui só se constroem a coluna e o fluxo de troca; o seeder de desenvolvimento mantém a flag a `false`.
- Utilizadores criados pelo formulário com a senha digitada pelo administrador **não** passam a ter a flag (decisão não pedida); se se quiser, é uma alteração pequena em `UsuarioAction::criar`.
- Superfície restante do Fortify sem interface (`user/profile-information`, 2FA): não se tocam; avaliar na etapa 11.
- Histórico de redefinições (só a última fica registada), notificação ao utilizador e bloqueio por exceder redefinições: fora do âmbito.
