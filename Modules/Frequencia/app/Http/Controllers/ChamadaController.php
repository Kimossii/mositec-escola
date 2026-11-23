<?php

namespace Modules\Frequencia\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Frequencia\Http\Requests\AbrirChamadaRequest;
use Modules\Frequencia\Http\Requests\NaoLeccionadaRequest;
use Modules\Frequencia\Http\Requests\RegistarPresencasRequest;
use Modules\Frequencia\Models\Aula;
use Modules\Frequencia\Services\ConsultaFrequenciaService;
use Modules\Frequencia\Services\GestaoFrequenciaService;
use Modules\Frequencia\Services\JanelaDeCorreccaoService;
use Modules\Horario\Services\ConsultaGrelhaService;

class ChamadaController extends Controller
{
    public function __construct(
        private GestaoFrequenciaService $service,
        private ConsultaFrequenciaService $consulta,
        private JanelaDeCorreccaoService $janela,
        private RelogioDoTenant $relogio,
        private ConsultaGrelhaService $grelha,
    ) {
    }

    /** As aulas do professor num dia (por omissão hoje) e o estado da chamada de cada uma. */
    public function dia(Request $request)
    {
        $this->authorize('frequencia.criar');

        $valor = (string) $request->input('data');
        $valido = preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) === 1 && CarbonImmutable::hasFormat($valor, 'Y-m-d');
        $hoje = $this->relogio->hoje();
        $data = $valido ? CarbonImmutable::createFromFormat('!Y-m-d', $valor) : $hoje;

        // A secretaria (corrigir-fora-prazo) escolhe a turma; o professor vê só as suas aulas.
        $secretaria = $request->user()->can('frequencia.corrigir-fora-prazo');
        $turmaId = $secretaria && $request->filled('turma_id') ? (int) $request->input('turma_id') : null;

        return Inertia::render('Frequencia/Dia', [
            'data' => $data->toDateString(),
            'hoje' => $hoje->toDateString(),
            'aulas' => $turmaId !== null ? $this->consulta->aulasDaTurmaNoDia($turmaId, $data) : $this->consulta->aulasDoDia($request->user(), $data),
            'filtros' => $turmaId !== null ? ['turma_id' => $turmaId] : [],
            'opcoes' => ['turmas' => $secretaria ? $this->grelha->opcoes()['turmas'] : []],
        ]);
    }

    /**
     * Abre a chamada se ainda não existe (é a única leitura com efeito: a acção é idempotente, por isso recarregar a
     * página ou abrir em dois separadores devolve sempre a mesma aula) e mostra-a.
     */
    public function chamada(AbrirChamadaRequest $request)
    {
        $this->authorize('frequencia.criar');

        $aula = $this->service->abrir($request->user(), $request->validated());

        return Inertia::render('Frequencia/Chamada', [
            'chamada' => $this->consulta->chamada($aula, $request->user()),
            'janelaDias' => $this->janela->dias(),
        ]);
    }

    public function presencas(RegistarPresencasRequest $request, Aula $aula)
    {
        $this->authorize('frequencia.editar');

        $this->service->registar($request->user(), $aula, $request->validated());

        return redirect()->back()->with('success', 'Chamada guardada.');
    }

    public function reabrir(Request $request, Aula $aula)
    {
        $this->authorize('frequencia.editar');

        $this->service->reabrir($request->user(), $aula);

        return redirect()->back()->with('success', 'Aula reaberta: as presenças voltam a contar.');
    }

    public function naoLeccionada(NaoLeccionadaRequest $request, Aula $aula)
    {
        $this->authorize('frequencia.editar');

        $this->service->naoLeccionada($request->user(), $aula, $request->validated('motivo'));

        return redirect()->back()->with('success', 'Aula marcada como não leccionada.');
    }
}
