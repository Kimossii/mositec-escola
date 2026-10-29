# Atribuição Docente Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Criar o módulo `Docente` com a entidade `AtribuicaoDocente` (professor × turma × disciplina × vigência), o ecrã de gestão da secretaria e o contrato `VerificaAtribuicaoDocente` no Core.

**Architecture:** Módulo novo a jusante de `Turma`, `PlanoCurricular`, `Matricula`, `Disciplina`, `AnoLectivo`, `Usuario`, `Permissao` e `Core`; nada o importa. Escrita em Actions (com `LogService`), leitura em Services, controllers finos, estado derivado das datas. A resolução das disciplinas atribuíveis reutiliza o resolvedor de plano curricular que a matrícula já usa.

**Tech Stack:** Laravel 12, PHP 8.3, nwidart/laravel-modules, Vue 3 + Inertia, PostgreSQL 16 (produção) e SQLite em memória (testes), Pest/PHPUnit.

**Spec:** `docs/superpowers/specs/2026-10-28-atribuicao-docente-design.md`

## Global Constraints

- Interface e mensagens em português de Portugal; Metronic, sem Livewire.
- Controllers finos: leitura em `*ConsultaService`, escrita em Actions; imports com `use`, nunca FQN inline.
- Toda a Action usa `Modules\Core\Traits\LogService` e `comLog()` (`tests/Feature/Arquitectura/ActionsComRegistoTest.php` falha se faltar).
- Todo o model do tenant usa `PertenceAoTenant`; nada de `insert`/`upsert`/`DB::raw`/`withoutEvents` em código de aplicação (`TenancyArquitecturaTest`).
- Coluna `*_descricao` sincronizada em hook `saving` para cada enum inteiro (`papel` → `papel_descricao`).
- O módulo só carrega se estiver em `modules_statuses.json`.
- **Limites temporais:** datas ao dia, extremos inclusivos, no fuso do tenant (`RelogioDoTenant`). A vigência efectiva é `[data_inicio, min(data_fim, fim do ano lectivo)]`; `data_fim` nula = fim do ano lectivo.
- **Histórico:** nada que tenha começado se elimina; só se elimina uma atribuição ainda **agendada**. Corrigir uma já iniciada = encerrar.
- **Concorrência:** em PostgreSQL a não-sobreposição é garantida pela base de dados (constraints de exclusão), não só pela validação; a Action trata a violação como erro de validação.
- Nunca editar a base de dados à mão. Os testes usam `RefreshDatabase`; aplicar migrações à BD de desenvolvimento é do dono.
- **Não fazer commit.** Fazer `git add` dos ficheiros da tarefa e parar; o commit é do dono.

## Review Focus

1. Substituir o titular com `ultimo dia` anterior ao `data_inicio` do titular actual → rejeitar (nunca criar vigência invertida). Pinned in Task 5.
2. Turma sem plano curricular confirmado ou disciplina fora do plano → mensagem clara e lista vazia, nunca 500. Pinned in Tasks 3 and 7.
3. Professor com vários perfis aceita-se; utilizador sem perfil Professor, inactivo ou de outro tenant rejeita-se. Pinned in Task 4.
4. Ano lectivo `ENCERRADO`: toda a escrita rejeitada, a leitura continua. Pinned in Tasks 4 and 7.
5. Titular com `data_fim` futura e novo titular no dia seguinte: sem sobreposição, e `podeGerir` certo no último dia do antigo e no primeiro do novo. Pinned in Tasks 5 and 6.

---

### Task 1: Módulo, enum, migração e model

**Files:**
- Create: `Modules/Docente/{module.json,composer.json,package.json}` (copiar a forma de `Modules/Curso/`)
- Create: `Modules/Docente/app/Providers/{DocenteServiceProvider,RouteServiceProvider,EventServiceProvider}.php`
- Create: `Modules/Docente/routes/{web,api}.php` (vazios por agora)
- Create: `Modules/Docente/app/Enums/PapelDocente.php`
- Create: `Modules/Docente/app/Models/AtribuicaoDocente.php`
- Create: `Modules/Docente/database/migrations/2026_10_28_100000_create_atribuicoes_docentes_table.php`
- Modify: `modules_statuses.json` (adicionar `"Docente": true`)
- Test: `Modules/Docente/tests/Feature/AtribuicaoDocenteModelTest.php`
- Test support: `Modules/Docente/tests/Support/CenarioDeDocencia.php`

**Interfaces:**
- Produces: `enum PapelDocente: int { case TITULAR = 0; case SUBSTITUTO = 1; public function label(): string }`
- Produces: `AtribuicaoDocente` (tabela `atribuicoes_docentes`) com `fillable`: `ano_lectivo_id, turma_id, disciplina_id, professor_id, papel, papel_descricao, data_inicio, data_fim, motivo_encerramento, criado_por, editado_por`; casts `papel => PapelDocente`, `data_inicio`/`data_fim => 'date'`; relações `anoLectivo()`, `turma()`, `disciplina()`, `professor()` (→ `User`).
- Produces: `scopeEmVigor(Builder $q, CarbonInterface $data)` (início ≤ data e (fim nulo ou fim ≥ data)) e `estadoNaData(CarbonInterface $data): string` devolvendo `'agendada' | 'em_curso' | 'terminada'`.
- Produces: trait de teste `CenarioDeDocencia` com `cenarioDeDocencia(): array` (chaves `ano, turma, disciplina, outraDisciplina, plano`) e `criarProfessor(string $email = 'prof@example.com'): User`. A turma **tem `curso_id`** (o resolvedor de plano compara `curso_id`); plano com `PlanoCurricularAnoLectivo` e duas `PlanoCurricularDisciplina`. Base: `tests/Concerns/PopulaDadosAcademicos.php` e `Modules/Curso/tests/Feature/CursoHttpTest.php` (helpers `actingAs*`).

- [ ] **Step 1: Write failing tests** em `AtribuicaoDocenteModelTest`: `test_papel_descricao_sincroniza_ao_gravar` (TITULAR → `'Titular'`), `test_estado_na_data_deriva_das_datas` (início 2026-03-01/fim 2026-03-31 → agendada em 2026-02-28, em_curso em 2026-03-01 e 2026-03-31, terminada em 2026-04-01; fim nulo → em_curso), `test_scope_em_vigor_inclui_os_dois_extremos`, `test_pertence_ao_tenant` (registo de outro tenant invisível; ver `Modules/Matricula/tests/Feature/MatriculaTenancyTest.php`); e, no estilo de `tests/PgsqlTestCase.php`, `test_pg_rejeita_referencias_a_turma_disciplina_ano_ou_professor_de_outro_tenant` e `test_pg_rejeita_dois_titulares_sobrepostos_mesmo_sem_passar_pela_action` (insere duas atribuições directamente pelo model com vigências sobrepostas e espera `QueryException` com SQLSTATE `23P01`).
- [ ] **Step 2: Run** `php artisan test Modules/Docente/tests/Feature/AtribuicaoDocenteModelTest.php` — Expected: FAIL (classes inexistentes).
- [ ] **Step 3: Implement** módulo, provider (`protected string $name = 'Docente'`, mesma forma de `CursoServiceProvider`), enum, model (`PertenceAoTenant`, `SoftDeletes`, `RegistaAutoria`; hook `saving` que define `papel_descricao = $papel->label()`, como em `Matricula`) e migração. Migração: colunas da spec §4 (sem coluna `estado`), `tenant_id` e FKs conforme exige `tests/Feature/Arquitectura/TenancyEsquemaTest.php` (FKs compostas `(tenant_id, id)`), índices `(professor_id, data_fim)` e `(ano_lectivo_id, turma_id)`, `softDeletes`. **Só em PostgreSQL** (`DB::getDriverName() === 'pgsql'`): `CREATE EXTENSION IF NOT EXISTS btree_gist` e duas constraints de exclusão, ambas com `WHERE (deleted_at IS NULL)` e intervalo `daterange(data_inicio, COALESCE(data_fim, 'infinity'::date), '[]')`: (a) `(turma_id WITH =, disciplina_id WITH =, intervalo WITH &&) WHERE papel = 0` (um titular por dia); (b) `(turma_id WITH =, disciplina_id WITH =, professor_id WITH =, intervalo WITH &&)` (mesmo professor sem sobreposição). FKs compostas `(tenant_id, id)` para `turmas`, `disciplinas`, `anos_lectivos` e `users`; se alguma dessas tabelas não tiver `unique(tenant_id, id)`, acrescentar numa migração própria do módulo dono. Activar em `modules_statuses.json`, correr `composer dump-autoload`.
- [ ] **Step 4: Run** o ficheiro de teste e `php artisan test tests/Feature/Arquitectura/TenancyEsquemaTest.php tests/Feature/Arquitectura/TenancyArquitecturaTest.php` — Expected: PASS.
- [ ] **Step 5: Stage** `git add Modules/Docente modules_statuses.json`.

---

### Task 2: Permissões

**Files:**
- Modify: `Modules/Permissao/app/Enums/Modulo.php`, `Modules/Permissao/database/seeders/AcaoSeeder.php`, `Modules/Permissao/app/Actions/SincronizarPerfisDeSistemaAction.php`, `Modules/Permissao/resources/js/Support/menusDosModulos.js`
- Test: `Modules/Docente/tests/Feature/AtribuicaoDocentePermissoesTest.php`

**Interfaces:**
- Produces: `Modulo::ATRIBUICAO_DOCENTE = 28`, slug `atribuicao-docente`, label `'Atribuição Docente'`, `acoesAplicaveis()` = `['ver','listar','criar','editar','encerrar','eliminar','exportar']`.
- Produces: acção nova `encerrar` (`numero => 13`) no `AcaoSeeder`.
- Produces: permissões Blade/Gate `atribuicao-docente.ver|listar|criar|editar|encerrar|eliminar|exportar`.

- [ ] **Step 1: Write failing tests**: depois de `$this->seed(PermissaoDatabaseSeeder::class)`, `test_admin_escola_tem_todas_as_acoes`, `test_funcionario_tem_ver_listar_criar_editar_encerrar_mas_nao_eliminar_nem_exportar` (confirmar com o que a spec §7 fixa: funcionário = `ver, listar, criar, editar, encerrar`), `test_professor_aluno_e_encarregado_nao_tem_acesso`.
- [ ] **Step 2: Run** o ficheiro — Expected: FAIL.
- [ ] **Step 3: Implement** o caso no enum (`slug`, `label`, `acoesAplicaveis`), a acção em `AcaoSeeder`, as concessões em `SincronizarPerfisDeSistemaAction` (lista explícita do admin e entrada do funcionário) e `'atribuicao-docente': { menu: 'Académico', entrada: 'Atribuições docentes' }` em `menusDosModulos.js`.
- [ ] **Step 4: Run** o ficheiro e `php artisan test --filter=Permissao` — Expected: PASS (ajustar testes existentes que contem módulos/acções, se houver).
- [ ] **Step 5: Stage** os ficheiros alterados.

---

### Task 3: Disciplinas atribuíveis e validador partilhado

**Files:**
- Create: `Modules/Docente/app/Services/DisciplinasAtribuiveisService.php`, `Modules/Docente/app/Services/ValidadorAtribuicaoDocenteService.php`, `Modules/Docente/app/DTO/AtribuicaoDocenteDTO.php`
- Test: `Modules/Docente/tests/Feature/DisciplinasAtribuiveisServiceTest.php`

**Interfaces:**
- Consumes: `Modules\Matricula\Actions\InscreverDisciplinasAutomaticamenteAction::resolverPlanoCurricular(Turma): ?PlanoCurricular`.
- Produces: `DisciplinasAtribuiveisService::daTurma(Turma $turma): Collection` de `Modules\Disciplina\Models\Disciplina` (as do plano resolvido com `plano_curricular_disciplinas.estado = 1`; vazia se não há plano).
- Produces: `AtribuicaoDocenteDTO` (readonly): `int $professor_id, int $turma_id, int $disciplina_id, PapelDocente $papel, string $data_inicio, ?string $data_fim` (mesmo estilo de `TurmaDTO`).
- Produces: `ValidadorAtribuicaoDocenteService::validar(AtribuicaoDocenteDTO $dto, ?AtribuicaoDocente $ignorar = null): Turma` — aplica as regras 1 a 6 da spec §5, devolve a turma, lança `ValidationException` com chaves `professor_id`, `turma_id`, `disciplina_id`, `data_inicio`, `data_fim`. Deve ser chamado **dentro** de transacção que já bloqueou a linha da turma. Faz o limite temporal pela vigência efectiva (Global Constraints).

- [ ] **Step 1: Write failing tests** de `daTurma`: `test_devolve_as_disciplinas_do_plano_confirmado`, `test_vazia_quando_a_turma_nao_tem_plano_confirmado`, `test_ignora_linhas_do_plano_inactivas`, `test_nao_mistura_disciplinas_de_outro_tenant`, `test_cada_disciplina_aparece_uma_so_vez` (resultado distinto por `disciplina_id`, mesmo que o resolvedor passe a devolver duplicados). Testes do validador: `test_rejeita_disciplina_fora_do_plano`, `test_rejeita_datas_fora_do_ano_lectivo`, `test_rejeita_data_fim_anterior_a_data_inicio`, `test_rejeita_turma_inactiva`, `test_rejeita_ano_lectivo_encerrado` (aceita `PLANEADO` e `ATIVO`).
- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement** os dois services e o DTO; mensagens em português. Regras 5 e 6 (sobreposição) ficam já no validador: consulta as atribuições da mesma `(turma, disciplina)` não eliminadas (excluindo `$ignorar`) e compara intervalos, tratando `data_fim` nula como fim do ano lectivo; titular contra titular (regra 5) e mesmo professor contra si próprio (regra 6).
- [ ] **Step 4: Run** — Expected: PASS.
- [ ] **Step 5: Stage.**

---

### Task 4: AtribuirDocenteAction

**Files:**
- Create: `Modules/Docente/app/Actions/AtribuirDocenteAction.php`
- Test: `Modules/Docente/tests/Feature/AtribuirDocenteActionTest.php`

**Interfaces:**
- Consumes: `ValidadorAtribuicaoDocenteService`, `AtribuicaoDocenteDTO`.
- Produces: `AtribuirDocenteAction::executar(AtribuicaoDocenteDTO $dto): AtribuicaoDocente` — transacção, `Turma::lockForUpdate()`, valida, cria com `ano_lectivo_id` **copiado da turma** (nunca do DTO), `LogService` com `comLog('Atribuir docente', ...)` no padrão de `CriarTurmaAction`.

- [ ] **Step 1: Write failing tests**: `test_cria_atribuicao_titular_com_ano_lectivo_da_turma`, `test_aceita_professor_com_varios_perfis` (Professor + Funcionário), `test_rejeita_utilizador_sem_perfil_professor`, `test_rejeita_professor_inactivo`, `test_rejeita_professor_de_outro_tenant`, `test_rejeita_segundo_titular_com_vigencia_sobreposta_com_mensagem_a_indicar_substituir`, `test_aceita_substituto_em_simultaneo_com_titular`, `test_substituto_sem_titular_e_permitido`, `test_rejeita_mesmo_professor_sobreposto_na_mesma_disciplina`, `test_ano_lectivo_encerrado_nao_aceita_escrita`, `test_data_fim_posterior_ao_fim_do_ano_e_rejeitada`, `test_referencia_de_outro_tenant_e_rejeitada_para_turma_disciplina_e_professor`. Concorrência (`PgsqlConcorrenciaTestCase`, ver `Modules/Financeiro/tests/Feature/Pgsql/ComProcessosConcorrentes.php`): `test_pg_dois_titulares_em_simultaneo_so_um_e_criado` — a segunda chamada falha com `ValidationException`, não com `QueryException`.
- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement** a Action. Professor válido = `User` do tenant com `estado = 1` cujos `roles` incluem `nome = Perfil::PROFESSOR->value` (como em `UsuarioConsultaService`); colocar esta verificação no validador da Task 3 se ainda não estiver.
- [ ] **Step 4: Run** o ficheiro e `php artisan test tests/Feature/Arquitectura/ActionsComRegistoTest.php` — Expected: PASS.
- [ ] **Step 5: Stage.**

---

### Task 5: Substituir, encerrar, actualizar e eliminar

**Files:**
- Create: `Modules/Docente/app/Actions/{SubstituirTitularAction,EncerrarAtribuicaoAction,AtualizarAtribuicaoAction,EliminarAtribuicaoAction}.php`, `Modules/Docente/app/DTO/SubstituicaoTitularDTO.php`
- Test: `Modules/Docente/tests/Feature/AtribuicaoDocenteAccoesTest.php`

**Interfaces:**
- Consumes: `ValidadorAtribuicaoDocenteService`.
- Produces: `SubstituicaoTitularDTO` (readonly): `int $turma_id, int $disciplina_id, int $novo_professor_id, string $ultimo_dia_do_titular, string $motivo`.
- Produces: `SubstituirTitularAction::executar(SubstituicaoTitularDTO $dto): AtribuicaoDocente` (o novo titular; inicia em `ultimo_dia_do_titular + 1 dia`, herda `data_fim` nula).
- Produces: `EncerrarAtribuicaoAction::executar(AtribuicaoDocente $a, string $dataFim, string $motivo): AtribuicaoDocente`.
- Produces: `AtualizarAtribuicaoAction::executar(AtribuicaoDocente $a, PapelDocente $papel, string $dataInicio, ?string $dataFim): AtribuicaoDocente` (só se `estadoNaData(hoje)` for `agendada` ou `em_curso`).
- Produces: `EliminarAtribuicaoAction::executar(AtribuicaoDocente $a): void` (soft delete; só se `estadoNaData(hoje) === 'agendada'`, senão `ValidationException`: «Uma atribuição já iniciada não se elimina; encerre-a.»).

- [ ] **Step 1: Write failing tests**: `test_substituir_define_data_fim_no_antigo_e_cria_novo_no_dia_seguinte`, `test_substituir_e_atomico_se_o_novo_professor_for_invalido` (antigo fica intacto), `test_substituir_rejeita_ultimo_dia_anterior_ao_inicio_do_titular`, `test_substituir_sem_titular_em_vigor_e_rejeitado`, `test_substituir_exige_motivo`, `test_encerrar_define_fim_e_motivo_e_aceita_data_futura`, `test_encerrar_rejeita_fim_anterior_ao_inicio`, `test_atribuicao_terminada_nao_se_actualiza_nem_se_reabre`, `test_actualizar_revalida_sobreposicao_ignorando_a_propria`, `test_eliminar_agendada_faz_soft_delete`, `test_eliminar_em_curso_ou_terminada_e_rejeitado`, `test_ano_lectivo_encerrado_bloqueia_as_quatro_accoes`.
- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement** as quatro Actions, todas com `LogService`/`comLog`, transacção e bloqueio da turma. Em `AtribuirDocenteAction` e `AtualizarAtribuicaoAction`, capturar `QueryException` com SQLSTATE `23P01` e relançá-la como `ValidationException` com a mesma mensagem de sobreposição. `SubstituirTitularAction` localiza o titular com `emVigor(ultimo_dia_do_titular)` por `(turma, disciplina)` e reutiliza `AtribuirDocenteAction` para criar o novo.
- [ ] **Step 4: Run** o ficheiro e `ActionsComRegistoTest` — Expected: PASS.
- [ ] **Step 5: Stage.**

---

### Task 6: Contrato do Core e dependência do ano lectivo

**Files:**
- Create: `Modules/Core/app/Contracts/VerificaAtribuicaoDocente.php`, `Modules/Docente/app/Services/VerificaAtribuicaoDocenteService.php`, `Modules/Docente/app/Support/DependenciasDeAtribuicoesDocentes.php`
- Modify: `Modules/Docente/app/Providers/DocenteServiceProvider.php`
- Test: `Modules/Docente/tests/Feature/VerificaAtribuicaoDocenteTest.php`, `Modules/Docente/tests/Feature/DependenciasDeAtribuicoesDocentesTest.php`

**Interfaces:**
- Produces (contrato, docblock em português como os vizinhos):
  - `podeGerir(int $professorId, int $turmaId, int $disciplinaId, ?CarbonInterface $data = null): bool` — há atribuição em vigor (titular ou substituto) na data (por omissão hoje, fuso do tenant via `RelogioDoTenant`), usando a vigência efectiva `[data_inicio, min(data_fim, fim do ano lectivo)]`.
  - `atribuicoesEmVigorDoProfessor(int $professorId, ?int $anoLectivoId = null): array` — lista de `{atribuicao_id, turma_id, turma_nome, disciplina_id, disciplina_nome, papel, data_inicio, data_fim}`.
- Produces: `DependenciasDeAtribuicoesDocentes implements AnoLectivo\Contracts\DependenciasDoAnoLectivo`: `bloqueiaEliminacao` devolve motivo se o ano tiver atribuições; `bloqueiaAlteracaoDeInicio` devolve motivo se alguma começar antes do novo início (ver `Financeiro/Support/DependenciasDePlanosPropina.php`).
- Provider: `$this->app->bind(VerificaAtribuicaoDocente::class, VerificaAtribuicaoDocenteService::class)` e `$this->app->tag([DependenciasDeAtribuicoesDocentes::class], DependenciasRegistadasDoAnoLectivo::ETIQUETA)`.

- [ ] **Step 1: Write failing tests**: `test_titular_pode_gerir_na_sua_disciplina_e_turma`, `test_vigencia_efectiva_e_cortada_pelo_fim_do_ano_lectivo`, `test_substituto_pode_gerir`, `test_outro_professor_nao_pode`, `test_agendada_e_terminada_nao_podem`, `test_no_ultimo_dia_do_antigo_e_no_primeiro_do_novo_cada_um_pode_e_o_outro_nao`, `test_nao_cruza_tenants`, `test_lista_em_vigor_filtra_por_ano_lectivo`, e para o ano lectivo `test_bloqueia_eliminacao_com_atribuicoes`, `test_bloqueia_mudanca_de_inicio_que_deixa_atribuicoes_fora`.
- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement** serviço, classe de dependências e registo no provider.
- [ ] **Step 4: Run** os dois ficheiros e `php artisan test --filter=AnoLectivo` — Expected: PASS.
- [ ] **Step 5: Stage.**

---

### Task 7: Consulta, controller, rotas e autorização

**Files:**
- Create: `Modules/Docente/app/Services/{AtribuicaoDocenteConsultaService,GestaoAtribuicaoDocenteService}.php`, `Modules/Docente/app/Http/Controllers/AtribuicaoDocenteController.php`, `Modules/Docente/app/Http/Requests/{AtribuirDocenteRequest,SubstituirTitularRequest,AtualizarAtribuicaoRequest,EncerrarAtribuicaoRequest}.php`
- Modify: `Modules/Docente/routes/web.php`
- Test: `Modules/Docente/tests/Feature/AtribuicaoDocenteConsultaServiceTest.php`, `Modules/Docente/tests/Feature/AtribuicaoDocenteHttpTest.php`, `Modules/Docente/tests/Feature/AtribuicaoDocenteAutorizacaoTest.php`

**Interfaces:**
- Produces `AtribuicaoDocenteConsultaService`:
  - `porTurma(int $anoLectivoId, int $turmaId): array` — uma linha por disciplina atribuível: `{disciplina_id, disciplina_nome, titular, substitutos, sem_titular, conta_como_em_falta}`; `titular`/`substitutos` são atribuições em vigor hoje (ou agendadas, marcadas como tal); `conta_como_em_falta` = sem titular **e** (`obrigatoria` ou com inscrição activa na turma); lista vazia + `motivo_vazio` quando a turma não tem plano.
  - `lista(array $filtros, int $porPagina = 15): LengthAwarePaginator` (filtros `ano_lectivo_id, turma_id, professor_id, disciplina_id, papel, estado_vigencia, pesquisa`).
  - `indicadores(int $anoLectivoId): array{em_vigor: int, disciplinas_sem_titular: int}` (varre as turmas activas do ano).
  - `opcoes(int $anoLectivoId): array{anosLectivos, turmas, professores}` (professores = utilizadores activos com perfil Professor).
- Produces `GestaoAtribuicaoDocenteService`: `atribuir(AtribuirDocenteRequest)`, `substituir(SubstituirTitularRequest)`, `actualizar(AtribuicaoDocente, AtualizarAtribuicaoRequest)`, `encerrar(AtribuicaoDocente, EncerrarAtribuicaoRequest)`, `eliminar(AtribuicaoDocente)` — espelha `GestaoCursoService`.
- Rotas em `/docentes/atribuicoes`, nomes `atribuicoes-docentes.*`: `GET /` index (`atribuicao-docente.ver`), `POST /` store (`criar`), `POST /substituir` substituir (`criar`), `PUT /{atribuicao}` update (`editar`), `PATCH /{atribuicao}/encerrar` (`encerrar`), `DELETE /{atribuicao}` (`eliminar`); `GET /exportar` fica para a Task 8. Index renderiza `Docente/Atribuicoes/Index` com props `anoLectivoId, vista ('turma'|'lista'), porTurma, atribuicoes, indicadores (Inertia::defer), filtros, opcoes`.

- [ ] **Step 1: Write failing tests**: serviço — `test_por_turma_marca_disciplinas_sem_titular`, `test_por_turma_sem_plano_devolve_motivo_vazio`, `test_opcional_sem_inscricoes_nao_conta_como_em_falta`, `test_substituto_sem_titular_conta_como_em_falta`, `test_indicadores_contam_sem_titular_em_toda_a_escola`, `test_lista_filtra_e_pagina`. HTTP — `test_index_renderiza_inertia_com_props`, `test_store_cria_e_regista_autoria`, `test_store_devolve_erros_de_validacao_em_portugues`, `test_substituir_encerrar_actualizar_eliminar`, `test_ano_lectivo_encerrado_le_mas_nao_escreve`, `test_ids_de_outro_tenant_em_turma_disciplina_e_professor_dao_erro_de_validacao_ou_404`, `test_delete_de_atribuicao_iniciada_devolve_erro`. Autorização (modelo: `CursoAutorizacaoTest`) — admin e funcionário conforme Task 2, professor/aluno/encarregado 403 em todas as rotas, e `tests/Feature/RotasEscritaAutorizadasTest.php` + `RotasPermissaoReconhecidaTest.php` a passar.
- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement** serviços, requests (validação de forma; regras de negócio ficam nas Actions), controller (cada método começa por `$this->authorize('atribuicao-docente.<acção>')`) e rotas, com o mesmo estilo de `CursoController`/`Modules/Curso/routes/web.php`. `ano_lectivo_id` por omissão = ano activo do tenant.
- [ ] **Step 4: Run** `php artisan test Modules/Docente tests/Feature/RotasEscritaAutorizadasTest.php tests/Feature/RotasPermissaoReconhecidaTest.php` — Expected: PASS.
- [ ] **Step 5: Stage.**

---

### Task 8: Exportação

**Files:**
- Create: `Modules/Docente/app/Services/ExportacaoDeAtribuicoesService.php`
- Modify: `AtribuicaoDocenteController.php`, `routes/web.php`
- Test: `Modules/Docente/tests/Feature/AtribuicaoDocenteExportacaoTest.php`

**Interfaces:**
- Consumes: `Modules\Core\Exportacao\Exportador` e `TabelaExportavel`; modelo a seguir: `ExportacaoDeUtilizadoresService` e `UsuarioController::exportar`.
- Produces: `GET /docentes/atribuicoes/exportar` (`atribuicao-docente.exportar`), mesmos filtros da lista, formatos que o `Exportador` já suporta; colunas: Professor, Turma, Disciplina, Papel, Início, Fim, Estado.

- [ ] **Step 1: Write failing tests**: `test_exporta_com_os_filtros_da_lista`, `test_exportar_exige_permissao` (funcionário 403), `test_respeita_o_limite_do_exportador` (mesmo comportamento que a exportação de utilizadores).
- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement** serviço e acção do controller.
- [ ] **Step 4: Run** o ficheiro — Expected: PASS.
- [ ] **Step 5: Stage.**

---

### Task 9: Página da secretaria e menus

**Files:**
- Create: `Modules/Docente/resources/js/Pages/Atribuicoes/Index.vue`
- Create: `Modules/Docente/resources/js/Components/Atribuicoes/{VistaPorTurma,VistaLista,AtribuirDocenteModal,SubstituirTitularModal,EditarAtribuicaoModal,EncerrarAtribuicaoModal,VigenciaBadge}.vue`
- Create: `Modules/Docente/resources/js/Models/PapelDocente.js`
- Modify: `resources/js/Components/Layout/SidebarMenuWrapper.vue` (descomentar só a secção `pessoal`, com o item «Atribuições docentes» → `/docentes/atribuicoes`, `permissao: 'atribuicao-docente.ver'`; os outros três itens ficam comentados), `resources/js/Composables/useAcademicoMenu.js` (novo link «Atribuições docentes»)
- Test: `tests/Feature/MenuAtribuicoesDocentesTest.php`

**Interfaces:**
- Consumes: props do `index` da Task 7; rotas `atribuicoes-docentes.*`. Referências visuais e de padrão: `Modules/Curso/resources/js/Pages/Index.vue` (lista, filtros, indicadores, modais), `Components/Shared/Loader.vue` (só em botões/tabela), `Modules/Turma/resources/js/Models/Estado.js` (forma dos models JS). Cartões internos com `bg-body-secondary`.
- Produces: página com selector de ano lectivo, alternância «Por turma» (por omissão) / «Lista», contador «disciplinas sem titular», destaque das disciplinas em falta, acções Atribuir / Substituir titular / Editar vigência / Encerrar por linha, estados vazios da spec §8 («Esta turma ainda não tem disciplinas definidas» com a razão), badge de vigência (agendada, em curso, terminada) e exportação.

- [ ] **Step 1: Write failing test** `MenuAtribuicoesDocentesTest`: para admin a resposta inertia partilha o menu com «Atribuições docentes»; para professor não aparece (seguir `tests/Feature/Arquitectura/MenuFinanceiroTest.php`).
- [ ] **Step 2: Run** — Expected: FAIL.
- [ ] **Step 3: Implement** a página, os componentes e os dois menus (ligar o item no sidebar **e** no menu do cabeçalho). Mensagens de erro do servidor mostradas por campo nos modais.
- [ ] **Step 4: Run** `php artisan test tests/Feature/MenuAtribuicoesDocentesTest.php` e `npm run build` — Expected: PASS e build sem erros.
- [ ] **Step 5: Verificar no browser** (skill `run`): entrar com `admin@mositec.gmail.com`, abrir `/docentes/atribuicoes`, percorrer atribuir, substituir, encerrar e o estado vazio de turma sem plano, nos temas claro e escuro. Confirmar espaçamentos e cores por `getComputedStyle`, não só por imagem.
- [ ] **Step 6: Stage.**

---

### Task 10: Verificação final

- [ ] **Step 1:** `php artisan test Modules/Docente tests/Feature/Arquitectura tests/Feature/Tenancy` — Expected: tudo PASS.
- [ ] **Step 2:** `php artisan test` completo; comparar com a linha de base anterior (a suite do Financeiro tem falhas conhecidas de fixtures, não atribuir a esta mudança) — Expected: nenhuma falha nova.
- [ ] **Step 3:** Confirmar a cobertura da spec: cada regra da §5 tem teste que a nomeia (tasks 3 a 5), contrato (6), permissões (2), ecrãs e estados vazios (7 e 9).
- [ ] **Step 4:** Resumir ao dono o que ficou em stage e que migração precisa de correr (`create_atribuicoes_docentes_table` e o seed da acção `encerrar`/módulo novo: `php artisan db:seed --force` para o catálogo global e sincronizar permissões dos perfis); o dono corre-as.
