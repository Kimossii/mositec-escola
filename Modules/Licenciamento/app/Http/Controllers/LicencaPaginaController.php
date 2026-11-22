<?php

namespace Modules\Licenciamento\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Modules\Core\Tenancy\TenantContext;
use Modules\Licenciamento\Actions\InstalarLicencaAction;
use Modules\Licenciamento\Exceptions\LicencaRecusada;
use Modules\Licenciamento\Http\Requests\InstalarLicencaRequest;
use Modules\Licenciamento\Services\EstadoDaLicencaService;

class LicencaPaginaController extends Controller
{
    public function index(EstadoDaLicencaService $estado): Response
    {
        return Inertia::render('Licenciamento/Licenca/Index', ['licenca_estado' => $estado->obter()]);
    }

    public function instalar(InstalarLicencaRequest $request, InstalarLicencaAction $instalar, TenantContext $contexto): RedirectResponse
    {
        try {
            $instalar->executar($contexto->id(), $contexto->atual()->codigo, $request->token());
        } catch (LicencaRecusada $recusada) {
            return back()->withErrors(['token' => $recusada->getMessage()]);
        }

        return redirect()->route('licenca.index')->with('success', 'Licença instalada com sucesso.');
    }
}
