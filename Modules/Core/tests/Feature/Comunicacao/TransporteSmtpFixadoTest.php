<?php

namespace Modules\Core\Tests\Feature\Comunicacao;

use Illuminate\Support\Facades\Mail;
use Modules\Core\Comunicacao\Email\TransporteSmtpFixado;
use Tests\TestCase;

/** O transporte liga ao IP validado, mas o certificado continua a ser verificado contra o NOME do servidor. */
class TransporteSmtpFixadoTest extends TestCase
{
    private function config(array $extra = []): array
    {
        return $extra + ['transport' => 'smtp-fixado', 'host' => 'smtp.exemplo.com', 'ip' => '142.250.1.1', 'port' => 587, 'scheme' => 'smtp',
            'username' => 'u', 'password' => 'p', 'timeout' => 9, 'verify_peer' => true, 'require_tls' => true];
    }

    public function test_liga_ao_ip_e_verifica_o_certificado_contra_o_nome(): void
    {
        $transporte = TransporteSmtpFixado::criar($this->config());

        $this->assertSame('smtp://142.250.1.1:587', (string) $transporte);
        $opcoes = $transporte->getStream()->getStreamOptions();
        $this->assertSame('smtp.exemplo.com', $opcoes['ssl']['peer_name']);
        $this->assertArrayNotHasKey('verify_peer', $opcoes['ssl']);
    }

    public function test_sem_ip_fixado_liga_ao_nome(): void
    {
        $transporte = TransporteSmtpFixado::criar($this->config(['ip' => null]));

        $this->assertSame('smtp://smtp.exemplo.com:587', (string) $transporte);
    }

    public function test_ssl_implicito_e_certificado_desligavel_mantem_o_comportamento(): void
    {
        $ssl = TransporteSmtpFixado::criar($this->config(['scheme' => 'smtps', 'port' => 465]));
        $this->assertSame('smtps://142.250.1.1', (string) $ssl);

        $sem = TransporteSmtpFixado::criar($this->config(['verify_peer' => false]));
        $opcoes = $sem->getStream()->getStreamOptions();
        $this->assertFalse($opcoes['ssl']['verify_peer']);
        $this->assertFalse($opcoes['ssl']['verify_peer_name']);
    }

    public function test_esta_registado_como_transporte_do_mailer(): void
    {
        $mailer = Mail::build($this->config());

        $this->assertSame('smtp://142.250.1.1:587', (string) $mailer->getSymfonyTransport());
    }
}
