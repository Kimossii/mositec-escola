<?php

namespace Modules\Core\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Tenancy\Contracts\ResolvedorTenant;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Core\Tenancy\Support\NormalizadorHost;
use Modules\Core\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\Response;

class ResolverTenant
{
    private const CONTEXTO_ANTERIOR = 'tenancy.contexto_anterior';

    public function __construct(
        private TenantContext $contexto,
        private ResolvedorTenant $resolvedor,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is(...config('tenancy.caminhos_sem_tenant', []))) {
            return $next($request);
        }

        // O Laravel cria outra instância deste middleware para o terminate(),
        // por isso o contexto anterior viaja no próprio pedido.
        $request->attributes->set(
            self::CONTEXTO_ANTERIOR,
            $this->contexto->temTenant() ? $this->contexto->atual() : null,
        );

        $this->contexto->limpar();

        if ($this->hostCentral($request)) {
            return $next($request);
        }

        $tenant = $this->resolvedor->resolver($request);

        // Host desconhecido e tenant encerrado respondem da mesma forma,
        // para não revelar que domínios existem.
        if ($tenant === null || $tenant->estado === EstadoTenant::ENCERRADO) {
            abort(404);
        }

        if ($tenant->estado === EstadoTenant::SUSPENSO) {
            abort(403, 'Conta suspensa.');
        }

        $this->contexto->definir($tenant);

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! $request->attributes->has(self::CONTEXTO_ANTERIOR)) {
            return;
        }

        $anterior = $request->attributes->get(self::CONTEXTO_ANTERIOR);

        $anterior === null ? $this->contexto->limpar() : $this->contexto->definir($anterior);
    }

    private function hostCentral(Request $request): bool
    {
        return in_array(
            NormalizadorHost::normalizar($request->getHost()),
            config('tenancy.hosts_centrais', []),
            true,
        );
    }
}
