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

    /** @return array<string, Route> as rotas `plataforma.*` (nome) */
    private function rotasNomeadasDaPlataforma(): array
    {
        $rotas = [];

        foreach (RouteFacade::getRoutes()->getRoutes() as $rota) {
            if (str_starts_with((string) $rota->getName(), 'plataforma.')) {
                $rotas[$rota->getName()] = $rota;
            }
        }

        return $rotas;
    }

    private function nomesEfectivos(Route $rota): array
    {
        return array_map(
            fn ($m) => is_string($m) ? $m : 'closure',
            app('router')->gatherRouteMiddleware($rota),
        );
    }

    public function test_nenhuma_rota_plataforma_tem_middleware_de_tenancy_e_todas_comecam_por_apenas_host_central(): void
    {
        $rotas = $this->rotasNomeadasDaPlataforma();
        $this->assertGreaterThanOrEqual(15, count($rotas), 'Esperavam-se todas as rotas do painel.');

        // Nenhuma rota do módulo fica sem nome (escaparia a este teste).
        foreach (RouteFacade::getRoutes()->getRoutes() as $rota) {
            if ($this->doModuloPlataforma($rota)) {
                $this->assertArrayHasKey((string) $rota->getName(), $rotas, 'Rota da Plataforma sem nome plataforma.*: ' . $rota->uri());
            }
        }

        $proibidos = ['VerificarTenantDaSessao', 'ExigirTenantNoContexto', 'ExigirConfiguracaoInicial', 'ExigirTrocaDeSenha', 'HandleInertiaRequests', 'ResolverTenant'];

        foreach ($rotas as $nome => $rota) {
            $declarado = $rota->gatherMiddleware();
            $this->assertNotContains('web', $declarado, $nome);
            $this->assertNotContains('api', $declarado, $nome);

            $efectivo = $this->nomesEfectivos($rota);
            $this->assertSame('ApenasHostCentral', class_basename(explode(':', $efectivo[0])[0]), "{$nome}: ApenasHostCentral tem de ser o primeiro.");

            foreach ([...$declarado, ...$efectivo] as $middleware) {
                $classe = class_basename(explode(':', (string) (is_string($middleware) ? $middleware : 'closure'))[0]);
                // `ExigirTrocaDeSenhaPlataforma` é o da Plataforma; `ExigirTrocaDeSenha` é o da escola.
                $this->assertNotContains($classe, $proibidos, "{$nome}: middleware de tenancy ({$classe}).");
            }
        }
    }

    public function test_as_rotas_autenticadas_tem_a_cadeia_de_autenticacao_pela_ordem_e_as_publicas_sao_so_o_login(): void
    {
        $rotas = $this->rotasNomeadasDaPlataforma();
        $publicas = [];

        foreach ($rotas as $nome => $rota) {
            $efectivo = array_map(fn (string $m) => class_basename(explode(':', $m)[0]) . (str_contains($m, ':') ? ':' . explode(':', $m, 2)[1] : ''), $this->nomesEfectivos($rota));

            if (! in_array('Authenticate:plataforma', $efectivo, true)) {
                $publicas[] = $nome;

                continue;
            }

            $posicoes = [];
            foreach (['Authenticate:plataforma', 'SuperAdminActivo', 'ExigirTrocaDeSenhaPlataforma'] as $esperado) {
                $posicao = array_search($esperado, $efectivo, true);
                $this->assertNotFalse($posicao, "{$nome}: falta {$esperado} em " . implode(', ', $efectivo));
                $posicoes[] = $posicao;
            }
            $ordenadas = $posicoes;
            sort($ordenadas);
            $this->assertSame($ordenadas, $posicoes, "{$nome}: ordem errada em " . implode(', ', $efectivo));
            // E tudo isto depois do que prepara a sessão.
            $this->assertGreaterThan(array_search('StartSession', $efectivo, true), $posicoes[0], $nome);
        }

        sort($publicas);
        $this->assertSame(['plataforma.login', 'plataforma.login.store'], $publicas, 'As únicas rotas públicas são o login (GET e POST).');
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
