<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plano_propina_alvos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('plano_propina_id')->constrained('planos_propina')->cascadeOnDelete();
            $table->foreignId('nivel_academico_id')->nullable()->constrained('niveis_academicos')->restrictOnDelete();
            $table->foreignId('curso_id')->nullable()->constrained('cursos')->restrictOnDelete();
            $table->foreignId('turno_id')->nullable()->constrained('turnos')->restrictOnDelete();
            $table->foreignId('turma_id')->nullable()->constrained('turmas')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['plano_propina_id', 'nivel_academico_id', 'curso_id', 'turno_id', 'turma_id'], 'plano_propina_alvos_unico');
            $table->index(['tenant_id', 'plano_propina_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plano_propina_alvos');
    }
};
