<?php

namespace Modules\Avaliacao\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Modules\Avaliacao\Actions\AnularNotaAction;
use Modules\Avaliacao\Actions\CriarInstrumentoAction;
use Modules\Avaliacao\Actions\EliminarInstrumentoAction;
use Modules\Avaliacao\Actions\LancarNotaAction;
use Modules\Avaliacao\DTO\InstrumentoDTO;
use Modules\Avaliacao\DTO\LancarNotaDTO;
use Modules\Avaliacao\Enums\TipoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoInstrumento;
use Modules\Avaliacao\Models\AvaliacaoNota;
use Modules\Avaliacao\Services\MiniPautaService;

class MiniPautaController extends Controller
{
    public function __construct(private MiniPautaService $miniPauta)
    {
    }

    public function index(Request $request)
    {
        $this->authorize('nota.ver');
        $f = $request->validate(['turma' => ['nullable', 'integer'], 'disciplina' => ['nullable', 'integer'], 'periodo' => ['nullable', 'integer']]);
        $utilizador = $request->user();

        $pauta = null;
        if (! empty($f['turma']) && ! empty($f['disciplina'])) {
            abort_unless($this->miniPauta->podeAceder($utilizador, (int) $f['turma'], (int) $f['disciplina']), 403);
            $pauta = $this->miniPauta->paraTurmaDisciplina($utilizador, (int) $f['turma'], (int) $f['disciplina'], isset($f['periodo']) ? (int) $f['periodo'] : null);
        }

        return Inertia::render('Avaliacao/Notas/MiniPauta', [
            'opcoes' => $this->miniPauta->opcoes($utilizador),
            'selecao' => ['turma' => $f['turma'] ?? null, 'disciplina' => $f['disciplina'] ?? null],
            'pauta' => $pauta,
            'tipos' => array_map(fn (TipoInstrumento $t) => ['valor' => $t->value, 'nome' => $t->label()], TipoInstrumento::cases()),
            'pode' => ['anular' => Gate::allows('nota.anular'), 'criar' => Gate::allows('nota.criar')],
        ]);
    }

    public function lancar(Request $request, LancarNotaAction $acao): RedirectResponse
    {
        $d = $request->validate([
            'instrumento_id' => ['required', 'integer'], 'inscricao_disciplina_id' => ['required', 'integer'],
            'valor' => ['required', 'string', 'max:6'], 'motivo' => ['nullable', 'string', 'max:500'],
        ]);

        $acao->executar(new LancarNotaDTO($d['instrumento_id'], $d['inscricao_disciplina_id'], $d['valor'], $d['motivo'] ?? null));

        return back();
    }

    public function anular(Request $request, int $nota, AnularNotaAction $acao): RedirectResponse
    {
        $d = $request->validate(['conta_como_zero' => ['required', 'boolean'], 'motivo' => ['required', 'string', 'max:500']]);

        $acao->executar(AvaliacaoNota::query()->findOrFail($nota)->id, (bool) $d['conta_como_zero'], $d['motivo']);

        return back();
    }

    public function criarInstrumento(Request $request, CriarInstrumentoAction $acao): RedirectResponse
    {
        $d = $request->validate([
            'turma_id' => ['required', 'integer'], 'disciplina_id' => ['required', 'integer'], 'periodo_id' => ['required', 'integer'],
            'tipo' => ['required', Rule::in(array_column(TipoInstrumento::cases(), 'value'))],
            'nome' => ['required', 'string', 'max:120'], 'obrigatorio' => ['required', 'boolean'],
        ]);
        $this->authorize('nota.criar');
        abort_unless($this->miniPauta->podeAceder($request->user(), $d['turma_id'], $d['disciplina_id']), 403);

        $acao->executar(new InstrumentoDTO($d['turma_id'], $d['disciplina_id'], $d['periodo_id'], TipoInstrumento::from((int) $d['tipo']), $d['nome'], (bool) $d['obrigatorio']));

        return back();
    }

    public function eliminarInstrumento(Request $request, int $instrumento, EliminarInstrumentoAction $acao): RedirectResponse
    {
        $this->authorize('nota.editar');
        $i = AvaliacaoInstrumento::query()->findOrFail($instrumento);
        abort_unless($this->miniPauta->podeAceder($request->user(), $i->turma_id, $i->disciplina_id), 403);

        $acao->executar($i->id);

        return back();
    }
}
