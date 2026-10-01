<?php

namespace Modules\Estabelecimento\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Estabelecimento\Models\Estabelecimento;

/**
 * Estabelecimento mínimo do tenant de desenvolvimento: só o nome, com configurado_em nulo,
 * como o provisioning fará. Corre dentro do contexto de um tenant.
 * Provisório: é substituído pelo provisionador do módulo quando o provisioning existir.
 */
class EstabelecimentoDesenvolvimentoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }

        if (! Estabelecimento::query()->exists()) {
            Estabelecimento::create(['nome' => 'Escola de Desenvolvimento']);
        }
    }
}
