<?php

namespace Modules\Financeiro\Services;

use Modules\Financeiro\Actions\AtualizarRegraCobrancaAction;
use Modules\Financeiro\DTO\RegraCobrancaDTO;
use Modules\Financeiro\Http\Requests\AtualizarRegraCobrancaRequest;
use Modules\Financeiro\Models\RegraCobranca;

class GestaoRegraCobrancaService
{
    public function __construct(
        private AtualizarRegraCobrancaAction $atualizarAction,
    ) {
    }

    public function obterAtual(): RegraCobranca
    {
        return RegraCobranca::doTenant();
    }

    public function atualizar(AtualizarRegraCobrancaRequest $request): RegraCobranca
    {
        return $this->atualizarAction->executar(RegraCobrancaDTO::fromRequest($request));
    }
}
