<?php

namespace Modules\Disciplina\Providers;

use Modules\Core\Importacao\RegistoDeImportadores;
use Modules\Disciplina\Importacao\DisciplinaImportador;
use Modules\Core\Providers\BaseModuleServiceProvider;

class DisciplinaServiceProvider extends BaseModuleServiceProvider
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
