<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Core\Enums\Estado;
use Modules\Financeiro\Actions\AlterarPrecoPlanoAction;
use Modules\Financeiro\DTO\AlterarPrecoPlanoDTO;
use Modules\Financeiro\Enums\ModoAlteracaoPreco;
use Modules\Financeiro\Enums\Periodicidade;
use Modules\Financeiro\Http\Requests\AlterarEstadoPlanoPropinaRequest;
use Modules\Financeiro\Http\Requests\AlterarPrecoPlanoRequest;
use Modules\Financeiro\Http\Requests\AtualizarPlanoPropinaRequest;
use Modules\Financeiro\Http\Requests\CopiarPlanosPropinaRequest;
use Modules\Financeiro\Http\Requests\CriarPlanoPropinaRequest;
use Modules\Financeiro\Http\Requests\PreVisualizarAlteracaoPrecoRequest;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Services\AlteracaoPrecoPlanoService;
use Modules\Financeiro\Services\GestaoPlanoPropinaService;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Services\PlanoPropinaConsultaService;
use Modules\Financeiro\Support\RotuloDePeriodo;

class PlanoPropinaController extends Controller
{
    public function __construct(
        private GestaoPlanoPropinaService $service,
        private PlanoPropinaConsultaService $consulta,
        private MoedaDoTenant $moedaDoTenant,
        private AlteracaoPrecoPlanoService $alteracaoPreco,
        private AlterarPrecoPlanoAction $alterarPrecoAction,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('plano-propina.ver');

        $filtros = $request->only(['pesquisa', 'estado', 'ano_lectivo_id']);

        if (! $request->has('ano_lectivo_id')) {
            $filtros['ano_lectivo_id'] = $this->consulta->anoLectivoActualId();
        }
       
        return Inertia::render('Financeiro/PlanosPropina/Index', [
            'planos' => $this->consulta->listar($filtros),
            'filtros' => $filtros,
            'anosLectivos' => $this->consulta->anosLectivos(),
            'niveis' => $this->consulta->niveis(),
            'cursos' => $this->consulta->cursos(),
            'turnos' => $this->consulta->turnos(),
            'turmas' => $this->consulta->turmas(),
            'periodicidades' => Periodicidade::opcoes(),
            'modosAlteracaoPreco' => ModoAlteracaoPreco::opcoes(),
            'moeda' => $this->moedaDoTenant->atual()->paraFrontend(),
        ]);
    }

    public function store(CriarPlanoPropinaRequest $request)
    {
        $this->authorize('plano-propina.criar');

        $plano = $this->service->criar($request);

        // A lista mostra por defeito o ano lectivo actual: leva o utilizador ao ano do plano para o ver logo.
        return redirect()
            ->route('financeiro.configuracao.planos-propina.index', ['ano_lectivo_id' => $plano->ano_lectivo_id])
            ->with('success', 'Plano de propina criado com sucesso.');
    }

    public function copiar(CopiarPlanosPropinaRequest $request)
    {
        $this->authorize('plano-propina.criar');

        $resumo = $this->service->copiar($request);

        return redirect()->back()
            ->with('success', count($resumo->copiados) . ' plano(s) de propina copiado(s). Reveja-os e active-os para entrarem em vigor.')
            ->with('copia_planos', $resumo->toArray());
    }

    public function update(AtualizarPlanoPropinaRequest $request, PlanoPropina $plano)
    {
        $this->authorize('plano-propina.editar');

        $this->service->atualizar($plano, $request);

        return redirect()->back()->with('success', 'Plano de propina atualizado com sucesso.');
    }

    public function alterarEstado(AlterarEstadoPlanoPropinaRequest $request, PlanoPropina $plano)
    {
        $this->authorize('plano-propina.editar');

        $this->service->alterarEstado($plano, Estado::from((int) $request->validated('estado')));

        return redirect()->back()->with('success', 'Estado do plano de propina atualizado com sucesso.');
    }

    public function preVisualizarAlteracaoPreco(PreVisualizarAlteracaoPrecoRequest $request, PlanoPropina $plano): JsonResponse
    {
        $this->authorize('propina.ajustar');
        $this->authorize('plano-propina.editar');

        return response()->json([
            'previsualizacao' => $this->alteracaoPreco->preVisualizar($plano, AlterarPrecoPlanoDTO::fromRequest($request))->toArray(),
        ]);
    }

    public function alterarPreco(AlterarPrecoPlanoRequest $request, PlanoPropina $plano)
    {
        $this->authorize('propina.ajustar');
        $this->authorize('plano-propina.editar');

        $alteracao = $this->alterarPrecoAction->executar($plano, AlterarPrecoPlanoDTO::fromRequest($request));
        $mes = RotuloDePeriodo::competencia($alteracao->mes_efeito->year, $alteracao->mes_efeito->month);
        $propinas = "{$alteracao->total_propinas_afectadas} propina(s) reajustada(s)";

        if ($alteracao->totalReassociadasSemAjuste > 0) {
            $propinas .= " e {$alteracao->totalReassociadasSemAjuste} mudada(s) de plano";
        }

        $propinas .= '.';

        return redirect()->back()->with('success', $alteracao->planoNovo === null
            ? "Preço do plano alterado. {$propinas}"
            : "Preço alterado a partir de {$mes}: criado o plano «{$alteracao->planoNovo->nome}». {$propinas}");
    }

    public function destroy(PlanoPropina $plano)
    {
        $this->authorize('plano-propina.eliminar');

        $this->service->eliminar($plano);

        return redirect()->back()->with('success', 'Plano de propina eliminado com sucesso.');
    }
}
