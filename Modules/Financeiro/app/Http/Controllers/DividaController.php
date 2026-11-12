<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Core\Exportacao\Exportador;
use Modules\Financeiro\Http\Requests\ExportarDividasRequest;
use Modules\Financeiro\Services\DividasConsultaService;
use Modules\Financeiro\Services\ExportacaoDeDividasService;
use Modules\Financeiro\Services\MoedaDoTenant;

class DividaController extends Controller
{
    public function __construct(private DividasConsultaService $consulta, private MoedaDoTenant $moeda)
    {
    }

    public function index(Request $request)
    {
        $this->authorize('propina.ver');

        $filtros = $request->only(['pesquisa', 'ano_lectivo_id', 'turma_id', 'tipo']);

        return Inertia::render('Financeiro/Dividas/Index', [
            'dividas' => $this->consulta->listar($filtros),
            // Diferido: os totais agregam todas as dívidas e preenchem-se depois de a lista abrir.
            'totais' => Inertia::defer(fn () => $this->consulta->totais($filtros)),
            'filtros' => $filtros,
            ...$this->consulta->opcoesDeFiltro(),
            'moeda' => $this->moeda->atual()->paraFrontend(),
        ]);
    }

    public function exportar(ExportarDividasRequest $request, Exportador $exportador, ExportacaoDeDividasService $exportacao)
    {
        return $exportador->responder($exportacao->tabela($request->filtros()), $request->validated()['formato'], 'dividas', 'pagamento.exportar');
    }
}
