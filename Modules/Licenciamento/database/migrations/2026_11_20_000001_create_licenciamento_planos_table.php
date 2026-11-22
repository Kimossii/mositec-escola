<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Catálogo comercial do emissor (global). Limites nulos = sem limite; tolerância/aviso nulos = valor por omissão da config.
        Schema::create('licenciamento_planos', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->text('descricao')->nullable();
            $table->unsignedInteger('limite_alunos')->nullable();
            $table->unsignedInteger('limite_utilizadores')->nullable();
            $table->unsignedSmallInteger('tolerancia_dias')->nullable();
            $table->unsignedSmallInteger('aviso_dias')->nullable();
            $table->json('modulos');
            $table->unsignedSmallInteger('validade_dias');
            $table->unsignedTinyInteger('modalidade');
            $table->string('modalidade_descricao');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licenciamento_planos');
    }
};
