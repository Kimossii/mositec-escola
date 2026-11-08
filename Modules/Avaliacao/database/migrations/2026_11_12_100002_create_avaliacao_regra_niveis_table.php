<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A que regra pertence cada classe num ano lectivo: uma só, numérica OU qualitativa.
        Schema::create('avaliacao_regra_niveis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('ano_lectivo_id');
            $table->foreignId('nivel_academico_id')->constrained('niveis_academicos')->restrictOnDelete();
            $table->unsignedBigInteger('regra_id')->nullable();
            $table->unsignedBigInteger('regra_qualitativa_id')->nullable();
            $table->timestamps();

            $table->foreign(['tenant_id', 'ano_lectivo_id'])->references(['tenant_id', 'id'])->on('ano_lectivos')->restrictOnDelete();
            $table->foreign(['tenant_id', 'regra_id'])->references(['tenant_id', 'id'])->on('avaliacao_regras')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'regra_qualitativa_id'])->references(['tenant_id', 'id'])->on('avaliacao_regras_qualitativas')->cascadeOnDelete();
            $table->unique(['tenant_id', 'ano_lectivo_id', 'nivel_academico_id'], 'avaliacao_regra_niveis_ano_nivel_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avaliacao_regra_niveis');
    }
};
