<?php

namespace Modules\Avaliacao\Providers;

use Modules\Core\Providers\BaseModuleServiceProvider;

class AvaliacaoServiceProvider extends BaseModuleServiceProvider
{
    protected string $name = 'Avaliacao';

    protected string $nameLower = 'avaliacao';

    protected array $providers = [
        RouteServiceProvider::class,
    ];
}
