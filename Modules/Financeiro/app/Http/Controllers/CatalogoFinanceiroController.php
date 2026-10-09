<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Financeiro\Services\CatalogoConsultaService;

class CatalogoFinanceiroController extends Controller
{
    public function __construct(
        private CatalogoConsultaService $consulta,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('catalogo-financeiro.ver');

        $filtros = $request->only(['pesquisa', 'estado', 'tipo']);

        return Inertia::render('Financeiro/ProdutosServicos/Index', [
            'itens' => $this->consulta->listar($filtros),
            'filtros' => $filtros,
        ]);
    }
}
