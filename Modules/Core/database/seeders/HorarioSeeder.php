<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Horario;

class HorarioSeeder extends Seeder
{
    public function run(): void
    {
        $horarios = [
            [
                'nome' => 'Período 1',
                'hora_inicio' => '07:30',
                'hora_fim' => '08:15',
                'tipo' => 2,
                'tipo_descricao' => 'Tempo',
            ],
            [
                'nome' => 'Período 2',
                'hora_inicio' => '08:15',
                'hora_fim' => '09:00',
                'tipo' => 2,
                'tipo_descricao' => 'Tempo',
            ],
            [
                'nome' => 'Período 3',
                'hora_inicio' => '09:15',
                'hora_fim' => '10:00',
                'tipo' => 2,
                'tipo_descricao' => 'Tempo',
            ],
            [
                'nome' => 'Período 4',
                'hora_inicio' => '10:00',
                'hora_fim' => '10:45',
                'tipo' => 2,
                'tipo_descricao' => 'Tempo',
            ],
            [
                'nome' => 'Período 5',
                'hora_inicio' => '11:00',
                'hora_fim' => '11:45',
                'tipo' => 2,
                'tipo_descricao' => 'Tempo',
            ],
            [
                'nome' => 'Período 6',
                'hora_inicio' => '11:45',
                'hora_fim' => '12:30',
                'tipo' => 2,
                'tipo_descricao' => 'Tempo',
            ],
            [
                'nome' => 'Período 7',
                'hora_inicio' => '13:30',
                'hora_fim' => '14:15',
                'tipo' => 2,
                'tipo_descricao' => 'Tempo',
            ],
            [
                'nome' => 'Período 8',
                'hora_inicio' => '14:15',
                'hora_fim' => '15:00',
                'tipo' => 2,
                'tipo_descricao' => 'Tempo',
            ],
            [
                'nome' => 'Período 9',
                'hora_inicio' => '15:15',
                'hora_fim' => '16:00',
                'tipo' => 2,
                'tipo_descricao' => 'Tempo',
            ],
            [
                'nome' => 'Período 10',
                'hora_inicio' => '16:00',
                'hora_fim' => '16:45',
                'tipo' => 2,
                'tipo_descricao' => 'Tempo',
            ],
        ];

        foreach ($horarios as $horario) {
            Horario::query()->firstOrCreate(
                [
                    'nome' => $horario['nome'],
                    'hora_inicio' => $horario['hora_inicio'],
                    'hora_fim' => $horario['hora_fim'],
                ],
                [
                    'tipo' => $horario['tipo'],
                    'tipo_descricao' => $horario['tipo_descricao'],
                    'estado' => 1,
                    'estado_descricao' => 'Ativo',
                ]
            );
        }
    }
}
