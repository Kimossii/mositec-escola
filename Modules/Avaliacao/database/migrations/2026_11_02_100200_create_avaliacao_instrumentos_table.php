<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avaliacao_instrumentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('turma_id');
            $table->unsignedBigInteger('disciplina_id');
            $table->foreignId('periodo_id')->constrained('periodos')->restrictOnDelete();
            $table->unsignedTinyInteger('tipo');
            $table->string('tipo_descricao');
            $table->string('nome');
            $table->boolean('obrigatorio')->default(true);
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['tenant_id', 'turma_id'])->references(['tenant_id', 'id'])->on('turmas')->restrictOnDelete();
            $table->foreign(['tenant_id', 'disciplina_id'])->references(['tenant_id', 'id'])->on('disciplinas')->restrictOnDelete();
            $table->unique(['tenant_id', 'id'], 'avaliacao_instrumentos_tenant_id_id_unique');
            $table->index(['turma_id', 'disciplina_id', 'periodo_id'], 'avaliacao_instrumentos_turma_disc_periodo_index');
        });

        if (DB::getDriverName() === 'pgsql') {
            // Uma única prova trimestral (tipo 1) por turma, disciplina e período; a Action valida primeiro.
            DB::statement('CREATE UNIQUE INDEX avaliacao_instrumentos_uma_pt_unique ON avaliacao_instrumentos (turma_id, disciplina_id, periodo_id) WHERE tipo = 1');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('avaliacao_instrumentos');
    }
};
