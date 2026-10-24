<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metodos_pagamento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('nome', 100);
            $table->unsignedTinyInteger('tipo');
            $table->string('tipo_descricao');
            $table->string('titular', 150)->nullable();
            $table->string('banco', 100)->nullable();
            $table->string('iban', 34)->nullable();
            $table->string('numero_conta', 40)->nullable();
            $table->string('telefone', 30)->nullable();
            $table->boolean('exige_comprovativo')->default(false);
            $table->unsignedTinyInteger('estado')->default(1);
            $table->string('estado_descricao')->default('Ativo');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'nome']);
            $table->index(['tenant_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metodos_pagamento');
    }
};
