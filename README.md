# Sistema de Gestão Acadêmica - MosiTec 
## - Instalar basa de dados PSQL(PostgreSQL)
### sudo apt install php-pgsql(linux) - para instalar as extensão o mesmo no windows-mac

#### Pacotes a serem instalados
- composer require nwidart/laravel-modules - pacote para orquestrar os módulos.
- publicar as configuções: php artisan vendor:publish --provider="Nwidart\Modules\LaravelModulesServiceProvider"
-- Comando para criar um novo módulo: php artisan module:make nome-novo-módulo
- Autenticação: composer require laravel/fortify e composer require laravel/sanctum 
-- php artisan vendor:publish --provider="Laravel\Fortify\FortifyServiceProvider"
-- php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
--- php artisan migrate
-- php artisan db:seed
---

# Módulos do projeto MosiTec Acadêmico

## Primordiais

| Módulo       | Função                                           | Depende de               | Observações                                              | Prioridade | Estado   |
| ------------ | ------------------------------------------------ | ------------------------ | -------------------------------------------------------- | ---------- | -------- |
| Usuário      | Cadastro e gerenciamento de usuários             | --------                 | Base para autenticação e permissões                      | 1          | Em Execução |
| Autenticação | Login, logout, reset de senha, segurança         | Usuário                  | Núcleo do sistema                                        | 1          | Em Execução |
| Permissões   | Controle de papéis e acessos                     | Usuário                  | Usado por todos os módulos                               | 1          | Em Execução |
| AnoLectivo   | Define períodos escolares                        | --------                 | Necessário para Matrícula, Notas, Frequência e Turmas    | 1          | Pendente |
| Licença      | Validação do uso do sistema e limite de usuários | Usuário                  | Discreto, backend-only                                   | 1          | Pendente |
| Aluno        | Cadastro de estudantes                           | AnoLectivo               | Necessário para Matrícula, Notas, Frequência, Financeiro | 1          | Pendente |
| Professor    | Cadastro de professores                          | -----                    | Necessário para Turmas, Disciplinas e Horários           | 1          | Pendente |
| Turmas       | Agrupamento de alunos                            | Professor, AnoLectivo    | Usado por Matrícula e Horário                            | 1          | Pendente |
| Matricula    | Registrar alunos em turmas/disciplinas           | Aluno, Turma, AnoLectivo | --------                                                 | 1          | Pendente |

## Secundários

| Módulo              | Função                           | Depende de                               | Observações                       | Prioridade | Estado   |
| ------------------- | -------------------------------- | ---------------------------------------- | --------------------------------- | ---------- | -------- |
| Disciplina          | Cadastro de matérias             | Professor, Turmas                        | Usado por Notas e Horários        | 2          | Pendente |
| Nota                | Registro e cálculo de notas      | Aluno, Disciplina, AnoLectivo            | -----                             | 2          | Pendente |
| Frequência          | Controle de presença             | Aluno, Turma, AnoLectivo                 | -----                             | 2          | Pendente |
| Horário             | Planejamento de aulas            | Turma, Disciplinas, Professor            | ------                            | 2          | Pendente |
| MaterialDidatico    | Controle de livros e recursos    | Disciplina, Turma, Usuário               | Integrável com módulos acadêmicos | 2          | Pendente |
| Financeiro          | Gestão de pagamentos             | Aluno, AnoLectivo                        |                                   | 2          | Pendente |
| Relatório           | Extração de dados                | Financeiro, Notas, Frequência, Matricula | Pode gerar relatórios combinados  | 2          | Pendente |
| Documento           | Armazenamento de arquivos        | Relatório, Financeiro                    | Contratos e documentos de alunos  | 2          | Pendente |
| ContratosDocumentos | Contratos e documentos de alunos | -                                        | -                                 | 2          | Pendente |

## Suporte

| Módulo        | Função                          | Depende de                | Observações                           | Prioridade | Estado   |
| ------------- | ------------------------------- | ------------------------- | ------------------------------------- | ---------- | -------- |
| Sincronização | Sincronização local/cloud       | Todos os módulos          | Prioridade de backend                 | 3          | Pendente |
| Base de dados | Estrutura                       | --------                  | Suporte técnico para todos os módulos | 3          | Pendente |
| Configurações | Parâmetros do sistema           | --------                  | Usado por todos os módulos            | 3          | Pendente |
| Comunicação   | Chat, SMS, emails, notificações | Aluno, Professor, Usuário | Integrável com módulos acadêmicos     | 3          | Pendente |
| Auditoria     | Logs e monitoramento            | Todos os módulos          | Backend-only; segurança               | 3          | Pendente |
