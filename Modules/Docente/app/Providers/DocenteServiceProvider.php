<?php

namespace Modules\Docente\Providers;

use Modules\AnoLectivo\Support\DependenciasRegistadasDoAnoLectivo;
use Modules\Core\Contracts\VerificaAtribuicaoDocente;
use Modules\Core\Support\DependenciasRegistadasDoUtilizador;
use Modules\Docente\Services\VerificaAtribuicaoDocenteService;
use Modules\Docente\Support\DependenciasDeAtribuicoesDocentes;
use Modules\Docente\Support\DependenciasDoUtilizadorDocente;
use Nwidart\Modules\Support\ModuleServiceProvider;

class DocenteServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Docente';

    protected string $nameLower = 'docente';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->bind(VerificaAtribuicaoDocente::class, VerificaAtribuicaoDocenteService::class);
        $this->app->tag([DependenciasDeAtribuicoesDocentes::class], DependenciasRegistadasDoAnoLectivo::ETIQUETA);
        $this->app->tag([DependenciasDoUtilizadorDocente::class], DependenciasRegistadasDoUtilizador::ETIQUETA);
    }

    public function boot(): void
    {
        parent::boot();
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
    }
}
