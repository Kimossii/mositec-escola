# Mecanismo central de autorização — design

**Data:** 2026-09-02
**Estado:** aprovado em conversa, a aguardar revisão do ficheiro escrito.

## 1. Problema

O MosiTec já tem, na parte de cadastro, um sistema de permissões completo: Perfis (`Role`), Módulos (`Modulo`), Ações (`Acao`), permissões por perfil (`role_permissoes`) e overrides individuais por utilizador (`user_permissoes.permitido`: `true` = Concedido, `false` = Negado — nunca `null`; a coluna é `NOT NULL` e a validação exige `boolean`). Não existir uma linha para um dado `(utilizador, módulo, acção)` significa simplesmente "sem override" — a decisão cai para o que o perfil concede; isto nunca é apresentado nem guardado como um terceiro estado nomeado, só a ausência de uma linha.

Nada disto é consultado em runtime hoje. Toda a autorização real (AnoLectivo, Estabelecimento, Horario) usa gates fixas, cabladas a um único perfil:

```php
Gate::define('gerir-ano-letivo', fn (User $user) => $user->roles->contains('nome', Perfil::ADMIN_ESCOLA->value));
```

Isto não escala: cada módulo novo exige uma gate nova, hardcoded a um perfil, sem qualquer granularidade por acção nem possibilidade de excepção individual — apesar de a estrutura de dados para isso já existir e estar a ser gerida nos ecrãs de administração.

## 2. Objectivo

Um mecanismo central, reutilizável por qualquer módulo, que:

- Calcula a permissão final de um utilizador para uma acção num módulo (`'turmas.criar'`), combinando o perfil e os overrides individuais.
- É a única fonte de verdade tanto para o backend (Policies/Controllers/rotas — bloqueia com 403 de forma real) como para o frontend (Vue+Inertia — `can('turmas.criar')` para esconder/mostrar UI).
- Não obriga cada módulo a escrever uma Policy nova só para repetir "este perfil pode fazer X" — isso deve ser automático a partir dos dados já existentes em `role_permissoes`/`user_permissoes`.

## 3. Fora de âmbito

- Não mexe no ecrã de gestão de perfis/permissões já existente (o CRUD de `role_permissoes`/`user_permissoes` já está feito).
- Não migra as gates `gerir-permissoes` e `gerir-usuarios` (módulos Usuario/Permissao) — só as 3 já identificadas: AnoLectivo, Estabelecimento, Horario.
- Não cobre autorização por dono-de-registo (ex: "só o Professor dono desta Turma") — esse tipo de regra continua a viver numa Policy própria do módulo, que pode compor com este mecanismo mas não é substituída por ele. **Isto não é um caso raro**: módulos como Frequência, Notas e Planos de Aula vistos por um Professor (só as suas próprias turmas), ou Ficha do Aluno/Histórico Escolar/Financeiro vistos por um Encarregado (só os seus próprios educandos), vão precisar sempre desta segunda camada por módulo. O mecanismo central resolve a primeira camada ("este perfil pode mexer em Frequência, em geral") de graça; a segunda camada continua a ser trabalho próprio de cada módulo, não algo que este plano elimine. Em módulos vistos só por perfis administrativos (Configurações, Sistema, Financeiro visto por Secretário/Admin, Infraestrutura) o mecanismo central chega sozinho.

## 4. Convenção da string de permissão

Formato fixo: `{slug-do-modulo}.{nome-da-acção}`.

- `nome-da-acção` já existe tal-e-qual na tabela `acoes`: `ver`, `criar`, `editar`, `eliminar`, `listar`, `exportar`.
- `slug-do-modulo` é novo — hoje `modulos.nome` é só um inteiro com uma `descricao` humana ao lado, sem slug. Resolvido pelo enum abaixo.

## 5. `Modules\Permissao\Enums\Modulo`

Espelha o `Perfil` já existente — um enum `int`-backed, um caso por módulo, com `slug()`, `fromSlug(string): self` e `label()`. O valor inteiro de cada caso **tem de bater certo** com o `modulos.nome` já seedado, para não migrar dados:

| Caso | int (`modulos.nome`) | slug |
|---|---|---|
| `USUARIO` | 0 | `usuario` |
| `AUTORIZACAO` | 1 | `autorizacao` |
| `ANO_LECTIVO` | 2 | `ano-lectivo` |
| `LICENCA` | 3 | `licenca` |
| `ALUNO` | 4 | `aluno` |
| `PROFESSOR` | 5 | `professor` |
| `TURMAS` | 6 | `turmas` |
| `MATRICULA` | 7 | `matricula` |
| `DISCIPLINA` | 8 | `disciplina` |
| `NOTA` | 9 | `nota` |
| `ESTABELECIMENTO` | 10 | `estabelecimento` |
| `HORARIO` | 11 | `horario` |

`HORARIO` não está seedado em `modulos` hoje (o `ModuloSeeder` só vai até 9/10) — falta acrescentar essa linha (ver secção 10). Os módulos 12–19 mencionados em comentário na migração (materialditatico, financeiro, etc.) **não** entram no enum agora — YAGNI: cada módulo novo acrescenta o seu próprio caso + linha seed quando for construído, não antes.

## 6. Algoritmo de resolução

Para `PermissionResolver::can($user, 'turmas.criar')`:

1. Parseia a string: `Modulo::fromSlug('turmas')` + `Acao::where('nome', 'criar')` → `modulo_id`/`acao_id`. Se o slug do módulo não for reconhecido, o `Gate::before` (secção 8) simplesmente não intercepta — a ability segue o caminho normal do Laravel (que, sem Policy/Gate registada, nega por omissão). Isto não deve acontecer em produção; a secção 11 tem um teste dedicado para apanhar isto em CI, não em runtime.
2. Procura em `user_permissoes` uma linha para `(user, modulo, acao)`:
   - `permitido = true` → **permitido**, decisão final (Concede vence sempre, mesmo Admin Escola).
   - `permitido = false` → **negado**, decisão final (Nega vence sempre, mesmo Admin Escola).
   - sem linha (sem override para este utilizador) → passa ao passo 3.
3. Para cada role do utilizador (um utilizador pode ter mais que uma), procura uma linha em `role_permissoes` para `(role, modulo, acao)`. Se **alguma** existir → **permitido**.
4. Nenhuma role concede, e não há override → **negado**. Um utilizador sem nenhuma role e sem nenhum override é sempre negado (nunca "permite por omissão").

Este algoritmo não corre "ao vivo" para cada `can()` isolado — ver secção 7.

## 7. `PermissionResolver` — cálculo e cache

Três operações públicas:

- `conjuntoConcedido(User $user): array<string>` — a operação de fundo. Não itera todos os casos do enum `Modulo` × todas as `acoes` a testar um por um; faz **duas queries** (as linhas de `role_permissoes` para todas as roles do utilizador, e todas as linhas de `user_permissoes` desse utilizador), aplica o algoritmo da secção 6 em memória sobre esses dois conjuntos pequenos, e devolve a lista final de strings `'modulo.acao'` concedidas.
- `can(User $user, string $permissao): bool` — chama `conjuntoConcedido($user)` (já cacheado, ver abaixo) e verifica pertença (`in_array` / `Set::has`).
- `reconhece(string $permissao): bool` — puramente sintáctica, sem tocar em dados do utilizador: confirma que a string tem o formato `modulo.acao` e que tanto o slug do módulo (`Modulo::fromSlug`) como o nome da acção existem. Usada pelo `Gate::before` (secção 8) para decidir se deve tratar esta ability como sua ou deixá-la seguir o caminho normal do Laravel.

Isto garante que backend (`can()`) e frontend (`conjuntoConcedido()`, partilhado via Inertia na secção 9) veem sempre exactamente o mesmo resultado, calculado pelo mesmo código — nunca duas fontes de verdade.

Três camadas, por ordem:

1. **Memória do pedido** — o conjunto calculado fica guardado (estático/instância singleton) para todo o pedido HTTP; múltiplas chamadas a `can()` no mesmo pedido não repetem trabalho.
2. **`Cache` facade do Laravel** (agnóstica de driver — hoje `CACHE_STORE=database`, muda sozinho se um dia passar a Redis), chave `permissoes:v{epoch}:user:{id}`.
3. **Base de dados** — só corre se as duas anteriores falharem (cache frio ou pedido novo).

**Invalidação** (activa, não por TTL):
- Escrita em `role_permissoes` → incrementa um contador global `permissoes:epoch` na cache. Isto invalida instantaneamente **todas** as chaves de todos os utilizadores (a chave muda de `v{epoch}` para `v{epoch+1}`), sem precisar de enumerar quem tinha aquela role.
- Escrita em `user_permissoes` para um utilizador X → `Cache::forget()` só da chave desse utilizador.

Estes dois pontos de invalidação vivem nas Actions que já escrevem nessas tabelas (as do ecrã de gestão de permissões já existente) — passam a chamar o invalidator depois de gravar.

## 8. `Gate::before` — ligação ao Laravel

Um único callback, registado **uma vez** (no boot do módulo Permissao, não em cada módulo consumidor):

```php
Gate::before(function (User $user, string $ability) {
    if (!str_contains($ability, '.') || !PermissionResolver::reconhece($ability)) {
        return null; // não é uma ability modulo.acao conhecida — segue o caminho normal
    }
    // Reconhecida: a decisão do Resolver é final e explícita (true/false),
    // nunca null — um "null" aqui deixaria a porta aberta a um Gate::before
    // futuro (ex: um bypass de super-admin) anular silenciosamente uma Nega.
    return app(PermissionResolver::class)->can($user, $ability);
});
```

`null` só é devolvido quando genuinamente não reconhecemos a ability (sem ponto, ou com um slug de módulo/nome de acção que não existe) — nesse caso sim faz sentido deixar o Laravel seguir o caminho normal (por exemplo, uma Policy de dono-de-registo com uma ability sem ponto, como `'update'` contra uma instância). Mas assim que a ability é reconhecida como `modulo.acao` válida, o resultado do Resolver é sempre `true` ou `false`, nunca `null` — isso é o que torna "Nega vence sempre" (secção 6) uma garantia estrutural, e não uma promessa implícita de que nenhum outro `Gate::before` alguma vez vai mexer nesta mesma ability.

Consequência prática: `Route::middleware('can:turmas.criar')` e `$this->authorize('turmas.criar')` funcionam em **qualquer módulo**, sem escrever nenhuma Policy — só quando houver lógica adicional por registo é que esse módulo escreve a sua própria Policy (que pode chamar o Resolver para a parte "pode fazer X no módulo Y" e acrescentar a sua própria regra por cima).

## 9. Entrega ao frontend (Vue + Inertia)

`HandleInertiaRequests::share()` passa a incluir, em toda e qualquer página Inertia, tal como `auth.user` já entra hoje:

```php
'permissoes' => $request->user()
    ? app(PermissionResolver::class)->conjuntoConcedido($request->user())
    : [],
```

Um array simples de strings (`['ano-lectivo.criar', 'turmas.ver', ...]`) — o mesmo conjunto já calculado (e cacheado, secção 7) pelo Resolver, sem cálculo duplicado.

Um helper JS único, partilhado por todos os módulos (ex: `resources/js/Composables/usePermissoes.js`), lido a partir de `usePage().props.permissoes` (convertido para `Set`, lookup O(1)):

```js
export function can(permissao) {
    return usePage().props.permissoes.includes(permissao);
}
```

Uso em qualquer módulo, sem importar nada específico desse módulo — em templates (`v-if="can('turmas.criar')"`) e nos ficheiros de menu, para esconder/mostrar entradas.

**Regra a manter sempre:** isto é só UX. A garantia real é sempre o backend (secções 6–8) — mesmo que alguém force o pedido directamente (curl, devtools), o `Gate::before` + `PermissionResolver` bloqueiam na mesma. O frontend nunca é fonte de verdade, só espelha o que o backend já decidiu. Nenhum módulo cria o seu próprio sistema de permissões no frontend; todos usam este mesmo `can()`.

## 10. Migração de AnoLectivo, Estabelecimento e Horario

Hoje 2 nomes de gate cobrem os 3 módulos: `gerir-ano-letivo` (AnoLectivo) e `gerir-estabelecimento` (Estabelecimento **e** Horario — Horario foi deliberadamente feito para partilhar esta gate, ver decisão de arquitectura anterior).

**Nota importante, deliberada:** depois desta migração, `horario.*` e `estabelecimento.*` tornam-se dois módulos **independentes** em `role_permissoes` — hoje são inseparáveis (mesma gate); depois, um admin pode conceder um sem o outro. É o ganho de granularidade pretendido, não um efeito colateral escondido.

Passos, por ordem (a ordem importa — sem o passo 1, os passos seguintes causam uma regressão de acesso real):

1. **Seed antes de tocar em código de autorização:**
   - Acrescentar `HORARIO` (11) ao `ModuloSeeder`.
   - Novo seeder de `role_permissoes` que concede a **ADMIN_ESCOLA**, e só a ela, exactamente o que os 3 módulos já expõem hoje:
     - `ano-lectivo`: ver, criar, editar, eliminar.
     - `estabelecimento`: ver, editar (sem criar/eliminar — é um singleton).
     - `horario`: ver, criar, editar, eliminar.
   - Teste de paridade (secção 11) correndo **antes** de remover as gates antigas, confirmando que nada mudou para Admin Escola nem para as outras roles.
2. **Trocar as strings de autorização**, agora uma por acção em vez de uma só para o módulo inteiro:
   - Rotas: cada rota passa a ter a sua própria `can:modulo.acao` (deixam de poder partilhar um único `Route::middleware()->group()` para todas as acções de um módulo).
   - Controllers: `$this->authorize('create', AnoLectivo::class)` → `$this->authorize('ano-lectivo.criar')`.
   - FormRequests: `$this->user()?->can('gerir-ano-letivo')` → `$this->user()?->can('ano-lectivo.criar')` (a acção certa por Request).
3. **Eliminar** `AnoLectivoPolicy`, `PeriodoPolicy`, `EventoCalendarioPolicy`, `HorarioPolicy` — sem lógica de dono-de-registo, tornam-se redundantes com o `Gate::before`.
4. **Remover** `Gate::define('gerir-ano-letivo', ...)` e `Gate::define('gerir-estabelecimento', ...)` do `AppServiceProvider`. `gerir-permissoes` e `gerir-usuarios` ficam, fora de âmbito.

## 11. Testes

**Backend:**
- `PermissionResolverTest` — as 4 combinações do algoritmo (secção 6): sem role/sem override → nega; role concede/sem override → concede; role concede + override Nega → nega vence; role não concede + override Concede → concede vence. Mais o caso "utilizador sem nenhuma role e sem override" → nega.
- `PermissaoCacheInvalidationTest` — escrever em `role_permissoes` invalida todos; escrever em `user_permissoes` invalida só o utilizador certo; um pedido a seguir à invalidação lê o valor novo, não o cacheado.
- `GateBeforeNaoInterfereTest` — uma ability sem ponto, ou com um slug de módulo/acção desconhecido, não é interceptada (`reconhece()` devolve `false`, o callback devolve `null`) — continua a seguir o caminho normal do Laravel.
- `GateBeforeNegaEExplicitaTest` — regressão directa ao caso identificado nesta revisão: para uma ability reconhecida e negada pelo Resolver, o callback devolve `false` (nunca `null`). Confirmar registando um segundo `Gate::before` fictício no teste que devolveria `true` para qualquer ability — e provar que, mesmo assim, o resultado final continua negado, porque o `false` explícito do nosso callback já decidiu antes desse segundo callback correr.
- **Teste de "fiação"**: percorre todas as rotas `can:modulo.acao` registadas na aplicação e confirma que cada slug de módulo e cada nome de acção resolvem para um caso real do enum/tabela — apanha um erro de digitação num nome de rota antes de chegar a produção como um 403 silencioso.
- `AnoLectivo/Estabelecimento/HorarioAutorizacaoTest` — reescritos para as novas strings, confirmando a paridade descrita na secção 10.

**Frontend:** sem framework de testes automatizado no projecto — verificação manual em browser real, como já é prática no MosiTec: um Admin Escola vê e consegue usar UI protegida por `can()`; um utilizador sem essa permissão não a vê **e**, ao forçar o URL/pedido directamente, recebe 403 do backend — prova de que a UI é só cosmética.

## 12. Resumo do que muda por módulo, quando um módulo novo nascer

1. Acrescentar um caso ao enum `Modulo` (mais uma linha no `ModuloSeeder`).
2. Seedar as linhas de `role_permissoes` para os perfis que devem ter acesso de raiz.
3. Usar `can:modulo.acao` nas rotas e `$this->authorize('modulo.acao')` no Controller. Sem Policy nova, salvo lógica por registo.
4. No frontend, `can('modulo.acao')` já funciona sem nada adicional — o array partilhado já cobre qualquer módulo automaticamente.
