<?php

namespace Modules\Core\Providers;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;
use Modules\Core\Comunicacao\Email\TransporteSmtpFixado;
use Modules\Core\Console\FilasEstadoCommand;
use Modules\Core\Importacao\RegistoDeImportadores;
use Modules\Core\Contracts\ConsultaChamadasPendentes;
use Modules\Core\Contracts\ConsultaFaltasDoAluno;
use Modules\Core\Contracts\IdentificaAdministradorDaEscola;
use Modules\Core\Contracts\NinguemEAdministrador;
use Modules\Core\Support\SemFrequencia\FaltasDoAlunoVazias;
use Modules\Core\Support\SemFrequencia\SemChamadasPendentes;
use Modules\Core\Licenciamento\Contracts\GarantiaDeLimites;
use Modules\Core\Licenciamento\Contracts\ModulosDisponiveis;
use Modules\Core\Licenciamento\Contracts\SemLimites;
use Modules\Core\Licenciamento\Contracts\TodosOsModulos;
use Modules\Core\Console\LimparExportacoesCommand;
use Modules\Core\Console\PodarEnviosEmailCommand;
use Modules\Core\Tenancy\Support\SessaoDatabaseSemTenantSegura;
use Modules\Core\Providers\RouteServiceProvider;
use Modules\Core\Support\PesquisaTexto;
use Modules\Core\Support\SaudeDoSistema;
use Modules\Core\Tenancy\Jobs\CapturaTenantNoPayload;
use Modules\Core\Tenancy\Provisioning\ColectorDeCredenciais;
use Modules\Core\Tenancy\TenantContext;
use Modules\Core\Tenancy\Validation\VerificadorPresencaTenant;

class CoreServiceProvider extends BaseModuleServiceProvider
{
    protected string $name = 'Core';

    protected string $nameLower = 'core';

    /**
     * @var string[]
     */
    protected array $commands = [
        FilasEstadoCommand::class,
        LimparExportacoesCommand::class,
        PodarEnviosEmailCommand::class,
    ];

    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->scoped(TenantContext::class);
        $this->app->singleton(RegistoDeImportadores::class);
        $this->app->scoped(ColectorDeCredenciais::class);
        // Sem o módulo Licenciamento não há limites; ele sobrepõe este binding (bindIf: vale qualquer ordem de registo).
        $this->app->bindIf(GarantiaDeLimites::class, SemLimites::class);
        $this->app->bindIf(ModulosDisponiveis::class, TodosOsModulos::class);
        // Sem o módulo Frequencia não há faltas nem chamadas por fazer; ele sobrepõe estes bindings (qualquer ordem de registo).
        $this->app->bindIf(ConsultaFaltasDoAluno::class, FaltasDoAlunoVazias::class);
        $this->app->bindIf(ConsultaChamadasPendentes::class, SemChamadasPendentes::class);
        // O Permissao sobrepõe este binding; sem ele ninguém é administrador (em vez de falhar cada pedido web).
        $this->app->bindIf(IdentificaAdministradorDaEscola::class, NinguemEAdministrador::class);

        // O TenantContext é resolvido dentro do verificador a cada consulta,
        // porque o verificador é singleton e o contexto tem âmbito de pedido.
        $this->app->extend('validation.presence', fn ($verificador, $app) => new VerificadorPresencaTenant($app['db']));
    }

    public function boot(): void
    {
        parent::boot();

        // Transporte SMTP que liga ao IP já validado (anti DNS rebinding) mas verifica o certificado contra o nome.
        Mail::extend(TransporteSmtpFixado::NOME, fn (array $config) => TransporteSmtpFixado::criar($config));

        PesquisaTexto::registar();
        SaudeDoSistema::registar();
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
