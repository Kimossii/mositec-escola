<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A quem se aplica um item obrigatório: níveis e/ou cursos; sem linhas = a todos.
        Schema::create('catalogo_alvos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('item_tipo', 20); // produto | servico
            $table->unsignedBigInteger('item_id');
            $table->foreignId('nivel_academico_id')->nullable()->constrained('niveis_academicos')->restrictOnDelete();
            $table->foreignId('curso_id')->nullable()->constrained('cursos')->restrictOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'item_tipo', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalogo_alvos');
    }
};
