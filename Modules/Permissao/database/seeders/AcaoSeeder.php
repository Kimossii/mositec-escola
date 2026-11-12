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
            // Acções financeiras: só aparecem na grelha dos módulos que as
            // declaram em Modulo::acoesAplicaveis().
            ['nome' => 'confirmar', 'numero' => 6],
            ['nome' => 'anular', 'numero' => 7],
            ['nome' => 'cancelar', 'numero' => 8],
            ['nome' => 'ajustar', 'numero' => 9],
            ['nome' => 'negociar', 'numero' => 10],
            ['nome' => 'isentar-multa', 'numero' => 11],
            ['nome' => 'trancar', 'numero' => 12],
            ['nome' => 'encerrar', 'numero' => 13],
            // Avaliação (Modulo::NOTA): excepções de prazo e lançamento fora do prazo.
            ['nome' => 'prolongar-prazo', 'numero' => 14],
            ['nome' => 'lancar-fora-prazo', 'numero' => 15],
            ['nome' => 'configurar', 'numero' => 16],
            // Pautas: aprovar (provisório → definitivo) e reabrir uma pauta definitiva.
            ['nome' => 'aprovar', 'numero' => 17],
            ['nome' => 'reabrir', 'numero' => 18],
            // Ver a pauta final de uma turma (o resultado de todos os alunos): permissão própria, à parte de `exportar` (PDF, Excel, boletins).
            ['nome' => 'ver-pauta', 'numero' => 19],
            ['nome' => 'tratar-negativa', 'numero' => 20],
        ];

        foreach ($acoes as $acao) {
            Acao::updateOrCreate(
                ['nome' => $acao['nome']],
                ['numero' => $acao['numero']],
            );
        }
    }
}
