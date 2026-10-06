<?php

namespace Modules\Plataforma\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enquanto `deve_alterar_senha` estiver activa (senha temporária), o Super Admin só alcança a
 * troca de senha, o seu PUT e o logout: nunca fica sem poder trocar a senha nem terminar a sessão.
 * Qualquer outra rota, de leitura ou escrita, é bloqueada.
 */
class ExigirTrocaDeSenhaPlataforma
{
    private const ROTAS_PERMITIDAS = ['plataforma.senha.alterar', 'plataforma.senha.alterar.store', 'plataforma.logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('plataforma')->user();

        if ($admin === null || ! $admin->deve_alterar_senha || $request->routeIs(self::ROTAS_PERMITIDAS)) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'message' => 'É obrigatório alterar a palavra-passe temporária antes de continuar.',
            ], 403);
        }

        // Navegação completa: um redireccionamento SPA não repõe o estado da página de troca.
        if ($request->header('X-Inertia')) {
            return Inertia::location(route('plataforma.senha.alterar'));
        }

        return redirect()->route('plataforma.senha.alterar');
    }
}
