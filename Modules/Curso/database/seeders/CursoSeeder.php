<?php

namespace Modules\Curso\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Curso\Models\Curso;
use Modules\Estabelecimento\Models\Estabelecimento;

class CursoSeeder extends Seeder
{
    public function run(): void
    {
        $estabelecimentos = Estabelecimento::query()->get();

        $cursos = [
            ['codigo' => 'INF', 'nome' => 'Informática'],
            ['codigo' => 'CONT', 'nome' => 'Contabilidade'],
            ['codigo' => 'GEST', 'nome' => 'Gestão'],
            ['codigo' => 'ADM', 'nome' => 'Administração'],
            ['codigo' => 'ELE', 'nome' => 'Electrotecnia'],
            ['codigo' => 'MEC', 'nome' => 'Mecânica'],
            ['codigo' => 'CONST', 'nome' => 'Construção Civil'],
            ['codigo' => 'ENF', 'nome' => 'Enfermagem'],
            ['codigo' => 'AGRI', 'nome' => 'Agronomia'],
            ['codigo' => 'HOT', 'nome' => 'Hotelaria e Turismo'],
            ['codigo' => 'COM', 'nome' => 'Comércio'],
            ['codigo' => 'DES', 'nome' => 'Desenho Técnico'],
        ];

        foreach ($estabelecimentos as $estabelecimento) {
            foreach ($cursos as $curso) {
                Curso::query()->firstOrCreate(
                    [
                        'estabelecimento_id' => $estabelecimento->id,
                        'codigo' => $curso['codigo'],
                    ],
                    [
                        'nome' => $curso['nome'],
                        'estado' => 1,
                        'estado_descricao' => 'Ativo',
                    ]
                );
            }
        }
    }
}
