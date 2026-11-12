<?php

namespace Modules\Avaliacao\Actions;

use Modules\AnoLectivo\Models\Periodo;
use Modules\Avaliacao\DTO\DefinirPrazoDTO;
use Modules\Avaliacao\Models\AvaliacaoPrazo;
use Modules\Core\Traits\LogService;

/** Define (ou altera) o prazo normal de lançamento da escola, por trimestre e tipo. */
class DefinirPrazoAction
{
    use LogService;

    public function executar(DefinirPrazoDTO $dto): AvaliacaoPrazo
    {
        return $this->comLog('Definir prazo de avaliação', $this->contextoDeArgumentos(func_get_args(), 'executar'), function () use ($dto) {
            $periodo = Periodo::query()->findOrFail($dto->periodo_id);

            return AvaliacaoPrazo::updateOrCreate(
                ['periodo_id' => $periodo->id, 'tipo' => $dto->tipo],
                ['data_limite' => $dto->data_limite],
            );
        });
    }
}
