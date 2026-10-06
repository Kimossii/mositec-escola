<?php

namespace Modules\Plataforma\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corre antes do StartSession: dá à Plataforma o seu próprio cookie de sessão, sem `Domain`
 * (um SESSION_DOMAIN de domínio-pai faria o cookie da escola valer no painel e vice-versa) e
 * com uma duração mais curta.
 *
 * Em produção cada pedido é um processo novo, por isso a configuração só precisava de ser
 * aplicada. Repõe-se no terminate() para não vazar para o pedido seguinte quando o processo é
 * partilhado (testes, Octane); pelo mesmo motivo o nome é também aplicado ao store de sessão
 * que já esteja resolvido.
 */
class ConfigurarSessaoPlataforma
{
    /** Duração segura quando a configurada é inválida (0, negativa ou não numérica). */
    private const MINUTOS_POR_OMISSAO = 60;

    private const ORIGINAL = 'plataforma.sessao_original';

    public static function nomeDoCookie(): string
    {
        return Str::slug((string) config('app.name'), '_') . '_plataforma_session';
    }

    public static function minutosDeSessao(): int
    {
        $minutos = config('plataforma.sessao_minutos');

        return is_numeric($minutos) && (int) $minutos > 0 ? (int) $minutos : self::MINUTOS_POR_OMISSAO;
    }

    /**
     * `auth:plataforma` chama Auth::shouldUse('plataforma'), o que mudaria o guard por omissão do
     * resto do pedido: o handler de sessão da base de dados passaria a gravar o id do Super Admin
     * em `sessions.user_id` (D5: tem de ficar nulo) e o processo, quando partilhado (testes,
     * Octane), levaria o guard errado para a escola. O SuperAdminActivo chama isto logo a seguir
     * ao `auth:plataforma` para repor o guard por omissão (`web`); o painel usa sempre guards explícitos.
     */
    public static function reporGuardPorOmissao(Request $request): void
    {
        $guard = $request->attributes->get(self::ORIGINAL)['guard'] ?? null;

        if ($guard !== null) {
            Auth::shouldUse($guard);
        }
    }

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::ORIGINAL, [
            'cookie' => config('session.cookie'),
            'domain' => config('session.domain'),
            'lifetime' => config('session.lifetime'),
            'guard' => config('auth.defaults.guard'),
        ]);

        config([
            'session.cookie' => self::nomeDoCookie(),
            'session.domain' => null,
            'session.lifetime' => self::minutosDeSessao(),
        ]);

        app('session')->driver()->setName(self::nomeDoCookie());

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        // O Laravel cria outra instância deste middleware para o terminate(): o original viaja no pedido.
        $original = $request->attributes->get(self::ORIGINAL);

        if ($original === null) {
            return;
        }

        Auth::shouldUse($original['guard']);

        config([
            'session.cookie' => $original['cookie'],
            'session.domain' => $original['domain'],
            'session.lifetime' => $original['lifetime'],
        ]);

        app('session')->driver()->setName($original['cookie']);
    }
}
