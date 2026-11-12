<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // O tratamento de uma negativa: a medida que a escola tomou para a disciplina em que o aluno ficou abaixo do mínimo.
        Schema::create('avaliacao_negativas_tratadas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('inscricao_disciplina_id')->constrained('inscricoes_disciplinas')->restrictOnDelete();
            $table->text('medida');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('inscricao_disciplina_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avaliacao_negativas_tratadas');
    }
};
