<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Aluno\Models\Aluno;
use Modules\Financeiro\Actions\AnularUtilizacaoCreditoAction;
use Modules\Financeiro\Actions\AplicarCreditoAction;
use Modules\Financeiro\DTO\AplicarCreditoDTO;
use Modules\Financeiro\DTO\MotivoDeAnulacaoDTO;
use Modules\Financeiro\Http\Requests\AnularUtilizacaoCreditoRequest;
use Modules\Financeiro\Http\Requests\AplicarCreditoRequest;
use Modules\Financeiro\Models\CreditoUtilizacao;
use Modules\Financeiro\Services\DistribuicaoDePagamentoService;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Services\PagamentoConsultaService;
use Modules\Matricula\Models\Matricula;

class CreditoController extends Controller
{
    public function __construct(
        private PagamentoConsultaService $consulta,
        private DistribuicaoDePagamentoService $distribuicao,
        private AplicarCreditoAction $aplicarAction,
        private AnularUtilizacaoCreditoAction $anularAction,
        private MoedaDoTenant $moedaDoTenant,
    ) {
    }

    public function aluno(Request $request, Aluno $aluno)
    {
        $this->authorize('pagamento.ver');

        return Inertia::render('Financeiro/Creditos/Aluno', [
            ...$this->consulta->creditosDoAluno($aluno),
            'moeda' => $this->moedaDoTenant->atual()->paraFrontend(),
            'podeAplicar' => $request->user()?->can('pagamento.criar') ?? false,
            'podeAnular' => $request->user()?->can('pagamento.anular') ?? false,
        ]);
    }

    public function preVisualizar(AplicarCreditoRequest $request, Matricula $matricula): JsonResponse
    {
        $this->authorize('pagamento.criar');

        return response()->json(['plano' => $this->distribuicao->preVisualizarCredito($matricula, AplicarCreditoDTO::fromRequest($request))->toArray()]);
    }

    public function aplicar(AplicarCreditoRequest $request, Matricula $matricula)
    {
        $this->authorize('pagamento.criar');

        $this->aplicarAction->executar($matricula, AplicarCreditoDTO::fromRequest($request));

        return redirect()->back()->with('success', 'Crédito aplicado.');
    }

    public function anularUtilizacao(AnularUtilizacaoCreditoRequest $request, CreditoUtilizacao $utilizacao)
    {
        $this->authorize('pagamento.anular');

        $this->anularAction->executar($utilizacao, MotivoDeAnulacaoDTO::fromRequest($request), $request->user()?->id);

        return redirect()->back()->with('success', 'Utilização de crédito anulada.');
    }
}
