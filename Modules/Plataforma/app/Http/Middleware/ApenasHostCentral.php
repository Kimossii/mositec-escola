<?php

namespace Modules\Plataforma\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Tenancy\Support\HostsCentrais;
use Symfony\Component\HttpFoundation\Response;

/**
 * Primeiro middleware do grupo `plataforma`: o painel só responde nos hosts centrais.
 * Fora deles (ou sem hosts centrais configurados) responde 404, igual a uma rota inexistente.
 *
 * Como envolve tudo o resto do grupo, também aplica a todas as respostas do painel (erros
 * incluídos) os cabeçalhos que impedem cache e indexação.
 */
class ApenasHostCentral
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! HostsCentrais::contem($request->getHost())) {
            abort(404);
        }

        $resposta = $next($request);

        $resposta->headers->set('Cache-Control', 'no-store, private');
        $resposta->headers->set('X-Robots-Tag', 'noindex');

        return $resposta;
    }
}
