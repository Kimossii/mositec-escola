<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Core\Enums\Estado;
use Modules\Financeiro\Enums\Periodicidade;
use Modules\Financeiro\Http\Requests\AlterarEstadoPlanoPropinaRequest;
use Modules\Financeiro\Http\Requests\AtualizarPlanoPropinaRequest;
use Modules\Financeiro\Http\Requests\CopiarPlanosPropinaRequest;
use Modules\Financeiro\Http\Requests\CriarPlanoPropinaRequest;
use Modules\Financeiro\Models\PlanoPropina;
use Modules\Financeiro\Services\GestaoPlanoPropinaService;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Services\PlanoPropinaConsultaService;

class PlanoPropinaController extends Controller
{
    public function __construct(
        private GestaoPlanoPropinaService $service,
        private PlanoPropinaConsultaService $consulta,
        private MoedaDoTenant $moedaDoTenant,
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
            'moeda' => $this->moedaDoTenant->atual()->paraFrontend(),
        ]);
    }

    public function store(CriarPlanoPropinaRequest $request)
    {
        $this->authorize('plano-propina.criar');

        $this->service->criar($request);

        return redirect()->back()->with('success', 'Plano de propina criado com sucesso.');
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

    public function destroy(PlanoPropina $plano)
    {
        $this->authorize('plano-propina.eliminar');

        $this->service->eliminar($plano);

        return redirect()->back()->with('success', 'Plano de propina eliminado com sucesso.');
    }
}
