<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Modules\Permissao\Services\PermissionResolver;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'layouts.app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * O axios do frontend envia sempre X-Requested-With, o que faz
     * Request::ajax() devolver true em toda navegação Inertia — inclusive
     * visitas normais de página. Isso faz o StartSession do próprio Laravel
     * nunca gravar `session('url.previous')` (só o faz quando `!$request->ajax()`),
     * deixando `redirect()->back()` dependente 100% do header Referer do
     * browser. Sem Referer (bloqueado por proteções de privacidade do
     * browser, extensões, etc.), back() cai no fallback padrão do Laravel
     * e manda para a home. Gravamos aqui a URL a cada navegação Inertia de
     * página (GET, não parcial) para dar a back() um fallback de sessão
     * fiável, independente do Referer.
     */
    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethod('GET') && $request->header('X-Inertia') && ! $request->header('X-Inertia-Partial-Data')) {
            $request->session()->setPreviousUrl($request->fullUrl());
        }

        return parent::handle($request, $next);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'permissoes' => $request->user()
                ? app(PermissionResolver::class)->conjuntoConcedido($request->user())
                : [],
            // A maioria das acções usa toast.success() com texto fixo no
            // frontend, sem olhar para isto — mas quando o resultado varia
            // por pedido (ex.: renovação em massa, com contagens), o backend
            // precisa de poder mandar a mensagem exacta.
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
            ],
        ];
    }
}
