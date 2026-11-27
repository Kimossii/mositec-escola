<?php

namespace Modules\Core\Http\Controllers\Comunicacao;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Modules\Core\Http\Requests\FiltrarEnviosEmailRequest;
use Modules\Core\Services\EnviosEmailConsultaService;

class EnvioEmailController extends Controller
{
    public function __construct(private EnviosEmailConsultaService $service)
    {
    }

    public function index(FiltrarEnviosEmailRequest $request)
    {
        $this->authorize('envio-email.ver');
        $filtros = $request->filtros();

        return Inertia::render('Core/EnviosEmail/Index', [
            'registos' => $this->service->listar($filtros),
            'filtros' => $filtros,
            'resumo' => $this->service->resumo(),
        ]);
    }
}
