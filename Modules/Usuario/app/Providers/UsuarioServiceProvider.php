<?php

namespace Modules\Usuario\Providers;

use Modules\Core\Tenancy\Contracts\ProvisionaTenant;
use Modules\Usuario\Provisioning\ProvisionarTiposDocumento;
use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class UsuarioServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Usuario';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'usuario';

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
    public function register(): void
    {
        parent::register();

        $this->app->tag([ProvisionarTiposDocumento::class], ProvisionaTenant::ETIQUETA);
    }

    public function boot(): void
    {
        parent::boot();
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
