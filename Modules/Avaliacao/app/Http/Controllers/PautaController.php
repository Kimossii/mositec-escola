<?php

namespace Modules\Avaliacao\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Modules\Avaliacao\Http\Requests\DecidirProgressaoRequest;
use Modules\Avaliacao\Http\Requests\ExportarBoletinsRequest;
use Modules\Avaliacao\Http\Requests\ExportarPautaRequest;
use Modules\Avaliacao\Http\Requests\ReabrirPautaRequest;
use Modules\Avaliacao\Http\Requests\VerPautaRequest;
use Modules\Avaliacao\Services\ExportacaoDePautasService;
use Modules\Avaliacao\Services\GestaoPautaService;
use Modules\Avaliacao\Services\PautaFinalService;

class PautaController extends Controller
{
    public function __construct(
        private PautaFinalService $pautas,
        private GestaoPautaService $gestao,
        private ExportacaoDePautasService $exportacao,
    ) {
    }

    public function index(VerPautaRequest $request)
    {
        $turma = $request->validated()['turma'] ?? null;

        return Inertia::render('Avaliacao/Pautas/Pauta', $this->pautas->pagina($request->user(), $turma === null ? null : (int) $turma));
    }

    public function exportar(ExportarPautaRequest $request, int $turma)
    {
        return $this->exportacao->pauta($turma, $request->validated()['formato']);
    }

    public function boletins(ExportarBoletinsRequest $request, int $turma)
    {
        $matricula = $request->validated()['matricula'] ?? null;

        return $this->exportacao->boletins($turma, $matricula === null ? null : (int) $matricula);
    }

    public function aprovar(int $turma): RedirectResponse
    {
        $this->gestao->aprovar($turma);

        return back();
    }

    public function progressao(DecidirProgressaoRequest $request, int $turma): RedirectResponse
    {
        $this->gestao->decidirProgressao($turma, $request);

        return back();
    }

    public function reabrir(ReabrirPautaRequest $request, int $turma): RedirectResponse
    {
        $this->gestao->reabrir($turma, $request);

        return back();
    }
}
