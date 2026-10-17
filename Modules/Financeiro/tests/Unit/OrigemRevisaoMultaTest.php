<?php

namespace Modules\Financeiro\Tests\Unit;

use Modules\Financeiro\Enums\OrigemRevisaoMulta;
use PHPUnit\Framework\TestCase;

class OrigemRevisaoMultaTest extends TestCase
{
    public function test_valores_e_rotulos_sao_os_do_spec(): void
    {
        $this->assertSame(0, OrigemRevisaoMulta::AUTOMATICA->value);
        $this->assertSame(1, OrigemRevisaoMulta::MANUAL->value);

        $this->assertSame('Automática', OrigemRevisaoMulta::AUTOMATICA->label());
        $this->assertSame('Manual', OrigemRevisaoMulta::MANUAL->label());
    }
}
