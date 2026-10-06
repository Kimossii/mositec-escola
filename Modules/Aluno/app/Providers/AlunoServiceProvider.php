<?php

namespace Modules\Aluno\Providers;

use Modules\Aluno\Services\ProcuraAlunoParaContaService;
use Modules\Aluno\Services\ProcuraFotosDeAlunosService;
use Modules\Core\Contracts\ProcuraAlunoParaConta;
use Modules\Core\Contracts\ProcuraFotosDeAlunos;
use Nwidart\Modules\Support\ModuleServiceProvider;

class AlunoServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Aluno';

    protected string $nameLower = 'aluno';

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->bind(ProcuraAlunoParaConta::class, ProcuraAlunoParaContaService::class);
        $this->app->bind(ProcuraFotosDeAlunos::class, ProcuraFotosDeAlunosService::class);
    }

    public function boot(): void
    {
        parent::boot();
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
    }
}
