# Fundação de Tenancy — Design

**Data:** 2026-09-30
**Estado:** aprovado em 2026-09-30
**Âmbito:** fundação de multi-tenancy (runtime + gestão mínima de Tenants). Sem interface de Plataforma, sem parte comercial.

---

## 0. Contexto e objectivo

Hoje a aplicação é, na prática, de uma só escola. `Estabelecimento::current()` devolve "o primeiro estabelecimento activo da BD" e é chamado cerca de 60 vezes em 40 ficheiros de 9 módulos. Não existe nenhuma fronteira de isolamento.

O objectivo é introduzir o **Tenant** como fronteira de isolamento, antes de se acrescentarem mais funcionalidades, de modo que:

- duas escolas na mesma base de dados nunca vejam dados uma da outra;
- código que corra sem tenant resolvido falhe de forma explícita, em vez de devolver tudo;
- os módulos académicos quase não mudem;
- a gestão de Tenants possa, no futuro, sair para uma Plataforma MosiTec separada sem reescrever os módulos académicos.

### Premissas fechadas

| # | Decisão |
|---|---|
| P1 | Cada escola que contrata o MosiTec é um Tenant independente. Três escolas do mesmo dono são três Tenants. Não existe "Grupo Escolar". |
| P2 | Tenants nunca partilham dados. |
| P3 | Cada Tenant tem exactamente 1 Estabelecimento, garantido pela BD. |
| P4 | `tenant_id` é a fronteira de isolamento. `estabelecimento_id` mantém-se onde já existe; os dois coexistem sem plano de remoção. |
| P5 | Infra-estrutura desta fase: PostgreSQL partilhado com coluna `tenant_id`. "Dedicado" e "local" são a mesma aplicação instalada com um único tenant. |
| P6 | Não há dados de produção a preservar. A BD pode ser recriada. |

### Fora do âmbito

- Interface e autenticação da Plataforma (Super Admin). A gestão é feita por comando artisan.
- Planos, subscrições, licenciamento, facturação.
- Troca dinâmica de ligações à BD, provisioning de infra-estrutura.
- Row-Level Security do PostgreSQL (o desenho fica compatível; ver §7.5).
- Verificação de DNS e certificados para domínios personalizados.
- Exportação, reposição e purga de dados por tenant.
- Remoção da tabela `licencas`.

---

## 1. Modelo conceptual: Tenant vs Estabelecimento

```text
Tenant  (dono: MosiTec)            Estabelecimento  (dono: a escola)
─────────────────────────          ─────────────────────────────────
id, codigo, nome, estado           nome, NIF, alvará, código MINED,
domínios                           contactos, morada, logótipo,
                                   tipo, tipo de ensino, etapas
        1 ─────────────────────── 1
```

A distinção é **quem é dono do registo**:

- **Tenant** é a identidade do cliente no produto. É criado e gerido pela MosiTec. A escola nunca o edita.
- **Estabelecimento** é o perfil institucional da escola. É editado pela escola. A MosiTec não lhe mexe depois do provisioning.

O Estabelecimento **não é um nível de isolamento**. Todos os dados pertencem ao Tenant. O `estabelecimento_id` nas tabelas onde existe representa a relação explícita com o perfil institucional, não uma fronteira de segurança.

Pessoas, Utilizadores, Alunos, Professores, Funcionários e Encarregados vivem ao nível do Tenant. Consequência aceite: a mesma pessoa em duas escolas (mesmo do mesmo dono) são dois registos e duas contas.

---

## 2. Runtime de Tenancy (`Modules/Core/app/Tenancy/`)

Tudo aquilo de que os módulos académicos dependem vive aqui. Nada aqui conhece as tabelas `tenants` ou `domains`.

| Componente | Responsabilidade |
|---|---|
| `TenantAtual` | Objecto imutável com `id`, `codigo`, `nome`, `estado`. É a única representação do tenant que os módulos académicos conhecem. |
| `EstadoTenant` | Enum dos estados (§6). Vive aqui e não em `Modules/Tenant` porque faz parte de `TenantAtual` e é lido pelo middleware. |
| `NormalizadorHost` | Normaliza hosts (minúsculas, sem porta, sem ponto final). Usado pelo middleware e pelo módulo Tenant. |
| `TenantContext` | Guarda o tenant do pedido corrente (§4). |
| `PertenceAoTenant` (trait) | Aplica o isolamento aos models (§7). |
| `TenantScope` | Global scope usado pela trait. |
| `ResolvedorTenant` (contrato) | `resolver(Request): ?TenantAtual`. A implementação vive em `Modules/Tenant`. |
| `ResolverTenant` (middleware) | Chama o resolvedor, aplica as regras de estado e define o contexto (§5). |
| `VerificadorPresencaTenant` | Torna `exists` e `unique` cientes do tenant (§9). |
| `ProvisionaTenant` (contrato) | Implementado por cada módulo para semear os seus dados (§16). |
| `DadosProvisionamento` (DTO) | Dados que os provisionadores recebem. |
| `CacheTenant`, `CaminhoTenant` | Prefixos de cache e de ficheiros (§11, §12). |
| `ComTenant` (trait de Job) | Transporta o tenant para a fila (§13). |
| Excepções | `TenantNaoResolvido`, `TenantSuspenso`, `AlteracaoDeTenantProibida`. |
| `config/tenancy.php` | Modo de resolução, hosts centrais, subdomínios reservados, tabelas globais. |

**Regra de dependência:** `Core/Tenancy` não importa nada de `Modules/Tenant`. Os módulos académicos não importam nada de `Modules/Tenant`. Um teste de arquitectura verifica ambas.

---

## 3. Módulo Tenant (`Modules/Tenant/`)

Responsável pela **gestão**: identidade, domínios, ciclo de vida, provisioning.

### 3.1 Tabelas

**`tenants`**

| Coluna | Notas |
|---|---|
| `id` | |
| `codigo` | Único, imutável. Formato `MOSI-000042`. **Não deriva do `id`**: é atribuído por numeração própria, ou fornecido explicitamente (instalações dedicadas e locais recebem o código que já tinham). |
| `nome` | Nome do cliente para a MosiTec. Pode diferir do nome do Estabelecimento. |
| `estado`, `estado_descricao` | Enum inteiro `EstadoTenant` com a coluna legível irmã (convenção do projecto). |
| `suspenso_em`, `motivo_suspensao` | |
| `encerrado_em` | |
| timestamps | Sem soft delete: "Encerrado" é o estado terminal. |

Não há coluna `plano` nem `tipo_instalacao`. Entram quando existir o conceito que representam.

**`domains`**

| Coluna | Notas |
|---|---|
| `id`, `tenant_id` | |
| `dominio` | Único global. Guardado normalizado: minúsculas, sem porta, sem ponto final. |
| `tipo`, `tipo_descricao` | Subdomínio MosiTec ou domínio personalizado. |
| `is_principal` | Índice único parcial: no máximo um principal por tenant. |
| timestamps | |

O domínio identifica o tenant; não é a identidade dele. Um tenant pode ter vários.

### 3.2 Estrutura

```text
Modules/Tenant/
├── app/
│   ├── Models/        Tenant, Domain            (sem a trait PertenceAoTenant)
│   ├── Enums/         TipoDominio            (EstadoTenant vive em Core/Tenancy: faz parte de TenantAtual)
│   ├── DTO/           CriarTenantDTO, DominioDTO
│   ├── Actions/       CriarTenantAction, SuspenderTenantAction,
│   │                  ReactivarTenantAction, EncerrarTenantAction,
│   │                  AdicionarDominioAction, RemoverDominioAction
│   ├── Services/      TenantConsultaService, GeradorCodigoTenant,
│   │                  ResolvedorTenantPorDominio, ResolvedorTenantUnico
│   ├── Console/       CriarTenantCommand  (mosi:tenant:create)
│   └── Providers/     TenantServiceProvider (liga o ResolvedorTenant ao modo configurado)
└── database/migrations/
```

Nesta fase **não há** controllers, FormRequests, policies, permissões nem páginas. O comando artisan e uma futura interface chamam exactamente as mesmas Actions com os mesmos DTOs.

O módulo tem de constar em `modules_statuses.json`.

### 3.3 O que acontece na futura separação

A tabela `tenants` fica para sempre na BD da escola, porque as chaves estrangeiras precisam dela. O que sai para a Plataforma é a *gestão*: as Actions passam a ser invocadas por sincronização vinda da Plataforma, e o `ResolvedorTenant` pode ganhar outra implementação. Os módulos académicos não notam a diferença, porque só conhecem `TenantAtual` e `TenantContext`.

---

## 4. TenantContext

Registado com âmbito de pedido (`scoped`), não como singleton.

| Método | Comportamento |
|---|---|
| `atual(): TenantAtual` | Devolve o tenant corrente. **Lança `TenantNaoResolvido` se não houver.** |
| `id(): int` | Atalho para `atual()->id`. Mesma excepção. |
| `temTenant(): bool` | Para os poucos sítios que precisam de perguntar (ex.: partilha de props do Inertia em páginas centrais). |
| `definir(TenantAtual)` | Usado só pelo middleware `ResolverTenant`. |
| `limpar()` | Usado pelo middleware no fim do pedido. |
| `executarComo(TenantAtual, Closure)` | Executa o closure dentro do tenant indicado e **repõe o contexto anterior** no fim, mesmo com excepção. É o único caminho para ter contexto fora de um pedido HTTP. |
| `lembrar(chave, Closure)` | Memória por tenant e por pedido. É limpa quando o contexto muda. O `Core` não conhece o Estabelecimento: é `Estabelecimento::current()` que usa este método para guardar o seu resultado. |

### Não existe `semTenant()`

Foi considerada uma saída `semTenant(fn)` para correr código sem contexto. **Não é incluída nesta fundação.** Análise caso a caso:

| Situação | Precisa de `semTenant`? |
|---|---|
| Gerir `tenants` e `domains` | Não. Esses models não têm a trait. |
| Resolver o tenant pelo domínio | Não. Lê `domains`. |
| Provisioning | Não. Usa `executarComo` com o tenant acabado de criar. |
| Seeders de desenvolvimento, testes | Não. Usam `executarComo`. |
| Comandos de manutenção | Não. Iteram tenants com `executarComo`. |
| Jobs | Não. Reentram com `executarComo`. |
| Métricas agregadas entre tenants | Futuro; fora do âmbito. |

Sem nenhum caso de uso, não há porta lateral a vigiar. Se um dia for necessária, entra com desenho próprio: lista explícita de chamadores autorizados e teste de arquitectura.

Pela mesma razão, `withoutGlobalScopes()` e `withoutGlobalScope(TenantScope::class)` são proibidos em `Modules/` por teste de arquitectura.

---

## 5. Resolução do Tenant

### 5.1 Modos

Configurado em `config/tenancy.php` (`TENANCY_MODO`).

**Modo `dominio`** — instalação partilhada, cloud, desenvolvimento.

```text
Pedido
  → host normalizado (minúsculas, sem porta)
  → é host central?  → segue sem tenant (só rotas centrais, ex.: /up)
  → procura em domains
      não existe → 404 genérico
      existe     → tenant
          Encerrado → 404 genérico
          Suspenso  → resposta "conta suspensa" (403)
          Activo    → TenantContext definido → aplicação
```

**Modo `unico`** — instalação local ou dedicada.

Usa o único tenant da instalação e ignora o host, porque uma instalação local é acedida por IP ou por nome de rede. Se existirem zero ou mais de um tenant, a aplicação falha com erro explícito. As regras de estado aplicam-se da mesma forma.

### 5.2 Middleware

- `ResolverTenant` é **middleware global**, registado em `bootstrap/app.php`: corre em todos os pedidos (`web`, `api` e rotas fora dos grupos), **antes** de qualquer middleware de rota, logo antes de sessão e autenticação. Não é registado nos grupos porque, no grupo `api`, o Sanctum coloca `EnsureFrontendRequestsAreStateful` (que inicia a sessão e autentica) à frente de tudo o que o grupo registe.
- A única excepção são os caminhos de `tenancy.caminhos_sem_tenant` (a verificação de saúde `up`), que respondem em qualquer host.
- No fim do pedido, limpa o contexto.
- O 404 para host desconhecido é igual ao de tenant encerrado, para não revelar que domínios existem.

### 5.3 Cenários

| Cenário | Como funciona |
|---|---|
| Subdomínio | `colegioabc.mositec.ao` é uma linha em `domains`. |
| Domínio personalizado | Outra linha em `domains` para o mesmo tenant. Verificação de propriedade e certificados ficam fora do âmbito. |
| Desenvolvimento local | Modo `dominio` com `escola-a.localhost:8000` e `escola-b.localhost:8000`. Os navegadores resolvem `*.localhost` sem editar o ficheiro hosts. |
| Instalação local | Modo `unico`. |
| Cloud futura | Modo `dominio`. |

### 5.4 Validação de domínios

`AdicionarDominioAction` normaliza o valor, recusa subdomínios reservados (`www`, `api`, `admin`, `plataforma`, `mail`, `app`, configuráveis), recusa hosts centrais e garante unicidade.

Como o host vem do cabeçalho do pedido, os proxies de confiança têm de estar configurados em produção. A tabela `domains` funciona como lista de permissão: um host que não conste nunca resolve.

---

## 6. Ciclo de vida

```text
            suspender
  Activo ─────────────► Suspenso
     ▲                     │
     └─────────────────────┘
            reactivar
     │                     │
     └──────► Encerrado ◄──┘      (terminal)
```

| Estado | Domínios | Login | Dados | Jobs |
|---|---|---|---|---|
| **Activo** | Resolvem | Sim | Normais | Correm |
| **Suspenso** | Resolvem para página "conta suspensa"; a API responde 403 | Não | Intactos | São ignorados |
| **Encerrado** | 404 | Não | Intactos, retidos | São ignorados |

- "Provisioning" não é um estado: a criação é uma transacção única (§16). Um tenant ou existe completo e Activo, ou não existe.
- "Em teste", "pagamento em atraso" e semelhantes são situação comercial e pertencem à futura Plataforma, não a este enum.
- Reabrir um tenant encerrado e purgar dados são procedimentos manuais, fora do âmbito.
- Cada transição é uma Action que regista data e, na suspensão, o motivo.

---

## 7. Isolamento e fail-closed

### 7.1 A regra única

> **Toda a leitura ou escrita numa tabela tenant-scoped é filtrada pelo tenant corrente. Sem tenant corrente, falha com excepção.**

Esta regra é aplicada em três pontos, e só três:

| Caminho de acesso | Mecanismo |
|---|---|
| Eloquent (Repositories, Services, Actions, route model binding, relações) | Trait `PertenceAoTenant` |
| Regras de validação `exists` e `unique` | `VerificadorPresencaTenant` (§9) |
| `DB::table()` | Proibido em `Modules/`, salvo lista de excepções revista à mão (§14) |

### 7.2 Trait `PertenceAoTenant`

- **Leitura:** o global scope acrescenta `where tenant_id = <tenant corrente>`. Sem contexto, **lança `TenantNaoResolvido`**. Não devolve colecção vazia: um resultado vazio esconderia o erro.
- **Criação:** preenche `tenant_id` a partir do contexto. Se vier preenchido com outro valor, lança excepção.
- **Actualização, eliminação e restauro:** exigem contexto (sem ele, `TenantNaoResolvido`) e que o registo pertença ao tenant corrente; caso contrário, ou se `tenant_id` tiver sido alterado, lançam `AlteracaoDeTenantProibida`. Isto é necessário porque `save()` e `delete()` de uma instância não passam pelo global scope.
- `tenant_id` nunca está em `$fillable` e nunca faz parte de um DTO. Vem sempre do contexto, nunca do input.

### 7.3 Consequências nos caminhos existentes

- **Route model binding** (164 rotas): usa Eloquent, logo um ID de outro tenant dá 404.
- **Relações:** carregam models com a trait, logo ficam filtradas.
- **Pivots escritos com `attach`/`sync`:** `user_roles` e `encarregados_alunos`. A relação `belongsToMany` é declarada com `withPivotValue('tenant_id', …)`, que preenche a coluna na escrita e filtra na leitura. As restantes tabelas de associação têm Model próprio e usam a trait.

### 7.4 Rede de segurança na BD

- `tenant_id` é `NOT NULL` com chave estrangeira para `tenants` em todas as tabelas tenant-scoped, incluindo filhas e pivots.
- Esquecer o `tenant_id` numa escrita directa resulta em erro de BD, não em linha órfã.

### 7.5 Row-Level Security

Fica fora do âmbito. O desenho é compatível: todas as tabelas tenant-scoped têm `tenant_id` directo, que é o pré-requisito. Os testes correm em SQLite em memória, que não suporta RLS; adoptá-lo implicaria uma suite de testes em PostgreSQL.

### 7.6 Limite conhecido

A BD **não** impede, por si só, que uma linha do tenant A referencie por chave estrangeira simples uma linha do tenant B (por exemplo, uma turma a apontar para um ano lectivo de outro tenant). Essa protecção é dada pela validação (§9) e pelo scope. A excepção é o par `tenant_id` + `estabelecimento_id`, garantido pela BD (§8).

---

## 8. `tenant_id` + `estabelecimento_id`

### 8.1 Garantias na BD

1. `estabelecimentos.tenant_id` tem **índice único** → exactamente um estabelecimento por tenant.
2. `estabelecimentos` tem índice único em `(tenant_id, id)`.
3. Cada tabela com `estabelecimento_id` tem **chave estrangeira composta** `(tenant_id, estabelecimento_id)` → `estabelecimentos (tenant_id, id)`.

Com isto, uma linha do tenant 5 a apontar para o estabelecimento do tenant 7 é rejeitada pela BD. Não é necessário código de aplicação para manter a consistência.

### 8.2 Tabelas com as duas colunas

`cursos`, `disciplinas`, `salas`, `turnos`, `niveis_academicos`, `planos_curriculares`, `ano_lectivos`, `alunos`, `estabelecimento_etapas_ensino`.

Os índices únicos existentes `(estabelecimento_id, codigo)` e `(estabelecimento_id, nome)` **ficam como estão**. Com 1:1, são equivalentes a unicidade por tenant.

As queries e regras de validação que hoje filtram por `Estabelecimento::current()->id` continuam válidas e não precisam de ser alteradas.

### 8.3 Regra para tabelas novas

- `tenant_id` é **obrigatório** em toda a entidade tenant-scoped.
- `estabelecimento_id` é usado **quando a relação explícita com o Estabelecimento fizer sentido** para a entidade. Não é acrescentado mecanicamente, nem é proibido.
- Quando for usado, leva a chave estrangeira composta de §8.1.

### 8.4 `Estabelecimento::current()`

Mantém o nome e a assinatura como fachada.

| | Antes | Depois |
|---|---|---|
| Fonte | `where('is_active', true)->first()` | O estabelecimento do tenant corrente (leitura com scope), guardado com `TenantContext::lembrar()` |
| Custo | Uma query por chamada | Uma query por pedido |
| Sem contexto | Devolve o primeiro da BD | Lança `TenantNaoResolvido` |
| Tipo de retorno | `?self` | `self` (o provisioning garante que existe) |

- Serve para obter o perfil institucional. Não é mecanismo de isolamento.
- `is_active` deixa de seleccionar o estabelecimento. A coluna mantém-se, sem função de selecção.
- O ramo `current() ?? new Estabelecimento(['is_active' => true])` em `AtualizarDadosEstabelecimentoAction` é removido: o estabelecimento nasce no provisioning.
- Os cerca de 40 ficheiros que chamam `current()` não são alterados, excepto onde tratavam o retorno nulo.

### 8.5 Configuração inicial (`configurado_em`)

A ordem de existência e a ordem de configuração são diferentes:

```text
Provisioning (transacção única)
    Tenant → Estabelecimento mínimo (só o nome) → perfis → Administrador

Primeiro acesso
    Administrador → completa os dados institucionais do Estabelecimento
```

- `estabelecimentos.configurado_em` (data, nula por omissão) indica que a configuração inicial foi concluída.
- O provisioning cria o estabelecimento com `configurado_em` nulo. A MosiTec só escreve o nome inicial.
- `AtualizarDadosEstabelecimentoAction` preenche `configurado_em` na primeira gravação bem-sucedida dos dados institucionais. Depois disso, nunca volta a nulo.
- Enquanto `configurado_em` for nulo, um middleware a seguir à autenticação encaminha para o ecrã de configuração do estabelecimento os utilizadores **com permissão para o editar**. As rotas desse ecrã e a de terminar sessão ficam de fora. Utilizadores sem essa permissão não são encaminhados.
- Não existe criação tardia: `Estabelecimento::current()` nunca devolve nulo dentro de um tenant, esteja o perfil configurado ou não.
- Os campos institucionais que hoje são obrigatórios na BD passam a aceitar nulo, para permitir o estabelecimento mínimo. A obrigatoriedade mantém-se na validação do formulário de configuração.

---

## 9. Validação tenant-aware

### 9.1 O problema

Existem cerca de 70 regras escritas como `exists:turmas,id` ou `unique:users,email`. O Laravel executa-as através do *presence verifier*, que usa o query builder directamente na tabela. **Não passa pelo Eloquent, logo ignora o global scope.** Sem tratamento, um utilizador do tenant A poderia submeter o ID de uma turma do tenant B e a validação aceitaria.

### 9.2 Alternativas consideradas

| | Descrição | Comportamento em caso de esquecimento |
|---|---|---|
| **A. Regras explícitas** | Reescrever as 70 regras como `Rule::exists(...)->where('tenant_id', ...)` | **Falha aberta.** Uma regra nova escrita da forma habitual fica sem filtro, em silêncio. |
| **B. Verificador central** (escolhida) | Um verificador que aplica o filtro a todas as tabelas tenant-scoped | **Falha fechada.** Uma regra nova fica protegida por omissão. |

A alternativa A é mais visível em cada FormRequest, mas depende de ninguém se esquecer, e isolamento não pode depender disso.

### 9.3 Como se integra no Laravel

O validador obtém o verificador a partir do container, na chave `validation.presence`. O `CoreServiceProvider` substitui essa ligação por `VerificadorPresencaTenant`, que **estende** o `DatabasePresenceVerifier` do Laravel e redefine **um único método**: `table($tabela)`, o ponto por onde passam todas as consultas de `exists` e `unique`.

```text
table($tabela):
    se $tabela está em config('tenancy.tabelas_globais')
        → consulta normal, sem filtro
    caso contrário
        → consulta com where tenant_id = TenantContext::id()
          (lança TenantNaoResolvido se não houver contexto)
```

É uma classe pequena, num só sítio, sem alterar a sintaxe das regras.

### 9.4 Como distingue cada caso

| Caso | Comportamento |
|---|---|
| **Tabela global** (`modulos`, `acoes`) | Consta em `tabelas_globais`. Sem filtro. `exists:modulos,id` funciona como hoje. |
| **Tabela tenant-scoped** | Qualquer tabela que não esteja na lista. Filtro aplicado. **Uma tabela desconhecida é tratada como tenant-scoped**: o padrão é o seguro. |
| **`exists`** | Significa "existe no meu tenant". |
| **`unique`** | Significa "é único no meu tenant". `ignore()` e condições adicionais (`where('estabelecimento_id', …)`) continuam a funcionar, porque são aplicadas sobre a mesma consulta. |
| **ID de outro tenant** | A contagem dá zero. A mensagem é a mesma de um ID inexistente, para não revelar que o registo existe noutra escola. |
| **Regra com classe de Model** (`Rule::exists(Turma::class)`) | O Laravel converte a classe no nome da tabela antes de chamar o verificador. Mesmo comportamento. |

### 9.5 Como se mantém compreensível

- O modelo mental é o mesmo do Eloquent: *ler uma tabela tenant-scoped filtra sempre pelo tenant*. O verificador só estende essa regra ao caminho da validação.
- A lista `tabelas_globais` é explícita e curta, num ficheiro de configuração.
- **Teste de esquema:** percorre todas as tabelas da BD e exige que cada uma ou tenha a coluna `tenant_id`, ou conste em `tabelas_globais`, ou conste na lista de tabelas de infra-estrutura. Nunca as duas coisas, nunca nenhuma. Uma tabela nova mal classificada faz o teste falhar.
- Nenhum FormRequest existente é alterado por causa do isolamento.

### 9.5.1 Lista de transição

Durante a implementação, as tabelas que ainda não receberam `tenant_id` constam em `tenancy.tabelas_por_converter` e não são filtradas (filtrá-las daria erro de coluna inexistente). Cada etapa retira dessa lista as tabelas que converte. O teste de esquema trata-a como quarta classe válida; a última etapa exige que esteja vazia e remove-a.

### 9.6 Relações e IDs encadeados

Depois de validado, o ID é carregado por Eloquent (com scope). Um ID de outro tenant é barrado duas vezes: na validação e no carregamento.

---

## 10. Autenticação, sessão e Sanctum

### 10.1 Utilizadores

- `users` ganha `tenant_id` e a trait.
- Unicidade passa a `(tenant_id, email)` e `(tenant_id, numero_matricula)`. O mesmo email pode existir em tenants diferentes.
- `Auth::attempt` (web e API) usa o provider Eloquent, que passa a filtrar pelo tenant do domínio. Credenciais válidas no tenant A não autenticam no domínio do tenant B.

### 10.2 Sessão

- A tabela `sessions` é de infra-estrutura e não leva `tenant_id`.
- No login, o `tenant_id` é gravado na sessão. Um middleware a seguir à sessão compara-o com o tenant do pedido; se diferir, invalida a sessão.
- Além disso, o provider só encontra o utilizador dentro do tenant corrente, pelo que uma sessão reenviada para outro domínio resulta em utilizador não encontrado.
- O cookie de sessão é por host (`SESSION_DOMAIN` nulo). Não deve ser configurado para o domínio pai.

### 10.3 Sanctum

- `personal_access_tokens` ganha `tenant_id`.
- O Sanctum passa a usar um model de token próprio com a trait (`Sanctum::usePersonalAccessTokenModel`). Um token emitido no tenant A não é encontrado no domínio do tenant B.
- As rotas `api` passam pelo `ResolverTenant`.

### 10.4 Recuperação de palavra-passe

`password_reset_tokens` tem hoje o email como chave primária. Com o mesmo email em dois tenants, colidiriam.

- A chave passa a `(tenant_id, email)`.
- O repositório de tokens do Laravel é estendido para filtrar e gravar o `tenant_id`.
- O link de recuperação é gerado com o domínio do tenant.

### 10.5 Limitador de login

`LimitadorLogin` usa hoje o hash do identificador. Com o mesmo email em dois tenants, tentativas falhadas numa escola bloqueariam a conta na outra. As três chaves (`conta-ip`, `conta`, `ip`) passam a incluir o tenant.

Existem hoje três definições de limitador de login (em `FortifyServiceProvider`, `AppServiceProvider` e `LimitadorLogin`). O plano de implementação deve identificar qual está em uso e garantir que é essa que fica ciente do tenant.

### 10.6 Ponto em aberto

`Features::registration()` está activo em `config/fortify.php`. Registo público num domínio de escola criaria utilizadores nesse tenant sem controlo. É desactivado (ver §22).

---

## 11. Cache

- `CacheTenant` (em `Core/Tenancy`) é um invólucro fino sobre `Cache` que prefixa todas as chaves com `tenant:{id}:`. Sem contexto, lança excepção.
- O código dos módulos usa `CacheTenant`. O uso directo da fachada `Cache` em `Modules/` é proibido por teste de arquitectura, com excepção de `Core/Tenancy`.
- **Único uso actual:** `PermissaoCache`. Passa a usar `CacheTenant`, o que torna a chave `permissoes:epoch` própria de cada tenant: uma escola deixa de invalidar a cache de permissões das outras.
- `PermissionResolver` é hoje singleton com memória própria. Passa a `scoped`, para não reter dados entre tenants num worker de fila.
- A resolução domínio → tenant **não** é guardada em cache nesta fase: é uma consulta indexada por pedido.

---

## 12. Ficheiros

`CaminhoTenant` devolve o prefixo `tenants/{id}/`. Todos os ficheiros de um tenant ficam debaixo desse prefixo, em qualquer disco.

| Ficheiro | Hoje | Depois |
|---|---|---|
| Logótipo | disco `public`, `estabelecimento/logotipos` | disco `public`, `tenants/{id}/estabelecimento/logotipos` |
| Foto de aluno | disco `public`, `alunos/fotos` | **disco privado**, `tenants/{id}/alunos/fotos`, servida por rota autenticada |
| Documento de pessoa | disco `documentos`, `documentos-pessoas/{pessoa}` | disco `documentos`, `tenants/{id}/documentos-pessoas/{pessoa}` |

A mudança das fotos de alunos para disco privado corrige um problema que existe independentemente do tenancy: hoje são acessíveis por URL sem autenticação. O logótipo continua público porque aparece em páginas sem sessão.

O descarregamento é sempre feito a partir do model (com scope), nunca a partir de um caminho recebido do cliente.

---

## 13. Jobs e Commands

Hoje não existe nenhum Job nem Command. Fixa-se a regra.

### Jobs

- Um job que toque em dados tenant-scoped usa a trait `ComTenant`.
- Ao ser despachado, captura o `id` do tenant corrente. Sem contexto, o despacho falha.
- Ao ser executado, reentra com `TenantContext::executarComo`. Se o tenant não estiver Activo, o job é descartado e o facto registado.
- Um job sem a trait que toque em models com scope falha com `TenantNaoResolvido`. É o comportamento pretendido.

### Commands

- Na linha de comandos não há tenant por omissão.
- Comandos que operam sobre dados de escola recebem `--tenant=CODIGO` ou `--todos`, e executam cada tenant com `executarComo`.
- Comandos de gestão (`mosi:tenant:create`) operam sobre `tenants` e `domains`, que não são tenant-scoped.
- `tinker` sem contexto não consegue ler dados tenant-scoped. Para inspeccionar, usa-se `executarComo`.

---

## 14. Sequências

### Estado actual

`GeradorMatriculaService` e `GeradorNumeroRegistoMatriculaService` são idênticos: `DB::table(...)->upsert` com `ano` como chave única, seguido de bloqueio e incremento. A numeração é global: a segunda escola continuaria a contagem da primeira.

### Desenho

- `matricula_sequencias` e `matricula_registo_sequencias` passam a ter chave única `(tenant_id, ano)`.
- O `upsert` passa a incluir `tenant_id` e a usar `(tenant_id, ano)` como alvo de conflito.
- A leitura com bloqueio é feita pelo model, que fica com a trait.
- Os dois geradores são consolidados num único gerador de sequências por tenant no `Core`, parametrizado pela tabela. Os dois serviços actuais passam a delegar nele.
- Esse gerador é a **única excepção** à proibição de `DB::table()` em `Modules/`. Consta numa lista explícita no teste de arquitectura.
- Unicidade dos números gerados: `users.numero_matricula`, `alunos.numero_matricula` e `matriculas.numero_registo_matricula` passam a únicos por tenant.

Como `tenant_id` é `NOT NULL`, um `upsert` que o esqueça falha com erro de BD em vez de criar uma sequência partilhada.

---

## 15. Permissões

| Tabela | Classificação |
|---|---|
| `modulos`, `acoes` | **Globais.** Catálogo do produto, igual para todos os tenants. |
| `roles`, `role_permissoes`, `user_roles`, `user_permissoes` | **Tenant-scoped.** |

- Os perfis de sistema (Administrador da Escola, Funcionário, Professor, Aluno, Encarregado) são **criados por tenant** no provisioning, com as suas permissões. Não existem perfis globais com `tenant_id` nulo.
- Perfis personalizados são naturalmente do tenant.
- As procuras `Role::where('nome', …)` continuam a funcionar: o scope devolve o perfil do tenant corrente.
- Não há permissões de gestão de Tenants no contexto da escola. Nenhum perfil da escola, incluindo o administrador, consegue ver ou alterar `tenants` ou `domains`.
- `Modulo::Licenca` deixa de ser atribuído a perfis da escola no provisioning (§18).

---

## 16. Provisioning modular

### 16.1 Problema

Criar um tenant exige criar o estabelecimento, os perfis e permissões, os tipos de documento e o administrador. Se `CriarTenantAction` conhecesse todos esses módulos, `Modules/Tenant` dependeria de toda a aplicação e deixaria de ser extraível.

### 16.2 Contrato

Definido em `Core/Tenancy`:

```text
ProvisionaTenant
    ordem(): int
    provisionar(TenantAtual $tenant, DadosProvisionamento $dados): void
```

Cada módulo regista o seu provisionador no container com uma etiqueta comum. `CriarTenantAction` obtém todos os etiquetados, ordena por `ordem()` e executa-os. Não conhece nenhum deles pelo nome.

`DadosProvisionamento` transporta: nome do estabelecimento, nome e email do administrador inicial.

### 16.3 Provisionadores

| Ordem | Módulo | O que cria | Origem da lógica |
|---|---|---|---|
| 10 | Estabelecimento | O estabelecimento mínimo do tenant: só o nome, com `configurado_em` nulo (§8.5) | — |
| 20 | Permissao | Perfis de sistema e respectivas permissões | `RoleSeeder`, `RolePermissaoSeeder` |
| 30 | Usuario | Tipos de documento por omissão | `TipoDocumentoSeeder` |
| 40 | Autenticacao | Administrador inicial, com perfil de Administrador da Escola | `AdminUserSeeder` |

`ModuloSeeder` e `AcaoSeeder` continuam a ser seeders globais, executados uma vez por instalação.

Os seeders de dados de demonstração (`CursoSeeder`, `DisciplinaSeeder`, `SalaSeeder`, `TurnoSeeder`, `HorarioSeeder`) não são provisioning. Passam a correr dentro de `executarComo`, apenas em desenvolvimento.

### 16.4 Fluxo de `CriarTenantAction`

```text
transacção única:
    1. gerar ou aceitar o codigo
    2. criar Tenant (Activo)
    3. criar Domain principal (validado)
    4. executarComo(tenant):
           provisionadores, por ordem
se algo falhar → nada é criado
```

- O administrador inicial **não recebe palavra-passe digitada por quem cria o tenant**. É criado com palavra-passe aleatória não comunicada e recebe um link de definição de palavra-passe (o mecanismo de recuperação de §10.4).
- O domínio personalizado não faz parte deste fluxo. É acrescentado depois com `AdicionarDominioAction`.

### 16.5 Comando

`php artisan mosi:tenant:create` recolhe os dados (por opções ou perguntas), constrói o `CriarTenantDTO` e chama `CriarTenantAction`. Não contém lógica. Aceita `--codigo=` para instalações que já têm código atribuído.

---

## 17. Impacto nos módulos existentes

### 17.1 Classificação de todas as tabelas

| Classe | Tabelas |
|---|---|
| **Globais** (`tabelas_globais`) | `tenants`, `domains`, `modulos`, `acoes`, `licencas` (legado) |
| **Infra-estrutura** | `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `sessions`, `migrations` |
| **Tenant, com `estabelecimento_id`** | `cursos`, `disciplinas`, `salas`, `turnos`, `niveis_academicos`, `planos_curriculares`, `ano_lectivos`, `alunos`, `estabelecimento_etapas_ensino` |
| **Tenant, só `tenant_id`** | `estabelecimentos`, `users`, `dados_pessoas`, `documentos_pessoas`, `tipos_documentos`, `encarregados_alunos`, `roles`, `role_permissoes`, `user_roles`, `user_permissoes`, `personal_access_tokens`, `password_reset_tokens`, `periodos`, `eventos_calendario`, `horarios`, `turno_horarios`, `turmas`, `turma_salas`, `plano_curricular_disciplinas`, `plano_curricular_anos_lectivos`, `plano_curricular_disciplina_periodos`, `aluno_enquadramentos_academicos`, `matriculas`, `matricula_historicos`, `inscricoes_disciplinas`, `matricula_sequencias`, `matricula_registo_sequencias` |

### 17.2 Índices únicos que mudam

| Hoje (global) | Passa a |
|---|---|
| `users.email` | `(tenant_id, email)` |
| `users.numero_matricula` | `(tenant_id, numero_matricula)` |
| `alunos.numero_matricula` | `(tenant_id, numero_matricula)` |
| `dados_pessoas.numero_identificacao` | `(tenant_id, numero_identificacao)` |
| `matriculas.numero_registo_matricula` | `(tenant_id, numero_registo_matricula)` |
| `matricula_sequencias.ano` | `(tenant_id, ano)` |
| `matricula_registo_sequencias.ano` | `(tenant_id, ano)` |
| `tipos_documentos.slug` | `(tenant_id, slug)` |
| `password_reset_tokens.email` (chave primária) | `(tenant_id, email)` |

Ficam como estão: todos os índices compostos com `estabelecimento_id` ou com o ID de um pai (`(ano_lectivo_id, codigo)`, `(turno_id, ordem)`, …), `users.dados_pessoa_id`, `alunos.dados_pessoa_id`, `personal_access_tokens.token`, `jobs.uuid`, `licencas.chave_licenca`.

### 17.3 Por módulo

| Módulo | Esquema | Código |
|---|---|---|
| **Core** | `horarios` | Todo o runtime de tenancy; gerador de sequências; verificador de presença |
| **Tenant** (novo) | `tenants`, `domains` | Todo o módulo |
| **Estabelecimento** | `tenant_id` único, índice `(tenant_id, id)`, etapas | `current()`; Action de actualização; caminho do logótipo; provisionador |
| **Usuario** | `users`, `dados_pessoas`, `documentos_pessoas`, `tipos_documentos`, `encarregados_alunos`, `matricula_sequencias`, tokens | Traits; relações pivot; gerador de matrícula; caminho dos documentos; provisionador |
| **Autenticacao** | `password_reset_tokens` | Limitador; sessão; repositório de tokens; provisionador do administrador |
| **Permissao** | 4 tabelas | Traits; `PermissaoCache`; `PermissionResolver` com âmbito de pedido; provisionador |
| **AnoLectivo** | `ano_lectivos`, `periodos`, `eventos_calendario` | Traits |
| **Curso, Disciplina, Infraestrutura** | 1 tabela cada | Traits |
| **Turma** | `turmas`, `turnos`, `niveis_academicos`, `turno_horarios`, `turma_salas` | Traits |
| **PlanoCurricular** | 4 tabelas | Traits |
| **Aluno** | `alunos`, `aluno_enquadramentos_academicos` | Traits; fotos em disco privado com rota autenticada |
| **Matricula** | `matriculas`, `matricula_historicos`, `inscricoes_disciplinas`, sequência | Traits; gerador de número de registo |

Controllers, FormRequests, DTOs, Repositories e Services dos módulos académicos **não mudam de assinatura**. Nenhum recebe `tenant_id` como parâmetro.

### 17.4 Frontend

- Uma página "conta suspensa".
- O ecrã de configuração do estabelecimento já existe; passa a ser o destino do encaminhamento enquanto `configurado_em` for nulo (§8.5).
- As fotos de alunos passam a ser obtidas por rota autenticada (§12).

O resto do frontend não muda.

---

## 18. Licenças: legado e futura Plataforma

### Estado actual

A tabela `licencas` liga-se a `user_id`, o model `Licenca` está vazio e nenhum código a lê. Existe apenas a migration, o model e a entrada `Modulo::Licenca` no catálogo de permissões.

### Nesta fundação

- A tabela **não é removida nem alterada**.
- É classificada como global/legado, para não receber `tenant_id` nem entrar no isolamento.
- O provisioning não atribui `Modulo::Licenca` a nenhum perfil da escola.
- A remoção ou redesenho é decidida numa tarefa própria.

### Separação futura (só documentada)

| | Onde vive | Quem escreve | Quem lê |
|---|---|---|---|
| **Licença / subscrição comercial**: plano, preço, período, facturação, cliente pagador | Plataforma MosiTec | Plataforma | Plataforma |
| **Direitos (entitlements)**: módulos activos, limites, validade | Registo local do tenant na aplicação escolar | Plataforma (por sincronização, ou por ficheiro de licença assinado em instalações locais) | Aplicação escolar, só leitura |

Princípios a respeitar quando isto for desenhado:

- A aplicação escolar nunca lê planos nem subscrições; só consulta direitos.
- A Plataforma nunca lê tabelas académicas; se precisar de contagens para facturação, a aplicação escolar publica contadores agregados.
- Um cliente com várias escolas é uma entidade da Plataforma (quem paga), ligada a vários tenants, sem qualquer partilha de dados entre eles.
- Activar módulos por plano não pode usar `modules_statuses.json`, que vale para a instalação inteira; terá de ser verificação de direitos em rotas e menus.

---

## 19. Testes de isolamento

### 19.1 Base de testes

- `Tests\TestCase` passa a criar um tenant por omissão (com estabelecimento) e a dirigir os pedidos HTTP para o domínio dele.
- Um auxiliar cria um segundo tenant e permite fazer pedidos no domínio dele.
- Os testes existentes (cerca de 190) devem continuar a passar sem alterar as asserções; só muda a preparação.
- Os testes correm em SQLite em memória. As chaves estrangeiras compostas e os índices únicos parciais são suportados.

### 19.2 Matriz de isolamento

Para cada módulo com dados de tenant, com tenant A e tenant B populados:

| Via | O que se prova |
|---|---|
| Listagem | A só vê registos de A. |
| ID directo / route model binding | Pedir no domínio de A um ID de B dá 404. |
| `exists` | Submeter em A um ID de B falha a validação. |
| `unique` | O mesmo código, email ou número pode existir em A e em B; duplicado dentro de A falha. |
| Repository / Service | Chamados no contexto de A, não devolvem dados de B. |
| Action | Criar no contexto de A grava `tenant_id` de A; alterar `tenant_id` lança excepção. |
| Sem contexto | Qualquer leitura ou escrita lança `TenantNaoResolvido`. |
| Sequências | A e B começam cada um em `0001` no mesmo ano. |
| Cache | Invalidar permissões em A não afecta B. |
| Ficheiros | Os caminhos de A começam por `tenants/{A}/`; o descarregamento de um ficheiro de B a partir de A dá 404. |
| Jobs | Um job despachado em A executa no contexto de A; sem contexto, o despacho falha. |
| Commands | Com `--tenant=A`, só toca em A. |
| Autenticação | Credenciais de A não entram no domínio de B; sessão e token de A são rejeitados em B; o limitador de A não bloqueia B. |
| Consistência | Inserir `tenant_id` de A com `estabelecimento_id` de B é rejeitado pela BD; segundo estabelecimento no mesmo tenant é rejeitado pela BD. |

### 19.3 Testes de arquitectura

Escritos em PHPUnit, por análise de ficheiros e de esquema:

1. Toda a tabela tem `tenant_id`, ou está em `tabelas_globais`, ou é de infra-estrutura. Exactamente uma das três.
2. Todo o model cuja tabela tem `tenant_id` usa `PertenceAoTenant`.
3. Nenhum ficheiro fora de `Modules/Tenant` importa `Modules\Tenant\…`.
4. `Core/Tenancy` não importa `Modules\Tenant\…`.
5. `withoutGlobalScopes` e `withoutGlobalScope(TenantScope…)` não aparecem em `Modules/`.
6. `DB::table(` não aparece em `Modules/`, salvo na lista explícita de excepções.
7. A fachada `Cache` não é usada em `Modules/` fora de `Core/Tenancy`.
8. Toda a tabela com `estabelecimento_id` tem a chave estrangeira composta.

### 19.4 Resolução e ciclo de vida

- Host desconhecido → 404. Host central → sem tenant.
- Tenant suspenso → página de suspensão (web) e 403 (API). Tenant encerrado → 404.
- Modo `unico` com zero ou dois tenants → erro explícito.
- `CriarTenantAction` com falha num provisionador → nada fica criado.
- Subdomínio reservado e domínio duplicado → recusados.
- Tenant acabado de criar: `Estabelecimento::current()` devolve o estabelecimento mínimo com `configurado_em` nulo; o administrador é encaminhado para a configuração; após gravar, `configurado_em` fica preenchido e o encaminhamento cessa; um utilizador sem permissão de edição não é encaminhado.

---

## 20. Ordem de implementação

Como não há dados a preservar, **as migrations originais são editadas** para incluir `tenant_id` desde a criação, e a BD é recriada. Não há migrations de preenchimento.

### 20.1 Ordem das migrations

As migrations de todos os módulos são ordenadas pelo nome do ficheiro. As dependências são:

```text
1. tenants, domains                  data anterior a 2026_03_31 (antes de dados_pessoas e users)
2. dados_pessoas, users, roles, …    já existentes; passam a referenciar tenants
3. estabelecimentos                  (2026_08_30) com tenant_id único e índice (tenant_id, id)
4. tabelas com estabelecimento_id    todas posteriores a estabelecimentos; chave composta
5. restantes tabelas                 pela ordem actual
```

`cache` e `jobs` (`0001_01_01`) não têm dependências e ficam onde estão.

### 20.2 Etapas

Cada etapa termina com a suite completa a passar.

| # | Etapa | Resultado |
|---|---|---|
| 1 | **Runtime no Core**: `TenantAtual`, `TenantContext`, trait, scope, excepções, configuração, testes de arquitectura 3 a 7 | Mecanismo existe e está testado isoladamente. Nenhuma tabela tocada. |
| 2 | **Módulo Tenant (base)**: tabelas, models, enums, resolvedores, middleware nos grupos `web` e `api`, os dois modos, base de testes com tenant por omissão | A aplicação corre dentro de um tenant. Ainda sem isolamento de dados. |
| 3 | **Verificador de presença** e teste de esquema (arquitectura 1 e 2) | A rede de segurança existe antes de as tabelas serem convertidas. |
| 4 | **Estabelecimento**: `tenant_id` único, `current()` pelo contexto, `configurado_em` e encaminhamento para a configuração inicial | Perfil institucional ligado ao tenant. |
| 5 | **Usuario, Permissao, Autenticacao**: tabelas, únicos, pivots, login, sessão, Sanctum, recuperação de palavra-passe, limitador, cache de permissões | Identidade e permissões isoladas. |
| 6 | **Módulos académicos**, por ordem de dependência: AnoLectivo e horários → Curso, Disciplina, Infraestrutura → Turma → PlanoCurricular → Aluno → Matricula. Inclui chaves compostas (arquitectura 8) | Cada módulo com a sua linha da matriz de isolamento. |
| 7 | **Sequências**: gerador único no Core, únicos por tenant | Numeração por escola. |
| 8 | **Ficheiros**: prefixo por tenant, fotos em disco privado | Ficheiros isolados. |
| 9 | **Provisioning**: contrato, provisionadores, `CriarTenantAction`, comando, seeders de desenvolvimento com dois tenants | Cria-se uma escola nova de ponta a ponta. |
| 10 | **Ciclo de vida**: suspender, reactivar, encerrar, página de suspensão | Estados funcionais. |
| 11 | **Jobs e Commands**: trait `ComTenant`, convenção de `--tenant`, testes com job e comando de exemplo | Regra fixada para trabalho futuro. |
| 12 | **Bateria completa** de isolamento com dois tenants, em todas as vias de §19.2 | Fundação concluída. |

---

## 21. Riscos conhecidos

| Risco | Tratamento |
|---|---|
| Tabela mal classificada em `tabelas_globais` expõe dados na validação | Teste de esquema (§19.3, ponto 1) |
| Integridade entre tenants em chaves estrangeiras simples não é garantida pela BD | Validação e scope; RLS numa fase posterior |
| Restaurar os dados de uma só escola numa BD partilhada | Fora do âmbito; precisa de desenho próprio antes de haver clientes em produção |
| Instalações dedicadas e locais a divergir de versão | Fora do âmbito; política de actualizações a definir |
| Domínios personalizados: propriedade e certificados | Fora do âmbito; até lá, só a MosiTec acrescenta domínios |
| "Regras próprias" de cada escola implementadas como condições por tenant no código | Convenção: diferenças entre escolas são sempre dados de configuração |
| Suite de testes em SQLite, produção em PostgreSQL | Já é a situação actual; os índices parciais e as chaves compostas usam sintaxe comum aos dois |

---

## 22. Pontos assumidos na aprovação

O spec foi aprovado "conforme definido", sem resposta individual a estes quatro pontos. Ficam assumidas as propostas do próprio spec; qualquer uma pode ser revertida antes da etapa que a implementa.

| # | Ponto | Assumido | Etapa |
|---|---|---|---|
| 1 | `Features::registration()` do Fortify (§10.6) | Desactivado | 5 |
| 2 | Fotos de alunos em disco privado (§12) | Incluído nesta fundação | 8 |
| 3 | `tipos_documentos` | Por tenant | 5 |
| 4 | Formato do código do tenant | `MOSI-` seguido de seis dígitos | 2 |
