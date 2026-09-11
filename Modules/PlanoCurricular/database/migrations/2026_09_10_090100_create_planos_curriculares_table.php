<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planos_curriculares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estabelecimento_id')->constrained('estabelecimentos')->restrictOnDelete();
            $table->foreignId('curso_id')->constrained('cursos')->restrictOnDelete();
            $table->string('codigo');
            $table->string('nome');
            $table->text('descricao')->nullable();
            $table->unsignedTinyInteger('estado')->default(1);
            $table->string('estado_descricao')->default('Ativo');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['estabelecimento_id', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planos_curriculares');
    }
};
