<?php

namespace Modules\Core\Http\Controllers\Auditoria;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Modules\Core\Http\Requests\FiltrarAuditoriaRequest;
use Modules\Core\Services\AuditoriaTenantConsultaService;

class AuditoriaController extends Controller
{
    public function __construct(private AuditoriaTenantConsultaService $service)
    {
    }

    public function index(FiltrarAuditoriaRequest $request)
    {
        $this->authorize('auditoria.ver');
        $filtros = $request->filtros();

        return Inertia::render('Core/Auditoria/Index', [
            'registos' => $this->service->listar($filtros),
            'filtros' => $filtros,
        ]);
    }
}
