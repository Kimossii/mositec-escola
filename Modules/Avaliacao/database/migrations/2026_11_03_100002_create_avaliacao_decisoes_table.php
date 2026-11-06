<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avaliacao_decisoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('pauta_id');
            $table->unsignedInteger('versao');
            $table->foreignId('matricula_id')->constrained('matriculas')->restrictOnDelete();
            $table->unsignedTinyInteger('decisao');
            $table->string('decisao_descricao');
            $table->timestamps();

            $table->foreign(['tenant_id', 'pauta_id'])->references(['tenant_id', 'id'])->on('avaliacao_pautas')->restrictOnDelete();
            $table->unique(['pauta_id', 'versao', 'matricula_id'], 'avaliacao_decisoes_versao_matricula_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avaliacao_decisoes');
    }
};
