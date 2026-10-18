<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Meses tocados por uma pausa de cobrança «não cobra». Cada linha é um mês (ano, mês) do
        // calendário do ano lectivo: `isento` = true quando o mês fica fora da cobrança (coberto por
        // inteiro, ou escolhido pelo utilizador nos meses parciais).
        Schema::create('pausa_cobranca_meses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('pausa_cobranca_id')->constrained('pausas_cobranca')->cascadeOnDelete();
            $table->unsignedSmallInteger('ano');
            $table->unsignedTinyInteger('mes');
            $table->boolean('isento')->default(false);
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['pausa_cobranca_id', 'ano', 'mes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pausa_cobranca_meses');
    }
};
