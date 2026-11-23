<?php

namespace Modules\Frequencia\Providers;

use Modules\Core\Contracts\ConsultaChamadasPendentes;
use Modules\Core\Contracts\ConsultaFaltasDoAluno;
use Modules\Core\Providers\BaseModuleServiceProvider;
use Modules\Frequencia\Services\ChamadasPorFazerService;
use Modules\Frequencia\Services\FaltasDoAlunoService;

class FrequenciaServiceProvider extends BaseModuleServiceProvider
{
    protected string $name = 'Frequencia';

    protected string $nameLower = 'frequencia';

    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->bind(ConsultaFaltasDoAluno::class, FaltasDoAlunoService::class);
        $this->app->bind(ConsultaChamadasPendentes::class, ChamadasPorFazerService::class);
    }

    public function boot(): void
    {
        parent::boot();
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
    }
}
