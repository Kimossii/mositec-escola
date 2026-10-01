<?php

namespace Modules\Autenticacao\Providers;

use Laravel\Sanctum\Sanctum;
use Modules\Autenticacao\Models\TokenDeAcesso;
use Modules\Autenticacao\Passwords\PasswordBrokerManagerTenant;
use Modules\Autenticacao\Service\LimitadorLogin;
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

        // Tokens de recuperação de palavra-passe isolados por tenant. O PasswordResetServiceProvider
        // do Laravel é diferido e regista 'auth.password' depois deste provider, anulando um
        // singleton() directo; extend() sobrevive a esse registo e substitui o gestor na resolução.
        $this->app->extend('auth.password', fn ($gestor, $app) => new PasswordBrokerManagerTenant($app));
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
