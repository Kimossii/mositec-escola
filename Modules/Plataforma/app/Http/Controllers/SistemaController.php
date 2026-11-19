<?php

namespace Modules\Plataforma\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Modules\Plataforma\Actions\ExecutarVerificacoesDeSaudeAction;
use Modules\Plataforma\Services\SistemaConsultaService;

/** Estado técnico do sistema: saúde, backups e filas. Sem contexto de escola. */
class SistemaController extends Controller
{
    public function index(SistemaConsultaService $sistema)
    {
        return Inertia::render('Plataforma/Sistema', [
            'saude' => $sistema->saude(),
            'backups' => $sistema->backups(),
            'filas' => $sistema->filas(),
        ]);
    }

    public function verificar(ExecutarVerificacoesDeSaudeAction $verificacoes): RedirectResponse
    {
        $verificacoes->executar();

        return redirect()->route('plataforma.sistema.index')->with('success', 'Verificações concluídas.');
    }
}
