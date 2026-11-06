<?php

namespace Modules\Avaliacao\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Modules\Avaliacao\Http\Requests\CopiarRegrasRequest;
use Modules\Avaliacao\Http\Requests\DefinirPrazoRequest;
use Modules\Avaliacao\Http\Requests\GuardarCompetenciasRequest;
use Modules\Avaliacao\Http\Requests\GuardarConfigDocumentosRequest;
use Modules\Avaliacao\Http\Requests\GuardarEscalaRequest;
use Modules\Avaliacao\Http\Requests\GuardarRegraQualitativaRequest;
use Modules\Avaliacao\Http\Requests\GuardarRegraRequest;
use Modules\Avaliacao\Http\Requests\ProlongarPrazoRequest;
use Modules\Avaliacao\Http\Requests\VerConfiguracaoRequest;
use Modules\Avaliacao\Services\ConfiguracaoAvaliacaoService;
use Modules\Avaliacao\Services\GestaoConfiguracaoAvaliacaoService;

class ConfiguracaoAvaliacaoController extends Controller
{
    public function __construct(
        private ConfiguracaoAvaliacaoService $configuracao,
        private GestaoConfiguracaoAvaliacaoService $gestao,
    ) {
    }

    public function index(VerConfiguracaoRequest $request)
    {
        $ano = $request->validated()['ano'] ?? null;

        return Inertia::render('Avaliacao/Configuracao/Index', $this->configuracao->pagina($ano === null ? null : (int) $ano));
    }

    public function guardarRegra(GuardarRegraRequest $request): RedirectResponse
    {
        $this->gestao->guardarRegra($request);

        return back();
    }

    public function copiar(CopiarRegrasRequest $request): RedirectResponse
    {
        $copiadas = $this->gestao->copiarRegras($request);

        return back()->with('success', $copiadas === 0 ? 'Não havia regras para copiar.' : "{$copiadas} regra(s) copiada(s) do ano anterior.");
    }

    public function definirPrazo(DefinirPrazoRequest $request): RedirectResponse
    {
        $this->gestao->definirPrazo($request);

        return back();
    }

    public function prolongarPrazo(ProlongarPrazoRequest $request, int $prazo): RedirectResponse
    {
        $this->gestao->prolongarPrazo($prazo, $request);

        return back();
    }

    public function guardarEscala(GuardarEscalaRequest $request): RedirectResponse
    {
        $this->gestao->guardarEscala($request);

        return back();
    }

    public function guardarRegraQualitativa(GuardarRegraQualitativaRequest $request): RedirectResponse
    {
        $this->gestao->guardarRegraQualitativa($request);

        return back();
    }

    public function guardarCompetencias(GuardarCompetenciasRequest $request): RedirectResponse
    {
        $this->gestao->guardarCompetencias($request);

        return back();
    }

    public function guardarDocumentos(GuardarConfigDocumentosRequest $request): RedirectResponse
    {
        $this->gestao->guardarDocumentos($request);

        return back();
    }
}
