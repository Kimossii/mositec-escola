# Atribuição docente — desenho

**Data:** 2026-10-28
**Estado:** proposta para aprovação (nada implementado)
**Contexto:** Fase 1 do plano académico (Professores → Avaliações → Consultas → Dashboard do Aluno). Esta é a primeira fatia, escolhida porque não depende de notas e desbloqueia a Área do Professor e o futuro módulo `Avaliacao`.

## 1. Intenção

Hoje o professor é só um utilizador com um perfil e um formulário. Não existe forma de dizer **quem lecciona o quê, a quem e quando**. Esta fatia cria essa informação como entidade própria e auditável, para que:

- a secretaria saiba que disciplinas de cada turma têm professor e quais estão por cobrir;
- o sistema consiga responder, por contrato, à pergunta "este professor pode gerir esta disciplina nesta turma?" — a pergunta de que o módulo `Avaliacao` vai depender para decidir quem lança notas;
- substituições e mudanças a meio do ano não apaguem o histórico (a pauta de um período deve poder dizer quem leccionou naquele momento).

## 2. Objectivos, não-objectivos, anti-objectivos

**Objectivos**
- Entidade `AtribuicaoDocente` com professor, turma, disciplina, ano lectivo, papel (titular/substituto), vigência e estado.
- Ecrã de gestão para a secretaria, com vista **por turma** que destaca disciplinas sem professor.
- Operações: atribuir, substituir titular, encerrar, editar vigência/papel.
- Contrato no Core para consulta/validação, implementado por este módulo.
- Permissões no sistema existente (enum `Modulo` + seed).

**Não-objectivos (ficam para outras fatias)**
- Área do Professor ("as minhas turmas") — próxima fatia; este desenho só garante que a consulta existe no serviço.
- Qualquer coisa de notas, avaliações, pautas, frequência.
- Horários / grelha de aulas por professor.
- Co-docência com vários titulares em simultâneo.
- Carga horária contratual, disponibilidade, habilitações do professor.
- Atribuição automática ou em massa por importação.

**Anti-objectivos (seria falha mesmo funcionando)**
- Um `professor_id` solto numa disciplina ou turma, sem vigência nem histórico.
- Apagar o titular anterior ao substituir.
- Dependências circulares entre módulos.
- Regras de atribuição só no frontend.

## 3. Decisões e as suas origens

| # | Decisão | Origem |
|---|---|---|
| D1 | Atribuição é entidade própria com papel, vigência e estado | Acordada com o dono (conversa de 2026-10-28) |
| D2 | Titular e substituto; várias atribuições ao longo do ano, histórico preservado | Acordada |
| D3 | Professor tem sempre conta de utilizador (perfil `Perfil::PROFESSOR`) | Acordada para a V1 |
| D4 | **Módulo novo `Docente`** (não em `Turma`) | **Minha decisão.** `Turma` e `PlanoCurricular` já se importam mutuamente (`NivelAcademico` ↔ `PlanoCurricular`, ciclo existente que não vamos agravar). Um módulo próprio só depende de módulos a montante, nada o importa, e o resto do sistema fala com ele pelo contrato do Core |
| D5 | Atribuição por **turma + disciplina do catálogo** (`disciplina_id`), não por `PlanoCurricularDisciplina` | **Minha decisão.** A turma não tem ligação directa ao plano; a ligação aluno↔disciplina vive em `InscricaoDisciplina → PlanoCurricularDisciplina → disciplina_id`, por isso os alunos de uma atribuição obtêm-se por `disciplina_id` sem precisar de resolver qual plano |
| D6 | Disciplinas atribuíveis a uma turma = disciplinas do plano que o sistema já resolve para a turma, reutilizando `InscreverDisciplinasAutomaticamenteAction::resolverPlanoCurricular(Turma)` (único sítio que sabe qual é o plano confirmado: curso, nível, estado e ano lectivo). Um só plano; só linhas `plano_curricular_disciplinas` com `estado = 1` | **Minha decisão (corrige a primeira versão deste desenho, que inventava uma regra nova de correspondência e podia divergir da matrícula)** |
| D7 | Vigência por datas. No máximo um titular em vigor em cada dia por (turma, disciplina); substitutos em número livre. Sem coluna `estado` guardada: o estado deriva das datas (agendada, em curso, terminada) | **Minha decisão** |
| D8 | "Substituir titular" é uma operação atómica: define `data_fim` no titular actual e cria o novo a partir do dia seguinte | **Minha decisão** |
| D9 | Substituto e titular podem estar em vigor ao mesmo tempo e ambos podem gerir a disciplina. Pode existir substituto sem titular; nesse caso a disciplina continua a contar como "sem titular" | **Minha decisão** |
| D10 | "É professor" = o utilizador tem o perfil `PROFESSOR` entre os seus perfis, mesmo que tenha outros (perfis personalizados continuam fora, como na decisão já tomada para o cadastro) | **Minha decisão** |

## 4. Modelo de dados

Tabela `atribuicoes_docentes` (módulo `Docente`), seguindo os padrões do repositório (`PertenceAoTenant`, FK composta `(tenant_id, id)`, `RegistaAutoria`, `SincronizaEstadoDescricao`, coluna `*_descricao` para cada enum inteiro, soft deletes).

| Coluna | Notas |
|---|---|
| `id`, `tenant_id` | padrão |
| `ano_lectivo_id` | **derivado da turma no servidor, nunca vindo do cliente**; guardado para consultas e índices |
| `turma_id` | FK |
| `disciplina_id` | FK para o catálogo `Disciplina` |
| `professor_id` | FK para `users` |
| `papel` + `papel_descricao` | enum: `TITULAR = 0`, `SUBSTITUTO = 1` |
| `data_inicio` | obrigatória, dentro do ano lectivo |
| `data_fim` | nula = até ao fim do ano lectivo. Pode ser futura (professor que sai no fim do mês) |
| `motivo_encerramento` | texto; obrigatório quando se define `data_fim` por encerramento ou substituição |
| `criado_por`, `editado_por`, timestamps, `deleted_at` | padrão |

**Estado derivado, não guardado:** *agendada* (início no futuro), *em curso* (hoje dentro de `[data_inicio, data_fim]`), *terminada* (`data_fim` já passou). Evita um campo que teria de ser sincronizado com as datas.

**Integridade**
- Sem sobreposição de vigências entre `TITULAR` para a mesma `(turma_id, disciplina_id)`, e sem sobreposição do mesmo professor na mesma `(turma, disciplina)`. Verificada na Action, dentro de transacção com bloqueio da linha da turma, para funcionar igual em PostgreSQL e SQLite (um índice parcial não consegue exprimir sobreposição de intervalos).
- Em PostgreSQL, a mesma regra é garantida pela base de dados com constraints de exclusão (`btree_gist`, intervalo fechado, só linhas não eliminadas): um titular por dia por (turma, disciplina) e mesmo professor sem sobreposição. A Action traduz a violação em erro de validação, o que cobre operações concorrentes além da validação transaccional.
- Referências a turma, disciplina, ano lectivo e professor por FK composta `(tenant_id, id)`.
- Vigência efectiva = `[data_inicio, min(data_fim, fim do ano lectivo)]`, extremos inclusivos, fuso do tenant.
- Índices por `(professor_id, data_fim)` e `(ano_lectivo_id, turma_id)`.

## 5. Regras de negócio

Todas aplicadas na Action (não só no frontend), com mensagens em português de Portugal.

1. O `professor_id` tem de ser um utilizador do tenant com o perfil `PROFESSOR` e estado activo.
2. A turma tem de estar activa e o seu ano lectivo não pode estar `ENCERRADO` (`EstadoAnoLectivo`; `PLANEADO` e `ATIVO` aceitam atribuições).
3. A disciplina tem de pertencer ao conjunto atribuível da turma (D6). Disciplinas opcionais (`obrigatoria = false`) são atribuíveis, mas só contam como "sem titular" se houver pelo menos uma inscrição activa nelas naquela turma.
4. `data_inicio` e `data_fim` ficam dentro das datas do próprio ano lectivo (`data_inicio` e `data_fim` do `AnoLectivo`, não dos períodos); `data_fim >= data_inicio`.
5. Dois titulares não podem ter vigências sobrepostas na mesma (turma, disciplina). Atribuir um titular quando já há um em vigor é rejeitado com indicação de usar "Substituir titular".
6. O mesmo professor não pode ter vigências sobrepostas na mesma (turma, disciplina), seja qual for o papel.
7. **Substituir titular**: define `data_fim` no titular actual (motivo obrigatório) e cria o novo titular com início no dia seguinte, numa só transacção. A data pode ser futura.
8. **Encerrar**: define `data_fim` (hoje ou futura) e `motivo_encerramento`. Uma atribuição terminada não se reabre; cria-se uma nova.
9. **Editar**: só papel e vigência de atribuições agendadas ou em curso; professor, turma e disciplina não mudam (para isso encerra-se e cria-se).
10. **Eliminar**: só atribuições ainda **agendadas** (nunca iniciadas). Atribuições em curso ou terminadas preservam-se sempre como histórico; corrigir uma já iniciada faz-se encerrando.
11. Registar todas as operações com `LogService::comLog`, como as restantes Actions.

## 6. Arquitectura

- **Módulo** `Modules/Docente` (activar em `modules_statuses.json`, senão falha em silêncio).
- **Camadas:** Controllers finos; leitura em `AtribuicaoDocenteConsultaService`; escrita em Actions (`AtribuirDocenteAction`, `SubstituirTitularAction`, `EncerrarAtribuicaoAction`, `AtualizarAtribuicaoAction`, `EliminarAtribuicaoAction`); DTOs; Form Requests; imports com `use`, nunca FQN inline.
- **Dependências (todas a montante):** `Turma`, `AnoLectivo`, `Disciplina`, `PlanoCurricular`, `Usuario`, `Permissao`, `Core`. Nenhum destes importa `Docente`.
- **Contrato novo no Core** `VerificaAtribuicaoDocente`, implementado e registado por `Docente`:
  - `podeGerir(int $professorId, int $turmaId, int $disciplinaId, ?CarbonInterface $data = null): bool` — há atribuição em vigor, titular ou substituto, naquela data (por omissão hoje);
  - `atribuicoesEmVigorDoProfessor(int $professorId, ?int $anoLectivoId = null): list<array{atribuicao_id, turma_id, turma_nome, disciplina_id, disciplina_nome, papel, data_inicio, data_fim}>`.
  - Consumidores futuros (`Avaliacao`, Área do Professor) usam só este contrato.
- **Ano lectivo:** `Docente` implementa `DependenciasDoAnoLectivo` e regista-se com a etiqueta existente. `bloqueiaEliminacao` devolve motivo se o ano tiver atribuições; `bloqueiaAlteracaoDeInicio` devolve motivo se alguma atribuição começar antes do novo início.
- **Tenancy:** sempre sob `PertenceAoTenant`; testes de isolamento entre escolas.

## 7. Permissões

- Novo caso no enum `Modulo`: `ATRIBUICAO_DOCENTE`, slug `atribuicao-docente`, separado de `Modulo::PROFESSOR` (que governa a lista de utilizadores professores). Acções `ver, listar, criar, editar, encerrar, eliminar, exportar`: `exportar` já existe no `AcaoSeeder`; `encerrar` é nova e declara-se em `acoesAplicaveis()` com seed correspondente.
- Seed em `RolePermissaoSeeder`: `admin_escola` com todas; `funcionario` com `ver, listar, criar, editar, encerrar`; `professor`, `aluno`, `encarregado` sem acesso a este módulo.
- O professor verá as **suas** atribuições na próxima fatia, por regra "é o próprio professor", sem precisar de permissão de listagem.
- Aplicar o mecanismo central de autorização, sem criar verificações paralelas.

## 8. Experiência (secretaria)

Uma página "Atribuições docentes" no menu, com o mesmo padrão visual das listas do projecto (Metronic, mesmos componentes e ícones, tema claro e escuro).

- **Selector de ano lectivo**, por omissão o activo.
- **Vista "Por turma" (principal):** escolhe-se uma turma e vê-se a lista das disciplinas atribuíveis com o titular actual, os substitutos e o estado. As disciplinas **sem titular** destacam-se, e um contador no topo diz quantas faltam em toda a escola. É o que responde à pergunta real da secretaria: "o que falta cobrir?".
- **Vista "Lista":** tabela com filtros por turma, professor, disciplina, papel e estado, pesquisa e exportação, no padrão das outras listas.
- **Acções por linha:** Atribuir, Substituir titular, Editar vigência, Encerrar. Cada uma num modal com validação do servidor.
- **Estados vazios claros:** ano sem turmas, turma sem plano curricular para o ano ("Esta turma ainda não tem disciplinas definidas"), professor inexistente.
- **Menu:** descomentar o placeholder existente em `SidebarMenuWrapper.vue` e no `useXxxMenu.js` correspondente, se existir; só criar se faltar.

## 9. Testes

Pest/PHPUnit com SQLite em memória, no estilo dos módulos existentes:

- cada regra da secção 5, caso feliz e rejeições;
- unicidade do titular, incluindo concorrência dentro da transacção;
- substituição atómica (falha a meio não deixa estado parcial);
- isolamento entre tenants;
- permissões por perfil (funcionário vs professor vs aluno);
- contrato `VerificaAtribuicaoDocente` com atribuição activa, encerrada, agendada e de outro professor.

## 10. Entregas desta fatia

1. Módulo `Docente` com migração, modelo, enums, Actions, Service, DTOs, Requests, Controller e rotas.
2. Contrato no Core e a sua implementação.
3. Permissões (enum, seed).
4. Página da secretaria (duas vistas e modais) e item de menu.
5. Testes.

## 11. Questões abertas

Nenhuma bloqueia a construção. Duas são decisões de política que o dono pode inverter:

- **O1:** turmas sem plano curricular activo para o ano não têm disciplinas atribuíveis; o ecrã explica porquê. Decidido assim.
- **O2:** o funcionário (secretaria) pode atribuir, como na matrícula. Decidido assim; se o dono preferir só o admin da escola, é uma linha no seed.

## 12. Deixado ao construtor

Nomes de rotas e componentes; forma exacta do índice parcial por driver; estrutura das pastas Vue (`Components/<Entidade>/`, `Pages/<EntidadePlural>/`); formato das mensagens de erro; paginação e ordenação por omissão.
