<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('importacao_linhas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('importacao_id')->constrained('importacoes')->cascadeOnDelete();
            $table->unsignedInteger('linha'); // número da linha no ficheiro original
            $table->json('dados');
            // 0 pendente | 1 válida | 2 com erro | 3 ignorada (já existe) | 4 importada
            $table->unsignedTinyInteger('estado')->default(0);
            $table->json('erros')->nullable();
            $table->unsignedBigInteger('registo_id')->nullable();

            $table->unique(['importacao_id', 'linha']);
            $table->index(['importacao_id', 'estado', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('importacao_linhas');
    }
};
