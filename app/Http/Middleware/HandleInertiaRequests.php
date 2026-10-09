<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Inertia\Inertia;
use Inertia\Middleware;
use Modules\Core\Support\HistoricoCifrado;
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

        // A resposta que transporta a senha temporária (flash) cifra o histórico do
        // browser, para que a senha em claro não fique legível em history.state.
        // Só essa resposta; as restantes seguem config('inertia.history.encrypt').
        // null repõe o valor da config (o ResponseFactory é singleton: sem isto
        // o true vazaria para pedidos seguintes na mesma instância da app).
        // Só com HTTPS: em HTTP o Inertia não consegue cifrar (sem `crypto.subtle`) e a visita fica
        // pendurada; ver HistoricoCifrado.
        Inertia::encryptHistory(
            $request->hasSession() && $request->session()->has('senha_temporaria') && HistoricoCifrado::possivel($request) ? true : null,
        );

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
            // Só o que o frontend realmente lê (UserMenu.vue: name, email) —
            // $request->user() completo ia inteiro, sem necessidade, a cada
            // página. Controlo de acesso não passa por aqui: quem decide o
            // que o utilizador pode ver/fazer é a `permissoes` abaixo.
            'auth' => [
                'user' => $request->user()?->only(['id', 'name', 'email']),
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
                // Resumo da cópia de planos de propina entre anos lectivos (copiados/ignorados).
                'copia_planos' => fn () => $request->session()->get('copia_planos'),
                // Senha temporária de uma redefinição manual: aparece uma só vez.
                'senha_temporaria' => function () use ($request) {
                    $flash = $request->session()->get('senha_temporaria');

                    return $flash ? [...$flash, 'senha' => Crypt::decryptString($flash['senha'])] : null;
                },
            ],
        ];
    }
}
