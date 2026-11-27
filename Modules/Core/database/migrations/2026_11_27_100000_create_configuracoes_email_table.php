<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /** Servidor SMTP próprio de uma escola (1:1 com tenants). A senha vai cifrada; nunca sai para o ecrã, logs ou auditoria. */
    public function up(): void
    {
        Schema::create('configuracoes_email', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained('tenants')->restrictOnDelete();
            $table->string('servidor');
            $table->unsignedSmallInteger('porta');
            $table->unsignedTinyInteger('encriptacao'); // 0: nenhuma, 1: STARTTLS, 2: SSL/TLS
            $table->string('encriptacao_descricao');
            $table->string('utilizador')->nullable();
            $table->text('senha')->nullable(); // cifrada (cast encrypted)
            $table->boolean('validar_certificado')->default(true);
            $table->string('dominio_ehlo')->nullable();
            $table->unsignedTinyInteger('tempo_limite')->default(15); // segundos, 5 a 30
            $table->string('remetente_nome')->nullable();
            $table->string('remetente_endereco');
            $table->string('responder_para')->nullable();
            $table->unsignedTinyInteger('estado')->default(0); // 0: por verificar, 1: verificado, 2: com falha
            $table->string('estado_descricao')->default('Por verificar');
            $table->boolean('activo')->default(false);
            $table->timestamp('verificada_em')->nullable();
            $table->string('ultimo_erro', 500)->nullable(); // curto e sem segredos
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracoes_email');
    }
};
