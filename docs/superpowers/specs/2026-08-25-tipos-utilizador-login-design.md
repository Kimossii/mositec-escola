# Tipos de Utilizador e Login — Design

## Contexto

O MosiTec é um sistema de gestão escolar multi-modelo (Cloud multitenant via
stancl/tenancy, Local com instalação dedicada por escola, Híbrido futuro).
Cada tipo de utilizador tem uma forma de acesso natural diferente:

| Perfil | Login com | Porquê |
|---|---|---|
| Admin SaaS | Email + senha | Ator central, fora de qualquer escola/tenant — **reservado, fora do âmbito** |
| Admin escola | Email + senha | Responsável técnico da escola |
| Secretário | Email + senha | Funcionário |
| Professor | Email + senha | Profissional |
| Aluno | Matrícula + senha | Não tem email necessariamente |
| Encarregado | Email + senha (conta própria) | Pode ter vários educandos na mesma escola — precisa de um login único, não das credenciais de cada filho |

Este documento cobre o subprojeto **"Tipos de utilizador e login"**, que
precede o Sistema de Permissões (perfil × módulo × ação) porque este último
depende do catálogo real de perfis definido aqui.

**Fora do âmbito deste documento** (tratados noutra altura, não bloqueiam
este trabalho):
- Integração real do stancl/tenancy (modo Cloud) e o ator Admin SaaS.
- Módulo Ano Letivo — a geração de matrícula usa o ano civil como
  substituto interino (ver secção Matrícula).
- Sistema de Permissões (perfil × módulo × ação × override individual) —
  subprojeto seguinte, consome o catálogo de perfis definido aqui.
- Seletor de "aluno em contexto" para um encarregado com vários filhos
  ligados (a UI/lógica de escolher a quem os módulos como Notas/Frequência/
  Financeiro se referem depois do login) — depende desses módulos
  existirem; fica para essa altura.
- Recuperação de senha para contas por matrícula (o mecanismo padrão do
  Laravel assume email) — sinalizado como questão em aberto, não resolvido
  aqui. (Contas de Encarregado, por terem email próprio, já usam o
  mecanismo padrão sem problema.)

## Catálogo de perfis

A tabela `roles` (por escola/tenant) passa a ter 5 perfis:

- **Admin escola**
- **Secretário**
- **Professor**
- **Aluno**
- **Encarregado**

`Encarregado` é um perfil técnico real, com conta própria (`users` +
`email` + `senha`), tal como Admin escola/Secretário/Professor. Não usa as
credenciais do(s) filho(s) — ver secção "Encarregado ↔ Aluno" para a forma
como se liga aos educandos.

`RoleSeeder` é atualizado para semear estes 5 perfis (substitui o atual
`Aluno/Administrador/Professor/Secretario`, que já não reflete os nomes
corretos nem inclui Admin SaaS como caso à parte).

## Mecânica de login

Um único campo de identificador no formulário (substituindo o campo `email`
fixo atual). A deteção do tipo de credencial é feita pelo formato do valor
introduzido:

- Contém `@` → tratado como **email**. Fluxo igual ao atual:
  `Auth::attempt(['email' => $identificador, 'password' => $password])`.
- Caso contrário → tratado como **matrícula**:
  `Auth::attempt(['numero_matricula' => $identificador, 'password' => $password])`.

Sem lógica de ambiguidade a resolver: Encarregado autentica-se pela via de
email como qualquer conta de staff (Admin escola/Secretário/Professor);
Aluno autentica-se sempre pela via de matrícula. Cada conta tem sempre
exatamente um destes dois caminhos, nunca os dois.

**Enum `TipoLogin`** (novo, segue o padrão já usado em `EstadoUsuario`):

```php
enum TipoLogin: int
{
    case EMAIL = 0;
    case MATRICULA = 1;
}
```

Valores alinhados com o comentário já existente na migration de `users`
(`0: email | 1: matricula`).

**Alterações de ficheiros existentes:**

- `Modules/Autenticacao/app/Http/Requests/LoginRequest.php` — campo
  `email` (obrigatório, formato email) substituído por `login`
  (obrigatório, string — sem `email:` como regra, porque também aceita
  matrícula).
- `Modules/Autenticacao/app/Http/Controllers/AutenticacaoController.php` —
  passa `$request->login` em vez de `$request->email` para o serviço.
- `Modules/Autenticacao/app/Service/GestaoAutenticacao.php` —
  `login($identificador, $password, $remember, $key)` decide internamente
  qual chave usar no `Auth::attempt()` consoante o formato de
  `$identificador` (presença de `@`).
- `Modules/Autenticacao/resources/js/Pages/Login.vue` — campo/label
  genérico ("Email ou Matrícula"), `form.email` renomeado para
  `form.login`.
- `Modules/Usuario/app/Models/User.php` — `$fillable` passa a incluir
  `numero_matricula` e `tipo_login` (colunas já existem na BD mas o model
  não as expõe atualmente).

## Encarregado ↔ Aluno

Um encarregado pode ter vários educandos na mesma escola (ex: 3 filhos na
mesma creche) e um aluno pode ter vários encarregados (mãe e pai, por
exemplo) — relação muitos-para-muitos entre duas contas `users` reais:

```php
Schema::create('encarregados_alunos', function (Blueprint $table) {
    $table->id();
    $table->foreignId('encarregado_id')->constrained('users')->onDelete('cascade');
    $table->foreignId('aluno_id')->constrained('users')->onDelete('cascade');
    $table->string('parentesco')->nullable(); // pai, mãe, tutor, outro
    $table->unique(['encarregado_id', 'aluno_id']);
    $table->timestamps();
});
```

Modelo `EncarregadoAluno` com `belongsTo(User::class, 'encarregado_id')` e
`belongsTo(User::class, 'aluno_id')`. No `User`, duas relações novas:
`User::educandos()` (`belongsToMany(User::class, 'encarregados_alunos',
'encarregado_id', 'aluno_id')`) e `User::encarregados()`
(`belongsToMany(User::class, 'encarregados_alunos', 'aluno_id',
'encarregado_id')`).

O cadastro de um Encarregado exige ligar pelo menos um Aluno já existente
(não faz sentido um encarregado sem educandos) — ver secção seguinte.

Como cada escola (tenant) tem a sua própria base de dados — em Local por
instalação dedicada, em Cloud por conexão trocada pelo stancl/tenancy — um
encarregado da Escola A nunca pode ser associado a um aluno da Escola B: a
tabela `encarregados_alunos` só vê os `users.id` da BD à qual está ligada
no momento. O isolamento é uma consequência da arquitetura de dados, não
precisa de nenhuma verificação adicional no código.

## Geração de matrícula

A matrícula do Aluno é **sempre gerada pelo sistema**, nunca introduzida
manualmente. Formato: `{ano}-{sequencial:04d}` (ex: `2026-0001`), sequencial
reiniciado a cada ano civil.

Como o módulo Ano Letivo ainda não existe, usa-se `now()->year` como
substituto. Quando o Ano Letivo for modelado, a geração passa a basear-se
nele — mudança isolada ao serviço de geração, sem impacto no resto do
desenho.

**Concorrência:** duas matrículas criadas em simultâneo não podem colidir
no mesmo número. Em vez de calcular `MAX(numero_matricula) + 1` (sujeito a
condição de corrida sob carga), usa-se uma tabela de contador dedicada:

```php
Schema::create('matricula_sequencias', function (Blueprint $table) {
    $table->id();
    $table->unsignedSmallInteger('ano')->unique();
    $table->unsignedInteger('ultimo_numero')->default(0);
    $table->timestamps();
});
```

Geração dentro de uma transação com `lockForUpdate()` sobre a linha do ano
corrente (criando-a com `ultimo_numero = 0` se ainda não existir),
incrementando e devolvendo `"{$ano}-" . str_pad($novoNumero, 4, '0', STR_PAD_LEFT)`.
Isto serializa apenas a criação de Alunos (contenção mínima, escala bem
para o volume esperado de matrículas por escola).

## Fluxo de criação de utilizador

O formulário de cadastro (`UsuarioFormFields.vue` + Forms por tipo) passa a
saber, por tipo de pessoa, qual mecanismo de login se aplica:

- `AlunoForm.vue` → `tipoLogin = 'matricula'` — esconde o campo Email, não
  pede matrícula (é gerada no backend, não é um input).
- `ProfessorForm.vue`, `FuncionarioForm.vue`, `AdministradorForm.vue` →
  `tipoLogin = 'email'` — mantém-se como está hoje.
- `EncarregadoForm.vue` (novo, 5º tipo de formulário) → `tipoLogin =
  'email'`, mais um seletor para escolher um ou mais Alunos já existentes
  a ligar (pesquisa por nome ou matrícula); envia a lista de `aluno_id`
  selecionados junto com os dados da pessoa.
- `UsuarioForm.vue` (genérico, lista "Todos os Utilizadores") → deriva
  `tipoLogin` da seleção de tipo de pessoa feita no próprio formulário
  (Aluno → matrícula; os restantes, incluindo Encarregado → email), em vez
  de pedir separadamente.

Backend (`CriarUsuarioRequest`, `UsuarioDTO`, `UsuarioAction`):

- `CriarUsuarioRequest` recebe `tipo_login` e valida `email` como
  obrigatório apenas quando `tipo_login = email`; nunca aceita
  `numero_matricula` do cliente. Quando o tipo de pessoa é Encarregado,
  valida `alunos_ids` como array obrigatório com pelo menos um id
  existente em `users`.
- `UsuarioDTO` ganha as propriedades `tipoLogin` (enum `TipoLogin`),
  `numeroMatricula` (nullable, preenchida internamente pela Action, nunca
  vinda do request) e `alunosIds` (array, nullable — só usado quando o
  tipo de pessoa é Encarregado).
- `UsuarioAction::criar()` — quando `tipoLogin = MATRICULA`, chama o
  serviço de geração de matrícula e ignora `email`; quando `tipoLogin =
  EMAIL`, usa o `email` fornecido e `numero_matricula` fica `null`. Depois
  de criar o utilizador, se `alunosIds` não for vazio, cria as linhas
  correspondentes em `encarregados_alunos`.

## Testes a cobrir (na fase de implementação)

- Geração de matrícula: formato correto, sequencial por ano, sem colisão
  sob criação concorrente (teste com múltiplas chamadas em paralelo ou
  simulação de lock).
- Login por email continua a funcionar exatamente como hoje.
- Login por matrícula autentica corretamente o Aluno.
- Criação de Aluno via `UsuarioAction` gera matrícula e não grava email
  vazio como string (deve ficar `null`).
- Criação de Professor/Secretário/Admin escola continua a exigir email e
  não gera matrícula.
- Criação de Encarregado exige pelo menos um `aluno_id` válido; grava as
  linhas em `encarregados_alunos`; falha se `alunos_ids` vier vazio.
- Um encarregado ligado a 3 alunos consegue autenticar-se uma vez (email +
  senha) e `User::educandos()` devolve os 3.
- Login por email de um Encarregado funciona pelo mesmo caminho que
  Professor/Secretário/Admin escola (sem código especial para o perfil).

## Questões em aberto (não bloqueiam esta fase)

- Recuperação de senha para contas por matrícula — o mecanismo padrão do
  Laravel (`password_reset_tokens`, envio por email) não serve
  diretamente para Alunos sem email. Precisa de desenho próprio (ex: reset
  assistido pela Secretaria, ou por email de um encarregado) — fica para
  quando este fluxo for necessário.
- Se/quando o Ano Letivo for modelado, a geração de matrícula deve migrar
  do ano civil para o ano letivo ativo. fica para furuto
