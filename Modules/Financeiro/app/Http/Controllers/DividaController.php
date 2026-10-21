<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Financeiro\Services\DividasConsultaService;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Turma\Models\Turma;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DividaController extends Controller
{
    public function __construct(private DividasConsultaService $consulta, private MoedaDoTenant $moeda)
    {
    }

    public function index(Request $request)
    {
        $this->authorize('propina.ver');

        $filtros = $request->only(['pesquisa', 'ano_lectivo_id', 'turma_id', 'tipo']);

        return Inertia::render('Financeiro/Dividas/Index', $this->consulta->listar($filtros) + [
            'filtros' => $filtros,
            'anosLectivos' => AnoLectivo::query()->orderByDesc('data_inicio')->get(['id', 'nome']),
            'turmas' => Turma::query()->orderBy('nome')->get(['id', 'nome', 'ano_lectivo_id']),
            'moeda' => $this->moeda->atual()->paraFrontend(),
        ]);
    }

    public function exportar(Request $request): StreamedResponse
    {
        $this->authorize('pagamento.exportar');

        $linhas = $this->consulta->linhas($request->only(['pesquisa', 'ano_lectivo_id', 'turma_id', 'tipo']));

        return response()->streamDownload(function () use ($linhas) {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF");
            fputcsv($f, ['Aluno', 'Nº aluno', 'Matrícula', 'Turma', 'Ano lectivo', 'Propinas', 'Multas', 'Produtos/serviços', 'Total', 'Meses em dívida', 'Em dívida desde'], ';');
            foreach ($linhas as $l) {
                fputcsv($f, [$l['aluno_nome'], $l['aluno_numero'], $l['matricula_numero'], $l['turma_nome'], $l['ano_lectivo_nome'], $l['propinas'], $l['multas'], $l['cobrancas'], $l['total'], $l['meses'], $l['desde']], ';');
            }
            fclose($f);
        }, 'dividas.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
