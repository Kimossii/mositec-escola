<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planos_propina', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('ano_lectivo_id')->constrained('ano_lectivos')->restrictOnDelete();
            $table->string('nome', 100);
            $table->text('descricao')->nullable();
            $table->unsignedTinyInteger('periodicidade');
            $table->string('periodicidade_descricao');
            $table->unsignedTinyInteger('intervalo_meses');
            $table->unsignedBigInteger('valor');
            $table->unsignedTinyInteger('mes_inicio');
            $table->unsignedTinyInteger('mes_fim');
            $table->unsignedTinyInteger('estado')->default(1);
            $table->string('estado_descricao')->default('Ativo');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'ano_lectivo_id', 'nome']);
            $table->index(['tenant_id', 'ano_lectivo_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planos_propina');
    }
};
