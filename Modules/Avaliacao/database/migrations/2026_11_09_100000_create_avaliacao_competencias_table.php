<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Competências de uma área (disciplina) do Pré-Escolar/Creche, para toda a escola ou só para uma classe.
        Schema::create('avaliacao_competencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('disciplina_id');
            $table->foreignId('nivel_academico_id')->nullable()->constrained('niveis_academicos')->restrictOnDelete();
            $table->string('descricao', 200);
            $table->unsignedSmallInteger('ordem');
            $table->boolean('ativa')->default(true);
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['tenant_id', 'disciplina_id'])->references(['tenant_id', 'id'])->on('disciplinas')->restrictOnDelete();
            $table->unique(['tenant_id', 'id'], 'avaliacao_competencias_tenant_id_id_unique');
            $table->index(['disciplina_id', 'nivel_academico_id'], 'avaliacao_competencias_disciplina_nivel_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avaliacao_competencias');
    }
};
