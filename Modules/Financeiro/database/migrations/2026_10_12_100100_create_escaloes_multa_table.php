<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escaloes_multa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('regra_cobranca_id')->constrained('regras_cobranca')->cascadeOnDelete();
            $table->unsignedTinyInteger('ordem');
            $table->unsignedSmallInteger('dias_atraso');
            $table->unsignedTinyInteger('tipo');
            $table->string('tipo_descricao');
            $table->unsignedBigInteger('valor');
            $table->timestamps();

            $table->unique(['regra_cobranca_id', 'ordem']);
            $table->unique(['regra_cobranca_id', 'dias_atraso']);
            $table->index(['tenant_id', 'regra_cobranca_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escaloes_multa');
    }
};
