<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A 7.ª–9.ª classes passam a ser Ensino Primário. A escola que tem esses níveis e só declarava o Secundário passa a
 * declarar também o Primário (a lista de etapas do nível só aceita as declaradas pela escola).
 */
return new class extends Migration
{
    public function up(): void
    {
        $agora = now();

        $escolas = DB::table('niveis_academicos')->whereIn('codigo', ['SEC_7', 'SEC_8', 'SEC_9'])->where('etapa_ensino', 3)
            ->distinct()->get(['tenant_id', 'estabelecimento_id']);

        foreach ($escolas as $escola) {
            DB::table('estabelecimento_etapas_ensino')->insertOrIgnore([
                'tenant_id' => $escola->tenant_id,
                'estabelecimento_id' => $escola->estabelecimento_id,
                'etapa_ensino' => 3,
                'etapa_ensino_descricao' => 'Ensino Primário',
                'created_at' => $agora,
                'updated_at' => $agora,
            ]);
        }
    }

    public function down(): void
    {
        // Não há como saber que linhas a migration acrescentou; mantêm-se (declarar uma etapa a mais é inofensivo).
    }
};
