<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avaliacao_regras', function (Blueprint $table) {
            // Pesos do exame próprio (6.ª e 9.ª): MF = MFD × peso_mfd_exame + NE × peso_exame.
            $table->decimal('peso_mfd_exame', 5, 2)->default(40)->after('peso_mt3');
            $table->decimal('peso_exame', 5, 2)->default(60)->after('peso_mfd_exame');
        });

        Schema::table('avaliacao_pauta_linhas', function (Blueprint $table) {
            $table->decimal('ne', 8, 4)->nullable()->after('mt3');
            $table->decimal('mf_exacta', 14, 10)->nullable()->after('mfd');
            $table->decimal('mf', 8, 4)->nullable()->after('mf_exacta');
        });

        if (DB::getDriverName() === 'pgsql') {
            // Um único exame próprio (tipo 2) por turma e disciplina; a Action valida primeiro.
            DB::statement('CREATE UNIQUE INDEX avaliacao_instrumentos_um_exame_unique ON avaliacao_instrumentos (turma_id, disciplina_id) WHERE tipo = 2');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS avaliacao_instrumentos_um_exame_unique');
        }

        Schema::table('avaliacao_pauta_linhas', fn (Blueprint $t) => $t->dropColumn(['ne', 'mf_exacta', 'mf']));
        Schema::table('avaliacao_regras', fn (Blueprint $t) => $t->dropColumn(['peso_mfd_exame', 'peso_exame']));
    }
};
