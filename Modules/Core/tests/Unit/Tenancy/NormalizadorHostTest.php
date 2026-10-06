<?php

namespace Modules\Core\Tests\Unit\Tenancy;

use Modules\Core\Tenancy\Support\NormalizadorHost;
use PHPUnit\Framework\TestCase;

class NormalizadorHostTest extends TestCase
{
    public function test_passa_a_minusculas(): void
    {
        $this->assertSame('escola-a.mositec.ao', NormalizadorHost::normalizar('Escola-A.MosiTec.AO'));
    }

    public function test_remove_a_porta(): void
    {
        $this->assertSame('escola-a.localhost', NormalizadorHost::normalizar('escola-a.localhost:8000'));
    }

    public function test_remove_o_ponto_final(): void
    {
        $this->assertSame('escola-a.mositec.ao', NormalizadorHost::normalizar('escola-a.mositec.ao.'));
    }

    public function test_remove_espacos_e_combina_tudo(): void
    {
        $this->assertSame('escola-a.localhost', NormalizadorHost::normalizar('  Escola-A.Localhost.:8000 '));
    }

    public function test_mantem_um_endereco_ip(): void
    {
        $this->assertSame('192.168.1.10', NormalizadorHost::normalizar('192.168.1.10:8080'));
    }
}
