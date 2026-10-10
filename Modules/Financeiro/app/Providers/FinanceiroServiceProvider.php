<?php

namespace Modules\Financeiro\Providers;

use Modules\AnoLectivo\Support\DependenciasRegistadasDoAnoLectivo;
use Modules\Core\Tenancy\Contracts\ProvisionaTenant;
use Modules\Financeiro\Console\CambioPlataformaCommand;
use Modules\Financeiro\Console\SincronizarFinanceiroCommand;
use Modules\Financeiro\Provisioning\ProvisionarConfiguracaoMonetaria;
use Modules\Financeiro\Provisioning\ProvisionarRegrasCobranca;
use Modules\Financeiro\Support\DependenciasDePlanosPropina;
use Modules\Financeiro\Support\FontesDePrecos;
use Modules\Financeiro\Support\PrecosDasMultas;
use Modules\Financeiro\Support\PrecosDoCatalogo;
use Modules\Financeiro\Support\PrecosDosPlanos;
use Modules\Financeiro\Support\PropinasReferenciam;
use Modules\Financeiro\Support\ReferenciasFinanceiras;
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
        CambioPlataformaCommand::class,
        SincronizarFinanceiroCommand::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->tag([
            ProvisionarRegrasCobranca::class,
            ProvisionarConfiguracaoMonetaria::class,
        ], ProvisionaTenant::ETIQUETA);
        $this->app->tag([DependenciasDePlanosPropina::class], DependenciasRegistadasDoAnoLectivo::ETIQUETA);
        $this->app->tag([PrecosDoCatalogo::class, PrecosDosPlanos::class, PrecosDasMultas::class], FontesDePrecos::ETIQUETA);
        $this->app->tag([PropinasReferenciam::class], ReferenciasFinanceiras::ETIQUETA);
    }
}
