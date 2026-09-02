<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ano_lectivos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estabelecimento_id')->nullable()->constrained('estabelecimentos')->nullOnDelete();
            $table->string('nome');
            $table->date('data_inicio');
            $table->date('data_fim');
            $table->unsignedTinyInteger('estado')->default(0); // 0: planeado | 1: activo | 2: encerrado
            $table->string('estado_descricao')->default('Planeado');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['estabelecimento_id', 'nome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ano_lectivos');
    }
};
