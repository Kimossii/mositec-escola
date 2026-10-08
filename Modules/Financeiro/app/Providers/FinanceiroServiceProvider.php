<?php

namespace Modules\Financeiro\Providers;

use Modules\Core\Tenancy\Contracts\ProvisionaTenant;
use Modules\Financeiro\Console\SincronizarFinanceiroCommand;
use Modules\Financeiro\Provisioning\ProvisionarRegrasCobranca;
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
        SincronizarFinanceiroCommand::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->tag([ProvisionarRegrasCobranca::class], ProvisionaTenant::ETIQUETA);
    }
}
