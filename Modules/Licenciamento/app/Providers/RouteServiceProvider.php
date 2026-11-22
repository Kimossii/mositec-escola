<?php

namespace Modules\Licenciamento\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    protected string $name = 'Licenciamento';

    public function map(): void
    {
        Route::middleware('web')->group(module_path($this->name, '/routes/web.php'));
    }
}
