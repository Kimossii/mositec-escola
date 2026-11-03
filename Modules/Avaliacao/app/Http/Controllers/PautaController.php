<?php

namespace Modules\Avaliacao\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Modules\Avaliacao\Actions\AprovarPautaAction;
use Modules\Avaliacao\Actions\ReabrirPautaAction;
use Modules\Avaliacao\Services\PautaFinalService;
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

    public function aprovar(int $turma, AprovarPautaAction $acao): RedirectResponse
    {
        $acao->executar(Turma::query()->findOrFail($turma)->id);

        return back();
    }

    public function reabrir(Request $request, int $turma, ReabrirPautaAction $acao): RedirectResponse
    {
        $d = $request->validate(['motivo' => ['required', 'string', 'max:500']]);

        $acao->executar(Turma::query()->findOrFail($turma)->id, $d['motivo']);

        return back();
    }
}
