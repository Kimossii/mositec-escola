<?php

namespace Modules\Avaliacao\Actions;

use Illuminate\Support\Facades\DB;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Avaliacao\Models\AvaliacaoRegra;
use Modules\Core\Traits\LogService;

/** Copia as regras do ano lectivo imediatamente anterior para o ano destino, sem sobrescrever as já existentes. */
class CopiarRegrasDoAnoAnteriorAction
{
    use LogService;

    /** @return int número de regras copiadas */
    public function executar(int $anoDestinoId): int
    {
        return $this->comLog('Copiar regras de avaliação do ano anterior', $this->contextoDeArgumentos(func_get_args(), 'executar'), function () use ($anoDestinoId) {
            $destino = AnoLectivo::query()->findOrFail($anoDestinoId);

            $anterior = AnoLectivo::query()
                ->where('estabelecimento_id', $destino->estabelecimento_id)
                ->whereDate('data_fim', '<', $destino->data_inicio)
                ->orderByDesc('data_fim')
                ->first();

            if ($anterior === null) {
                return 0;
            }

            return DB::transaction(function () use ($anterior, $destino) {
                $jaTem = AvaliacaoRegra::query()->where('ano_lectivo_id', $destino->id)->pluck('nivel_academico_id')->all();
                $copiadas = 0;

                foreach (AvaliacaoRegra::query()->where('ano_lectivo_id', $anterior->id)->whereNotIn('nivel_academico_id', $jaTem)->get() as $regra) {
                    $nova = AvaliacaoRegra::create([
                        ...$regra->only([
                            'nivel_academico_id', 'escala_maxima', 'nota_minima', 'peso_mac', 'peso_pt', 'peso_mt1', 'peso_mt2',
                            'peso_mt3', 'peso_mfd_exame', 'peso_exame', 'recurso_max_disciplinas', 'casas_decimais', 'modo_arredondamento', 'reprova_por_aproveitamento',
                        ]),
                        'ano_lectivo_id' => $destino->id,
                    ]);
                    $nova->disciplinasChave()->syncWithPivotValues($regra->disciplinasChave()->pluck('disciplinas.id')->all(), ['tenant_id' => $nova->tenant_id]);
                    $copiadas++;
                }

                return $copiadas;
            });
        });
    }
}
