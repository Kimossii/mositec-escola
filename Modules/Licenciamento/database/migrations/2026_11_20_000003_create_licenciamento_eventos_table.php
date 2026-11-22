<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Histórico append-only das licenças. `super_admin_id` é um inteiro simples (sem FK). O `detalhe` nunca leva o token nem chaves.
        Schema::create('licenciamento_eventos', function (Blueprint $table) {
            $table->id();
            $table->string('licenca_id', 36)->index();
            $table->string('tipo', 40);
            $table->unsignedBigInteger('super_admin_id')->nullable();
            $table->string('motivo')->nullable();
            $table->json('detalhe')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licenciamento_eventos');
    }
};
