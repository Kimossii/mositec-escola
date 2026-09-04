<?php

namespace Modules\Permissao\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Permissao\Models\Acao;

class AcaoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $acoes = [
            ['nome' => 'ver', 'numero' => 0],
            ['nome' => 'criar', 'numero' => 1],
            ['nome' => 'editar', 'numero' => 2],
            ['nome' => 'eliminar', 'numero' => 3],
            ['nome' => 'listar', 'numero' => 4],
            ['nome' => 'exportar', 'numero' => 5],
        ];

        foreach ($acoes as $acao) {
            Acao::updateOrCreate(
                ['nome' => $acao['nome']],
                ['numero' => $acao['numero']],
            );
        }
    }
}
