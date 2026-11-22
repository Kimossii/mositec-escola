<?php

namespace Modules\Autenticacao\Http\Responses;

use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Modules\Core\Tenancy\TenantContext;

/** Fim do desafio 2FA: mesmo estado de sessão que o login normal (tenant) e navegação completa para o shell da app. */
class DoisFactoresLoginResponse implements TwoFactorLoginResponse
{
    public function toResponse($request)
    {
        $request->session()->put('tenant_id', app(TenantContext::class)->id());

        return $request->wantsJson() && ! $request->header('X-Inertia')
            ? new JsonResponse('', 204)
            : Inertia::location('/');
    }
}
