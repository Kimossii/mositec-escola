<?php

namespace Modules\Docente\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Docente\Listeners\GarantirDocenteAoCriarProfessor;
use Modules\Usuario\Events\PerfilAtribuidoAUtilizador;
use Modules\Usuario\Events\UtilizadorCriado;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event handler mappings for the application.
     *
     * @var array<string, array<int, string>>
     */
    protected $listen = [
        UtilizadorCriado::class => [GarantirDocenteAoCriarProfessor::class],
        PerfilAtribuidoAUtilizador::class => [GarantirDocenteAoCriarProfessor::class],
    ];

    /**
     * Indicates if events should be discovered.
     *
     * @var bool
     */
    protected static $shouldDiscoverEvents = true;

    /**
     * Configure the proper event listeners for email verification.
     */
    protected function configureEmailVerification(): void {}
}
