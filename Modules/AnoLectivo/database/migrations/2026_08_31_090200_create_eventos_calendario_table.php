<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eventos_calendario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ano_lectivo_id')->constrained('ano_lectivos')->cascadeOnDelete();
            $table->string('titulo');
            $table->text('descricao')->nullable();
            $table->unsignedTinyInteger('tipo')->default(6); // 0: aula | 1: avaliação | 2: reunião | 3: férias | 4: feriado | 5: actividade | 6: evento | 7: outro
            $table->string('tipo_descricao')->nullable();
            $table->date('data_inicio');
            $table->date('data_fim');
            $table->boolean('dia_inteiro')->default(true);
            $table->unsignedTinyInteger('estado')->default(1); // 0: inativo, 1: ativo
            $table->string('estado_descricao')->default('Ativo');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['ano_lectivo_id', 'data_inicio']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventos_calendario');
    }
};
