<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedidos_exportacao', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('titulo');
            $table->string('formato', 10);
            $table->string('nome');
            $table->unsignedInteger('linhas')->default(0);
            $table->unsignedTinyInteger('estado')->default(0); // 0 a preparar | 1 pronto | 2 erro
            $table->string('caminho')->nullable();
            $table->string('erro')->nullable();
            $table->timestamp('concluido_em')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedidos_exportacao');
    }
};
