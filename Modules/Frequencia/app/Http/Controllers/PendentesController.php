<?php

namespace Modules\Frequencia\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Frequencia\Http\Requests\DefinicoesRequest;
use Modules\Frequencia\Services\ConsultaFrequenciaService;
use Modules\Frequencia\Services\GestaoFrequenciaService;
use Modules\Frequencia\Services\JanelaDeCorreccaoService;
use Modules\Horario\Services\ConsultaGrelhaService;

/** Vista da direcção: aulas previstas dos últimos 7 dias sem chamada, e a janela de correcção da escola. */
class PendentesController extends Controller
{
    public function __construct(
        private ConsultaFrequenciaService $consulta,
        private GestaoFrequenciaService $service,
        private JanelaDeCorreccaoService $janela,
        private ConsultaGrelhaService $grelha,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('frequencia.listar');

        $turmaId = $request->filled('turma_id') ? (int) $request->input('turma_id') : null;

        return Inertia::render('Frequencia/Pendentes', [
            'pendentes' => $this->consulta->pendentes($turmaId),
            'filtros' => $request->only(['turma_id']),
            'opcoes' => ['turmas' => $this->grelha->opcoes()['turmas']],
            'janelaDias' => $this->janela->dias(),
            'podeConfigurar' => (bool) $request->user()->can('frequencia.configurar'),
        ]);
    }

    public function definicoes(DefinicoesRequest $request)
    {
        $this->authorize('frequencia.configurar');

        $this->service->guardarDefinicoes((int) $request->validated('janela_correccao_dias'));

        return redirect()->back()->with('success', 'Janela de correcção actualizada.');
    }
}
