<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plano_curricular_disciplina_periodos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plano_curricular_ano_lectivo_id')->constrained('plano_curricular_anos_lectivos')->cascadeOnDelete();
            $table->foreignId('plano_curricular_disciplina_id')->constrained('plano_curricular_disciplinas')->restrictOnDelete();
            $table->foreignId('periodo_id')->constrained('periodos')->restrictOnDelete();
            $table->timestamps();

            $table->unique(
                ['plano_curricular_ano_lectivo_id', 'plano_curricular_disciplina_id', 'periodo_id'],
                'plano_disciplina_periodo_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plano_curricular_disciplina_periodos');
    }
};
