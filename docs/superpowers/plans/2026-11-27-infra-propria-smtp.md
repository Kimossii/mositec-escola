# Infra própria de email por escola (SMTP) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cada escola configura o seu servidor SMTP, verifica-o com um teste de envio real e, depois de verificado, todos os seus emails saem por ele.

**Architecture:** Uma tabela `configuracoes_email` (1:1 com `tenants`) no Core. Um serviço `MailerDaEscola` regista, em tempo de execução, um mailer SMTP com os dados da escola; `NotificacaoDeTenant::mensagemBase()` escolhe-o quando a configuração está verificada e activa. Actions finas para guardar, testar e desactivar; ecrã Inertia na escola; botão e cartão na Plataforma.

**Tech Stack:** Laravel 12, Symfony Mailer (smtp), Inertia + Vue 3, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-11-26-notificacoes-email-design.md` (secções «Infra própria» e «Infra própria V1: SMTP»).

## Global Constraints

- Uma tabela por migração (nunca agrupar). Colunas-enum inteiras têm irmã `*_descricao`, sincronizada no hook `saving` do Model.
- Controllers finos: Service para leitura, Action para escrita. `use` no topo, nunca FQN inline.
- Reaproveitar `RegistaAutoria` (Core\Traits) e `PertenceAoTenant` (Core\Tenancy). Auditoria da escola por `RegistarAuditoriaTenantAction` + `AuditoriaTenantDTO`; nunca com a senha.
- Portas SMTP permitidas: 25, 465, 587, 2525. IPs privados, loopback e link-local recusados (resolvendo o nome) excepto com `config('tenancy.modo') === 'unico'`.
- Teste de envio: 5 por hora por escola. `tempo_limite` 5 a 30 s, por omissão 15.
- Senha cifrada (cast `encrypted`), em `$hidden`, nunca no Inertia, logs ou auditoria. Em branco ao editar = manter.
- Alterar campo de ligação ou do remetente volta `estado` a «por verificar» e `activo` a falso. Sem teste bem-sucedido não activa. Sem fallback à central depois de verificada e activa.
- Permissão nova: módulo `configuracao-email` = 37, acções `ver` e `editar`, Administrador por defeito (seed em `SincronizarPerfisDeSistemaAction`, `ModuloSeeder`, `menusDosModulos.js`).
- Responder e escrever em português. Só `git add`; nunca `git commit`.

## Review Focus

- Servidor SMTP que resolve para IP privado (ex.: `localhost`, `10.0.0.5`, `169.254.169.254`): recusado fora do modo único.
- Servidor sem autenticação (utilizador e senha vazios): aceita, não envia credenciais.
- Editar só o nome do remetente com a senha em branco: mantém a senha e volta a «por verificar».
- Configuração `com falha` ou `por verificar`: o envio usa a central, não a infra da escola.
- Falha de envio pela infra própria: fica em `envios_email` com canal «Infra própria da escola» e a excepção continua a subir (a fila repete).
- Resposta Inertia nunca contém `senha` nem o valor cifrado.

---

## Slice 1: base de dados e envio

### Task 1: Tabela, enums e Model

**Files:**
- Create: `Modules/Core/database/migrations/2026_11_27_100000_create_configuracoes_email_table.php`
- Create: `Modules/Core/app/Comunicacao/Email/EncriptacaoSmtp.php`
- Create: `Modules/Core/app/Comunicacao/Email/EstadoConfiguracaoEmail.php`
- Create: `Modules/Core/app/Models/ConfiguracaoEmail.php`
- Test: `Modules/Core/tests/Feature/Comunicacao/ConfiguracaoEmailModelTest.php`

**Interfaces:**
- Produces: `enum EncriptacaoSmtp: int { NENHUMA=0; STARTTLS=1; SSL=2; label(): string }`; `enum EstadoConfiguracaoEmail: int { POR_VERIFICAR=0; VERIFICADO=1; COM_FALHA=2; label(): string }`; Model `ConfiguracaoEmail` (usa `PertenceAoTenant`, `RegistaAutoria`; tabela `configuracoes_email`) com casts `encriptacao`→enum, `estado`→enum, `senha`→`encrypted`, `activo`/`validar_certificado`→bool, `verificada_em`→datetime; `$hidden = ['senha']`; método `public function estaEmUso(): bool` (= `activo && estado === VERIFICADO`).

- [ ] **Step 1: Escrever o teste.** `test_senha_fica_cifrada_na_base_e_escondida_na_serializacao` (valor na BD ≠ texto limpo, `toArray()` sem `senha`); `test_estado_descricao_e_encriptacao_descricao_sincronizam_ao_gravar`; `test_um_tenant_so_pode_ter_uma_configuracao` (segunda criação lança `QueryException`); `test_estaEmUso_so_com_verificado_e_activo` (3 combinações).
- [ ] **Step 2: Correr e ver falhar.** `php artisan test Modules/Core/tests/Feature/Comunicacao/ConfiguracaoEmailModelTest.php` → FAIL (classe inexistente).
- [ ] **Step 3: Migração.** Colunas: `id`, `tenant_id` (FK `tenants`, `restrictOnDelete`, `unique`), `servidor` string, `porta` unsignedSmallInteger, `encriptacao` tinyint + `encriptacao_descricao` string, `utilizador` nullable, `senha` text nullable, `validar_certificado` bool default true, `dominio_ehlo` nullable, `tempo_limite` unsignedTinyInteger default 15, `remetente_nome` nullable, `remetente_endereco` string, `responder_para` nullable, `estado` tinyint default 0 + `estado_descricao` default 'Por verificar', `activo` bool default false, `verificada_em` timestamp nullable, `ultimo_erro` string(500) nullable, `criado_por`/`editado_por` (FK `users`, `nullOnDelete`, nullable, seguir `envios`/outras tabelas com autoria), timestamps.
- [ ] **Step 4: Enums e Model** com o hook `saving` a preencher as duas `*_descricao` (como `EnvioEmail`).
- [ ] **Step 5: Correr o teste.** Expected: PASS.
- [ ] **Step 6: `git add` dos ficheiros acima.**

### Task 2: Validador do servidor SMTP

**Files:**
- Create: `Modules/Core/app/Comunicacao/Email/ValidadorDeServidorSmtp.php`
- Test: `Modules/Core/tests/Feature/Comunicacao/ValidadorDeServidorSmtpTest.php`

**Interfaces:**
- Produces: `class ValidadorDeServidorSmtp { public const PORTAS = [25, 465, 587, 2525]; public function erro(string $servidor, int $porta): ?string }` — devolve mensagem em português ou `null` se aceitável. Resolução DNS injectável: construtor `__construct(private ?Closure $resolver = null)`, por omissão `gethostbynamel`.

- [ ] **Step 1: Escrever o teste** (resolver falso): `smtp.gmail.com` + 587 → `null`; porta 80 → erro de porta; `localhost` e `127.0.0.1`, `10.0.0.5`, `192.168.1.10`, `172.16.0.1`, `169.254.169.254`, `::1` → erro de endereço privado; nome que não resolve → erro «não foi possível resolver»; com `config(['tenancy.modo' => 'unico'])` os privados passam mas a porta continua validada.
- [ ] **Step 2: Falhar.** `php artisan test --filter=ValidadorDeServidorSmtpTest` → FAIL.
- [ ] **Step 3: Implementar `erro()`** com `filter_var(..., FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)` sobre cada IP resolvido (qualquer IP recusado recusa o servidor).
- [ ] **Step 4: Passar.** Expected: PASS.
- [ ] **Step 5: `git add`.**

### Task 3: Envio pela infra da escola

**Files:**
- Create: `Modules/Core/app/Comunicacao/Email/MailerDaEscola.php`
- Modify: `Modules/Core/app/Comunicacao/Email/NotificacaoDeTenant.php` (`mensagemBase()`)
- Modify: `Modules/Core/app/Comunicacao/Email/RemetenteTenant.php` (`resolver()`)
- Modify: `Modules/Core/app/Comunicacao/Email/RegistoDeEnvios.php` (canal)
- Modify: `Modules/Core/app/Comunicacao/Email/EmailDisponivel.php`
- Test: `Modules/Core/tests/Feature/Comunicacao/EnvioPelaInfraDaEscolaTest.php`

**Interfaces:**
- Consumes: `ConfiguracaoEmail::estaEmUso()`, Task 1.
- Produces: `class MailerDaEscola { public function configuracao(): ?ConfiguracaoEmail` (do tenant corrente, só se `estaEmUso()`); `public function nome(): ?string` (regista `config(["mail.mailers.escola" => [...]])` com `transport=smtp`, `host`, `port`, `username`, `password`, `scheme` (`smtps` se SSL, senão `smtp`), `local_domain`, `timeout`, e `verify_peer`; chama `Mail::purge('escola')`; devolve `'escola'` ou `null`); `public function canal(): CanalEnvioEmail`; `public static function configDe(ConfiguracaoEmail $c): array` (usado também pelo teste na Task 5)}`.

- [ ] **Step 1: Escrever o teste** (`config(['mail.default'=>'array'])`, mailer `escola` redirigido para `array` no teste através de `MailerDaEscola::configDe` não é possível: testar `configDe` directamente e o resto por comportamento): `test_configDe_traduz_todos_os_campos` (SSL→`smtps`, STARTTLS/nenhuma→`smtp`, `verify_peer` false quando `validar_certificado` falso, utilizador/senha nulos sem credenciais); `test_sem_configuracao_ou_nao_verificada_usa_a_central` (`nome()` null, `canal()` CENTRAL, `mensagemBase()->mailer` null); `test_verificada_e_activa_usa_o_mailer_da_escola_e_o_remetente_dela` (mailer `escola`; From = `remetente_endereco`/`remetente_nome`; Reply-To = `responder_para`); `test_envio_registado_com_canal_proprio` (`envios_email.canal` = 1, `canal_descricao` «Infra própria da escola»); `test_falha_pela_infra_propria_regista_e_relanca`; `test_EmailDisponivel_conta_a_infra_verificada_mesmo_com_mailer_log`.
- [ ] **Step 2: Falhar.**
- [ ] **Step 3: Implementar `MailerDaEscola`** e ligá-lo: `NotificacaoDeTenant::mensagemBase()` chama `->mailer($nome)` quando `nome()` não é null; `RemetenteTenant::resolver()` devolve nome/endereço/replyTo da configuração quando em uso (replyTo: `responder_para` ?? email do estabelecimento); `RegistoDeEnvios::registar()` passa `app(MailerDaEscola::class)->canal()` em vez de `CENTRAL` fixo (actualizar o comentário); `EmailDisponivel::disponivel()` devolve true também se `MailerDaEscola::configuracao() !== null` (só com tenant resolvido; sem tenant mantém a regra actual).
- [ ] **Step 4: Passar** o teste novo e `php artisan test Modules/Core/tests/Feature/Comunicacao Modules/Autenticacao` (nada regride). Expected: PASS.
- [ ] **Step 5: `git add`.**

---

## Slice 2: permissão, Actions e ecrã

### Task 4: Módulo de permissões `configuracao-email`

**Files:**
- Modify: `Modules/Permissao/app/Enums/Modulo.php` (`CONFIGURACAO_EMAIL = 37`; `acoesAplicaveis` → `['ver','editar']`; slug `configuracao-email`; label «Configuração de Email»)
- Modify: `Modules/Permissao/database/seeders/ModuloSeeder.php` (`['nome'=>37,'descricao'=>'Configuracao de Email']`)
- Modify: `Modules/Permissao/app/Actions/SincronizarPerfisDeSistemaAction.php` (Admin: `['ver','editar']`)
- Modify: `Modules/Permissao/resources/js/Support/menusDosModulos.js` (`'configuracao-email': { menu: 'Sistema', entrada: 'Email da escola' }`)
- Modify: `Modules/Permissao/tests/Feature/AcoesAplicaveisTest.php` (lista de excepções, `has('modulos', 38)`) e `ModuloSeederTest.php` (38)

- [ ] **Step 1:** actualizar os testes de contagem para 38 e a lista de excepções; correr `php artisan test Modules/Permissao/tests` → FAIL.
- [ ] **Step 2:** fazer as alterações listadas. 
- [ ] **Step 3:** `php artisan test Modules/Permissao/tests` → PASS.
- [ ] **Step 4: `git add`.**

### Task 5: Actions de escrita

**Files:**
- Create: `Modules/Core/app/Actions/Email/GuardarConfiguracaoEmailAction.php`
- Create: `Modules/Core/app/Actions/Email/TestarConfiguracaoEmailAction.php`
- Create: `Modules/Core/app/Actions/Email/DesactivarConfiguracaoEmailAction.php`
- Test: `Modules/Core/tests/Feature/Comunicacao/ConfiguracaoEmailActionsTest.php`

**Interfaces:**
- Consumes: `ValidadorDeServidorSmtp::erro`, `MailerDaEscola::configDe`, `RegistarAuditoriaTenantAction`.
- Produces: `GuardarConfiguracaoEmailAction::executar(array $dados): ConfiguracaoEmail` (cria ou actualiza a do tenant; `senha` ausente ou `''` mantém a actual; quando muda qualquer campo de ligação ou remetente → `estado=POR_VERIFICAR`, `activo=false`, `ultimo_erro=null`; audita `configuracao_email.guardada` com antes/depois **sem** `senha`, só `senha_alterada: bool`; lança `ValidationException` se o validador recusar servidor/porta); `TestarConfiguracaoEmailAction::executar(ConfiguracaoEmail $c, string $destino): ConfiguracaoEmail` (envia síncrono, com `Mail::build(configDe($c))->raw(...)`/`send`, para `$destino`; sucesso → `VERIFICADO`, `activo=true`, `verificada_em=now()`, `ultimo_erro=null`; excepção → `COM_FALHA`, `activo=false`, `ultimo_erro` = classe + mensagem curta (≤200 chars) com a senha e o utilizador removidos; audita `configuracao_email.testada` com `resultado`); `DesactivarConfiguracaoEmailAction::executar(ConfiguracaoEmail $c): void` (`activo=false`, audita `configuracao_email.desactivada`).

- [ ] **Step 1: Escrever o teste** com transporte falso (`Mail::build` substituído por `Mail::fake`/mailer `array` via binding): guardar nova; guardar sem senha mantém a antiga; editar só `remetente_nome` volta a `POR_VERIFICAR` e desliga `activo`; guardar com porta 80 ou `127.0.0.1` falha com `ValidationException`; testar com sucesso activa; testar com falha marca `COM_FALHA` e o `ultimo_erro` não contém a senha; desactivar; auditoria nunca contém a senha (procurar o texto limpo em `auditoria_tenant.detalhe`).
- [ ] **Step 2: Falhar.**
- [ ] **Step 3: Implementar** as três Actions (transacção na Guardar).
- [ ] **Step 4: Passar.**
- [ ] **Step 5: `git add`.**

### Task 6: Ecrã «Email da escola»

**Files:**
- Create: `Modules/Core/app/Http/Controllers/Comunicacao/ConfiguracaoEmailController.php` (`show`, `guardar`, `testar`, `desactivar`)
- Create: `Modules/Core/app/Http/Requests/GuardarConfiguracaoEmailRequest.php`, `TestarConfiguracaoEmailRequest.php`
- Create: `Modules/Core/app/Services/ConfiguracaoEmailConsultaService.php` (`paraEcra(): array` — nunca inclui `senha`; devolve `tem_senha: bool`)
- Create: `Modules/Core/resources/js/Pages/ConfiguracaoEmail/Index.vue`
- Modify: `Modules/Core/routes/web.php`, `resources/js/Composables/useSistemaMenu.js`, `resources/js/Components/Layout/SidebarMenuWrapper.vue`
- Test: `Modules/Core/tests/Feature/ConfiguracaoEmailHttpTest.php`

**Interfaces:**
- Rotas (todas `auth`): `GET /configuracao-email` (`can:configuracao-email.ver`, nome `configuracao-email.show`); `PUT /configuracao-email` (`can:configuracao-email.editar`); `POST /configuracao-email/testar` (`can:configuracao-email.editar`, `throttle:5,60`); `POST /configuracao-email/desactivar` (`can:configuracao-email.editar`).
- Regras do request: `servidor` required string max 255; `porta` required in `ValidadorDeServidorSmtp::PORTAS`; `encriptacao` in 0,1,2; `utilizador`/`senha` nullable string max 255; `validar_certificado` boolean; `dominio_ehlo` nullable string max 255; `tempo_limite` integer 5–30; `remetente_nome` nullable max 255; `remetente_endereco` required email; `responder_para` nullable email; teste: `destino` required email.
- Página: cartão de estado (badge por estado, `ultimo_erro`, `verificada_em`), formulário com todos os campos, aviso vermelho junto de «validar certificado» quando desligado, botão «Testar envio» (pede o email de destino), botão «Voltar à central» (btn-light-danger com confirmação), predefinições rápidas (Gmail 587 STARTTLS, Outlook 587 STARTTLS, Zoho 465 SSL, Outro) que só preenchem servidor/porta/encriptação. Mensagens de teste/guardar por `flash`. Classes e componentes como em `Pages/Auditoria/Index.vue` e `Pages/EnviosEmail/Index.vue`.

- [ ] **Step 1: Escrever o teste HTTP:** admin vê a página (`component Core/ConfiguracaoEmail/Index`, sem `senha` em nenhuma prop, `tem_senha` true/false); funcionário e professor 403 em GET e PUT; PUT válido grava e devolve ao estado «por verificar»; PUT com porta 80 → erro de validação em `porta`; `POST /testar` com sucesso (transporte falso) activa; o 6.º teste na hora → 429; `desactivar` desliga; outra escola nunca vê a configuração da primeira.
- [ ] **Step 2: Falhar.**
- [ ] **Step 3: Implementar** controller fino (Service para ler, Actions para escrever), requests, rotas, página e itens de menu (`permissao: 'configuracao-email.ver'`, «Email da escola», ao lado de «Envios de email»).
- [ ] **Step 4:** `php artisan test Modules/Core/tests/Feature/ConfiguracaoEmailHttpTest.php` → PASS; `npx vite build` → sem erros.
- [ ] **Step 5: `git add`.**

---

## Slice 3: super admin

### Task 7: Modo e estado por escola, e «Forçar regresso à central»

**Files:**
- Modify: `Modules/Plataforma/app/Services/EnviosEmailConsultaService.php` (`infraPorEscola(): array` — por tenant com configuração: `escola`, `escola_nome`, `estado`, `estado_descricao`, `activo`, `ultimo_erro`; **nunca** servidor/utilizador/senha)
- Modify: `Modules/Plataforma/app/Http/Controllers/PainelController.php`, `Modules/Plataforma/resources/js/Pages/Painel.vue` (lista «Escolas com infra própria»)
- Create: `Modules/Plataforma/app/Actions/ForcarRegressoACentralAction.php` (`executar(Tenant $tenant): void` — `activo=false` na `ConfiguracaoEmail` dessa escola, sem contexto de escola, via `withoutGlobalScopes`; audita em `plataforma_auditoria` com `RegistarAuditoriaAction`, acção `email.regresso_central_forcado`, `codigo_tenant`)
- Modify: `Modules/Plataforma/routes/web.php` (`POST /escolas/{tenant}/email/regresso-central`, nome `plataforma.escolas.email.regresso-central`), `Modules/Plataforma/resources/js/Pages/Escolas/Show.vue` (botão btn-light-danger com `ConfirmModal`, só se a escola tem infra própria activa)
- Test: `Modules/Plataforma/tests/Feature/InfraDeEmailNaPlataformaTest.php`

- [ ] **Step 1: Escrever o teste** (padrão de `FalhasDeEmailNoPainelTest`, códigos `MOSI-9xxxxx`): painel lista só escolas com configuração, sem servidor/utilizador/senha na resposta; `POST` desactiva só a escola indicada e grava auditoria da Plataforma; sem sessão de super admin é redireccionado; escola sem configuração → 404 ou no-op documentado (escolher 404).
- [ ] **Step 2: Falhar.**
- [ ] **Step 3: Implementar.**
- [ ] **Step 4:** `php artisan test Modules/Plataforma Modules/Core/tests/Feature` → PASS; `npx vite build`.
- [ ] **Step 5: `git add`.**

### Task 8: Fecho

- [ ] Actualizar a secção «Estado» de `docs/superpowers/specs/2026-11-26-notificacoes-email-design.md` e `docs/notificacoes-email.md` (como configurar, portas, regra de IPs privados).
- [ ] `php artisan test` completo (≈ 4640 testes) → 0 falhas; registar o resultado.
- [ ] Lembrar o dono: `php artisan migrate` e `php artisan db:seed --force` (ou o comando de actualizar permissões) para a tabela e o módulo 37.
- [ ] `git add -A`; sem commit.
