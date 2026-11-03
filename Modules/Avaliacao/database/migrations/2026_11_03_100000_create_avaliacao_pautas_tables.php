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

        // Instantâneo imutável de cada versão aprovada: o que foi impresso na pauta, não o que se recalcula hoje.
        Schema::create('avaliacao_pauta_linhas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('pauta_id');
            $table->unsignedInteger('versao');
            $table->foreignId('inscricao_disciplina_id')->constrained('inscricoes_disciplinas')->restrictOnDelete();
            $table->decimal('mt1', 8, 4)->nullable();
            $table->decimal('mt2', 8, 4)->nullable();
            $table->decimal('mt3', 8, 4)->nullable();
            $table->decimal('mfd_exacta', 14, 10)->nullable();
            $table->decimal('mfd', 8, 4)->nullable();
            $table->boolean('aprovado')->nullable();
            $table->timestamps();

            $table->foreign(['tenant_id', 'pauta_id'])->references(['tenant_id', 'id'])->on('avaliacao_pautas')->restrictOnDelete();
            $table->unique(['pauta_id', 'versao', 'inscricao_disciplina_id'], 'avaliacao_pauta_linhas_versao_inscricao_unique');
        });

        Schema::create('avaliacao_decisoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('pauta_id');
            $table->unsignedInteger('versao');
            $table->foreignId('matricula_id')->constrained('matriculas')->restrictOnDelete();
            $table->unsignedTinyInteger('decisao');
            $table->string('decisao_descricao');
            $table->timestamps();

            $table->foreign(['tenant_id', 'pauta_id'])->references(['tenant_id', 'id'])->on('avaliacao_pautas')->restrictOnDelete();
            $table->unique(['pauta_id', 'versao', 'matricula_id'], 'avaliacao_decisoes_versao_matricula_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avaliacao_decisoes');
        Schema::dropIfExists('avaliacao_pauta_linhas');
        Schema::dropIfExists('avaliacao_pautas');
    }
};
