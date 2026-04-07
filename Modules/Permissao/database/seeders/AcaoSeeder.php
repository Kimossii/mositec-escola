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
        // $this->call([]);
        Acao::truncate();
        $now = now();
        Acao::insert([
            ['nome' => 'ver', 'numero' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['nome' => 'criar', 'numero' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['nome' => 'editar', 'numero' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['nome' => 'eliminar', 'numero' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['nome' => 'listar', 'numero' => 4, 'created_at' => $now, 'updated_at' => $now],
            ['nome' => 'exportar', 'numero' => 5, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }
}
