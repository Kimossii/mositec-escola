<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Quem pode ir ao recurso: máximo de disciplinas negativas e disciplinas-chave (negativa a uma delas = reprovação directa).
        Schema::table('avaliacao_regras', function (Blueprint $table) {
            $table->unsignedTinyInteger('recurso_max_disciplinas')->nullable()->after('peso_exame');
        });

        Schema::create('avaliacao_regra_disciplinas_chave', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('regra_id');
            $table->unsignedBigInteger('disciplina_id');
            $table->timestamps();

            $table->foreign(['tenant_id', 'regra_id'])->references(['tenant_id', 'id'])->on('avaliacao_regras')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'disciplina_id'])->references(['tenant_id', 'id'])->on('disciplinas')->restrictOnDelete();
            $table->unique(['regra_id', 'disciplina_id'], 'avaliacao_regra_disciplinas_chave_unico');
        });

        // Progressão do Pré-Escolar: automática (todos transitam) ou decidida criança a criança.
        Schema::table('avaliacao_regras_qualitativas', function (Blueprint $table) {
            $table->unsignedTinyInteger('progressao')->default(0)->after('escala_id');
            $table->string('progressao_descricao')->default('Automática')->after('progressao');
        });

        Schema::create('avaliacao_progressoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('matricula_id')->constrained('matriculas')->restrictOnDelete();
            $table->unsignedTinyInteger('decisao');
            $table->string('decisao_descricao');
            $table->text('motivo')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('matricula_id');
        });

        Schema::create('avaliacao_competencia_registos_historico', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('registo_id')->constrained('avaliacao_competencia_registos')->cascadeOnDelete();
            $table->unsignedBigInteger('escala_nivel_anterior_id')->nullable();
            $table->unsignedBigInteger('escala_nivel_novo_id');
            $table->boolean('fora_do_prazo')->default(false);
            $table->text('motivo')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['tenant_id', 'escala_nivel_anterior_id'], 'avaliacao_comp_hist_anterior_fk')->references(['tenant_id', 'id'])->on('avaliacao_escala_niveis')->restrictOnDelete();
            $table->foreign(['tenant_id', 'escala_nivel_novo_id'], 'avaliacao_comp_hist_novo_fk')->references(['tenant_id', 'id'])->on('avaliacao_escala_niveis')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avaliacao_competencia_registos_historico');
        Schema::dropIfExists('avaliacao_progressoes');
        Schema::table('avaliacao_regras_qualitativas', fn (Blueprint $t) => $t->dropColumn(['progressao', 'progressao_descricao']));
        Schema::dropIfExists('avaliacao_regra_disciplinas_chave');
        Schema::table('avaliacao_regras', fn (Blueprint $t) => $t->dropColumn('recurso_max_disciplinas'));
    }
};
