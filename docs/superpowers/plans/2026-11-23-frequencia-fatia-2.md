# Frequência (fatia 2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Módulo `Frequencia`: o professor faz a chamada (por tempo, ou uma por dia no Pré-Escolar e no Primário), as faltas justificam-se com histórico, e o resultado chega ao boletim, ao ecrã do aluno e ao dashboard.

**Architecture:** Módulo Laravel novo `Modules/Frequencia`, a jusante de `Horario`, `Docente`, `Matricula`, `Turma`, `AnoLectivo`. A aula **prevista** é calculada na hora (grelha vigente × calendário); só se **guarda** a aula quando a chamada é aberta, como fotografia (data, tempo, turma, disciplina, docente, sala). Boletim e dashboard consomem o módulo por contratos no Core. Mesmo padrão em camadas do módulo `Horario`: Model → Validação em Service → Actions finas → Service de consulta → Controller fino → Vue.

**Tech Stack:** PHP 8.x / Laravel, nwidart/laravel-modules, Inertia + Vue 3, PHPUnit (SQLite nos testes, PostgreSQL em produção), tenancy por `PertenceAoTenant`.

**Spec:** `docs/superpowers/specs/2026-11-22-frequencia-horarios-design.md` (§4 D2–D7, D9–D13 e §6). A fatia 1 já existe (`Modules/Horario`, `GrelhaEntrada`, contrato `ConsultaDocenciaParaHorario`).

## Global Constraints

- Uma tabela por migração; FKs por chave composta `(tenant_id, id)`; `PertenceAoTenant` + `RegistaAutoria`; coluna `*_descricao` para cada enum inteiro.
- Controllers finos: leitura em Service, escrita em Action; classes importadas com `use`, nunca FQN inline (também nos testes).
- Sem commits: só `git add`. Os passos "Stage" substituem "Commit". Nunca migrar nem escrever na BD de dev: só testes.
- Regime da chamada decidido pela etapa da turma (`NivelAcademico.etapa_ensino`): `PRE_ESCOLAR` e `PRIMARIO` → tipo `DIA` (uma chamada por dia); as restantes → tipo `TEMPO`. Sem coluna nova (D4). Creche fica fora.
- Aula prevista: só em dias com entrada da grelha em vigor; não há aula em dia coberto por `EventoCalendario` do tipo `FERIAS` ou `FERIADO`, nem aos domingos, nem fora do ano lectivo. No regime `DIA`, o dia é previsto se a turma tiver pelo menos uma entrada em vigor nesse dia da semana (D10).
- Só se abre chamada de data ≤ hoje (fuso da escola). Estados: `PRESENTE=1`, `FALTA=2`, `ATRASO=3`; aula: `REALIZADA=1`, `NAO_LECCIONADA=2` (com motivo).
- Ao abrir a chamada criam-se registos de **todos** os alunos da lista como `PRESENTE`; o professor só altera as excepções (decisão deste plano, ver Rulings abaixo).
- Lista de alunos (D11): matrículas `ACTIVA` da turma, com `data_matricula ≤ data` e (`data_fim` nulo ou `≥ data`); no regime `TEMPO` só os com inscrição `INSCRITA` na disciplina da aula (`inscricoes_disciplina` → `plano_curricular_disciplinas.disciplina_id`). Trancadas ficam de fora.
- Quem marca: professor com atribuição em vigor na turma (regime `DIA`) ou na turma e disciplina (regime `TEMPO`), titular ou substituto, via `ConsultaDocenciaParaHorario::paresEmVigorDoUtilizador`; ou quem tem `frequencia.corrigir-fora-prazo`.
- Janela de correcção (D9, D13): o professor altera registos até N dias depois da data da aula (N em `frequencia_definicoes.janela_correccao_dias`, por omissão 7); depois só quem tem `frequencia.corrigir-fora-prazo`, com motivo obrigatório.
- Nada se apaga: cada marcação, alteração, justificação e remoção de justificação grava uma linha em `frequencia_registos_historico`.
- Justificação (D7): só de registos `FALTA`; quem tem `frequencia.justificar`; um professor só justifica faltas das suas aulas. Guarda motivo (obrigatório), `documento_referencia` (texto livre opcional) e quem justificou/quando.
- Faltas contadas no boletim e no ecrã do aluno = registos `FALTA` (atrasos não contam). Unidade: `aulas` (regime `TEMPO`) ou `dias` (regime `DIA`).

## Rulings deste plano (a confirmar pelo dono)

- **R1 — sem anexo de ficheiro na justificação (v1).** O spec diz "documento opcional"; este plano guarda só `documento_referencia` (texto, p. ex. n.º do atestado). Anexar ficheiro exige decidir armazenamento e acesso. Custo se errado: uma migração e um campo de upload depois.
- **R2 — sem "director de turma".** Não existe essa entidade no sistema. Quem justifica = perfis com `frequencia.justificar` (Administrador, Funcionário/secretaria, Professor das suas aulas). Custo se errado: a regra de âmbito do professor ganha uma excepção.
- **R3 — presentes por omissão.** Abrir a chamada cria todos como `PRESENTE`. Custo se errado: uma flag.
- **R4 — boletim.** Por disciplina há uma coluna "Faltas" (total do ano) nas turmas em regime `TEMPO`; nas de regime `DIA` uma linha "Faltas (dias)" na identificação. O desdobramento por período fica na consulta do aluno (contrato com `porPeriodo`). O spec pedia "por disciplina e período" no boletim; encaixar uma coluna por período obriga a mexer nas colunas da pauta.

## Review Focus

- Chamada de data futura, de dia sem aula prevista ou de feriado: recusada com mensagem clara.
- Duas aberturas da mesma chamada (duplo clique, dois separadores): uma só aula, sem registos duplicados.
- Aluno trancado, ou matriculado depois da data, nunca aparece na lista; aluno sem inscrição na disciplina também não (regime `TEMPO`).
- Professor sem atribuição naquela turma/disciplina (ou só noutra data) não marca; substituto marca; outro tenant nunca vê nada.
- Correcção fora da janela: professor recusado; com `corrigir-fora-prazo` sem motivo recusado.
- Justificar presente/atraso recusado; professor a justificar falta de aula de outro professor recusado; remover justificação deixa histórico.
- Aula `NAO_LECCIONADA` não gera faltas e não conta como "chamada por fazer".
- Turma em regime `DIA` sem nenhuma entrada na grelha nesse dia: não há chamada prevista.
- Matrícula sem faltas devolve zeros, não erro; turma sem períodos não rebenta.

## File Structure

```
Modules/Frequencia/
  module.json, composer.json, app/Providers/{FrequenciaServiceProvider,RouteServiceProvider}.php
  app/Enums/{TipoAula,EstadoAula,EstadoPresenca,EventoFrequencia}.php
  app/Models/{FrequenciaDefinicoes,Aula,Registo,Justificacao,RegistoHistorico}.php
  app/DTO/{AulaPrevista,PresencaDTO}.php
  app/Services/{RegimeDeChamada,AulasPrevistasService,AlunosDaAulaService,AutorizacaoDeChamadaService,
                JanelaDeCorreccaoService,ConsultaFrequenciaService,ChamadasPorFazerService,
                FaltasDoAlunoService,GestaoFrequenciaService}.php
  app/Actions/{AbrirChamadaAction,RegistarPresencasAction,MarcarNaoLeccionadaAction,JustificarFaltaAction,RemoverJustificacaoAction,GuardarDefinicoesAction}.php
  app/Http/Controllers/{ChamadaController,JustificacaoController,PendentesController,MinhasFaltasController}.php
  app/Http/Requests/*.php, routes/web.php
  database/migrations/2026_11_24_10000{0..5}_*.php
  resources/js/Pages/{Dia,Chamada,Pendentes,MinhasFaltas}.vue, Components/*.vue
  tests/Support/CenarioDeFrequencia.php, tests/Feature/*Test.php
Modules/Core/app/Contracts/{ConsultaFaltasDoAluno,ConsultaChamadasPendentes}.php   # novos contratos
Modules/Matricula/database/migrations/2026_11_24_090000_add_unique_tenant_id_to_matriculas_table.php
Modules/Permissao/{Enums/Modulo.php, Actions/SincronizarPerfisDeSistemaAction.php, database/seeders/{ModuloSeeder,AcaoSeeder}.php}
Modules/Avaliacao/app/Services/BoletimDocumentoService.php   # coluna/linha de faltas
app/Services/DashboardService.php                            # "chamadas por fazer"
resources/js/Components/Layout/SidebarMenuWrapper.vue, Composables/usePedagogicoMenu.js
tests/Feature/Tenancy/MatrizIsolamentoTest.php               # + 'Frequencia'
```

---

### Task 1: Esqueleto do módulo e permissões

**Files:** criar `Modules/Frequencia/{module.json,composer.json,app/Providers/*,routes/web.php,routes/api.php}`; modificar `modules_statuses.json`, `Modulo.php`, `SincronizarPerfisDeSistemaAction.php`, `ModuloSeeder.php`, `AcaoSeeder.php`, `Modules/Permissao/tests/Feature/{ModuloSeederTest,AcoesAplicaveisTest}.php`; teste `Modules/Frequencia/tests/Feature/FrequenciaPermissoesTest.php`.

**Interfaces:** Produces `Modulo::FREQUENCIA` (valor 33, slug `frequencia`, rótulo "Frequência", acções `['ver','listar','criar','editar','justificar','corrigir-fora-prazo','configurar','ver-proprias']`). Acções novas no catálogo (`AcaoSeeder`): `justificar` (22), `corrigir-fora-prazo` (23). Perfis: Admin todas menos `ver-proprias`; Funcionário `ver, listar, justificar, corrigir-fora-prazo`; Professor `ver, listar, criar, editar, justificar`; Aluno `ver-proprias`; Encarregado nada. Modelar em `Modules/Horario` (fatia 1) e `Modules/Horario/tests/Feature/HorarioPermissoesTest.php`.

- [ ] **Step 1: Teste** `FrequenciaPermissoesTest`: `test_modulo_tem_slug_e_accoes`, `test_administrador_tem_as_accoes_de_gestao_e_nao_ver_proprias`, `test_funcionario_justifica_e_corrige_mas_nao_marca`, `test_professor_marca_e_justifica_mas_nao_corrige_fora_do_prazo`, `test_aluno_so_tem_ver_proprias`, `test_encarregado_sem_acesso`.
- [ ] **Step 2:** `php artisan test Modules/Frequencia/tests/Feature/FrequenciaPermissoesTest.php` → FAIL (`FREQUENCIA` inexistente).
- [ ] **Step 3:** Acrescentar o `case`, os três `match` do enum, `PERMISSOES_POR_PERFIL`, `ModuloSeeder` (`['nome' => 33, 'descricao' => 'Frequencia']`), `AcaoSeeder` (as duas acções), `"Frequencia": true` em `modules_statuses.json`; criar o esqueleto do módulo copiando o do `Horario` (`module.json`, `composer.json`, providers com `loadMigrationsFrom(__DIR__.'/../../database/migrations')`); actualizar as contagens em `ModuloSeederTest` (34) e `AcoesAplicaveisTest` (`has('modulos', 34)` e a lista de módulos com acções próprias); `composer dump-autoload`.
- [ ] **Step 4:** Correr `php artisan test Modules/Frequencia Modules/Permissao` → PASS.
- [ ] **Step 5: Stage** `git add Modules/Frequencia Modules/Permissao modules_statuses.json`.

### Task 2: Migrações, enums e Models

**Files:** migrações (uma tabela cada, prefixo `2026_11_24_`): `090000` add unique `(tenant_id,id)` a `matriculas` (em `Modules/Matricula/database/migrations`), `100000` `frequencia_definicoes`, `100100` `frequencia_aulas`, `100200` `frequencia_registos`, `100300` `frequencia_justificacoes`, `100400` `frequencia_registos_historico`; enums e Models em `app/`; `tests/Support/CenarioDeFrequencia.php`; teste `tests/Feature/FrequenciaModelosTest.php`.

**Interfaces:** Produces
- `TipoAula` (`TEMPO=1`, `DIA=2`), `EstadoAula` (`REALIZADA=1`, `NAO_LECCIONADA=2`), `EstadoPresenca` (`PRESENTE=1`, `FALTA=2`, `ATRASO=3`), `EventoFrequencia` (`MARCACAO=1`, `ALTERACAO=2`, `JUSTIFICACAO=3`, `REMOCAO_JUSTIFICACAO=4`), todos com `label(): string`.
- `FrequenciaDefinicoes` (`frequencia_definicoes`: `janela_correccao_dias` tinyint, por omissão 7; uma linha por tenant, `unique(tenant_id)`).
- `Aula` (`frequencia_aulas`): `ano_lectivo_id, turma_id, tipo, tipo_descricao, data, horario_id?, disciplina_id?, docente_id?, sala_id?, estado, estado_descricao, motivo_nao_leccionada?, criado_por, editado_por`. Único (turma, data, horario) para `TEMPO` e único (turma, data) para `DIA` (índice único parcial `WHERE horario_id IS NULL`, válido em SQLite e PostgreSQL). Relações `turma(), horario(), disciplina(), docente(), sala(), registos()`.
- `Registo` (`frequencia_registos`): `aula_id, matricula_id, estado, estado_descricao, observacao?, criado_por, editado_por`; único `(aula_id, matricula_id)`; relações `aula(), matricula(), justificacao()`.
- `Justificacao` (`frequencia_justificacoes`): `registo_id` (único), `motivo`, `documento_referencia?`, `justificada_por`, `justificada_em`.
- `RegistoHistorico` (`frequencia_registos_historico`): `registo_id, evento, estado_anterior?, estado_novo?, motivo?, alterado_por, created_at`. Só se insere (sem `updated_at`).
- Trait `CenarioDeFrequencia` (usa `CenarioDeHorario`): `cenarioDeFrequencia(): array` → o do horário mais `alunos` (3 matrículas `ACTIVA` na turma, inscritas na `disciplina`), `entrada` (segunda, `tempo1`, `disciplina`, vigente desde 2026-01-01), e `criarAluno(string $nome): Matricula`.

- [ ] **Step 1: Teste** `FrequenciaModelosTest`: `test_aula_guarda_descricoes`, `test_unicidade_de_aula_por_turma_data_e_tempo`, `test_unicidade_de_aula_do_dia_por_turma_e_data`, `test_registo_unico_por_aula_e_matricula`, `test_fk_composta_recusa_matricula_de_outro_tenant`, `test_isolado_por_tenant`, `test_historico_nao_tem_updated_at`.
- [ ] **Step 2:** Correr → FAIL.
- [ ] **Step 3:** Migrações (padrão de `create_horario_grelha_entradas_table`, FKs compostas para `ano_lectivos`, `turmas`, `horarios`, `disciplinas`, `docentes`, `salas`, `matriculas`; `frequencia_aulas` com `unique(tenant_id,id)` para ser alvo das FKs seguintes; `frequencia_registos` com `unique(tenant_id,id)` pela mesma razão); enums, Models (hooks `saving` para as `*_descricao`; `Registo` e `Justificacao` e `RegistoHistorico` com `PertenceAoTenant`), trait de cenário.
- [ ] **Step 4:** Correr → PASS.
- [ ] **Step 5: Stage.**

### Task 3: Regime, aulas previstas e lista de alunos

**Files:** criar `app/Services/{RegimeDeChamada,AulasPrevistasService,AlunosDaAulaService}.php`, `app/DTO/AulaPrevista.php`; testes `tests/Feature/{RegimeDeChamadaTest,AulasPrevistasServiceTest,AlunosDaAulaServiceTest}.php`.

**Interfaces:**
- `RegimeDeChamada::daTurma(Turma $turma): TipoAula` (`DIA` para `PRE_ESCOLAR`/`PRIMARIO`; `TEMPO` caso contrário; turma sem nível → `TEMPO`).
- `final readonly class AulaPrevista(int $turma_id, string $data, TipoAula $tipo, ?int $horario_id, ?int $disciplina_id, ?int $sala_id, ?int $docente_id, bool $sem_docente)`; `docente_id` = titular em vigor na data via `ConsultaDocenciaParaHorario::titularEm`.
- `AulasPrevistasService::daTurmaNoDia(int $turmaId, CarbonInterface $data): list<AulaPrevista>` (vazia fora do ano, domingo, feriado/férias, ou sem entradas); `::dosDocentes(list<array{turma_id:int,disciplina_id:int}> $pares, CarbonInterface $de, CarbonInterface $ate): list<AulaPrevista>` (para "chamadas por fazer"; no regime `DIA` inclui o dia se o professor tiver atribuição na turma).
- `AlunosDaAulaService::daAula(int $turmaId, CarbonInterface $data, TipoAula $tipo, ?int $disciplinaId): Collection<int, Matricula>` (regras da lista nas Global Constraints; ordenada por nome do aluno).

- [ ] **Step 1: Testes** (um por regra): `test_etapas_pre_escolar_e_primario_sao_dia_e_as_outras_tempo`; previstas: `test_regime_tempo_devolve_uma_por_entrada_em_vigor`, `test_regime_dia_devolve_uma_so_se_houver_entrada_nesse_dia_da_semana`, `test_feriado_e_ferias_anulam_o_dia`, `test_domingo_e_fora_do_ano_nao_tem_aula`, `test_entrada_sem_titular_vem_sem_docente`, `test_versao_antiga_da_grelha_vale_nas_datas_antigas`; alunos: `test_so_matriculas_activas_com_data_valida`, `test_trancada_e_matricula_futura_ficam_de_fora`, `test_regime_tempo_exige_inscricao_na_disciplina`, `test_regime_dia_ignora_disciplina`.
- [ ] **Step 2:** Correr → FAIL.
- [ ] **Step 3:** Implementar reutilizando `GrelhaEntrada::emVigor`, `EventoCalendario` (feriado/férias cobrindo a data, `dia_inteiro` ou não: qualquer evento FERIADO/FERIAS que contenha a data) e `RelogioDoTenant`.
- [ ] **Step 4:** Correr → PASS.
- [ ] **Step 5: Stage.**

### Task 4: Autorização, janela de correcção e Actions de chamada

**Files:** criar `app/Services/{AutorizacaoDeChamadaService,JanelaDeCorreccaoService}.php`, `app/Actions/{AbrirChamadaAction,RegistarPresencasAction,MarcarNaoLeccionadaAction,GuardarDefinicoesAction}.php`, `app/DTO/PresencaDTO.php`; testes `tests/Feature/{AutorizacaoDeChamadaTest,AbrirChamadaActionTest,RegistarPresencasActionTest,MarcarNaoLeccionadaActionTest,DefinicoesTest}.php`.

**Interfaces:**
- `AutorizacaoDeChamadaService::podeMarcar(User $u, int $turmaId, ?int $disciplinaId, TipoAula $tipo, CarbonInterface $data): bool` (professor com atribuição em vigor, ou `can('frequencia.corrigir-fora-prazo')`).
- `JanelaDeCorreccaoService::dentroDaJanela(CarbonInterface $dataDaAula): bool` (hoje − N dias ≤ data); `::dias(): int`.
- `AbrirChamadaAction::executar(User $u, int $turmaId, string $data, ?int $horarioId): Aula` — valida: data ≤ hoje; aula prevista existe (senão `data` com mensagem "Não há aula prevista…"); `podeMarcar`; cria a `Aula` (fotografia: disciplina, sala, docente = docente da conta se existir, senão titular) e os `Registo` de todos os alunos da lista como `PRESENTE`, com histórico `MARCACAO`; **idempotente**: se a aula já existe devolve-a (corrida coberta pelo índice único; capturar a violação e reler). Bloqueia a linha da turma (`lockForUpdate`).
- `final readonly class PresencaDTO(int $matricula_id, int $estado, ?string $observacao)`.
- `RegistarPresencasAction::executar(User $u, Aula $aula, array $presencas /* list<PresencaDTO> */, ?string $motivo = null): Aula` — só alunos da aula; aula `NAO_LECCIONADA` recusada; fora da janela exige `corrigir-fora-prazo` e `motivo` não vazio; grava histórico `ALTERACAO` só dos registos cujo estado/observação mudou.
- `MarcarNaoLeccionadaAction::executar(User $u, Aula $aula, string $motivo): Aula` (motivo obrigatório; apaga nada: as presenças ficam, mas a aula deixa de contar; mesma janela e permissão).
- `GuardarDefinicoesAction::executar(int $janelaDias): FrequenciaDefinicoes` (1 a 60; permissão `frequencia.configurar`, verificada no controller).

- [ ] **Step 1: Testes:** `AbrirChamada`: `test_abre_e_cria_todos_como_presentes_com_historico`, `test_data_futura_recusada`, `test_dia_sem_aula_prevista_recusado`, `test_feriado_recusado`, `test_segunda_abertura_devolve_a_mesma_aula_sem_duplicar`, `test_professor_sem_atribuicao_recusado`, `test_substituto_abre`, `test_regime_dia_qualquer_docente_da_turma_abre`, `test_outro_tenant_nao_abre`; `RegistarPresencas`: `test_altera_falta_e_atraso_e_grava_historico_so_do_que_mudou`, `test_aluno_fora_da_lista_recusado`, `test_fora_da_janela_professor_recusado`, `test_fora_da_janela_com_permissao_exige_motivo`, `test_aula_nao_leccionada_recusa_presencas`; `NaoLeccionada`: `test_exige_motivo_e_muda_estado`; `Definicoes`: `test_janela_por_omissao_7_e_limites_1_a_60`.
- [ ] **Step 2:** Correr → FAIL.
- [ ] **Step 3:** Implementar; `LogService` (`comLog`) em todas as Actions como em `Modules/Horario/app/Actions/CriarEntradaAction.php`.
- [ ] **Step 4:** Correr → PASS.
- [ ] **Step 5: Stage.**

### Task 5: Justificação

**Files:** criar `app/Actions/{JustificarFaltaAction,RemoverJustificacaoAction}.php`; teste `tests/Feature/JustificacaoActionsTest.php`.

**Interfaces:** `JustificarFaltaAction::executar(User $u, Registo $registo, string $motivo, ?string $documentoReferencia): Justificacao`; `RemoverJustificacaoAction::executar(User $u, Registo $registo, string $motivo): void`. Regras: o registo tem de ser `FALTA`; `can('frequencia.justificar')`; um professor só se `AutorizacaoDeChamadaService::podeMarcar` para a turma/disciplina/data da aula (quem tem `corrigir-fora-prazo` justifica qualquer); já justificada → `ValidationException`; histórico `JUSTIFICACAO` / `REMOCAO_JUSTIFICACAO` (a remoção guarda o `motivo`). Não há janela para justificar (o atestado chega tarde).

- [ ] **Step 1: Testes:** `test_justifica_falta_e_grava_quem_quando_e_historico`, `test_presente_e_atraso_nao_se_justificam`, `test_professor_so_justifica_as_suas_aulas`, `test_funcionario_justifica_qualquer`, `test_justificar_duas_vezes_recusado`, `test_remover_deixa_historico_e_a_falta_volta_a_injustificada`, `test_sem_motivo_recusado`.
- [ ] **Step 2:** Correr → FAIL.
- [ ] **Step 3:** Implementar.
- [ ] **Step 4:** Correr → PASS.
- [ ] **Step 5: Stage.**

### Task 6: Consultas, resumo de faltas e contratos do Core

**Files:** criar `Modules/Core/app/Contracts/{ConsultaFaltasDoAluno,ConsultaChamadasPendentes}.php`, `app/Services/{ConsultaFrequenciaService,ChamadasPorFazerService,FaltasDoAlunoService}.php`; modificar `FrequenciaServiceProvider` (binds); testes `tests/Feature/{ConsultaFrequenciaServiceTest,ChamadasPorFazerServiceTest,FaltasDoAlunoServiceTest}.php`.

**Interfaces:**
- `ConsultaFaltasDoAluno::resumoDaMatricula(int $matriculaId): array{unidade: 'aulas'|'dias', total: int, justificadas: int, injustificadas: int, por_disciplina: array<int, array{total:int, justificadas:int, injustificadas:int}>, por_periodo: list<array{periodo_id:int, nome:string, total:int, justificadas:int, injustificadas:int}>}` (zeros se não houver nada; `por_periodo` pelas datas de `Periodo`; aulas `NAO_LECCIONADA` não contam; `por_disciplina` vazio no regime `DIA`).
- `ConsultaChamadasPendentes::doUtilizador(int $utilizadorId): int` (aulas previstas dos últimos 7 dias, até hoje, sem aula guardada nem marcada `NAO_LECCIONADA`) e `::daEscola(): int` (idem, todas as turmas do ano activo).
- `ConsultaFrequenciaService`: `aulasDoDia(User $u, CarbonInterface $data): list<array{...AulaPrevista, estado: 'pendente'|'feita'|'nao_leccionada', aula_id: ?int, turma_rotulo, disciplina_nome, horario_nome}>` (só as do professor); `chamada(Aula $aula): array{aula, registos: list<{registo_id, matricula_id, nome, estado, observacao, justificada, motivo_justificacao}>, editavel: bool}`; `pendentes(?int $turmaId): list<...>` (vista da direcção, com o docente); `minhasFaltas(User $aluno): array` (um bloco por matrícula do aluno, com `resumoDaMatricula`).

- [ ] **Step 1: Testes:** resumo: `test_conta_so_faltas_e_separa_justificadas`, `test_atraso_e_presente_nao_contam`, `test_nao_leccionada_nao_conta`, `test_por_disciplina_e_por_periodo`, `test_regime_dia_conta_dias`, `test_sem_registos_devolve_zeros`, `test_ano_sem_periodos_nao_rebenta`; pendentes: `test_aula_sem_chamada_conta_e_com_chamada_ou_nao_leccionada_nao`, `test_so_ultimos_7_dias_ate_hoje`, `test_daescola_conta_todas_as_turmas`, `test_doutilizador_so_as_dele`; consulta: `test_aulas_do_dia_marcam_estado`, `test_chamada_lista_registos_e_editavel_conforme_a_janela`, `test_minhas_faltas_so_do_proprio_aluno`.
- [ ] **Step 2:** Correr → FAIL.
- [ ] **Step 3:** Implementar; sem N+1 (agregar por SQL com `groupBy`); binds dos contratos no provider.
- [ ] **Step 4:** Correr → PASS.
- [ ] **Step 5: Stage.**

### Task 7: HTTP — rotas, controllers, requests, autorização

**Files:** criar controllers, requests, `routes/web.php`, `GestaoFrequenciaService`; testes `tests/Feature/{FrequenciaHttpTest,FrequenciaAutorizacaoTest}.php`.

**Interfaces:** rotas (todas `auth`):

| Método | URI | Nome | Permissão |
|---|---|---|---|
| GET | `/frequencia` | `frequencia.dia` | `frequencia.criar` (aulas do dia do professor; query `data`) |
| GET | `/frequencia/chamada` | `frequencia.chamada` | `frequencia.criar` (query `turma_id`, `data`, `horario_id?`; abre a chamada se ainda não existe) |
| PUT | `/frequencia/aulas/{aula}/presencas` | `frequencia.presencas` | `frequencia.editar` |
| PATCH | `/frequencia/aulas/{aula}/nao-leccionada` | `frequencia.nao-leccionada` | `frequencia.editar` |
| POST | `/frequencia/registos/{registo}/justificacao` | `frequencia.justificar` | `frequencia.justificar` |
| DELETE | `/frequencia/registos/{registo}/justificacao` | `frequencia.remover-justificacao` | `frequencia.justificar` |
| GET | `/frequencia/pendentes` | `frequencia.pendentes` | `frequencia.listar` |
| PUT | `/frequencia/definicoes` | `frequencia.definicoes` | `frequencia.configurar` |
| GET | `/minhas-faltas` | `frequencia.minhas-faltas` | `frequencia.ver-proprias` |

Páginas Inertia: `Frequencia/Dia` (`data`, `aulas`), `Frequencia/Chamada` (`aula`, `registos`, `editavel`, `janelaDias`), `Frequencia/Pendentes` (`pendentes`, `filtros`, `opcoes`), `Frequencia/MinhasFaltas` (`blocos`). O `GET /frequencia/chamada` é a única leitura com efeito (abre a chamada): documentá-lo no controller e proteger contra recarregamentos (idempotente, T4). `{aula}`/`{registo}` com `whereNumber`.

- [ ] **Step 1: Testes:** `test_dia_lista_as_aulas_do_professor`, `test_chamada_abre_e_devolve_registos`, `test_presencas_valido_redirecciona_com_success`, `test_presencas_fora_da_janela_erro_para_professor`, `test_nao_leccionada`, `test_justificar_e_remover`, `test_pendentes_so_com_listar`, `test_definicoes_so_com_configurar`, `test_minhas_faltas_so_do_aluno_autenticado`; autorização: `test_perfis_sem_permissao_recebem_403` (aluno/encarregado em tudo menos `minhas-faltas`; professor em `pendentes` e `definicoes`), `test_funcionario_justifica_mas_nao_marca`, `test_aluno_nao_ve_faltas_de_outro`, `test_registo_ou_aula_de_outro_tenant_da_404`, `test_anonimo_e_redireccionado`.
- [ ] **Step 2:** Correr → FAIL.
- [ ] **Step 3:** Implementar (controllers finos: `authorize` + Service/Action; Requests validam formato e usam `ExisteNaEscola`).
- [ ] **Step 4:** Correr → PASS.
- [ ] **Step 5: Stage.**

### Task 8: Ecrãs do professor, da direcção e do aluno

**Files:** criar `Pages/{Dia,Chamada,Pendentes,MinhasFaltas}.vue`, `Components/{AulaDoDiaCard,LinhaDeAluno,JustificarFaltaModal,NaoLeccionadaModal}.vue`; modificar `SidebarMenuWrapper.vue` e `usePedagogicoMenu.js`. Verificar com `npm run build` e, se a BD de dev estiver migrada, no browser com playwright (sem gravar dados).

**Interfaces:** `Dia.vue` mostra as aulas do dia como cartões (tempo, turma, disciplina, estado) com acção "Fazer chamada"; seletor de data (≤ hoje). `Chamada.vue`: lista de alunos com três botões (Presente/Falta/Atraso), observação opcional, "Guardar" (um PUT com todos os alunos), aviso quando fora da janela ("só quem tem permissão corrige"), botão "Aula não leccionada" (modal com motivo), ícone de falta justificada e acção "Justificar" (modal: motivo + referência do documento). Em ecrã < 768px os alunos são cartões com botões grandes. `Pendentes.vue`: tabela (data, turma, disciplina, docente) com filtro por turma. `MinhasFaltas.vue`: por matrícula, totais, justificadas, por disciplina e por período. Menu: item "Frequência" no grupo Pedagógico (`frequencia.listar`), "As minhas chamadas" na Área do Professor (`frequencia.criar`), "As minhas faltas" (`frequencia.ver-proprias`). Reaproveitar o aspecto de `Horario/Components` e os modais com `Cancelar` em `btn-light-danger`.

- [ ] **Step 1:** Escrever as páginas e componentes a partir das props da Task 7.
- [ ] **Step 2:** `npm run build` → sem erros.
- [ ] **Step 3:** Verificar o percurso no browser (só leitura) quando a BD de dev estiver migrada; medir `getComputedStyle` onde o aspecto importa; viewport 375px.
- [ ] **Step 4: Stage** (incluindo o build gerado).

### Task 9: Boletim, dashboard, tenancy e fecho

**Files:** modificar `Modules/Avaliacao/app/Services/BoletimDocumentoService.php`, `app/Services/DashboardService.php`, `tests/Feature/Tenancy/MatrizIsolamentoTest.php` (+ `'Frequencia' => 'Modules/Frequencia/tests/Feature/FrequenciaTenancyTest.php'`); criar `Modules/Frequencia/tests/Feature/FrequenciaTenancyTest.php`; testes em `Modules/Avaliacao/tests/Feature/BoletimFaltasTest.php` e `tests/Feature/DashboardFrequenciaTest.php`.

**Interfaces:** O `BoletimDocumentoService` recebe `ConsultaFaltasDoAluno` e, por aluno: turmas em regime `TEMPO` ganham a coluna "Faltas" (total do ano por disciplina; vazio se zero é "0"); turmas em regime `DIA` ganham na identificação `['Faltas (dias)', '<total> (<justificadas> justificadas)']`. Só o Boletim consome o contrato (a Avaliacao não importa `Frequencia`). O `DashboardService::atencao` acrescenta `['rotulo' => 'Chamadas por fazer', 'quantidade' => …, 'valor' => null, 'cor' => 'warning', 'link' => '/frequencia']` para quem tem `frequencia.criar` (contagem `doUtilizador`) e `['rotulo' => 'Aulas sem chamada', …, 'link' => '/frequencia/pendentes']` para quem tem `frequencia.listar` (contagem `daEscola`).

- [ ] **Step 1: Testes:** `test_boletim_tempo_tem_coluna_de_faltas_por_disciplina`, `test_boletim_dia_tem_linha_de_faltas_na_identificacao`, `test_boletim_sem_faltas_mostra_zero`, `test_dashboard_professor_ve_chamadas_por_fazer_e_direccao_aulas_sem_chamada`, `test_dashboard_sem_pendentes_nao_mostra_o_item`; tenancy: `test_pedir_no_dominio_de_a_uma_aula_de_b_da_404`, `test_a_base_recusa_referencias_a_outro_tenant`, `test_alterar_o_tenant_de_uma_aula_lanca_excepcao`.
- [ ] **Step 2:** Correr → FAIL.
- [ ] **Step 3:** Implementar as duas integrações e o teste de tenancy.
- [ ] **Step 4:** Suite completa `php artisan test --parallel` → sem regressões.
- [ ] **Step 5: Stage** e listar `git status` ao dono. Actualizar o spec (estado) e a memória do projecto (feito pelo assistente, fora do commit).

---

## Self-Review

- Cobertura do spec §6: aula prevista calculada (T3), aula guardada como fotografia (T2, T4), chamada por tempo e por dia (T3, T4), quem marca (T4), estados e "não leccionada" (T4), justificação com histórico (T5), janela de correcção (T4), boletim (T9, R4), dashboard aluno/professor/direcção (T6, T8, T9). D12 (sem docente) tratado em T3 (`sem_docente`) e T6 (pendentes mostram "sem docente"). Fora: alerta por excesso de faltas, notificações, sumários, reposição, anexos (R1).
- Tipos consistentes: `AulaPrevista`, `PresencaDTO`, `TipoAula`, `EstadoPresenca` e os métodos dos dois contratos usam os mesmos nomes de T3 a T9.
- O implementador confirma no código: o nome exacto do campo do nome do aluno em `Aluno`/`DadosPessoa` para ordenar a lista (T3), o formato do `EventoCalendario` que cobre intervalos (T3), e o padrão actual de `atencao()` no `DashboardService` (T9).
- Proporção: o plano é cerca de 2,5 vezes o spec; os blocos de código foram reservados a assinaturas e nomes de testes.
