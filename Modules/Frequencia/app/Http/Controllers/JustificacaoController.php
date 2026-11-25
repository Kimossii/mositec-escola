<?php

namespace Modules\Frequencia\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Frequencia\Http\Requests\JustificarFaltaRequest;
use Modules\Frequencia\Http\Requests\RemoverJustificacaoRequest;
use Modules\Frequencia\Models\Registo;
use Modules\Frequencia\Services\GestaoFrequenciaService;

class JustificacaoController extends Controller
{
    public function __construct(private GestaoFrequenciaService $service)
    {
    }

    public function store(JustificarFaltaRequest $request, Registo $registo)
    {
        $this->authorize('frequencia.justificar');

        $this->service->justificar($request->user(), $registo, $request->validated());

        return redirect()->back()->with('success', 'Falta justificada.');
    }

    public function destroy(RemoverJustificacaoRequest $request, Registo $registo)
    {
        $this->authorize('frequencia.justificar');

        $this->service->removerJustificacao($request->user(), $registo, $request->validated('motivo'));

        return redirect()->back()->with('success', 'Justificação retirada.');
    }
}
