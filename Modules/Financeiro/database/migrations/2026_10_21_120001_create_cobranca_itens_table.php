<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Linhas da cobrança: guardam uma CÓPIA do nome, código e preço no momento da venda.
        Schema::create('cobranca_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('cobranca_id')->constrained('cobrancas')->restrictOnDelete();
            $table->string('tipo', 20); // produto | servico
            $table->foreignId('produto_id')->nullable()->constrained('produtos')->restrictOnDelete();
            $table->foreignId('servico_id')->nullable()->constrained('servicos')->restrictOnDelete();
            $table->foreignId('variante_id')->nullable()->constrained('produto_variantes')->restrictOnDelete();
            $table->string('nome');
            $table->string('codigo', 50)->nullable();
            $table->string('variante_nome', 100)->nullable();
            $table->unsignedBigInteger('preco_unitario');
            $table->unsignedInteger('quantidade');
            $table->unsignedBigInteger('total');
            $table->unsignedTinyInteger('stock_abatido')->default(0); // 1 se esta linha abateu stock (para repor ao cancelar)
            $table->timestamps();

            $table->index(['tenant_id', 'cobranca_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobranca_itens');
    }
};
