<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A 7.ª–9.ª classes deixam o Ensino Primário e passam a ter a sua própria etapa («1.º Ciclo»): uma escola só até à 6.ª não
 * recebe classes que não tem. As escolas que já têm essas classes passam a declarar também a nova etapa.
 */
return new class extends Migration
{
    private const PRIMARIO = 3;

    private const PRIMEIRO_CICLO = 6;

    public function up(): void
    {
        $agora = now();
        $codigos = ['SEC_7', 'SEC_8', 'SEC_9'];

        $escolas = DB::table('niveis_academicos')->whereIn('codigo', $codigos)->where('etapa_ensino', self::PRIMARIO)
            ->distinct()->get(['tenant_id', 'estabelecimento_id']);

        DB::table('niveis_academicos')->whereIn('codigo', $codigos)->where('etapa_ensino', self::PRIMARIO)
            ->update(['etapa_ensino' => self::PRIMEIRO_CICLO, 'etapa_ensino_descricao' => '1.º Ciclo (7.ª a 9.ª classe)']);

        foreach ($escolas as $escola) {
            DB::table('estabelecimento_etapas_ensino')->insertOrIgnore([
                'tenant_id' => $escola->tenant_id,
                'estabelecimento_id' => $escola->estabelecimento_id,
                'etapa_ensino' => self::PRIMEIRO_CICLO,
                'etapa_ensino_descricao' => '1.º Ciclo (7.ª a 9.ª classe)',
                'created_at' => $agora,
                'updated_at' => $agora,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('niveis_academicos')->whereIn('codigo', ['SEC_7', 'SEC_8', 'SEC_9'])->where('etapa_ensino', self::PRIMEIRO_CICLO)
            ->update(['etapa_ensino' => self::PRIMARIO, 'etapa_ensino_descricao' => 'Ensino Primário']);
        // A etapa declarada mantém-se: declarar uma etapa a mais é inofensivo.
    }
};
