<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Services\RelatorioFinanceiroService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RelatorioFinanceiroController extends Controller
{
    public function __construct(private RelatorioFinanceiroService $relatorio, private MoedaDoTenant $moeda, private RelogioDoTenant $relogio)
    {
    }

    public function index(Request $request)
    {
        $this->authorize('pagamento.exportar');

        [$de, $ate] = $this->periodo($request);

        return Inertia::render('Financeiro/Relatorios/Index', [
            'relatorio' => $this->relatorio->gerar($de, $ate),
            'moeda' => $this->moeda->atual()->paraFrontend(),
        ]);
    }

    public function exportarRecebimentos(Request $request): StreamedResponse
    {
        $this->authorize('pagamento.exportar');

        [$de, $ate] = $this->periodo($request);
        $linhas = $this->relatorio->recebimentosCsv($de, $ate);

        return response()->streamDownload(function () use ($linhas) {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF");
            foreach ($linhas as $linha) {
                fputcsv($f, $linha, ';');
            }
            fclose($f);
        }, "recebimentos-{$de}-{$ate}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{0: string, 1: string} início do mês corrente → hoje, por omissão */
    private function periodo(Request $request): array
    {
        $hoje = $this->relogio->hoje();
        $de = preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string) $request->query('de')) === 1 ? (string) $request->query('de') : $hoje->startOfMonth()->toDateString();
        $ate = preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string) $request->query('ate')) === 1 ? (string) $request->query('ate') : $hoje->toDateString();

        return $de <= $ate ? [$de, $ate] : [$ate, $de];
    }
}
