<?php

namespace Modules\Avaliacao\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Modules\Avaliacao\Http\Requests\VerMinhasNotasRequest;
use Modules\Avaliacao\Services\ExportacaoDePautasService;
use Modules\Avaliacao\Services\NotasDoAlunoService;

class MinhasNotasController extends Controller
{
    public function __construct(
        private NotasDoAlunoService $notas,
        private ExportacaoDePautasService $exportacao,
    ) {
    }

    public function index(VerMinhasNotasRequest $request)
    {
        return Inertia::render('Avaliacao/MinhasNotas/Index', $this->notas->paraUtilizador($request->user()));
    }

    public function boletim(VerMinhasNotasRequest $request, int $matricula)
    {
        $propria = $this->notas->matriculaComBoletim($request->user(), $matricula);

        return $this->exportacao->boletins($propria->turma_id, $propria->id);
    }
}
