<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * `MatriculaConsultaService::listarTodas` — a listagem principal do
     * módulo, a tabela que mais cresce no sistema (um registo por aluno por
     * ano lectivo) — filtra por `ano_lectivo_id` e `estado` em conjunto por
     * omissão (ano lectivo activo). Os índices existentes têm
     * `ano_lectivo_id` como coluna secundária (`aluno_id, ano_lectivo_id` e
     * `turma_id, ano_lectivo_id`), que o Postgres não consegue usar quando
     * o filtro não inclui `aluno_id`/`turma_id`.
     */
    public function up(): void
    {
        Schema::table('matriculas', function (Blueprint $table) {
            $table->index(['ano_lectivo_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::table('matriculas', function (Blueprint $table) {
            $table->dropIndex(['ano_lectivo_id', 'estado']);
        });
    }
};
