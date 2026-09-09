<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('turmas', function (Blueprint $table) {
            // NOT NULL sem default e sem backfill: só corre com sucesso numa tabela `turmas` vazia.
            // Intencional nesta fase de desenvolvimento, não é um esquecimento.
            $table->foreignId('curso_id')->after('nivel_academico_id')
                ->constrained('cursos')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('turmas', function (Blueprint $table) {
            $table->dropForeign(['curso_id']);
            $table->dropColumn('curso_id');
        });
    }
};
