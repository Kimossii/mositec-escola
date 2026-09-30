<?php

namespace Modules\Disciplina\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class DisciplinaServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Disciplina';

    protected string $nameLower = 'disciplina';

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
