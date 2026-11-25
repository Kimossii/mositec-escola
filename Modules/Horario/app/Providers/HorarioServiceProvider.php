<?php

namespace Modules\Horario\Providers;

use Modules\Core\Providers\BaseModuleServiceProvider;
use Modules\Horario\Support\TempoEmUsoNaGrelha;
use Modules\Turma\Support\DependenciasRegistadasDoTurno;

class HorarioServiceProvider extends BaseModuleServiceProvider
{
    protected string $name = 'Horario';

    protected string $nameLower = 'horario';

    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->tag([TempoEmUsoNaGrelha::class], DependenciasRegistadasDoTurno::ETIQUETA);
    }

    public function boot(): void
    {
        parent::boot();
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
    }
}
