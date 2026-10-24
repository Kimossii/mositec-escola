<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\Actions\CriarAcordoAction;
use Modules\Financeiro\Actions\EncerrarAcordoAction;
use Modules\Financeiro\Enums\EstadoAcordo;
use Modules\Financeiro\Models\Acordo;
use Modules\Financeiro\Models\RegraCobranca;
use Modules\Financeiro\Services\AcordoConsultaService;
use Modules\Financeiro\Services\CobrancaConsultaService;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Matricula\Models\Matricula;

class AcordoController extends Controller
{
    public function __construct(
        private AcordoConsultaService $consulta,
        private CobrancaConsultaService $matriculas,
        private CriarAcordoAction $criar,
        private EncerrarAcordoAction $encerrar,
        private MoedaDoTenant $moeda,
        private RelogioDoTenant $relogio,
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

        $regras = RegraCobranca::query()->first();
        $matricula = $request->filled('matricula') ? Matricula::query()->find($request->integer('matricula')) : null;

        return Inertia::render('Financeiro/Acordos/Create', [
            'permiteNegociacao' => (bool) ($regras?->permite_negociacao ?? false),
            'descontoMaximo' => (int) ($regras?->desconto_maximo_negociacao ?? 0),
            'moeda' => $this->moeda->atual()->paraFrontend(),
            'hoje' => $this->relogio->hoje()->toDateString(),
            'matriculaInicial' => $matricula === null ? null : $this->matriculas->resumoDaMatricula($matricula),
        ]);
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

    public function store(Request $request)
    {
        $this->authorize('acordo.criar');

        $dados = $request->validate([
            'matricula_id' => ['required', 'integer', Rule::exists('matriculas', 'id')],
            'propinas' => ['required', 'array', 'min:1'],
            'propinas.*' => ['integer'],
            'desconto_multa_pct' => ['required', 'integer', 'between:0,100'],
            'prestacoes' => ['required', 'integer', 'between:1,' . CriarAcordoAction::MAX_PRESTACOES],
            'primeiro_vencimento' => ['required', 'date'],
            'motivo' => ['required', 'string', 'min:5', 'max:1000'],
        ], [
            'propinas.required' => 'Escolha pelo menos uma propina.',
            'propinas.min' => 'Escolha pelo menos uma propina.',
            'primeiro_vencimento.required' => 'Indique a data da primeira prestação.',
            'motivo.required' => 'Indique o motivo do acordo.',
            'motivo.min' => 'Indique o motivo (mínimo 5 caracteres).',
        ]);

        $acordo = $this->criar->executar(Matricula::query()->findOrFail($dados['matricula_id']), $dados['propinas'], (int) $dados['desconto_multa_pct'], (int) $dados['prestacoes'], $dados['primeiro_vencimento'], $dados['motivo'], $request->user()?->id);

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

    public function encerrar(Request $request, Acordo $acordo)
    {
        $this->authorize('acordo.cancelar');

        $dados = $request->validate(['destino' => ['required', Rule::in(['cancelar', 'quebrar'])], 'motivo' => ['required', 'string', 'min:5', 'max:1000']], ['motivo.required' => 'Indique o motivo.', 'motivo.min' => 'Indique o motivo (mínimo 5 caracteres).']);

        $this->encerrar->executar($acordo, $dados['destino'] === 'quebrar' ? EstadoAcordo::QUEBRADO : EstadoAcordo::CANCELADO, $dados['motivo']);

        return redirect()->route('financeiro.acordos.show', $acordo->id)->with('success', $dados['destino'] === 'quebrar' ? 'Acordo quebrado. As multas voltam a incidir.' : 'Acordo cancelado.');
    }
}
