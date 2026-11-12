<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Financeiro\Http\Requests\GuardarBolsaRequest;
use Modules\Financeiro\Http\Requests\TerminarBolsaRequest;
use Modules\Financeiro\Models\Bolsa;
use Modules\Financeiro\Services\BolsaConsultaService;
use Modules\Financeiro\Services\CobrancaConsultaService;
use Modules\Financeiro\Services\GestaoBolsaService;
use Modules\Financeiro\Services\MoedaDoTenant;

class BolsaController extends Controller
{
    public function __construct(
        private BolsaConsultaService $consulta,
        private CobrancaConsultaService $matriculas,
        private GestaoBolsaService $gestao,
        private MoedaDoTenant $moeda,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('bolsa.ver');

        $filtros = $request->only(['pesquisa', 'estado', 'ano_lectivo_id']);

        return Inertia::render('Financeiro/Bolsas/Index', [
            'bolsas' => $this->consulta->listar($filtros),
            'indicadores' => Inertia::defer(fn () => $this->consulta->indicadores($filtros)),
            'filtros' => $filtros,
            'estados' => $this->consulta->estados(),
            'anosLectivos' => $this->consulta->anosLectivos(),
            'moeda' => $this->moeda->atual()->paraFrontend(),
        ]);
    }

    public function pesquisarMatriculas(Request $request): JsonResponse
    {
        $this->authorize('bolsa.criar');

        return response()->json(['matriculas' => $this->matriculas->pesquisarMatriculas((string) $request->query('q', ''))]);
    }

    public function store(GuardarBolsaRequest $request)
    {
        $this->gestao->guardar($request);

        return redirect()->back()->with('success', 'Bolsa criada. Aplica-se às propinas geradas daqui em diante.');
    }

    public function update(GuardarBolsaRequest $request, Bolsa $bolsa)
    {
        $this->gestao->guardar($request, $bolsa);

        return redirect()->back()->with('success', 'Bolsa actualizada. As propinas já geradas não mudam.');
    }

    public function terminar(TerminarBolsaRequest $request, Bolsa $bolsa)
    {
        $this->gestao->terminar($bolsa, $request);

        return redirect()->back()->with('success', 'Bolsa terminada.');
    }

    public function aplicar(Request $request, Bolsa $bolsa)
    {
        $this->authorize('bolsa.editar');

        $r = $this->gestao->aplicar($bolsa);

        return redirect()->back()->with('success', "Bolsa aplicada a {$r['aplicadas']} propina(s) em aberto" . ($r['ignoradas'] > 0 ? " ({$r['ignoradas']} ignorada(s))." : '.'));
    }
}
