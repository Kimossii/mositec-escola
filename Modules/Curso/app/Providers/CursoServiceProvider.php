<?php

namespace Modules\Curso\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class CursoServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Curso';

    protected string $nameLower = 'curso';

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
