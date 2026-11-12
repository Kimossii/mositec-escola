<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avaliacao_definicoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            // Valem para todas as classes e para todos os professores da escola. Por defeito: até 10 avaliações contínuas por
            // trimestre, e a escola espera pelo menos 5 (aviso ao professor; não entra no cálculo).
            $table->unsignedTinyInteger('max_avaliacoes')->default(10);
            $table->unsignedTinyInteger('min_avaliacoes')->default(5);
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avaliacao_definicoes');
    }
};
