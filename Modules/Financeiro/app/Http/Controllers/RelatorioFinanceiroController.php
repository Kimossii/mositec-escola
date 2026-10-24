<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Core\Exportacao\Exportador;
use Modules\Core\Exportacao\TabelaExportavel;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Services\RelatorioFinanceiroService;

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
            // Diferido: o relatório faz agregações pesadas; a página abre logo e os números chegam a seguir.
            'relatorio' => Inertia::defer(fn () => $this->relatorio->gerar($de, $ate)),
            'periodo' => ['de' => $de, 'ate' => $ate],
            'moeda' => $this->moeda->atual()->paraFrontend(),
        ]);
    }

    public function exportarRecebimentos(Request $request, Exportador $exportador)
    {
        $this->authorize('pagamento.exportar');

        $request->validate(['formato' => ['required', 'string']]);
        [$de, $ate] = $this->periodo($request);
        $moeda = $this->moeda->atual();
        $linhas = array_map(fn (array $l) => [...$l, 'valor' => $l['valor'] / $moeda->fator()], $this->relatorio->recebimentos($de, $ate));

        $tabela = new TabelaExportavel(
            titulo: 'Recebimentos',
            colunas: ['numero' => 'Nº', 'data' => 'Data', 'aluno' => 'Aluno', 'numero_aluno' => 'Nº aluno', 'matricula' => 'Matrícula', 'metodo' => 'Método', 'referencia' => 'Referência', 'valor' => "Valor ({$moeda->simbolo})"],
            linhas: $linhas,
            subtitulo: "Período {$de} a {$ate} · só pagamentos confirmados",
            entidade: Estabelecimento::current()?->nome,
        );

        return $exportador->responder($tabela, $request->query('formato'), "recebimentos-{$de}-{$ate}");
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
