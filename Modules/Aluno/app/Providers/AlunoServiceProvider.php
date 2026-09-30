<?php

namespace Modules\Aluno\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class AlunoServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Aluno';

    protected string $nameLower = 'aluno';

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
