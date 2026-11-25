<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('horario_grelha_entradas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('ano_lectivo_id');
            $table->unsignedBigInteger('turma_id');
            $table->unsignedTinyInteger('dia_semana');
            $table->string('dia_semana_descricao');
            $table->unsignedBigInteger('horario_id');
            $table->unsignedBigInteger('disciplina_id');
            $table->unsignedBigInteger('sala_id')->nullable();
            $table->date('vigencia_inicio');
            $table->date('vigencia_fim')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Referências por chave composta: uma entrada nunca aponta para dados de outro tenant.
            $table->foreign(['tenant_id', 'ano_lectivo_id'])->references(['tenant_id', 'id'])->on('ano_lectivos')->restrictOnDelete();
            $table->foreign(['tenant_id', 'turma_id'])->references(['tenant_id', 'id'])->on('turmas')->restrictOnDelete();
            $table->foreign(['tenant_id', 'horario_id'])->references(['tenant_id', 'id'])->on('horarios')->restrictOnDelete();
            $table->foreign(['tenant_id', 'disciplina_id'])->references(['tenant_id', 'id'])->on('disciplinas')->restrictOnDelete();
            $table->foreign(['tenant_id', 'sala_id'])->references(['tenant_id', 'id'])->on('salas')->restrictOnDelete();

            $table->index(['turma_id', 'dia_semana', 'horario_id'], 'grelha_entradas_turma_dia_tempo_index');
            $table->index(['sala_id', 'dia_semana', 'horario_id'], 'grelha_entradas_sala_dia_tempo_index');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Não-sobreposição garantida pela base (a Action valida primeiro; isto cobre a concorrência).
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        // Uma turma só tem uma entrada por dia e tempo em cada instante.
        DB::statement(<<<'SQL'
            ALTER TABLE horario_grelha_entradas ADD CONSTRAINT grelha_entradas_turma_excl
            EXCLUDE USING gist (
                turma_id WITH =,
                dia_semana WITH =,
                horario_id WITH =,
                (daterange(vigencia_inicio, COALESCE(vigencia_fim, 'infinity'::date), '[]')) WITH &&
            ) WHERE (deleted_at IS NULL)
        SQL);

        // Uma sala só aloja uma entrada por dia e tempo em cada instante.
        DB::statement(<<<'SQL'
            ALTER TABLE horario_grelha_entradas ADD CONSTRAINT grelha_entradas_sala_excl
            EXCLUDE USING gist (
                sala_id WITH =,
                dia_semana WITH =,
                horario_id WITH =,
                (daterange(vigencia_inicio, COALESCE(vigencia_fim, 'infinity'::date), '[]')) WITH &&
            ) WHERE (sala_id IS NOT NULL AND deleted_at IS NULL)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('horario_grelha_entradas');
    }
};
