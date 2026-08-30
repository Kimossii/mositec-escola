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
        Schema::create('licencas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->integer('tipo')->default(1); // 0: Mensal, 1: Anual, 2:semestral, 3: trimestral, 4: vitalícia, 5: semanal
            $table->string('tipo_descricao')->default('Anual');
            $table->date('validade');
            $table->integer('estado')->default(0); // 0: Inativa, 1: Ativa, 2: Expirada
            $table->string('estado_descricao')->default('Inativa');
            $table->text('chave_licenca')->unique();
            $table->foreignId('criado_por')->nullable()->constrained('users')->onDelete('set null');
            $table->foreignId('editado_por')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('licencas');
    }
};
