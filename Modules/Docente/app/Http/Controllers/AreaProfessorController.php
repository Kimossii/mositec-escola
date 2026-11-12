<?php

namespace Modules\Docente\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Aluno\Services\AlunoConsultaService;
use Modules\Docente\Http\Requests\MeusAlunosRequest;
use Modules\Docente\Services\AreaProfessorService;

class AreaProfessorController extends Controller
{
    public function __construct(private AreaProfessorService $area, private AlunoConsultaService $alunos)
    {
    }

    public function index(Request $request)
    {
        $this->authorize('area-professor.ver');

        return Inertia::render('Docente/AreaProfessor/Index', [
            'turmas' => $this->area->minhasTurmas($request->user()->id),
        ]);
    }

    public function alunos(Request $request, int $turma, int $disciplina)
    {
        $this->authorize('area-professor.ver');

        return Inertia::render('Docente/AreaProfessor/Alunos', $this->area->alunosDoProfessor($request->user()->id, $turma, $disciplina));
    }

    public function meusAlunos(MeusAlunosRequest $request)
    {
        $filtros = $request->filtros();

        return Inertia::render('Docente/AreaProfessor/MeusAlunos', [
            ...$this->area->meusAlunos($request->user()->id, $filtros['turma'], $filtros['disciplina'], $filtros['q']),
            'filtros' => $filtros,
        ]);
    }

    public function ficha(Request $request, int $aluno)
    {
        $this->authorize('area-professor.ver');

        return Inertia::render('Docente/AreaProfessor/FichaAluno', ['aluno' => $this->area->fichaDoProfessor($request->user()->id, $aluno)]);
    }

    public function foto(Request $request, int $aluno)
    {
        $this->authorize('area-professor.ver');

        return $this->alunos->servirFoto($this->area->alunoParaFoto($request->user()->id, $aluno));
    }
}
