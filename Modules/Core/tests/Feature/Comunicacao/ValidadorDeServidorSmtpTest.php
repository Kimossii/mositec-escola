<?php

namespace Modules\Core\Tests\Feature\Comunicacao;

use Modules\Core\Comunicacao\Email\ValidadorDeServidorSmtp;
use Tests\TestCase;

class ValidadorDeServidorSmtpTest extends TestCase
{
    private function validador(array $ips = ['142.250.1.1']): ValidadorDeServidorSmtp
    {
        return new ValidadorDeServidorSmtp(fn (string $nome) => $ips === [] ? false : $ips);
    }

    public function test_servidor_publico_na_porta_certa_e_aceite(): void
    {
        $this->assertNull($this->validador()->erro('smtp.gmail.com', 587));
    }

    public function test_porta_fora_da_lista_e_recusada(): void
    {
        $this->assertStringContainsString('porta', mb_strtolower((string) $this->validador()->erro('smtp.gmail.com', 80)));
    }

    public function test_enderecos_privados_loopback_e_link_local_sao_recusados(): void
    {
        foreach (['127.0.0.1', '10.0.0.5', '192.168.1.10', '172.16.0.1', '169.254.169.254', '::1', '100.100.100.200', '198.18.0.1', '64:ff9b::7f00:1', '2002:7f00:1::1'] as $ip) {
            $erro = $this->validador([$ip])->erro('smtp.exemplo.com', 587);
            $this->assertNotNull($erro, "$ip devia ser recusado");
            $this->assertStringContainsString('privado', $erro);
        }
    }

    public function test_literais_localhost_e_ip_privado_directos_sao_recusados(): void
    {
        $real = new ValidadorDeServidorSmtp;
        $this->assertNotNull($real->erro('localhost', 587));
        $this->assertNotNull($real->erro('127.0.0.1', 25));
        $this->assertNotNull($real->erro('10.0.0.5', 25));
        $this->assertNotNull($real->erro('::1', 25));
    }

    public function test_qualquer_ip_privado_entre_os_resolvidos_recusa_o_servidor(): void
    {
        $this->assertNotNull($this->validador(['142.250.1.1', '10.0.0.1'])->erro('smtp.exemplo.com', 587));
    }

    public function test_nome_que_nao_resolve_e_recusado(): void
    {
        $this->assertStringContainsString('resolver', (string) $this->validador([])->erro('nao-existe.invalido', 587));
    }

    public function test_modo_unico_aceita_privados_mas_valida_a_porta(): void
    {
        config(['tenancy.modo' => 'unico']);

        $this->assertNull($this->validador(['10.0.0.5'])->erro('smtp.interno', 587));
        $this->assertNull((new ValidadorDeServidorSmtp)->erro('127.0.0.1', 25));
        $this->assertNotNull($this->validador(['10.0.0.5'])->erro('smtp.interno', 80));
    }

    public function test_validar_devolve_o_ip_a_fixar_resolvendo_uma_so_vez(): void
    {
        $chamadas = 0;
        $validador = new ValidadorDeServidorSmtp(function () use (&$chamadas) {
            $chamadas++;

            return ['142.250.1.1', '142.250.1.2'];
        });

        $this->assertSame([null, '142.250.1.1'], $validador->validar('smtp.gmail.com', 587));
        $this->assertSame(1, $chamadas);
    }

    public function test_validar_nao_fixa_ip_quando_o_servidor_ja_e_um_ip_ou_no_modo_unico(): void
    {
        $this->assertSame([null, null], (new ValidadorDeServidorSmtp)->validar('142.250.1.1', 587));

        config(['tenancy.modo' => 'unico']);
        $this->assertSame([null, null], $this->validador(['10.0.0.5'])->validar('smtp.interno', 587));
    }

    public function test_validar_com_erro_nao_devolve_ip(): void
    {
        [$erro, $ip] = $this->validador(['10.0.0.5'])->validar('smtp.exemplo.com', 587);

        $this->assertNotNull($erro);
        $this->assertNull($ip);
    }
}
