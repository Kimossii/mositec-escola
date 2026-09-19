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
| S1 | Alta | P | Dependências vulneráveis: `guzzlehttp/guzzle`, `laravel/framework`, `league/commonmark`, `guzzlehttp/psr7`, `symfony/yaml` (composer); 6 avisos no npm (5 altos, incl. `vite`) | `composer update` e `npm audit fix`, correr a suíte de testes, adicionar `composer audit` e `npm audit` à CI | Por decidir | [ ] |
| S2 | Alta | P | Rate limit de login só por IP (`login-attempts:{ip}`): atacante distribuído força uma conta sem bloqueio; NAT da escola bloqueia todos os utilizadores | Chave `identificador\|ip` mais limite global por conta, com `RateLimiter` nomeado | Por decidir | [ ] |
| S3 | Alta | M | Race condition em matrículas: `validarMatriculaNaoDuplicada` só valida em aplicação, sem índice único na BD | Índice único parcial em Postgres (`aluno_id`, `curso_id`, `nivel_academico_id` onde estado é Pendente/Activa), ou `lockForUpdate` no aluno | Por decidir | [ ] |
| S4 | Média | M | Documentos pessoais sem âmbito: `documento-pessoa.ver` dá acesso aos documentos de qualquer pessoa; sem registo de acessos | Regra de âmbito na Policy (ex.: professor só vê os seus alunos), log de downloads, limite de taxa | Por decidir | [ ] |
| S5 | Média | P | Rotas de escrita sem `can:` em Usuario e Permissão (intencional, dependem de FormRequest/Policy); risco de regressão silenciosa. Não verificadas uma a uma | Ampliar `RotasPermissaoReconhecidaTest` para falhar se uma rota `auth` de escrita não tiver `can:` nem `authorize` verificável | Por decidir | [ ] |
| S6 | Baixa | P | Configuração de produção: `.env.example` com `APP_ENV=local`, `SESSION_SECURE_COOKIE=false`, `SESSION_ENCRYPT=false`, `LOG_LEVEL=debug`, `MAIL_MAILER=log`; logs de login guardam identificador e IP | Checklist de deploy; cookies seguros; `APP_DEBUG=false`; Redis para cache e sessão; mascarar o identificador nos logs | Por decidir | [ ] |
| S7 | Baixa | P | `auth.user` vai completo para o Inertia em cada página (só `password` e `remember_token` ocultos) | Partilhar apenas `id`, `nome`, `email` e perfil | Por decidir | [ ] |
| S8 | Baixa | M | Tenancy incompleta: `AnoLectivoTenancyTest` marcado como incompleto; alguns Services filtram por `Estabelecimento::current()` e outros não (ex.: `CriarMatriculaAction` usa `Turma::findOrFail` sem âmbito). Não verificado em todos | Decidir mono-escola vs multi-escola. Mono: documentar e remover o teste. Multi: global scope por estabelecimento | Por decidir | [ ] |

## 2. Performance

| ID | Sev. | Esf. | Item | Proposta | Decisão | Estado |
|---|---|---|---|---|---|---|
| P1 | Média | P | Pesquisa com `LIKE` em Postgres (Aluno, Matrícula, Curso, Disciplina, Turma): sensível a maiúsculas e sem uso de índice; `%` e `_` não escapados | `ilike` mais escape dos curingas; para escala, `pg_trgm` com índice GIN e `unaccent` | Por decidir | [ ] |
| P2 | Média | M | Poucos índices compostos para os filtros reais (154 usos de FK/índice/único, 16 `->index()` explícitos) | Rever com `EXPLAIN`; criar `matriculas(aluno_id, ano_lectivo_id, estado)`, `turmas(ano_lectivo_id, curso_id)` e afins | Por decidir | [ ] |
| P3 | Média | M | Listagens sem paginação: `UsuarioConsultaService::listarTodos/listarPorPerfil`, `AlunoConsultaService::turmasDisponiveis` (só 8 `paginate()` no projecto) | Paginação no servidor com pesquisa | Por decidir | [ ] |
| P4 | Média | M | Renovação em massa: `find()` por id em ciclo, sem limite de tamanho (`matricula_ids` sem `max`), síncrona | `max:200` no request, `whereIn` com eager loading, Job em fila para volumes grandes (fila `database` já configurada) | Por decidir | [ ] |

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
