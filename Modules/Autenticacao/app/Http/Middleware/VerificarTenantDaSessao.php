<?php

namespace Modules\Autenticacao\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defesa em profundidade (spec §10.2): uma sessão iniciada num tenant só vale nesse tenant.
 * A defesa principal é o provider, que só encontra o utilizador dentro do tenant corrente.
 */
class VerificarTenantDaSessao
{
    public function __construct(private TenantContext $contexto) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->contexto->temTenant() || ! $request->hasSession()) {
            return $next($request);
        }

        $daSessao = $request->session()->get('tenant_id');

        if ($daSessao !== null && (int) $daSessao !== $this->contexto->id()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        return $next($request);
    }
}
