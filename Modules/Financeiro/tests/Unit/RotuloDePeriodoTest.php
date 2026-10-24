<?php

namespace Modules\Financeiro\Tests\Unit;

use Modules\Financeiro\Support\RotuloDePeriodo;
use PHPUnit\Framework\TestCase;

class RotuloDePeriodoTest extends TestCase
{
    public function test_competencia(): void
    {
        $this->assertSame('Set/2026', RotuloDePeriodo::competencia(2026, 9));
        $this->assertSame('Jan/2027', RotuloDePeriodo::competencia(2027, 1));
    }

    public function test_periodos_de_um_mes_do_mesmo_ano_e_a_atravessar_o_ano(): void
    {
        $this->assertSame('Set/2026', RotuloDePeriodo::periodo('2026-09-01', '2026-09-30'));
        $this->assertSame('Set–Nov/2026', RotuloDePeriodo::periodo('2026-09-01', '2026-11-30'));
        $this->assertSame('Dez/2026–Fev/2027', RotuloDePeriodo::periodo('2026-12-01', '2027-02-28'));
    }
}
