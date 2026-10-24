<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regras_cobranca', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedTinyInteger('dia_vencimento')->default(10);
            $table->unsignedTinyInteger('dias_tolerancia')->default(5);
            $table->boolean('permite_pagamento_parcial')->default(false);
            $table->boolean('permite_pagamento_antecipado')->default(false);
            $table->boolean('gerar_automaticamente')->default(false);
            $table->boolean('permite_negociacao')->default(false);
            $table->unsignedTinyInteger('desconto_maximo_negociacao')->default(0);
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regras_cobranca');
    }
};
