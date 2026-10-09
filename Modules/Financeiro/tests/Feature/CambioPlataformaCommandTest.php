<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CambioPlataformaCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_regista_o_cambio_do_dia_com_a_data_indicada(): void
    {
        $this->artisan('financeiro:cambio-plataforma', ['moeda' => 'AOA', 'taxa' => '915,50', '--data' => '2026-10-10'])
            ->assertSuccessful();

        $linha = DB::table('cambios_plataforma')->first();
        $this->assertSame('AOA', $linha->moeda_cotada);
        $this->assertSame('USD', $linha->moeda_base);
        $this->assertSame('2026-10-10', substr((string) $linha->data, 0, 10));
        $this->assertSame(915_500_000, (int) $linha->taxa);
        $this->assertSame('manual', $linha->fonte);
    }

    public function test_sem_data_usa_hoje_e_actualiza_a_linha_do_mesmo_dia(): void
    {
        $this->artisan('financeiro:cambio-plataforma', ['moeda' => 'AOA', 'taxa' => '910'])->assertSuccessful();
        $this->artisan('financeiro:cambio-plataforma', ['moeda' => 'AOA', 'taxa' => '920', '--fonte' => 'api'])->assertSuccessful();

        $this->assertSame(1, DB::table('cambios_plataforma')->count());
        $linha = DB::table('cambios_plataforma')->first();
        $this->assertSame(920_000_000, (int) $linha->taxa);
        $this->assertSame('api', $linha->fonte);
        $this->assertSame(now()->toDateString(), substr((string) $linha->data, 0, 10));
    }

    public function test_rejeita_entradas_invalidas_sem_gravar(): void
    {
        $this->artisan('financeiro:cambio-plataforma', ['moeda' => 'XXX', 'taxa' => '910'])->assertFailed();
        $this->artisan('financeiro:cambio-plataforma', ['moeda' => 'AOA', 'taxa' => '0'])->assertFailed();
        $this->artisan('financeiro:cambio-plataforma', ['moeda' => 'AOA', 'taxa' => '910', '--data' => 'abc'])->assertFailed();
        $this->artisan('financeiro:cambio-plataforma', ['moeda' => 'AOA', 'taxa' => '910', '--fonte' => 'xyz'])->assertFailed();
        $this->artisan('financeiro:cambio-plataforma', ['moeda' => 'USD', 'taxa' => '1'])->assertFailed();

        $this->assertSame(0, DB::table('cambios_plataforma')->count());
    }
}
