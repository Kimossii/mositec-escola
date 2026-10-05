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

## Plataforma (Plano 13)

O painel central da MosiTec (`Modules/Plataforma`, prefixo `/plataforma`, rotas `plataforma.*`) é uma segunda interface sobre as Actions de `Modules/Tenant`: listar, criar, consultar, suspender, reactivar, encerrar, gerir domínios e recuperar o administrador de uma escola. Sem planos, subscrições, impersonação nem acesso a dados académicos.

### Regra de ouro do contexto

- Nenhum controller, FormRequest, middleware, Vue ou rota da Plataforma abre contexto de tenant: os pedidos do painel acabam sempre com `TenantContext::temTenant() === false`. Só uma Action fora de `Modules/Plataforma` (hoje `Modules/Tenant` e `Modules/Autenticacao`) usa `executarComo`, de forma curta e restaurada em `finally`. Ler um model de escola a partir do painel lança `TenantNaoResolvido`.
- Imposto por duas redes. **Scanner** (`TenancyArquitecturaTest`): `Modules/Plataforma` não usa `definir`/`limpar`/`executarComo`/`lembrar` pelo nome nem em chamadas dinâmicas comuns (`->{...}`, `->$m(`, `::$m(`, `call_user_func`, array-callable, reflexão), importa só Core e Tenant, nenhum controller refere `executarComo`, os models não usam `PertenceAoTenant` e o scanner de bypass do Eloquent cobre o módulo sem excepções; não tenta vencer ofuscação deliberada. **Espião de runtime** (`Tests\Fixtures\Plataforma\EspiaDoTenantContext`, instalado por `ComEspiaoDeContexto` em `PlataformaIsolamentoTest`): é a rede de segurança. Substitui o contexto do container e falha se esses métodos forem chamados a partir de um ficheiro de `/Modules/Plataforma/` (conta o sítio da chamada, saltando o `vendor/`, e não a pilha inteira: controller da Plataforma, Action do Tenant a abrir o contexto, é o desenho previsto). Acumula as violações e verifica-as no fim de cada teste, por isso um `catch (Throwable)` no código da Plataforma não as esconde.

### Dois mundos, dois hosts

- O grupo `plataforma` só responde nos hosts de `TENANCY_HOSTS_CENTRAIS` (`ApenasHostCentral`, o primeiro); `web` e `api` (a escola) só respondem com tenant resolvido (`ExigirTenantNoContexto`, o primeiro). Fora do seu mundo, 404 igual a um host desconhecido; `/up` responde em todos. O grupo `plataforma` nunca leva `VerificarTenantDaSessao`, `ExigirTrocaDeSenha`, `ExigirConfiguracaoInicial` nem `HandleInertiaRequests` (usa `HandleInertiaPlataforma`). Um teste verifica rota a rota (`FronteiraRotasTest`).
- O prefixo `/plataforma` existe porque o Laravel indexa rotas por método + caminho, sem host: `/` e `/login` não podiam existir nos dois mundos.
- **`TENANCY_HOSTS_CENTRAIS`**: obrigatório para o painel existir; lista separada por vírgulas, vazia = painel desactivado (404 em tudo). Normaliza maiúsculas, porta e ponto final (`HostsCentrais`, no Core). O host nunca é fixado em código nem em testes. Um host central tem prioridade sobre um domínio de escola igual: não registar um domínio de escola que depois passe a central.
- Rotas autenticadas: `auth:plataforma`, `SuperAdminActivo`, `ExigirTrocaDeSenhaPlataforma`, por esta ordem; as públicas são só o login (GET/POST).

### Sessão, cookie e CSRF

- Cookie próprio `<app>_plataforma_session`, sem `Domain`, com `PLATAFORMA_SESSAO_MINUTOS` (60 por omissão), aplicado por `ConfigurarSessaoPlataforma` antes do `StartSession`. As sessões do painel ficam em `sessions` com `user_id` nulo (o guard por omissão continua `web`), por isso revogar os acessos de uma escola nunca as apaga.
- **`SESSION_DOMAIN`**: com domínio-pai (aceitável em desenvolvimento) o cookie `XSRF-TOKEN` da escola chega ao host do painel (mesmo nome nos dois mundos). Por isso o Vue do painel envia sempre `X-CSRF-TOKEN` (prop `csrf_token`), provado em testes com CSRF real. Em produção deixar `SESSION_DOMAIN` vazio.
- Um pedido JSON (modal) com sessão terminada recebe 401; Inertia e navegador seguem para o login. Todas as respostas levam `Cache-Control: no-store, private` e `X-Robots-Tag: noindex`.

### Super admins

- Primeiro super admin: `php artisan mosi:plataforma:admin:create --nome= --email=` (senha temporária mostrada uma vez, troca obrigatória); reset: `mosi:plataforma:admin:reset --email=` (nova senha temporária e sessões abertas invalidadas). Sem registo público nem recuperação por e-mail.
- Senha: mínimo 12 caracteres, com maiúsculas, minúsculas, números e símbolos. Limitador de login `plataforma.login` com dois baldes: e-mail+IP (5 falhas por minuto) e só IP (30 falhas por minuto), chaves `p|`; só as falhas contam.
- Senhas temporárias (nova escola, recuperar administrador): só hash na BD, flash cifrado mostrado uma vez, nunca em logs nem na auditoria. Auditoria em `plataforma_auditoria` (tabela global), sem segredos.

### Risco de deploy: reverse proxy e IP

Atrás de um reverse proxy sem `trustProxies` configurado, todos os clientes chegam com o IP do proxy e o balde por IP (`p|ip:`) passa a ser global: qualquer pessoa bloquearia o login dos super admins. Configurar os proxies confiáveis NO DEPLOY (não há variável de ambiente para isso) e SÓ para `X-Forwarded-For` e `X-Forwarded-Proto`. NUNCA confiar em `X-Forwarded-Host`: permitiria forjar o host e, portanto, o tenant. Em produção: HTTPS e `SESSION_SECURE_COOKIE=true`.

### Modelo de ameaça

- Quem tem acesso ao painel gere todas as escolas (suspender, encerrar, recuperar administradores). Sem 2FA na V1 (mitigações: senha longa, limitador, troca obrigatória, sessão curta, auditoria); 2FA é a primeira melhoria recomendada para uma V2. Perfis de super admin estão fora do âmbito (todos podem tudo).
- Risco aceite: um `405`/`OPTIONS` pode revelar a existência de rotas do outro mundo, mas só caminhos iguais em todas as instalações (não há middleware global para isto). O painel só lê de escola o nome e o e-mail dos administradores activos; ao recuperar com um e-mail indicado pode ainda distinguir um administrador DESACTIVADO (motivo próprio, só dentro da mesma escola; um e-mail de outra escola dá o mesmo erro de um inexistente).
- Observação: um id de sessão de escola enviado sob o nome do cookie do painel dá hoje 500 (o handler de sessões da BD pergunta o utilizador ao guard `web` sem tenant); falha fechada, sem acesso nem dados; só se alcança com a `APP_KEY` (o cookie vai cifrado).

## Requisitos de implantação

- `SESSION_DRIVER=database` (a sessão guarda o tenant e é invalidada noutro host); HTTPS em produção.
- `TENANCY_MODO` (`dominio` ou `unico`), `TENANCY_DOMINIOS_RAIZ` (raízes que contam como subdomínio da MosiTec), `TENANCY_HOSTS_CENTRAIS` se houver hosts sem tenant.
- `ModuloSeeder` e `AcaoSeeder` (catálogo global) correm antes do primeiro tenant; `DatabaseSeeder` já os inclui.
- `APP_URL` e os hosts dos domínios têm de coincidir com os `domains` registados.

## Modelo de ameaça e limites

- Quem tem shell ou acesso à BD é privilegiado: pode correr `executarComo` em qualquer tenant. A protecção é contra falhas de código e de pedidos entre escolas, não contra o operador.
- A BD não impede chaves estrangeiras simples entre filhas de tenants diferentes (spec §7.6); a garantia vem do scope e da validação. Só o par `tenant_id`+`estabelecimento_id` é garantido pela BD.
- Row-Level Security está fora do âmbito (spec §7.5, §21). Restauro por escola, domínios personalizados e Plataforma: spec §21 e §18.
