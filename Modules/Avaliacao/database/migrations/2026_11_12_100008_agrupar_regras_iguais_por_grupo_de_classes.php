<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * As regras que existiam «de uma classe» tornaram-se regras de uma só classe. Aqui juntam-se as que são iguais e
 * pertencem ao mesmo grupo de classes por defeito (3.ª a 5.ª, 7.ª e 8.ª, Creche e Pré-Escolar…): ficam uma regra com o
 * nome do grupo e as classes todas ligadas. Regras do mesmo grupo que a escola tenha tornado diferentes não se tocam.
 */
return new class extends Migration
{
    private const GRUPOS = [
        ['Creche e Pré-Escolar', ['CRECHE_I', 'CRECHE_II', 'PRE_I', 'PRE_II', 'PRE_III'], true],
        ['1.ª e 2.ª classe', ['PRI_1', 'PRI_2'], false],
        ['3.ª a 5.ª classe', ['PRI_3', 'PRI_4', 'PRI_5'], false],
        ['6.ª classe', ['PRI_6'], false],
        ['7.ª e 8.ª classe', ['SEC_7', 'SEC_8'], false],
        ['9.ª classe', ['SEC_9'], false],
    ];

    private const CAMPOS_NUMERICOS = ['escala_maxima', 'nota_minima', 'peso_mac', 'peso_pt', 'peso_mt1', 'peso_mt2', 'peso_mt3', 'peso_mfd_exame', 'peso_exame',
        'recurso_max_disciplinas', 'casas_decimais', 'modo_arredondamento', 'reprova_por_aproveitamento', 'com_exame', 'com_recurso'];

    private const CAMPOS_QUALITATIVOS = ['escala_id', 'progressao'];

    public function up(): void
    {
        $anos = DB::table('avaliacao_regra_niveis')->select('tenant_id', 'ano_lectivo_id')->distinct()->get();

        foreach ($anos as $ano) {
            foreach (self::GRUPOS as [$nome, $codigos, $qualitativa]) {
                $this->juntar($ano->tenant_id, $ano->ano_lectivo_id, $nome, $codigos, $qualitativa);
            }
        }
    }

    private function juntar(int $tenantId, int $anoId, string $nome, array $codigos, bool $qualitativa): void
    {
        $coluna = $qualitativa ? 'regra_qualitativa_id' : 'regra_id';
        $tabela = $qualitativa ? 'avaliacao_regras_qualitativas' : 'avaliacao_regras';

        $ids = DB::table('avaliacao_regra_niveis as l')->join('niveis_academicos as n', 'n.id', '=', 'l.nivel_academico_id')
            ->where('l.tenant_id', $tenantId)->where('l.ano_lectivo_id', $anoId)->whereIn('n.codigo', $codigos)->whereNotNull("l.{$coluna}")
            ->orderBy("l.{$coluna}")->pluck("l.{$coluna}")->unique()->values();

        if ($ids->isEmpty()) {
            return;
        }

        $regras = DB::table($tabela)->whereIn('id', $ids)->orderBy('id')->get();
        $campos = $qualitativa ? self::CAMPOS_QUALITATIVOS : self::CAMPOS_NUMERICOS;
        $assinatura = fn ($r) => json_encode([array_map(fn ($c) => $r->{$c} === null ? null : (string) $r->{$c}, $campos), $qualitativa ? [] : $this->chave($r->id)]);

        if ($regras->map($assinatura)->unique()->count() !== 1) {
            return;
        }

        $fica = $regras->first();

        foreach ($regras->slice(1) as $sai) {
            DB::table('avaliacao_regra_niveis')->where($coluna, $sai->id)->update([$coluna => $fica->id]);
            DB::table($tabela)->where('id', $sai->id)->delete();
        }

        DB::table($tabela)->where('id', $fica->id)->update(['nome' => $nome]);
    }

    /** As disciplinas-chave da regra, ordenadas, para comparar regras. */
    private function chave(int $regraId): array
    {
        return DB::table('avaliacao_regra_disciplinas_chave')->where('regra_id', $regraId)->orderBy('disciplina_id')->pluck('disciplina_id')->all();
    }

    public function down(): void
    {
        // As regras juntadas continuam válidas: não se voltam a separar.
    }
};
