<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Modules\Financeiro\Http\Requests\AtualizarConfiguracaoMonetariaRequest;
use Modules\Financeiro\Http\Requests\RegistarCambioRequest;
use Modules\Financeiro\Models\Cambio;
use Modules\Financeiro\Services\GestaoMoedaCambioService;
use Modules\Financeiro\Services\MoedaCambioConsultaService;

class MoedaCambioController extends Controller
{
    public function __construct(
        private GestaoMoedaCambioService $service,
        private MoedaCambioConsultaService $consulta,
    ) {
    }

    public function show()
    {
        $this->authorize('moeda-cambio.ver');

        return Inertia::render('Financeiro/MoedaCambio/Edit', [
            'configuracao' => $this->consulta->configuracao(),
            'moeda' => $this->consulta->moeda(),
            'moedas' => $this->consulta->moedas(),
            'cambioVigente' => $this->consulta->cambioVigente(),
            'historico' => $this->consulta->historico(),
        ]);
    }

    public function atualizar(AtualizarConfiguracaoMonetariaRequest $request)
    {
        $this->authorize('moeda-cambio.editar');

        $this->service->atualizar($request);

        return redirect()->back()->with('success', 'Moeda e câmbio atualizados com sucesso.');
    }

    public function registarCambio(RegistarCambioRequest $request)
    {
        $this->authorize('moeda-cambio.criar');

        $this->service->registarCambio($request);

        return redirect()->back()->with('success', 'Câmbio registado com sucesso.');
    }

    public function eliminarCambio(Cambio $cambio)
    {
        $this->authorize('moeda-cambio.eliminar');

        $this->service->eliminarCambio($cambio);

        return redirect()->back()->with('success', 'Câmbio eliminado com sucesso.');
    }
}
