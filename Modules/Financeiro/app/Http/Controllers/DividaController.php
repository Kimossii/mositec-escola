<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\AnoLectivo\Models\AnoLectivo;
use Modules\Core\Exportacao\Exportador;
use Modules\Core\Exportacao\TabelaExportavel;
use Modules\Estabelecimento\Models\Estabelecimento;
use Modules\Financeiro\Services\DividasConsultaService;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Turma\Models\Turma;

class DividaController extends Controller
{
    public function __construct(private DividasConsultaService $consulta, private MoedaDoTenant $moeda)
    {
    }

    public function index(Request $request)
    {
        $this->authorize('propina.ver');

        $filtros = $request->only(['pesquisa', 'ano_lectivo_id', 'turma_id', 'tipo']);

        return Inertia::render('Financeiro/Dividas/Index', [
            'dividas' => $this->consulta->listar($filtros),
            // Diferido: os totais agregam todas as dívidas e preenchem-se depois de a lista abrir.
            'totais' => Inertia::defer(fn () => $this->consulta->totais($filtros)),
            'filtros' => $filtros,
            'anosLectivos' => AnoLectivo::query()->orderByDesc('data_inicio')->get(['id', 'nome']),
            'turmas' => Turma::query()->with(['turno:id,nome', 'anoLectivo:id,nome'])->orderBy('nome')->get(['id', 'nome', 'ano_lectivo_id', 'turno_id'])
                ->each(fn (Turma $turma) => $turma->setAttribute('rotulo', $turma->rotulo(completo: true))->makeHidden(['turno', 'ano_lectivo'])),
            'moeda' => $this->moeda->atual()->paraFrontend(),
        ]);
    }

    public function exportar(Request $request, Exportador $exportador)
    {
        $this->authorize('pagamento.exportar');

        $request->validate(['formato' => ['required', 'string']]);
        $moeda = $this->moeda->atual();
        $maior = fn (int $v): float => $v / $moeda->fator();
        $linhas = $this->consulta->linhas($request->only(['pesquisa', 'ano_lectivo_id', 'turma_id', 'tipo']))
            ->map(fn (array $l) => [
                'aluno' => $l['aluno_nome'], 'numero' => $l['aluno_numero'], 'matricula' => $l['matricula_numero'], 'turma' => $l['turma_rotulo_completo'], 'ano' => $l['ano_lectivo_nome'],
                'propinas' => $maior($l['propinas']), 'multas' => $maior($l['multas']), 'cobrancas' => $maior($l['cobrancas']), 'total' => $maior($l['total']),
                'meses' => $l['meses'], 'desde' => $l['desde'],
            ]);

        $tabela = new TabelaExportavel(
            titulo: 'Dívidas',
            colunas: [
                'aluno' => 'Aluno', 'numero' => 'Nº aluno', 'matricula' => 'Matrícula', 'turma' => 'Turma', 'ano' => 'Ano lectivo',
                'propinas' => "Propinas ({$moeda->simbolo})", 'multas' => "Multas ({$moeda->simbolo})", 'cobrancas' => "Produtos/serviços ({$moeda->simbolo})", 'total' => "Total ({$moeda->simbolo})",
                'meses' => 'Meses em dívida', 'desde' => 'Em dívida desde',
            ],
            linhas: $linhas->all(),
            subtitulo: 'Da maior dívida para a menor',
            entidade: Estabelecimento::current()?->nome,
            assinaturas: ['Elaborado por', 'Verificado por', 'O Director'],
        );

        return $exportador->responder($tabela, $request->query('formato'), 'dividas', 'pagamento.exportar');
    }
}
