<?php

namespace Modules\Core\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
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

        // Fail-closed: o contexto nunca é aberto para tenants suspensos, e o pedido
        // para aqui, antes de sessão e autenticação.
        if ($tenant->estado === EstadoTenant::SUSPENSO) {
            return $this->contaSuspensa($request);
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

    /**
     * Sem dados do tenant nem motivo: mensagem genérica (spec §6, §17.4).
     */
    private function contaSuspensa(Request $request): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return (new JsonResponse(['message' => 'Conta suspensa.'], 403))
                ->header('Cache-Control', 'no-store, private');
        }

        // Pedidos Inertia (XHR) não conseguem mostrar uma página HTML: forçam uma visita completa.
        if ($request->header('X-Inertia')) {
            return response('', 409)
                ->header('X-Inertia-Location', $request->fullUrl())
                ->header('Cache-Control', 'no-store, private');
        }

        return response()
            ->view('tenancy.conta-suspensa', [], 403)
            ->header('Cache-Control', 'no-store, private');
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
