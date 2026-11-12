<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bolsa de estudo: cobre uma percentagem das propinas de um aluno num ano lectivo, entre duas datas.
        Schema::create('bolsas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('aluno_id')->constrained('alunos')->restrictOnDelete();
            $table->foreignId('ano_lectivo_id')->constrained('ano_lectivos')->restrictOnDelete();
            $table->string('nome', 150); // ex.: Bolsa de mérito
            $table->unsignedSmallInteger('percentagem'); // pontos-base: 5000 = 50%, 10000 = 100%
            $table->date('data_inicio');
            $table->date('data_fim');
            $table->text('motivo');
            $table->unsignedTinyInteger('estado')->default(1); // 1 activa, 2 terminada
            $table->string('estado_descricao')->default('Activa');
            $table->text('motivo_termino')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'aluno_id', 'ano_lectivo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bolsas');
    }
};
