<?php

namespace Modules\Portal\Providers;

use Modules\Core\Providers\BaseModuleServiceProvider;
use Modules\Portal\Http\Middleware\EducandoDoEncarregado;

class PortalServiceProvider extends BaseModuleServiceProvider
{
    protected string $name = 'Portal';

    protected string $nameLower = 'portal';

    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        // `portal.educando`: toda a rota com `{educando}` passa por aqui (ponto único de acesso aos educandos).
        $this->app['router']->aliasMiddleware('portal.educando', EducandoDoEncarregado::class);
    }
}
