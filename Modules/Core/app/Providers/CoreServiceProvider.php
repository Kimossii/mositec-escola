<?php

namespace Modules\Core\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Modules\Core\Providers\RouteServiceProvider;
use Modules\Core\Support\PesquisaTexto;
use Modules\Core\Tenancy\Jobs\CapturaTenantNoPayload;
use Modules\Core\Tenancy\Provisioning\ColectorDeCredenciais;
use Modules\Core\Tenancy\TenantContext;
use Modules\Core\Tenancy\Validation\VerificadorPresencaTenant;

class CoreServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Core';

    protected string $nameLower = 'core';

    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->scoped(TenantContext::class);
        $this->app->scoped(ColectorDeCredenciais::class);

        // O TenantContext é resolvido dentro do verificador a cada consulta,
        // porque o verificador é singleton e o contexto tem âmbito de pedido.
        $this->app->extend('validation.presence', fn ($verificador, $app) => new VerificadorPresencaTenant($app['db']));
    }

    public function boot(): void
    {
        parent::boot();

        PesquisaTexto::registar();
        CapturaTenantNoPayload::registar();
    }
}
