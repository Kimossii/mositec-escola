<?php

namespace Modules\Aluno\Providers;

use Modules\Aluno\Services\ProcuraAlunoParaContaService;
use Modules\Aluno\Services\ProcuraFotosDeAlunosService;
use Modules\Core\Contracts\ProcuraAlunoParaConta;
use Modules\Aluno\Importacao\AlunoImportador;
use Modules\Core\Contracts\ProcuraFotosDeAlunos;
use Modules\Core\Importacao\RegistoDeImportadores;
use Modules\Core\Providers\BaseModuleServiceProvider;

class AlunoServiceProvider extends BaseModuleServiceProvider
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
        $this->app->make(RegistoDeImportadores::class)->registar(AlunoImportador::class);
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
    }
}
