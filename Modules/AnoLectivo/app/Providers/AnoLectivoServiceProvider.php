<?php

namespace Modules\AnoLectivo\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class AnoLectivoServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'AnoLectivo';

    protected string $nameLower = 'anolectivo';

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
