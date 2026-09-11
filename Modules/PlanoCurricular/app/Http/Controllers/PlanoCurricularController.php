<?php

namespace Modules\PlanoCurricular\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Modules\Core\Enums\Estado;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\PlanoCurricular\Http\Requests\AdicionarDisciplinaRequest;
use Modules\PlanoCurricular\Http\Requests\AlterarEstadoPlanoCurricularRequest;
use Modules\PlanoCurricular\Http\Requests\AtualizarDisciplinaRequest;
use Modules\PlanoCurricular\Http\Requests\AtualizarPlanoCurricularRequest;
use Modules\PlanoCurricular\Http\Requests\ConfirmarAnoLectivoRequest;
use Modules\PlanoCurricular\Http\Requests\CriarPlanoCurricularRequest;
use Modules\PlanoCurricular\Models\PlanoCurricular;
use Modules\PlanoCurricular\Models\PlanoCurricularDisciplina;
use Modules\PlanoCurricular\Services\GestaoPlanoCurricularService;
use Modules\PlanoCurricular\Services\PlanoCurricularConsultaService;

class PlanoCurricularController extends Controller
{
    public function __construct(
        private GestaoPlanoCurricularService $service,
        private PlanoCurricularConsultaService $consulta,
    ) {
    }

    public function index()
    {
        $this->authorize('plano-curricular.ver');

        return Inertia::render('PlanoCurricular/Index', [
            'planosCurriculares' => $this->consulta->listar(),
            'opcoes' => $this->consulta->opcoesFormulario(),
        ]);
    }

    public function show(PlanoCurricular $planoCurricular)
    {
        $this->authorize('plano-curricular.ver');

        $this->garantirMesmoEstabelecimento($planoCurricular);

        $planoCurricular->load(['curso', 'disciplinas.disciplina', 'disciplinas.nivelAcademico', 'anosLectivos.anoLectivo', 'anosLectivos.confirmadoPor']);

        return Inertia::render('PlanoCurricular/Show', [
            'planoCurricular' => $planoCurricular,
            'opcoes' => $this->consulta->opcoesFormulario(),
        ]);
    }

    public function store(CriarPlanoCurricularRequest $request)
    {
        $this->authorize('plano-curricular.criar');

        $this->service->criar($request);

        return redirect()->back()->with('success', 'Plano curricular criado com sucesso.');
    }

    public function update(AtualizarPlanoCurricularRequest $request, PlanoCurricular $planoCurricular)
    {
        $this->authorize('plano-curricular.editar');

        $this->garantirMesmoEstabelecimento($planoCurricular);

        $this->service->atualizar($planoCurricular, $request);

        return redirect()->back()->with('success', 'Plano curricular atualizado com sucesso.');
    }

    public function alterarEstado(AlterarEstadoPlanoCurricularRequest $request, PlanoCurricular $planoCurricular)
    {
        $this->authorize('plano-curricular.editar');

        $this->garantirMesmoEstabelecimento($planoCurricular);

        $this->service->alterarEstado($planoCurricular, Estado::from((int) $request->validated('estado')));

        return redirect()->back()->with('success', 'Estado do plano curricular atualizado com sucesso.');
    }

    public function adicionarDisciplina(AdicionarDisciplinaRequest $request, PlanoCurricular $planoCurricular)
    {
        $this->authorize('plano-curricular.editar');

        $this->garantirMesmoEstabelecimento($planoCurricular);

        $this->service->adicionarDisciplina($planoCurricular, $request);

        return redirect()->back()->with('success', 'Disciplina associada ao plano com sucesso.');
    }

    public function atualizarDisciplina(AtualizarDisciplinaRequest $request, PlanoCurricular $planoCurricular, PlanoCurricularDisciplina $disciplina)
    {
        $this->authorize('plano-curricular.editar');

        $this->garantirMesmoEstabelecimento($planoCurricular);
        abort_unless($disciplina->plano_curricular_id === $planoCurricular->id, 404);

        $this->service->atualizarDisciplina($disciplina, $request);

        return redirect()->back()->with('success', 'Disciplina do plano atualizada com sucesso.');
    }

    public function removerDisciplina(PlanoCurricular $planoCurricular, PlanoCurricularDisciplina $disciplina)
    {
        $this->authorize('plano-curricular.editar');

        $this->garantirMesmoEstabelecimento($planoCurricular);
        abort_unless($disciplina->plano_curricular_id === $planoCurricular->id, 404);

        $this->service->removerDisciplina($disciplina);

        return redirect()->back()->with('success', 'Disciplina removida do plano com sucesso.');
    }

    public function confirmarAnoLectivo(ConfirmarAnoLectivoRequest $request, PlanoCurricular $planoCurricular)
    {
        $this->authorize('plano-curricular.editar');

        $this->garantirMesmoEstabelecimento($planoCurricular);

        $this->service->confirmarAnoLectivo($planoCurricular, $request, auth()->id());

        return redirect()->back()->with('success', 'Plano confirmado para o ano lectivo com sucesso.');
    }

    /**
     * Garante que o plano curricular pertence ao estabelecimento actual.
     *
     * Não há global scope por estabelecimento em PlanoCurricular, pelo que o
     * route-model-binding do Laravel resolve o registo apenas pelo id, sem
     * qualquer filtro. Esta verificação evita que um utilizador autorizado
     * num estabelecimento visualize ou altere planos de outro estabelecimento
     * apenas por adivinhar/iterar o id na URL.
     */
    private function garantirMesmoEstabelecimento(PlanoCurricular $planoCurricular): void
    {
        abort_unless($planoCurricular->estabelecimento_id === Estabelecimento::current()?->id, 404);
    }
}
