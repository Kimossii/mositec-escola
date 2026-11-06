<?php

namespace Modules\Avaliacao\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Modules\Avaliacao\Actions\AprovarPautaAction;
use Modules\Avaliacao\Actions\DecidirProgressaoAction;
use Modules\Avaliacao\Actions\ReabrirPautaAction;
use Modules\Avaliacao\Documentos\ExportadorDePautas;
use Modules\Avaliacao\Services\BoletimDocumentoService;
use Modules\Avaliacao\Services\PautaDocumentoService;
use Modules\Avaliacao\Enums\Decisao;
use Modules\Avaliacao\Services\PautaFinalService;
use Modules\Matricula\Models\Matricula;
use Modules\Turma\Models\Turma;

class PautaController extends Controller
{
    public function __construct(private PautaFinalService $pautas)
    {
    }

    public function index(Request $request)
    {
        $f = $request->validate(['turma' => ['nullable', 'integer']]);
        $turma = isset($f['turma']) ? Turma::query()->findOrFail((int) $f['turma']) : null;

        return Inertia::render('Avaliacao/Pautas/Pauta', [
            'turmas' => $this->pautas->turmas(),
            'selecao' => ['turma' => $turma?->id],
            'pauta' => $turma === null ? null : $this->pautas->paraTurma($turma->id),
            'pode' => ['aprovar' => Gate::allows('nota.aprovar'), 'reabrir' => Gate::allows('nota.reabrir')],
        ]);
    }

    public function exportar(Request $request, int $turma, PautaDocumentoService $documento, ExportadorDePautas $exportador)
    {
        $f = $request->validate(['formato' => ['required', 'string', 'in:pdf,excel']]);
        $alvo = Turma::query()->findOrFail($turma);

        return $exportador->responder($documento->paraTurma($alvo->id), $f['formato'], 'pauta-final-'.$alvo->nome);
    }

    public function aprovar(int $turma, AprovarPautaAction $acao): RedirectResponse
    {
        $acao->executar(Turma::query()->findOrFail($turma)->id);

        return back();
    }

    public function boletins(Request $request, int $turma, BoletimDocumentoService $boletins, ExportadorDePautas $exportador)
    {
        $f = $request->validate(['matricula' => ['nullable', 'integer']]);
        $alvo = Turma::query()->findOrFail($turma);
        $matricula = isset($f['matricula']) ? Matricula::query()->where('turma_id', $alvo->id)->findOrFail((int) $f['matricula']) : null;

        return $exportador->responderBoletins($boletins->paraTurma($alvo->id, $matricula?->id), $matricula === null ? 'boletins-'.$alvo->nome : 'boletim-'.$alvo->nome);
    }

    public function progressao(Request $request, int $turma, DecidirProgressaoAction $acao): RedirectResponse
    {
        $d = $request->validate(['matricula_id' => ['required', 'integer'], 'decisao' => ['required', 'integer', 'in:0,1'], 'motivo' => ['nullable', 'string', 'max:500']]);
        $matricula = Matricula::query()->where('turma_id', Turma::query()->findOrFail($turma)->id)->findOrFail($d['matricula_id']);

        $acao->executar($matricula->id, Decisao::from((int) $d['decisao']), $d['motivo'] ?? null);

        return back();
    }

    public function reabrir(Request $request, int $turma, ReabrirPautaAction $acao): RedirectResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:500']]);

        $acao->executar(Turma::query()->findOrFail($turma)->id, $d['motivo']);

        return back();
    }
}
