<?php

namespace Modules\Plataforma\Tests\Feature;

use Closure;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Modules\Plataforma\Providers\PlataformaServiceProvider;
use ReflectionClass;
use ReflectionFunction;
use Tests\TestCase;

/**
 * Arquitectura das rotas: os dois mundos nunca se misturam nos grupos de middleware.
 */
class FronteiraRotasTest extends TestCase
{
    /** Ficheiro onde a rota foi declarada (closure ou controller). */
    private function ficheiroDe(Route $rota): ?string
    {
        $uses = $rota->getAction('uses');

        if ($uses instanceof Closure) {
            return (new ReflectionFunction($uses))->getFileName() ?: null;
        }

        if (is_string($uses)) {
            $classe = explode('@', $uses)[0];

            return class_exists($classe) ? (new ReflectionClass($classe))->getFileName() : null;
        }

        return null;
    }

    private function doModuloPlataforma(Route $rota): bool
    {
        return str_contains(str_replace('\\', '/', (string) $this->ficheiroDe($rota)), '/Modules/Plataforma/');
    }

    public function test_nenhuma_rota_da_plataforma_colide_com_uma_rota_da_escola(): void
    {
        $daPlataforma = [];
        $daEscola = [];

        foreach (RouteFacade::getRoutes()->getRoutes() as $rota) {
            foreach ($rota->methods() as $metodo) {
                if ($this->doModuloPlataforma($rota)) {
                    $daPlataforma[$metodo . ' /' . ltrim($rota->uri(), '/')] = true;
                } else {
                    $daEscola[$metodo . ' /' . ltrim($rota->uri(), '/')] = true;
                }
            }
        }

        $this->assertNotEmpty($daPlataforma);
        $this->assertNotEmpty($daEscola);
        $this->assertSame([], array_keys(array_intersect_key($daPlataforma, $daEscola)), 'Método + caminho partilhado entre os dois mundos: o Laravel só guarda uma das rotas.');
        // Controlo positivo: a escola continua com `/` e `/login`.
        $this->assertArrayHasKey('GET /', $daEscola);
        $this->assertArrayHasKey('GET /login', $daEscola);
    }

    public function test_todas_as_rotas_da_plataforma_levam_o_prefixo(): void
    {
        $this->assertSame('plataforma', PlataformaServiceProvider::PREFIXO);

        foreach (RouteFacade::getRoutes()->getRoutes() as $rota) {
            if ($this->doModuloPlataforma($rota)) {
                $this->assertMatchesRegularExpression('#^plataforma(/|$)#', $rota->uri());
            }
        }

        $this->assertSame('/plataforma', route('plataforma.inicio', absolute: false));
    }

    public function test_nenhuma_rota_do_modulo_plataforma_tem_o_grupo_web_ou_api(): void
    {
        $rotasDaPlataforma = 0;

        foreach (RouteFacade::getRoutes()->getRoutes() as $rota) {
            if (! $this->doModuloPlataforma($rota)) {
                continue;
            }

            $rotasDaPlataforma++;
            $middleware = $rota->gatherMiddleware();

            $this->assertContains('plataforma', $middleware, $rota->uri());
            $this->assertNotContains('web', $middleware, $rota->uri());
            $this->assertNotContains('api', $middleware, $rota->uri());
        }

        $this->assertGreaterThan(0, $rotasDaPlataforma, 'O módulo Plataforma não registou rotas.');
    }

    public function test_nenhuma_rota_fora_do_modulo_tem_o_grupo_plataforma(): void
    {
        $outras = 0;

        foreach (RouteFacade::getRoutes()->getRoutes() as $rota) {
            if ($this->doModuloPlataforma($rota)) {
                continue;
            }

            $outras++;
            $this->assertNotContains('plataforma', $rota->gatherMiddleware(), $rota->uri());
        }

        $this->assertGreaterThan(0, $outras);
    }

    public function test_o_grupo_plataforma_nunca_inclui_middleware_da_escola(): void
    {
        $grupo = app('router')->getMiddlewareGroups()['plataforma'] ?? null;

        $this->assertIsArray($grupo, 'O grupo de middleware `plataforma` não existe.');
        $this->assertSame('ApenasHostCentral', class_basename($grupo[0]), 'ApenasHostCentral tem de ser o primeiro.');

        foreach ($grupo as $middleware) {
            $nome = class_basename((string) $middleware);
            foreach (['VerificarTenantDaSessao', 'ExigirTrocaDeSenha', 'ExigirConfiguracaoInicial', 'HandleInertiaRequests', 'ExigirTenantNoContexto', 'ResolverTenant'] as $proibido) {
                $this->assertNotSame($proibido, $nome);
            }
        }

        $this->assertSame(
            ['ApenasHostCentral', 'ConfigurarSessaoPlataforma', 'EncryptCookies', 'AddQueuedCookiesToResponse', 'StartSession', 'ShareErrorsFromSession', 'ValidateCsrfToken', 'SubstituteBindings', 'HandleInertiaPlataforma'],
            array_map(fn ($m) => class_basename((string) $m), $grupo),
        );
    }

    public function test_os_grupos_web_e_api_comecam_por_exigir_tenant_no_contexto(): void
    {
        foreach (['web', 'api'] as $grupo) {
            $middleware = app('router')->getMiddlewareGroups()[$grupo];

            $this->assertSame('ExigirTenantNoContexto', class_basename((string) $middleware[0]), "Grupo {$grupo}");
        }
    }
}
