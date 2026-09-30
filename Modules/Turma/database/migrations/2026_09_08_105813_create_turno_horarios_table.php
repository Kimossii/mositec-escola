<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('turno_horarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('turno_id')->constrained('turnos')->cascadeOnDelete();
            $table->foreignId('horario_id')->constrained('horarios')->restrictOnDelete();
            $table->unsignedInteger('ordem');
            $table->unique(['turno_id', 'horario_id']);
            $table->unique(['turno_id', 'ordem']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('turno_horarios');
    }
};
