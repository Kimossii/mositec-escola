<?php

namespace Modules\Estabelecimento\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Tenancy\TenantContext;
use Modules\Estabelecimento\Models\Estabelecimento;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enquanto a configuração inicial do estabelecimento não foi feita, leva quem
 * pode editá-lo para o ecrã de dados da escola (spec §8.5). Quem não tem essa
 * permissão segue normalmente.
 */
class ExigirConfiguracaoInicial
{
    public function __construct(private TenantContext $contexto) {}

    public function handle(Request $request, Closure $next): Response
    {
        $utilizador = $request->user();

        if ($utilizador === null || ! $this->contexto->temTenant() || $this->rotaIsenta($request)) {
            return $next($request);
        }

        // Só encaminha quem também pode ver o destino, senão o 403 de lá gera um ciclo de redirecionamentos.
        $podeConfigurar = $utilizador->can('estabelecimento.editar') && $utilizador->can('estabelecimento.ver');

        if (Estabelecimento::current()->estaConfigurado() || ! $podeConfigurar) {
            return $next($request);
        }

        return redirect()->route('estabelecimento.dados');
    }

    private function rotaIsenta(Request $request): bool
    {
        $rota = $request->route();

        return $rota === null
            || $rota->getName() === 'logout'
            || $request->routeIs('estabelecimento.*')
            // A troca obrigatória de senha tem prioridade: sem esta isenção os dois middlewares redireccionam em ciclo.
            || $request->routeIs('senha.alterar', 'senha.alterar.store');
    }
}
