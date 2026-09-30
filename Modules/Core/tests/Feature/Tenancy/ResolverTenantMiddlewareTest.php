<?php

namespace Modules\Core\Tests\Feature\Tenancy;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Modules\Core\Tenancy\Enums\EstadoTenant;
use Modules\Core\Tenancy\Http\Middleware\ResolverTenant;
use Modules\Core\Tenancy\TenantContext;
use Tests\TestCase;

class ResolverTenantMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $responder = fn () => app(TenantContext::class)->temTenant()
            ? app(TenantContext::class)->atual()->codigo
            : 'sem-tenant';

        Route::middleware('web')->get('/_tenancy/web', $responder);
        Route::middleware('api')->get('/_tenancy/api', $responder);
    }

    public function test_a_base_de_testes_cria_um_tenant_por_omissao_e_define_o_contexto(): void
    {
        $this->assertSame('MOSI-000001', $this->tenant->codigo);
        $this->assertSame($this->tenant->id, app(TenantContext::class)->id());
    }

    public function test_pedido_web_no_dominio_do_tenant_corre_dentro_desse_tenant(): void
    {
        $this->get('/_tenancy/web')->assertOk()->assertSee('MOSI-000001');
    }

    public function test_pedido_api_tambem_e_resolvido(): void
    {
        $this->getJson('/_tenancy/api')->assertOk()->assertSee('MOSI-000001');
    }

    public function test_cada_dominio_resolve_o_seu_tenant(): void
    {
        $b = $this->criarTenant('MOSI-000002', 'Escola B', 'escola-b.localhost');

        $this->get($this->urlDoTenant($b, '/_tenancy/web'))->assertOk()->assertSee('MOSI-000002');
        $this->get($this->urlDoTenant($this->tenant, '/_tenancy/web'))->assertOk()->assertSee('MOSI-000001');
    }

    public function test_host_desconhecido_da_404(): void
    {
        $this->get('http://nao-existe.localhost/_tenancy/web')->assertNotFound();
        $this->getJson('http://nao-existe.localhost/_tenancy/api')->assertNotFound();
    }

    public function test_tenant_suspenso_da_403(): void
    {
        $this->tenant->update(['estado' => EstadoTenant::SUSPENSO]);

        $this->get('/_tenancy/web')->assertForbidden();
        $this->getJson('/_tenancy/api')->assertForbidden()->assertJsonFragment(['message' => 'Conta suspensa.']);
    }

    public function test_tenant_encerrado_da_404(): void
    {
        $this->tenant->update(['estado' => EstadoTenant::ENCERRADO]);

        $this->get('/_tenancy/web')->assertNotFound();
    }

    public function test_host_central_segue_sem_tenant(): void
    {
        config(['tenancy.hosts_centrais' => ['plataforma.localhost']]);

        $this->get('http://plataforma.localhost/_tenancy/web')->assertOk()->assertSee('sem-tenant');
    }

    public function test_depois_do_pedido_o_contexto_volta_ao_que_estava(): void
    {
        $b = $this->criarTenant('MOSI-000002', 'Escola B', 'escola-b.localhost');

        $this->get($this->urlDoTenant($b, '/_tenancy/web'))->assertSee('MOSI-000002');
        $this->assertSame($this->tenant->id, app(TenantContext::class)->id());

        $this->get('http://nao-existe.localhost/_tenancy/web')->assertNotFound();
        $this->assertSame($this->tenant->id, app(TenantContext::class)->id());
    }

    public function test_sem_contexto_anterior_o_pedido_nao_deixa_tenant_definido(): void
    {
        app(TenantContext::class)->limpar();

        $this->get('/_tenancy/web')->assertSee('MOSI-000001');

        $this->assertFalse(app(TenantContext::class)->temTenant());
    }

    public function test_modo_unico_ignora_o_host(): void
    {
        config(['tenancy.modo' => 'unico']);

        $this->get('http://192.168.1.10/_tenancy/web')->assertOk()->assertSee('MOSI-000001');
    }

    public function test_no_tenant_executa_no_contexto_indicado(): void
    {
        $b = $this->criarTenant('MOSI-000002', 'Escola B', 'escola-b.localhost');

        $codigo = $this->noTenant($b, fn () => app(TenantContext::class)->atual()->codigo);

        $this->assertSame('MOSI-000002', $codigo);
        $this->assertSame($this->tenant->id, app(TenantContext::class)->id());
    }

    public function test_e_middleware_global_para_correr_antes_de_sessao_e_autenticacao_em_todas_as_rotas(): void
    {
        $this->assertTrue(app(Kernel::class)->hasMiddleware(ResolverTenant::class));
    }

    public function test_nenhum_grupo_corre_sessao_ou_autenticacao_antes_do_tenant(): void
    {
        // Como middleware global, corre antes de qualquer middleware de rota,
        // incluindo o EnsureFrontendRequestsAreStateful do Sanctum no grupo api.
        foreach (Route::getRoutes() as $rota) {
            $this->assertNotContains(
                ResolverTenant::class,
                Route::gatherRouteMiddleware($rota),
                "A rota {$rota->uri()} volta a registar o ResolverTenant num grupo; ele é global.",
            );
        }
    }

    public function test_rota_fora_dos_grupos_tambem_exige_tenant(): void
    {
        Route::get('/_tenancy/sem-grupo', fn () => 'ok');

        $this->get('http://nao-existe.localhost/_tenancy/sem-grupo')->assertNotFound();
        $this->get($this->urlDoTenant($this->tenant, '/_tenancy/sem-grupo'))->assertOk();
    }

    public function test_verificacao_de_saude_responde_em_qualquer_host(): void
    {
        $this->get('http://nao-existe.localhost/up')->assertOk();
    }

    public function test_o_pedido_de_teste_comeca_sem_tenant_como_em_producao(): void
    {
        config(['tenancy.hosts_centrais' => ['plataforma.localhost']]);

        // Em host central o middleware não define tenant: se o contexto do
        // teste vazasse para dentro do pedido, a rota veria MOSI-000001.
        $this->get('http://plataforma.localhost/_tenancy/web')->assertSee('sem-tenant');
        $this->assertSame($this->tenant->id, app(TenantContext::class)->id());
    }

    public function test_terminate_repoe_o_contexto_anterior(): void
    {
        $b = $this->criarTenant('MOSI-000002', 'Escola B', 'escola-b.localhost');
        $contexto = app(TenantContext::class);
        $pedido = Request::create($this->urlDoTenant($b, '/qualquer'));
        $dentro = null;

        $resposta = app(ResolverTenant::class)->handle($pedido, function () use ($contexto, &$dentro) {
            $dentro = $contexto->atual()->codigo;

            return new Response('ok');
        });

        $this->assertSame('MOSI-000002', $dentro);
        $this->assertSame('MOSI-000002', $contexto->atual()->codigo, 'Antes do terminate o tenant do pedido continua activo.');

        // O Laravel usa outra instância do middleware para o terminate().
        app(ResolverTenant::class)->terminate($pedido, $resposta);

        $this->assertSame($this->tenant->id, $contexto->id());
    }
}
