<?php

namespace Modules\Core\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Primeiro middleware dos grupos `web` e `api`: o mundo da escola só responde com um tenant
 * resolvido. Sem tenant (host central, por exemplo) responde 404, igual ao de um host
 * desconhecido, antes de sessão, CSRF, autenticação ou qualquer outra coisa.
 */
class ExigirTenantNoContexto
{
    public function __construct(private TenantContext $contexto) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->contexto->temTenant()) {
            abort(404);
        }

        return $next($request);
    }
}
