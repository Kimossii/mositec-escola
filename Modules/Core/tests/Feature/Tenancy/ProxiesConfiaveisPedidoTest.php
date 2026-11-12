<?php

namespace Modules\Core\Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Atrás do proxy da plataforma de alojamento, o esquema vem em X-Forwarded-Proto. Sem proxies confiáveis a aplicação julga
 * que o pedido é http e gera redirects http://, que o browser bloqueia num site https.
 */
class ProxiesConfiaveisPedidoTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        TrustProxies::flushState();

        parent::tearDown();
    }

    private function urlGeradoAtrasDoProxy(): string
    {
        Route::get('/_proxy/esquema', fn () => response(url('/destino'), 200, ['Content-Type' => 'text/plain']));

        $resposta = $this->withServerVariables(['REMOTE_ADDR' => '10.66.67.2'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'forjado.exemplo.test'])
            ->get('/_proxy/esquema');

        $resposta->assertOk();

        return $resposta->getContent();
    }

    public function test_sem_proxies_confiaveis_o_esquema_enviado_pelo_proxy_e_ignorado(): void
    {
        $this->assertStringStartsWith('http://', $this->urlGeradoAtrasDoProxy());
    }

    public function test_com_o_proxy_confiavel_o_esquema_e_https_e_o_host_nunca_e_forjado(): void
    {
        TrustProxies::at(['10.0.0.0/8']);
        TrustProxies::withHeaders(Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);

        $url = $this->urlGeradoAtrasDoProxy();

        $this->assertStringStartsWith('https://', $url);
        $this->assertStringNotContainsString('forjado.exemplo.test', $url);
    }
}
