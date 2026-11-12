<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Instantâneo imutável de cada versão aprovada: o que foi impresso na pauta, não o que se recalcula hoje.
        Schema::create('avaliacao_pauta_linhas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('pauta_id');
            $table->unsignedInteger('versao');
            $table->foreignId('inscricao_disciplina_id')->constrained('inscricoes_disciplinas')->restrictOnDelete();
            $table->decimal('mt1', 8, 4)->nullable();
            $table->decimal('mt2', 8, 4)->nullable();
            $table->decimal('mt3', 8, 4)->nullable();
            $table->decimal('mfd_exacta', 14, 10)->nullable();
            $table->decimal('mfd', 8, 4)->nullable();
            $table->boolean('aprovado')->nullable();
            $table->timestamps();

            $table->foreign(['tenant_id', 'pauta_id'])->references(['tenant_id', 'id'])->on('avaliacao_pautas')->restrictOnDelete();
            $table->unique(['pauta_id', 'versao', 'inscricao_disciplina_id'], 'avaliacao_pauta_linhas_versao_inscricao_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avaliacao_pauta_linhas');
    }
};
