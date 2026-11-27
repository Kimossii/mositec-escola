<?php

namespace Modules\Plataforma\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Modules\Plataforma\Actions\ForcarRegressoACentralAction;
use Modules\Plataforma\Actions\LevantarBloqueioDeEmailAction;
use Modules\Tenant\Models\Tenant;

/** Email de uma escola visto pela Plataforma: a medida de emergência de a mandar de volta à central (com bloqueio) e o seu levantamento. */
class EmailEscolaController extends Controller
{
    public function regressoACentral(Tenant $tenant, ForcarRegressoACentralAction $forcar): RedirectResponse
    {
        $forcar->executar($tenant, request()->user('plataforma'));

        return redirect()->route('plataforma.escolas.show', $tenant->codigo)->with('success', 'A escola voltou a enviar emails pela central e ficou bloqueada de reactivar o servidor próprio.');
    }

    public function levantarBloqueio(Tenant $tenant, LevantarBloqueioDeEmailAction $levantar): RedirectResponse
    {
        $levantar->executar($tenant, request()->user('plataforma'));

        return redirect()->route('plataforma.escolas.show', $tenant->codigo)->with('success', 'Bloqueio levantado. A escola pode voltar a testar o seu servidor.');
    }
}
