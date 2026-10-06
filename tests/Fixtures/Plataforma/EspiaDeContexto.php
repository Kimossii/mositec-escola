<?php

namespace Tests\Fixtures\Plataforma;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware espião: regista, durante o pedido, se havia tenant no contexto.
 */
class EspiaDeContexto
{
    /** @var bool[] */
    public static array $observacoes = [];

    public function handle(Request $request, Closure $next): Response
    {
        self::$observacoes[] = app(TenantContext::class)->temTenant();

        return $next($request);
    }
}
