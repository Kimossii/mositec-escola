<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Financeiro\Actions\AplicarBolsaAction;
use Modules\Financeiro\Actions\GuardarBolsaAction;
use Modules\Financeiro\Actions\TerminarBolsaAction;
use Modules\Financeiro\Http\Requests\GuardarBolsaRequest;
use Modules\Financeiro\Models\Bolsa;
use Modules\Financeiro\Services\BolsaConsultaService;
use Modules\Financeiro\Services\CobrancaConsultaService;

class BolsaController extends Controller
{
    public function __construct(
        private BolsaConsultaService $consulta,
        private CobrancaConsultaService $matriculas,
        private GuardarBolsaAction $guardar,
        private TerminarBolsaAction $terminar,
        private AplicarBolsaAction $aplicar,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('bolsa.ver');

        $filtros = $request->only(['pesquisa', 'estado', 'ano_lectivo_id']);

        return Inertia::render('Financeiro/Bolsas/Index', [
            'bolsas' => $this->consulta->listar($filtros),
            'filtros' => $filtros,
            'estados' => $this->consulta->estados(),
            'anosLectivos' => AnoLectivo::query()->orderByDesc('data_inicio')->get(['id', 'nome', 'data_inicio', 'data_fim']),
        ]);
    }

    public function pesquisarMatriculas(Request $request): JsonResponse
    {
        $this->authorize('bolsa.criar');

        return response()->json(['matriculas' => $this->matriculas->pesquisarMatriculas((string) $request->query('q', ''))]);
    }

    public function store(GuardarBolsaRequest $request)
    {
        $this->guardar->executar($request->validated());

        return redirect()->back()->with('success', 'Bolsa criada. Aplica-se às propinas geradas daqui em diante.');
    }

    public function update(GuardarBolsaRequest $request, Bolsa $bolsa)
    {
        $this->guardar->executar($request->validated(), $bolsa);

        return redirect()->back()->with('success', 'Bolsa actualizada. As propinas já geradas não mudam.');
    }

    public function terminar(Request $request, Bolsa $bolsa)
    {
        $this->authorize('bolsa.editar');
        $dados = $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:1000']], ['motivo.required' => 'Indique o motivo.', 'motivo.min' => 'Indique o motivo (mínimo 5 caracteres).']);

        $this->terminar->executar($bolsa, $dados['motivo']);

        return redirect()->back()->with('success', 'Bolsa terminada.');
    }

    public function aplicar(Request $request, Bolsa $bolsa)
    {
        $this->authorize('bolsa.editar');

        $r = $this->aplicar->executar($bolsa);

        return redirect()->back()->with('success', "Bolsa aplicada a {$r['aplicadas']} propina(s) em aberto" . ($r['ignoradas'] > 0 ? " ({$r['ignoradas']} ignorada(s))." : '.'));
    }
}
