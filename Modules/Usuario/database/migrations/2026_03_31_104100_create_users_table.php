<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('numero_matricula')->nullable()->unique();
            $table->integer('tipo_login')->default(0); // 0: email | 1: matricula
            $table->string('tipo_login_descricao')->default('Email');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->foreignId('dados_pessoa_id')->nullable()->constrained('dados_pessoas')->onDelete('set null');
            $table->rememberToken();
            $table->integer('estado')->default(1); // 0 = inativo, 1 = ativo
            $table->string('estado_descricao')->default('Ativo');
            $table->foreignId('criado_por')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('editado_por')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
