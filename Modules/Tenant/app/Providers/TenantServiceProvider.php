<?php

namespace Modules\Tenant\Providers;

use InvalidArgumentException;
use Modules\Core\Tenancy\Contracts\ResolvedorTenant;
use Modules\Tenant\Services\ResolvedorTenantPorDominio;
use Modules\Tenant\Services\ResolvedorTenantUnico;
use Nwidart\Modules\Support\ModuleServiceProvider;

class TenantServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Tenant';

    protected string $nameLower = 'tenant';

    public function register(): void
    {
        parent::register();

        // bind (e não singleton): o modo é lido a cada resolução.
        $this->app->bind(ResolvedorTenant::class, function ($app) {
            $modo = config('tenancy.modo');

            return match ($modo) {
                'dominio' => $app->make(ResolvedorTenantPorDominio::class),
                'unico' => $app->make(ResolvedorTenantUnico::class),
                default => throw new InvalidArgumentException("Modo de tenancy desconhecido: '{$modo}'. Use 'dominio' ou 'unico'."),
            };
        });
    }
}
