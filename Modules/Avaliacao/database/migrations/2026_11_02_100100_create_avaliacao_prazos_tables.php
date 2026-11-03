<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avaliacao_prazos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('regra_id');
            $table->foreignId('periodo_id')->constrained('periodos')->restrictOnDelete();
            $table->unsignedTinyInteger('tipo');
            $table->string('tipo_descricao');
            $table->date('data_limite');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['tenant_id', 'regra_id'])->references(['tenant_id', 'id'])->on('avaliacao_regras')->cascadeOnDelete();
            $table->unique(['regra_id', 'periodo_id', 'tipo'], 'avaliacao_prazos_regra_periodo_tipo_unique');
            $table->unique(['tenant_id', 'id'], 'avaliacao_prazos_tenant_id_id_unique');
        });

        Schema::create('avaliacao_prazo_excepcoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('prazo_id');
            $table->unsignedBigInteger('turma_id')->nullable();
            $table->unsignedBigInteger('disciplina_id')->nullable();
            $table->unsignedBigInteger('docente_id')->nullable();
            $table->date('data_limite');
            $table->text('motivo');
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['tenant_id', 'prazo_id'])->references(['tenant_id', 'id'])->on('avaliacao_prazos')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'turma_id'])->references(['tenant_id', 'id'])->on('turmas')->restrictOnDelete();
            $table->foreign(['tenant_id', 'disciplina_id'])->references(['tenant_id', 'id'])->on('disciplinas')->restrictOnDelete();
            $table->foreign(['tenant_id', 'docente_id'])->references(['tenant_id', 'id'])->on('docentes')->restrictOnDelete();
            $table->index('prazo_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avaliacao_prazo_excepcoes');
        Schema::dropIfExists('avaliacao_prazos');
    }
};
