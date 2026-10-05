<?php

namespace Modules\Plataforma\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Inertia\Inertia;
use Inertia\Middleware;
use Modules\Core\Support\HistoricoCifrado;

/**
 * Inertia do painel. Props partilhadas mínimas: o Super Admin autenticado e as mensagens flash.
 * Nunca permissões, nem dados de tenant: o painel não tem contexto de escola.
 */
class HandleInertiaPlataforma extends Middleware
{
    protected $rootView = 'layouts.plataforma';

    public function handle(Request $request, Closure $next)
    {
        // O ResponseFactory do Inertia é singleton: as props partilhadas por um pedido anterior no mesmo
        // processo (testes, Octane), como as da escola, nunca podem aparecer no painel.
        Inertia::flushShared();

        // A resposta que transporta a senha temporária (flash) cifra o histórico do browser, para que
        // a senha em claro não fique legível em history.state. Só essa resposta; null repõe a config
        // (o ResponseFactory é singleton: sem isto o true vazaria para pedidos seguintes).
        // Só com HTTPS: em HTTP o Inertia não consegue cifrar (sem `crypto.subtle`) e a visita fica
        // pendurada; ver HistoricoCifrado.
        Inertia::encryptHistory(
            $request->hasSession() && $request->session()->has('senha_temporaria') && HistoricoCifrado::possivel($request) ? true : null,
        );

        return parent::handle($request, $next);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            // Token CSRF DESTE painel. As páginas enviam-no sempre em X-CSRF-TOKEN (o Laravel verifica-o antes
            // de X-XSRF-TOKEN): com SESSION_DOMAIN de domínio-pai, o cookie XSRF-TOKEN da escola chega ao host
            // do painel e o cliente HTTP do Inertia podia ler o errado (419 intermitente).
            'csrf_token' => fn () => csrf_token(),
            'auth' => [
                'superAdmin' => Auth::guard('plataforma')->user()?->only(['id', 'name', 'email']),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                // Senha temporária (criação de escola, recuperação do administrador): aparece uma só vez.
                'senha_temporaria' => function () use ($request) {
                    $flash = $request->session()->get('senha_temporaria');

                    return $flash ? [...$flash, 'senha' => Crypt::decryptString($flash['senha'])] : null;
                },
            ],
        ];
    }
}
