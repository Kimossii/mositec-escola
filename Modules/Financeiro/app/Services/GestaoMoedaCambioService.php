<?php

namespace Modules\Financeiro\Services;

use Modules\Financeiro\Actions\AtualizarConfiguracaoMonetariaAction;
use Modules\Financeiro\Actions\EliminarCambioAction;
use Modules\Financeiro\Actions\RegistarCambioAction;
use Modules\Financeiro\DTO\CambioDTO;
use Modules\Financeiro\DTO\ConfiguracaoMonetariaDTO;
use Modules\Financeiro\Http\Requests\AtualizarConfiguracaoMonetariaRequest;
use Modules\Financeiro\Http\Requests\RegistarCambioRequest;
use Modules\Financeiro\Models\Cambio;
use Modules\Financeiro\Models\ConfiguracaoMonetaria;

class GestaoMoedaCambioService
{
    public function __construct(
        private AtualizarConfiguracaoMonetariaAction $atualizarAction,
        private RegistarCambioAction $registarAction,
        private EliminarCambioAction $eliminarAction,
    ) {
    }

    public function atualizar(AtualizarConfiguracaoMonetariaRequest $request): ConfiguracaoMonetaria
    {
        return $this->atualizarAction->executar(ConfiguracaoMonetariaDTO::fromRequest($request));
    }

    public function registarCambio(RegistarCambioRequest $request): Cambio
    {
        return $this->registarAction->executar(CambioDTO::fromRequest($request));
    }

    public function eliminarCambio(Cambio $cambio): void
    {
        $this->eliminarAction->executar($cambio);
    }
}
