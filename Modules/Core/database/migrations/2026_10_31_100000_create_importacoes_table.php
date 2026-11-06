<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('importacoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('entidade', 60);
            $table->string('nome_ficheiro');
            $table->string('caminho');
            // 0 carregada | 1 a validar | 2 validada | 3 a importar | 4 concluída | 5 falhada | 6 cancelada
            $table->unsignedTinyInteger('estado')->default(0);
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('validas')->default(0);
            $table->unsignedInteger('ignoradas')->default(0);
            $table->unsignedInteger('com_erro')->default(0);
            $table->unsignedInteger('importadas')->default(0);
            $table->string('erro_geral')->nullable();
            $table->timestamp('concluido_em')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('importacoes');
    }
};
