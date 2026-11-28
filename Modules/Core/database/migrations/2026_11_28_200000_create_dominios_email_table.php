<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /** Domínio próprio verificado (no Resend) de uma escola, 1:1 com tenants. Alternativa ao servidor SMTP próprio; não guarda segredos. */
    public function up(): void
    {
        Schema::create('dominios_email', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained('tenants')->restrictOnDelete();
            $table->string('dominio');
            $table->string('id_externo')->nullable(); // identificador do domínio no fornecedor
            $table->string('remetente_nome')->nullable();
            $table->string('remetente_local', 64)->default('noreply'); // parte antes do @
            $table->json('registos')->nullable(); // registos DNS a criar (SPF/DKIM): tipo, nome, valor
            $table->unsignedTinyInteger('estado')->default(0); // 0: por verificar, 1: verificado, 2: com falha
            $table->string('estado_descricao')->default('Por verificar');
            $table->boolean('activo')->default(false);
            $table->timestamp('verificado_em')->nullable();
            $table->string('ultimo_erro', 500)->nullable(); // curto e sem segredos
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dominios_email');
    }
};
