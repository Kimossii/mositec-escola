<?php

namespace Modules\Avaliacao\Actions;

use Illuminate\Validation\ValidationException;
use Modules\AnoLectivo\Models\Periodo;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoPrazo;
use Modules\Avaliacao\Models\AvaliacaoRegra;
use Modules\Core\Traits\LogService;

/** Define (ou altera) o prazo normal de lançamento de uma regra, por trimestre e tipo. */
class DefinirPrazoAction
{
    use LogService;

    public function executar(int $regraId, int $periodoId, TipoInstrumento $tipo, string $dataLimite): AvaliacaoPrazo
    {
        return $this->comLog('Definir prazo de avaliação', $this->contextoDeArgumentos(func_get_args(), 'executar'), function () use ($regraId, $periodoId, $tipo, $dataLimite) {
            $regra = AvaliacaoRegra::query()->findOrFail($regraId);
            $periodo = Periodo::query()->findOrFail($periodoId);

            if ($periodo->ano_lectivo_id !== $regra->ano_lectivo_id) {
                throw ValidationException::withMessages(['periodo_id' => 'O período não pertence ao ano lectivo da regra.']);
            }

            return AvaliacaoPrazo::updateOrCreate(
                ['regra_id' => $regra->id, 'periodo_id' => $periodo->id, 'tipo' => $tipo],
                ['data_limite' => $dataLimite],
            );
        });
    }
}
