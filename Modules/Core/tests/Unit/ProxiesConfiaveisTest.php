<?php

namespace Modules\Core\Tests\Unit;

use Modules\Core\Tenancy\Support\ProxiesConfiaveis;
use PHPUnit\Framework\TestCase;

class ProxiesConfiaveisTest extends TestCase
{
    public function test_vazio_ou_ausente_nao_confia_em_ninguem(): void
    {
        $this->assertNull(ProxiesConfiaveis::dasVariaveis(null));
        $this->assertNull(ProxiesConfiaveis::dasVariaveis(''));
        $this->assertNull(ProxiesConfiaveis::dasVariaveis('   '));
        $this->assertNull(ProxiesConfiaveis::dasVariaveis(' , , '));
    }

    public function test_asterisco_confia_em_todos(): void
    {
        $this->assertSame('*', ProxiesConfiaveis::dasVariaveis('*'));
        $this->assertSame('*', ProxiesConfiaveis::dasVariaveis(' * '));
    }

    public function test_lista_de_ips_e_redes_separados_por_virgulas(): void
    {
        $this->assertSame(['10.0.0.0/8'], ProxiesConfiaveis::dasVariaveis('10.0.0.0/8'));
        $this->assertSame(['10.0.0.0/8', '172.16.0.5'], ProxiesConfiaveis::dasVariaveis(' 10.0.0.0/8 , 172.16.0.5,'));
    }
}
