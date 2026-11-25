# Portal do Encarregado — Fatia 0 (fundação) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** O encarregado entra, vê a lista dos seus educandos (ou vai direto ao único), escolhe um e chega à página de início desse educando; tudo protegido por uma regra única de acesso.

**Architecture:** Módulo novo `Portal` (só leitura, sem tabelas). A ligação encarregado→aluno é a tabela `encarregados_alunos` já existente. Um serviço único (`EducandosDoEncarregadoService`) decide quais educandos o encarregado pode ver; um middleware (`portal.educando`) aplica-o a toda a rota `/portal/{educando}`. A conta de encarregado passa a ser de perfil fixo, como a do Aluno. As permissões "herdadas" lêem-se do perfil Aluno por um método do `PermissionResolver`, sem copiar nada para o encarregado.

**Tech Stack:** Laravel 12 + nwidart/laravel-modules, Inertia + Vue 3 (módulo com `resources/js/Pages`), Pest/PHPUnit (`Tests\TestCase`, `RefreshDatabase`).

**Spec:** [docs/superpowers/specs/2026-11-25-portal-encarregado-design.md](../specs/2026-11-25-portal-encarregado-design.md)

## Global Constraints

- **Nada de `git commit`.** Só `git add` no fim de cada tarefa (regra do dono; o commit é dele).
- Controllers finos: Service para leitura, Action para escrita. Esta fatia só lê, por isso não há Actions novas.
- Em PHP, importar classes com `use` no topo; nunca FQN inline.
- Sem tabelas nem migrações novas. `encarregados_alunos` fica como está.
- Sem limite novo na licença; o encarregado já conta em `Recurso::UTILIZADORES`.
- Encarregado: conta de perfil fixo (um só perfil, sem overrides). Quem também é staff tem duas contas.
- Estado do educando: o encarregado vê o que o aluno veria. Regra usada: educando com `users.estado = Estado::ATIVO` (a mesma que bloqueia o login, `GestaoAutenticacao`). Qualquer outro estado: não listado e 404.
- Resposta a educando que não é do encarregado: **404**, igual ao inexistente (nunca 403).
- O ponto único `EducandosDoEncarregadoService` é o sítio onde o futuro bloqueio por atraso de propina entra. Não espalhar verificações por páginas.
- Permissão nova: `area-encarregado.ver` (módulo `AREA_ENCARREGADO = 35`).
- UI: reutilizar `AppLayout`, `EstadoVazio`, cartões internos `bg-body-secondary`, usável em telemóvel (`col-12 col-md-6`).
- Testar o fluxo HTTP real (GET com sessão), não só o serviço.

## Review Focus

1. Encarregado A pede `/portal/{id}` de um aluno que só é filho de B → 404 (Task 4 e 5).
2. Educando inactivo: não aparece na lista e `/portal/{id}` dá 404 (Task 4 e 5).
3. Encarregado sem nenhum educando ligado: `/portal` mostra estado vazio, sem erro (Task 5).
4. Aluno, professor ou admin a abrir `/portal` → 403; encarregado a abrir `/minhas-notas` ou `/minhas-faltas` → 403, ou seja, a herança não abre as rotas do aluno (Task 3 e 5).
5. Ligação cruzada entre escolas (linha de `encarregados_alunos` de outro tenant) não aparece (Task 4).

## File Structure

| Ficheiro | Responsabilidade |
|---|---|
| `Modules/Usuario/app/Models/User.php` (alterar) | `ePerfilEncarregado()`, `eContaDePerfilFixo()` |
| `Modules/Permissao/app/Exceptions/PerfilDeAlunoFixo.php` (alterar) | mensagens do encarregado |
| `Modules/Permissao/app/Services/PermissionResolver.php` (alterar) | overrides ignorados para conta fixa; `conjuntoDoPerfilAluno()` |
| `Modules/Permissao/app/Actions/AtribuirPerfilAction.php`, `SincronizarPermissoesUtilizadorAction.php`, `Http/Controllers/PermissaoController.php` (alterar) | bloqueios de conta fixa também para o encarregado |
| `Modules/Permissao/app/Enums/Modulo.php`, `database/seeders/ModuloSeeder.php`, `Actions/SincronizarPerfisDeSistemaAction.php`, `resources/js/Support/menusDosModulos.js` (alterar) | módulo de permissões `area-encarregado` |
| `Modules/Portal/**` (criar) | módulo: provider, rotas, middleware, serviços, controller, página Vue, testes |
| `app/Http/Controllers/HomeController.php` (alterar) | encarregado vai do dashboard para o portal |
| `resources/js/Components/Layout/SidebarMenuWrapper.vue` (alterar) | entrada de menu |
| `modules_statuses.json` (alterar) | activar o módulo |
| `tests/Feature/Arquitectura/FronteiraPortalTest.php` (criar) | ninguém importa o Portal |

---

### Task 1: Conta de encarregado de perfil fixo

**Files:**
- Modify: `Modules/Usuario/app/Models/User.php` (junto de `ePerfilAluno()`, linha ~115)
- Modify: `Modules/Permissao/app/Exceptions/PerfilDeAlunoFixo.php`
- Modify: `Modules/Permissao/app/Services/PermissionResolver.php:117`
- Modify: `Modules/Permissao/app/Actions/AtribuirPerfilAction.php:35-39`
- Modify: `Modules/Permissao/app/Actions/SincronizarPermissoesUtilizadorAction.php:37`
- Modify: `Modules/Permissao/app/Http/Controllers/PermissaoController.php:75`
- Test: `Modules/Permissao/tests/Feature/PerfilDeEncarregadoFixoTest.php`

**Interfaces:**
- Produces: `User::ePerfilEncarregado(): bool`; `User::eContaDePerfilFixo(): bool` (aluno **ou** encarregado); `PerfilDeAlunoFixo::encarregadoSemPerfisExtra(): self`, `PerfilDeAlunoFixo::encarregadoSemPermissoesPersonalizadas(): self`.

- [ ] **Step 1: Write the failing test** `PerfilDeEncarregadoFixoTest`, no mesmo molde de `PerfilDeAlunoFixoTest` (admin autenticado; `setUp` semeia `PermissaoDatabaseSeeder` e cria `$this->encarregado` com o perfil `Perfil::ENCARREGADO`). Testes, com estas asserções:
  - `test_ecra_de_permissoes_do_encarregado_e_recusado`: `GET /permissoes/utilizadores/{id}/permissoes` → `assertForbidden()`.
  - `test_guardar_overrides_de_encarregado_e_recusado`: `PUT` no mesmo URL com uma célula → `assertSessionHasErrors('celulas')` e `assertDatabaseMissing('user_permissoes', ['users_id' => ...])`.
  - `test_encarregado_nao_recebe_perfil_extra`: `POST /permissoes/utilizadores/{id}/perfis` com o role Funcionário → `assertSessionHasErrors('role_id')`; `roles()->count()` continua 1.
  - `test_perfil_encarregado_nao_e_atribuido_a_outros_utilizadores`: atribuir o role Encarregado a um professor → `assertSessionHasErrors('role_id')`.
  - `test_overrides_antigos_de_encarregado_nao_tem_efeito`: criar um override `usuario.eliminar` permitido directamente; `assertNotContains('usuario.eliminar', app(PermissionResolver::class)->conjuntoConcedido($this->encarregado))`.
- [ ] **Step 2: Run** `php artisan test Modules/Permissao/tests/Feature/PerfilDeEncarregadoFixoTest.php`. Expected: FAIL (os 5 testes).
- [ ] **Step 3: Implement.** `ePerfilEncarregado()` espelha `ePerfilAluno()` com `Perfil::ENCARREGADO->value`; `eContaDePerfilFixo()` devolve `ePerfilAluno() || ePerfilEncarregado()`. Em `PermissionResolver`, `PermissaoController` e `SincronizarPermissoesUtilizadorAction` trocar `ePerfilAluno()` por `eContaDePerfilFixo()`; a excepção lançada para o encarregado usa as novas fábricas (mensagens: «Utilizadores encarregado usam apenas as permissões do seu perfil.» e «Um utilizador encarregado não pode ter outros perfis, nem o perfil de encarregado pode ser atribuído a outros utilizadores.»). Em `AtribuirPerfilAction`, bloquear também quando `$user->ePerfilEncarregado()` ou `$roleId` for o role Encarregado.
- [ ] **Step 4: Run** o teste novo e `php artisan test Modules/Permissao Modules/Usuario`. Expected: PASS; os testes de aluno continuam verdes.
- [ ] **Step 5:** `git add` dos ficheiros tocados.

---

### Task 2: Módulo de permissões `area-encarregado` e perfil Encarregado

**Files:**
- Modify: `Modules/Permissao/app/Enums/Modulo.php` (case, `acoesAplicaveis`, `slug`, `label`; modelo: `AREA_PROFESSOR`)
- Modify: `Modules/Permissao/database/seeders/ModuloSeeder.php`
- Modify: `Modules/Permissao/app/Actions/SincronizarPerfisDeSistemaAction.php` (mapa `PERMISSOES_POR_PERFIL`)
- Modify: `Modules/Permissao/resources/js/Support/menusDosModulos.js`
- Modify: `Modules/Permissao/tests/Feature/ModuloSeederTest.php:54`, `AcoesAplicaveisTest.php:71`
- Test: `Modules/Permissao/tests/Feature/AreaEncarregadoPermissoesTest.php`

**Interfaces:**
- Produces: `Modulo::AREA_ENCARREGADO` (valor 35, slug `area-encarregado`, label `Área do Encarregado`, acções `['ver']`); o perfil Encarregado recebe `area-encarregado.ver` e mais nada.

- [ ] **Step 1: Write the failing test** `AreaEncarregadoPermissoesTest`, no molde de `FrequenciaPermissoesTest`: `test_modulo_tem_slug_e_accoes` (`35`, `'area-encarregado'`, `'Área do Encarregado'`, `['ver']`); `test_encarregado_so_tem_ver_na_area_do_encarregado` (acções do perfil neste módulo = `['ver']`); `test_nenhum_outro_perfil_tem_a_area_do_encarregado` (Admin, Funcionário, Professor, Aluno: vazio); `test_encarregado_so_tem_essa_permissao_em_todo_o_sistema` (o total de `RolePermissao` do role Encarregado é 1).
- [ ] **Step 2: Run** o teste. Expected: FAIL.
- [ ] **Step 3: Implement** o enum e o seeder (`['nome' => 35, 'descricao' => 'Area do Encarregado']`); em `PERMISSOES_POR_PERFIL` acrescentar `Perfil::ENCARREGADO->value => [Modulo::AREA_ENCARREGADO->value => ['ver']]`; em `menusDosModulos.js` acrescentar `'area-encarregado': { menu: 'Pessoal', entrada: 'Área do Encarregado' }`. Actualizar `ModuloSeederTest` (35 → 36) e a lista de `AcoesAplicaveisTest` (módulos só-`ver`/especiais) com `AREA_ENCARREGADO`.
- [ ] **Step 4: Run** `php artisan test Modules/Permissao tests/Feature/RotasPermissaoReconhecidaTest.php`. Expected: PASS. Corrigir qualquer contagem de módulos que falhar por 35 → 36.
- [ ] **Step 5:** `git add`.

---

### Task 3: Herança de permissões do perfil Aluno (leitura)

**Files:**
- Modify: `Modules/Permissao/app/Services/PermissionResolver.php`
- Test: `Modules/Permissao/tests/Feature/PermissoesHerdadasDoAlunoTest.php`

**Interfaces:**
- Produces: `PermissionResolver::conjuntoDoPerfilAluno(): array` — lista `list<string>` de `modulo.acao` do role Aluno (hoje `nota.ver-proprias`, `frequencia.ver-proprias`), reaproveitando `paraString()`. Não toca em `conjuntoConcedido()` do encarregado: o encarregado **não** recebe estas permissões no seu conjunto (se recebesse, o menu mostrava «As minhas notas» e as rotas do aluno abriam para quem não é aluno).

- [ ] **Step 1: Write the failing test**:
  - `test_conjunto_do_perfil_aluno_tem_as_permissoes_do_aluno`: `assertEqualsCanonicalizing(['nota.ver-proprias', 'frequencia.ver-proprias'], app(PermissionResolver::class)->conjuntoDoPerfilAluno())`.
  - `test_encarregado_nao_recebe_as_permissoes_do_aluno_no_seu_conjunto`: `conjuntoConcedido($encarregado)` é exactamente `['area-encarregado.ver']`.
  - `test_encarregado_nao_abre_as_paginas_do_aluno`: autenticado como encarregado, `GET /minhas-notas` e `GET /minhas-faltas` → `assertForbidden()`.
  - `test_acompanha_mudancas_do_perfil_aluno`: depois de dar `nota.ver` ao role Aluno via `RolePermissao::create`, `conjuntoDoPerfilAluno()` inclui `nota.ver` (a herança é sempre a lista actual do perfil, sem cópia).
- [ ] **Step 2: Run.** Expected: FAIL (método inexistente).
- [ ] **Step 3: Implement** `conjuntoDoPerfilAluno()`: ids do role com `nome = Perfil::ALUNO->value` e `estado = ATIVO`, `RolePermissao` desses roles mapeado por `paraString()`. Sem cache persistente; memoizar em `$this->memoria` com chave `perfil-aluno:<tenant>`, e limpar essa chave em `esquecerTudo()`.
- [ ] **Step 4: Run** o teste e `php artisan test Modules/Permissao`. Expected: PASS.
- [ ] **Step 5:** `git add`.

---

### Task 4: Módulo `Portal`, serviço de educandos e middleware

**Files:**
- Create: `Modules/Portal/module.json`, `composer.json`, `app/Providers/PortalServiceProvider.php`, `app/Providers/RouteServiceProvider.php`, `routes/web.php` (vazio por agora, só `<?php` e `use`), `routes/api.php` (vazio)
- Create: `Modules/Portal/app/Services/EducandosDoEncarregadoService.php`
- Create: `Modules/Portal/app/Http/Middleware/EducandoDoEncarregado.php`
- Create: `Modules/Portal/tests/Support/CenarioDeFamilia.php`
- Modify: `modules_statuses.json` (`"Portal": true`)
- Test: `Modules/Portal/tests/Feature/EducandosDoEncarregadoServiceTest.php`

**Interfaces:**
- Produces:
  - `EducandosDoEncarregadoService::listar(User $encarregado): Collection<int, User>` — só `users.estado = Estado::ATIVO->value`, ordenados por `name`; cada `User` traz `pivot->parentesco`.
  - `EducandosDoEncarregadoService::obter(User $encarregado, int $educandoId): ?User` — `null` se não ligado, de outra escola, ou inactivo.
  - Middleware `EducandoDoEncarregado`, alias `portal.educando`: lê o parâmetro de rota `educando` (int), usa `obter()`, aborta `404` se `null`, senão faz `$request->attributes->set('educando', $user)`.
  - Trait de teste `CenarioDeFamilia` com `criarEncarregado(string $email = 'enc@example.com'): User` (perfil Encarregado), `criarEducando(string $nome, string $matricula, int $estado = 1): User` (perfil Aluno, `numero_matricula`), `ligar(User $enc, User $aluno, ?string $parentesco = null): void`; semeia `PermissaoDatabaseSeeder` no `prepararCenario()`.
- Consumes: `User::educandos()` (já existe, filtra pelo tenant actual).

- [ ] **Step 1: Write the failing test** `EducandosDoEncarregadoServiceTest` (usa `CenarioDeFamilia`, `RefreshDatabase`):
  - `test_lista_so_os_educandos_activos_do_encarregado_por_ordem_de_nome`: dois activos (`Zeca`, `Ana`), um inactivo (`estado = 0`), um aluno de outro encarregado → `['Ana', 'Zeca']`.
  - `test_obter_devolve_o_educando_ligado_e_null_para_os_outros`: filho próprio → `User`; aluno de outro encarregado → `null`; id inexistente → `null`; educando inactivo → `null`.
  - `test_ligacao_de_outra_escola_nao_aparece`: criar um segundo tenant com `criarTenant()` + `noTenant()` (padrão em `UsuarioTenancyTest`), nele um aluno; inserir directamente em `encarregados_alunos` a linha `tenant_id = <outro>` apontando para ele; `listar()` e `obter()` não o devolvem.
  - `test_o_encarregado_sem_educandos_recebe_lista_vazia`.
- [ ] **Step 2: Run** `php artisan test Modules/Portal`. Expected: FAIL.
- [ ] **Step 3: Implement.** Copiar o esqueleto de `Modules/Frequencia` (module.json com `"name": "Portal", "alias": "portal"`, composer.json com `Modules\\Portal\\`, `PortalServiceProvider extends BaseModuleServiceProvider` com `name = 'Portal'`, `nameLower = 'portal'`, `providers = [RouteServiceProvider::class]`). No `boot()` do provider: `$this->app['router']->aliasMiddleware('portal.educando', EducandoDoEncarregado::class)`. O serviço usa `$encarregado->educandos()->where('users.estado', Estado::ATIVO->value)`. Correr `composer dump-autoload`.
- [ ] **Step 4: Run** `php artisan test Modules/Portal`. Expected: PASS.
- [ ] **Step 5:** `git add Modules/Portal modules_statuses.json`.

---

### Task 5: Rotas, controller e dados da página de início

**Files:**
- Create: `Modules/Portal/app/Http/Controllers/PortalController.php`
- Create: `Modules/Portal/app/Services/PortalInicioService.php`
- Modify: `Modules/Portal/routes/web.php`
- Modify: `app/Http/Controllers/HomeController.php` (`index`)
- Test: `Modules/Portal/tests/Feature/PortalHttpTest.php`, `app/`-nível: acrescentar caso em `tests/Feature/DashboardTest.php`

**Interfaces:**
- Consumes: `EducandosDoEncarregadoService`, `PermissionResolver::conjuntoDoPerfilAluno()`, middleware `portal.educando`.
- Produces:
  - Rotas (grupo `['auth', 'can:area-encarregado.ver']`, prefixo `portal`): `GET /portal` → `portal.index`; `GET /portal/{educando}` (`whereNumber`, middleware `portal.educando`) → `portal.educando`.
  - `PortalInicioService::dados(User $encarregado, ?User $educando): array` → `['educandos' => list<{id:int,nome:string,numero_matricula:?string,parentesco:?string}>, 'educando' => ?{id,nome,numero_matricula,parentesco}, 'acessos' => list<string>]` (`acessos` = `conjuntoDoPerfilAluno()`, usado pelas fatias seguintes para saber que páginas mostrar).
  - Página Inertia `Portal/Inicio` com essas três props.
  - `PortalController::index`: 1 educando → `redirect()->route('portal.educando', $id)`; 0 ou vários → render com `educando = null`. `PortalController::show`: render com o educando do atributo `educando`.

- [ ] **Step 1: Write the failing test** `PortalHttpTest` (`CenarioDeFamilia`; usar `Inertia\Testing\AssertableInertia as Assert`):
  - `test_encarregado_com_um_filho_e_redireccionado_para_o_filho`: `GET /portal` → `assertRedirect("/portal/{$filho->id}")`.
  - `test_encarregado_com_varios_filhos_ve_a_lista`: `GET /portal` → `component('Portal/Inicio')`, `has('educandos', 2)`, `where('educando', null)`.
  - `test_pagina_do_educando_traz_os_dados_e_os_acessos`: `GET /portal/{id}` → `where('educando.id', $id)`, `where('educando.parentesco', 'Mãe')`, `has('educandos', 2)`, `acessos` contém `nota.ver-proprias`.
  - `test_filho_de_outro_encarregado_da_404`: igual a `GET /portal/999999`.
  - `test_educando_inactivo_da_404_e_nao_e_listado`.
  - `test_encarregado_sem_educandos_ve_estado_vazio`: `GET /portal` → 200, `has('educandos', 0)`.
  - `test_quem_nao_e_encarregado_recebe_403`: admin, professor, aluno em `GET /portal` → `assertForbidden()`.
  - `test_visitante_vai_para_o_login`: `assertRedirect('/login')`.
  - Em `DashboardTest`: `test_encarregado_no_dashboard_vai_para_o_portal`: `GET /` como encarregado → `assertRedirect('/portal')`.
- [ ] **Step 2: Run.** Expected: FAIL (404 nas rotas).
- [ ] **Step 3: Implement** rotas, controller fino (só chama os serviços e `Inertia::render('Portal/Inicio', ...)`), `PortalInicioService`. Em `HomeController::index`, antes do render: `if ($utilizador->ePerfilEncarregado()) { return redirect()->route('portal.index'); }`.
- [ ] **Step 4: Run** `php artisan test Modules/Portal tests/Feature/DashboardTest.php tests/Feature/RotasPermissaoReconhecidaTest.php tests/Feature/RotasEscritaAutorizadasTest.php tests/Feature/RotasSensiveisComLimiteTest.php`. Expected: PASS.
- [ ] **Step 5:** `git add`.

---

### Task 6: Página Vue e menu

**Files:**
- Create: `Modules/Portal/resources/js/Pages/Inicio.vue`
- Modify: `resources/js/Components/Layout/SidebarMenuWrapper.vue` (junto de `areaProfessor`, ~linhas 56 e 526)

**Interfaces:**
- Consumes: props `educandos`, `educando`, `acessos` (Task 5); `AppLayout`, `EstadoVazio` (`@/Components/Shared/EstadoVazio.vue`) como em `MinhasFaltas.vue`.
- Produces: item de menu «Área do Encarregado» → «Os meus educandos» (`/portal`, `permissao: 'area-encarregado.ver'`).

- [ ] **Step 1: Implement `Inicio.vue`** (`defineOptions({ layout: AppLayout })`, `app-container container-xxl py-6`): 
  - sem educandos: `EstadoVazio` com título «Sem educandos ligados» e descrição «A escola ainda não ligou nenhum aluno à sua conta.»;
  - `educando` nulo e vários educandos: título «Escolha o educando» e um cartão por filho (`col-12 col-md-6 col-xl-4`, nome, nº de matrícula, parentesco) com `<Link :href="`/portal/${e.id}`">`;
  - `educando` definido: cabeçalho com o nome, nº de matrícula e parentesco; com mais de um educando, um `<select>` que faz `router.visit(`/portal/${id}`)`; abaixo, um cartão `bg-body-secondary` com o texto «As informações do seu educando vão aparecendo aqui à medida que a escola as disponibiliza.».
- [ ] **Step 2: Implement o menu:** `const areaEncarregado = { title: 'Área do Encarregado', icon: 'ki-people', paths: 1, items: [{ href: '/portal', title: 'Os meus educandos', permissao: 'area-encarregado.ver' }] }` e `<SidebarAccordion :item="areaEncarregado" heading="Área do Encarregado" />` logo a seguir ao da Área do Professor.
- [ ] **Step 3: Verify:** `npm run build`. Expected: compila sem erros e sem avisos de componente em falta.
- [ ] **Step 4: Verificação visual (pedir ao dono):** não gravar dados na BD real. Pedir ao dono que crie um encarregado pelo formulário de Utilizadores (com 1 e depois 2 matrículas de educandos), entre com ele e confira: redirecionamento do dashboard, lista, selector, menu lateral, telemóvel (< 768px).
- [ ] **Step 5:** `git add`.

---

### Task 7: Licença, fronteira de módulos e documentação

**Files:**
- Modify: `Modules/Licenciamento/tests/Feature/LimitesDeLicencaTest.php` (acrescentar casos)
- Create: `tests/Feature/Arquitectura/FronteiraPortalTest.php`
- Modify: `docs/superpowers/specs/2026-11-25-portal-encarregado-design.md` (§5.1), `ROADMAP.md` (F10), `README.md` (estado do módulo)

- [ ] **Step 1: Write the tests.**
  - Em `LimitesDeLicencaTest` (usa `instalarComLimites`, `criarUser`): `test_encarregado_activo_gasta_uma_vaga_de_utilizador` (com limite `utilizadores = 1` e um encarregado activo, `garantir(Recurso::UTILIZADORES)` lança `LimiteDeLicencaExcedido`) e `test_desactivar_o_encarregado_liberta_a_vaga` (com o encarregado `estado = 0`, `garantir()` passa).
  - `FronteiraPortalTest`, no molde de `FronteiraUsuarioAlunoTest`: nenhum ficheiro de `app/` e `routes/` dos módulos `Usuario`, `Permissao`, `Core`, `Aluno`, `Avaliacao`, `Frequencia`, `Financeiro`, `Horario` contém `Modules\Portal\`.
- [ ] **Step 2: Run** `php artisan test Modules/Licenciamento tests/Feature/Arquitectura`. Expected: PASS (a regra da licença já funciona; os testes provam-na).
- [ ] **Step 3: Docs.** No desenho §5.1, substituir o parágrafo da herança por: o encarregado não recebe as permissões do Aluno no seu conjunto; o portal lê o perfil Aluno por `PermissionResolver::conjuntoDoPerfilAluno()` (motivo: senão o menu mostrava «As minhas notas» e as rotas do aluno abriam para quem não é aluno). No `ROADMAP.md`, F10 passa a `[~] 2026-11-25 (fatia 0 feita)` com uma linha a dizer o que falta (notas, faltas, propinas, recibos, boletim, horário). No `README.md`, acrescentar o módulo Portal à lista de módulos.
- [ ] **Step 4: Run a suite completa:** `php artisan test`. Expected: tudo verde. Se falhar algo, listar os testes com a saída antes de corrigir.
- [ ] **Step 5:** `git add`. **Não fazer commit.** Nota para o dono: nas escolas já existentes é preciso correr o `PermissaoDatabaseSeeder` para a nova permissão `area-encarregado.ver` chegar ao perfil Encarregado.
