<?php

namespace Modules\Docente\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Aluno\Models\Aluno;
use Modules\Aluno\Services\AlunoConsultaService;
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
        abort_unless($this->area->podeVer($request->user()->id, $turma, $disciplina), 403);

        return Inertia::render('Docente/AreaProfessor/Alunos', $this->area->alunos($turma, $disciplina));
    }

    public function meusAlunos(Request $request)
    {
        $this->authorize('area-professor.ver');
        $filtros = $request->validate(['turma' => ['nullable', 'integer'], 'disciplina' => ['nullable', 'integer'], 'q' => ['nullable', 'string', 'max:100']]);

        return Inertia::render('Docente/AreaProfessor/MeusAlunos', [
            ...$this->area->meusAlunos($request->user()->id, $filtros['turma'] ?? null, $filtros['disciplina'] ?? null, $filtros['q'] ?? null),
            'filtros' => ['turma' => $filtros['turma'] ?? null, 'disciplina' => $filtros['disciplina'] ?? null, 'q' => $filtros['q'] ?? ''],
        ]);
    }

    public function ficha(Request $request, int $aluno)
    {
        $this->authorize('area-professor.ver');
        $ficha = $this->area->ficha($request->user()->id, $aluno);
        abort_if($ficha === null, 403);

        return Inertia::render('Docente/AreaProfessor/FichaAluno', ['aluno' => $ficha]);
    }

    public function foto(Request $request, int $aluno)
    {
        $this->authorize('area-professor.ver');
        abort_if($this->area->ficha($request->user()->id, $aluno) === null, 403);

        return $this->alunos->servirFoto(Aluno::query()->findOrFail($aluno));
    }
}
