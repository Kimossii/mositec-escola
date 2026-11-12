<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avaliacao_qualitativas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('inscricao_disciplina_id')->constrained('inscricoes_disciplinas')->restrictOnDelete();
            $table->foreignId('periodo_id')->constrained('periodos')->restrictOnDelete();
            $table->unsignedBigInteger('escala_nivel_id');
            $table->text('observacao')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['tenant_id', 'escala_nivel_id'])->references(['tenant_id', 'id'])->on('avaliacao_escala_niveis')->restrictOnDelete();
            $table->unique(['inscricao_disciplina_id', 'periodo_id'], 'avaliacao_qualitativas_inscricao_periodo_unique');
            $table->unique(['tenant_id', 'id'], 'avaliacao_qualitativas_tenant_id_id_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avaliacao_qualitativas');
    }
};
