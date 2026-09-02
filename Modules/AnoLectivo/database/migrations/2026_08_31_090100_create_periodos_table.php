<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('periodos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ano_lectivo_id')->constrained('ano_lectivos')->cascadeOnDelete();
            $table->string('nome');
            $table->unsignedTinyInteger('tipo')->default(0); // 0: trimestre | 1: semestre | 2: outro
            $table->string('tipo_descricao')->nullable();
            $table->unsignedTinyInteger('numero')->nullable();
            $table->date('data_inicio');
            $table->date('data_fim');
            $table->unsignedTinyInteger('estado')->default(1); // 0: inativo, 1: ativo
            $table->string('estado_descricao')->default('Ativo');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['ano_lectivo_id', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('periodos');
    }
};
