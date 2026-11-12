<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Disciplinas-chave: negativa a uma delas = reprovação directa, sem recurso.
        Schema::create('avaliacao_regra_disciplinas_chave', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('regra_id');
            $table->unsignedBigInteger('disciplina_id');
            $table->timestamps();

            $table->foreign(['tenant_id', 'regra_id'])->references(['tenant_id', 'id'])->on('avaliacao_regras')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'disciplina_id'])->references(['tenant_id', 'id'])->on('disciplinas')->restrictOnDelete();
            $table->unique(['regra_id', 'disciplina_id'], 'avaliacao_regra_disciplinas_chave_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avaliacao_regra_disciplinas_chave');
    }
};
