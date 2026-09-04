<?php

namespace Modules\Core\Http\Controllers\Horario;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Modules\Core\Http\Requests\Horario\AtualizarHorarioRequest;
use Modules\Core\Http\Requests\Horario\CriarHorarioRequest;
use Modules\Core\Models\Horario;
use Modules\Core\Services\GestaoHorarioService;
use Modules\Core\Services\HorarioConsultaService;

class HorarioController extends Controller
{
    public function __construct(
        private GestaoHorarioService $service,
        private HorarioConsultaService $consulta,
    ) {}

    public function index()
    {
        $this->authorize('horario.ver');

        return Inertia::render('Core/Horario/Index', [
            'horarios' => $this->consulta->listar(),
        ]);
    }

    public function store(CriarHorarioRequest $request)
    {
        $this->authorize('horario.criar');

        $this->service->criar($request);

        return redirect()->back()->with('success', 'Horário criado com sucesso.');
    }

    public function update(AtualizarHorarioRequest $request, Horario $horario)
    {
        $this->authorize('horario.editar');

        $this->service->atualizar($horario, $request);

        return redirect()->back()->with('success', 'Horário atualizado com sucesso.');
    }

    public function destroy(Horario $horario)
    {
        $this->authorize('horario.eliminar');

        $this->service->eliminar($horario);

        return redirect()->back()->with('success', 'Horário eliminado com sucesso.');
    }
}
