<?php

namespace Modules\Financeiro\Services;

use Modules\Core\Enums\Estado;
use Modules\Financeiro\Actions\AlterarEstadoMetodoPagamentoAction;
use Modules\Financeiro\Actions\AtualizarMetodoPagamentoAction;
use Modules\Financeiro\Actions\CriarMetodoPagamentoAction;
use Modules\Financeiro\Actions\EliminarMetodoPagamentoAction;
use Modules\Financeiro\DTO\MetodoPagamentoDTO;
use Modules\Financeiro\Http\Requests\AtualizarMetodoPagamentoRequest;
use Modules\Financeiro\Http\Requests\CriarMetodoPagamentoRequest;
use Modules\Financeiro\Models\MetodoPagamento;

class GestaoMetodoPagamentoService
{
    public function __construct(
        private CriarMetodoPagamentoAction $criarAction,
        private AtualizarMetodoPagamentoAction $atualizarAction,
        private AlterarEstadoMetodoPagamentoAction $alterarEstadoAction,
        private EliminarMetodoPagamentoAction $eliminarAction,
    ) {
    }

    public function criar(CriarMetodoPagamentoRequest $request): MetodoPagamento
    {
        return $this->criarAction->executar(MetodoPagamentoDTO::fromRequest($request));
    }

    public function atualizar(MetodoPagamento $metodo, AtualizarMetodoPagamentoRequest $request): MetodoPagamento
    {
        return $this->atualizarAction->executar($metodo, MetodoPagamentoDTO::fromRequest($request));
    }

    public function alterarEstado(MetodoPagamento $metodo, Estado $estado): MetodoPagamento
    {
        return $this->alterarEstadoAction->executar($metodo, $estado);
    }

    public function eliminar(MetodoPagamento $metodo): void
    {
        $this->eliminarAction->executar($metodo);
    }
}
