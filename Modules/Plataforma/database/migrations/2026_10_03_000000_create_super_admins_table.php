<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Tabela global da Plataforma: sem tenant_id por desenho (o e-mail é único em toda a instalação).
        Schema::create('super_admins', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->integer('estado')->default(1); // 1: Ativo, 0: Inativo
            $table->string('estado_descricao')->default('Ativo');
            $table->boolean('deve_alterar_senha')->default(false);
            $table->timestamp('ultimo_login_em')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('super_admins');
    }
};
