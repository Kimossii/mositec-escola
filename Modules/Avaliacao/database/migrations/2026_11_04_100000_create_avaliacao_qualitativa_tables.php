<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avaliacao_escalas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('nome');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'nome']);
            $table->unique(['tenant_id', 'id'], 'avaliacao_escalas_tenant_id_id_unique');
        });

        Schema::create('avaliacao_escala_niveis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('escala_id');
            $table->string('codigo', 20);
            $table->string('designacao');
            $table->unsignedSmallInteger('ordem');
            $table->timestamps();

            $table->foreign(['tenant_id', 'escala_id'])->references(['tenant_id', 'id'])->on('avaliacao_escalas')->cascadeOnDelete();
            $table->unique(['escala_id', 'codigo']);
            $table->unique(['escala_id', 'ordem']);
            $table->unique(['tenant_id', 'id'], 'avaliacao_escala_niveis_tenant_id_id_unique');
        });

        Schema::create('avaliacao_regras_qualitativas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('ano_lectivo_id');
            $table->foreignId('nivel_academico_id')->constrained('niveis_academicos')->restrictOnDelete();
            $table->unsignedBigInteger('escala_id');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['tenant_id', 'ano_lectivo_id'])->references(['tenant_id', 'id'])->on('ano_lectivos')->restrictOnDelete();
            $table->foreign(['tenant_id', 'escala_id'])->references(['tenant_id', 'id'])->on('avaliacao_escalas')->restrictOnDelete();
            $table->unique(['tenant_id', 'ano_lectivo_id', 'nivel_academico_id'], 'avaliacao_regras_qualitativas_ano_nivel_unique');
        });

        Schema::create('avaliacao_qualitativas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('inscricao_disciplina_id')->constrained('inscricoes_disciplinas')->restrictOnDelete();
            $table->foreignId('periodo_id')->constrained('periodos')->restrictOnDelete();
            $table->unsignedBigInteger('escala_nivel_id');
            $table->text('observacao')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['tenant_id', 'escala_nivel_id'])->references(['tenant_id', 'id'])->on('avaliacao_escala_niveis')->restrictOnDelete();
            $table->unique(['inscricao_disciplina_id', 'periodo_id'], 'avaliacao_qualitativas_inscricao_periodo_unique');
            $table->unique(['tenant_id', 'id'], 'avaliacao_qualitativas_tenant_id_id_unique');
        });

        Schema::create('avaliacao_qualitativas_historico', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('qualitativa_id');
            $table->unsignedBigInteger('escala_nivel_anterior_id')->nullable();
            $table->unsignedBigInteger('escala_nivel_novo_id');
            $table->boolean('fora_do_prazo')->default(false);
            $table->text('motivo')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['tenant_id', 'qualitativa_id'])->references(['tenant_id', 'id'])->on('avaliacao_qualitativas')->restrictOnDelete();
            $table->foreign(['tenant_id', 'escala_nivel_anterior_id'], 'avaliacao_qual_hist_anterior_fk')->references(['tenant_id', 'id'])->on('avaliacao_escala_niveis')->restrictOnDelete();
            $table->foreign(['tenant_id', 'escala_nivel_novo_id'], 'avaliacao_qual_hist_novo_fk')->references(['tenant_id', 'id'])->on('avaliacao_escala_niveis')->restrictOnDelete();
        });

        // Instantâneo da pauta qualitativa: guarda o código e a designação impressos (a escala pode mudar depois).
        Schema::create('avaliacao_pauta_linhas_qualitativas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('pauta_id');
            $table->unsignedInteger('versao');
            $table->foreignId('inscricao_disciplina_id')->constrained('inscricoes_disciplinas')->restrictOnDelete();
            $table->json('mt1')->nullable();
            $table->json('mt2')->nullable();
            $table->json('mt3')->nullable();
            $table->json('mfd')->nullable();
            $table->timestamps();

            $table->foreign(['tenant_id', 'pauta_id'])->references(['tenant_id', 'id'])->on('avaliacao_pautas')->restrictOnDelete();
            $table->unique(['pauta_id', 'versao', 'inscricao_disciplina_id'], 'avaliacao_pauta_linhas_qual_versao_inscricao_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avaliacao_pauta_linhas_qualitativas');
        Schema::dropIfExists('avaliacao_qualitativas_historico');
        Schema::dropIfExists('avaliacao_qualitativas');
        Schema::dropIfExists('avaliacao_regras_qualitativas');
        Schema::dropIfExists('avaliacao_escala_niveis');
        Schema::dropIfExists('avaliacao_escalas');
    }
};
