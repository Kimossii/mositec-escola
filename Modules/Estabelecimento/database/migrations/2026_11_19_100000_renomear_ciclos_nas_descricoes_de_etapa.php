<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** As etapas passam a chamar-se «I Ciclo» (7.ª–9.ª) e «II Ciclo» (10.ª–13.ª): actualiza a descrição legível já gravada. */
return new class extends Migration
{
    private const TABELAS = ['niveis_academicos', 'estabelecimento_etapas_ensino'];

    public function up(): void
    {
        foreach (self::TABELAS as $tabela) {
            DB::table($tabela)->where('etapa_ensino', 6)->update(['etapa_ensino_descricao' => 'I Ciclo (7.ª a 9.ª classe)']);
            DB::table($tabela)->where('etapa_ensino', 4)->update(['etapa_ensino_descricao' => 'II Ciclo (10.ª a 13.ª classe)']);
        }
    }

    public function down(): void
    {
        foreach (self::TABELAS as $tabela) {
            DB::table($tabela)->where('etapa_ensino', 6)->update(['etapa_ensino_descricao' => '1.º Ciclo (7.ª a 9.ª classe)']);
            DB::table($tabela)->where('etapa_ensino', 4)->update(['etapa_ensino_descricao' => 'Ensino Secundário']);
        }
    }
};
