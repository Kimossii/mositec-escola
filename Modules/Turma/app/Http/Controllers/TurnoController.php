<?php

namespace Modules\Turma\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Modules\Core\Enums\Estado;
use Modules\Turma\Http\Requests\AdicionarHorarioTurnoRequest;
use Modules\Turma\Http\Requests\AlterarEstadoTurnoRequest;
use Modules\Turma\Http\Requests\AtualizarTurnoRequest;
use Modules\Turma\Http\Requests\CriarTurnoRequest;
use Modules\Turma\Models\Turno;
use Modules\Turma\Services\GestaoTurnoService;
use Modules\Turma\Services\TurnoConsultaService;

class TurnoController extends Controller
{
    public function __construct(
        private GestaoTurnoService $service,
        private TurnoConsultaService $consulta,
    ) {}

    public function index()
    {
        $this->authorize('turmas.ver');

        return Inertia::render('Turma/Turnos/Index', [
            'turnos' => $this->consulta->listar(),
            'horariosDisponiveis' => $this->consulta->horariosDisponiveis(),
        ]);
    }

    public function store(CriarTurnoRequest $request)
    {
        $this->authorize('turmas.criar');

        $this->service->criar($request);

        return redirect()->back()->with('success', 'Turno criado com sucesso.');
    }

    public function update(AtualizarTurnoRequest $request, Turno $turno)
    {
        $this->authorize('turmas.editar');

        $this->service->atualizar($turno, $request);

        return redirect()->back()->with('success', 'Turno atualizado com sucesso.');
    }

    public function alterarEstado(AlterarEstadoTurnoRequest $request, Turno $turno)
    {
        $this->authorize('turmas.editar');

        $this->service->alterarEstado($turno, Estado::from((int) $request->validated('estado')));

        return redirect()->back()->with('success', 'Estado do turno atualizado com sucesso.');
    }

    public function destroy(Turno $turno)
    {
        $this->authorize('turmas.eliminar');

        $this->service->eliminar($turno);

        return redirect()->back()->with('success', 'Turno eliminado com sucesso.');
    }

    public function adicionarHorario(AdicionarHorarioTurnoRequest $request, Turno $turno)
    {
        $this->authorize('turmas.editar');

        $this->service->adicionarHorario($turno, $request);

        return redirect()->back()->with('success', 'Horário adicionado ao turno com sucesso.');
    }
}
