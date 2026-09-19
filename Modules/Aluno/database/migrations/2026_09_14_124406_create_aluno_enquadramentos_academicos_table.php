<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('aluno_enquadramentos_academicos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aluno_id')->constrained('alunos');
            $table->foreignId('curso_id')->nullable()->constrained('cursos');
            $table->foreignId('nivel_academico_id')->nullable()->constrained('niveis_academicos');
            $table->date('data_inicio');
            $table->date('data_fim')->nullable();
            $table->unsignedTinyInteger('estado');
            $table->text('observacoes')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('aluno_id');
            $table->index('curso_id');
            $table->index('nivel_academico_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('aluno_enquadramentos_academicos');
    }
};
