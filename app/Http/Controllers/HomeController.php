<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Financeiro\Services\MoedaDoTenant;

class HomeController extends Controller
{
    public function index(Request $request, DashboardService $dashboard, MoedaDoTenant $moeda)
    {
        $utilizador = $request->user();

        return Inertia::render('Dashboard/Index', [
            'moeda' => $moeda->atual()->paraFrontend(),
            'indicadores' => Inertia::defer(fn () => ['cartoes' => $dashboard->cartoes($utilizador)]),
            'atencao' => Inertia::defer(fn () => $dashboard->atencao($utilizador)),
            'resumo' => Inertia::defer(fn () => $dashboard->resumo($utilizador)),
            'risco' => Inertia::defer(fn () => $dashboard->risco($utilizador)),
            'previsao' => Inertia::defer(fn () => $dashboard->previsao($utilizador)),
            'calendario' => Inertia::defer(fn () => $dashboard->calendario($utilizador)),
            'graficos' => Inertia::defer(fn () => $dashboard->graficos($utilizador)),
            'ultimosPagamentos' => Inertia::defer(fn () => $dashboard->ultimosPagamentos($utilizador)),
        ]);
    }

    /** Navegação do calendário de vencimentos: um mês `YYYY-MM` (entre 5 anos atrás e 5 anos à frente). */
    public function calendario(Request $request, DashboardService $dashboard): JsonResponse
    {
        $dados = $request->validate([
            'mes' => ['required', 'date_format:Y-m', 'after_or_equal:'.now()->subYears(5)->format('Y-m'), 'before_or_equal:'.now()->addYears(5)->format('Y-m')],
        ]);

        $calendario = $dashboard->calendario($request->user(), $dados['mes']);

        abort_if($calendario === null, 403);

        return response()->json($calendario);
    }
}
