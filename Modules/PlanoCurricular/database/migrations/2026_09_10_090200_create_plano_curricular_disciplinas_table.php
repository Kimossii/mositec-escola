<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plano_curricular_disciplinas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plano_curricular_id')->constrained('planos_curriculares')->cascadeOnDelete();
            $table->foreignId('disciplina_id')->constrained('disciplinas')->restrictOnDelete();
            $table->foreignId('nivel_academico_id')->constrained('niveis_academicos')->restrictOnDelete();
            $table->unsignedSmallInteger('carga_horaria')->nullable();
            $table->unsignedSmallInteger('creditos')->nullable();
            $table->unsignedTinyInteger('componente')->nullable();
            $table->string('componente_descricao')->nullable();
            $table->unsignedTinyInteger('tipo')->default(0);
            $table->string('tipo_descricao')->default('Normal');
            $table->boolean('obrigatoria')->default(true);
            $table->unsignedInteger('ordem')->default(0);
            $table->unsignedTinyInteger('estado')->default(1);
            $table->string('estado_descricao')->default('Ativo');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['plano_curricular_id', 'disciplina_id', 'nivel_academico_id'], 'plano_disciplina_nivel_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plano_curricular_disciplinas');
    }
};
