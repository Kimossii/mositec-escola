<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Como a escola chama à prova trimestral nos documentos (PT, NPT…): é opção da regra, não se adivinha pela classe.
        Schema::table('avaliacao_regras', function (Blueprint $table) {
            $table->string('sigla_prova', 10)->default('PT')->after('peso_pt');
        });

        // Até aqui o documento escrevia NPT na 7.ª a 9.ª classes: as regras que as têm ficam com essa sigla.
        $regras = DB::table('avaliacao_regra_niveis as l')->join('niveis_academicos as n', 'n.id', '=', 'l.nivel_academico_id')
            ->whereNotNull('l.regra_id')->whereIn('n.codigo', ['SEC_7', 'SEC_8', 'SEC_9'])->pluck('l.regra_id');
        DB::table('avaliacao_regras')->whereIn('id', $regras)->update(['sigla_prova' => 'NPT']);
    }

    public function down(): void
    {
        Schema::table('avaliacao_regras', fn (Blueprint $table) => $table->dropColumn('sigla_prova'));
    }
};
