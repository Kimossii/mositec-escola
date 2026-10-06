# Roadmap MosiTec Escola

Origem: auditoria de 2026-09-19 (segurança, performance, falhas e novas funcionalidades).
Este ficheiro é o registo de decisão do que se implementa **agora** e do que fica para **depois**.

## Como usar

- **Decisão:** `Agora` · `Depois` · `Descartado` · `Por decidir` (valor inicial de todos os itens).
- Ao concluir um item, marcar `[x]`, preencher a data e o commit/PR.
- Sev. = severidade (Alta / Média / Baixa). Esf. = esforço estimado (P / M / G).

---

## 1. Segurança e falhas

| ID | Sev. | Esf. | Item | Proposta | Decisão | Estado |
|---|---|---|---|---|---|---|
| S1 | Alta | P | Dependências vulneráveis: `guzzlehttp/guzzle`, `laravel/framework`, `league/commonmark`, `guzzlehttp/psr7`, `symfony/yaml` (composer); 6 avisos no npm (5 altos, incl. `vite`) | `composer update` e `npm audit fix`, correr a suíte de testes, adicionar `composer audit` e `npm audit` à CI | Agora | [x] 2026-09-30 (composer/npm audit em 0 avisos, sem bump maior — laravel/framework ficou em ^12; `.github/workflows/ci.yml` novo corre os dois audits e a suíte em cada PR/push, que antes não tinha CI nenhum) |
| S2 | Alta | P | Rate limit de login só por IP (`login-attempts:{ip}`): atacante distribuído força uma conta sem bloqueio; NAT da escola bloqueia todos os utilizadores | Chave `identificador\|ip` mais limite global por conta, com `RateLimiter` nomeado | Agora | [x] 2026-09-19 |
| S3 | Alta | M | Race condition em matrículas: `validarMatriculaNaoDuplicada` só valida em aplicação, sem índice único na BD | Índice único parcial em Postgres (`aluno_id`, `curso_id`, `nivel_academico_id` onde estado é Pendente/Activa), ou `lockForUpdate` no aluno | Agora | [x] 2026-09-19 (bloqueio de linha por aluno; índice único descartado: a regra depende de `turmas`) |
| S4 | Média | M | Documentos pessoais sem âmbito: `documento-pessoa.ver` dá acesso aos documentos de qualquer pessoa; sem registo de acessos | Regra de âmbito na Policy (ex.: professor só vê os seus alunos), log de downloads, limite de taxa | Por decidir | [ ] |
| S5 | Média | P | Rotas de escrita sem `can:` em Usuario e Permissão (intencional, dependem de FormRequest/Policy); risco de regressão silenciosa. Não verificadas uma a uma | Ampliar `RotasPermissaoReconhecidaTest` para falhar se uma rota `auth` de escrita não tiver `can:` nem `authorize` verificável | Agora | [x] 2026-09-30 (novo teste `RotasEscritaAutorizadasTest`, cobre todas as rotas de escrita autenticadas do projecto, não só Usuario/Permissão; verificado que apanha um `authorize()` trivial) |
| S6 | Baixa | P | Configuração de produção: `.env.example` com `APP_ENV=local`, `SESSION_SECURE_COOKIE=false`, `SESSION_ENCRYPT=false`, `LOG_LEVEL=debug`, `MAIL_MAILER=log`; logs de login guardam identificador e IP | Checklist de deploy; cookies seguros; `APP_DEBUG=false`; Redis para cache e sessão; mascarar o identificador nos logs | Por decidir | [ ] |
| S7 | Baixa | P | `auth.user` vai completo para o Inertia em cada página (só `password` e `remember_token` ocultos) | Partilhar apenas `id`, `nome`, `email` e perfil | Agora | [x] 2026-09-30 (`id`, `name`, `email` — único consumidor no frontend, UserMenu.vue, só lia esses dois; "perfil" não é usado em lado nenhum, controlo de acesso já passa pela prop `permissoes`) |
| S8 | Baixa | M | Tenancy incompleta: `AnoLectivoTenancyTest` marcado como incompleto; alguns Services filtram por `Estabelecimento::current()` e outros não (ex.: `CriarMatriculaAction` usa `Turma::findOrFail` sem âmbito). Não verificado em todos | Decidir mono-escola vs multi-escola. Mono: documentar e remover o teste. Multi: global scope por estabelecimento | Por decidir | [ ] |

## 2. Performance

| ID | Sev. | Esf. | Item | Proposta | Decisão | Estado |
|---|---|---|---|---|---|---|
| P1 | Média | P | Pesquisa com `LIKE` em Postgres (Aluno, Matrícula, Curso, Disciplina, Turma): sensível a maiúsculas e sem uso de índice; `%` e `_` não escapados | `ilike` mais escape dos curingas; para escala, `pg_trgm` com índice GIN e `unaccent` | Agora | [x] 2026-09-19 (macro `whereContem`/`orWhereContem` no Core, `ilike` em Postgres com escape de `%`, `_`, `\`; `pg_trgm`/`unaccent` ficam por decidir) |
| P2 | Média | M | Poucos índices compostos para os filtros reais (154 usos de FK/índice/único, 16 `->index()` explícitos) | Rever com `EXPLAIN`; criar `matriculas(aluno_id, ano_lectivo_id, estado)`, `turmas(ano_lectivo_id, curso_id)` e afins | Agora | [x] 2026-09-30 (2 índices, verificados com EXPLAIN ANALYZE numa base de 17k matrículas: `alunos.estabelecimento_id`, sem nenhum índice apesar de ser o filtro mais repetido do sistema; `matriculas(ano_lectivo_id, estado)`, a listagem principal do módulo. `turmas`/`cursos`/`disciplinas` ficam pequenas mesmo a longo prazo — não justificam índice novo) |
| P3 | Média | M | Listagens sem paginação: `UsuarioConsultaService::listarTodos/listarPorPerfil`, `AlunoConsultaService::turmasDisponiveis` (só 8 `paginate()` no projecto) | Paginação no servidor com pesquisa | Agora | [x] 2026-09-19 (utilizadores: paginação, pesquisa e estado no servidor; `turmasDisponiveis` de Alunos: restrita ao ano lectivo do filtro) |
| P4 | Média | M | Renovação em massa: `find()` por id em ciclo, sem limite de tamanho (`matricula_ids` sem `max`), síncrona | `max:200` no request, `whereIn` com eager loading, Job em fila para volumes grandes (fila `database` já configurada) | Agora | [x] 2026-09-30 (`max:200` no request; `find()` por id trocado por 1 `whereIn` com eager loading, verificado por teste de contagem de queries — N+1 confirmado antes, 1 query depois; fila para volumes grandes fica por decidir, muda a UX síncrona actual de sucesso/falha por matrícula) |

## 3. Novas funcionalidades

| ID | Valor | Esf. | Funcionalidade | Notas | Decisão | Estado |
|---|---|---|---|---|---|---|
| F1 | Alto | M | Auditoria transversal (quem alterou o quê e quando) | Trait no Core, no estilo de `RegistaAutoria`; aplicar a matrículas, notas, permissões e documentos | Por decidir | [ ] |
| F2 | Alto | G | Avaliações e notas | `InscricaoDisciplina` já existe; pautas, médias, estado de aprovação | Por decidir | [ ] |
| F3 | Alto | G | Assiduidade | Ligada a Horário e Turmas | Por decidir | [ ] |
| F4 | Alto | G | Propinas e pagamentos | Por matrícula, com estado da inscrição | Por decidir | [ ] |
| F5 | Médio | M | Encerramento e transição de ano lectivo | Renovação em massa agendada, arquivo | Por decidir | [ ] |
| F6 | Médio | P | Alertas de validade de documentos | `data_validade` já existe; tarefa agendada mais notificação | Por decidir | [ ] |
| F7 | Médio | M | Exportações e importação | PDF da ficha do aluno, comprovativo de matrícula, importação de alunos por CSV | Por decidir | [ ] |
| F8 | Médio | M | Painel com indicadores | Contagens por ano, curso e estado | Por decidir | [ ] |
| F9 | Médio | P | Autenticação de dois factores para administradores | Fortify já está instalado | Por decidir | [ ] |
| F10 | Alto | G | Portal do encarregado e do aluno | Aproveita `EncarregadoAluno` | Por decidir | [ ] |

---

## Ordem sugerida

1. **Segurança:** S1, S2, S3, S5.
2. **Performance:** P1, P2, P3, P4.
3. **Restante:** S4, S6, S7, S8; depois F1, F2, F3.

## Registo de decisões

| Data | Decisão | Itens | Notas |
|---|---|---|---|
| | | | |
