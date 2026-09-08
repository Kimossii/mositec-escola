<?php

namespace Modules\Infraestrutura\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class InfraestruturaServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Infraestrutura';

    protected string $nameLower = 'infraestrutura';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
    }
}
