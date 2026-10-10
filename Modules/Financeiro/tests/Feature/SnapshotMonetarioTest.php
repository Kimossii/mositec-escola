<?php

namespace Modules\Financeiro\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Financeiro\Models\CambioPlataforma;
use Modules\Financeiro\Models\ConfiguracaoMonetaria;
use Modules\Financeiro\Services\SnapshotMonetario;
use Modules\Financeiro\Support\TaxaCambio;
use Tests\TestCase;

class SnapshotMonetarioTest extends TestCase
{
    use RefreshDatabase;

    private function em(string $dia): array
    {
        return app(SnapshotMonetario::class)->em(CarbonImmutable::parse($dia));
    }

    public function test_sem_cambio_guarda_a_moeda_e_cambio_nulo(): void
    {
        $this->assertSame(['moeda' => 'AOA', 'cambio_usd' => null], $this->em('2026-10-05'));
    }

    public function test_usa_o_ultimo_cambio_ate_a_data_e_nunca_um_posterior(): void
    {
        CambioPlataforma::create([
            'moeda_cotada' => 'AOA', 'moeda_base' => 'USD', 'data' => '2026-10-01',
            'taxa' => TaxaCambio::deDecimal('910')->micros(), 'fonte' => 'manual',
        ]);

        $this->assertSame(['moeda' => 'AOA', 'cambio_usd' => 910_000_000], $this->em('2026-10-05'));
        $this->assertSame(['moeda' => 'AOA', 'cambio_usd' => null], $this->em('2026-09-30'));
    }

    public function test_escola_em_usd_tem_cambio_um(): void
    {
        ConfiguracaoMonetaria::doTenant()->update(['moeda' => 'USD']);

        $this->assertSame(['moeda' => 'USD', 'cambio_usd' => 1_000_000], $this->em('2026-10-05'));
    }
}
