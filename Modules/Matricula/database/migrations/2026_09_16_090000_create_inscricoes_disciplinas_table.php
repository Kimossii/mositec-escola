<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inscricoes_disciplinas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('matricula_id')->constrained('matriculas')->restrictOnDelete();
            $table->foreignId('plano_curricular_disciplina_id')->constrained('plano_curricular_disciplinas')->restrictOnDelete();
            $table->date('data_inscricao');
            $table->date('data_conclusao')->nullable();
            $table->unsignedTinyInteger('estado');
            $table->text('observacoes')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('matricula_id');
            $table->index('plano_curricular_disciplina_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inscricoes_disciplinas');
    }
};
