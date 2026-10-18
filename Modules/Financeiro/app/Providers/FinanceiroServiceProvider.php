<?php

namespace Modules\Financeiro\Providers;

use Illuminate\Support\Facades\Event;
use Modules\AnoLectivo\Support\DependenciasRegistadasDoAnoLectivo;
use Modules\Core\Contracts\SituacaoDePropinasDoAluno;
use Modules\Core\Tenancy\Contracts\ProvisionaTenant;
use Modules\Financeiro\Console\AplicarMultasCommand;
use Modules\Financeiro\Console\CambioPlataformaCommand;
use Modules\Financeiro\Console\GerarPropinasCommand;
use Modules\Financeiro\Console\SincronizarFinanceiroCommand;
use Modules\Financeiro\Listeners\CancelarPropinasAoTerminarMatricula;
use Modules\Financeiro\Listeners\GerarPropinasAoActivarMatricula;
use Modules\Financeiro\Provisioning\ProvisionarConfiguracaoMonetaria;
use Modules\Financeiro\Provisioning\ProvisionarRegrasCobranca;
use Modules\Financeiro\Services\SituacaoDePropinasDoAlunoService;
use Modules\Financeiro\Support\DependenciasDePlanosPropina;
use Modules\Financeiro\Support\FontesDePagamentoDePropina;
use Modules\Financeiro\Support\FontesDePrecos;
use Modules\Financeiro\Support\HistoricoDosPagamentos;
use Modules\Financeiro\Support\HistoricosDePagamentoDaPropina;
use Modules\Financeiro\Support\PagamentosDaPropina;
use Modules\Financeiro\Support\PagamentosReferenciam;
use Modules\Financeiro\Support\PrecosDasMultas;
use Modules\Financeiro\Support\PrecosDoCatalogo;
use Modules\Financeiro\Support\PrecosDosPlanos;
use Modules\Financeiro\Support\PropinasDaMatricula;
use Modules\Financeiro\Support\PropinasReferenciam;
use Modules\Financeiro\Support\ReferenciasFinanceiras;
use Modules\Matricula\Events\MatriculaEstadoAlterado;
use Modules\Matricula\Support\DependenciasRegistadasDaMatricula;
use Nwidart\Modules\Support\ModuleServiceProvider;

class FinanceiroServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Financeiro';

    protected string $nameLower = 'financeiro';

    /**
     * @var string[]
     */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    /**
     * @var string[]
     */
    protected array $commands = [
        AplicarMultasCommand::class,
        CambioPlataformaCommand::class,
        GerarPropinasCommand::class,
        SincronizarFinanceiroCommand::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->bind(SituacaoDePropinasDoAluno::class, SituacaoDePropinasDoAlunoService::class);

        $this->app->tag([
            ProvisionarRegrasCobranca::class,
            ProvisionarConfiguracaoMonetaria::class,
        ], ProvisionaTenant::ETIQUETA);
        $this->app->tag([DependenciasDePlanosPropina::class], DependenciasRegistadasDoAnoLectivo::ETIQUETA);
        $this->app->tag([PropinasDaMatricula::class], DependenciasRegistadasDaMatricula::ETIQUETA);
        $this->app->tag([PrecosDoCatalogo::class, PrecosDosPlanos::class, PrecosDasMultas::class], FontesDePrecos::ETIQUETA);
        $this->app->tag([PropinasReferenciam::class, PagamentosReferenciam::class], ReferenciasFinanceiras::ETIQUETA);
        // Pagamentos-A: as fontes reais do valor pago e do histórico de uma propina (contratos de F1).
        $this->app->tag([PagamentosDaPropina::class], FontesDePagamentoDePropina::ETIQUETA);
        $this->app->tag([HistoricoDosPagamentos::class], HistoricosDePagamentoDaPropina::ETIQUETA);
    }

    public function boot(): void
    {
        parent::boot();

        // D1 (plano F3): registo explícito (o Financeiro não tem EventServiceProvider nem descoberta de eventos).
        // O cancelamento é síncrono e corre na transacção da mudança de estado da matrícula (Q5).
        Event::listen(MatriculaEstadoAlterado::class, CancelarPropinasAoTerminarMatricula::class);
        // Q7: depois do commit (ShouldHandleEventsAfterCommit); falhas registadas, nunca propagadas.
        Event::listen(MatriculaEstadoAlterado::class, GerarPropinasAoActivarMatricula::class);
    }
}
