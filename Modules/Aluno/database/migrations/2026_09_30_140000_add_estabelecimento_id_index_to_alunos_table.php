<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * `estabelecimento_id` é FK mas o Postgres não indexa chaves
     * estrangeiras automaticamente — e é o filtro de praticamente toda
     * consulta a Alunos (AlunoConsultaService::listar, MatriculaConsultaService
     * via whereHas('aluno', ...), etc.), sem nenhum índice que o cubra.
     */
    public function up(): void
    {
        Schema::table('alunos', function (Blueprint $table) {
            $table->index('estabelecimento_id');
        });
    }

    public function down(): void
    {
        Schema::table('alunos', function (Blueprint $table) {
            $table->dropIndex(['estabelecimento_id']);
        });
    }
};
