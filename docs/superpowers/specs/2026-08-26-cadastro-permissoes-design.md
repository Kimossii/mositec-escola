# Cadastro de Perfis e Permissões — Design

## Contexto

O MosiTec precisa de um sistema de controlo de acessos baseado em Perfil ×
Módulo × Ação, com override individual por utilizador. As tabelas e models
já existem (`roles`, `modulos`, `acoes`, `role_permissoes`,
`user_permissoes`, `Role`/`Modulo`/`Acao`/`RolePermissao`/`UserPermissao`),
tal como os seeders de `Modulo` (10 módulos) e `Acao` (6 ações). Os 5
perfis de sistema (Admin escola, Secretário, Professor, Aluno, Encarregado)
já são semeados por `RoleSeeder`, usando o enum `Perfil`.

Este documento cobre apenas o **cadastro** (ecrãs de gestão): criar/editar
perfis, configurar as permissões padrão de cada perfil, e configurar
overrides individuais por utilizador. A **verificação em runtime**
(`$user->hasPermission(...)` a bloquear ações de facto) fica
explicitamente fora do âmbito — combinado com o utilizador para tratar
depois.

**Fora do âmbito:** CRUD de Módulos/Ações pela UI (ficam fixos por
seeder/código); mecanismo de autorização em runtime; integração com o
Assistente de IA.

## Ajuste ao schema

`roles.nome` (hoje `integer` não-nulo, espelha `Perfil::value`, valores
0-4) precisa de distinguir os 5 perfis de sistema dos criados pelo admin.
Tornar a coluna `nullable()` exigiria o pacote `doctrine/dbal` (não está
instalado) para o `->change()` da migration, e um `ALTER COLUMN` não é
suportado da mesma forma em SQLite (usado pelos testes) — mudar o schema
traria mais complexidade do que vale a pena aqui.

Em vez disso: perfis criados pelo admin recebem explicitamente
`nome = -1` (fora do intervalo 0-4 do enum `Perfil`, nunca colide com os
de sistema). "É perfil de sistema?" passa a ser `$role->nome !== -1` (em
vez de `!== null`). A sua identidade real continua a ser `id` + `descricao`
— `nome` só serve para o código saber "isto é um dos 5 perfis
automáticos" ou não. Sem migration nova, sem dependências novas.

`UserPermissao::$fillable` está a faltar `permitido` (bug pré-existente,
encontrado ao ler o código) — corrigido como parte deste trabalho.

## Ecrãs

1. **Lista de Perfis** (`/permissoes/perfis`) — tabela com nome/descrição,
   estado, e um botão "Configurar permissões" por linha. Modal de criação
   (descrição + estado). Perfis de sistema (`nome !== -1`) não podem
   ser eliminados; os restantes podem, desde que sem utilizadores
   atribuídos.
2. **Matriz de permissões do perfil** (`/permissoes/perfis/{role}/permissoes`)
   — grelha Módulos (linhas) × Ações (colunas), checkbox por célula.
   Checkbox marcado = existe uma linha em `role_permissoes` para esse
   `role_id`+`modulo_id`+`acao_id`; desmarcar apaga a linha, marcar cria.
3. **Permissões individuais do utilizador** (`/permissoes/utilizadores/{user}/permissoes`)
   — mostra os perfis atualmente atribuídos ao utilizador (com opção de
   atribuir/remover perfis via `user_roles` — necessário para perfis
   personalizados, que não passam pelos 5 Forms fixos de cadastro), as
   permissões herdadas (união das permissões de todos os perfis do
   utilizador — só para referência visual, não editável aqui) e uma
   segunda grelha, tri-estado, para os overrides: **Herda** (sem linha em
   `user_permissoes`), **Concede** (`permitido = true`), **Nega**
   (`permitido = false`).

## Backend

**Rotas** (`Modules/Permissao/routes/web.php`, substituindo o
`Route::resource('permissaos', ...)` atual):

```
GET    /permissoes/perfis
POST   /permissoes/perfis
PUT    /permissoes/perfis/{role}
DELETE /permissoes/perfis/{role}
GET    /permissoes/perfis/{role}/permissoes
PUT    /permissoes/perfis/{role}/permissoes
GET    /permissoes/utilizadores/{user}/permissoes
PUT    /permissoes/utilizadores/{user}/permissoes
POST   /permissoes/utilizadores/{user}/perfis      (atribuir perfil)
DELETE /permissoes/utilizadores/{user}/perfis/{role} (remover perfil)
```

**Actions** (segue o padrão já usado no módulo Usuario):

- `SincronizarPermissoesPerfilAction` — recebe `Role` + array de pares
  `[modulo_id, acao_id]` marcados; faz diff contra `role_permissoes`
  atuais (apaga o que saiu, insere o que entrou), tudo numa transação.
- `SincronizarPermissoesUtilizadorAction` — recebe `User` + array de
  `{modulo_id, acao_id, permitido}` (`permitido` pode ser `null` para
  remover o override); mesma lógica de diff sobre `user_permissoes`.

**Cálculo de permissões herdadas** (só para exibição, não enforcement):
`PermissaoConsultaService::permissoesHerdadas(User $user): Collection` —
união de `role_permissoes` para todos os `roles` do utilizador (via
`user_roles`).

## Testes a cobrir

- Criar perfil novo (`nome = -1`), não pode ser eliminado se tiver
  utilizadores atribuídos, pode ser eliminado se não tiver.
- Perfil de sistema (`nome !== -1`) nunca pode ser eliminado.
- Sincronizar matriz do perfil: marcar cria linhas, desmarcar remove,
  idempotente ao reenviar o mesmo estado.
- Sincronizar permissões do utilizador: os 3 estados (herda/concede/nega)
  persistem e são lidos corretamente; herda = sem linha.
- Atribuir/remover perfil a um utilizador via `user_roles`.
- Permissões herdadas = união correta quando o utilizador tem mais que um
  perfil.
