<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Financeiro\Actions\GerarPropinasAction;
use Modules\Financeiro\DTO\PedidoGeracaoPropinasDTO;
use Modules\Financeiro\Http\Requests\GerarPropinasRequest;
use Modules\Financeiro\Http\Requests\PreVisualizarGeracaoPropinasRequest;
use Modules\Financeiro\Services\GerarPropinasService;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Services\PlanoPropinaConsultaService;
use Modules\Financeiro\Services\PropinaConsultaService;

class PropinaController extends Controller
{
    public function __construct(
        private PropinaConsultaService $consulta,
        private PlanoPropinaConsultaService $planos,
        private GerarPropinasService $geracao,
        private GerarPropinasAction $gerarAction,
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
}
