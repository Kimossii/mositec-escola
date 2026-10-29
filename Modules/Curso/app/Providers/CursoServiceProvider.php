<?php

namespace Modules\Curso\Providers;

use Modules\Core\Importacao\RegistoDeImportadores;
use Modules\Curso\Importacao\CursoImportador;
use Modules\Core\Providers\BaseModuleServiceProvider;

class CursoServiceProvider extends BaseModuleServiceProvider
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
