<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avaliacao_qualitativas_historico', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('qualitativa_id');
            $table->unsignedBigInteger('escala_nivel_anterior_id')->nullable();
            $table->unsignedBigInteger('escala_nivel_novo_id');
            $table->boolean('fora_do_prazo')->default(false);
            $table->text('motivo')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['tenant_id', 'qualitativa_id'])->references(['tenant_id', 'id'])->on('avaliacao_qualitativas')->restrictOnDelete();
            $table->foreign(['tenant_id', 'escala_nivel_anterior_id'], 'avaliacao_qual_hist_anterior_fk')->references(['tenant_id', 'id'])->on('avaliacao_escala_niveis')->restrictOnDelete();
            $table->foreign(['tenant_id', 'escala_nivel_novo_id'], 'avaliacao_qual_hist_novo_fk')->references(['tenant_id', 'id'])->on('avaliacao_escala_niveis')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avaliacao_qualitativas_historico');
    }
};
