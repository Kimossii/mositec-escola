<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\Actions\AnularPagamentoAction;
use Modules\Financeiro\Actions\RegistarPagamentoAction;
use Modules\Financeiro\DTO\MotivoDeAnulacaoDTO;
use Modules\Financeiro\DTO\RegistarPagamentoDTO;
use Modules\Financeiro\Http\Requests\AnularPagamentoRequest;
use Modules\Financeiro\Http\Requests\PreVisualizarPagamentoRequest;
use Modules\Financeiro\Http\Requests\RegistarPagamentoRequest;
use Modules\Financeiro\Models\Pagamento;
use Modules\Financeiro\Services\DistribuicaoDePagamentoService;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Services\PagamentoConsultaService;
use Modules\Financeiro\Support\Moeda;
use Modules\Matricula\Models\Matricula;

class PagamentoController extends Controller
{
    public function __construct(
        private PagamentoConsultaService $consulta,
        private DistribuicaoDePagamentoService $distribuicao,
        private RegistarPagamentoAction $registar,
        private AnularPagamentoAction $anularAction,
        private MoedaDoTenant $moedaDoTenant,
        private RelogioDoTenant $relogio,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('pagamento.ver');

        $filtros = $request->only(['pesquisa', 'metodo_pagamento_id', 'estado', 'data_de', 'data_ate', 'referencia', 'aluno_id', 'matricula_id']);

        return Inertia::render('Financeiro/Pagamentos/Index', [
            'pagamentos' => $this->consulta->listar($filtros),
            'filtros' => $filtros,
            'metodos' => $this->consulta->metodos(),
            'estados' => $this->consulta->estados(),
            'moeda' => $this->moedaDoTenant->atual()->paraFrontend(),
            'podeRegistar' => $request->user()?->can('pagamento.criar') ?? false,
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('pagamento.criar');

        return Inertia::render('Financeiro/Pagamentos/Create', [
            'metodos' => $this->consulta->metodosActivos(),
            'moeda' => $this->moedaDoTenant->atual()->paraFrontend(),
            'hoje' => $this->relogio->hoje()->toDateString(),
            'matriculaInicial' => $request->filled('matricula') ? $this->consulta->resumoDaMatricula($request->integer('matricula')) : null,
        ]);
    }

    public function pesquisarMatriculas(Request $request): JsonResponse
    {
        $this->authorize('pagamento.criar');

        return response()->json(['matriculas' => $this->consulta->pesquisarMatriculas((string) $request->query('q', ''))]);
    }

    public function situacao(Matricula $matricula): JsonResponse
    {
        $this->authorize('pagamento.criar');

        return response()->json(['situacao' => $this->consulta->situacao($matricula)]);
    }

    public function preVisualizar(PreVisualizarPagamentoRequest $request, Matricula $matricula): JsonResponse
    {
        $this->authorize('pagamento.criar');

        $dto = RegistarPagamentoDTO::fromRequest($request);
        $aviso = $this->distribuicao->avisoDeReferencia($dto->metodoPagamentoId, $dto->referencia); // O1: não bloqueia

        return response()->json([
            'plano' => $this->distribuicao->preVisualizarPagamento($matricula, $dto)->toArray(),
            'avisos' => $aviso === null ? [] : [$aviso],
        ]);
    }

    public function store(RegistarPagamentoRequest $request, Matricula $matricula)
    {
        $this->authorize('pagamento.criar');

        $dto = RegistarPagamentoDTO::fromRequest($request);
        $aviso = $this->distribuicao->avisoDeReferencia($dto->metodoPagamentoId, $dto->referencia); // O1: antes de registar; não bloqueia

        $pagamento = $this->registar->executar($matricula, $dto);

        $resposta = redirect()->route('financeiro.pagamentos.show', $pagamento->id)->with('success', "Pagamento n.º {$pagamento->numero} registado.");

        return $aviso === null ? $resposta : $resposta->with('warning', $aviso);
    }

    public function show(Request $request, Pagamento $pagamento)
    {
        $this->authorize('pagamento.ver');

        $detalhe = $this->consulta->detalhe($pagamento);
        $podeAnular = $request->user()?->can('pagamento.anular') ?? false;

        return Inertia::render('Financeiro/Pagamentos/Show', [
            'pagamento' => $detalhe,
            'moeda' => Moeda::de($pagamento->moeda)->paraFrontend(),
            // O servidor decide: permissão E pagamento anulável (o botão nunca aparece para um 422 certo).
            'podeAnular' => $podeAnular && $detalhe['anulacao_bloqueada'] === null,
            'podeAnularUtilizacoes' => $podeAnular,
        ]);
    }

    public function anular(AnularPagamentoRequest $request, Pagamento $pagamento)
    {
        $this->authorize('pagamento.anular');

        $this->anularAction->executar($pagamento, MotivoDeAnulacaoDTO::fromRequest($request), $request->user()?->id);

        return redirect()->back()->with('success', 'Pagamento anulado.');
    }
}
