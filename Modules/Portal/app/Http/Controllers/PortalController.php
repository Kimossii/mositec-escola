<?php

namespace Modules\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Portal\Services\EducandosDoEncarregadoService;
use Modules\Portal\Services\PortalInicioService;

class PortalController extends Controller
{
    public function __construct(
        private EducandosDoEncarregadoService $educandos,
        private PortalInicioService $inicio,
    ) {
    }

    public function index(Request $request): RedirectResponse|Response
    {
        $lista = $this->educandos->listar($request->user());

        if ($lista->count() === 1) {
            return redirect()->route('portal.educando', $lista->first()->id);
        }

        return Inertia::render('Portal/Inicio', $this->inicio->dados($request->user(), null));
    }

    public function show(Request $request): Response
    {
        return Inertia::render('Portal/Inicio', $this->inicio->dados($request->user(), $request->attributes->get('educando')));
    }
}
