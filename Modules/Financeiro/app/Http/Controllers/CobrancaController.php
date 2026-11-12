<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\Http\Requests\CancelarCobrancaRequest;
use Modules\Financeiro\Http\Requests\CriarCobrancaRequest;
use Modules\Financeiro\Http\Requests\PagarCobrancaRequest;
use Modules\Financeiro\Models\Cobranca;
use Modules\Financeiro\Services\CobrancaConsultaService;
use Modules\Financeiro\Services\GestaoCobrancaService;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Services\PagamentoConsultaService;

class CobrancaController extends Controller
{
    public function __construct(
        private CobrancaConsultaService $consulta,
        private GestaoCobrancaService $gestao,
        private PagamentoConsultaService $pagamentos,
        private MoedaDoTenant $moeda,
        private RelogioDoTenant $relogio,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('cobranca.ver');

        $filtros = $request->only(['pesquisa', 'estado', 'origem']);

        return Inertia::render('Financeiro/Cobrancas/Index', [
            'cobrancas' => $this->consulta->listar($filtros),
            'indicadores' => Inertia::defer(fn () => $this->consulta->indicadores($filtros)),
            'filtros' => $filtros,
            'estados' => $this->consulta->estados(),
            'moeda' => $this->moeda->atual()->paraFrontend(),
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('cobranca.criar');

        return Inertia::render('Financeiro/Cobrancas/Create', [
            'catalogo' => $this->consulta->catalogo(),
            'metodos' => $this->pagamentos->metodosActivos(),
            'moeda' => $this->moeda->atual()->paraFrontend(),
            'hoje' => $this->relogio->hoje()->toDateString(),
            'matriculaInicial' => $this->consulta->resumoDaMatriculaPorId($request->filled('matricula') ? $request->integer('matricula') : null),
            // Para onde voltar (ex.: a ficha do aluno): lista branca, só a ficha de um aluno.
            'voltarPara' => preg_match('#^/alunos/\d+$#D', (string) $request->query('voltar')) === 1 ? (string) $request->query('voltar') : null,
        ]);
    }

    public function pesquisarMatriculas(Request $request): JsonResponse
    {
        $this->authorize('cobranca.criar');

        return response()->json(['matriculas' => $this->consulta->pesquisarMatriculas((string) $request->query('q', ''))]);
    }

    public function store(CriarCobrancaRequest $request)
    {
        $resultado = $this->gestao->criar($request, $request->user()?->id);

        return redirect()->route('financeiro.cobrancas.show', $resultado['cobranca']->id)
            ->with('success', $resultado['pagamento'] === null
                ? 'Cobrança criada. Fica em aberto até ser paga.'
                : "Cobrança criada e paga (pagamento n.º {$resultado['pagamento']->numero}).");
    }

    public function show(Request $request, Cobranca $cobranca)
    {
        $this->authorize('cobranca.ver');

        return Inertia::render('Financeiro/Cobrancas/Show', [
            'cobranca' => $this->consulta->detalhe($cobranca),
            'metodos' => $this->pagamentos->metodosActivos(),
            'moeda' => $this->moeda->atual()->paraFrontend(),
            'hoje' => $this->relogio->hoje()->toDateString(),
            'podeCriar' => $request->user()?->can('cobranca.criar') ?? false,
            'podeCancelar' => $request->user()?->can('cobranca.cancelar') ?? false,
        ]);
    }

    public function pagar(PagarCobrancaRequest $request, Cobranca $cobranca)
    {
        $pagamento = $this->gestao->pagar($cobranca, $request);

        return redirect()->route('financeiro.cobrancas.show', $cobranca->id)->with('success', "Cobrança paga (pagamento n.º {$pagamento->numero}).");
    }

    public function cancelar(CancelarCobrancaRequest $request, Cobranca $cobranca)
    {
        $this->gestao->cancelar($cobranca, $request->validated()['motivo'], $request->user()?->id);

        return redirect()->route('financeiro.cobrancas.show', $cobranca->id)->with('success', 'Cobrança cancelada.');
    }
}
