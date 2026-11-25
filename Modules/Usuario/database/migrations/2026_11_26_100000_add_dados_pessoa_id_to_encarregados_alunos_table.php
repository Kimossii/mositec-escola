<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 1/3: o encarregado passa a ligar-se à pessoa do aluno (o aluno pode não ter conta de login). Nesta fase a coluna ainda é opcional. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('encarregados_alunos', function (Blueprint $table) {
            $table->foreignId('dados_pessoa_id')->nullable()->after('aluno_id')->constrained('dados_pessoas')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('encarregados_alunos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dados_pessoa_id');
        });
    }
};
