<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('atribuicoes_docentes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('ano_lectivo_id');
            $table->unsignedBigInteger('turma_id');
            $table->unsignedBigInteger('disciplina_id');
            $table->unsignedBigInteger('professor_id');
            $table->unsignedTinyInteger('papel');
            $table->string('papel_descricao');
            $table->date('data_inicio');
            $table->date('data_fim')->nullable();
            $table->text('motivo_encerramento')->nullable();
            $table->foreignId('criado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('editado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Referências por chave composta: uma atribuição nunca aponta para dados de outro tenant.
            $table->foreign(['tenant_id', 'ano_lectivo_id'])->references(['tenant_id', 'id'])->on('ano_lectivos')->restrictOnDelete();
            $table->foreign(['tenant_id', 'turma_id'])->references(['tenant_id', 'id'])->on('turmas')->restrictOnDelete();
            $table->foreign(['tenant_id', 'disciplina_id'])->references(['tenant_id', 'id'])->on('disciplinas')->restrictOnDelete();
            $table->foreign(['tenant_id', 'professor_id'])->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();

            $table->index(['professor_id', 'data_fim'], 'atribuicoes_docentes_professor_fim_index');
            $table->index(['ano_lectivo_id', 'turma_id'], 'atribuicoes_docentes_ano_turma_index');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Não-sobreposição garantida pela base (a Action valida primeiro; isto cobre a concorrência).
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        // Um titular por dia por (turma, disciplina).
        DB::statement(<<<'SQL'
            ALTER TABLE atribuicoes_docentes ADD CONSTRAINT atribuicoes_docentes_um_titular_excl
            EXCLUDE USING gist (
                turma_id WITH =,
                disciplina_id WITH =,
                (daterange(data_inicio, COALESCE(data_fim, 'infinity'::date), '[]')) WITH &&
            ) WHERE (papel = 0 AND deleted_at IS NULL)
        SQL);

        // O mesmo professor sem vigências sobrepostas na mesma (turma, disciplina), seja qual for o papel.
        DB::statement(<<<'SQL'
            ALTER TABLE atribuicoes_docentes ADD CONSTRAINT atribuicoes_docentes_professor_excl
            EXCLUDE USING gist (
                turma_id WITH =,
                disciplina_id WITH =,
                professor_id WITH =,
                (daterange(data_inicio, COALESCE(data_fim, 'infinity'::date), '[]')) WITH &&
            ) WHERE (deleted_at IS NULL)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('atribuicoes_docentes');
    }
};
