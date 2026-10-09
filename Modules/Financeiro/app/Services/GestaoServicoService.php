<?php

namespace Modules\Financeiro\Services;

use Modules\Core\Enums\Estado;
use Modules\Financeiro\Actions\AlterarEstadoServicoAction;
use Modules\Financeiro\Actions\AtualizarServicoAction;
use Modules\Financeiro\Actions\CriarServicoAction;
use Modules\Financeiro\Actions\EliminarServicoAction;
use Modules\Financeiro\DTO\ServicoDTO;
use Modules\Financeiro\Http\Requests\AtualizarServicoRequest;
use Modules\Financeiro\Http\Requests\CriarServicoRequest;
use Modules\Financeiro\Models\Servico;

class GestaoServicoService
{
    public function __construct(
        private CriarServicoAction $criarAction,
        private AtualizarServicoAction $atualizarAction,
        private AlterarEstadoServicoAction $alterarEstadoAction,
        private EliminarServicoAction $eliminarAction,
    ) {
    }

    public function criar(CriarServicoRequest $request): Servico
    {
        return $this->criarAction->executar(ServicoDTO::fromRequest($request));
    }

    public function atualizar(Servico $servico, AtualizarServicoRequest $request): Servico
    {
        return $this->atualizarAction->executar($servico, ServicoDTO::fromRequest($request));
    }

    public function alterarEstado(Servico $servico, Estado $estado): Servico
    {
        return $this->alterarEstadoAction->executar($servico, $estado);
    }

    public function eliminar(Servico $servico): void
    {
        $this->eliminarAction->executar($servico);
    }
}
