<?php

namespace Modules\Avaliacao\Services;

use Modules\Avaliacao\Actions\AprovarPautaAction;
use Modules\Avaliacao\Actions\DecidirProgressaoAction;
use Modules\Avaliacao\Actions\ReabrirPautaAction;
use Modules\Avaliacao\DTO\DecidirProgressaoDTO;
use Modules\Avaliacao\DTO\ReabrirPautaDTO;
use Modules\Avaliacao\Enums\Decisao;
use Modules\Avaliacao\Enums\FasePauta;
use Modules\Avaliacao\Http\Requests\DecidirProgressaoRequest;
use Modules\Avaliacao\Http\Requests\ReabrirPautaRequest;

/** Os pedidos de escrita da pauta final: aprovar, reabrir e decidir a progressão das crianças. */
class GestaoPautaService
{
    public function __construct(
        private AprovarPautaAction $aprovar,
        private ReabrirPautaAction $reabrir,
        private DecidirProgressaoAction $decidirProgressao,
    ) {
    }

    public function aprovar(int $turmaId, FasePauta $fase = FasePauta::EPOCA_NORMAL): void
    {
        $this->aprovar->executar($turmaId, $fase);
    }

    public function reabrir(int $turmaId, ReabrirPautaRequest $request): void
    {
        $d = $request->validated();

        $this->reabrir->executar(new ReabrirPautaDTO($turmaId, $d['motivo'], FasePauta::from((int) ($d['fase'] ?? 1))));
    }

    public function decidirProgressao(int $turmaId, DecidirProgressaoRequest $request): void
    {
        $d = $request->validated();

        $this->decidirProgressao->executar(new DecidirProgressaoDTO($turmaId, (int) $d['matricula_id'], Decisao::from((int) $d['decisao']), $d['motivo'] ?? null));
    }
}
