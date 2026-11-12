<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avaliacao_pautas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('turma_id');
            $table->unsignedTinyInteger('estado');
            $table->string('estado_descricao');
            $table->unsignedInteger('versao')->default(1);
            // Casas decimais impressas na versão aprovada: a apresentação do instantâneo não segue a regra de hoje.
            $table->unsignedTinyInteger('casas_decimais')->default(2);
            $table->foreignId('aprovada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('aprovada_em')->nullable();
            $table->foreignId('reaberta_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reaberta_em')->nullable();
            $table->text('motivo_reabertura')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['tenant_id', 'turma_id'])->references(['tenant_id', 'id'])->on('turmas')->restrictOnDelete();
            $table->unique('turma_id');
            $table->unique(['tenant_id', 'id'], 'avaliacao_pautas_tenant_id_id_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avaliacao_pautas');
    }
};
