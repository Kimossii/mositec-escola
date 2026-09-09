<?php

namespace Modules\Turma\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Modules\Core\Enums\Estado;
use Modules\Turma\Http\Requests\AlterarEstadoTurmaRequest;
use Modules\Turma\Http\Requests\AssociarSalaTurmaRequest;
use Modules\Turma\Http\Requests\AtualizarTurmaRequest;
use Modules\Turma\Http\Requests\CriarTurmaRequest;
use Modules\Turma\Http\Requests\EncerrarSalaTurmaRequest;
use Modules\Turma\Models\Turma;
use Modules\Turma\Models\TurmaSala;
use Modules\Turma\Services\GestaoTurmaService;
use Modules\Turma\Services\TurmaConsultaService;

class TurmaController extends Controller
{
    public function __construct(
        private GestaoTurmaService $service,
        private TurmaConsultaService $consulta,
    ) {}

    public function index()
    {
        $this->authorize('turmas.ver');

        return Inertia::render('Turma/Turmas/Index', array_merge([
            'turmas' => $this->consulta->listar(),
        ], $this->consulta->opcoesFormulario()));
    }

    public function show(Turma $turma)
    {
        $this->authorize('turmas.ver');

        $turma->load(['anoLectivo', 'nivelAcademico', 'curso', 'turno', 'turmaSalas.sala']);

        return Inertia::render('Turma/Turmas/Show', array_merge([
            'turma' => $turma,
            'salas' => $this->consulta->salasDisponiveis(),
        ], $this->consulta->opcoesFormulario()));
    }

    public function store(CriarTurmaRequest $request)
    {
        $this->authorize('turmas.criar');

        $this->service->criar($request);

        return redirect()->back()->with('success', 'Turma criada com sucesso.');
    }

    public function update(AtualizarTurmaRequest $request, Turma $turma)
    {
        $this->authorize('turmas.editar');

        $this->service->atualizar($turma, $request);

        return redirect()->back()->with('success', 'Turma atualizada com sucesso.');
    }

    public function alterarEstado(AlterarEstadoTurmaRequest $request, Turma $turma)
    {
        $this->authorize('turmas.editar');

        $this->service->alterarEstado($turma, Estado::from((int) $request->validated('estado')));

        return redirect()->back()->with('success', 'Estado da turma atualizado com sucesso.');
    }

    public function destroy(Turma $turma)
    {
        $this->authorize('turmas.eliminar');

        $this->service->eliminar($turma);

        return redirect()->back()->with('success', 'Turma eliminada com sucesso.');
    }

    public function associarSala(AssociarSalaTurmaRequest $request, Turma $turma)
    {
        $this->authorize('turmas.editar');

        $this->service->associarSala($turma, $request);

        return redirect()->back()->with('success', 'Sala associada à turma com sucesso.');
    }

    public function encerrarSala(EncerrarSalaTurmaRequest $request, Turma $turma, TurmaSala $sala)
    {
        $this->authorize('turmas.editar');

        abort_unless($sala->turma_id === $turma->id, 404);

        $this->service->encerrarSala($sala, $request);

        return redirect()->back()->with('success', 'Associação da sala encerrada com sucesso.');
    }
}
