# Licenciamento — Fase 1 (Fundação) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Um módulo `Licenciamento` independente que emite, valida e aplica licenças assinadas (PASETO v4.public) com o mesmo mecanismo no SaaS e no self-hosted, com bloqueio por perfil, limites nas Actions e gestão no Super Admin.

**Architecture:** Duas metades no mesmo módulo. *Emissor* (planos, emissão, ciclo de vida; só activo onde há chave privada) e *Verificador* (instala e valida o documento assinado, calcula o modo, aplica limites e o gate). No SaaS o emissor grava o token no registo de direitos do tenant **pela mesma `InstalarLicencaAction`** que o administrador usa ao colar/carregar uma licença. O Licenciamento só depende do Core; os módulos de negócio fornecem contadores através de um contrato do Core, e a Plataforma chama as Actions do Licenciamento (nunca ao contrário).

**Tech Stack:** Laravel 12 / PHP ^8.4, `ext-sodium` (já presente), nwidart modules, Inertia + Vue, PHPUnit (SQLite). **Nenhuma dependência nova** (ver decisão D1).

**Spec:** `docs/superpowers/specs/2026-11-19-licenciamento-design.md` (aprovado). Contexto: `docs/superpowers/specs/2026-09-30-fundacao-tenancy-design.md` §18.

## Decisões deste plano (a rever pelo dono)

- **D1 — Codec PASETO próprio, sem pacote.** `paragonie/paseto` 3.5 exige `ext-gmp` (não instalada), `easy-ecc`, `sodium_compat`, `corner`: demasiado para usar só `v4.public`. Implementa-se `v4.public` (assinar/verificar) em ~60 linhas sobre `sodium_crypto_sign_*`, provado contra os **vectores de teste oficiais do PASETO**, atrás de uma interface para trocar por biblioteca sem tocar no resto. Continua a ser PASETO, desde a fundação.
- **D2 — Nomes de tabela com prefixo `licenciamento_`.** Já existe a tabela legado `licencas` (+ `Modules\Autenticacao\Models\Licenca`, tenancy §18). Não se toca nela.
- **D3 — Activação por flag.** `LICENCIAMENTO_ACTIVO` (env, `false` por omissão). Sem a flag, o gate e os limites são inertes: a suite existente e as escolas actuais não bloqueiam no deploy. Só os testes do módulo a ligam.
- **D4 — Separação de §18.** Planos/emissões (comercial) vs. `licenciamento_direitos` (registo local, só leitura para a app escolar). A app escolar nunca lê planos.
- **D5 — Limite "estabelecimentos" só viaja no documento, sem aplicação** (1 tenant = 1 estabelecimento, decisão fechada; não há Action de criação para aplicar).
- **D6 — "utilizadores" = contas que não são de Aluno** (alunos têm limite próprio). Decisão minha; confirmar.
- **Confirmações do dono (2026-11-19):** (a) D6 confirmado, e **limites são sempre opcionais**: no SaaS depende do plano contratado, `null` = sem limite, por plano e por licença; (b) `tolerancia_dias`/`aviso_dias` por omissão (propostos 7 e 30) são **configuráveis** (config/env) e sobreponíveis por plano e por licença; (c) módulos licenciáveis: Financeiro e Avaliação agora, mas a lista é **extensível** (`modulos_licenciaveis`) e depende do plano/licença; (d) exportação do administrador = a que já existe nos módulos; (e) execução subagent-driven.
- **Sem commits.** Cada tarefa termina com `git add` (regra do dono: commit só a pedido).

## Global Constraints

- PHP: `use` no topo, nunca FQN inline. Controllers finos (query/regra em Service/Action).
- Toda Action usa `LogService` + `comLog()` (`tests/Feature/Arquitectura/ActionsComRegistoTest.php` falha senão).
- Uma tabela por migração. Coluna-enum inteira leva irmã `*_descricao` (trait `SincronizaEstadoDescricao` do Core).
- Cada tabela nova é classificada em `config/tenancy.php` (`tabelas_globais`) e, se tiver `tenant_id`, em `GLOBAIS_COM_TENANT_ID` de `tests/Feature/Arquitectura/TenancyEsquemaTest.php`.
- Módulo só carrega se estiver em `modules_statuses.json`.
- Licenciamento importa **apenas** `Modules\Core\…` (teste de fronteira na Tarefa 1).
- Resposta de bloqueio a não-administradores: texto exacto `Ocorreu um erro. Tente mais tarde.`, sem cabeçalhos/rotas/texto reveladores.
- Instantes em UTC (`Carbon`); sem relógio do tenant (não é data civil de escola).
- UI: Cancelar sempre `btn-light-danger`; reaproveitar `ConfirmModal`/padrões existentes; usável em smartphone.
- Fora de âmbito: API de sincronização/renovação automática (Fase 4), anti-recuo do relógio e listagem de Instalações (Fase 3).

## Review Focus

- Token com um byte alterado, com `kid` desconhecido, `v4.local`/`v3.public`, ou truncado → recusado sem excepção não tratada (Tarefa 2).
- Licença ainda não iniciada (`inicio` futuro) e `expira_em` anterior ao `inicio` → bloqueado (Tarefa 3).
- Instalar uma licença **mais antiga** que a actual (replay) é recusado (Tarefa 4).
- Dois pedidos simultâneos a criar o último aluno permitido não ultrapassam o limite (Tarefa 5).
- Administrador com sessão aberta em modo bloqueado só alcança as rotas permitidas; os outros são desligados e recebem a resposta genérica (Tarefa 6).
- Com `LICENCIAMENTO_ACTIVO=false` nada muda no comportamento actual (Tarefa 6).
- Comandos agendados, filas e o painel da Plataforma continuam a funcionar em modo bloqueado (Tarefa 6).

---

### Task 1: Esqueleto do módulo, configuração e fronteira

**Files:**
- Create: `Modules/Licenciamento/{module.json,composer.json,config/config.php}`, `Modules/Licenciamento/app/Providers/LicenciamentoServiceProvider.php` (+ `RouteServiceProvider`), `config/licenciamento.php`
- Modify: `modules_statuses.json`
- Test: `tests/Feature/Arquitectura/FronteiraLicenciamentoTest.php`

**Interfaces:**
- Produces: `config('licenciamento')` com `activo` (bool, env `LICENCIAMENTO_ACTIVO`), `chaves_publicas` (`kid => base64`), `emissor.chave_privada` (env `LICENCIAMENTO_CHAVE_PRIVADA`, base64) e `emissor.kid`, `tolerancia_dias_padrao`, `aviso_dias_padrao` (valores a escolher pelo dono; propor 7 e 30), `rotas_permitidas_ao_admin` (lista de nomes de rota), `modulos_licenciaveis` (aliases).

- [ ] **Step 1: Write the failing test** — `test_licenciamento_so_importa_o_core`: varre `Modules/Licenciamento/app` e falha se houver `use Modules\X` com `X ∉ {Licenciamento, Core}`.
- [ ] **Step 2: Run** `php artisan test --filter FronteiraLicenciamentoTest` → FAIL (módulo inexistente).
- [ ] **Step 3: Criar o módulo** copiando a forma de `Modules/Plataforma` (provider estende `BaseModuleServiceProvider`; `name`/`nameLower` = `Licenciamento`/`licenciamento`); adicionar `"Licenciamento": true` a `modules_statuses.json`; criar `config/licenciamento.php` com as chaves acima (chave privada vazia por omissão).
- [ ] **Step 4: Run** o teste → PASS; `php artisan module:list` mostra o módulo activo.
- [ ] **Step 5: Stage** `git add Modules/Licenciamento config/licenciamento.php modules_statuses.json tests/Feature/Arquitectura/FronteiraLicenciamentoTest.php`

### Task 2: Codec PASETO v4.public

**Files:**
- Create: `Modules/Licenciamento/app/Assinatura/CodecoDeLicenca.php` (interface), `.../CodecoPasetoV4Publico.php`, `.../Exceptions/{DocumentoInvalido,AssinaturaInvalida,ChaveDesconhecida}.php`
- Test: `Modules/Licenciamento/tests/Unit/CodecoPasetoV4PublicoTest.php`

**Interfaces:**
- Produces:
  - `interface CodecoDeLicenca { public function assinar(array $claims, string $chavePrivada, string $kid): string; /** @param array<string,string> $chavesPublicas kid=>chave */ public function verificar(string $token, array $chavesPublicas): array; }`
  - Excepções: `DocumentoInvalido` (formato/versão/propósito/JSON), `ChaveDesconhecida` (`kid` fora do mapa), `AssinaturaInvalida`; as três estendem `LicencaInvalida` (RuntimeException base, mesmo namespace de Exceptions).

- [ ] **Step 1: Write the failing tests** (usar os vectores públicos oficiais `v4.public` do PASETO, 4-S-1 e 4-S-2, copiados para um array no teste, com a chave pública/secreta de teste do próprio vector): `verifica_os_vectores_oficiais` (payload e footer recuperados exactamente); `assina_e_verifica_ida_e_volta` (claims iguais; `kid` no footer JSON `{"kid":"k1"}`); `um_byte_alterado_no_payload_falha` → `AssinaturaInvalida`; `footer_com_kid_desconhecido_falha` → `ChaveDesconhecida`; `rejeita_v4_local_v3_public_e_lixo` → `DocumentoInvalido`; `token_truncado_ou_base64_invalido` → `DocumentoInvalido`; `o_footer_e_autenticado` (alterar o `kid` mantendo o payload falha).
- [ ] **Step 2: Run** → FAIL (classes inexistentes).
- [ ] **Step 3: Implement `CodecoPasetoV4Publico`** conforme a especificação PASETO v4.public: mensagem assinada = `PAE("v4.public.", payload, footer, implicit="")`, token = `v4.public.` + base64url(payload ‖ assinatura[64]) + `.` + base64url(footer). Usar `sodium_crypto_sign_detached`/`sodium_crypto_sign_verify_detached`, `hash_equals` onde comparar, `sodium_memzero` na chave. O `kid` vem **do footer**, verificado **antes** de confiar em qualquer claim.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Stage** `git add Modules/Licenciamento`

### Task 3: Documento da licença e avaliação do modo (puro)

**Files:**
- Create: `Modules/Licenciamento/app/Documento/DireitosDaLicenca.php` (readonly), `.../Enums/{ModoDeOperacao,Modalidade,Recurso}.php`, `.../Services/AvaliadorDeLicenca.php`
- Test: `Modules/Licenciamento/tests/Unit/AvaliadorDeLicencaTest.php`

**Interfaces:**
- Produces:
  - `final readonly class DireitosDaLicenca { public static function deClaims(array $claims): self /* @throws DocumentoInvalido */; public function paraClaims(): array; public string $id; public ?string $escola; public ?string $instalacao; public CarbonImmutable $emitidaEm, $inicio, $expiraEm; public int $toleranciaDias; public array $limites /* alunos,utilizadores,estabelecimentos => ?int, null = sem limite */; public array $modulos; public Modalidade $modalidade; public int $versaoFormato; public function bloqueiaEm(): CarbonImmutable; public function temModulo(string $alias): bool; }`
  - `enum ModoDeOperacao: string { case NORMAL; case AVISO; case BLOQUEADO; }`; `enum Modalidade: int { ONLINE=1; LOCAL=2; HIBRIDO=3 }` (label + slug como `Perfil`); `enum Recurso: string { ALUNOS='alunos'; UTILIZADORES='utilizadores' }`.
  - `AvaliadorDeLicenca::modo(?DireitosDaLicenca $d, CarbonImmutable $agora, int $avisoDias, bool $revogada = false): ModoDeOperacao` — `null`/revogada → BLOQUEADO.

- [ ] **Step 1: Write the failing tests:** `sem_licenca_e_bloqueado`; `dentro_da_validade_e_normal`; `a_aviso_dias_do_fim_e_aviso`; `expirada_dentro_da_tolerancia_e_aviso`; `apos_expira_mais_tolerancia_e_bloqueado` (borda: no instante exacto `bloqueiaEm()` ainda AVISO, 1 s depois BLOQUEADO); `tolerancia_zero_bloqueia_logo_apos_expirar`; `a_tolerancia_conta_uma_so_vez` (avaliar em `expira+tol+1d` → BLOQUEADO, nunca volta a AVISO); `inicio_no_futuro_e_bloqueado`; `deClaims_recusa_expira_antes_de_inicio`, `deClaims_recusa_versao_formato_desconhecida`, `deClaims_recusa_tolerancia_negativa`; `claims_ida_e_volta_sao_iguais`.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** as classes; claims PASETO (`iss`,`sub`,`iat`,`nbf`,`exp`, `jti`) mapeadas a `emitidaEm`/`inicio`/`expiraEm`/`id`; extras: `escola`, `instalacao`, `tol`, `lim`, `mod`, `modalidade`, `fmt`.
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Stage** `git add Modules/Licenciamento`

### Task 4: Direitos locais e instalação da licença (caminho único SaaS/self-hosted)

**Files:**
- Create: migrations `create_licenciamento_direitos_table` e `create_licenciamento_instalacao_table`; models `DireitosLocais` (global, sem `PertenceAoTenant`), `Instalacao`; `Actions/InstalarLicencaAction.php`; `Services/DireitosService.php`; `Services/CodigoDaInstalacao.php`; excepções `LicencaRecusada` (com motivo legível ao administrador)
- Modify: `config/tenancy.php` (`tabelas_globais`), `tests/Feature/Arquitectura/TenancyEsquemaTest.php` (`GLOBAIS_COM_TENANT_ID`)
- Test: `Modules/Licenciamento/tests/Feature/InstalarLicencaTest.php`, `DireitosServiceTest.php`

**Interfaces:**
- Consumes: `CodecoDeLicenca`, `DireitosDaLicenca`, `TenantContext` (Core).
- Produces:
  - `InstalarLicencaAction::executar(int $tenantId, string $codigoTenant, string $token): DireitosDaLicenca` (`@throws LicencaRecusada`)
  - `DireitosService::doTenantActual(): ?DireitosDaLicenca`, `::modo(): ModoDeOperacao` (usa `LICENCIAMENTO_ACTIVO`: inactivo → NORMAL), `::revogadaDoTenantActual(): bool`, memoizado por pedido via `TenantContext`
  - `CodigoDaInstalacao::obter(): string` (gera e guarda na 1.ª chamada; imutável)
  - `licenciamento_direitos`: `tenant_id` único (inteiro simples, **sem FK** para manter a independência), `licenca_id`, `emitida_em`, `token` (texto), `claims` (json), `revogada_em` nullable, `instalada_em`. `licenciamento_instalacao`: `codigo` único.

- [ ] **Step 1: Write the failing tests:** `instala_licenca_valida_e_guarda_direitos`; `recusa_assinatura_invalida`; `recusa_instalacao_diferente_quando_o_token_a_traz` (claim `instalacao` ≠ `CodigoDaInstalacao`); `recusa_escola_diferente_do_tenant`; `sem_claims_de_ligacao_instala_em_qualquer_sitio` (ligação desligada); `recusa_licenca_mais_antiga_que_a_actual` (replay por `emitidaEm`); `reinstalar_a_mesma_licenca_e_idempotente`; `licenca_mais_nova_substitui_a_antiga`; `codigo_da_instalacao_e_estavel_entre_chamadas`; `direitos_service_devolve_null_sem_licenca`; `modo_normal_com_flag_inactiva_mesmo_sem_licenca`; `isolamento: licenca do tenant A nao e vista no tenant B`.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** (migrações uma por tabela; o `token` completo é guardado para poder ser reverificado a cada leitura — `DireitosService` **reverifica a assinatura** ao carregar, não confia só nas colunas, para que editar a BD não conceda direitos). Chaves públicas lidas de `config('licenciamento.chaves_publicas')`.
- [ ] **Step 4: Run** → PASS; correr `TenancyEsquemaTest` → PASS.
- [ ] **Step 5: Stage** `git add Modules/Licenciamento config/tenancy.php tests/Feature/Arquitectura/TenancyEsquemaTest.php`

### Task 5: Limites aplicados nas Actions

**Files:**
- Create: `Modules/Core/app/Licenciamento/Contracts/ContaRecursoLicenciado.php`; `Modules/Licenciamento/app/Services/LimitesDeLicenca.php`; `Exceptions/LimiteDeLicencaExcedido.php`; contadores `Modules/Aluno/app/Licenciamento/ContaAlunos.php` e `Modules/Usuario/app/Licenciamento/ContaUtilizadores.php` (registados com tag `licenciamento.contadores` nos respectivos providers)
- Modify: `CriarAlunoAction` (Aluno), Actions de criação de conta de staff do Usuario (`CriarUtilizadorDocenteAction` e o caminho de criação de `UsuarioAction`/equivalente; confirmar por leitura quais criam `User` não-aluno), `Modules/Aluno/…` e `Modules/Usuario/…` Providers
- Test: `Modules/Licenciamento/tests/Feature/LimitesDeLicencaTest.php`; `Modules/Aluno/tests/Feature/LimiteDeAlunosTest.php`

**Interfaces:**
- Produces: `interface ContaRecursoLicenciado { public function recurso(): string; public function contar(): int; }` (no Core); `LimitesDeLicenca::garantir(Recurso $r, int $adicionar = 1): void` (`@throws LimiteDeLicencaExcedido`, mensagem em português dirigida ao administrador). Sem licença/flag inactiva/limite `null` → não faz nada.

- [ ] **Step 1: Write the failing tests:** `garante_dentro_do_limite_passa`; `no_limite_exacto_o_proximo_falha`; `limite_null_significa_sem_limite`; `flag_inactiva_nao_aplica`; `criar_aluno_acima_do_limite_falha_e_nao_grava` (nada persistido); `limite_de_utilizadores_ignora_contas_de_aluno`; `contas_concorrentes_nao_ultrapassam_o_limite` (grupo `pgsql`, em `PgsqlConcorrenciaTestCase`, dois pedidos ao último lugar → exactamente um sucesso); `a_mensagem_nao_vaza_dados_de_outro_tenant`.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** o serviço (soma os contadores etiquetados do recurso; chamado **dentro** da `DB::transaction` da Action, com a contagem feita após um `lockForUpdate` na linha de `licenciamento_direitos` do tenant, para serializar criações concorrentes). Nas Actions: uma única linha `$this->limites->garantir(Recurso::ALUNOS)` injectada pelo construtor.
- [ ] **Step 4: Run** → PASS; correr as suites de Aluno e Usuario para provar que nada regrediu (flag inactiva por omissão).
- [ ] **Step 5: Stage** `git add Modules`

### Task 6: Gate de pedidos

**Files:**
- Create: `Modules/Licenciamento/app/Http/Middleware/GateDeLicenca.php`; Core: `Modules/Core/app/Contracts/IdentificaAdministradorDaEscola.php` (`eAdministrador(Authenticatable $u): bool`), implementação em `Modules/Permissao/app/Support/` (usa `Perfil::ADMIN_ESCOLA`, como `RecuperarAdministradorAction`), registada no `PermissaoServiceProvider`
- Modify: `bootstrap/app.php` (junto a `VerificarTenantDaSessao` no grupo `web`, e no grupo `api` depois do Sanctum), `app/Http/Middleware/HandleInertiaRequests.php` (partilha `licenca` **só** ao administrador: `modo`, `bloqueia_em`, `dias_restantes`)
- Test: `Modules/Licenciamento/tests/Feature/GateDeLicencaTest.php`

**Interfaces:**
- Consumes: `DireitosService::modo()`, `IdentificaAdministradorDaEscola`, `config('licenciamento.rotas_permitidas_ao_admin')` (mínimo: `licenca.*`, logout, exportação/descarga de ficheiros — nomes confirmados por leitura de `Modules/Core/routes` e dos módulos).
- Produces: resposta a não-administrador bloqueado: **JSON/API** → 503 `{"message":"Ocorreu um erro. Tente mais tarde."}`; **Inertia (XHR)** → `redirect()->back()->withErrors(['autorizacao' => <mesma frase>])` (toast, convenção já usada em `bootstrap/app.php`); **documento** → 503 com a vista `errors.503` genérica ou equivalente neutra; sempre `Cache-Control: no-store, private`; a sessão é terminada (`logout` + `invalidate`) antes de responder. Administrador fora da lista: redirecciona para `route('licenca.index')`.

- [ ] **Step 1: Write the failing tests:** `modo_normal_nao_interfere`; `aviso_so_aparece_ao_administrador` (prop `licenca` presente para admin, ausente para professor/aluno); `bloqueado_aluno_recebe_resposta_generica_e_perde_a_sessao`; `bloqueado_professor_e_funcionario_idem`; `bloqueado_api_devolve_503_sem_pistas` (corpo e cabeçalhos não contêm "licen" nem o motivo); `bloqueado_inertia_devolve_toast_generico`; `bloqueado_admin_e_levado_a_pagina_de_licenca`; `bloqueado_admin_acede_a_exportacao_e_logout`; `bloqueado_admin_nao_acede_a_pautas_nem_matriculas`; `visitante_nao_autenticado_ve_o_login_normalmente`; `sessao_aberta_e_expulsa_no_pedido_seguinte`; `flag_inactiva_nada_muda`; `comando_agendado_corre_em_modo_bloqueado` (ex.: `artisan exportacoes:limpar --todos` sai com 0); `painel_da_plataforma_nao_e_afectado`; `licenca_revogada_bloqueia`.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** o middleware (decisão pura sobre `modo` + perfil + rota; sem lógica de negócio no controller) e a implementação do contrato de perfil.
- [ ] **Step 4: Run** → PASS; correr a suite completa para provar que a flag inactiva não quebra nada.
- [ ] **Step 5: Stage** `git add Modules bootstrap/app.php app`

### Task 7: Emissor — planos e emissão

**Files:**
- Create: migrations `create_licenciamento_planos_table`, `create_licenciamento_licencas_table`, `create_licenciamento_eventos_table` (uma cada); models `Plano`, `LicencaEmitida`, `EventoDeLicenca` (append-only: `updating`/`deleting` lançam); enum `EstadoComercial` (activa, suspensa, expirada, cancelada) com `*_descricao`; Actions `CriarPlanoAction`, `AtualizarPlanoAction`, `EmitirLicencaAction`; `Services/EmissorDeLicencas.php`; DTOs `EmitirLicencaDTO`, `PlanoDTO`
- Modify: `config/tenancy.php` (`tabelas_globais`)
- Test: `Modules/Licenciamento/tests/Feature/EmitirLicencaTest.php`, `PlanosTest.php`

**Interfaces:**
- Produces: `EmissorDeLicencas::disponivel(): bool` (há chave privada válida); `EmitirLicencaAction::executar(EmitirLicencaDTO $dto, ?int $autorId): LicencaEmitida` (`@throws EmissorIndisponivel`) — copia os direitos do plano para a licença (snapshot) com sobreposição por licença (limites, tolerância, módulos, validade, ligação à instalação), assina com o `CodecoDeLicenca`, grava `LicencaEmitida` + evento `emitida`; **se `escola` é um tenant desta instalação (SaaS), chama `InstalarLicencaAction`** (mesmo caminho do administrador). `LicencaEmitida` guarda o token para descarga. `autorId` é inteiro simples (o Licenciamento não importa o `SuperAdmin`).

- [ ] **Step 1: Write the failing tests:** `emite_licenca_assinada_que_o_verificador_aceita`; `a_licenca_e_snapshot_do_plano` (editar o plano depois não altera a emitida); `sobreposicao_por_licenca_prevalece`; `tolerancia_zero_e_aceite`; `sem_chave_privada_nao_emite` → `EmissorIndisponivel`; `no_saas_fica_activa_na_hora_via_instalar_licenca` (usa `InstalarLicencaAction`: verificar por spy/`DireitosService`); `ligacao_a_instalacao_obrigatoria_por_omissao_no_self_hosted` e `desligavel_por_licenca`; `evento_emitida_registado_com_autor`; `eventos_sao_append_only` (update/delete lançam); `plano_sem_modulos_ou_limites_negativos_e_recusado`.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** (as chaves de teste geradas por `sodium_crypto_sign_keypair()` num helper de teste; nunca chaves reais no repositório).
- [ ] **Step 4: Run** → PASS; `TenancyEsquemaTest` → PASS.
- [ ] **Step 5: Stage** `git add Modules config/tenancy.php`

### Task 8: Emissor — ciclo de vida

**Files:**
- Create: Actions `RenovarLicencaAction`, `EstenderToleranciaAction` (extensão de cortesia), `SuspenderLicencaAction`, `ReactivarLicencaAction`, `CancelarLicencaAction`
- Test: `Modules/Licenciamento/tests/Feature/CicloDeVidaDaLicencaTest.php`

**Interfaces:**
- Consumes: `EmitirLicencaAction`, `DireitosLocais.revogada_em`.
- Produces: cada Action recebe `LicencaEmitida` + `motivo` obrigatório + `?int $autorId`, regista o evento (`renovada`, `estendida`, `suspensa`, `reactivada`, `cancelada`). Renovar/estender emitem **nova** licença (novo `id`, `emitida_em` posterior) e substituem a anterior no SaaS; suspender/cancelar marcam `revogada_em` no registo local quando o tenant é desta instalação (efeito imediato no SaaS) — para tokens offline só afectam o registo do emissor (limite documentado).

- [ ] **Step 1: Write the failing tests:** `renovar_emite_nova_licenca_e_substitui_no_saas`; `estender_exige_motivo_e_regista_autor`; `a_extensao_e_licenca_nova_nunca_automatica` (o avaliador não alarga a tolerância sozinho); `suspender_bloqueia_de_imediato_no_saas`; `reactivar_levanta_o_bloqueio`; `cancelar_e_definitivo`; `transicoes_invalidas_sao_recusadas` (ex.: reactivar cancelada); `motivo_vazio_e_recusado`; `historico_ordenado_por_data`.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Stage** `git add Modules`

### Task 9: Painel Super Admin — planos e licenças

**Files:**
- Create (no módulo Plataforma, que passa a depender do Licenciamento): `PlanoController`, `LicencaController`, Requests, páginas `Pages/Planos/{Index,Form}.vue`, `Pages/Licencas/{Index,Emitir}.vue` (ou secção na página da escola), componentes reaproveitando `ConfirmModal`/`EstadoBadge`; descarga do token como ficheiro e botão copiar
- Modify: `Modules/Plataforma/routes/web.php` (rotas no grupo autenticado), `LayoutPlataforma.vue` (entradas «Planos» e «Licenças»), `EscolaController@show`/`Escolas/Show` (estado da licença da escola)
- Test: `Modules/Plataforma/tests/Feature/LicencasPlataformaTest.php`

**Interfaces:**
- Consumes: Actions das Tarefas 7–8; `RegistarAuditoriaAction` (acção `licenca.emitida`, `.renovada`, `.estendida`, `.suspensa`, … com `detalhe` sem segredos; **nunca** o token nem a chave).
- Produces: rotas `plataforma.planos.*`, `plataforma.licencas.*` (incl. `descarregar`). Emitir em instalação sem chave privada → mensagem clara e sem botão.

- [ ] **Step 1: Write the failing tests:** `super_admin_cria_plano`; `super_admin_emite_licenca_para_escola_saas_e_fica_activa`; `emite_licenca_self_hosted_com_codigo_de_instalacao_e_descarrega_ficheiro`; `estender_regista_auditoria_com_motivo`; `utilizador_da_escola_nao_acede_ao_painel`; `sem_chave_privada_o_painel_mostra_emissor_indisponivel`; `a_auditoria_nunca_contem_o_token`; `a_listagem_mostra_proximas_expiracoes_ordenadas`.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** controllers finos (Action + auditoria via o `AuditaSemFalhar` já usado); Vue seguindo `Escolas/Index.vue` e responsivo em < 768px (cartões).
- [ ] **Step 4: Run** → PASS; `npm run build` sem erros; verificar o painel no browser (Chrome do sistema, login local, sem gravar dados reais).
- [ ] **Step 5: Stage** `git add Modules`

### Task 10: Página «Licença» do administrador da escola

**Files:**
- Create: `LicencaPaginaController` (`licenca.index`, `licenca.instalar`), `InstalarLicencaRequest` (aceita `token` colado **ou** ficheiro, tamanho máximo), `Pages/Licenca/Index.vue` + banner de aviso (componente partilhado ao Header/Layout só para administrador)
- Modify: rotas do módulo (grupo `web`, autenticado; **só** o administrador, via `IdentificaAdministradorDaEscola`)
- Test: `Modules/Licenciamento/tests/Feature/PaginaDeLicencaTest.php`

**Interfaces:**
- Consumes: `InstalarLicencaAction`, `DireitosService`, `CodigoDaInstalacao`.
- Produces: GET mostra modo, validade, `bloqueia_em`, limites vs. utilização, módulos e o **código da instalação** (botão copiar); POST instala e responde com sucesso ou a mensagem de `LicencaRecusada`.

- [ ] **Step 1: Write the failing tests:** `admin_ve_estado_e_codigo_da_instalacao`; `admin_cola_licenca_valida_e_fica_activa`; `admin_carrega_ficheiro_valido`; `licenca_invalida_mostra_erro_sem_detalhe_criptografico`; `professor_recebe_resposta_generica`; `instalacao_nova_sem_licenca_so_mostra_esta_pagina`; `administrador_em_modo_bloqueado_consegue_instalar_a_renovacao`; `a_pagina_nunca_expoe_o_token_guardado`.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Implement** (Vue com o padrão visual existente; Cancelar `btn-light-danger`; testar em largura de smartphone).
- [ ] **Step 4: Run** → PASS; `npm run build`; percorrer o fluxo no browser (colar licença de teste emitida pelo painel local com chave de dev).
- [ ] **Step 5: Stage** `git add Modules resources`

### Task 11: Módulos contratados

**Files:**
- Create: `Modules/Licenciamento/app/Http/Middleware/ExigirModuloContratado.php` (alias `licenca.modulo`)
- Modify: grupos de rotas dos módulos listados em `config('licenciamento.modulos_licenciaveis')` (propor: Financeiro, Avaliacao — confirmar com o dono), `HandleInertiaRequests` (partilha `licenca.modulos` para esconder entradas de menu, seguindo o padrão dos placeholders já existentes no `SidebarMenuWrapper`/`useXxxMenu`)
- Test: `Modules/Licenciamento/tests/Feature/ModulosContratadosTest.php`

**Interfaces:**
- Consumes: `DireitosDaLicenca::temModulo(alias)`. Produces: módulo não contratado → rota 404 neutra (não revela o produto) e menu oculto; módulos base nunca são licenciáveis; flag inactiva → tudo disponível.

- [ ] **Step 1: Write the failing tests:** `modulo_contratado_responde`; `modulo_nao_contratado_da_404`; `modulos_base_nunca_sao_bloqueados`; `menu_nao_mostra_modulo_nao_contratado`; `flag_inactiva_tudo_disponivel`.
- [ ] **Step 2: Run** → FAIL. **Step 3: Implement.** **Step 4: Run** → PASS.
- [ ] **Step 5: Stage** `git add Modules resources`

### Task 12: Verificação final

- [ ] **Step 1:** `vendor/bin/pint --dirty` e corrigir.
- [ ] **Step 2:** `php artisan test` completo (flag inactiva): o número de falhas **não** pode subir face ao estado anterior (o Financeiro tem falhas de fixtures já conhecidas; comparar com `git stash` se necessário). `--filter Licenciamento` e os testes de arquitectura a verde.
- [ ] **Step 3:** `php artisan migrate:status` mostra as migrações novas pendentes (quem corre `migrate:fresh` é o dono); `npm run build` ok.
- [ ] **Step 4:** Ligar `LICENCIAMENTO_ACTIVO=true` numa BD de dev, percorrer: emitir → instalar → expirar (alterar a hora com a licença de teste curta) → bloqueio por perfil → renovar. Sem gravar dados reais.
- [ ] **Step 5:** Stage final; resumir ao dono as decisões D1–D6 e o que fica para as Fases 2–4.
