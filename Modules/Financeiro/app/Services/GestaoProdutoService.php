<?php

namespace Modules\Financeiro\Services;

use Modules\Core\Enums\Estado;
use Modules\Financeiro\Actions\AlterarEstadoProdutoAction;
use Modules\Financeiro\Actions\AtualizarProdutoAction;
use Modules\Financeiro\Actions\CriarProdutoAction;
use Modules\Financeiro\Actions\EliminarProdutoAction;
use Modules\Financeiro\DTO\ProdutoDTO;
use Modules\Financeiro\Http\Requests\AtualizarProdutoRequest;
use Modules\Financeiro\Http\Requests\CriarProdutoRequest;
use Modules\Financeiro\Models\Produto;

class GestaoProdutoService
{
    public function __construct(
        private CriarProdutoAction $criarAction,
        private AtualizarProdutoAction $atualizarAction,
        private AlterarEstadoProdutoAction $alterarEstadoAction,
        private EliminarProdutoAction $eliminarAction,
    ) {
    }

    public function criar(CriarProdutoRequest $request): Produto
    {
        return $this->criarAction->executar(ProdutoDTO::fromRequest($request));
    }

    public function atualizar(Produto $produto, AtualizarProdutoRequest $request): Produto
    {
        return $this->atualizarAction->executar($produto, ProdutoDTO::fromRequest($request));
    }

    public function alterarEstado(Produto $produto, Estado $estado): Produto
    {
        return $this->alterarEstadoAction->executar($produto, $estado);
    }

    public function eliminar(Produto $produto): void
    {
        $this->eliminarAction->executar($produto);
    }
}
