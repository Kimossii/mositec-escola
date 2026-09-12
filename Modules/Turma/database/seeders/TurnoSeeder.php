<?php

namespace Modules\Turma\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Turma\Models\Turno;

class TurnoSeeder extends Seeder
{
    public function run(): void
    {
        $estabelecimentos = Estabelecimento::query()->get();

        $turnos = [
            [
                'nome' => 'Manhã',
                'descricao' => 'Turno da manhã.',
            ],
            [
                'nome' => 'Tarde',
                'descricao' => 'Turno da tarde.',
            ],
            [
                'nome' => 'Noite',
                'descricao' => 'Turno da noite.',
            ],
        ];

        foreach ($estabelecimentos as $estabelecimento) {
            foreach ($turnos as $turno) {
                Turno::query()->firstOrCreate(
                    [
                        'estabelecimento_id' => $estabelecimento->id,
                        'nome' => $turno['nome'],
                    ],
                    [
                        'descricao' => $turno['descricao'],
                        'estado' => 1,
                        'estado_descricao' => 'Ativo',
                    ]
                );
            }
        }
    }
}
