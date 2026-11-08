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
            $table->dropUnique('avaliacao_regras_ano_nivel_unique');
            $table->dropForeign(['nivel_academico_id']);
            $table->dropColumn('nivel_academico_id');
        });
    }

    public function down(): void
    {
        Schema::table('avaliacao_regras', function (Blueprint $table) {
            $table->unsignedBigInteger('nivel_academico_id')->nullable()->after('ano_lectivo_id');
        });

        // Repõe a classe de cada regra a partir da ligação (a primeira, se houver várias).
        foreach (DB::table('avaliacao_regra_niveis')->whereNotNull('regra_id')->orderBy('id')->get() as $ligacao) {
            DB::table('avaliacao_regras')->where('id', $ligacao->regra_id)->whereNull('nivel_academico_id')->update(['nivel_academico_id' => $ligacao->nivel_academico_id]);
        }

        Schema::table('avaliacao_regras', function (Blueprint $table) {
            $table->foreign('nivel_academico_id')->references('id')->on('niveis_academicos')->restrictOnDelete();
            $table->unique(['tenant_id', 'ano_lectivo_id', 'nivel_academico_id'], 'avaliacao_regras_ano_nivel_unique');
        });
    }
};
