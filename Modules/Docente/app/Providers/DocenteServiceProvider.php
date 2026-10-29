<?php

namespace Modules\Docente\Providers;

use Modules\AnoLectivo\Support\DependenciasRegistadasDoAnoLectivo;
use Modules\Core\Contracts\ProcuraDocenteParaConta;
use Modules\Core\Contracts\VerificaAtribuicaoDocente;
use Modules\Docente\Console\SincronizarDocentesCommand;
use Modules\Docente\Services\ProcuraDocenteParaContaService;
use Modules\Docente\Services\VerificaAtribuicaoDocenteService;
use Modules\Docente\Support\DependenciasDeAtribuicoesDocentes;
use Modules\Core\Providers\BaseModuleServiceProvider;

class DocenteServiceProvider extends BaseModuleServiceProvider
{
    protected string $name = 'Docente';

    protected string $nameLower = 'docente';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    /** @var list<class-string> */
    protected array $commands = [
        SincronizarDocentesCommand::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->bind(VerificaAtribuicaoDocente::class, VerificaAtribuicaoDocenteService::class);
        $this->app->bind(ProcuraDocenteParaConta::class, ProcuraDocenteParaContaService::class);
        $this->app->tag([DependenciasDeAtribuicoesDocentes::class], DependenciasRegistadasDoAnoLectivo::ETIQUETA);
    }

    public function boot(): void
    {
        parent::boot();
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
    }
}
