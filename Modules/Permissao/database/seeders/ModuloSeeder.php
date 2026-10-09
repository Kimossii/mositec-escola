<?php

namespace Modules\Permissao\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Permissao\Models\Modulo;

class ModuloSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $modulos = [
            ['nome' => 0, 'descricao' => 'Utilizadores'],
            ['nome' => 1, 'descricao' => 'Autorizacao'],
            ['nome' => 2, 'descricao' => 'Ano Letivo'],
            ['nome' => 3, 'descricao' => 'Licenca'],
            ['nome' => 4, 'descricao' => 'Aluno'],
            ['nome' => 5, 'descricao' => 'Professor'],
            ['nome' => 6, 'descricao' => 'Turmas'],
            ['nome' => 7, 'descricao' => 'Matricula'],
            ['nome' => 8, 'descricao' => 'Disciplina'],
            ['nome' => 9, 'descricao' => 'Nota'],
            ['nome' => 10, 'descricao' => 'Estabelecimento'],
            ['nome' => 11, 'descricao' => 'Horario'],
            ['nome' => 12, 'descricao' => 'Infraestrutura'],
            ['nome' => 13, 'descricao' => 'Curso'],
            ['nome' => 14, 'descricao' => 'Plano Curricular'],
            ['nome' => 15, 'descricao' => 'Documento Pessoa'],
            ['nome' => 16, 'descricao' => 'Senha de Utilizador'],
            ['nome' => 17, 'descricao' => 'Regra de Cobranca'],
            ['nome' => 18, 'descricao' => 'Metodo de Pagamento'],
            ['nome' => 19, 'descricao' => 'Catalogo Financeiro'],
            ['nome' => 20, 'descricao' => 'Plano de Propina'],
            ['nome' => 21, 'descricao' => 'Moeda e Cambio'],
        ];

        foreach ($modulos as $modulo) {
            Modulo::updateOrCreate(
                ['nome' => $modulo['nome']],
                ['descricao' => $modulo['descricao']],
            );
        }
    }
}
