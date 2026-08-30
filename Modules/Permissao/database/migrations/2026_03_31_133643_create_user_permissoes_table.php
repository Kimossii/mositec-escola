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
        Schema::create('user_permissoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('users_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('modulo_id')->constrained('modulos')->onDelete('cascade');
            $table->foreignId('acao_id')->constrained('acoes')->onDelete('cascade');
            $table->boolean('permitido')->default(null);//NULL   → não existe uma decisão individual; TRUE→ permitido explicitamente-FALSE  → negado explicitamente
            $table->unique(['users_id', 'modulo_id', 'acao_id']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_permissoes');
    }
};
