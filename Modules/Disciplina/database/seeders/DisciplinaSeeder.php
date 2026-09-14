<?php

namespace Modules\Disciplina\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Disciplina\Models\Disciplina;
use Modules\Estabelecimento\Models\Estabelecimento;
class DisciplinaSeeder extends Seeder
{
    public function run(): void
    {
        $estabelecimentos = Estabelecimento::query()->get();

        $disciplinas = [
            ['codigo' => 'MAT', 'nome' => 'Matemática'],
            ['codigo' => 'LP', 'nome' => 'Língua Portuguesa'],
            ['codigo' => 'ING', 'nome' => 'Língua Inglesa'],
            ['codigo' => 'FRA', 'nome' => 'Língua Francesa'],
            ['codigo' => 'FIS', 'nome' => 'Física'],
            ['codigo' => 'QUI', 'nome' => 'Química'],
            ['codigo' => 'BIO', 'nome' => 'Biologia'],
            ['codigo' => 'GEO', 'nome' => 'Geografia'],
            ['codigo' => 'HIS', 'nome' => 'História'],
            ['codigo' => 'FIL', 'nome' => 'Filosofia'],
            ['codigo' => 'TIC', 'nome' => 'Tecnologias de Informação e Comunicação'],
            ['codigo' => 'INF', 'nome' => 'Informática'],
            ['codigo' => 'EF', 'nome' => 'Educação Física'],
            ['codigo' => 'EV', 'nome' => 'Educação Visual'],
            ['codigo' => 'ET', 'nome' => 'Educação Tecnológica'],
            ['codigo' => 'EM', 'nome' => 'Educação Musical'],
            ['codigo' => 'EMC', 'nome' => 'Educação Moral e Cívica'],
            ['codigo' => 'ECON', 'nome' => 'Economia'],
            ['codigo' => 'SOC', 'nome' => 'Sociologia'],
            ['codigo' => 'PSIC', 'nome' => 'Psicologia'],
            ['codigo' => 'DIR', 'nome' => 'Direito'],
            ['codigo' => 'CONT', 'nome' => 'Contabilidade'],
            ['codigo' => 'GEST', 'nome' => 'Gestão'],
            ['codigo' => 'EMP', 'nome' => 'Empreendedorismo'],
            ['codigo' => 'ELE', 'nome' => 'Electrotecnia'],
            ['codigo' => 'MEC', 'nome' => 'Mecânica'],
            ['codigo' => 'DES', 'nome' => 'Desenho Técnico'],
            ['codigo' => 'EST', 'nome' => 'Estatística'],
        ];

        foreach ($estabelecimentos as $estabelecimento) {
            foreach ($disciplinas as $disciplina) {
                Disciplina::query()->firstOrCreate(
                    [
                        'estabelecimento_id' => $estabelecimento->id,
                        'codigo' => $disciplina['codigo'],
                    ],
                    [
                        'nome' => $disciplina['nome'],
                        'estado' => 1,
                        'estado_descricao' => 'Ativo',
                    ]
                );
            }
        }
    }
}
