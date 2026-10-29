<?php

namespace Modules\Core\Providers;

use Illuminate\Support\Facades\Session;
use Modules\Core\Console\FilasEstadoCommand;
use Modules\Core\Console\LimparExportacoesCommand;
use Modules\Core\Tenancy\Support\SessaoDatabaseSemTenantSegura;
use Nwidart\Modules\Support\ModuleServiceProvider;
use Modules\Core\Providers\RouteServiceProvider;
use Modules\Core\Support\PesquisaTexto;
use Modules\Core\Tenancy\Jobs\CapturaTenantNoPayload;
use Modules\Core\Tenancy\Provisioning\ColectorDeCredenciais;
use Modules\Core\Tenancy\TenantContext;
use Modules\Core\Tenancy\Validation\VerificadorPresencaTenant;

class CoreServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Core';

    protected string $nameLower = 'core';

    /**
     * @var string[]
     */
    protected array $commands = [
        FilasEstadoCommand::class,
        LimparExportacoesCommand::class,
    ];

    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->scoped(TenantContext::class);
        $this->app->scoped(ColectorDeCredenciais::class);

        // O TenantContext é resolvido dentro do verificador a cada consulta,
        // porque o verificador é singleton e o contexto tem âmbito de pedido.
        $this->app->extend('validation.presence', fn ($verificador, $app) => new VerificadorPresencaTenant($app['db']));
    }

    public function boot(): void
    {
        parent::boot();

        PesquisaTexto::registar();
        CapturaTenantNoPayload::registar();

        // Mesmo driver `database` do Laravel, com o handler que não falha sem tenant (ver a classe). O gestor monta a Store
        // à volta do handler devolvido; a ligação à BD vem de um método protegido, daí o `Closure::call` no âmbito do gestor.
        Session::extend('database', fn ($app) => new SessaoDatabaseSemTenantSegura(
            (fn () => $this->getDatabaseConnection())->call($app['session']),
            $app['config']['session.table'],
            $app['config']['session.lifetime'],
            $app,
        ));
    }
}
