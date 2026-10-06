<?php

namespace Modules\Autenticacao\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enquanto `deve_alterar_senha` estiver activa (senha temporária), o utilizador só
 * alcança a troca de senha e o logout, na web e na API: nunca fica sem poder trocar
 * a senha nem terminar a sessão. Qualquer outra rota, de leitura ou escrita, é bloqueada.
 */
class ExigirTrocaDeSenha
{
    private const ROTAS_PERMITIDAS = ['senha.alterar', 'senha.alterar.store', 'logout'];

    private const CAMINHOS_API_PERMITIDOS = ['api/v1/autenticacaoApi/api/logout'];

    public function handle(Request $request, Closure $next): Response
    {
        // O guard por omissão é o `web`; os pedidos da API autenticam por token (sanctum).
        $utilizador = $request->user() ?? $request->user('sanctum');

        if ($utilizador === null || ! $utilizador->deve_alterar_senha || $this->rotaPermitida($request)) {
            return $next($request);
        }

        if ($request->is('api/*') || ($request->expectsJson() && ! $request->header('X-Inertia'))) {
            return response()->json([
                'message' => 'É obrigatório alterar a palavra-passe temporária antes de continuar.',
            ], 403);
        }

        // A página de troca usa outra vista de raiz: um redireccionamento SPA deixaria o shell por montar.
        if ($request->header('X-Inertia')) {
            return Inertia::location(route('senha.alterar'));
        }

        return redirect()->route('senha.alterar');
    }

    private function rotaPermitida(Request $request): bool
    {
        return $request->routeIs(self::ROTAS_PERMITIDAS) || $request->is(self::CAMINHOS_API_PERMITIDOS);
    }
}
