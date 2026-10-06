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
        Schema::create('dados_pessoas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('nome_completo');
            $table->string('email')->nullable();
            $table->string('telefone')->nullable();
            $table->date('data_nascimento')->nullable();
            $table->integer('sexo')->default(0); // 0: Não especificado, 1: Masculino, 2: Feminino.
            $table->string('numero_identificacao');
            $table->integer('tipo_pessoa')->default(0); // 0: Aluno, 1: Professor, 2: Funcionário, 3: Outro
            $table->timestamps();

            $table->unique(['tenant_id', 'numero_identificacao']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dados_pessoas');
    }
};
