<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Permissao\Enums\Perfil;
use Modules\Usuario\Models\User;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('gerir-permissoes', function (User $user) {
            return $user->roles->contains('nome', Perfil::ADMIN_ESCOLA->value);
        });

        Gate::define('gerir-usuarios', function (User $user) {
            return $user->roles->contains('nome', Perfil::ADMIN_ESCOLA->value);
        });

        RateLimiter::for('api', function ($request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Rate limiter para login (opcional)
        RateLimiter::for('login', function ($request) {
            return Limit::perMinute(5)->by($request->ip());
        });
    }
}
