# Sistema de Gestão Académica - MosiTec

Plataforma de gestão escolar **multi-escola** (cada escola é um *tenant*), construída em **Laravel 12** com módulos
independentes (`nwidart/laravel-modules`) e interface **Vue 3 + Inertia** sobre o tema Metronic.

## Licença

Este projeto é **apenas para estudo e aprendizado**.
É proibido usar ou distribuir o código para fins comerciais sem minha autorização.

---

## Stack

| Camada        | Tecnologia                                                                                   |
| ------------- | -------------------------------------------------------------------------------------------- |
| Backend       | PHP 8.2+, Laravel 12, Fortify (autenticação), Sanctum, `nwidart/laravel-modules` 13         |
| Frontend      | Vue 3, Inertia 3, Vite 7, Tailwind 4 + Bootstrap/Metronic, `vue-sonner`, FullCalendar        |
| Base de dados | PostgreSQL (produção) e SQLite em memória (testes). Extensão `php-pgsql` necessária          |
| Testes        | PHPUnit 11 (grupo `pgsql` para os testes que exigem PostgreSQL real)                         |

## Instalar e arrancar

```bash
sudo apt install php-pgsql          # Linux; no Windows/Mac activar a extensão pdo_pgsql
composer install
npm install
cp .env.example .env && php artisan key:generate   # configurar a ligação à base de dados no .env

php artisan migrate                 # cria as tabelas
php artisan db:seed                 # módulos, acções, perfis de sistema, dados base
php artisan financeiro:sincronizar --todos   # cria regras de cobrança, moeda e permissões financeiras nas escolas existentes
npm run build                       # ou `npm run dev` durante o desenvolvimento
```

> Para recriar a base de dados do zero (apaga tudo): `php artisan migrate:fresh --seed`.
> Um módulo só carrega se estiver activo em `modules_statuses.json` (falha silenciosa: sem rotas e sem erro).
> Novo módulo: `php artisan module:make NomeDoModulo`.

## Arquitectura

- **Módulos** em `Modules/<Nome>`: cada um com as suas migrações, models, Actions, Services, rotas e páginas Vue.
- **Multi-tenant**: 1 tenant = 1 escola = 1 estabelecimento. Os models usam `PertenceAoTenant` (isolamento automático
  por `tenant_id`). Há uma área de **Plataforma** (super-administrador MosiTec) para criar e gerir escolas.
- **Camadas**: o Controller é fino. **Service** para leitura, **Action** para escrita (uma transacção); regras de
  negócio nunca ficam no controller.
- **Permissões**: módulo × acção (`aluno.ver`, `cobranca.criar`, `matricula.trancar`…), com perfis de sistema e
  perfis personalizados. Cada módulo declara as acções que lhe são aplicáveis.
- **Convenções**: colunas-enum inteiras têm irmã legível `*_descricao`; dinheiro em **unidades menores** da moeda da
  escola; datas de negócio pelo fuso da escola (`RelogioDoTenant`).
- **Fronteiras entre módulos** por contratos no `Core` (ex.: o Matricula pergunta ao Financeiro se pode trancar, sem o
  importar).
- Especificações e planos de implementação em `docs/superpowers/` (`specs/` e `plans/`).

---

# Módulos do projeto MosiTec Académico

Legenda: ✅ feito · 🔧 em execução (parcial) · ⏳ por fazer

## Fundação

| Módulo          | O que faz e como está feito                                                                                                             | Estado |
| --------------- | --------------------------------------------------------------------------------------------------------------------------------------- | ------ |
| Core            | Contratos entre módulos, traços (autoria, estado), tenancy base, auditoria da escola (`auditoria_tenant`, só acrescenta), horários     | ✅     |
| Tenant          | Escolas (tenants), domínios, provisionamento de uma escola nova                                                                         | ✅     |
| Plataforma      | Área do super-administrador MosiTec: login próprio, gestão de escolas                                                                   | ✅     |
| Estabelecimento | Dados da escola (NIF, morada, província/município, fuso horário), logótipo e aparência                                                  | ✅     |
| Autenticação    | Login, logout, recuperação de senha, redefinição de senha (Fortify)                                                                     | ✅     |
| Usuário         | Listas por tipo (todos, alunos, professores, funcionários, encarregados, administradores), registo por assistente, documentos pessoais | ✅     |
| Permissão       | Perfis, permissões por módulo × acção (grelha agrupada por menu), permissões por utilizador, perfis de sistema fixos                   | ✅     |
| Licença         | Validação do uso do sistema e limite de utilizadores                                                                                    | ⏳     |

## Académico

| Módulo          | O que faz e como está feito                                                                                                                  | Estado |
| --------------- | -------------------------------------------------------------------------------------------------------------------------------------------- | ------ |
| AnoLectivo      | Anos lectivos (activo, encerrado), períodos; bloqueios quando há planos de propina                                                           | ✅     |
| Curso           | Cursos e níveis                                                                                                                              | ✅     |
| Turma           | Turmas, turnos e níveis académicos                                                                                                           | ✅     |
| Disciplina      | Cadastro de disciplinas                                                                                                                      | ✅     |
| PlanoCurricular | Plano curricular por curso/nível, com confirmação                                                                                            | ✅     |
| Aluno           | Ficha do aluno (dados pessoais, matrículas, situação de propinas com pesquisa/filtros/paginação, produtos e serviços por pagar)              | ✅     |
| Matrícula       | Matrículas (pendente, activa, concluída, cancelada, transferida, **trancada**), renovação, inscrição em disciplinas, histórico, bloqueio por dívida de anos anteriores, trancamento com verificação financeira, fim de matrícula com previsão do que é cancelado | ✅     |
| Horário         | Horários de aulas (calendário)                                                                                                               | 🔧     |
| Professor       | Cadastro próprio de professores (hoje vive em Utilizadores)                                                                                  | ⏳     |
| Nota            | Registo e cálculo de notas                                                                                                                   | ⏳     |
| Frequência      | Controlo de presenças                                                                                                                        | ⏳     |

## Financeiro

| Área                         | O que faz e como está feito                                                                                                                                                                  | Estado |
| ---------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------ |
| Moeda e câmbio               | Moeda por escola (unidades menores, decimais próprios), câmbio da plataforma e por escola                                                                                                    | ✅     |
| Regras de cobrança           | Vencimento, tolerância, pagamento parcial/antecipado, geração automática, multas por escalões, exigências de regularização (trancamento, matrícula, serviços), geração dos obrigatórios      | ✅     |
| Planos de propina            | Planos por datas, alvos (nível/curso/turno/turma), precedência, alteração de preço com pré-visualização, cópia entre anos, pausas de cobrança                                                | ✅     |
| Propinas                     | Geração (por aluno, turma, nível, plano; retroactiva com motivo; "até à data"), detalhe, cancelamento, anulação, multas automáticas com regra aplicada, isenção/redução                      | ✅     |
| Pagamentos                   | Registo com distribuição automática (mês mais antigo primeiro), meses a pagar, crédito do aluno, comprovativo, anulação; recibo em duas vias                                                 | ✅     |
| Produtos e serviços          | Catálogo com obrigatoriedade (quando e a quem), stock e tamanhos opcionais                                                                                                                   | ✅     |
| Cobranças                    | Venda de produtos e serviços a uma matrícula (preço copiado na venda), pagar agora ou depois, cancelamento repõe stock, obrigatórios gerados ao matricular                                   | ✅     |
| Dívidas                      | Lista de matrículas com propinas, multas e cobranças por pagar, filtros, totais, exportação CSV                                                                                              | ✅     |
| Recibos                      | Lista de recibos (um por pagamento) para ver e imprimir                                                                                                                                       | ✅     |
| Relatórios financeiros       | Recebido por origem, método e mês; dívida por turma; exportação CSV de recebimentos                                                                                                          | ✅     |
| Descontos e bolsas           | Descontos por aluno, bolsas                                                                                                                                                                   | ⏳     |
| Negociação de dívidas        | Acordos de prazo, prestações                                                                                                                                                                  | ⏳     |
| Conversão entre moedas       | Conversão de valores (precisa de `bcmath`)                                                                                                                                                    | ⏳     |

## Infraestrutura e outros

| Módulo               | Função                                                                                  | Estado |
| -------------------- | --------------------------------------------------------------------------------------- | ------ |
| Infraestrutura       | Salas feitas; equipamentos, inventário e manutenção por fazer                           | 🔧     |
| Relatórios académicos| Desempenho, assiduidade, boletins, matrículas, turmas                                   | ⏳     |
| Documentos           | Declarações, certificados, modelos, arquivo                                             | ⏳     |
| Comunicação          | Avisos, mensagens internas, SMS/email, notificações                                     | ⏳     |
| Biblioteca           | Livros, empréstimos, devoluções                                                         | ⏳     |
| Auditoria            | Mecanismo de auditoria da escola criado (regras de cobrança); consulta e outros eventos | 🔧     |
| AppMode / Sincronização | Modos local, híbrido e online; sincronização local/cloud                             | ⏳     |

## 📂 SIDEBAR

```text
🏠  Início
│    └── Dashboard Principal
│
├── 👥  Académico
│    ├── ✅ Cursos
│    ├── ✅ Alunos  (inclui a ficha do aluno)
│    ├── ⏳ Encarregados de Educação
│    ├── ✅ Turmas
│    ├── ✅ Matrículas  (trancar, reactivar, renovar)
│    ├── ⏳ Transferências
│    └── ⏳ Histórico Escolar
│
├── 📚  Pedagógico
│    ├── ✅ Disciplinas
│    ├── 🔧 Horários
│    ├── ⏳ Planos de Aula
│    ├── ⏳ Avaliações / Notas
│    ├── ⏳ Pautas
│    ├── ⏳ Conselho de Turma
│    └── ⏳ Exames / Recuperações
│
├── 🕐  Frequência                    ⏳ (Presenças, Faltas, Justificação, Mapa de Frequência)
├── 👨‍🏫  Pessoal                       ⏳ (Docentes, Funcionários, Assiduidade, Horários do Pessoal)
│
├── 💰  Financeiro
│    ├── ✅ Propinas
│    ├── ✅ Pagamentos
│    ├── ✅ Cobranças  (produtos e serviços)
│    ├── ✅ Dívidas
│    ├── ⏳ Descontos / Bolsas
│    ├── ✅ Recibos
│    └── ✅ Relatórios Financeiros
│
├── 📣  Comunicação                   ⏳
├── 📄  Documentos                    ⏳ (Declarações, Certificados, Modelos, Arquivo)
├── 📖  Biblioteca                    ⏳
│
├── 🏢  Infraestrutura
│    ├── ✅ Salas
│    └── ⏳ Equipamentos, Inventário, Manutenção
│
├── 📊  Relatórios                    ⏳ (Desempenho, Assiduidade, Boletins, Matrículas, Turmas)
│
└── ⚙️  Configurações
     │
     ├── 👥  Utilizadores
     │    ├── ✅ Todos os Utilizadores
     │    ├── ✅ Alunos
     │    ├── ✅ Professores
     │    ├── ✅ Funcionários
     │    ├── ✅ Administradores
     │    └── ✅ Encarregados
     │
     ├── 🔐  Perfis & Permissões
     │    └── ✅ Perfis e Permissões  (grelha por menu, com recolher/expandir)
     │
     ├── 🏫  Estabelecimento
     │    ├── ✅ Dados da Escola
     │    └── ✅ Logótipo & Aparência
     │
     ├── 📅  Ano Lectivo
     │    └── ✅ Anos Lectivos
     │
     ├── 💰  Financeiro
     │    ├── ✅ Moeda e Câmbio
     │    ├── ✅ Planos de Propina
     │    ├── ✅ Pausas de Cobrança
     │    ├── ✅ Regras de Cobrança
     │    ├── ✅ Métodos de Pagamento
     │    └── ✅ Produtos / Serviços
     │
     └── 🔧  Sistema                  ⏳
          ├── Parâmetros Gerais
          ├── Sessões Activas
          ├── Histórico de Logins
          ├── Backups
          └── Registos de Actividade
```

> 🔐 **Acesso:** a área de **Configurações** está disponível apenas para utilizadores com permissões administrativas.
> Cada entrada só aparece a quem tem a permissão `<módulo>.ver` correspondente.

## Comandos úteis

```bash
# Financeiro
php artisan financeiro:sincronizar --todos      # regras de cobrança, moeda e permissões financeiras nas escolas existentes (ou --tenant=CODIGO)
php artisan financeiro:gerar-propinas --todos   # geração automática (só escolas com "gerar automaticamente" ligado)
php artisan financeiro:aplicar-multas --todos   # aplica as multas por atraso (corre também agendado)
php artisan financeiro:cambio-plataforma AOA 925,50   # câmbio padrão da plataforma

# Qualidade
php artisan test                                # suite completa
php artisan test --group=pgsql                  # testes que exigem PostgreSQL real (base de testes dedicada)
vendor/bin/pint                                 # formatação PHP
```

> A suite tem alguns testes conhecidos que falham por razões de ambiente (extensão GD em falta para as fotos de
> alunos) ou que apontam dívida técnica antiga dos testes de arquitectura; não são regressões do código novo.
