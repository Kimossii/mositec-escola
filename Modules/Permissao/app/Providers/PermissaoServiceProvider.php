<?php

namespace Modules\Permissao\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Modules\Permissao\Services\PermissionResolver;
use Modules\Usuario\Models\User;

class PermissaoServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Permissao';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'permissao';

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

        $this->app->singleton(PermissionResolver::class);
    }

    public function boot(): void
    {
        parent::boot();
        // Carrega as migrations do módulo
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');

        Gate::before(function (User $user, string $ability) {
            $resolver = app(PermissionResolver::class);

            if (!$resolver->reconhece($ability)) {
                return null;
            }

            return $resolver->can($user, $ability);
        });
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
