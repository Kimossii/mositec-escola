# Horários e Frequência — desenho

**Data:** 2026-11-22
**Estado:** proposta para aprovação (nada implementado)
**Contexto:** roadmap pós-Docente (Calendário/Horários → Avaliações → Frequência). Avaliações está feito. Este desenho cobre as duas fatias finais em conjunto porque a presença é marcada por aula e a aula nasce da grelha de horários.

## 1. Intenção

O professor marca presença todos os dias, mas o sistema não sabe **quando** cada turma tem aula, nem tem onde guardar presenças. Esta entrega cria:

1. a **grelha de horários** (quem tem aula de quê, quando, onde);
2. o **registo de frequência** (presente / falta / atraso), com justificação auditável;
3. a ligação ao **boletim** (faltas por disciplina e período) e ao **dashboard** (aluno, professor, direcção).

## 2. Objectivos, não-objectivos, anti-objectivos

**Objectivos**
- Grelha semanal por turma, com vigência por datas, e três vistas: por turma, por professor, por sala.
- Chamada feita pelo professor da disciplina; só o professor com atribuição em vigor pode marcar.
- Etapas Pré-Escolar e Primário: **uma chamada por dia**, não por tempo.
- Justificação de faltas por secretaria, director de turma ou o professor da disciplina, com histórico.
- Faltas no boletim por período; "chamadas por fazer" no dashboard do professor e da direcção.

**Não-objectivos**
- Reprovação ou alerta automático por excesso de faltas.
- Notificações a encarregados.
- Sumários, aulas de reposição, QR/biometria.
- Atribuição de docentes em lote.
- Creche (a escola não a usa).

**Anti-objectivos**
- Apagar ou sobrescrever um registo de presença: tudo o que muda fica em histórico.
- Alterar a grelha e com isso mudar aulas já registadas.
- Regras só no frontend.
- Dependências circulares entre módulos.

## 3. Abordagens consideradas (aula pré-gerada / sob demanda / híbrida)

| | Prós | Contras |
|---|---|---|
| A. Pré-gerada | Sabe sempre o que devia ter acontecido | Regenerar e reconciliar quando a grelha ou o calendário mudam |
| B. Sob demanda | Simples | Não detecta chamadas em falta |
| **C. Híbrida (escolhida)** | Detecta chamadas em falta sem regenerar nada; mudar a grelha não estraga o histórico | Duas fontes (previsto calculado, real guardado) |

O dono escolheu a **C** em 2026-11-22.

## 4. Decisões e as suas origens

| # | Decisão | Origem |
|---|---|---|
| D1 | Dois módulos novos: `Horario` (fatia 1) e `Frequencia` (fatia 2). Dependem só de módulos a montante; nada os importa. Boletim/dashboard consomem-nos por contrato no Core | Minha decisão, no padrão do módulo `Docente` |
| D2 | Aulas previstas **calculadas na hora** (grelha vigente × calendário); só se guarda a aula quando a chamada é aberta | Acordada (opção C) |
| D3 | A aula guardada é uma fotografia: data, tempo, turma, disciplina, docente e sala próprios | Acordada |
| D4 | Etapas Pré-Escolar e Primário: uma chamada por **dia**. O professor com atribuição em vigor na turma (na prática, o único) marca. Etapa vem de `NivelAcademico.etapa_ensino`; sem coluna nova | Acordada |
| D5 | Sem mexer em `AtribuicaoDocente`: no primário continua uma atribuição por disciplina | Acordada |
| D6 | Estados da chamada: presente, falta, atraso. Aula pode ficar "não leccionada" com motivo | Acordada |
| D7 | Justificação: secretaria, director de turma ou o professor da disciplina; permissão própria; guarda quem, quando, motivo e documento opcional | Acordada |
| D8 | A grelha **não guarda o docente**: resolve-se pela atribuição em vigor. Substituir o titular não obriga a refazer horários | **Minha decisão** |
| D9 | Professor corrige livremente os últimos 7 dias (configurável); depois só a secretaria, com motivo | **Minha decisão** |
| D10 | No regime diário, o dia é "previsto" se a grelha da turma tiver pelo menos uma entrada nesse dia da semana | **Minha decisão** |
| D11 | No regime diário, a lista são todos os matriculados activos na turma; no regime por tempo, os inscritos na disciplina. Trancados ficam de fora | **Minha decisão** |

| D12 | Se uma entrada da grelha ficar sem docente em vigor (atribuição encerrada), a aula continua prevista mas aparece como "sem docente" nas vistas e no dashboard da direcção, e ninguém a pode marcar até haver nova atribuição | **Minha decisão** |
| D13 | A janela de correção de 7 dias fica nas definições da escola (tenant), com valor por omissão 7 | **Minha decisão** |

## 5. Fatia 1 — Horários

**Grelha:** uma linha por (turma, dia da semana, tempo, disciplina, sala opcional), com `vigencia_inicio` e `vigencia_fim`. Alterar fecha a linha antiga e cria outra.

**Validações (servidor):**
- o tempo pertence ao turno da turma (`TurnoHorario`);
- a disciplina é do plano da turma (mesma resolução usada na atribuição docente);
- existe docente com atribuição em vigor nessa turma e disciplina;
- sem conflito de turma, sala ou docente no mesmo dia e tempo com vigências sobrepostas. O conflito de docente usa o titular em vigor.

**Vistas:** por turma (editável, secretaria), por professor ("O meu horário", Área do Professor), por sala.

**Permissões:** `Modulo` no enum + seed em `RolePermissaoSeeder`; registar em `modules_statuses.json`.

## 6. Fatia 2 — Frequência

**Aula prevista:** calculada a partir da grelha vigente e do calendário. Dias de `EventoCalendario` de feriado/pausa não têm aula. Só se pode abrir chamada numa aula prevista.

**Aula guardada:** tipo `tempo` ou `dia`. Única por (turma, data, tempo); no tipo `dia`, única por (turma, data) com tempo vazio.

**Registo:** um por aluno e aula, com estado e observação. Alterações vão para o histórico (padrão das notas).

**Justificação:** liga ao registo; o estado "justificada" deriva da sua existência. Remover justificação também fica no histórico.

**Quem marca:** titular ou substituto em vigor na data (regime por tempo) ou qualquer docente com atribuição em vigor na turma (regime diário).

**Boletim e pautas:** total, justificadas e injustificadas por disciplina e período, pelas datas do `Periodo`. Turmas em regime diário contam dias; as outras contam aulas.

**Dashboard:** aluno vê as suas faltas; professor vê chamadas por fazer; direcção vê aulas previstas sem chamada.

## 7. Tabelas e relações

- `horario_grelha_entradas` → `turmas`, `horarios` (tempo), `disciplinas`, `salas`, com vigência.
- `frequencia_aulas` → `turmas`, `disciplinas` (vazio no regime diário), `docentes`, `salas`, `horarios`; guarda tipo, data e estado (realizada / não leccionada).
- `frequencia_registos` → `frequencia_aulas`, `matriculas`.
- `frequencia_justificacoes` → `frequencia_registos`.
- `frequencia_registos_historico` → `frequencia_registos`.
- Lê de `atribuicoes_docentes`, `turno_horarios`, `eventos_calendario`, `periodos`, `inscricoes_disciplina`, `niveis_academicos`.

Uma tabela por migração. Colunas-enum com irmã `*_descricao`. Tudo com tenancy (`PertenceAoTenant`) e autoria (`RegistaAutoria`).

## 8. Deixado ao construtor

Nomes de classes, formato exacto das consultas de "previsto menos registado", desenho dos ecrãs (seguindo padrões já existentes), ordem interna das tarefas de cada plano.
