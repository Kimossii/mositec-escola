<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Instantâneo da pauta qualitativa: guarda o código e a designação impressos (a escala pode mudar depois).
        Schema::create('avaliacao_pauta_linhas_qualitativas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('pauta_id');
            $table->unsignedInteger('versao');
            $table->foreignId('inscricao_disciplina_id')->constrained('inscricoes_disciplinas')->restrictOnDelete();
            $table->json('mt1')->nullable();
            $table->json('mt2')->nullable();
            $table->json('mt3')->nullable();
            $table->json('mfd')->nullable();
            $table->timestamps();

            $table->foreign(['tenant_id', 'pauta_id'])->references(['tenant_id', 'id'])->on('avaliacao_pautas')->restrictOnDelete();
            $table->unique(['pauta_id', 'versao', 'inscricao_disciplina_id'], 'avaliacao_pauta_linhas_qual_versao_inscricao_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avaliacao_pauta_linhas_qualitativas');
    }
};
