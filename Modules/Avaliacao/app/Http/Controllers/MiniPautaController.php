<?php

namespace Modules\Avaliacao\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Modules\Avaliacao\Http\Requests\AnularNotaRequest;
use Modules\Avaliacao\Http\Requests\CriarInstrumentoRequest;
use Modules\Avaliacao\Http\Requests\EliminarInstrumentoRequest;
use Modules\Avaliacao\Http\Requests\ExportarMiniPautaRequest;
use Modules\Avaliacao\Http\Requests\LancarCompetenciaRequest;
use Modules\Avaliacao\Http\Requests\LancarExameRequest;
use Modules\Avaliacao\Http\Requests\LancarNotaRequest;
use Modules\Avaliacao\Http\Requests\LancarQualitativaRequest;
use Modules\Avaliacao\Http\Requests\VerMiniPautaRequest;
use Modules\Avaliacao\Services\ExportacaoDePautasService;
use Modules\Avaliacao\Services\GestaoMiniPautaService;
use Modules\Avaliacao\Services\MiniPautaService;

class MiniPautaController extends Controller
{
    public function __construct(
        private MiniPautaService $miniPauta,
        private GestaoMiniPautaService $gestao,
        private ExportacaoDePautasService $exportacao,
    ) {
    }

    public function index(VerMiniPautaRequest $request)
    {
        $f = $request->validated();

        return Inertia::render('Avaliacao/Notas/MiniPauta', $this->miniPauta->pagina(
            $request->user(), isset($f['turma']) ? (int) $f['turma'] : null, isset($f['disciplina']) ? (int) $f['disciplina'] : null, isset($f['periodo']) ? (int) $f['periodo'] : null,
        ));
    }

    public function exportar(ExportarMiniPautaRequest $request)
    {
        $f = $request->validated();

        return $this->exportacao->miniPauta(
            $request->user(), (int) $f['turma'], (int) $f['disciplina'], $f['formato'], $f['detalhe'] ?? null, isset($f['periodo']) ? (int) $f['periodo'] : null,
        );
    }

    public function lancar(LancarNotaRequest $request): RedirectResponse
    {
        $this->gestao->lancar($request);

        return back();
    }

    public function lancarExame(LancarExameRequest $request): RedirectResponse
    {
        $this->gestao->lancarExame($request);

        return back();
    }

    public function lancarRecurso(LancarExameRequest $request): RedirectResponse
    {
        $this->gestao->lancarRecurso($request);

        return back();
    }

    public function lancarCompetencia(LancarCompetenciaRequest $request): RedirectResponse
    {
        $this->gestao->lancarCompetencia($request);

        return back();
    }

    public function lancarQualitativa(LancarQualitativaRequest $request): RedirectResponse
    {
        $this->gestao->lancarQualitativa($request);

        return back();
    }

    public function anular(AnularNotaRequest $request, int $nota): RedirectResponse
    {
        $this->gestao->anular($nota, $request);

        return back();
    }

    public function criarInstrumento(CriarInstrumentoRequest $request): RedirectResponse
    {
        $this->gestao->criarInstrumento($request);

        return back();
    }

    public function eliminarInstrumento(EliminarInstrumentoRequest $request, int $instrumento): RedirectResponse
    {
        $this->gestao->eliminarInstrumento($request->user(), $instrumento);

        return back();
    }
}
