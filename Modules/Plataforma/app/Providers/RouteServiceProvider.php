<?php

namespace Modules\Plataforma\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;
use Modules\Plataforma\Providers\PlataformaServiceProvider;

class RouteServiceProvider extends ServiceProvider
{
    protected string $name = 'Plataforma';

    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Só o grupo `plataforma`: nunca `web` nem `api` (mundo da escola). Todas as rotas levam o prefixo `/plataforma`.
     */
    public function map(): void
    {
        Route::middleware('plataforma')->prefix(PlataformaServiceProvider::PREFIXO)->group(module_path($this->name, '/routes/web.php'));
    }
}
