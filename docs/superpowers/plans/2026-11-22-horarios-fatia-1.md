# Horários (fatia 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Módulo `Horario`: grelha semanal por turma (turma × dia × tempo × disciplina × sala), com vigência, validações no servidor e três vistas (turma, professor, sala) mais "O meu horário" na Área do Professor.

**Architecture:** Módulo Laravel (nwidart) novo `Modules/Horario`, no padrão de `Modules/Docente`: Model → DTO → Validador → Actions finas → Service de consulta → Controller fino → páginas Inertia/Vue. A grelha **não guarda o docente**: resolve-o pela atribuição em vigor através de um contrato novo no Core, implementado pelo módulo `Docente`. Alterar uma entrada fecha a antiga e cria outra.

**Tech Stack:** PHP 8.x / Laravel, nwidart/laravel-modules, Inertia + Vue 3, PHPUnit (SQLite nos testes, PostgreSQL em produção), tenancy por `PertenceAoTenant`.

**Spec:** `docs/superpowers/specs/2026-11-22-frequencia-horarios-design.md` (secções 4 D1–D3, D8, D10–D13 e 5). A fatia 2 (Frequência) tem plano próprio, depois desta.

## Global Constraints

- Uma tabela por migração; FKs por chave composta `(tenant_id, id)`; `PertenceAoTenant` + `RegistaAutoria`; coluna `*_descricao` para cada enum inteiro.
- Controllers finos: leitura em Service, escrita em Action; classes importadas com `use`, nunca FQN inline.
- A grelha nunca guarda `docente_id` (D8). Conflito de docente usa o titular em vigor na **data de referência** = `max(vigencia_inicio, hoje no fuso da escola)` limitada a `vigencia_fim` (**decisão deste plano**).
- Dias da semana 1–6 (segunda a sábado, ISO); a UI mostra segunda a sexta e o sábado é opcional (botão "Mostrar sábado"; aparece sozinho se houver entradas). Só tempos do tipo `TipoHorarioEnum::TEMPO` entram na grelha; intervalos/períodos não.
- Slug de permissão do módulo: `horario` (já referenciado em `usePedagogicoMenu.js` e `SidebarMenuWrapper.vue` como `horario.ver`). "O meu horário" usa `area-professor.ver`.
- Ano lectivo encerrado ou turma inactiva não aceitam alterações à grelha.
- Sem commits: só `git add` (regra do dono). Os passos "Stage" substituem "Commit".
- Eliminar só é permitido a entradas cuja vigência ainda não começou (a fatia 2 reforça: nunca com aulas registadas).

## Review Focus

- Turma sem `turno_id`: erro claro, não excepção.
- Tempo do tipo PERIODO, ou que não pertence ao turno da turma: recusado.
- Vigência fora do ano lectivo da turma, ou `fim < início`: recusado.
- Entrada cujo docente deixou de ter atribuição: continua listada, marcada `sem_docente`, não rebenta.
- Duas entradas da mesma turma no mesmo dia/tempo com vigências sobrepostas: recusado; vigências encostadas (fim = véspera do início da outra) são válidas.
- Mesmo docente em duas turmas ao mesmo dia/tempo: recusado; docente titular a trocar a meio da vigência não gera falso conflito.
- Utilizador de outro tenant nunca vê nem altera entradas (404/lista vazia).
- Alterar com `vigencia_inicio` ≤ início da entrada actual: recusado (usar eliminar).

## File Structure

```
Modules/Horario/
  module.json, composer.json                      # copiados do padrão do Docente
  app/Providers/{HorarioServiceProvider,RouteServiceProvider}.php
  app/Enums/DiaSemana.php
  app/Models/GrelhaEntrada.php
  app/DTO/GrelhaEntradaDTO.php
  app/Services/{ValidadorGrelhaService,ConsultaGrelhaService,GestaoGrelhaService}.php
  app/Actions/{CriarEntradaAction,AlterarEntradaAction,EncerrarEntradaAction,EliminarEntradaAction}.php
  app/Http/Controllers/{GrelhaController,MeuHorarioController}.php
  app/Http/Requests/{GuardarEntradaRequest,EncerrarEntradaRequest}.php
  database/migrations/2026_11_23_100000_create_horario_grelha_entradas_table.php
  routes/web.php
  resources/js/Pages/Grelha/Index.vue, Pages/MeuHorario.vue
  resources/js/Components/{GrelhaSemanal,EntradaFormModal,EncerrarEntradaModal}.vue
  tests/Support/CenarioDeHorario.php, tests/Feature/*Test.php
Modules/Core/app/Contracts/ConsultaDocenciaParaHorario.php        # novo contrato
Modules/Docente/app/Services/ConsultaDocenciaParaHorarioService.php
Modules/Permissao/app/Enums/Modulo.php                            # + HORARIO = 32
Modules/Permissao/app/Actions/SincronizarPerfisDeSistemaAction.php
modules_statuses.json                                             # "Horario": true
resources/js/Components/Layout/SidebarMenuWrapper.vue + Composables/useProfessorMenu*.js   # "O meu horário"
```

---

### Task 1: Esqueleto do módulo e permissões

**Files:** criar `Modules/Horario/{module.json,composer.json,app/Providers/*}`; modificar `modules_statuses.json`, `Modulo.php`, `SincronizarPerfisDeSistemaAction.php`; teste `Modules/Horario/tests/Feature/HorarioPermissoesTest.php`.

**Interfaces:** Produces `Modulo::HORARIO` (valor 32, slug `horario`, rótulo "Horário", acções `['ver','listar','criar','editar','encerrar','eliminar']`).

- [ ] **Step 1: Teste** `HorarioPermissoesTest`: `test_modulo_horario_tem_slug_e_accoes`, `test_administrador_tem_todas_as_accoes_de_horario`, `test_funcionario_nao_tem_eliminar` (`ver, listar, criar, editar, encerrar` sim; `eliminar` não), `test_professor_nao_tem_horario_ver`. Modelar em `Docente/tests/Feature/DocentePermissoesTest.php`.
- [ ] **Step 2:** Correr `php artisan test Modules/Horario/tests/Feature/HorarioPermissoesTest.php` → FAIL (`HORARIO` inexistente).
- [ ] **Step 3:** Acrescentar `case HORARIO = 32` e as entradas nos três `match` do enum (acções, slug, rótulo) e nas listas de ADMIN/FUNCIONARIO de `SincronizarPerfisDeSistemaAction`. Criar `module.json`/`composer.json` (copiar do Docente, nomes `Horario`/`horario`), `HorarioServiceProvider` (`$name='Horario'`, `$nameLower='horario'`, providers `[RouteServiceProvider::class]`, `boot()` com `loadMigrationsFrom` como o Docente), `RouteServiceProvider` copiado do Docente; `"Horario": true` em `modules_statuses.json`; `composer dump-autoload`.
- [ ] **Step 4:** Mesmo comando → PASS; `php artisan permissao:sincronizar` (comando/seed já usado para o `Docente`) não deve falhar.
- [ ] **Step 5: Stage** `git add Modules/Horario Modules/Permissao modules_statuses.json`.

### Task 2: Contrato de docência para o Horário

**Files:** criar `Modules/Core/app/Contracts/ConsultaDocenciaParaHorario.php`, `Modules/Docente/app/Services/ConsultaDocenciaParaHorarioService.php`; modificar `DocenteServiceProvider.php` (bind); teste `Modules/Docente/tests/Feature/ConsultaDocenciaParaHorarioTest.php` (usa `CenarioDeDocencia`).

**Interfaces:** Produces (todos respeitam tenant e a vigência efectiva usada em `VerificaAtribuicaoDocenteService`):
- `titularEm(int $turmaId, int $disciplinaId, CarbonInterface $data): ?array{docente_id:int, nome:string}`
- `paresEmVigorDoDocente(int $docenteId, CarbonInterface $data): list<array{turma_id:int, disciplina_id:int}>` (qualquer papel)
- `paresEmVigorDoUtilizador(int $utilizadorId, CarbonInterface $data): list<array{turma_id:int, disciplina_id:int}>` (resolve o docente pela pessoa da conta, como `docenteDoUtilizador`; conta sem docente → `[]`)
- `docentesActivos(): list<array{id:int, nome:string}>` (ordenados por nome)

- [ ] **Step 1: Testes:** `test_titular_em_devolve_o_titular_em_vigor_e_ignora_substituto`, `test_titular_em_devolve_null_sem_titular_ou_apos_fim`, `test_pares_do_docente_incluem_substituto`, `test_pares_do_utilizador_resolvem_pela_pessoa_e_conta_sem_docente_da_vazio`, `test_docentes_activos_exclui_inactivos`, `test_nao_ve_atribuicoes_de_outro_tenant`.
- [ ] **Step 2:** Correr → FAIL.
- [ ] **Step 3:** Implementar o service reutilizando o scope de vigência efectiva da `AtribuicaoDocente` (não duplicar a regra); `$this->app->bind(ConsultaDocenciaParaHorario::class, ConsultaDocenciaParaHorarioService::class)` no `register()`.
- [ ] **Step 4:** Correr → PASS.
- [ ] **Step 5: Stage.**

### Task 3: Enum, migração, Model e cenário de testes

**Files:** criar `app/Enums/DiaSemana.php`, `app/Models/GrelhaEntrada.php`, migração `2026_11_23_100000_create_horario_grelha_entradas_table.php`, `tests/Support/CenarioDeHorario.php`; teste `tests/Feature/GrelhaEntradaModelTest.php`.

**Interfaces:** Produces
- `enum DiaSemana: int` SEGUNDA=1…SABADO=6; `label(): string` ("Segunda-feira"…); `static fromData(CarbonInterface $d): ?self` (domingo → null).
- `GrelhaEntrada` (tabela `horario_grelha_entradas`): `ano_lectivo_id, turma_id, dia_semana (DiaSemana), dia_semana_descricao, horario_id, disciplina_id, sala_id (nullable), vigencia_inicio, vigencia_fim (nullable), criado_por, editado_por`; `SoftDeletes`, `PertenceAoTenant`, `RegistaAutoria`; relações `turma(), horario(), disciplina(), sala()`; `scopeEmVigor(Builder, CarbonInterface): Builder` (início ≤ d e (fim nulo ou fim ≥ d)); `scopeSobrepoe(Builder, string $inicio, ?string $fim): Builder`; hook `saving` que preenche `dia_semana_descricao`.
- Trait `CenarioDeHorario` (usa `CenarioDeDocencia`): `cenarioDeHorario(): array` → o do docência mais `turno` (ligado à turma), `tempo1` (07:00–07:45), `tempo2` (07:45–08:30) em `turno_horarios`, `intervalo` (tipo PERIODO, 08:30–08:45, também no turno), `sala`, `docente` (titular em vigor de `disciplina` e de `outraDisciplina`, `data_inicio` 2026-01-01).

- [ ] **Step 1: Teste** `GrelhaEntradaModelTest`: `test_guarda_descricao_do_dia`, `test_scope_em_vigor_inclui_extremos`, `test_scope_sobrepoe_trata_fim_nulo_como_aberto`, `test_fk_composta_recusa_turma_de_outro_tenant`, `test_isolada_por_tenant`.
- [ ] **Step 2:** Correr → FAIL.
- [ ] **Step 3:** Migração (FKs compostas como `create_atribuicoes_docentes_table`; índices `(turma_id, dia_semana, horario_id)` e `(sala_id, dia_semana, horario_id)`). Em PostgreSQL acrescentar, no mesmo ficheiro e guardado por `DB::getDriverName() === 'pgsql'`, a exclusão `EXCLUDE USING gist (turma_id =, dia_semana =, horario_id =, daterange(vigencia_inicio, COALESCE(vigencia_fim,'infinity'),'[]') &&) WHERE (deleted_at IS NULL)` e a análoga por `sala_id` com `WHERE (sala_id IS NOT NULL AND deleted_at IS NULL)`. Enum, Model e trait de cenário conforme Interfaces.
- [ ] **Step 4:** `php artisan test Modules/Horario/tests/Feature/GrelhaEntradaModelTest.php` → PASS.
- [ ] **Step 5: Stage.**

### Task 4: Validador da grelha

**Files:** criar `app/DTO/GrelhaEntradaDTO.php`, `app/Services/ValidadorGrelhaService.php`; teste `tests/Feature/ValidadorGrelhaServiceTest.php`.

**Interfaces:**
- Consumes: `ConsultaDocenciaParaHorario::titularEm`, `Docente\Services\DisciplinasAtribuiveisService::daTurma(Turma)` (módulo upstream, uso directo como no Validador do Docente), `RelogioDoTenant` (hoje no fuso da escola, como em `VerificaAtribuicaoDocenteService`).
- Produces: `final readonly class GrelhaEntradaDTO(int $turma_id, int $dia_semana, int $horario_id, int $disciplina_id, ?int $sala_id, string $vigencia_inicio, ?string $vigencia_fim)`; `ValidadorGrelhaService::validar(GrelhaEntradaDTO $dto, ?GrelhaEntrada $ignorar = null): Turma` (lança `ValidationException` com a chave do campo; chamado dentro de transacção que já bloqueou a turma).
- Regras, por ordem: turma existe/activa/ano não encerrado; turma com turno (`turno_id` não nulo); tempo existe, é `TEMPO`, activo e pertence ao turno (`turno_horarios`); disciplina do plano da turma; sala (se dada) existe e activa; vigência dentro do ano e `fim ≥ início`; existe titular em vigor para (turma, disciplina) na data de referência (senão `disciplina_id`: "Sem docente titular nesta disciplina…"); sem conflito de turma / sala / docente (mensagens distintas) com outras entradas (excepto `$ignorar`) no mesmo `dia_semana` e `horario_id` com vigência sobreposta; conflito de docente = titular da outra entrada na data de referência é o mesmo docente.

- [ ] **Step 1: Testes** (um por regra, mais os do Review Focus): `test_aceita_entrada_valida`, `test_turma_sem_turno`, `test_tempo_do_tipo_periodo`, `test_tempo_fora_do_turno`, `test_disciplina_fora_do_plano`, `test_vigencia_fora_do_ano`, `test_fim_antes_do_inicio`, `test_sem_titular`, `test_conflito_de_turma`, `test_vigencias_encostadas_nao_conflituam`, `test_conflito_de_sala`, `test_conflito_de_docente_em_duas_turmas`, `test_troca_de_titular_a_meio_nao_gera_falso_conflito`, `test_ano_encerrado`, `test_ignorar_permite_rever_a_propria_entrada`.
- [ ] **Step 2:** Correr → FAIL.
- [ ] **Step 3:** Implementar; mensagens em português como no Validador do Docente; constantes públicas `MENSAGEM_*` para reutilizar na Action (violação de exclusão do PostgreSQL → mesma mensagem, via helper como `SobreposicaoDeAtribuicao`).
- [ ] **Step 4:** Correr → PASS.
- [ ] **Step 5: Stage.**

### Task 5: Actions de escrita

**Files:** criar `app/Actions/{CriarEntradaAction,AlterarEntradaAction,EncerrarEntradaAction,EliminarEntradaAction}.php`; teste `tests/Feature/GrelhaActionsTest.php`.

**Interfaces:**
- Consumes: `ValidadorGrelhaService`, trait `LogService` (`comLog`, como `AtribuirDocenteAction`).
- Produces: `CriarEntradaAction::executar(GrelhaEntradaDTO): GrelhaEntrada`; `AlterarEntradaAction::executar(GrelhaEntrada $entrada, GrelhaEntradaDTO $novo): GrelhaEntrada` (fecha a actual com `vigencia_fim = novo.vigencia_inicio − 1 dia` e cria a nova, numa transacção; recusa se `novo.vigencia_inicio ≤ entrada.vigencia_inicio`, chave `vigencia_inicio`); `EncerrarEntradaAction::executar(GrelhaEntrada, string $dataFim): GrelhaEntrada` (`dataFim ≥ início` e dentro do ano); `EliminarEntradaAction::executar(GrelhaEntrada): void` (só se `vigencia_inicio > hoje`, senão `ValidationException`). Todas bloqueiam a linha da turma (`lockForUpdate`) antes de validar.

- [ ] **Step 1: Testes:** `test_criar_grava_ano_da_turma_e_autoria`, `test_alterar_fecha_a_antiga_na_vespera_e_cria_nova`, `test_alterar_com_inicio_nao_posterior_e_recusado`, `test_alterar_nao_perde_historico` (a antiga continua com `vigencia_fim`), `test_encerrar_define_fim`, `test_eliminar_so_antes_de_comecar`, `test_alterar_com_conflito_nao_fecha_a_antiga` (rollback).
- [ ] **Step 2:** Correr → FAIL.
- [ ] **Step 3:** Implementar; `ano_lectivo_id` vem sempre da turma (nunca do cliente).
- [ ] **Step 4:** Correr → PASS.
- [ ] **Step 5: Stage.**

### Task 6: Consulta (vistas) e service de gestão

**Files:** criar `app/Services/ConsultaGrelhaService.php`, `app/Services/GestaoGrelhaService.php`; teste `tests/Feature/ConsultaGrelhaServiceTest.php`.

**Interfaces:**
- Produces: `ConsultaGrelhaService`:
  - `porTurma(int $turmaId, ?CarbonInterface $data = null): array{tempos: list<array{id,nome,hora_inicio,hora_fim}>, entradas: list<EntradaVista>}`
  - `porDocente(int $docenteId, ?CarbonInterface $data = null): same`
  - `porSala(int $salaId, ?CarbonInterface $data = null): same`
  - `doUtilizador(int $utilizadorId, ?CarbonInterface $data = null): same` (usa `paresEmVigorDoUtilizador`)
  - `opcoes(?int $anoLectivoId): array{turmas, salas, docentes, disciplinas}` para selects
  - `EntradaVista = {id, dia_semana, horario_id, turma_id, turma_rotulo, disciplina_id, disciplina_nome, sala_id, sala_nome, vigencia_inicio, vigencia_fim, docente: ?array{id,nome}, sem_docente: bool}`; sem `data` usa hoje no fuso da escola; só entradas em vigor nessa data, ordenadas por dia e hora.
  - `GestaoGrelhaService`: `criar(GuardarEntradaRequest)`, `alterar(GrelhaEntrada, GuardarEntradaRequest)`, `encerrar(GrelhaEntrada, EncerrarEntradaRequest)`, `eliminar(GrelhaEntrada)` — traduz request → DTO e chama a Action.

- [ ] **Step 1: Testes:** `test_por_turma_lista_tempos_do_turno_sem_intervalos_na_grelha_mas_com_todos_os_tempos`, `test_entrada_sem_titular_vem_marcada_sem_docente`, `test_por_docente_inclui_aulas_como_substituto`, `test_doutilizador_conta_sem_docente_devolve_vazio`, `test_por_sala`, `test_data_futura_mostra_nova_versao_e_passada_a_antiga`, `test_nao_mistura_tenants`.
- [ ] **Step 2:** Correr → FAIL.
- [ ] **Step 3:** Implementar com eager loading (sem N+1: `titularEm` chamado uma vez por par (turma, disciplina) distinto).
- [ ] **Step 4:** Correr → PASS.
- [ ] **Step 5: Stage.**

### Task 7: HTTP — controllers, requests, rotas, autorização

**Files:** criar `GrelhaController`, `MeuHorarioController`, `GuardarEntradaRequest`, `EncerrarEntradaRequest`, `routes/web.php`; teste `tests/Feature/HorarioHttpTest.php` e `HorarioAutorizacaoTest.php` (padrão de `AtribuicaoDocenteAutorizacaoTest`: tabela de pedidos × perfis).

**Interfaces:** rotas (todas `auth`):

| Método | URI | Nome | Permissão |
|---|---|---|---|
| GET | `/horarios` | `horarios.index` | `horario.ver` (query `vista=turma\|docente\|sala`, `turma_id`/`docente_id`/`sala_id`, `data`) |
| POST | `/horarios` | `horarios.store` | `horario.criar` |
| PUT | `/horarios/{entrada}` | `horarios.update` | `horario.editar` (cria nova versão) |
| PATCH | `/horarios/{entrada}/encerrar` | `horarios.encerrar` | `horario.encerrar` |
| DELETE | `/horarios/{entrada}` | `horarios.destroy` | `horario.eliminar` |
| GET | `/professor/horario` | `area-professor.horario` | `area-professor.ver` |

Páginas Inertia: `Horario/Grelha/Index` (props `vista`, `grelha`, `opcoes`, `filtros`, `data`) e `Horario/MeuHorario` (props `grelha`, `data`). `{entrada}` com `whereNumber`.

- [ ] **Step 1: Testes:** `test_index_por_turma_devolve_props`, `test_store_valido_redirecciona_com_success`, `test_store_com_conflito_devolve_erro_de_validacao`, `test_update_cria_nova_versao`, `test_encerrar_e_destroy`, `test_meu_horario_so_mostra_as_aulas_do_professor`, `test_perfis_sem_permissao_recebem_403` (professor em `/horarios`, funcionário em `destroy`), `test_entrada_de_outro_tenant_da_404`.
- [ ] **Step 2:** Correr → FAIL.
- [ ] **Step 3:** Controllers finos como `AtribuicaoDocenteController` (`$this->authorize(...)`, delegar em Services). Requests validam formato (inteiros, datas `Y-m-d`, `dia_semana` 1–6) e usam `MensagensDeDocente`-style para mensagens.
- [ ] **Step 4:** Correr → PASS.
- [ ] **Step 5: Stage.**

### Task 8: Ecrã da grelha (vista por turma, editável)

**Files:** criar `resources/js/Pages/Grelha/Index.vue`, `Components/{GrelhaSemanal,EntradaFormModal,EncerrarEntradaModal}.vue`; teste de compilação `npm run build` + verificação no browser (playwright-core, login admin local, **sem gravar dados**).

**Interfaces:** `GrelhaSemanal.vue` props `tempos`, `entradas`, `editavel`, emite `criar({dia_semana,horario_id})`, `editar(entrada)`, `encerrar(entrada)`, `eliminar(entrada)`. Reaproveitar o padrão visual de `Docente/Pages/Atribuicoes/Index.vue` (cabeçalho, filtros, modais, `bg-body-secondary` nos cartões internos, botão Cancelar `btn-light-danger`, `Loader.vue` nos botões). Célula vazia = "+ atribuir"; célula preenchida mostra disciplina, docente (ou "Sem docente" a vermelho) e sala. Selector de vista (Turma/Professor/Sala) com filtros; vistas Professor/Sala só leitura. Em ecrã < 768px a grelha vira lista por dia (cartões).

- [ ] **Step 1:** Escrever os componentes e a página a partir das props da Task 7.
- [ ] **Step 2:** `npm run build` → sem erros.
- [ ] **Step 3:** Verificar no browser: grelha de uma turma com dados de teste existentes, modal de nova entrada com erro de conflito mostrado, vista Professor e vista Sala; medir `getComputedStyle` onde o aspecto importa; viewport 375px.
- [ ] **Step 4: Stage** (incluindo o build gerado, como nas fatias anteriores).

### Task 9: "O meu horário", menu e fecho

**Files:** criar `Pages/MeuHorario.vue`; modificar `SidebarMenuWrapper.vue` (item em `areaProfessor`: `{ href: '/professor/horario', title: 'O meu horário', permissao: 'area-professor.ver' }`) e o composable de menu do professor correspondente; os itens `/horarios` já existem (não inventar); actualizar o roadmap (`project_roadmap_pos_docente`) e o spec com o estado.

- [ ] **Step 1:** `MeuHorario.vue` reutiliza `GrelhaSemanal` em modo só leitura, com selector de data.
- [ ] **Step 2:** Ligar o item de menu nos dois sítios (wrapper e composable), seguindo o padrão do item "As minhas turmas".
- [ ] **Step 3:** `npm run build`; verificar no browser com o professor de teste: "O meu horário" mostra só as aulas dele.
- [ ] **Step 4:** Suite completa: `php artisan test` → sem regressões (incluir `Modules/Horario` e `Modules/Docente`).
- [ ] **Step 5: Stage** e listar `git status` ao dono.

---

## Self-Review

- Cobertura do spec §5: grelha com vigência (T3, T5), validações (T4), vistas turma/professor/sala (T6, T8), O meu horário (T9), permissões (T1), D8 (T2, T4), D12 `sem_docente` (T6). Frequência (§6) fica no plano 2.
- Tipos consistentes: `GrelhaEntradaDTO`, `EntradaVista` e as quatro assinaturas do contrato são usadas com os mesmos nomes em T4–T7.
- Pontos que o implementador confirma no código: campos obrigatórios de `Turno`/`Sala`/`Horario` ao montar o cenário (T3), nome do composable de menu do professor (T9), comando de sincronização de permissões (T1).
