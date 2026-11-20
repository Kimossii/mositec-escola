<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Registo local dos direitos do tenant (global, sem PertenceAoTenant). `tenant_id` é um inteiro simples, SEM chave
        // estrangeira, para o módulo continuar independente. O `token` completo é reverificado a cada leitura;
        // `claims` é só informativo (nunca concede direitos).
        Schema::create('licenciamento_direitos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->unique();
            $table->string('licenca_id');
            $table->timestamp('emitida_em');
            $table->text('token');
            $table->json('claims');
            $table->timestamp('revogada_em')->nullable();
            $table->timestamp('instalada_em');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licenciamento_direitos');
    }
};
