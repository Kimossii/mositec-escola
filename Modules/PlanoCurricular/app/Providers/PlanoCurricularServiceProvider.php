<?php

namespace Modules\PlanoCurricular\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class PlanoCurricularServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'PlanoCurricular';

    protected string $nameLower = 'planocurricular';

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
