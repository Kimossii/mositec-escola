<?php

namespace Modules\Curso\Providers;

use Modules\Core\Importacao\RegistoDeImportadores;
use Modules\Curso\Importacao\CursoImportador;
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
        $this->app->make(RegistoDeImportadores::class)->registar(CursoImportador::class);
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
    }
}
