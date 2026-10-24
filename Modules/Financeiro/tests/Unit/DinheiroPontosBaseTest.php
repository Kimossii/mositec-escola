<?php

namespace Modules\Financeiro\Tests\Unit;

use Modules\Financeiro\Support\Dinheiro;
use PHPUnit\Framework\TestCase;

class DinheiroPontosBaseTest extends TestCase
{
    public function test_pontos_base_arredonda_para_baixo(): void
    {
        $this->assertSame(25_000, Dinheiro::deUnidadesMenores(2_500_000)->pontosBase(100)->unidadesMenores()); // 1%
        $this->assertSame(62_500, Dinheiro::deUnidadesMenores(2_500_000)->pontosBase(250)->unidadesMenores()); // 2,5%
        $this->assertSame(1, Dinheiro::deUnidadesMenores(123)->pontosBase(100)->unidadesMenores()); // 1,23% → 1
        $this->assertSame(0, Dinheiro::deUnidadesMenores(300)->pontosBase(1)->unidadesMenores()); // 0,03% → 0
    }

    public function test_pontos_base_limites(): void
    {
        $this->assertSame(2_500_000, Dinheiro::deUnidadesMenores(2_500_000)->pontosBase(10_000)->unidadesMenores()); // 100%
        $this->assertSame(0, Dinheiro::deUnidadesMenores(2_500_000)->pontosBase(0)->unidadesMenores());

        $this->expectException(\InvalidArgumentException::class);
        Dinheiro::deUnidadesMenores(1)->pontosBase(10_001);
    }
}
