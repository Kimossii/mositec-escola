<?php

namespace Modules\Aluno\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Aluno\Http\Requests\AlterarEstadoAlunoRequest;
use Modules\Aluno\Http\Requests\AtualizarAlunoRequest;
use Modules\Aluno\Http\Requests\CriarAlunoRequest;
use Modules\Aluno\Models\Aluno;
use Modules\Aluno\Services\AlunoConsultaService;
use Modules\Aluno\Services\GestaoAlunoService;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Enums\Estado;
use Modules\Matricula\Services\MatriculaConsultaService;

class AlunoController extends Controller
{
    public function __construct(
        private GestaoAlunoService $service,
        private AlunoConsultaService $consulta,
        private MatriculaConsultaService $matriculaConsulta,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('aluno.ver');

        $filtros = $request->only(['pesquisa', 'ano_lectivo_id', 'curso_id', 'turma_id', 'nivel_academico_id']);

        return Inertia::render('Aluno/Index', [
            'alunos' => $this->consulta->listar($filtros),
            'filtros' => $filtros,
            'anosLectivosDisponiveis' => $this->consulta->anosLectivosDisponiveis(),
            'turmasDisponiveis' => $this->consulta->turmasDisponiveis(),
            'cursosDisponiveis' => $this->consulta->cursosDisponiveis(),
            'niveisAcademicosDisponiveis' => $this->consulta->niveisAcademicosDisponiveis(),
        ]);
    }

    public function show(Aluno $aluno, Request $request)
    {
        $this->authorize('aluno.ver');

        // Por omissão mostra só o ano lectivo activo — mas só quando o
        // pedido não indicou nenhum (incluindo "todos", que chega como
        // ano_lectivo_id vazio); assim a paginação/pesquisa não perdem esse
        // filtro implícito ao navegar.
        $filtrosMatricula = [
            'ano_lectivo_id' => $request->has('ano_lectivo_id')
                ? $request->input('ano_lectivo_id')
                : AnoLectivo::current($aluno->estabelecimento_id)?->id,
            'pesquisa' => $request->input('pesquisa'),
        ];

        return Inertia::render('Aluno/Show', [
            'aluno' => $aluno->load('dadosPessoa'),
            'matriculas' => $this->matriculaConsulta->listarPorAluno($aluno, $filtrosMatricula),
            'matriculaActual' => $this->matriculaConsulta->matriculaActual($aluno),
            'turmasDisponiveis' => $this->matriculaConsulta->turmasDisponiveis(),
            'anosLectivosComMatricula' => $this->matriculaConsulta->anosLectivosComMatricula($aluno),
            'filtrosMatricula' => $filtrosMatricula,
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
