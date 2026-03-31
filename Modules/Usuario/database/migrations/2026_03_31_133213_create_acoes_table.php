<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('acoes', function (Blueprint $table) {
            $table->id();
            $table->string('nome')->nullable();
            $table->integer('numero')->default(0); // 0: ver, 1: criar, 2: editar, 3: eliminar, 4: listar, 5: exportar
            $table->integer('estado')->default(1); // 0: inativo, 1: ativo
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('acoes');
    }
};
