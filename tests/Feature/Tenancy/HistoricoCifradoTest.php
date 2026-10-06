<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Http\Request;
use Modules\Core\Support\HistoricoCifrado;
use Tests\TestCase;

/**
 * O Inertia cifra o histórico com `crypto.subtle`, que o browser só expõe em contexto seguro (HTTPS).
 * Em HTTP, `encryptHistory` lança "Unable to encrypt history" e a visita nunca termina (o botão fica
 * em "Aguarde..." para sempre, embora o servidor já tenha feito a operação). Por isso a cifra só se
 * pede quando há HTTPS: pedido seguro, ou `session.secure` ligado (o requisito de produção).
 */
class HistoricoCifradoTest extends TestCase
{
    public function test_em_http_sem_cookie_seguro_nao_se_pede_a_cifra(): void
    {
        config(['session.secure' => false]);

        $this->assertFalse(HistoricoCifrado::possivel(Request::create('http://escola.test/x')));
    }

    public function test_em_https_pede_se_a_cifra(): void
    {
        config(['session.secure' => false]);

        $this->assertTrue(HistoricoCifrado::possivel(Request::create('https://escola.test/x')));
    }

    public function test_com_cookie_seguro_ligado_pede_se_a_cifra_mesmo_atras_de_um_proxy_que_termina_o_tls(): void
    {
        config(['session.secure' => true]);

        $this->assertTrue(HistoricoCifrado::possivel(Request::create('http://escola.test/x')));
    }

    public function test_valor_nulo_ou_vazio_de_session_secure_conta_como_desligado(): void
    {
        config(['session.secure' => null]);
        $this->assertFalse(HistoricoCifrado::possivel(Request::create('http://escola.test/x')));
    }
}
