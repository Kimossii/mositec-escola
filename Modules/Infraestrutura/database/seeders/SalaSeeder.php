<?php

namespace Modules\Infraestrutura\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Infraestrutura\Models\Sala;

class SalaSeeder extends Seeder
{
    public function run(): void
    {
        $estabelecimentos = Estabelecimento::query()->get();

        $salas = [
            [
                'codigo' => 'SA-01',
                'nome' => 'Sala de Aula 01',
                'tipo' => 0,
                'tipo_descricao' => 'Sala de Aula',
                'capacidade' => 40,
                'localizacao' => 'Bloco A - 1.º Andar',
            ],
            [
                'codigo' => 'SA-02',
                'nome' => 'Sala de Aula 02',
                'tipo' => 0,
                'tipo_descricao' => 'Sala de Aula',
                'capacidade' => 40,
                'localizacao' => 'Bloco A - 1.º Andar',
            ],
            [
                'codigo' => 'SA-03',
                'nome' => 'Sala de Aula 03',
                'tipo' => 0,
                'tipo_descricao' => 'Sala de Aula',
                'capacidade' => 35,
                'localizacao' => 'Bloco A - 1.º Andar',
            ],
            [
                'codigo' => 'LAB-01',
                'nome' => 'Laboratório de Informática',
                'tipo' => 1,
                'tipo_descricao' => 'Laboratório',
                'capacidade' => 30,
                'localizacao' => 'Bloco B - Rés-do-chão',
            ],
            [
                'codigo' => 'LAB-02',
                'nome' => 'Laboratório de Ciências',
                'tipo' => 1,
                'tipo_descricao' => 'Laboratório',
                'capacidade' => 25,
                'localizacao' => 'Bloco B - Rés-do-chão',
            ],
            [
                'codigo' => 'BIB-01',
                'nome' => 'Biblioteca',
                'tipo' => 2,
                'tipo_descricao' => 'Biblioteca',
                'capacidade' => 60,
                'localizacao' => 'Bloco C - 1.º Andar',
            ],
            [
                'codigo' => 'AUD-01',
                'nome' => 'Auditório',
                'tipo' => 3,
                'tipo_descricao' => 'Auditório',
                'capacidade' => 200,
                'localizacao' => 'Edifício Principal',
            ],
            [
                'codigo' => 'GIN-01',
                'nome' => 'Ginásio',
                'tipo' => 4,
                'tipo_descricao' => 'Ginásio',
                'capacidade' => 100,
                'localizacao' => 'Área Desportiva',
            ],
            [
                'codigo' => 'SP-01',
                'nome' => 'Sala dos Professores',
                'tipo' => 5,
                'tipo_descricao' => 'Sala de Professores',
                'capacidade' => 20,
                'localizacao' => 'Bloco A - Rés-do-chão',
            ],
            [
                'codigo' => 'GA-01',
                'nome' => 'Gabinete Administrativo',
                'tipo' => 6,
                'tipo_descricao' => 'Gabinete Administrativo',
                'capacidade' => 5,
                'localizacao' => 'Bloco Administrativo',
            ],
        ];

        foreach ($estabelecimentos as $estabelecimento) {
            foreach ($salas as $sala) {
                Sala::query()->firstOrCreate(
                    [
                        'estabelecimento_id' => $estabelecimento->id,
                        'codigo' => $sala['codigo'],
                    ],
                    [
                        'nome' => $sala['nome'],
                        'tipo' => $sala['tipo'],
                        'tipo_descricao' => $sala['tipo_descricao'],
                        'capacidade' => $sala['capacidade'],
                        'localizacao' => $sala['localizacao'],
                        'estado' => 0,
                        'estado_descricao' => 'Ativa',
                    ]
                );
            }
        }
    }
}
