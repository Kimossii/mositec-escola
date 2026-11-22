# S4 Documentos pessoais Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Limitar o acesso a documentos pessoais por âmbito, registar cada acesso e travar o varrimento em massa.

**Architecture:** Uma `DocumentoPessoaPolicy` decide o âmbito por `DadosPessoa`; o `DocumentoPessoaController` chama-a e delega o registo numa Action; os acessos ficam numa tabela por tenant lida por um Service num ecrã só de administrador. O limite de taxa usa `throttle` nas rotas + um limitador de pessoas distintas por utilizador.

**Tech Stack:** Laravel (nwidart modules), Inertia + Vue, PHPUnit (`RefreshDatabase`, `PermissaoDatabaseSeeder`).

**Spec:** `docs/superpowers/specs/2026-11-22-s4-documentos-pessoais-design.md`

## Global Constraints

- Controllers finos: Service para leitura, Action para escrita; `use` no topo, nunca FQN inline.
- Uma tabela por migração; tabela por tenant com `PertenceAoTenant`.
- **Só `git add`; nunca `git commit`** (o dono faz o commit). Nunca editar a BD directamente.
- Testar o fluxo HTTP (não só Actions); se tocar em Vue, compilar.
- Permissão nova: caso no enum `Modulo` (slug real, label, acções) + `SincronizarPerfisDeSistemaAction`/seed; só entra no menu descomentando o placeholder existente se houver, senão no `useXxxMenu.js` e `SidebarMenuWrapper.vue`.
- Cancelar sempre `btn-light-danger`; cartões internos `bg-body-secondary`.

## Review Focus

- Professor sem atribuição em vigor: 403 em qualquer aluno.
- Professor com atribuição terminada (`data_fim` passada) perde o acesso.
- Matrícula de aluno noutra turma do mesmo professor-colega: 403.
- Utilizador sem `Docente` nem perfil de gestão nem aluno: 403.
- IDs de tenant diferente: 404 (não 403 nem fuga).
- Acesso negado também fica registado (`negado`), e o registo nunca rebenta o pedido.

## Regras de âmbito (decididas no spec + calls do plano)

`DocumentoPessoaPolicy::ver(User $u, DadosPessoa $alvo): bool`, por esta ordem:
1. `$u->dados_pessoa_id === $alvo->id` → true.
2. Algum role activo do utilizador fora de {PROFESSOR, ALUNO, ENCARREGADO} (ex.: ADMIN_ESCOLA, FUNCIONARIO, perfil personalizado) → true (âmbito largo ganha).
3. `$u->ePerfilAluno()` → false.
4. Utilizador com `Docente` ligado (`Docente::where('dados_pessoa_id', $u->dados_pessoa_id)`) → true só se `$alvo` é `Aluno` com `Matricula` **ACTIVA** (call do plano: só activa) em turma com `AtribuicaoDocente` do docente `emVigor(now())`.
5. Caso contrário (incl. ENCARREGADO, call do plano) → false.

---

### Task 1: Tabela, Model, Action e Service de acessos

**Files:**
- Create: `Modules/Usuario/database/migrations/2026_11_22_100000_create_acessos_documentos_pessoa_table.php`
- Create: `Modules/Usuario/app/Models/AcessoDocumentoPessoa.php`
- Create: `Modules/Usuario/app/Enums/AcaoAcessoDocumento.php`
- Create: `Modules/Usuario/app/Actions/RegistarAcessoDocumentoPessoaAction.php`
- Create: `Modules/Usuario/app/Services/GestaoAcessoDocumentoPessoaService.php`
- Test: `Modules/Usuario/tests/Feature/AcessoDocumentoPessoaTest.php`

**Interfaces:**
- Produces: `enum AcaoAcessoDocumento: string { Listar='listar'; Visualizar='visualizar'; Download='download'; Negado='negado' }`
- Produces: `RegistarAcessoDocumentoPessoaAction::executar(User $utilizador, DadosPessoa $dona, AcaoAcessoDocumento $acao, ?DocumentoPessoa $documento, Request $request): void` — nunca lança (apanha `Throwable` e usa `logException` do `LogService`).
- Produces: `GestaoAcessoDocumentoPessoaService::listar(array $filtros /* dados_pessoa_id, user_id, de, ate, acao */, int $porPagina = 25): LengthAwarePaginator`
- Colunas: `tenant_id`, `user_id`, `dados_pessoa_id`, `documento_pessoa_id` (null), `acao`, `acao_descricao`, `ip`, `user_agent`, `created_at` (sem `updated_at`, sem soft delete). `acao_descricao` sincronizada no hook `saving` (regra do projecto). Model sem rotas de edição/apagar.

- [ ] **Step 1: Escrever os testes** em `AcessoDocumentoPessoaTest`: `test_regista_acesso_com_utilizador_pessoa_acao_ip`, `test_documento_id_fica_nulo_em_listagem`, `test_falha_ao_gravar_nao_lanca_excepcao` (forçar erro e afirmar que a Action retorna), `test_listar_filtra_por_pessoa_utilizador_data_e_acao`, `test_acessos_de_outro_tenant_nao_aparecem`.
- [ ] **Step 2:** `php artisan test --filter=AcessoDocumentoPessoaTest` → FAIL (classes inexistentes).
- [ ] **Step 3:** Implementar migração (índices em `dados_pessoa_id`, `user_id`, `created_at`), Model com `PertenceAoTenant` e `saving` para `acao_descricao`, Enum com `label()`, Action e Service conforme as Interfaces.
- [ ] **Step 4:** O mesmo comando → PASS.
- [ ] **Step 5:** `git add Modules/Usuario` (sem commit).

### Task 2: DocumentoPessoaPolicy

**Files:**
- Create: `Modules/Usuario/app/Policies/DocumentoPessoaPolicy.php`
- Modify: provider do módulo Usuario que regista policies (seguir o padrão de `UserPolicy`; se for `Gate::policy` num provider, registar `DadosPessoa::class => DocumentoPessoaPolicy::class` apenas para a habilidade `verDocumentos`)
- Test: `Modules/Usuario/tests/Feature/DocumentoPessoaPolicyTest.php`

**Interfaces:**
- Produces: `DocumentoPessoaPolicy::verDocumentos(User $utilizador, DadosPessoa $alvo): bool` seguindo as regras acima. Uso: `$this->authorize('verDocumentos', $dadosPessoa)`.

- [ ] **Step 1: Testes** (um por regra): `test_pessoa_ve_os_proprios`, `test_admin_ve_qualquer`, `test_funcionario_ve_qualquer`, `test_aluno_so_ve_os_proprios`, `test_professor_ve_aluno_da_sua_turma_com_atribuicao_em_vigor`, `test_professor_nao_ve_aluno_de_outra_turma`, `test_professor_nao_ve_colega_docente`, `test_professor_com_atribuicao_terminada_nao_ve`, `test_professor_nao_ve_aluno_com_matricula_nao_activa`, `test_professor_com_perfil_funcionario_tem_ambito_largo`, `test_utilizador_sem_docente_nem_gestao_nao_ve`, `test_encarregado_nao_ve`.
- [ ] **Step 2:** `php artisan test --filter=DocumentoPessoaPolicyTest` → FAIL.
- [ ] **Step 3:** Implementar `verDocumentos` (e um helper privado `temAmbitoLargo(User): bool` que lê roles activos na BD, como `PermissionResolver`); a consulta do passo 4 numa única query (`exists`) com `Matricula`/`AtribuicaoDocente`.
- [ ] **Step 4:** → PASS. **Step 5:** `git add`.

### Task 3: Ligar Policy + registo ao controller

**Files:**
- Modify: `Modules/Usuario/app/Http/Controllers/DocumentoPessoaController.php` (métodos `index`, `inativos`, `download`, `visualizar`)
- Modify: `Modules/Usuario/app/Services/GestaoDocumentoPessoaService.php` só se precisar expor a dona do documento (`$documento->dadosPessoa`)
- Test: `Modules/Usuario/tests/Feature/DocumentoPessoaAcessoHttpTest.php`

**Interfaces:**
- Consumes: Tasks 1 e 2.
- Em `download`/`visualizar` a pessoa-dona vem de `$documento->dados_pessoa_id`; em `index`/`inativos` do parâmetro de rota.

- [ ] **Step 1: Testes HTTP** (payload real, rotas `documentos-pessoa.*`): `test_download_permitido_cria_registo_download`, `test_visualizar_permitido_cria_registo_visualizar` (incluindo o ramo em que `visualizar` cai em download: regista **uma** linha), `test_listagem_cria_registo_listar`, `test_professor_fora_do_ambito_recebe_403_e_regista_negado` para as 4 rotas, `test_documento_de_outro_tenant_devolve_404_sem_registo`, `test_registo_nao_contem_conteudo_do_ficheiro`.
- [ ] **Step 2:** `php artisan test --filter=DocumentoPessoaAcessoHttpTest` → FAIL.
- [ ] **Step 3:** Em cada método: `can:documento-pessoa.ver` mantém-se na rota; no controller `Gate::allows('verDocumentos', $pessoa)`; se falhar → Action `Negado` + `abort(403)`; se passar → Action com a acção certa **antes** de devolver o ficheiro. O `visualizar` regista `Visualizar` uma só vez mesmo que o service delegue em `download`.
- [ ] **Step 4:** PASS + `php artisan test Modules/Usuario` (suite do módulo, sem regressões em `DocumentoPessoaHttpTest`). **Step 5:** `git add`.

### Task 4: Limites de taxa

**Files:**
- Modify: `Modules/Usuario/routes/web.php:59-72` (`throttle:operacao-pesada` em `visualizar`, `index`, `inativos`)
- Create: `Modules/Usuario/app/Services/LimitadorVarrimentoDocumentos.php`
- Modify: `DocumentoPessoaController` (chamar o limitador depois da Policy)
- Test: `Modules/Usuario/tests/Feature/LimiteDocumentosPessoaTest.php`

**Interfaces:**
- Produces: `LimitadorVarrimentoDocumentos::registar(User $utilizador, DadosPessoa $alvo): void` — lança `ThrottleRequestsException` (429) quando o utilizador abre documentos de **mais de 10 pessoas distintas em 10 minutos** (valores do plano, constantes na classe; Cache com chave por tenant+utilizador; as próprias pessoas e repetições da mesma pessoa não contam). Âmbito largo (admin/funcionário) **não** isento: o limite protege contra credencial roubada.

- [ ] **Step 1: Testes:** `test_visualizar_tem_throttle`, `test_listagem_tem_throttle`, `test_onze_pessoas_distintas_em_10_minutos_devolvem_429`, `test_repetir_a_mesma_pessoa_nao_conta`, `test_o_429_fica_registado_como_negado`, `test_janela_expira_apos_10_minutos` (`Carbon::setTestNow`).
- [ ] **Step 2:** FAIL. **Step 3:** implementar. **Step 4:** PASS. **Step 5:** `git add`.

### Task 5: Ecrã "Acessos a documentos"

**Files:**
- Modify: `Modules/Permissao/app/Enums/Modulo.php` (novo caso `ACESSO_DOCUMENTO_PESSOA`, slug `acesso-documento-pessoa`, label "Acessos a Documentos", acções `['ver', 'listar']`) + `Modules/Permissao/tests/Unit/ModuloEnumTest.php`
- Modify: `Modules/Permissao/app/Actions/SincronizarPerfisDeSistemaAction.php` (só ADMIN_ESCOLA recebe `ver`/`listar`) e o seed `RolePermissaoSeeder`
- Create: `Modules/Usuario/app/Http/Controllers/AcessoDocumentoPessoaController.php` (`index`, usa `GestaoAcessoDocumentoPessoaService::listar`; Inertia)
- Create: `Modules/Usuario/resources/js/Pages/AcessosDocumentos/Index.vue` (tabela com filtros pessoa/utilizador/data/acção, cartões em < 768px, `Loader.vue` pequeno na tabela, padrão de listas existentes)
- Modify: `Modules/Usuario/routes/web.php` (`GET /acessos-documentos`, `can:acesso-documento-pessoa.ver`, nome `acessos-documentos.index`)
- Modify: menu (`useXxxMenu.js` + `SidebarMenuWrapper.vue`, mesmo padrão dos placeholders) e `menusDosModulos.js`
- Test: `Modules/Usuario/tests/Feature/AcessosDocumentosHttpTest.php`

- [ ] **Step 1: Testes:** `test_admin_ve_lista_paginada`, `test_funcionario_sem_permissao_recebe_403`, `test_professor_recebe_403`, `test_filtros_aplicam_se`, `test_nao_existem_rotas_para_editar_ou_apagar_acessos`.
- [ ] **Step 2:** FAIL. **Step 3:** implementar na ordem enum → seed/sincronização → controller/rota → Vue → menu.
- [ ] **Step 4:** `php artisan test --filter=AcessosDocumentosHttpTest`, `php artisan test Modules/Permissao`, `npm run build`; depois teste visual com playwright-core (login admin local, sem gravar dados): abrir um documento e confirmar a linha no ecrã. **Step 5:** `git add`.

### Task 6: Fecho

- [ ] Actualizar `ROADMAP.md:21` (S4 → feito, linha da data) e a secção de segurança do `README.md` se mencionar S4.
- [ ] `php artisan test` completo → sem novas falhas; reportar números reais.
- [ ] `git add` de tudo, sem commit; fechar com a lista das tabelas envolvidas e relações (`acessos_documentos_pessoa` → `users`, `dados_pessoas`, `documentos_pessoas`; `matriculas`/`atribuicoes_docentes`/`docentes` só lidas).

## Auto-revisão

- Cobertura do spec: Policy (T2/T3), tabela (T1), limites (T4), ecrã (T5). 
- Calls do plano para o dono ver: só matrícula ACTIVA conta; encarregado sem acesso; o limite de 10 pessoas/10 min vale também para a gestão; valores dos limites ajustáveis em constantes.
