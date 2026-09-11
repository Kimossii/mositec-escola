<?php

namespace Modules\Curso\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Modules\Core\Enums\Estado;
use Modules\Curso\Http\Requests\AlterarEstadoCursoRequest;
use Modules\Curso\Http\Requests\AtualizarCursoRequest;
use Modules\Curso\Http\Requests\CriarCursoRequest;
use Modules\Curso\Models\Curso;
use Modules\Curso\Services\CursoConsultaService;
use Modules\Curso\Services\GestaoCursoService;

class CursoController extends Controller
{
    public function __construct(
        private GestaoCursoService $service,
        private CursoConsultaService $consulta,
    ) {
    }

    public function index()
    {
        $this->authorize('curso.ver');

        return Inertia::render('Curso/Index', [
            'cursos' => $this->consulta->listar(),
        ]);
    }

    public function show(Curso $curso)
    {
        $this->authorize('curso.ver');

        return Inertia::render('Curso/Show', [
            'curso' => $this->consulta->comRelacoes($curso),
        ]);
    }

    public function store(CriarCursoRequest $request)
    {
        $this->authorize('curso.criar');

        $this->service->criar($request);

        return redirect()->back()->with('success', 'Curso criado com sucesso.');
    }

    public function update(AtualizarCursoRequest $request, Curso $curso)
    {
        $this->authorize('curso.editar');

        $this->service->atualizar($curso, $request);

        return redirect()->back()->with('success', 'Curso atualizado com sucesso.');
    }

    public function alterarEstado(AlterarEstadoCursoRequest $request, Curso $curso)
    {
        $this->authorize('curso.editar');

        $this->service->alterarEstado($curso, Estado::from((int) $request->validated('estado')));

        return redirect()->back()->with('success', 'Estado do curso atualizado com sucesso.');
    }
}
