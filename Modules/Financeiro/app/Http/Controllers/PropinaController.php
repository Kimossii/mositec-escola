<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Financeiro\Actions\AnularPropinaAction;
use Modules\Financeiro\Actions\CancelarPropinaAction;
use Modules\Financeiro\Actions\GerarPropinasAction;
use Modules\Financeiro\Actions\IsentarReduzirMultaAction;
use Modules\Financeiro\DTO\CancelarPropinaDTO;
use Modules\Financeiro\DTO\IsentarMultaDTO;
use Modules\Financeiro\DTO\MotivoDeAnulacaoDTO;
use Modules\Financeiro\DTO\PedidoGeracaoPropinasDTO;
use Modules\Financeiro\Http\Requests\AnularPropinaRequest;
use Modules\Financeiro\Http\Requests\CancelarPropinaRequest;
use Modules\Financeiro\Http\Requests\GerarPropinasRequest;
use Modules\Financeiro\Http\Requests\IsentarReduzirMultaRequest;
use Modules\Financeiro\Http\Requests\PreVisualizarGeracaoPropinasRequest;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\GerarPropinasService;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Services\PlanoPropinaConsultaService;
use Modules\Financeiro\Services\PropinaConsultaService;
use Modules\Financeiro\Support\Moeda;

class PropinaController extends Controller
{
    public function __construct(
        private PropinaConsultaService $consulta,
        private PlanoPropinaConsultaService $planos,
        private GerarPropinasService $geracao,
        private GerarPropinasAction $gerarAction,
        private CancelarPropinaAction $cancelarAction,
        private AnularPropinaAction $anularAction,
        private IsentarReduzirMultaAction $isentarAction,
        private MoedaDoTenant $moedaDoTenant,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('propina.ver');

        $filtros = $request->only([
            'ano_lectivo_id', 'estado', 'pesquisa', 'plano_propina_id', 'matricula_id', 'origem_geracao', 'vencimento_de', 'vencimento_ate',
        ]);

        if (! $request->filled('ano_lectivo_id')) {
            $filtros['ano_lectivo_id'] = $this->planos->anoLectivoActualId();
        }

        return Inertia::render('Financeiro/Propinas/Index', [
            'propinas' => $this->consulta->listar($filtros),
            'filtros' => $filtros,
            'anosLectivos' => $this->planos->anosLectivos(),
            'anoLectivoActualId' => $this->planos->anoLectivoActualId(),
            'planos' => $this->consulta->planos(),
            'turmas' => $this->planos->turmas(),
            'niveis' => $this->planos->niveis(),
            'estados' => $this->consulta->estados(),
            'origens' => $this->consulta->origens(),
            'moeda' => $this->moedaDoTenant->atual()->paraFrontend(),
        ]);
    }

    public function show(Request $request, Propina $propina)
    {
        $this->authorize('propina.ver');

        $detalhe = $this->consulta->detalhe($propina);

        return Inertia::render('Financeiro/Propinas/Show', [
            'propina' => $detalhe,
            'moeda' => Moeda::de($propina->moeda)->paraFrontend(),
            // D13: decide o servidor — permissão E propina cancelável (o botão nunca aparece para um 422 certo).
            'podeCancelar' => ($request->user()?->can('propina.cancelar') ?? false) && $detalhe['cancelamento_bloqueado'] === null,
            'podeAnular' => ($request->user()?->can('propina.anular') ?? false) && $detalhe['anulacao_bloqueada'] === null,
        ]);
    }

    public function preVisualizarGeracao(PreVisualizarGeracaoPropinasRequest $request): JsonResponse
    {
        $this->authorize('propina.criar');

        return response()->json([
            'resultado' => $this->geracao->preVisualizar(PedidoGeracaoPropinasDTO::fromRequest($request))->toArray(),
        ]);
    }

    public function gerar(GerarPropinasRequest $request)
    {
        $this->authorize('propina.criar');

        $resultado = $this->gerarAction->executar(PedidoGeracaoPropinasDTO::fromRequest($request));
        $totais = $resultado->totais();
        $mensagem = "{$totais['criadas']} propina(s) gerada(s), {$totais['ignoradas']} período(s) ignorado(s), "
            . "{$totais['conflitos']} conflito(s), {$totais['erros']} erro(s).";

        $redireccao = redirect()->back();

        // Com erros o resultado é parcial (as outras matrículas ficaram gravadas): aviso, nunca sucesso.
        if ($totais['erros'] > 0) {
            $redireccao->with('warning', $mensagem . ' Veja o detalhe.');
        } else {
            $redireccao->with('success', $mensagem);
        }

        return $redireccao->with('geracao_propinas', $resultado->toArray());
    }

    public function cancelar(CancelarPropinaRequest $request, Propina $propina)
    {
        $this->authorize('propina.cancelar');

        $this->cancelarAction->executar($propina, CancelarPropinaDTO::fromRequest($request), $request->user()?->id);

        return redirect()->back()->with('success', 'Propina cancelada.');
    }

    public function anular(AnularPropinaRequest $request, Propina $propina)
    {
        $this->authorize('propina.anular');

        $this->anularAction->executar($propina, MotivoDeAnulacaoDTO::fromRequest($request), $request->user()?->id);

        return redirect()->back()->with('success', 'Propina anulada.');
    }

    public function isentarMulta(IsentarReduzirMultaRequest $request, Propina $propina)
    {
        $this->authorize('propina.isentar-multa');

        $this->isentarAction->executar($propina, IsentarMultaDTO::fromRequest($request), $request->user()?->id);

        return redirect()->back()->with('success', 'Multa actualizada.');
    }
}
