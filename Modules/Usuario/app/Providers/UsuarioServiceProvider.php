<?php

namespace Modules\Usuario\Providers;

use Modules\Core\Licenciamento\Contracts\ContaRecursoLicenciado;
use Modules\Core\Tenancy\Contracts\ProvisionaTenant;
use Illuminate\Support\Facades\Gate;
use Modules\Usuario\Licenciamento\ContaUtilizadores;
use Modules\Usuario\Policies\DocumentoPessoaPolicy;
use Modules\Usuario\Provisioning\ProvisionarTiposDocumento;
use Modules\Core\Providers\BaseModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class UsuarioServiceProvider extends BaseModuleServiceProvider
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
        $this->app->tag([ContaUtilizadores::class], ContaRecursoLicenciado::TAG);
    }

    public function boot(): void
    {
        parent::boot();
        // Carrega as migrations do módulo
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');

        Gate::define('verDocumentosPessoa', [DocumentoPessoaPolicy::class, 'verDocumentos']);
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
