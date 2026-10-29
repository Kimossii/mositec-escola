<?php

namespace Modules\AnoLectivo\Providers;

use Modules\Core\Providers\BaseModuleServiceProvider;

class AnoLectivoServiceProvider extends BaseModuleServiceProvider
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
