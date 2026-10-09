<?php

namespace Modules\Financeiro\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Financeiro\Models\CambioPlataforma;
use Modules\Financeiro\Support\TaxaCambio;

/**
 * Câmbio por defeito da plataforma: 1 USD = 910,00 AOA, com uma data antiga para valer em
 * qualquer data até a plataforma registar um câmbio real (comando financeiro:cambio-plataforma,
 * e no futuro uma API). É um valor configurável, não um câmbio de mercado. Idempotente e não sobrescreve uma taxa já editada pelo operador.
 */
class CambioPlataformaSeeder extends Seeder
{
    public function run(): void
    {
        CambioPlataforma::firstOrCreate(
            ['moeda_cotada' => 'AOA', 'moeda_base' => 'USD', 'data' => '2000-01-01'],
            ['taxa' => TaxaCambio::deDecimal('910')->micros(), 'fonte' => 'padrao'],
        );
    }
}
