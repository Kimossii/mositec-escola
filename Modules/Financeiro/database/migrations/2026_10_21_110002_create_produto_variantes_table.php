<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produto_variantes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('produto_id')->constrained('produtos')->cascadeOnDelete();
            $table->string('nome', 100); // ex.: tamanho P, M, G, 38
            $table->unsignedInteger('stock_quantidade')->default(0);
            $table->timestamps();

            $table->unique(['produto_id', 'nome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produto_variantes');
    }
};
