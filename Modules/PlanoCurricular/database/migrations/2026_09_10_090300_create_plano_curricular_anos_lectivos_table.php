<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plano_curricular_anos_lectivos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plano_curricular_id')->constrained('planos_curriculares')->restrictOnDelete();
            $table->foreignId('ano_lectivo_id')->constrained('ano_lectivos')->restrictOnDelete();
            $table->unsignedTinyInteger('estado')->default(1);
            $table->string('estado_descricao')->default('Ativo');
            $table->timestamp('confirmado_em')->nullable();
            $table->foreignId('confirmado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->text('observacoes')->nullable();
            $table->timestamps();

            $table->unique(['plano_curricular_id', 'ano_lectivo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plano_curricular_anos_lectivos');
    }
};
