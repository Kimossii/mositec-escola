<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Modules\Autenticacao\Http\Middleware\VerificarTenantDaSessao;
use Modules\Core\Tenancy\Http\Middleware\ResolverTenant;
use Modules\Estabelecimento\Http\Middleware\ExigirConfiguracaoInicial;
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

        $middleware->api([
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);
        // VerificarTenantDaSessao antes do Inertia: nunca partilhar os dados
        // de um utilizador de outra escola.
        $middleware->web(append: [
            VerificarTenantDaSessao::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
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
