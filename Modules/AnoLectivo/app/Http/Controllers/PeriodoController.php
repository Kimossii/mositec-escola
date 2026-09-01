<?php

namespace Modules\AnoLectivo\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AnoLectivo\Http\Requests\AtualizarPeriodoRequest;
use Modules\AnoLectivo\Http\Requests\CriarPeriodoRequest;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\AnoLectivo\Models\Periodo;
use Modules\AnoLectivo\Services\GestaoAnoLectivoService;

class PeriodoController extends Controller
{
    public function __construct(
        private GestaoAnoLectivoService $service,
    ) {}

    public function store(CriarPeriodoRequest $request, AnoLectivo $anoLectivo)
    {
        $this->authorize('create', Periodo::class);

        $this->service->criarPeriodo($anoLectivo, $request);

        return redirect()->back()->with('success', 'Período criado com sucesso.');
    }

    public function update(AtualizarPeriodoRequest $request, Periodo $periodo)
    {
        $this->authorize('update', Periodo::class);

        $this->service->atualizarPeriodo($periodo, $request);

        return redirect()->back()->with('success', 'Período atualizado com sucesso.');
    }

    public function destroy(Periodo $periodo)
    {
        $this->authorize('delete', Periodo::class);

        $this->service->eliminarPeriodo($periodo);

        return redirect()->back()->with('success', 'Período eliminado com sucesso.');
    }
}
