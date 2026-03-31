<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->integer('nome')->default(0); // 0: aluno, 1: admin, 2: professor, 3: secretario
            $table->string('descricao')->nullable();
            $table->integer('estado')->default(1); // 0: inativo, 1: ativo
            $table->foreignId('criado_por')->nullable()->constrained('usuarios')->onDelete('set null');
            $table->foreignId('editado_por')->nullable()->constrained('usuarios')->onDelete('set null');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
