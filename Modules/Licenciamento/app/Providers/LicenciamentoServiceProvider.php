<?php

namespace Modules\Licenciamento\Providers;

use Modules\Core\Licenciamento\Contracts\GarantiaDeLimites;
use Modules\Core\Licenciamento\Contracts\ModulosDisponiveis;
use Modules\Core\Licenciamento\Contracts\RegrasDeEmailDaLicenca;
use Modules\Core\Providers\BaseModuleServiceProvider;
use Modules\Licenciamento\Console\EmitirTransicaoCommand;
use Modules\Licenciamento\Console\EscolasSemLicencaCommand;
use Modules\Licenciamento\Console\GerarChavesCommand;
use Modules\Licenciamento\Assinatura\CodecoDeLicenca;
use Modules\Licenciamento\Assinatura\CodecoPasetoV4Publico;
use Modules\Licenciamento\Services\DireitosService;
use Modules\Licenciamento\Services\LimitesDeLicenca;
use Modules\Licenciamento\Services\ModulosContratados;
use Modules\Licenciamento\Services\RegrasDeEmailService;

class LicenciamentoServiceProvider extends BaseModuleServiceProvider
{
    protected string $name = 'Licenciamento';

    protected string $nameLower = 'licenciamento';

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
        GerarChavesCommand::class,
        EscolasSemLicencaCommand::class,
        EmitirTransicaoCommand::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->app->bind(CodecoDeLicenca::class, CodecoPasetoV4Publico::class);
        // `scoped`, não `singleton`: captura o TenantContext (também scoped) e tem de ser refeito a cada pedido/job (workers, Octane).
        // CodigoDaInstalacao e ChavesPublicas não guardam estado (leem sempre da BD/config), por isso não precisam de registo.
        $this->app->scoped(DireitosService::class);
        // Sobrepõe o binding nulo do Core (bindIf lá; qualquer ordem de registo dá este).
        $this->app->scoped(GarantiaDeLimites::class, LimitesDeLicenca::class);
        $this->app->scoped(ModulosDisponiveis::class, ModulosContratados::class);
        $this->app->scoped(RegrasDeEmailDaLicenca::class, RegrasDeEmailService::class);
    }
}
