# Wizard de Cadastro/Edição de Utilizador — Design

## Contexto

O cadastro de utilizador (5 formulários por tipo + o genérico) e o sistema
de permissões (perfis, matriz Módulo × Ação, overrides individuais) já
existem como peças separadas. Este documento une as duas: ao criar ou
editar um utilizador, o admin escolhe o perfil e já vê/ajusta as
permissões desse utilizador específico, tudo no mesmo fluxo, num wizard de
2 passos.

**Fora do âmbito:** verificação em runtime (`hasPermission()`); CRUD de
Módulos/Ações; qualquer alteração ao ecrã dedicado de Permissões do
Utilizador (`UtilizadorPermissoes.vue`) — continua a existir para ajustes
posteriores e para atribuir *mais que um* perfil a um utilizador.

## Componentes

Um componente novo e partilhado, `UsuarioWizardForm.vue`, substitui a
lógica hoje duplicada em `Forms/Alunos/AlunoForm.vue`,
`Forms/Professores/ProfessorForm.vue`,
`Forms/Funcionarios/FuncionarioForm.vue`,
`Forms/Administradores/AdministradorForm.vue` e
`Forms/Encarregados/EncarregadoForm.vue`. Cada um destes ficheiros passa a
ser uma casca fina que só define o `perfilFixo` e é montado pela mesma
`UsuarioListLayout` de sempre. O form genérico (`Forms/UsuarioForm.vue`,
usado por "Todos os Utilizadores") usa o mesmo `UsuarioWizardForm` sem
`perfilFixo`, deixando escolher o perfil no Passo 1.

- **Passo 1** — nome; email ou matrícula (conforme o perfil escolhido,
  igual ao que `UsuarioFormFields` já faz); seletor de perfil (ou badge
  fixo, se `perfilFixo` vier definido); campo de ligar educandos por
  matrícula, visível só quando o perfil é Encarregado (mesma UI que já
  existe em `EncarregadoForm.vue`).
- **Passo 2** — grelha Módulo × Ação, tri-estado (herda/concede/nega,
  igual ao padrão já usado em `UtilizadorPermissoes.vue`), pré-preenchida
  com o que o perfil escolhido no Passo 1 já dá por padrão
  (`role_permissoes` desse perfil). Os módulos/ações e as permissões
  padrão de todos os perfis vêm como prop da página (`permissoesPorPerfil:
  { [role_id]: [{modulo_id, acao_id}] }`), para trocar de perfil no Passo 1
  não precisar de um pedido HTTP novo.
- Um único `POST` (criar) ou `PUT` (editar) no final do Passo 2, com tudo:
  dados básicos + perfil + overrides + educandos (se Encarregado).

`utilizador` como prop opcional do `UsuarioWizardForm` — presente = modo
edição (campos e grelha pré-preenchidos com os dados atuais); ausente =
modo criação. O wizard mantém os 2 passos em ambos os modos.

## Backend

- `Modules/Usuario/app/Http/Requests/CriarUsuarioRequest.php` ganha
  `celulas` (mesma forma e validação de
  `SincronizarPermissoesUtilizadorRequest`: `nullable|array`, cada item
  `{modulo_id, acao_id, permitido}`).
- `UsuarioDTO` ganha a propriedade `celulas` (array, default `[]`).
- `UsuarioAction::criar()` — depois de criar o `User` e atribuir o perfil,
  chama `SincronizarPermissoesUtilizadorAction::executar($user, $dto->celulas)`
  dentro da mesma transação. Reutiliza a Action já existente no módulo
  Permissao — sem duplicar lógica de sincronização.
- `AtualizarUsuarioAction` (novo, `Modules/Usuario/app/Actions/`) —
  atualiza `name`/`email` do `User`, garante que o perfil escolhido fica
  atribuído via `syncWithoutDetaching` (nunca remove outros perfis que o
  utilizador já tenha ganho pelo ecrã de Permissões), e chama
  `SincronizarPermissoesUtilizadorAction` com os overrides atuais.
- `UsuarioController::edit(User $user)` — devolve JSON (não Inertia) com
  nome, email, perfil atual (o primeiro/único perfil de sistema
  atribuído), `celulas` atuais (overrides), e `permissoesPorPerfil` para o
  Passo 2. Chamado via `fetch` quando o botão "Edit" é clicado (o modal
  não é uma visita de página Inertia).
- `UsuarioController::update(CriarUsuarioRequest, User $user)` — chama
  `AtualizarUsuarioAction`.
- Rotas novas em `Modules/Usuario/routes/web.php`:
  `GET /usuarios/{user}/editar` e `PUT /usuarios/{user}`.

## Testes a cobrir

- Criar utilizador com overrides: `UsuarioAction::criar()` grava o user, o
  perfil, e as células de override na mesma operação.
- Editar utilizador: nome/email atualizam; perfil novo fica atribuído sem
  remover um perfil que já lá estava (atribuído por outra via); overrides
  substituem os anteriores (mesma semântica de
  `SincronizarPermissoesUtilizadorAction` já testada).
- `GET /usuarios/{user}/editar` devolve a forma esperada (perfil atual +
  overrides atuais + `permissoesPorPerfil`).
- Fluxo end-to-end: criar um Professor com uma permissão extra concedida
  além do que o perfil dá por padrão, e confirmar que fica gravada.
