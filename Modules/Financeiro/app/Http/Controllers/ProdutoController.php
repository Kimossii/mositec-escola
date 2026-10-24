<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Core\Enums\Estado;
use Modules\Financeiro\Http\Requests\AlterarEstadoProdutoRequest;
use Modules\Financeiro\Http\Requests\AtualizarProdutoRequest;
use Modules\Financeiro\Http\Requests\CriarProdutoRequest;
use Modules\Financeiro\Models\Produto;
use Modules\Financeiro\Services\GestaoProdutoService;

class ProdutoController extends Controller
{
    public function __construct(
        private GestaoProdutoService $service,
    ) {
    }

    public function store(CriarProdutoRequest $request)
    {
        $this->authorize('catalogo-financeiro.criar');

        $this->service->criar($request);

        return redirect()->back()->with('success', 'Produto criado com sucesso.');
    }

    public function update(AtualizarProdutoRequest $request, Produto $produto)
    {
        $this->authorize('catalogo-financeiro.editar');

        $this->service->atualizar($produto, $request);

        return redirect()->back()->with('success', 'Produto atualizado com sucesso.');
    }

    public function alterarEstado(AlterarEstadoProdutoRequest $request, Produto $produto)
    {
        $this->authorize('catalogo-financeiro.editar');

        $this->service->alterarEstado($produto, Estado::from((int) $request->validated('estado')));

        return redirect()->back()->with('success', 'Estado do produto atualizado com sucesso.');
    }

    public function destroy(Produto $produto)
    {
        $this->authorize('catalogo-financeiro.eliminar');

        $this->service->eliminar($produto);

        return redirect()->back()->with('success', 'Produto eliminado com sucesso.');
    }
}
