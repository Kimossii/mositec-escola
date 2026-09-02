<?php

namespace Modules\AnoLectivo\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\AnoLectivo\Http\Requests\AtualizarEventoCalendarioRequest;
use Modules\AnoLectivo\Http\Requests\CriarEventoCalendarioRequest;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\AnoLectivo\Models\EventoCalendario;
use Modules\AnoLectivo\Services\GestaoAnoLectivoService;

class EventoCalendarioController extends Controller
{
    public function __construct(
        private GestaoAnoLectivoService $service,
    ) {}

    public function store(CriarEventoCalendarioRequest $request, AnoLectivo $anoLectivo)
    {
        $this->authorize('create', EventoCalendario::class);

        $this->service->criarEventoCalendario($anoLectivo, $request);

        return redirect()->back()->with('success', 'Evento de calendário criado com sucesso.');
    }

    public function update(AtualizarEventoCalendarioRequest $request, EventoCalendario $evento)
    {
        $this->authorize('update', EventoCalendario::class);

        $this->service->atualizarEventoCalendario($evento, $request);

        return redirect()->back()->with('success', 'Evento de calendário atualizado com sucesso.');
    }

    public function destroy(EventoCalendario $evento)
    {
        $this->authorize('delete', EventoCalendario::class);

        $this->service->eliminarEventoCalendario($evento);

        return redirect()->back()->with('success', 'Evento de calendário eliminado com sucesso.');
    }
}
