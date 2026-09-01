<?php

namespace Modules\AnoLectivo\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Modules\AnoLectivo\Enums\EstadoAnoLectivo;
use Modules\AnoLectivo\Http\Requests\AlterarEstadoAnoLectivoRequest;
use Modules\AnoLectivo\Http\Requests\AtualizarAnoLectivoRequest;
use Modules\AnoLectivo\Http\Requests\CriarAnoLectivoRequest;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\AnoLectivo\Services\AnoLectivoConsultaService;
use Modules\AnoLectivo\Services\GestaoAnoLectivoService;

class AnoLectivoController extends Controller
{
    public function __construct(
        private GestaoAnoLectivoService $service,
        private AnoLectivoConsultaService $consulta,
    ) {}

    public function index()
    {
        $this->authorize('view', AnoLectivo::class);

        return Inertia::render('AnoLectivo/Index', [
            'anoLectivos' => $this->consulta->listar(),
        ]);
    }

    public function show(AnoLectivo $anoLectivo)
    {
        $this->authorize('view', AnoLectivo::class);

        return Inertia::render('AnoLectivo/Show', [
            'anoLectivo' => $this->consulta->comRelacoes($anoLectivo),
        ]);
    }

    public function store(CriarAnoLectivoRequest $request)
    {
        $this->authorize('create', AnoLectivo::class);

        $this->service->criar($request);

        return redirect()->back()->with('success', 'Ano Lectivo criado com sucesso.');
    }

    public function update(AtualizarAnoLectivoRequest $request, AnoLectivo $anoLectivo)
    {
        $this->authorize('update', AnoLectivo::class);

        $this->service->atualizar($anoLectivo, $request);

        return redirect()->back()->with('success', 'Ano Lectivo atualizado com sucesso.');
    }

    public function alterarEstado(AlterarEstadoAnoLectivoRequest $request, AnoLectivo $anoLectivo)
    {
        $this->authorize('update', AnoLectivo::class);

        $this->service->alterarEstado($anoLectivo, EstadoAnoLectivo::from((int) $request->validated('estado')));

        return redirect()->back()->with('success', 'Estado do Ano Lectivo atualizado com sucesso.');
    }

    public function destroy(AnoLectivo $anoLectivo)
    {
        $this->authorize('delete', AnoLectivo::class);

        $this->service->eliminar($anoLectivo);

        return redirect()->back()->with('success', 'Ano Lectivo eliminado com sucesso.');
    }
}
