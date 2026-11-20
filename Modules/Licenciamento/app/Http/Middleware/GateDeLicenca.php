<?php

namespace Modules\Licenciamento\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Inertia\Inertia;
use Illuminate\Support\Str;
use Modules\Core\Contracts\IdentificaAdministradorDaEscola;
use Modules\Licenciamento\Enums\ModoDeOperacao;
use Modules\Licenciamento\Services\DireitosService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Com a licença da escola bloqueada: o administrador só chega à página da licença, ao logout e às exportações
 * já existentes; qualquer outro perfil recebe uma resposta genérica, sem pistas, e perde a sessão. Sem sessão
 * (visitantes) não interfere. Só decide por modo + perfil + rota; vive nos grupos web e api, nunca na consola,
 * nas filas nem na plataforma. Com a flag inactiva, `modo()` devolve NORMAL e o pedido segue de imediato.
 */
class GateDeLicenca
{
    public const MENSAGEM = 'Ocorreu um erro. Tente mais tarde.';

    public function __construct(
        private readonly DireitosService $direitos,
        private readonly IdentificaAdministradorDaEscola $administradores,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('licenciamento.activo') || $this->direitos->modo() !== ModoDeOperacao::BLOQUEADO) {
            return $next($request);
        }

        $utilizador = $request->user('sanctum') ?? $request->user();

        if ($utilizador === null) {
            return $next($request);
        }

        if ($this->administradores->eAdministrador($utilizador)) {
            return $this->rotaPermitida($request) ? $next($request) : $this->levarAdministradorALicenca();
        }

        return $this->recusar($request);
    }

    private function rotaPermitida(Request $request): bool
    {
        $nome = $request->route()?->getName();

        return $nome !== null && Str::is((array) config('licenciamento.rotas_permitidas_ao_admin', []), $nome);
    }

    /** Para a página da licença (303: o método original não se repete). O administrador também está bloqueado em «/», por isso nunca para lá. */
    private function levarAdministradorALicenca(): Response
    {
        return $this->semArmazenar(redirect()->route('licenca.index', [], 303));
    }

    private function recusar(Request $request): Response
    {
        return $this->semArmazenar($this->resposta($request, tomarSessao: true));
    }

    private function resposta(Request $request, bool $tomarSessao): Response
    {
        if ($tomarSessao) {
            $this->terminarSessao($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => self::MENSAGEM], 503);
        }

        // Inertia (só quem perdeu a sessão): um único salto para o login, que é um GET a renderizar com o erro já em sessão.
        // (Um redirect() daqui sairia como 302 — o Inertia só o reescreve para 303 depois deste middleware — e um back() cairia
        // num pedido de visitante que redirecciona outra vez e gasta o erro.)
        if ($request->header('X-Inertia') && $tomarSessao) {
            $request->hasSession() && $request->session()->flash('errors', (new ViewErrorBag)->put('default', new MessageBag(['autorizacao' => self::MENSAGEM])));

            return Inertia::location(route('login'));
        }

        return response()->view('errors.generico', [], 503);
    }

    /** Termina a sessão do pedido (cookie); autenticação por token (Bearer) não tem sessão para terminar. */
    private function terminarSessao(Request $request): void
    {
        if ($request->bearerToken() !== null || ! $request->hasSession()) {
            return;
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function semArmazenar(Response $resposta): Response
    {
        $resposta->headers->set('Cache-Control', 'no-store, private');

        return $resposta;
    }
}
