# Guia de tenancy para a equipa

Resumo prático. O desenho e as razões estão em `docs/superpowers/specs/2026-09-30-fundacao-tenancy-design.md` (referido como "spec"). Modelo: 1 tenant = 1 escola = 1 estabelecimento, BD partilhada.

## Como funciona em runtime (spec §2, §4, §7)

- `TenantContext` guarda o tenant corrente (`TenantAtual`). O middleware `ResolverTenant` define-o em cada pedido web/API (pelo host, ou o único tenant em modo `unico`) e limpa-o no fim.
- Fora de um pedido (comandos, jobs, seeders, testes) o único caminho é `TenantContext::executarComo($tenant, fn)`. Nunca chame `definir()`/`limpar()` fora do runtime (há teste de arquitectura).
- Um model de tenant usa a trait `PertenceAoTenant`: o `TenantScope` filtra todas as leituras, `creating` grava o `tenant_id` do contexto e `updating`/`deleting` recusam outro tenant (`AlteracaoDeTenantProibida`).
- Fail-closed: sem contexto, ler ou escrever um model de tenant lança `TenantNaoResolvido`. Não existe `semTenant()`.
- Proibido em `Modules/`: `withoutGlobalScope(s)`, `DB::table(...)`, a fachada `Cache` (use `CacheTenant`, chaves `tenant:{id}:...`), importar `Modules\Tenant\...`.
- `tenant_id` nunca é preenchível em massa.

## Checklist: nova tabela

1. Migration com `tenant_id` NOT NULL e chave estrangeira para `tenants` (editar a migration de origem; sem migrations de preenchimento).
2. Se pertence a um estabelecimento: `estabelecimento_id` e chave estrangeira **composta** `(tenant_id, estabelecimento_id)` → `estabelecimentos (tenant_id, id)` (spec §8).
3. Únicos de negócio sempre `(tenant_id, coluna)`, nunca a coluna sozinha (spec §17.2).
4. Model com `PertenceAoTenant`.
5. Classificação: tem `tenant_id`, ou entra em `tenancy.tabelas_globais` (catálogo do produto), ou em `tabelas_infraestrutura` (framework). Exactamente uma.
6. Teste de tenancy do módulo (listagem, 404 por ID, exists, unique, sem contexto) e referência na linha certa de `tests/Feature/Tenancy/MatrizIsolamentoTest.php`.

`TenancyEsquemaTest` e `MatrizIsolamentoTest` falham se faltar a trait, a classificação ou a chave composta.

## Validação (spec §9)

`exists:` e `unique:` são filtrados por tenant automaticamente (`VerificadorPresencaTenant`) para tabelas de tenant; um ID de outro tenant falha como um ID inexistente. Não acrescente `where tenant_id` à mão. Para tabelas globais não há filtro.

## Jobs (spec §13)

- Todo o job/listener/mailable/notification em fila usa `ComTenant`: o `tenant_id` vai no payload e o job corre dentro do tenant. Sem contexto, o despacho falha.
- `ShouldBeUnique` exige também `UnicoPorTenant` (opcional: método `identificadorUnico()`); há teste de arquitectura.
- Não suportado (falha alto): closures em fila, `dispatchAfterResponse()`, driver de fila `background`. Cadeias e lotes: cada elo precisa da trait.
- Tenant suspenso/encerrado/inexistente: o job é descartado com aviso no log. O worker (`queue:work`) e o `schedule:work` são centrais, sem `--tenant`.

## Comandos

Comandos de dados de escola usam `ParaTodosOsTenants` (`--tenant=CODIGO` ou `--todos`) ou `EscolheUmTenant` (só `--tenant`). Exactamente uma opção; `--todos` salta tenants não activos; uma falha não impede os restantes. Agendar sempre com `--todos` (ex.: `mosi:tenant:tokens:prune --todos`, em `routes/console.php`). Exemplos: `mosi:tenant:sync-perfis`, `mosi:tenant:admin:reset`.

## Ficheiros (spec §12)

Toda a gravação usa `CaminhoTenant::para('dir/sub')` como caminho (`tenants/{id}/dir/sub`); `CaminhoTenant::garantir()` valida leituras e remoções. Ficheiros sensíveis vão para os discos privados (`privado`, `documentos`) e servem-se por rota autenticada, com o model a vir do scope. O teste de arquitectura recusa gravações sem `CaminhoTenant`.

## Sequências (spec §14)

Números por escola e ano (`0001`...) vêm de `GeradorSequencia::gerar(Modelo::class)`; o model implementa `SequenciaPorTenant` com único `(tenant_id, ano)`. Não calcule `max()+1`.

## Provisioning e ciclo de vida (spec §6, §16)

- `mosi:tenant:create` corre `CriarTenantAction`: cria tenant, domínio e executa os `ProvisionaTenant` etiquetados (estabelecimento, perfis, tipos de documento, administrador) numa transacção; qualquer falha desfaz tudo. Um módulo novo com dados iniciais regista o seu provisionador com a etiqueta e acrescenta o FQCN a `tenancy.provisionadores_esperados`.
- O administrador inicial recebe uma senha temporária mostrada uma vez, com troca obrigatória e configuração inicial do estabelecimento.
- Estados: Activo → Suspenso → Activo; Activo/Suspenso → Encerrado (terminal). Suspenso: web com página de suspensão (403), API 403, login e tokens recusados. Encerrado: 404 como host desconhecido.
- Operação: `mosi:tenant:suspend {codigo} --motivo= [--revogar-sessoes]`, `mosi:tenant:reactivate`, `mosi:tenant:close [--force]`, `mosi:tenant:domain:add|remove`, `mosi:tenant:admin:reset --tenant=`, `mosi:tenant:tokens:prune`, `mosi:tenant:sync-perfis`.

## Plataforma (Plano 13, parcial)

O painel central da MosiTec (`Modules/Plataforma`) é uma segunda interface sobre a gestão de tenants. Esta secção cobre a fronteira; login, escolas, auditoria e recuperação do administrador chegam nas tarefas seguintes do plano.

- **Regra de ouro:** nenhum controller, FormRequest, middleware ou Vue da Plataforma abre contexto de tenant. Os pedidos do painel correm sempre com `TenantContext::temTenant() === false`; só uma Action fora de `Modules/Plataforma` pode usar `TenantContext::executarComo`, de forma curta e restaurada em `finally`. Uma leitura de model tenant-scoped feita a partir do painel lança `TenantNaoResolvido`. Imposto por teste de arquitectura (`Modules/Plataforma/app/Http` não referencia `definir`, `limpar` nem `executarComo`) e por testes de comportamento (`FronteiraTest`).
- **Dois mundos, dois hosts:** o grupo de middleware `plataforma` só responde nos hosts de `TENANCY_HOSTS_CENTRAIS` (`ApenasHostCentral`, o primeiro do grupo) e os grupos `web` e `api`, o mundo da escola, só respondem com tenant resolvido (`ExigirTenantNoContexto`, o primeiro de cada grupo). Fora do seu mundo, qualquer pedido dá 404, igual a um host desconhecido. `/up` não pertence a nenhum grupo e responde em todos os hosts. O grupo `plataforma` nunca inclui `VerificarTenantDaSessao`, `ExigirTrocaDeSenha` nem `ExigirConfiguracaoInicial` da escola, nem o `HandleInertiaRequests` (usa `HandleInertiaPlataforma`, sem permissões nem dados de tenant).
- **Prefixo `/plataforma`:** todas as URLs do painel levam este prefixo (`/plataforma`, `/plataforma/login`, `/plataforma/escolas`...; nomes `plataforma.*`). O Laravel indexa as rotas por método + caminho, sem olhar ao host, por isso `/` e `/login` não podiam existir nos dois mundos (a última registada substituiria a outra). O prefixo elimina a colisão; a barreira real de host continua a ser o `ApenasHostCentral`. Um teste varre as rotas e falha se alguma da Plataforma partilhar método + caminho com uma da escola.
- **`TENANCY_HOSTS_CENTRAIS`:** obrigatório para o painel existir. Lista separada por vírgulas; vazio = painel desactivado (404 em tudo) e a escola intacta. O host nunca é fixado em código nem em testes: só nesta variável.
- **Host central que também é domínio de uma escola:** o host central tem prioridade (o `ResolverTenant` trata-o como central, sem tenant). O `ValidadorDominio` recusa registar como domínio de escola um host central, mas um domínio já registado que depois passe a constar de `TENANCY_HOSTS_CENTRAIS` deixa de servir essa escola: não fazer isto. A leitura dos hosts é única (`HostsCentrais` no Core, normaliza maiúsculas, porta e ponto final) e partilhada pelos três consumidores.
- **Cookie e sessão próprios:** o painel usa o cookie `<app>_plataforma_session`, sem `Domain`, e `PLATAFORMA_SESSAO_MINUTOS` (por omissão 60, mais curto que `SESSION_LIFETIME`). `ConfigurarSessaoPlataforma` aplica-o antes do `StartSession`, mesmo que `SESSION_DOMAIN` esteja definido. As sessões da Plataforma ficam em `sessions` com `user_id` nulo (o guard por omissão continua a ser `web`), por isso a revogação de acessos de uma escola nunca as apaga.
- **Aviso sobre `SESSION_DOMAIN`:** não usar um domínio-pai (ex.: `.mositec.ao`) em produção. Mesmo com o cookie de sessão próprio, o cookie `XSRF-TOKEN` tem o mesmo nome nos dois mundos e um `XSRF-TOKEN` com domínio-pai fica visível no host do painel, com risco de 419 e de partilha de cookies entre escolas e painel. Deixar `SESSION_DOMAIN` vazio (`null`): cada host fica com o seu cookie.
- Todas as respostas do painel levam `Cache-Control: no-store, private` e `X-Robots-Tag: noindex`.
- **Autenticação do painel (Task 3):** guard `plataforma` (o por omissão continua `web`; o painel usa sempre `Auth::guard('plataforma')` e `auth:plataforma`, e `SuperAdminActivo` repõe o guard por omissão logo a seguir, para `sessions.user_id` ficar nulo). Sem registo público nem recuperação de senha por e-mail. As sessões ligam-se à credencial por uma impressão (HMAC do hash da senha) guardada na sessão (`ImpressaoDeCredencial`, único componente; usado por `SuperAdminActivo`): trocar ou repor a senha invalida as outras sessões sem apagar linhas de `sessions`. Limitador de login próprio `plataforma.login` (e-mail+IP e IP, chaves `p|`). O Vue do painel envia sempre `X-CSRF-TOKEN` (prop `csrf_token`), porque o cookie `XSRF-TOKEN` da escola pode chegar ao host do painel.

## Requisitos de implantação

- `SESSION_DRIVER=database` (a sessão guarda o tenant e é invalidada noutro host); HTTPS em produção.
- `TENANCY_MODO` (`dominio` ou `unico`), `TENANCY_DOMINIOS_RAIZ` (raízes que contam como subdomínio da MosiTec), `TENANCY_HOSTS_CENTRAIS` se houver hosts sem tenant.
- `ModuloSeeder` e `AcaoSeeder` (catálogo global) correm antes do primeiro tenant; `DatabaseSeeder` já os inclui.
- `APP_URL` e os hosts dos domínios têm de coincidir com os `domains` registados.

## Modelo de ameaça e limites

- Quem tem shell ou acesso à BD é privilegiado: pode correr `executarComo` em qualquer tenant. A protecção é contra falhas de código e de pedidos entre escolas, não contra o operador.
- A BD não impede chaves estrangeiras simples entre filhas de tenants diferentes (spec §7.6); a garantia vem do scope e da validação. Só o par `tenant_id`+`estabelecimento_id` é garantido pela BD.
- Row-Level Security está fora do âmbito (spec §7.5, §21). Restauro por escola, domínios personalizados e Plataforma: spec §21 e §18.
