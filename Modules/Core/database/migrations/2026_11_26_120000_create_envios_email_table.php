<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /** Um registo por tentativa de envio de uma notificação por email de uma escola (histórico, falhas e, mais tarde, consumo). */
    public function up(): void
    {
        Schema::create('envios_email', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('notificacao'); // nome curto da classe, ex.: RecuperarSenhaNotificacao
            $table->string('categoria', 20); // essencial | opcional
            $table->unsignedTinyInteger('estado'); // 0: enviado, 1: falhou, 2: descartado por limite, 3: descartado por contrato
            $table->string('estado_descricao');
            $table->unsignedTinyInteger('canal')->default(0); // 0: central, 1: infra própria da escola
            $table->string('canal_descricao')->default('Central');
            $table->foreignId('destinatario_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('destinatario_mascarado')->nullable(); // p***@gmail.com: nunca o endereço completo
            $table->string('erro', 500)->nullable(); // curto e sem segredos
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'estado', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envios_email');
    }
};
