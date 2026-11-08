<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cada regra que existia «de uma classe» passa a ser uma regra com nome, ligada a essa mesma classe: o comportamento é
 * igual ao de antes. As opções de exame próprio e de recurso, que vinham do código da classe, ficam gravadas na regra.
 */
return new class extends Migration
{
    private const COM_EXAME = ['PRI_6', 'SEC_9'];

    private const COM_RECURSO = ['PRI_6', 'SEC_7', 'SEC_8', 'SEC_9'];

    public function up(): void
    {
        $agora = now();

        foreach (DB::table('avaliacao_regras')->get() as $regra) {
            $nivel = DB::table('niveis_academicos')->where('id', $regra->nivel_academico_id)->first();

            DB::table('avaliacao_regras')->where('id', $regra->id)->update([
                'nome' => $nivel->nome,
                'com_exame' => in_array($nivel->codigo, self::COM_EXAME, true),
                'com_recurso' => in_array($nivel->codigo, self::COM_RECURSO, true),
            ]);

            DB::table('avaliacao_regra_niveis')->insert([
                'tenant_id' => $regra->tenant_id, 'ano_lectivo_id' => $regra->ano_lectivo_id, 'nivel_academico_id' => $regra->nivel_academico_id,
                'regra_id' => $regra->id, 'created_at' => $agora, 'updated_at' => $agora,
            ]);
        }

        foreach (DB::table('avaliacao_regras_qualitativas')->get() as $regra) {
            $nivel = DB::table('niveis_academicos')->where('id', $regra->nivel_academico_id)->first();

            DB::table('avaliacao_regras_qualitativas')->where('id', $regra->id)->update(['nome' => $nivel->nome]);

            DB::table('avaliacao_regra_niveis')->insert([
                'tenant_id' => $regra->tenant_id, 'ano_lectivo_id' => $regra->ano_lectivo_id, 'nivel_academico_id' => $regra->nivel_academico_id,
                'regra_qualitativa_id' => $regra->id, 'created_at' => $agora, 'updated_at' => $agora,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('avaliacao_regra_niveis')->delete();
    }
};
