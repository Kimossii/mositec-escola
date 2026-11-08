<?php

namespace Modules\Avaliacao\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Avaliacao\Models\AvaliacaoRegra;
use Modules\Avaliacao\Models\AvaliacaoRegraQualitativa;
use Modules\Core\Traits\LogService;

/** Elimina uma regra que já não tem classes ligadas (os prazos dela vão com ela). */
class EliminarRegraAvaliacaoAction
{
    use LogService;

    public function executar(int $regraId, bool $qualitativa): void
    {
        $this->comLog('Eliminar regra de avaliação', $this->contextoDeArgumentos(func_get_args(), 'executar'), function () use ($regraId, $qualitativa) {
            $regra = $qualitativa ? AvaliacaoRegraQualitativa::query()->findOrFail($regraId) : AvaliacaoRegra::query()->findOrFail($regraId);

            if ($regra->niveis()->exists()) {
                throw ValidationException::withMessages(['regra_id' => 'Tire primeiro as classes desta regra, ou passe-as para outra.']);
            }

            $regra->delete();
        });
    }
}
