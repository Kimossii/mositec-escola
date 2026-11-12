<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Core\Exportacao\Exportador;
use Modules\Financeiro\Http\Requests\ExportarRecebimentosRequest;
use Modules\Financeiro\Services\ExportacaoDeRecebimentosService;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Services\RelatorioFinanceiroService;

class RelatorioFinanceiroController extends Controller
{
    public function __construct(private RelatorioFinanceiroService $relatorio, private MoedaDoTenant $moeda)
    {
    }

    public function index(Request $request)
    {
        $this->authorize('pagamento.exportar');

        [$de, $ate] = $this->relatorio->periodo($request->query('de'), $request->query('ate'));

        return Inertia::render('Financeiro/Relatorios/Index', [
            // Diferido: o relatório faz agregações pesadas; a página abre logo e os números chegam a seguir.
            'relatorio' => Inertia::defer(fn () => $this->relatorio->gerar($de, $ate)),
            'periodo' => ['de' => $de, 'ate' => $ate],
            'moeda' => $this->moeda->atual()->paraFrontend(),
        ]);
    }

    public function exportarRecebimentos(ExportarRecebimentosRequest $request, Exportador $exportador, ExportacaoDeRecebimentosService $exportacao)
    {
        [$de, $ate] = $this->relatorio->periodo($request->query('de'), $request->query('ate'));

        return $exportador->responder($exportacao->tabela($de, $ate), $request->validated()['formato'], "recebimentos-{$de}-{$ate}", 'pagamento.exportar');
    }
}
