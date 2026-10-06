<?php

namespace Modules\Plataforma\Providers;

use Modules\Plataforma\Console\CriarSuperAdminCommand;
use Modules\Plataforma\Console\RedefinirSuperAdminCommand;
use Modules\Plataforma\Support\LimitadorLoginPlataforma;
use Nwidart\Modules\Support\ModuleServiceProvider;

class PlataformaServiceProvider extends ModuleServiceProvider
{
    /**
     * Prefixo de caminho de todas as rotas do painel. Elimina a colisão com as rotas da escola
     * (`/`, `/login`...): o Laravel indexa as rotas por método + caminho, sem olhar ao host.
     * A barreira real de host continua a ser o ApenasHostCentral.
     */
    public const PREFIXO = 'plataforma';

    protected string $name = 'Plataforma';

    protected string $nameLower = 'plataforma';

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
        CriarSuperAdminCommand::class,
        RedefinirSuperAdminCommand::class,
    ];

    public function boot(): void
    {
        parent::boot();

        LimitadorLoginPlataforma::definir();
    }
}
