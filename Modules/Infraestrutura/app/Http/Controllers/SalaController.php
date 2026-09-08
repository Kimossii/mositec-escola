<?php

namespace Modules\Infraestrutura\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Modules\Infraestrutura\Enums\EstadoSala;
use Modules\Infraestrutura\Http\Requests\AlterarEstadoSalaRequest;
use Modules\Infraestrutura\Http\Requests\AtualizarSalaRequest;
use Modules\Infraestrutura\Http\Requests\CriarSalaRequest;
use Modules\Infraestrutura\Models\Sala;
use Modules\Infraestrutura\Services\GestaoSalaService;
use Modules\Infraestrutura\Services\SalaConsultaService;

class SalaController extends Controller
{
    public function __construct(
        private GestaoSalaService $service,
        private SalaConsultaService $consulta,
    ) {}

    public function index()
    {
        $this->authorize('infraestrutura.ver');

        return Inertia::render('Infraestrutura/Salas/Index', [
            'salas' => $this->consulta->listar(),
        ]);
    }

    public function show(Sala $sala)
    {
        $this->authorize('infraestrutura.ver');

        return Inertia::render('Infraestrutura/Salas/Show', [
            'sala' => $sala,
        ]);
    }

    public function store(CriarSalaRequest $request)
    {
        $this->authorize('infraestrutura.criar');

        $this->service->criar($request);

        return redirect()->back()->with('success', 'Sala criada com sucesso.');
    }

    public function update(AtualizarSalaRequest $request, Sala $sala)
    {
        $this->authorize('infraestrutura.editar');

        $this->service->atualizar($sala, $request);

        return redirect()->back()->with('success', 'Sala atualizada com sucesso.');
    }

    public function alterarEstado(AlterarEstadoSalaRequest $request, Sala $sala)
    {
        $this->authorize('infraestrutura.editar');

        $this->service->alterarEstado($sala, EstadoSala::from((int) $request->validated('estado')));

        return redirect()->back()->with('success', 'Estado da sala atualizado com sucesso.');
    }

    public function destroy(Sala $sala)
    {
        $this->authorize('infraestrutura.eliminar');

        $this->service->eliminar($sala);

        return redirect()->back()->with('success', 'Sala eliminada com sucesso.');
    }
}
