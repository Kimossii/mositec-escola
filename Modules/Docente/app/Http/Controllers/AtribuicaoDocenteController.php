<?php

namespace Modules\Docente\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Core\Exportacao\Exportador;
use Modules\Docente\Http\Requests\AtribuirDocenteRequest;
use Modules\Docente\Http\Requests\AtualizarAtribuicaoRequest;
use Modules\Docente\Http\Requests\EncerrarAtribuicaoRequest;
use Modules\Docente\Http\Requests\SubstituirTitularRequest;
use Modules\Docente\Models\AtribuicaoDocente;
use Modules\Docente\Services\AtribuicaoDocenteConsultaService;
use Modules\Docente\Services\ExportacaoDeAtribuicoesService;
use Modules\Docente\Services\GestaoAtribuicaoDocenteService;

class AtribuicaoDocenteController extends Controller
{
    public function __construct(
        private GestaoAtribuicaoDocenteService $service,
        private AtribuicaoDocenteConsultaService $consulta,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('atribuicao-docente.ver');

        $filtros = $request->only(['turma_id', 'docente_id', 'disciplina_id', 'papel', 'estado_vigencia', 'pesquisa']);
        $anoLectivoId = (int) ($request->input('ano_lectivo_id') ?: $this->consulta->anoLectivoPadrao());
        $vista = $request->input('vista') === 'lista' ? 'lista' : 'turma';
        $turmaId = (int) $request->input('turma_id');

        return Inertia::render('Docente/Atribuicoes/Index', [
            'anoLectivoId' => $anoLectivoId ?: null,
            'vista' => $vista,
            'porTurma' => $turmaId > 0 ? $this->consulta->porTurma($anoLectivoId, $turmaId) : null,
            'atribuicoes' => $this->consulta->lista($filtros + ['ano_lectivo_id' => $anoLectivoId]),
            'indicadores' => Inertia::defer(fn () => $this->consulta->indicadores($anoLectivoId)),
            'filtros' => $filtros,
            'opcoes' => $this->consulta->opcoes($anoLectivoId),
        ]);
    }

    /** Exporta a lista (Excel, PDF, CSV ou ZIP) com os mesmos filtros do ecrã. */
    public function exportar(Request $request, ExportacaoDeAtribuicoesService $exportacao, Exportador $exportador)
    {
        $this->authorize('atribuicao-docente.exportar');

        $dados = $request->validate([
            'formato' => ['required', 'string'],
            'ano_lectivo_id' => ['nullable', 'integer'],
            'turma_id' => ['nullable', 'integer'],
            'docente_id' => ['nullable', 'integer'],
            'disciplina_id' => ['nullable', 'integer'],
            'papel' => ['nullable', 'in:0,1'],
            'estado_vigencia' => ['nullable', 'in:agendada,em_curso,terminada'],
            'pesquisa' => ['nullable', 'string', 'max:100'],
        ]);

        return $exportador->responder($exportacao->tabela($dados), $dados['formato'], 'atribuicoes-docentes', 'atribuicao-docente.exportar');
    }

    public function store(AtribuirDocenteRequest $request)
    {
        $this->authorize('atribuicao-docente.criar');

        $this->service->atribuir($request);

        return redirect()->back()->with('success', 'Docente atribuído com sucesso.');
    }

    public function substituir(SubstituirTitularRequest $request)
    {
        $this->authorize('atribuicao-docente.criar');

        $this->service->substituir($request);

        return redirect()->back()->with('success', 'Titular substituído com sucesso.');
    }

    public function update(AtualizarAtribuicaoRequest $request, AtribuicaoDocente $atribuicao)
    {
        $this->authorize('atribuicao-docente.editar');

        $this->service->actualizar($atribuicao, $request);

        return redirect()->back()->with('success', 'Vigência actualizada com sucesso.');
    }

    public function encerrar(EncerrarAtribuicaoRequest $request, AtribuicaoDocente $atribuicao)
    {
        $this->authorize('atribuicao-docente.encerrar');

        $this->service->encerrar($atribuicao, $request);

        return redirect()->back()->with('success', 'Atribuição encerrada com sucesso.');
    }

    public function destroy(AtribuicaoDocente $atribuicao)
    {
        $this->authorize('atribuicao-docente.eliminar');

        $this->service->eliminar($atribuicao);

        return redirect()->back()->with('success', 'Atribuição eliminada com sucesso.');
    }
}
