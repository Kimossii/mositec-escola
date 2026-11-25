<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('frequencia_justificacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('registo_id')->unique();
            $table->text('motivo');
            $table->string('documento_referencia')->nullable();
            $table->foreignId('justificada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('justificada_em');
            $table->timestamps();

            $table->foreign(['tenant_id', 'registo_id'])->references(['tenant_id', 'id'])->on('frequencia_registos')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('frequencia_justificacoes');
    }
};
