<?php

namespace Modules\Plataforma\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Modules\Tenant\Exceptions\TenantNaoEncontrado;
use Modules\Tenant\Services\TenantConsultaService;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RouteServiceProvider extends ServiceProvider
{
    protected string $name = 'Plataforma';

    public function boot(): void
    {
        // `{tenant}` resolve-se pelo CÓDIGO da escola, e só nas rotas da Plataforma (nome `plataforma.*`):
        // outra rota com um parâmetro `{tenant}` recebe o texto cru. Inexistente = 404; encerradas contam.
        Route::bind('tenant', function (string $codigo, LaravelRoute $rota) {
            if (! str_starts_with((string) $rota->getName(), 'plataforma.')) {
                return $codigo;
            }

            try {
                return app(TenantConsultaService::class)->porCodigo($codigo);
            } catch (TenantNaoEncontrado) {
                throw new NotFoundHttpException;
            }
        });

        parent::boot();
    }

    /**
     * Só o grupo `plataforma`: nunca `web` nem `api` (mundo da escola). Todas as rotas levam o prefixo `/plataforma`.
     */
    public function map(): void
    {
        Route::middleware('plataforma')->prefix(PlataformaServiceProvider::PREFIXO)->group(module_path($this->name, '/routes/web.php'));
    }
}
