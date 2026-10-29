<?php

namespace Modules\Docente\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Docente\Services\AreaProfessorService;

class AreaProfessorController extends Controller
{
    public function __construct(private AreaProfessorService $area)
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
}
