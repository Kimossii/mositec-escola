<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Modules\Autenticacao\Http\Middleware\ExigirTrocaDeSenha;
use Modules\Autenticacao\Http\Middleware\VerificarTenantDaSessao;
use Modules\Core\Tenancy\Http\Middleware\ExigirTenantNoContexto;
use Modules\Core\Tenancy\Http\Middleware\ResolverTenant;
use Modules\Estabelecimento\Http\Middleware\ExigirConfiguracaoInicial;
use Modules\Plataforma\Http\Middleware\ApenasHostCentral;
use Modules\Plataforma\Http\Middleware\ConfigurarSessaoPlataforma;
use Modules\Plataforma\Http\Middleware\ExigirTrocaDeSenhaPlataforma;
use Modules\Plataforma\Http\Middleware\HandleInertiaPlataforma;
use Modules\Plataforma\Http\Middleware\SuperAdminActivo;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global, e não nos grupos web/api: o tenant tem de estar resolvido
        // antes de qualquer middleware de rota. No grupo api, o Sanctum põe o
        // EnsureFrontendRequestsAreStateful (que inicia a sessão e autentica)
        // à frente de tudo o que o grupo registe.
        $middleware->append(ResolverTenant::class);

        // Os dois mundos: `web` e `api` são a escola e exigem tenant resolvido (ExigirTenantNoContexto
        // é o primeiro de cada grupo, antes até do Sanctum, que iniciaria a sessão); `plataforma` é o
        // painel central e exige host central. Nunca partilham middleware de sessão da escola.
        $middleware->prependToGroup('web', ExigirTenantNoContexto::class);
        $middleware->prependToGroup('api', ExigirTenantNoContexto::class);

        $middleware->group('plataforma', [
            ApenasHostCentral::class,
            ConfigurarSessaoPlataforma::class,
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            HandleInertiaPlataforma::class,
        ]);

        // As verificações de conta (activa, credencial actual, troca obrigatória) correm ANTES de resolver
        // `{tenant}` pelo código: sem isto, uma sessão inválida receberia 404 (existe ou não) em vez de ser
        // expulsa, e o painel consultaria `tenants` antes de saber quem pede.
        $middleware->prependToPriorityList(before: \Illuminate\Routing\Middleware\SubstituteBindings::class, prepend: SuperAdminActivo::class);
        $middleware->prependToPriorityList(before: \Illuminate\Routing\Middleware\SubstituteBindings::class, prepend: ExigirTrocaDeSenhaPlataforma::class);

        // O login de um visitante no painel é o do painel; a escola mantém o comportamento por omissão (route('login')).
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('plataforma', 'plataforma/*') ? route('plataforma.login') : null);

        $middleware->api([
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            ExigirTrocaDeSenha::class,
            'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);
        // VerificarTenantDaSessao antes do Inertia: nunca partilhar os dados
        // de um utilizador de outra escola.
        $middleware->web(append: [
            VerificarTenantDaSessao::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            ExigirTrocaDeSenha::class,
            ExigirConfiguracaoInicial::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Uma ação negada por Gate/Policy (403) não deve mostrar a página de
        // erro crua do Laravel — volta pra página anterior com a mensagem
        // (vinda do backend, ex: Response::deny('...') na Policy) no mesmo
        // canal "errors" que o frontend já usa pra toasts de ValidationException.
        // O Handler do Laravel converte AuthorizationException sem status em
        // AccessDeniedHttpException antes de chegar aqui — por isso é esse o
        // tipo que precisa ser pego, não o AuthorizationException original.
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if ($request->header('X-Inertia')) {
                return redirect()->back()->withErrors(['autorizacao' => $e->getMessage()]);
            }
        });
    })->create();
