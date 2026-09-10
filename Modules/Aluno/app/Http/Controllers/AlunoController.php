<?php

namespace Modules\Aluno\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Modules\Aluno\Http\Requests\AlterarEstadoAlunoRequest;
use Modules\Aluno\Http\Requests\AtualizarAlunoRequest;
use Modules\Aluno\Http\Requests\CriarAlunoRequest;
use Modules\Aluno\Models\Aluno;
use Modules\Aluno\Services\AlunoConsultaService;
use Modules\Aluno\Services\GestaoAlunoService;
use Modules\Core\Enums\Estado;

class AlunoController extends Controller
{
    public function __construct(
        private GestaoAlunoService $service,
        private AlunoConsultaService $consulta,
    ) {
    }

    public function index()
    {
        $this->authorize('aluno.ver');

        return Inertia::render('Aluno/Index', [
            'alunos' => $this->consulta->listar(),
        ]);
    }

    public function show(Aluno $aluno)
    {
        $this->authorize('aluno.ver');

        return Inertia::render('Aluno/Show', [
            'aluno' => $aluno->load('dadosPessoa'),
        ]);
    }

    public function store(CriarAlunoRequest $request)
    {
        $this->authorize('aluno.criar');

        $this->service->criar($request);

        return redirect()->back()->with('success', 'Aluno criado com sucesso.');
    }

    public function update(AtualizarAlunoRequest $request, Aluno $aluno)
    {
        $this->authorize('aluno.editar');

        $this->service->atualizar($aluno, $request);

        return redirect()->back()->with('success', 'Aluno atualizado com sucesso.');
    }

    public function alterarEstado(AlterarEstadoAlunoRequest $request, Aluno $aluno)
    {
        $this->authorize('aluno.editar');

        $this->service->alterarEstado($aluno, Estado::from((int) $request->validated('estado')));

        return redirect()->back()->with('success', 'Estado do aluno atualizado com sucesso.');
    }
}
