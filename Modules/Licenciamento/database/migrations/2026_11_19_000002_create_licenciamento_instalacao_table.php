<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Identidade desta instalação (um só registo, imutável): serve para ligar licenças a esta instalação.
        Schema::create('licenciamento_instalacao', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licenciamento_instalacao');
    }
};
