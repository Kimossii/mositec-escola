# Email: consumo, subscrição e domínio Resend — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Quota mensal de emails opcionais por plano (configurável pelo super admin), com consumo medido por escola, avisos e bloqueio opcional, e verificação de domínio Resend por escola.

**Architecture:** A subscrição é a licença existente: o plano (`licenciamento_planos`) e a licença emitida ganham as regras de email, que viajam no token assinado e são lidas no Core por um contrato novo (mesmo padrão de `ModulosDisponiveis`/`GarantiaDeLimites`, com implementação «sem limites» por omissão). O envio de opcionais passa por um serviço de consumo no `shouldSend` de `NotificacaoDeTenant`. O domínio Resend vive em `configuracoes_email` atrás de uma interface com implementação real e simulada.

**Tech Stack:** Laravel 12, módulos nwidart (Core, Licenciamento, Plataforma), Inertia + Vue 3, PHPUnit (`php artisan test`), SQLite em testes.

**Spec:** [docs/superpowers/specs/2026-11-28-email-consumo-subscricao-resend-design.md](../specs/2026-11-28-email-consumo-subscricao-resend-design.md)

## Global Constraints

- Uma tabela/alteração por migração; prefixo `2026_11_28_`, na pasta `database/migrations` do módulo dono.
- Controllers finos: leitura em `Services`, escrita em `Actions`; nada de query ou regra no controller. `use` no topo, nunca FQN inline.
- Colunas-enum inteiras têm irmã `*_descricao` (hook `saving` no Model ou trait `SincronizaEstadoDescricao`).
- Essenciais **nunca** consultam a quota. Só opcionais contam e são bloqueados.
- Mês civil em UTC (`AAAA-MM`). Aviso uma só vez por mês e limiar.
- Valores omissos: `limite_emails_mes` nulo = sem limite; `emails_aviso_pct` = 80; `emails_bloqueia_ao_exceder` = verdadeiro.
- Licença continua formato v1; campos novos opcionais (chaves desconhecidas já são ignoradas).
- Instalação própria (sem tenant) não aplica quota.
- Texto de UI em português (pt-PT). **Só `git add`; nunca `git commit`** (o dono commita).
- Cada tarefa termina com a suite do módulo tocado verde; a última corre `php artisan test` completo.

## Review Focus

- Escola sem licença/limite nulo: continua a enviar opcionais como hoje.
- Quota exactamente igual ao consumo (100%): o envio que atinge o 100% sai; o seguinte é bloqueado.
- Virada de mês: o contador do mês novo começa a 0 e os avisos podem repetir.
- Envios concorrentes ao limite: o contador não passa a quota por corrida (incremento atómico).
- Bloqueio por quota não pode contar como consumo nem como falha do SMTP; fica registado com motivo próprio.
- Domínio Resend: sem chave real nada activa; `verificado` só vem do cliente, nunca do formulário.

---

## Fatia 1 — Regras no plano e na licença

### Task 1: Colunas de email no plano e na licença emitida

**Files:**
- Create: `Modules/Licenciamento/database/migrations/2026_11_28_000001_add_regras_email_to_licenciamento_planos_table.php`
- Create: `Modules/Licenciamento/database/migrations/2026_11_28_000002_add_regras_email_to_licenciamento_licencas_table.php`
- Modify: `Modules/Licenciamento/app/Models/Plano.php` (fillable/casts), `Modules/Licenciamento/app/Models/LicencaEmitida.php` (fillable/casts)
- Test: `Modules/Licenciamento/tests/Feature/RegrasDeEmailNoPlanoTest.php`

**Interfaces:**
- Produces: colunas `limite_emails_mes` (unsignedInteger null), `emails_aviso_pct` (unsignedTinyInteger, default 80), `emails_bloqueia_ao_exceder` (boolean, default true) em ambas as tabelas (`licenciamento_planos`, tabela de `LicencaEmitida`).

- [ ] **Step 1:** Teste `test_plano_guarda_regras_de_email_com_omissoes`: criar `Plano` só com campos antigos; afirmar `limite_emails_mes === null`, `emails_aviso_pct === 80`, `emails_bloqueia_ao_exceder === true`. Idem para `LicencaEmitida` (via factory/helper já usado em `EmitirLicencaTest`).
- [ ] **Step 2:** Correr `php artisan test --filter=RegrasDeEmailNoPlanoTest` → falha (colunas em falta).
- [ ] **Step 3:** Duas migrações (uma por tabela) e actualizar `$fillable`/`$casts` (`emails_bloqueia_ao_exceder` → `boolean`).
- [ ] **Step 4:** Correr o teste → passa.
- [ ] **Step 5:** `git add` dos ficheiros acima.

### Task 2: Validação e emissão com as regras de email

**Files:**
- Modify: `Modules/Licenciamento/app/Services/ValidaDireitosComerciais.php` (`CHAVES_LIMITES` ganha `emails_mes`; validar `aviso_pct` 1–100)
- Modify: `Modules/Licenciamento/app/DTOs/PlanoDTO.php` (campos `emailsAvisoPct`, `emailsBloqueia`; `limites` aceita `emails_mes`)
- Modify: `Modules/Licenciamento/app/Actions/CriarPlanoAction.php`, `AtualizarPlanoAction.php`, `EmitirLicencaAction.php`
- Test: `Modules/Licenciamento/tests/Feature/EmitirLicencaTest.php` (acrescentar) e `PlanosTest.php` (acrescentar)

**Interfaces:**
- Consumes: Task 1.
- Produces: `ValidaDireitosComerciais::erros(...)` rejeita `emails_mes` negativo/> `MAX_LIMITE` e `emails_aviso_pct` fora de 1–100; `EmitirLicencaAction` copia as três regras do plano (ou do DTO) para a `LicencaEmitida` e para o token.

- [ ] **Step 1:** Testes: `test_emitir_licenca_copia_regras_de_email_do_plano`; `test_aviso_pct_invalido_e_recusado` (0 e 101 → erro `emails_aviso_pct`); `test_emails_mes_negativo_e_recusado`.
- [ ] **Step 2:** Correr → falham.
- [ ] **Step 3:** Implementar. `emails_mes` vai em `limites` (mesma chave do token `lim`); `aviso_pct` e `bloqueia` seguem como parâmetros próprios com os valores omissos das Global Constraints.
- [ ] **Step 4:** Correr `php artisan test Modules/Licenciamento` → passa.
- [ ] **Step 5:** `git add`.

### Task 3: Token assinado transporta as regras; leitura em `DireitosDaLicenca`

**Files:**
- Modify: `Modules/Licenciamento/app/Documento/DireitosDaLicenca.php` (`LIMITES` ganha `emails_mes`; claims novos `eap` (int 1–100) e `ebl` (bool); propriedades `emailsAvisoPct` (default 80) e `emailsBloqueia` (default true) no fim do construtor, com defaults)
- Test: `Modules/Licenciamento/tests/Feature/DireitosServiceTest.php` (acrescentar)

**Interfaces:**
- Produces: `DireitosDaLicenca::limiteEmailsMes(): ?int`, `->emailsAvisoPct: int`, `->emailsBloqueia: bool`; `paraClaims()` emite `eap`/`ebl`; `deClaims()` aceita a sua ausência (licença antiga → sem limite, 80, true) e rejeita `eap` fora de 1–100 ou `ebl` não-booleano com `DocumentoInvalido`.

- [ ] **Step 1:** Testes: round-trip `paraClaims`→`deClaims` preserva os três valores; claims sem `eap`/`ebl`/`lim.emails_mes` dão os omissos; `eap=0` e `ebl='x'` lançam `DocumentoInvalido`.
- [ ] **Step 2:** Correr → falham.
- [ ] **Step 3:** Implementar mantendo `VERSAO_FORMATO = 1`.
- [ ] **Step 4:** Correr `php artisan test Modules/Licenciamento` → passa (incluindo os testes de assinatura existentes).
- [ ] **Step 5:** `git add`.

### Task 4: Ecrã Planos do super admin

**Files:**
- Modify: `Modules/Plataforma/app/Http/Requests/GuardarPlanoRequest.php`, `Modules/Plataforma/app/Http/Controllers/PlanoController.php` (só passa dados às Actions)
- Modify: `Modules/Plataforma/resources/js/Pages/Planos/Form.vue`, `Planos/Index.vue` (coluna «Emails/mês»)
- Test: `Modules/Plataforma/tests/Feature/PlanosEmailHttpTest.php`

**Interfaces:**
- Consumes: Task 2 (DTO/Actions).
- Produces: campos de formulário `limite_emails_mes` (vazio = «Sem limite»), `emails_aviso_pct` (1–100, por omissão 80), `emails_bloqueia_ao_exceder` (interruptor).

- [ ] **Step 1:** Testes HTTP como super admin: criar plano com `limite_emails_mes=500`, `emails_aviso_pct=90`, bloqueia=false e ver persistido; `emails_aviso_pct=0` → erro de validação no campo; editar plano mantém valores; `Index` devolve a quota.
- [ ] **Step 2:** Correr → falham.
- [ ] **Step 3:** Implementar Request (regras: `nullable|integer|min:0|max:` `DireitosDaLicenca::MAX_LIMITE`; `integer|between:1,100`; `boolean`), controller e Vue (reaproveitar as classes dos campos de limite já presentes no Form, `form-control-solid`, mensagens de erro `text-danger fs-7`).
- [ ] **Step 4:** Correr `php artisan test Modules/Plataforma` e `npm run build` (ou o build de dev do projecto) sem erros.
- [ ] **Step 5:** `git add`.

---

## Fatia 2 — Consumo, decisão no envio e avisos

### Task 5: Contrato Core e implementação no Licenciamento

**Files:**
- Create: `Modules/Core/app/Licenciamento/Contracts/RegrasDeEmailDaLicenca.php` (interface), `Modules/Core/app/Licenciamento/Contracts/SemRegrasDeEmail.php` (omissão: tudo nulo/sem limite)
- Create: `Modules/Core/app/Comunicacao/Email/RegrasDeEmail.php` (DTO readonly)
- Create: `Modules/Licenciamento/app/Services/RegrasDeEmailService.php` (implementa o contrato, lê `DireitosService::doTenantActual()`)
- Modify: service providers de Core e Licenciamento (bind; seguir como `ModulosDisponiveis`/`SemLimites` estão ligados)
- Test: `Modules/Licenciamento/tests/Feature/RegrasDeEmailServiceTest.php`

**Interfaces:**
- Produces: `RegrasDeEmail { ?int $limiteMes; int $avisoPct; bool $bloqueia }`; `RegrasDeEmailDaLicenca::doTenantActual(): RegrasDeEmail`. Sem licença, `config('licenciamento.activo')` falso ou sem tenant → `new RegrasDeEmail(null, 80, true)`.

- [ ] **Step 1:** Testes: com licença `emails_mes=100,eap=90,ebl=false` devolve esses valores; sem licença devolve sem limite; `SemRegrasDeEmail` (Core isolado) devolve sem limite.
- [ ] **Step 2:** Correr → falham.
- [ ] **Step 3:** Implementar; o Core não importa nada de Licenciamento.
- [ ] **Step 4:** Correr `php artisan test Modules/Licenciamento Modules/Core` → passa.
- [ ] **Step 5:** `git add`.

### Task 6: Tabela e serviço de consumo mensal

**Files:**
- Create: `Modules/Core/database/migrations/2026_11_28_100000_create_consumo_email_mensal_table.php` (`tenant_id` FK restrictOnDelete, `mes` char(7), `opcionais` unsignedInteger default 0, `aviso_enviado_pct` boolean default false, `bloqueio_avisado` boolean default false, timestamps; único `(tenant_id, mes)`)
- Create: `Modules/Core/app/Models/ConsumoEmailMensal.php` (`PertenceAoTenant`, como `EnvioEmail`)
- Create: `Modules/Core/app/Comunicacao/Email/ConsumoDeEmail.php`
- Test: `Modules/Core/tests/Feature/Comunicacao/ConsumoDeEmailTest.php`

**Interfaces:**
- Produces: `ConsumoDeEmail::tentarConsumir(): ResultadoConsumo` (enum `ResultadoConsumo { PERMITIDO, PERMITIDO_NO_AVISO, PERMITIDO_NO_LIMITE, BLOQUEADO }`); `::doMes(?string $mes = null): int`. Incremento atómico (`UPDATE ... SET opcionais = opcionais + 1 WHERE ... AND (limite nulo OR opcionais < limite)` dentro de transacção; cria a linha do mês se faltar com upsert).
- Semântica: `PERMITIDO_NO_AVISO` na primeira vez que `opcionais*100/limite >= avisoPct`; `PERMITIDO_NO_LIMITE` quando o envio atinge exactamente o limite; `BLOQUEADO` quando já está no limite **e** `bloqueia`; se `bloqueia=false` nunca bloqueia (continua a contar).

- [ ] **Step 1:** Testes (com `RegrasDeEmail` simulada por binding): sem limite → sempre `PERMITIDO` e conta; limite 10/aviso 80: 8.º envio devolve `PERMITIDO_NO_AVISO` uma só vez; 10.º devolve `PERMITIDO_NO_LIMITE`; 11.º `BLOQUEADO` e o contador fica em 10; `bloqueia=false` não bloqueia; mudança de mês (`Carbon::setTestNow`) recomeça a 0 e permite novo aviso; dois tenants não se misturam.
- [ ] **Step 2:** Correr → falham.
- [ ] **Step 3:** Implementar `tentarConsumir` com transacção e bloqueio de linha (`lockForUpdate`) — o limite não pode ser ultrapassado por corrida.
- [ ] **Step 4:** Correr → passa.
- [ ] **Step 5:** `git add`.

### Task 7: Aplicar a quota no envio de opcionais

**Files:**
- Modify: `Modules/Core/app/Comunicacao/Email/EstadoEnvioEmail.php` (novo caso `DESCARTADO_QUOTA = 4`, com `label()` «Descartado por quota»), `NotificacaoDeTenant.php` (`shouldSend`)
- Test: `Modules/Core/tests/Feature/Comunicacao/QuotaDeEmailNoEnvioTest.php`

**Interfaces:**
- Consumes: Tasks 5–6.
- Regra no `shouldSend`, depois da verificação de contrato e **antes** do limite por hora: só para `CategoriaEmail::OPCIONAL`, chamar `ConsumoDeEmail::tentarConsumir()`; `BLOQUEADO` → `RegistoDeEnvios::descartado(..., DESCARTADO_QUOTA)` e `return false`. Essenciais saltam este passo.

- [ ] **Step 1:** Testes: opcional dentro da quota sai; opcional acima da quota não sai e fica `DESCARTADO_QUOTA` em `envios_email`; **essencial acima da quota sai sempre**; escola sem limite não é afectada; descartar por quota não incrementa o contador.
- [ ] **Step 2:** Correr → falham.
- [ ] **Step 3:** Implementar; nova linha de migração **não** é precisa (`estado` é inteiro; `estado_descricao` já sincroniza no hook do `EnvioEmail`) — confirmar e, se o hook usar mapa fixo, acrescentar o caso.
- [ ] **Step 4:** Correr `php artisan test Modules/Core` → passa.
- [ ] **Step 5:** `git add`.

### Task 8: Avisos de aviso e de bloqueio

**Files:**
- Create: `Modules/Core/app/Notifications/QuotaDeEmailNoAvisoNotificacao.php`, `QuotaDeEmailEsgotadaNotificacao.php` (categoria **ESSENCIAL**, estendem `NotificacaoDeTenant`)
- Create: `Modules/Core/app/Comunicacao/Email/AvisadorDeQuota.php`
- Modify: `NotificacaoDeTenant::shouldSend` (chamar o avisador conforme o `ResultadoConsumo`)
- Test: `Modules/Core/tests/Feature/Comunicacao/AvisosDeQuotaTest.php`

**Interfaces:**
- Produces: `AvisadorDeQuota::avisar(ResultadoConsumo $r, int $consumo, ?int $limite): void` — notifica os administradores da escola (utilizadores com perfil administrador, via o mecanismo que `RecuperarSenhaNotificacao`/avisos de admin já usam) e regista o evento para o super admin em `plataforma_auditoria`/`envios_email`. Idempotente por mês: usa `aviso_enviado_pct`/`bloqueio_avisado` de `consumo_email_mensal`.

- [ ] **Step 1:** Testes: `PERMITIDO_NO_AVISO` gera 1 notificação por admin e não repete no mesmo mês; `PERMITIDO_NO_LIMITE`/`BLOQUEADO` geram o aviso de esgotada uma só vez; aviso é essencial (sai mesmo com a quota esgotada); conteúdo sem dados sensíveis.
- [ ] **Step 2:** Correr → falham.
- [ ] **Step 3:** Implementar; textos pt-PT curtos com consumo, limite e mês.
- [ ] **Step 4:** Correr `php artisan test Modules/Core` → passa.
- [ ] **Step 5:** `git add`.

---

## Fatia 3 — Ecrãs de consumo

### Task 9: Consumo no painel da escola e na página da escola (Plataforma)

**Files:**
- Create: `Modules/Core/app/Services/ConsumoDeEmailConsultaService.php` (leitura: mês actual, limite, %, estado `normal|aviso|esgotado`)
- Modify: `Modules/Plataforma/app/Http/Controllers/EscolaController.php`, `EmailEscolaController.php` (usam o serviço), Vue da página da escola e `Pages/Escolas/…` (barra de consumo reaproveitando o padrão visual das barras/badges existentes)
- Modify: ecrã `/configuracao-email` (controlador e Vue do Core/Autenticação que o serve): bloco só leitura «Consumo do mês»
- Test: `Modules/Plataforma/tests/Feature/ConsumoEmailEscolaHttpTest.php`, `Modules/Core/tests/Feature/ConfiguracaoEmailHttpTest.php` (acrescentar)

**Interfaces:**
- Consumes: Tasks 5–6.
- Produces: prop Inertia `consumoEmail: { mes, usado, limite|null, percentagem|null, estado }`.

- [ ] **Step 1:** Testes: super admin vê o consumo da escola; admin da escola vê o seu e **não** o de outra (isolamento por tenant); sem limite devolve `limite=null`, `estado=normal`.
- [ ] **Step 2:** Correr → falham.
- [ ] **Step 3:** Implementar serviço + controllers finos + Vue; `bg-body-secondary` nos cartões internos; verificar nos dois temas e em largura de telemóvel (< 768px).
- [ ] **Step 4:** Correr testes dos módulos tocados; build do front.
- [ ] **Step 5:** `git add`.

---

## Fatia 4 — Domínio Resend

### Task 10: Colunas de domínio e interface do cliente

**Files:**
- Create: `Modules/Core/database/migrations/2026_11_28_200000_add_dominio_to_configuracoes_email_table.php` (`dominio` string null, `dominio_estado` unsignedTinyInteger null + `dominio_estado_descricao`, `dominio_registos` json null, `dominio_verificado_em` timestamp null; a coluna `caminho` já existente, se houver, passa a admitir `dominio`)
- Create: `Modules/Core/app/Comunicacao/Email/Dominio/ClienteDeDominios.php` (interface), `ClienteResend.php`, `ClienteSimulado.php`, `EstadoDominio.php` (enum `POR_VERIFICAR=0, VERIFICADO=1, COM_FALHA=2`)
- Modify: `Modules/Core/app/Models/ConfiguracaoEmail.php`, service provider (bind: `ClienteResend` se `services.resend.key` preenchida, senão `ClienteSimulado`)
- Test: `Modules/Core/tests/Feature/Comunicacao/ClienteDeDominiosTest.php`

**Interfaces:**
- Produces: `ClienteDeDominios::registar(string $dominio): array` (lista de registos DNS `{tipo, nome, valor}`); `::verificar(string $dominio): EstadoDominio`. `ClienteResend` usa `Http::` contra a API do Resend (endpoints `/domains`) com a chave de `services.resend.key`; nunca regista a chave em logs.

- [ ] **Step 1:** Testes com `Http::fake` para `ClienteResend` (registar devolve registos; verificar mapeia `verified`/`pending`/`failed`) e para `ClienteSimulado` (determinista).
- [ ] **Step 2:** Correr → falham.
- [ ] **Step 3:** Implementar. Escolher o domínio recusa endereços vazios, IPs e domínios do próprio sistema (`config('app.url')`).
- [ ] **Step 4:** Correr `php artisan test Modules/Core` → passa.
- [ ] **Step 5:** `git add`.

### Task 11: Fluxo de verificação e activação por domínio

**Files:**
- Create: `Modules/Core/app/Actions/RegistarDominioDeEmailAction.php`, `VerificarDominioDeEmailAction.php` (usam `ClienteDeDominios`; escrevem auditoria como as Actions de `configuracoes_email` existentes)
- Modify: controller/Request/Vue de `/configuracao-email` (aba/secção «Domínio verificado»: registar, mostrar registos SPF/DKIM com botão copiar, «Verificar»)
- Modify: `MailerDaEscola` (escola com `caminho=dominio` e `dominio_estado=VERIFICADO` envia pelo mailer `resend` com From no domínio da escola; sem fallback para a central) e `ConfiguracaoEmail::estaEmUso()`
- Test: `Modules/Core/tests/Feature/ConfiguracaoEmailDominioHttpTest.php`

**Interfaces:**
- Consumes: Task 10.
- Regra: `verificado` só é escrito por `VerificarDominioDeEmailAction` a partir do cliente; o formulário nunca o envia. Permissão `configuracao-email` (`editar`) obrigatória; super admin pode forçar regresso à central (já existe).

- [ ] **Step 1:** Testes HTTP: registar domínio devolve registos; verificar com cliente simulado `VERIFICADO` activa e `MailerDaEscola` usa o mailer do domínio; `COM_FALHA` não activa e fica visível; tentar enviar `dominio_estado=1` no pedido é ignorado; sem permissão → 403; isolamento por tenant.
- [ ] **Step 2:** Correr → falham.
- [ ] **Step 3:** Implementar; mensagens de erro sem segredos; auditar quem registou/verificou.
- [ ] **Step 4:** Correr `php artisan test Modules/Core` e build do front.
- [ ] **Step 5:** `git add`.

### Task 12: Verificação final e documentação

**Files:**
- Modify: `docs/notificacoes-email.md`, `docs/manual-utilizador.md`, `README.md` (se citarem limites), spec `2026-11-26-notificacoes-email-design.md` (secção «Adiado» passa a «Feito»)
- Modify: memória `project_notificacoes_email.md` (estado e decisões)

- [ ] **Step 1:** `php artisan test` (suite completa) → 0 falhas; anotar totais.
- [ ] **Step 2:** `vendor/bin/pint --test` (ou o linter do projecto) → sem diferenças.
- [ ] **Step 3:** Percurso manual por Playwright/Chrome (login `admin@mositec.gmail.com`): criar plano com quota 2, emitir licença, disparar 3 opcionais e ver o 3.º descartado, avisos criados, essencial ainda a sair; **sem gravar dados reais**.
- [ ] **Step 4:** Actualizar documentação e memória.
- [ ] **Step 5:** `git add` de tudo; **não commitar**.

## Self-review

- Spec: §1 → Tasks 1–4; §2 → 6; §3 → 5, 7; §4 → 8; §5 → 4, 9; §6 → 10–11; fatias → cabeçalhos; Tabelas → Tasks 1, 6, 10.
- Tipos: `RegrasDeEmail(limiteMes, avisoPct, bloqueia)` e `ResultadoConsumo` usados com os mesmos nomes nas Tasks 5–8; `EstadoEnvioEmail::DESCARTADO_QUOTA` só na Task 7.
- Pontos que o construtor tem de confirmar no código: como o hook `estado_descricao` de `EnvioEmail` mapeia casos (Task 7); como os administradores da escola são seleccionados nos avisos existentes (Task 8); onde vive o controller de `/configuracao-email` (Tasks 9 e 11).
