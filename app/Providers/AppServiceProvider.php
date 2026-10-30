<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;

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
        // Fora de produção, uma relação carregada em ciclo (N+1) lança excepção: aponta o sítio exacto a corrigir com with().
        // Em produção nunca lança — só regista —, para um descuido não partir um pedido real.
        Model::preventLazyLoading();
        Model::handleLazyLoadingViolationUsing(function (Model $modelo, string $relacao): void {
            if (! app()->isProduction()) {
                throw new \Illuminate\Database\LazyLoadingViolationException($modelo, $relacao);
            }

            logger()->warning('N+1: relação carregada em ciclo', ['modelo' => $modelo::class, 'relacao' => $relacao]);
        });

        // Por utilizador (ou IP): travam um cliente em ciclo ou um script, sem incomodar o uso normal.
        RateLimiter::for('pesquisa', fn ($request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('operacao-pesada', fn ($request) => Limit::perMinute(20)->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('api', function ($request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });
    }
}
