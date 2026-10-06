<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->unique();
            $table->string('nome');
            $table->integer('estado')->default(1); // 1: Activo, 2: Suspenso, 3: Encerrado
            $table->string('estado_descricao')->default('Activo');
            $table->timestamp('suspenso_em')->nullable();
            $table->string('motivo_suspensao')->nullable();
            $table->timestamp('encerrado_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
