<?php

namespace Modules\Plataforma\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Modules\Plataforma\Support\ImpressaoDeCredencial;
use Symfony\Component\HttpFoundation\Response;

/**
 * A cada pedido autenticado do painel confirma que o Super Admin da sessão continua a poder
 * estar nela: existe, está activo e a sessão ainda traz a impressão da sua credencial actual.
 * Se não (conta desactivada ou apagada, senha alterada ou reposta noutro sítio), termina a
 * sessão e manda para o login.
 *
 * Corre depois de `auth:plataforma`. A decisão de invalidar uma sessão vive aqui e em
 * ImpressaoDeCredencial; nenhum controller a conhece.
 */
class SuperAdminActivo
{
    public function handle(Request $request, Closure $next): Response
    {
        ConfigurarSessaoPlataforma::reporGuardPorOmissao($request);

        $guard = Auth::guard('plataforma');
        $admin = $guard->user();

        if ($admin !== null && $admin->estaActivo() && ImpressaoDeCredencial::coincide($request->session(), $admin)) {
            return $next($request);
        }

        $guard->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Visita completa: o pedido Inertia seguiria para uma página do painel sem sessão.
        return Inertia::location(route('plataforma.login'));
    }
}
