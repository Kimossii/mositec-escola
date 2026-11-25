<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('frequencia_aulas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('ano_lectivo_id');
            $table->unsignedBigInteger('turma_id');
            $table->unsignedTinyInteger('tipo');
            $table->string('tipo_descricao');
            $table->date('data');
            $table->unsignedBigInteger('horario_id')->nullable();
            $table->unsignedBigInteger('disciplina_id')->nullable();
            $table->unsignedBigInteger('docente_id')->nullable();
            $table->unsignedBigInteger('sala_id')->nullable();
            $table->unsignedTinyInteger('estado');
            $table->string('estado_descricao');
            $table->text('motivo_nao_leccionada')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'frequencia_aulas_tenant_id_id_unique');
            // Uma aula por turma, dia e tempo (as do regime «dia» não têm tempo: ver o índice parcial abaixo).
            $table->unique(['turma_id', 'data', 'horario_id'], 'frequencia_aulas_turma_data_tempo_unique');
            $table->index(['data', 'turma_id'], 'frequencia_aulas_data_turma_index');

            // Referências por chave composta: uma aula nunca aponta para dados de outro tenant.
            $table->foreign(['tenant_id', 'ano_lectivo_id'])->references(['tenant_id', 'id'])->on('ano_lectivos')->restrictOnDelete();
            $table->foreign(['tenant_id', 'turma_id'])->references(['tenant_id', 'id'])->on('turmas')->restrictOnDelete();
            $table->foreign(['tenant_id', 'horario_id'])->references(['tenant_id', 'id'])->on('horarios')->restrictOnDelete();
            $table->foreign(['tenant_id', 'disciplina_id'])->references(['tenant_id', 'id'])->on('disciplinas')->restrictOnDelete();
            $table->foreign(['tenant_id', 'docente_id'])->references(['tenant_id', 'id'])->on('docentes')->restrictOnDelete();
            $table->foreign(['tenant_id', 'sala_id'])->references(['tenant_id', 'id'])->on('salas')->restrictOnDelete();
        });

        // Uma só chamada diária por turma e dia (NULL não conta como igual no índice único acima).
        DB::statement('CREATE UNIQUE INDEX frequencia_aulas_dia_unica ON frequencia_aulas (turma_id, data) WHERE horario_id IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('frequencia_aulas');
    }
};
