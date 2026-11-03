<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avaliacao_pauta_linhas', function (Blueprint $table) {
            $table->decimal('ner', 8, 4)->nullable()->after('mf');
            $table->decimal('mfr_exacta', 14, 10)->nullable()->after('ner');
            $table->decimal('mfr', 8, 4)->nullable()->after('mfr_exacta');
        });

        if (DB::getDriverName() === 'pgsql') {
            // Um único exame de recurso (tipo 3) por turma e disciplina; a Action valida primeiro.
            DB::statement('CREATE UNIQUE INDEX avaliacao_instrumentos_um_recurso_unique ON avaliacao_instrumentos (turma_id, disciplina_id) WHERE tipo = 3');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS avaliacao_instrumentos_um_recurso_unique');
        }

        Schema::table('avaliacao_pauta_linhas', fn (Blueprint $t) => $t->dropColumn(['ner', 'mfr_exacta', 'mfr']));
    }
};
