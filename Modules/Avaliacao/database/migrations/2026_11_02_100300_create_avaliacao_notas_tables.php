<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avaliacao_notas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('instrumento_id');
            $table->foreignId('inscricao_disciplina_id')->constrained('inscricoes_disciplinas')->restrictOnDelete();
            $table->decimal('valor', 5, 2)->nullable();
            $table->unsignedTinyInteger('estado');
            $table->string('estado_descricao');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['tenant_id', 'instrumento_id'])->references(['tenant_id', 'id'])->on('avaliacao_instrumentos')->restrictOnDelete();
            $table->unique(['instrumento_id', 'inscricao_disciplina_id'], 'avaliacao_notas_instrumento_inscricao_unique');
            $table->unique(['tenant_id', 'id'], 'avaliacao_notas_tenant_id_id_unique');
        });

        Schema::create('avaliacao_notas_historico', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('nota_id');
            $table->decimal('valor_anterior', 5, 2)->nullable();
            $table->decimal('valor_novo', 5, 2)->nullable();
            $table->unsignedTinyInteger('estado_anterior')->nullable();
            $table->unsignedTinyInteger('estado_novo');
            $table->boolean('fora_do_prazo')->default(false);
            $table->text('motivo')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['tenant_id', 'nota_id'])->references(['tenant_id', 'id'])->on('avaliacao_notas')->restrictOnDelete();
            $table->index('nota_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avaliacao_notas_historico');
        Schema::dropIfExists('avaliacao_notas');
    }
};
