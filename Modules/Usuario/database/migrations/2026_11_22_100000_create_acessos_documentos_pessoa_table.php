<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acessos_documentos_pessoa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            // Auditoria sobrevive à eliminação do utilizador/pessoa: FK a null e cópia dos nomes no momento do acesso.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('dados_pessoa_id')->nullable()->constrained('dados_pessoas')->nullOnDelete();
            $table->string('utilizador_nome')->nullable();
            $table->string('pessoa_nome')->nullable();
            $table->foreignId('documento_pessoa_id')->nullable()->constrained('documentos_pessoas')->nullOnDelete();
            $table->string('acao', 20);
            $table->string('acao_descricao', 40);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('dados_pessoa_id');
            $table->index('user_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acessos_documentos_pessoa');
    }
};
