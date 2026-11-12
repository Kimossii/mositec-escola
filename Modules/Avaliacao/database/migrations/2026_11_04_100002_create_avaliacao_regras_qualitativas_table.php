<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avaliacao_regras_qualitativas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('ano_lectivo_id');
            $table->foreignId('nivel_academico_id')->constrained('niveis_academicos')->restrictOnDelete();
            $table->unsignedBigInteger('escala_id');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['tenant_id', 'ano_lectivo_id'])->references(['tenant_id', 'id'])->on('ano_lectivos')->restrictOnDelete();
            $table->foreign(['tenant_id', 'escala_id'])->references(['tenant_id', 'id'])->on('avaliacao_escalas')->restrictOnDelete();
            $table->unique(['tenant_id', 'ano_lectivo_id', 'nivel_academico_id'], 'avaliacao_regras_qualitativas_ano_nivel_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avaliacao_regras_qualitativas');
    }
};
