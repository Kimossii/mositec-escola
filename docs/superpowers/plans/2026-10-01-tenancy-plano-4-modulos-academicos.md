# Tenancy — Plano 4: Módulos académicos (etapa 6)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Isolar por tenant todas as tabelas académicas (AnoLectivo e horários, Curso, Disciplina, Infraestrutura, Turma, PlanoCurricular, Aluno, Matricula), com `tenant_id NOT NULL`, chave estrangeira composta `(tenant_id, estabelecimento_id)` onde há `estabelecimento_id`, únicos por tenant onde o spec o manda, e uma linha da matriz de isolamento por módulo.

**Architecture:** O mesmo padrão já aplicado à identidade (Plano 3), repetido por módulo, por ordem de dependência: editar a migration original (a BD é recriada, premissa P6), pôr a trait `PertenceAoTenant` nos models, retirar a tabela de `tenancy.tabelas_por_converter`, e provar o isolamento. Controllers, FormRequests, DTOs, Services e Repositories **não mudam de assinatura** (spec §17.3). As regras `exists`/`unique` ficam protegidas pelo `VerificadorPresencaTenant` assim que a tabela sai da lista de transição.

**Tech Stack:** PHP 8.2, Laravel 12, PHPUnit 11, SQLite em memória nos testes (PostgreSQL em desenvolvimento).

**Spec:** `docs/superpowers/specs/2026-09-30-fundacao-tenancy-design.md` — etapa 6 de §20.2; §7 (isolamento), §8 (`tenant_id` + `estabelecimento_id`, chave composta), §9 (validação), §17.1 a 17.3 (classificação, únicos, módulos), §19.2 e §19.3 (matriz e testes de arquitectura 2 e 8). Depende dos Planos 1 a 3b.

**Fora deste plano:** sequências (`matricula_sequencias`, `matricula_registo_sequencias`) e os geradores de número: etapa 7. Ficheiros e fotos de alunos: etapa 8. Provisionamento: etapa 9. Estas duas tabelas **ficam em `tabelas_por_converter`** até à etapa 7.

## Global Constraints

- **Nunca fazer commit.** O dono do repositório faz os commits. Cada tarefa termina com `git add` dos ficheiros tocados (nunca `.superpowers`). Nunca `git stash`.
- **Nunca escrever na BD de desenvolvimento por tinker ou SQL.** Só testes (SQLite em memória). Nunca `migrate:fresh --seed` sem confirmação: é o utilizador quem o corre (o sistema de permissões do agente bloqueia-o). Este plano altera migrations originais: a Tarefa 7 pára e pede-lho.
- Fail-closed: sem tenant resolvido, o código tenant-scoped lança `TenantNaoResolvido`.
- `tenant_id` nunca em `$fillable`, nunca em DTOs, nunca vindo do input. Vem do contexto.
- Não se usa `withoutGlobalScopes`, `DB::table(` (salvo os dois geradores de sequência, que ficam como estão até à etapa 7), fachada `Cache` fora de `Core/Tenancy`, nem `definir()`/`limpar()` do `TenantContext` fora do runtime.
- Controllers finos; leitura em Service, escrita em Action. Em PHP, `use` no topo, nunca FQN inline. Mensagens e comentários em português.
- **Não mexer** em assinaturas de Controllers/FormRequests/DTOs/Services/Repositories, em Vue, em rotas nem em permissões, salvo onde a tarefa o diga. Os testes existentes mantêm as asserções; só muda a preparação (por omissão o `Tests\TestCase` já cria um tenant com estabelecimento e dirige os pedidos para o domínio dele).
- Comando de testes: `php artisan test`. A suite completa tem de passar no fim de cada tarefa (baseline à entrada: 884 passed, 1 incomplete; o incomplete é `AnoLectivoTenancyTest`, que a Tarefa 1 passa a ser um teste real).
- Chaves compostas só nas tabelas com `estabelecimento_id`: `cursos`, `disciplinas`, `salas`, `turnos`, `niveis_academicos`, `planos_curriculares`, `ano_lectivos`, `alunos`. Os índices únicos existentes com `estabelecimento_id` ou com o ID de um pai (`(ano_lectivo_id, codigo)`, `(turno_id, ordem)`, …) **ficam como estão** (spec §8.2 e §17.2).
- Únicos que mudam neste plano: `alunos.numero_matricula` → `(tenant_id, numero_matricula)`; `matriculas.numero_registo_matricula` → `(tenant_id, numero_registo_matricula)`. Os dois geradores de sequência continuam globais até à etapa 7: a unicidade por tenant não colide porque a contagem global não repete números.
- Se um teste existente falhar por usar inserção directa sem `tenant_id` (`DB::table(...)->insert`, factories sem tenant), corrigir a **preparação** do teste (criar pelo Model com o contexto) e nunca a asserção.

## Padrão de conversão (aplica-se a todas as tarefas)

**1. Migration original** (editada no sítio; a BD é recriada). Em cada `Schema::create`, logo a seguir a `$table->id();`:

```php
$table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
```

Nas tabelas **com** `estabelecimento_id` acrescenta-se, depois das colunas e antes dos índices únicos, a chave composta (a coluna `estabelecimento_id` mantém a sua `constrained()` actual; a composta soma-se a ela, como em `Modules/Estabelecimento/database/migrations/2026_09_11_120000_create_estabelecimento_etapas_ensino_table.php`):

```php
$table->foreign(['tenant_id', 'estabelecimento_id'])
    ->references(['tenant_id', 'id'])
    ->on('estabelecimentos')
    ->restrictOnDelete();
```

Se `estabelecimento_id` for `nullable` (`ano_lectivos`, `salas`), a composta continua válida (com `NULL` não é verificada) e o teste de arquitectura 8 já a exige.

Nas tabelas filhas (só `tenant_id`) não há chave composta nem índice novo, **salvo** os dois únicos de §Global Constraints. Migrations posteriores (`add_*`, `make_*_nullable`, `move_*`) que mexam em colunas dessas tabelas não precisam de alteração, salvo se recriarem a tabela: verificar com `php artisan migrate:fresh` em SQLite (o `RefreshDatabase` dos testes basta).

**2. Model:** acrescentar `use Modules\Core\Tenancy\PertenceAoTenant;` e `PertenceAoTenant` à lista de traits. Não acrescentar `tenant_id` a `$fillable`. Para `belongsToMany`/pivots escritos com `attach`/`sync` usar `withPivotValue('tenant_id', …)` como `User::roles()` (só se existirem; os pivots académicos têm Model próprio).

**3. Config:** retirar da lista `tabelas_por_converter` em `config/tenancy.php` as tabelas da tarefa.

**4. Seeders e código que escreve fora do Eloquent:** `grep -rn "DB::table\|::insert(\|insertGetId\|upsert(" <módulo>` e converter para o Model (o contexto preenche o `tenant_id`). Os seeders do módulo correm dentro do tenant (`DatabaseSeeder` usa o contexto) e criam por Model; confirmar que não escrevem `tenant_id` à mão.

**5. Teste de isolamento do módulo** (`Modules/<Modulo>/tests/Feature/<Modulo>TenancyTest.php`), com tenant A (`$this->tenant`, domínio por omissão) e tenant B (`$this->criarTenant('MOSI-000002', 'Escola B', 'b.localhost')`; dados de B criados com `$this->noTenant($outro, fn () => …)`; o estabelecimento de B vem de `$this->estabelecimentoDeOutroTenant()`). Casos obrigatórios, nomeados por tarefa:
- `test_a_listagem_mostra_so_os_registos_do_tenant_do_dominio` (pedido HTTP autenticado como administrador de A; B ausente da resposta; controlo positivo: A presente);
- `test_pedir_no_dominio_de_a_um_id_de_b_da_404` (route model binding; controlo positivo com um id de A);
- `test_exists_rejeita_o_id_de_outro_tenant` (`Validator::make(['x' => $idDeB], ['x' => 'exists:<tabela>,id'])` falha; com `$idDeA` passa);
- `test_unique_e_por_tenant` (o mesmo código/nome/número existe em A e B; duplicado dentro de A falha na BD e na validação `unique`);
- `test_criar_grava_o_tenant_do_contexto_e_alterar_o_tenant_lanca_excepcao` (`AlteracaoDeTenantProibida`);
- `test_sem_contexto_lanca_tenant_nao_resolvido` (`TenantContext::limpar()` só no teste via o helper existente, ver `PertenceAoTenantTest`; lê e escreve);
- onde a tabela tem `estabelecimento_id`: `test_a_bd_rejeita_estabelecimento_de_outro_tenant` (`QueryException` ao inserir `tenant_id` de A com `estabelecimento_id` de B, por SQL directo no teste);
- onde a tabela tem FK para outra tabela do módulo: `test_a_relacao_nao_atravessa_tenants` (um Model de A não resolve o pai de B por Eloquent).
Os testes de utilizador autenticado usam o administrador do tenant (padrão de `tests/Feature/Tenancy/IdentidadeIsolamentoTest.php`: `PermissaoDatabaseSeeder` + `Perfil::ADMIN_ESCOLA`).

**6. Verificação por mutação** (reverter e confirmar `git diff` limpo): (a) retirar a trait de um Model da tarefa → pelo menos um teste novo falha; (b) retirar a chave composta de uma migration (onde exista) → o teste de arquitectura 8 e o teste da BD falham; (c) deixar uma tabela em `tabelas_por_converter` → o teste de esquema e o `exists` de B passam a divergir (o teste `exists` falha).

## Review Focus

1. **`exists`/`unique` em FormRequests existentes** (cerca de 70): um ID de outro tenant tem de falhar com a mesma mensagem de um ID inexistente → cada tarefa, `test_exists_rejeita_o_id_de_outro_tenant` e um teste HTTP de submissão (`POST`/`PUT` com um id de B num campo `*_id`) que devolve erro de validação 302/422 e não grava.
2. **IDs encadeados** (Turma → AnoLectivo/Nível/Curso, Matrícula → Aluno/Turma/AnoLectivo, Inscrição → disciplina do plano): criar em A referenciando um pai de B falha na validação e, se contornada, no carregamento Eloquent → Tarefas 3, 4 e 6.
3. **Código que corre fora do pedido** (Services/Actions com `DB::transaction` e queries agregadas, `Model::count()`, `exists()` de unicidade escrito à mão, ordenações): confirmar que passam pelo scope; contagens e totais de A não incluem B → cada tarefa, no teste de listagem/Service.
4. **Chaves únicas compostas por tenant** (`alunos.numero_matricula`, `matriculas.numero_registo_matricula`) e geradores globais: o mesmo número em A e B é aceite; duplicado em A é recusado → Tarefas 5 e 6.
5. **Seeders e dados de desenvolvimento**: os seeders académicos que existem criam dados do tenant do contexto e nunca `tenant_id` de outro; `DatabaseSeeder` continua a correr → Tarefa 7.

## Mapa de ficheiros

| Tarefa | Módulo(s) | Migrations editadas | Models com a trait |
|---|---|---|---|
| 1 | AnoLectivo, Core | `ano_lectivos`, `periodos`, `eventos_calendario`; Core `horarios` | `AnoLectivo`, `Periodo`, `EventoCalendario`, `Modules\Core\Models\Horario` |
| 2 | Curso, Disciplina, Infraestrutura | `cursos`, `disciplinas`, `salas` | `Curso`, `Disciplina`, `Sala` |
| 3 | Turma | `turnos`, `niveis_academicos`, `turmas`, `turno_horarios`, `turma_salas` | `Turno`, `NivelAcademico`, `Turma`, `TurnoHorario`, `TurmaSala` |
| 4 | PlanoCurricular | `planos_curriculares`, `plano_curricular_disciplinas`, `plano_curricular_anos_lectivos`, `plano_curricular_disciplina_periodos` | `PlanoCurricular`, `PlanoCurricularDisciplina`, `PlanoCurricularAnoLectivo`, `PlanoCurricularDisciplinaPeriodo` |
| 5 | Aluno | `alunos` (+ único `(tenant_id, numero_matricula)`), `aluno_enquadramentos_academicos` | `Aluno`, `AlunoEnquadramentoAcademico` |
| 6 | Matricula | `matriculas` (+ único `(tenant_id, numero_registo_matricula)`), `matricula_historicos`, `inscricoes_disciplinas` | `Matricula`, `MatriculaHistorico`, `InscricaoDisciplina` |
| 7 | transversal | — | `tests/Feature/Tenancy/AcademicoIsolamentoTest.php`, ajuste de `TenancyEsquemaTest.php` |

Ficam de fora (etapa 7): `matricula_sequencias` e `matricula_registo_sequencias` (e os models `MatriculaSequencia`, `MatriculaRegistoSequencia`).

---

### Task 1: AnoLectivo e horários

**Files:**
- Modify: `Modules/AnoLectivo/database/migrations/2026_08_31_090000_create_ano_lectivos_table.php`, `2026_08_31_090100_create_periodos_table.php`, `2026_08_31_090200_create_eventos_calendario_table.php`
- Modify: `Modules/Core/database/migrations/2026_09_02_133732_create_horarios_table.php`
- Modify: `Modules/AnoLectivo/app/Models/AnoLectivo.php`, `Periodo.php`, `EventoCalendario.php`; `Modules/Core/app/Models/Horario.php`
- Modify: `config/tenancy.php`
- Test: `Modules/AnoLectivo/tests/Feature/AnoLectivoTenancyTest.php` (hoje `markTestIncomplete`: **substituir** pelo teste real), `Modules/Core/tests/Feature/HorarioTenancyTest.php`

**Interfaces:**
- Consumes: `PertenceAoTenant`, helpers `ComTenantDeTeste`.
- Produces: `ano_lectivos`, `periodos`, `eventos_calendario`, `horarios` com `tenant_id NOT NULL`; `ano_lectivos` com chave composta ao estabelecimento. Usado pelas Tarefas 3, 4 e 6 (FKs para `ano_lectivos`, `periodos`, `horarios`).

- [ ] **Step 1: Escrever os testes.** `AnoLectivoTenancyTest` com os casos do Padrão de conversão (5) para `ano_lectivos` (único `(estabelecimento_id, nome)`: o mesmo nome em A e B é aceite), `periodos` (relação ao ano lectivo não atravessa tenants) e `eventos_calendario`; mais `test_a_bd_rejeita_estabelecimento_de_outro_tenant` em `ano_lectivos`. `HorarioTenancyTest`: listagem, id de B → 404 se existir rota, `exists:horarios,id`, criar e sem contexto. Remover o `markTestIncomplete` e o comentário que o justifica.
- [ ] **Step 2: Correr para ver falhar.** `php artisan test --filter="AnoLectivoTenancyTest|HorarioTenancyTest"` → FAIL (sem `tenant_id`).
- [ ] **Step 3: Converter** segundo o Padrão (migrations, trait nos 4 Models, retirar `ano_lectivos`, `periodos`, `eventos_calendario`, `horarios` de `tabelas_por_converter`, procurar escritas fora do Eloquent e corrigir testes existentes por preparação).
- [ ] **Step 4: Correr os testes novos e a suite completa.** Esperado: PASS; o incomplete desaparece (883+ passed, 0 incomplete).
- [ ] **Step 5: Mutação** (Padrão §6) num Model e na chave composta de `ano_lectivos`.
- [ ] **Step 6: Stage.** `git add` dos ficheiros tocados. Sem commit.

---

### Task 2: Curso, Disciplina, Infraestrutura

**Files:**
- Modify: `Modules/Curso/database/migrations/2026_09_09_090000_create_cursos_table.php`, `Modules/Disciplina/database/migrations/2026_09_09_090000_create_disciplinas_table.php`, `Modules/Infraestrutura/database/migrations/2026_09_05_100000_create_salas_table.php`
- Modify: `Modules/Curso/app/Models/Curso.php`, `Modules/Disciplina/app/Models/Disciplina.php`, `Modules/Infraestrutura/app/Models/Sala.php`; `config/tenancy.php`
- Modify (se escreverem fora do Eloquent): `Modules/Curso/database/seeders/CursoSeeder.php`, `Modules/Disciplina/database/seeders/DisciplinaSeeder.php`, `Modules/Infraestrutura/database/seeders/SalaSeeder.php`
- Test: `Modules/Curso/tests/Feature/CursoTenancyTest.php`, `Modules/Disciplina/tests/Feature/DisciplinaTenancyTest.php`, `Modules/Infraestrutura/tests/Feature/SalaTenancyTest.php`

**Interfaces:**
- Produces: `cursos`, `disciplinas`, `salas` com `tenant_id` e chave composta. Únicos mantidos: `(estabelecimento_id, codigo)`, `(estabelecimento_id, nome)` (cursos, disciplinas), `(estabelecimento_id, codigo)` (salas). Usado pelas Tarefas 3, 4 e 5.

- [ ] **Step 1: Escrever os testes** (um ficheiro por módulo, casos do Padrão 5 incluindo o teste da chave composta e `unique` por tenant com o mesmo `codigo`/`nome` em A e B), mais um teste HTTP de submissão: `POST` de criação em A com um campo de FormRequest que referencie id de B (onde exista; por exemplo, `estabelecimento`/`curso_id` se o formulário o aceitar) não grava.
- [ ] **Step 2: Correr para ver falhar.**
- [ ] **Step 3: Converter** (Padrão 1 a 4).
- [ ] **Step 4: Testes novos e suite completa.**
- [ ] **Step 5: Mutação** (Padrão 6).
- [ ] **Step 6: Stage.**

---

### Task 3: Turma

**Files:**
- Modify: `Modules/Turma/database/migrations/2026_09_08_105633_create_turnos_table.php`, `2026_09_08_110000_create_niveis_academicos_table.php`, `2026_09_08_111148_create_turmas_table.php`, `2026_09_08_105813_create_turno_horarios_table.php`, `2026_09_08_111339_create_turma_salas_table.php`
- Modify: `Modules/Turma/app/Models/Turno.php`, `NivelAcademico.php`, `Turma.php`, `TurnoHorario.php`, `TurmaSala.php`; `config/tenancy.php`
- Modify (se escreverem fora do Eloquent): `Modules/Turma/database/seeders/TurnoSeeder.php`
- Test: `Modules/Turma/tests/Feature/TurmaTenancyTest.php`

**Interfaces:**
- Consumes: `ano_lectivos`, `horarios` (Tarefa 1), `salas`, `cursos` (Tarefa 2).
- Produces: as cinco tabelas com `tenant_id`; `turnos` e `niveis_academicos` com chave composta. Usado pelas Tarefas 4 e 6.

- [ ] **Step 1: Escrever os testes** (Padrão 5 para `turnos`, `niveis_academicos` e `turmas`; `unique`: `(ano_lectivo_id, codigo)` em turmas, `(estabelecimento_id, nome)` em turnos e `(estabelecimento_id, codigo|nome)` em níveis são aceites iguais em A e B), mais: `test_turma_nao_referencia_ano_lectivo_nivel_ou_curso_de_outro_tenant` (criar `Turma` em A com `ano_lectivo_id` de B falha na validação do FormRequest existente por HTTP e o `exists` rejeita), `test_turma_salas_e_turno_horarios_nao_atravessam_tenants` (pivots com Model próprio com `tenant_id` do contexto), e `test_contagens_de_turma_so_contam_o_tenant` se existir Service com contagem.
- [ ] **Step 2: Correr para ver falhar.**
- [ ] **Step 3: Converter** (Padrão 1 a 4; `turnos` e `niveis_academicos` com chave composta; `turmas`, `turma_salas`, `turno_horarios` só `tenant_id`).
- [ ] **Step 4: Testes novos e suite completa.**
- [ ] **Step 5: Mutação** (Padrão 6).
- [ ] **Step 6: Stage.**

---

### Task 4: PlanoCurricular

**Files:**
- Modify: `Modules/PlanoCurricular/database/migrations/2026_09_10_090100_create_planos_curriculares_table.php`, `2026_09_10_090200_create_plano_curricular_disciplinas_table.php`, `2026_09_10_090300_create_plano_curricular_anos_lectivos_table.php`, `2026_09_11_100000_create_plano_curricular_disciplina_periodos_table.php`
- Modify: `Modules/PlanoCurricular/app/Models/PlanoCurricular.php`, `PlanoCurricularDisciplina.php`, `PlanoCurricularAnoLectivo.php`, `PlanoCurricularDisciplinaPeriodo.php`; `config/tenancy.php`
- Modify (se escrever fora do Eloquent): `Modules/PlanoCurricular/database/seeders/PlanoCurricularDatabaseSeeder.php`
- Test: `Modules/PlanoCurricular/tests/Feature/PlanoCurricularTenancyTest.php`

**Interfaces:**
- Consumes: `cursos`, `disciplinas` (Tarefa 2), `niveis_academicos` (Tarefa 3), `ano_lectivos`, `periodos` (Tarefa 1).
- Produces: as quatro tabelas com `tenant_id`; `planos_curriculares` com chave composta. Usado pela Tarefa 6 (`plano_curricular_disciplinas`).

- [ ] **Step 1: Escrever os testes** (Padrão 5 para `planos_curriculares` com `unique (estabelecimento_id, codigo)` igual em A e B; filhas: `test_o_plano_nao_referencia_curso_disciplina_nivel_ou_ano_de_outro_tenant` por HTTP nos FormRequests existentes; `test_totais_do_plano_so_contam_o_tenant` se houver agregações).
- [ ] **Step 2: Correr para ver falhar.**
- [ ] **Step 3: Converter** (Padrão 1 a 4).
- [ ] **Step 4: Testes novos e suite completa.**
- [ ] **Step 5: Mutação** (Padrão 6).
- [ ] **Step 6: Stage.**

---

### Task 5: Aluno

**Files:**
- Modify: `Modules/Aluno/database/migrations/2026_09_10_000001_create_alunos_table.php` (`tenant_id`, chave composta, e trocar `numero_matricula->unique()` por `$table->unique(['tenant_id', 'numero_matricula']);`; `dados_pessoa_id->unique()` fica), `2026_09_14_124406_create_aluno_enquadramentos_academicos_table.php`
- Modify: `Modules/Aluno/app/Models/Aluno.php`, `AlunoEnquadramentoAcademico.php`; `config/tenancy.php`
- Test: `Modules/Aluno/tests/Feature/AlunoTenancyTest.php`

**Interfaces:**
- Consumes: `dados_pessoas` e `users` (já por tenant, Plano 3), `cursos`, `niveis_academicos`.
- Produces: `alunos` (com chave composta) e `aluno_enquadramentos_academicos` com `tenant_id`; único `alunos (tenant_id, numero_matricula)`. As fotos (`foto_path`, disco `public`) **não mudam** neste plano (etapa 8).

- [ ] **Step 1: Escrever os testes** (Padrão 5; `unique`: o mesmo `numero_matricula` em A e B aceite, duplicado em A falha na BD e na validação; `test_aluno_nao_referencia_dados_pessoa_de_outro_tenant` por `exists`; `test_a_bd_rejeita_estabelecimento_de_outro_tenant`).
- [ ] **Step 2: Correr para ver falhar.**
- [ ] **Step 3: Converter** (Padrão 1 a 4; confirmar que `GestaoAlunoService`/Actions que criam `DadosPessoa` + `Aluno` mantêm o contexto na transacção).
- [ ] **Step 4: Testes novos e suite completa.**
- [ ] **Step 5: Mutação** (Padrão 6).
- [ ] **Step 6: Stage.**

---

### Task 6: Matricula

**Files:**
- Modify: `Modules/Matricula/database/migrations/2026_09_14_110647_create_matriculas_table.php` (`tenant_id`; trocar `numero_registo_matricula->unique()` por `$table->unique(['tenant_id', 'numero_registo_matricula']);`), `2026_09_16_090000_create_matricula_historicos_table.php`, `2026_09_16_090000_create_inscricoes_disciplinas_table.php`
- Modify: `Modules/Matricula/app/Models/Matricula.php`, `MatriculaHistorico.php`, `InscricaoDisciplina.php`; `config/tenancy.php` (retirar só `matriculas`, `matricula_historicos`, `inscricoes_disciplinas`; **`matricula_registo_sequencias` e `matricula_sequencias` ficam**)
- Modify (se escrever fora do Eloquent): `Modules/Matricula/database/seeders/MatriculaDatabaseSeeder.php`
- Test: `Modules/Matricula/tests/Feature/MatriculaTenancyTest.php`

**Interfaces:**
- Consumes: `alunos` (Tarefa 5), `turmas` (Tarefa 3), `ano_lectivos` (Tarefa 1), `plano_curricular_disciplinas` (Tarefa 4).
- Produces: as três tabelas com `tenant_id`; único `matriculas (tenant_id, numero_registo_matricula)`. `GeradorNumeroRegistoMatriculaService` e `MatriculaRegistoSequencia` não mudam (etapa 7).

- [ ] **Step 1: Escrever os testes** (Padrão 5; `unique`: o mesmo `numero_registo_matricula` em A e B aceite, duplicado em A recusado; `test_matricula_nao_referencia_aluno_turma_ano_ou_disciplina_do_plano_de_outro_tenant` por HTTP nos FormRequests/Actions existentes; `test_historico_e_inscricoes_gravam_o_tenant_do_contexto`; `test_o_gerador_de_numero_continua_a_funcionar_com_dois_tenants` (dois números gerados em A e B nunca colidem)).
- [ ] **Step 2: Correr para ver falhar.**
- [ ] **Step 3: Converter** (Padrão 1 a 4; as duas tabelas de sequência não se tocam).
- [ ] **Step 4: Testes novos e suite completa.**
- [ ] **Step 5: Mutação** (Padrão 6).
- [ ] **Step 6: Stage.**

---

### Task 7: Matriz transversal e fecho

**Files:**
- Create: `tests/Feature/Tenancy/AcademicoIsolamentoTest.php`
- Modify: `tests/Feature/Arquitectura/TenancyEsquemaTest.php` (acrescentar o teste das tabelas académicas convertidas, ao estilo de `test_as_tabelas_de_identidade_ja_nao_estao_na_lista_de_transicao`, e as chaves únicas `alunos` e `matriculas`)
- Verify: `config/tenancy.php` (só devem restar `matricula_sequencias` e `matricula_registo_sequencias` em `tabelas_por_converter`)

- [ ] **Step 1: Escrever o teste ponta a ponta** `AcademicoIsolamentoTest` com dois tenants populados em todos os módulos (um registo de cada entidade em A e em B): para cada listagem HTTP académica (`/ano-lectivos`, `/cursos`, `/disciplinas`, salas, turmas, planos curriculares, alunos, matrículas — procurar as rotas reais com `php artisan route:list`), A só vê os dele; pedir o id de B de cada entidade no domínio de A dá 404; e um teste de chaves compostas que percorre as oito tabelas com `estabelecimento_id`.
- [ ] **Step 2: Teste de esquema.** As 21 tabelas convertidas já não estão em `tabelas_por_converter` e têm `tenant_id NOT NULL` com FK para `tenants`; únicos `(tenant_id, numero_matricula)` e `(tenant_id, numero_registo_matricula)` presentes; `tabelas_por_converter` contém exactamente as duas tabelas de sequência.
- [ ] **Step 3: Suite completa, `npm run build` e greps.** `php artisan test` (0 falhas, 0 incomplete); `grep -rn "withoutGlobalScopes\|DB::table(" Modules app` só nos testes e nos dois geradores; os testes de arquitectura verdes.
- [ ] **Step 4: BD de desenvolvimento.** As migrations de `Modules/*/database/migrations` mudaram: **parar e pedir ao utilizador para correr** `php artisan migrate:fresh --seed` (o sistema de permissões do agente bloqueia-o). Depois da confirmação dele, verificar por HTTP (cookie jar, sem escrever na BD por fora da app): login do admin; as listagens académicas respondem 200; o segundo tenant (se `TenantDesenvolvimentoSeeder` o criar) não vê os dados do primeiro.
- [ ] **Step 5: Stage** e relatório final.

## Auto-revisão

**Cobertura do spec (etapa 6):**
- AnoLectivo e horários → Tarefa 1; Curso, Disciplina, Infraestrutura → Tarefa 2; Turma → Tarefa 3; PlanoCurricular → Tarefa 4; Aluno → Tarefa 5; Matricula → Tarefa 6, na ordem de dependência de §20.2.
- Chaves compostas (arquitectura 8) nas oito tabelas com `estabelecimento_id` → Tarefas 1 a 5 e Tarefa 7.
- Únicos que mudam (§17.2) para `alunos` e `matriculas` → Tarefas 5 e 6; as duas sequências ficam para a etapa 7, como o spec manda.
- Matriz §19.2 (listagem, id directo, `exists`, `unique`, Repository/Service, Action, sem contexto, consistência) → Padrão 5 em cada tarefa e Tarefa 7.
- `AnoLectivoTenancyTest` deixa de ser `markTestIncomplete` → Tarefa 1.

**Fica deliberadamente fora:** sequências e geradores globais (etapa 7); fotos de alunos e caminhos de documentos (etapa 8); provisionamento dos seeders académicos por tenant (etapa 9); jobs e commands (etapa 11).
