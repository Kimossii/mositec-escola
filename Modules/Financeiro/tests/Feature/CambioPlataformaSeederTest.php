<?php

namespace Modules\Financeiro\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Financeiro\Database\Seeders\CambioPlataformaSeeder;
use Tests\TestCase;

class CambioPlataformaSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_o_cambio_padrao_do_kwanza_e_e_idempotente(): void
    {
        $this->seed(CambioPlataformaSeeder::class);
        $this->seed(CambioPlataformaSeeder::class);

        $this->assertSame(1, DB::table('cambios_plataforma')->count());
        $linha = DB::table('cambios_plataforma')->first();
        $this->assertSame('AOA', $linha->moeda_cotada);
        $this->assertSame('USD', $linha->moeda_base);
        $this->assertSame(910_000_000, (int) $linha->taxa);
        $this->assertSame('padrao', $linha->fonte);
        $this->assertSame('2000-01-01', substr((string) $linha->data, 0, 10));
    }

    public function test_voltar_a_semear_nao_sobrescreve_uma_taxa_editada_pelo_operador(): void
    {
        $this->seed(CambioPlataformaSeeder::class);
        DB::table('cambios_plataforma')->update(['taxa' => 925_000_000, 'fonte' => 'manual']);

        $this->seed(CambioPlataformaSeeder::class);

        $linha = DB::table('cambios_plataforma')->first();
        $this->assertSame(925_000_000, (int) $linha->taxa);
        $this->assertSame('manual', $linha->fonte);
    }
}
