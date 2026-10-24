<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pausa de cobrança de um ano lectivo: suspende a geração de propinas num intervalo de datas.
        // Com `cobra_propina` = true a pausa é só informativa (sem linhas filhas nem efeito); com false,
        // os meses tocados (e a isenção de cada um) vivem em `pausa_cobranca_meses`.
        Schema::create('pausas_cobranca', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('ano_lectivo_id')->constrained('ano_lectivos')->restrictOnDelete();
            $table->string('descricao', 200);
            $table->date('data_inicio');
            $table->date('data_fim');
            $table->boolean('cobra_propina')->default(true);
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'ano_lectivo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pausas_cobranca');
    }
};
