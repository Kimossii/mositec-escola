<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('frequencia_registos_historico', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('registo_id');
            $table->unsignedTinyInteger('evento');
            $table->string('evento_descricao');
            $table->unsignedTinyInteger('estado_anterior')->nullable();
            $table->unsignedTinyInteger('estado_novo')->nullable();
            $table->text('motivo')->nullable();
            $table->foreignId('alterado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['registo_id', 'id'], 'frequencia_historico_registo_index');
            $table->foreign(['tenant_id', 'registo_id'])->references(['tenant_id', 'id'])->on('frequencia_registos')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('frequencia_registos_historico');
    }
};
