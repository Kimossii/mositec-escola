<?php

namespace Modules\Avaliacao\Actions;

use Illuminate\Validation\ValidationException;
use Modules\Avaliacao\Models\AvaliacaoRegra;
use Modules\Avaliacao\Services\RegraEmUsoService;
use Modules\Avaliacao\Models\AvaliacaoRegraQualitativa;
use Modules\Core\Traits\LogService;

/** Elimina uma regra que ainda não foi usada (sem avaliações, pautas ou registos nas turmas das suas classes): as classes ficam sem regra. */
class EliminarRegraAvaliacaoAction
{
    use LogService;

    public function __construct(private RegraEmUsoService $emUso)
    {
    }

    public function executar(int $regraId, bool $qualitativa): void
    {
        $this->comLog('Eliminar regra de avaliação', $this->contextoDeArgumentos(func_get_args(), 'executar'), function () use ($regraId, $qualitativa) {
            $regra = $qualitativa ? AvaliacaoRegraQualitativa::query()->findOrFail($regraId) : AvaliacaoRegra::query()->findOrFail($regraId);

            if ($this->emUso->emUso($regra)) {
                throw ValidationException::withMessages(['regra_id' => 'Esta regra já foi usada (há avaliações lançadas nas turmas das suas classes) e não pode ser eliminada.']);
            }

            $regra->delete();
        });
    }
}
