<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Financeiro\Models\Acordo;
use Modules\Financeiro\Http\Requests\CriarAcordoRequest;
use Modules\Financeiro\Http\Requests\EncerrarAcordoRequest;
use Modules\Financeiro\Services\AcordoConsultaService;
use Modules\Financeiro\Services\CobrancaConsultaService;
use Modules\Financeiro\Services\GestaoAcordoService;
use Modules\Financeiro\Services\MoedaDoTenant;

class AcordoController extends Controller
{
    public function __construct(
        private AcordoConsultaService $consulta,
        private CobrancaConsultaService $matriculas,
        private GestaoAcordoService $gestao,
        private MoedaDoTenant $moeda,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('acordo.ver');

        $filtros = $request->only(['pesquisa', 'estado']);

        return Inertia::render('Financeiro/Acordos/Index', [
            'acordos' => $this->consulta->listar($filtros),
            'indicadores' => Inertia::defer(fn () => $this->consulta->indicadores()),
            'filtros' => $filtros,
            'moeda' => $this->moeda->atual()->paraFrontend(),
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('acordo.criar');

        return Inertia::render('Financeiro/Acordos/Create', $this->consulta->formulario($request->filled('matricula') ? $request->integer('matricula') : null));
    }

    public function pesquisarMatriculas(Request $request): JsonResponse
    {
        $this->authorize('acordo.criar');

        return response()->json(['matriculas' => $this->matriculas->pesquisarMatriculas((string) $request->query('q', ''), incluirCanceladas: true)]);
    }

    public function propinas(Matricula $matricula): JsonResponse
    {
        $this->authorize('acordo.criar');

        return response()->json(['propinas' => $this->consulta->propinasEmDivida($matricula)]);
    }

    public function store(CriarAcordoRequest $request)
    {
        $acordo = $this->gestao->criar($request, $request->user()?->id);

        return redirect()->route('financeiro.acordos.show', $acordo->id)->with('success', 'Acordo criado. As multas ficam congeladas e o pagamento parcial é permitido nestas propinas.');
    }

    public function show(Request $request, Acordo $acordo)
    {
        $this->authorize('acordo.ver');

        return Inertia::render('Financeiro/Acordos/Show', [
            'acordo' => $this->consulta->detalhe($acordo),
            'moeda' => $this->moeda->atual()->paraFrontend(),
            'podeCancelar' => $request->user()?->can('acordo.cancelar') ?? false,
            'podePagar' => $request->user()?->can('pagamento.criar') ?? false,
        ]);
    }

    public function encerrar(EncerrarAcordoRequest $request, Acordo $acordo)
    {
        $this->gestao->encerrar($acordo, $request);

        return redirect()->route('financeiro.acordos.show', $acordo->id)->with('success', $request->quebra() ? 'Acordo quebrado. As multas voltam a incidir.' : 'Acordo cancelado.');
    }
}
