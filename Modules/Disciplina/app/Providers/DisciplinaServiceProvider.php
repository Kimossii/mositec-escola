<?php

namespace Modules\Disciplina\Providers;

use Modules\Core\Importacao\RegistoDeImportadores;
use Modules\Disciplina\Importacao\DisciplinaImportador;
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
        $this->app->make(RegistoDeImportadores::class)->registar(DisciplinaImportador::class);
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
    }
}
