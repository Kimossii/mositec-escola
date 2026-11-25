<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** 2/3: cada ligação antiga (encarregado → conta do aluno) passa a apontar para a pessoa dessa conta. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('encarregados_alunos')
            ->whereNull('dados_pessoa_id')
            ->whereNotNull('aluno_id')
            ->update([
                'dados_pessoa_id' => DB::raw('(select users.dados_pessoa_id from users where users.id = encarregados_alunos.aluno_id)'),
            ]);

        $semPessoa = DB::table('encarregados_alunos')->whereNull('dados_pessoa_id')->count();

        if ($semPessoa > 0) {
            throw new RuntimeException("{$semPessoa} ligação(ões) encarregado→aluno apontam para uma conta sem ficha de aluno (dados_pessoa_id nulo). Corrija ou elimine-as antes de migrar.");
        }
    }

    public function down(): void
    {
        // Sem retorno: a coluna nova é só preenchida; a antiga continua a existir até à migração 3/3.
    }
};
