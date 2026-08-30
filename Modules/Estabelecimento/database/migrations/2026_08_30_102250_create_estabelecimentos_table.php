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
        Schema::create('estabelecimentos', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->string('nome_abreviado')->nullable();
            $table->unsignedTinyInteger('tipo'); // 1: público | 2: privado | 3: cooperativo
            $table->string('tipo_descricao')->nullable();
            $table->string('nif')->nullable();
            $table->string('codigo_mined')->nullable();
            $table->string('numero_alvara')->nullable();
            $table->string('email')->nullable();
            $table->string('telefone')->nullable();
            $table->string('telefone_alternativo')->nullable();
            $table->string('website')->nullable();
            $table->string('endereco')->nullable();
            $table->string('caixa_postal')->nullable();
            $table->string('municipio')->nullable();
            $table->string('provincia')->nullable();
            $table->string('responsavel_nome')->nullable();
            $table->string('responsavel_cargo')->nullable();
            $table->unsignedSmallInteger('ano_fundacao')->nullable();
            $table->string('logotipo_path')->nullable();
            $table->text('observacoes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('estabelecimentos');
    }
};
