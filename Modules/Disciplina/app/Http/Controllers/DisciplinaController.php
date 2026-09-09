<?php

namespace Modules\Disciplina\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Modules\Core\Enums\Estado;
use Modules\Disciplina\Http\Requests\AlterarEstadoDisciplinaRequest;
use Modules\Disciplina\Http\Requests\AtualizarDisciplinaRequest;
use Modules\Disciplina\Http\Requests\CriarDisciplinaRequest;
use Modules\Disciplina\Models\Disciplina;
use Modules\Disciplina\Services\DisciplinaConsultaService;
use Modules\Disciplina\Services\GestaoDisciplinaService;

class DisciplinaController extends Controller
{
    public function __construct(
        private GestaoDisciplinaService $service,
        private DisciplinaConsultaService $consulta,
    ) {
    }

    public function index()
    {
        $this->authorize('disciplina.ver');

        return Inertia::render('Disciplina/Index', [
            'disciplinas' => $this->consulta->listar(),
        ]);
    }

    public function show(Disciplina $disciplina)
    {
        $this->authorize('disciplina.ver');

        return Inertia::render('Disciplina/Show', [
            'disciplina' => $disciplina,
        ]);
    }

    public function store(CriarDisciplinaRequest $request)
    {
        $this->authorize('disciplina.criar');

        $this->service->criar($request);

        return redirect()->back()->with('success', 'Disciplina criada com sucesso.');
    }

    public function update(AtualizarDisciplinaRequest $request, Disciplina $disciplina)
    {
        $this->authorize('disciplina.editar');

        $this->service->atualizar($disciplina, $request);

        return redirect()->back()->with('success', 'Disciplina atualizada com sucesso.');
    }

    public function alterarEstado(AlterarEstadoDisciplinaRequest $request, Disciplina $disciplina)
    {
        $this->authorize('disciplina.editar');

        $this->service->alterarEstado($disciplina, Estado::from((int) $request->validated('estado')));

        return redirect()->back()->with('success', 'Estado da disciplina atualizado com sucesso.');
    }
}
