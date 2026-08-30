<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('encarregados_alunos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('encarregado_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('aluno_id')->constrained('users')->onDelete('cascade');
            $table->string('parentesco')->nullable();
            $table->unique(['encarregado_id', 'aluno_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('encarregados_alunos');
    }
};
