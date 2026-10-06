<?php

namespace Modules\Autenticacao\Providers;

use Laravel\Sanctum\Sanctum;
use Modules\Autenticacao\Actions\RevogarAcessosDoTenantAction;
use Modules\Autenticacao\Console\PodarTokensCommand;
use Modules\Autenticacao\Console\RecuperarAdministradorCommand;
use Modules\Autenticacao\Console\SincronizarPerfisCommand;
use Modules\Autenticacao\Models\TokenDeAcesso;
use Modules\Autenticacao\Service\LimitadorLogin;
use Modules\Autenticacao\Actions\RecuperaAdministradorDoTenantAction;
use Modules\Core\Tenancy\Contracts\ProvisionaTenant;
use Modules\Core\Tenancy\Contracts\RecuperaAdministradorDoTenant;
use Modules\Core\Tenancy\Contracts\RevogaAcessosDoTenant;
use Modules\Autenticacao\Provisioning\ProvisionarAdministradorInicial;
use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class AutenticacaoServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Autenticacao';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'autenticacao';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        SincronizarPerfisCommand::class,
        RecuperarAdministradorCommand::class,
        PodarTokensCommand::class,
    ];

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

        $this->app->tag([ProvisionarAdministradorInicial::class], ProvisionaTenant::ETIQUETA);
        $this->app->bind(RevogaAcessosDoTenant::class, RevogarAcessosDoTenantAction::class);
        $this->app->bind(RecuperaAdministradorDoTenant::class, RecuperaAdministradorDoTenantAction::class);
    }

    public function boot(): void
    {
        parent::boot();
        // Carrega as migrations do módulo
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');

        LimitadorLogin::definir();

        Sanctum::usePersonalAccessTokenModel(TokenDeAcesso::class);
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
