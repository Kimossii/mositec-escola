<?php

namespace Modules\Tenant\Providers;

use InvalidArgumentException;
use Modules\Core\Tenancy\Contracts\CatalogoDeTenants;
use Modules\Core\Tenancy\Contracts\ResolvedorTenant;
use Modules\Tenant\Console\AdicionarDominioCommand;
use Modules\Tenant\Console\CriarTenantCommand;
use Modules\Tenant\Console\DefinirDominioPrincipalCommand;
use Modules\Tenant\Console\EncerrarTenantCommand;
use Modules\Tenant\Console\ReactivarTenantCommand;
use Modules\Tenant\Console\RemoverDominioCommand;
use Modules\Tenant\Console\RevogarAcessosCommand;
use Modules\Tenant\Console\SuspenderTenantCommand;
use Modules\Tenant\Services\CatalogoDeTenantsEloquent;
use Modules\Tenant\Services\ResolvedorTenantPorDominio;
use Modules\Tenant\Services\ResolvedorTenantUnico;
use Nwidart\Modules\Support\ModuleServiceProvider;

class TenantServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Tenant';

    protected string $nameLower = 'tenant';

    /**
     * @var string[]
     */
    protected array $commands = [
        CriarTenantCommand::class,
        SuspenderTenantCommand::class,
        ReactivarTenantCommand::class,
        EncerrarTenantCommand::class,
        AdicionarDominioCommand::class,
        RemoverDominioCommand::class,
        DefinirDominioPrincipalCommand::class,
        RevogarAcessosCommand::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->bind(CatalogoDeTenants::class, CatalogoDeTenantsEloquent::class);

        // bind (e não singleton): o modo é lido a cada resolução.
        $this->app->bind(ResolvedorTenant::class, function ($app) {
            $modo = config('tenancy.modo');

            return match ($modo) {
                'dominio' => $app->make(ResolvedorTenantPorDominio::class),
                'unico' => $app->make(ResolvedorTenantUnico::class),
                default => throw new InvalidArgumentException("Modo de tenancy desconhecido: '{$modo}'. Use 'dominio' ou 'unico'."),
            };
        });
    }
}
