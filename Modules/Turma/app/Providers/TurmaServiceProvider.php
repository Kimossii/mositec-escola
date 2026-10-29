<?php

namespace Modules\Turma\Providers;

use Modules\Core\Importacao\RegistoDeImportadores;
use Modules\Turma\Importacao\TurmaImportador;
use Modules\Core\Providers\BaseModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class TurmaServiceProvider extends BaseModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Turma';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'turma';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    // protected array $commands = [];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];
    public function boot(): void
    {
        parent::boot();
        $this->app->make(RegistoDeImportadores::class)->registar(TurmaImportador::class);
        // Carrega as migrations do módulo
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
    }

    /**
     * Define module schedules.
     *
     * @param $schedule
     */
    // protected function configureSchedules(Schedule $schedule): void
    // {
    //     $schedule->command('inspire')->hourly();
    // }
}
