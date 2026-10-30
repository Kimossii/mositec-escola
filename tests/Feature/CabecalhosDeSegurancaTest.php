<?php

namespace Tests\Feature;

use App\Http\Middleware\CabecalhosDeSeguranca;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

class CabecalhosDeSegurancaTest extends TestCase
{
    private function passar(Request $pedido, ?Response $resposta = null): Response
    {
        return (new CabecalhosDeSeguranca())->handle($pedido, fn () => $resposta ?? new Response('ok'));
    }

    public function test_cabecalhos_simples_vao_sempre_mesmo_em_http(): void
    {
        $r = $this->passar(Request::create('http://escola.test/'));

        $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $r->headers->get('X-Frame-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $r->headers->get('Referrer-Policy'));
        $this->assertNotNull($r->headers->get('Permissions-Policy'));
    }

    public function test_hsts_nao_vai_enquanto_o_interruptor_estiver_desligado(): void
    {
        config(['seguranca.forcar_https' => false]);

        $this->assertNull($this->passar(Request::create('https://escola.test/'))->headers->get('Strict-Transport-Security'));
    }

    public function test_hsts_so_vai_com_interruptor_ligado_e_pedido_https(): void
    {
        config(['seguranca.forcar_https' => true, 'seguranca.hsts_max_age' => 3600]);

        $this->assertSame('max-age=3600', $this->passar(Request::create('https://escola.test/'))->headers->get('Strict-Transport-Security'));
        $this->assertNull($this->passar(Request::create('http://escola.test/'))->headers->get('Strict-Transport-Security'));
    }

    public function test_cabecalho_ja_definido_pelo_controller_nao_e_sobrescrito(): void
    {
        $resposta = new Response('ok');
        $resposta->headers->set('X-Frame-Options', 'DENY');

        $this->assertSame('DENY', $this->passar(Request::create('http://escola.test/'), $resposta)->headers->get('X-Frame-Options'));
    }

    public function test_csp_report_only_so_com_interruptor(): void
    {
        config(['seguranca.csp_report_only' => false]);
        $this->assertNull($this->passar(Request::create('http://escola.test/'))->headers->get('Content-Security-Policy-Report-Only'));

        config(['seguranca.csp_report_only' => true]);
        $this->assertNotNull($this->passar(Request::create('http://escola.test/'))->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_sessao_so_fica_secure_quando_o_interruptor_https_estiver_ligado(): void
    {
        $this->assertFalse((bool) config('session.secure'), 'Em HTTP interno o cookie não pode ser secure, senão o login deixa de funcionar.');
    }
}
