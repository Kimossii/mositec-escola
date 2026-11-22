<?php

namespace Modules\Horario\Providers;

use Modules\Core\Providers\BaseModuleServiceProvider;

class HorarioServiceProvider extends BaseModuleServiceProvider
{
    protected string $name = 'Horario';

    protected string $nameLower = 'horario';

    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
    }
}
