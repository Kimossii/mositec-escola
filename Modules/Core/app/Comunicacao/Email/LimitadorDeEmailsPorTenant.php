<?php

namespace Modules\Core\Comunicacao\Email;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Core\Tenancy\TenantContext;

/** Conta os emails da última hora do tenant corrente, por categoria (config/comunicacao.php). */
class LimitadorDeEmailsPorTenant
{
    public function __construct(private TenantContext $contexto) {}

    /** Regista uma tentativa e diz se ainda cabe no limite. */
    public function permitir(CategoriaEmail $categoria): bool
    {
        $tenantId = $this->contexto->id();
        $chave = "email-tenant:{$tenantId}:{$categoria->value}";
        $limite = (int) config("comunicacao.limites_por_hora.{$categoria->value}");

        if (RateLimiter::tooManyAttempts($chave, $limite)) {
            Log::warning('Limite de emails por hora excedido; email descartado.', [
                'tenant_id' => $tenantId,
                'categoria' => $categoria->value,
                'limite' => $limite,
            ]);

            return false;
        }

        RateLimiter::hit($chave, 3600);

        return true;
    }
}
