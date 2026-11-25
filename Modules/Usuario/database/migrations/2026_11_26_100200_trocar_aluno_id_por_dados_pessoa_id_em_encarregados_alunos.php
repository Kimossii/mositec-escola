<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 3/3: a pessoa do aluno é obrigatória e única por encarregado; a coluna antiga (conta do aluno) sai. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('encarregados_alunos', function (Blueprint $table) {
            $table->dropUnique(['encarregado_id', 'aluno_id']);
            $table->dropConstrainedForeignId('aluno_id');
        });

        Schema::table('encarregados_alunos', function (Blueprint $table) {
            $table->unsignedBigInteger('dados_pessoa_id')->nullable(false)->change();
            $table->unique(['encarregado_id', 'dados_pessoa_id']);
        });
    }

    public function down(): void
    {
        Schema::table('encarregados_alunos', function (Blueprint $table) {
            $table->dropUnique(['encarregado_id', 'dados_pessoa_id']);
            $table->foreignId('aluno_id')->nullable()->after('encarregado_id')->constrained('users')->cascadeOnDelete();
            $table->unique(['encarregado_id', 'aluno_id']);
        });
    }
};
