<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Os prazos passam a ser da escola, por trimestre e tipo, e já não de cada regra. Onde havia vários para o mesmo
 * trimestre e tipo, fica o mais recente (os outros desaparecem) e as excepções dos que saem passam para ele.
 */
return new class extends Migration
{
    public function up(): void
    {
        $grupos = DB::table('avaliacao_prazos')->orderByDesc('updated_at')->orderByDesc('id')->get()->groupBy(fn ($p) => $p->tenant_id.'-'.$p->periodo_id.'-'.$p->tipo);

        foreach ($grupos as $prazos) {
            $fica = $prazos->first();

            foreach ($prazos->slice(1) as $sai) {
                DB::table('avaliacao_prazo_excepcoes')->where('prazo_id', $sai->id)->update(['prazo_id' => $fica->id]);
                DB::table('avaliacao_prazos')->where('id', $sai->id)->delete();
            }
        }
    }

    public function down(): void
    {
        // Os prazos repetidos já não existem: não há o que repor.
    }
};
