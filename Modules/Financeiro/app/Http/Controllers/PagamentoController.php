<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Estabelecimento\Services\RelogioDoTenant;
use Modules\Financeiro\Actions\AnularPagamentoAction;
use Modules\Financeiro\Actions\AnexarComprovativoAction;
use Modules\Financeiro\Actions\RegistarPagamentoAction;
use Modules\Financeiro\DTO\MotivoDeAnulacaoDTO;
use Modules\Financeiro\DTO\RegistarPagamentoDTO;
use Modules\Financeiro\Http\Requests\AnularPagamentoRequest;
use Modules\Financeiro\Http\Requests\AnexarComprovativoRequest;
use Modules\Financeiro\Http\Requests\PreVisualizarPagamentoRequest;
use Modules\Financeiro\Http\Requests\TotalDevidoRequest;
use Modules\Financeiro\Http\Requests\RegistarPagamentoRequest;
use Modules\Financeiro\Models\Pagamento;
use Modules\Financeiro\Models\Propina;
use Modules\Financeiro\Services\ComprovativoService;
use Modules\Financeiro\Services\DistribuicaoDePagamentoService;
use Modules\Financeiro\Services\MoedaDoTenant;
use Modules\Financeiro\Services\PagamentoConsultaService;
use Modules\Financeiro\Support\Moeda;
use Modules\Matricula\Models\Matricula;

class PagamentoController extends Controller
{
    public function __construct(
        private PagamentoConsultaService $consulta,
        private DistribuicaoDePagamentoService $distribuicao,
        private RegistarPagamentoAction $registar,
        private AnularPagamentoAction $anularAction,
        private AnexarComprovativoAction $anexarComprovativo,
        private ComprovativoService $comprovativo,
        private MoedaDoTenant $moedaDoTenant,
        private RelogioDoTenant $relogio,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('pagamento.ver');

        $filtros = $request->only(['pesquisa', 'metodo_pagamento_id', 'estado', 'data_de', 'data_ate', 'referencia', 'aluno_id', 'matricula_id']);

        return Inertia::render('Financeiro/Pagamentos/Index', [
            'pagamentos' => $this->consulta->listar($filtros),
            'indicadores' => Inertia::defer(fn () => $this->consulta->indicadores($filtros)),
            'filtros' => $filtros,
            'metodos' => $this->consulta->metodos(),
            'estados' => $this->consulta->estados(),
            'moeda' => $this->moedaDoTenant->atual()->paraFrontend(),
            'podeRegistar' => $request->user()?->can('pagamento.criar') ?? false,
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('pagamento.criar');

        return Inertia::render('Financeiro/Pagamentos/Create', [
            'metodos' => $this->consulta->metodosActivos(),
            'moeda' => $this->moedaDoTenant->atual()->paraFrontend(),
            'hoje' => $this->relogio->hoje()->toDateString(),
            // Para onde voltar (ex.: a ficha do aluno): lista branca: só a ficha de um aluno (sem barras duplas nem barras invertidas, logo sem redireccionamento externo).
            'voltarPara' => preg_match('#^/alunos/\d+$#D', (string) $request->query('voltar')) === 1 ? (string) $request->query('voltar') : null,
            'matriculaInicial' => $request->filled('matricula') ? $this->consulta->resumoDaMatricula($request->integer('matricula')) : null,
        ]);
    }

    public function pesquisarMatriculas(Request $request): JsonResponse
    {
        $this->authorize('pagamento.criar');

        return response()->json(['matriculas' => $this->consulta->pesquisarMatriculas((string) $request->query('q', ''))]);
    }

    public function situacao(Matricula $matricula): JsonResponse
    {
        $this->authorize('pagamento.criar');

        return response()->json(['situacao' => $this->consulta->situacao($matricula)]);
    }

    public function totalDevido(TotalDevidoRequest $request, Matricula $matricula): JsonResponse
    {
        $this->authorize('pagamento.criar');

        $ateAno = $request->filled('ate_ano') ? $request->integer('ate_ano') : null;
        $ateMes = $request->filled('ate_mes') ? $request->integer('ate_mes') : null;

        return response()->json(['total_devido' => $this->distribuicao->totalDevido($matricula, $ateAno, $ateMes)]);
    }

    public function preVisualizar(PreVisualizarPagamentoRequest $request, Matricula $matricula): JsonResponse
    {
        $this->authorize('pagamento.criar');

        $dto = RegistarPagamentoDTO::fromRequest($request);
        $aviso = $this->distribuicao->avisoDeReferencia($dto->metodoPagamentoId, $dto->referencia); // O1: não bloqueia

        return response()->json([
            'plano' => $this->distribuicao->preVisualizarPagamento($matricula, $dto)->toArray(),
            'avisos' => $aviso === null ? [] : [$aviso],
        ]);
    }

    public function store(RegistarPagamentoRequest $request, Matricula $matricula)
    {
        $this->authorize('pagamento.criar');

        $dto = RegistarPagamentoDTO::fromRequest($request);
        $aviso = $this->distribuicao->avisoDeReferencia($dto->metodoPagamentoId, $dto->referencia); // O1: antes de registar; não bloqueia

        $pagamento = $this->registar->executar($matricula, $dto);

        $resposta = redirect()->route('financeiro.pagamentos.show', $pagamento->id)->with('success', "Pagamento n.º {$pagamento->numero} registado.");

        return $aviso === null ? $resposta : $resposta->with('warning', $aviso);
    }

    public function show(Request $request, Pagamento $pagamento)
    {
        $this->authorize('pagamento.ver');

        $detalhe = $this->consulta->detalhe($pagamento);
        $podeAnular = $request->user()?->can('pagamento.anular') ?? false;

        return Inertia::render('Financeiro/Pagamentos/Show', [
            'pagamento' => $detalhe,
            'recibo' => $this->consulta->recibo($pagamento),
            'moeda' => Moeda::de($pagamento->moeda)->paraFrontend(),
            // O servidor decide: permissão E pagamento anulável (o botão nunca aparece para um 422 certo).
            'podeAnular' => $podeAnular && $detalhe['anulacao_bloqueada'] === null,
            'podeAnularUtilizacoes' => $podeAnular,
        ]);
    }

    public function recibo(Pagamento $pagamento)
    {
        $this->authorize('pagamento.ver');

        return $this->reciboDoPagamento($pagamento, $this->consulta->recibo($pagamento), null);
    }

    public function reciboPropina(Pagamento $pagamento, Propina $propina)
    {
        $this->authorize('pagamento.ver');

        $recibo = $this->consulta->recibo($pagamento);
        $posicao = null;

        foreach ($recibo['periodos'] as $indice => $periodo) {
            if ($periodo['propina_id'] === (int) $propina->getKey()) {
                $posicao = $indice + 1;
                break;
            }
        }

        abort_if($posicao === null, 404);

        return $this->reciboDoPagamento($pagamento, $recibo, $posicao);
    }

    /** @param array<string, mixed> $recibo */
    private function reciboDoPagamento(Pagamento $pagamento, array $recibo, ?int $posicao)
    {
        $numero = $pagamento->numero;
        $rotulo = 'Recibo geral';
        $propinaId = null;

        if ($posicao !== null) {
            $periodo = $recibo['periodos'][$posicao - 1];
            // Com um só período, o recibo do mês é o próprio recibo do pagamento: sem sufixo.
            $numero = count($recibo['periodos']) > 1 ? "{$pagamento->numero}/{$posicao}" : $pagamento->numero;
            $rotulo = "Recibo do mês {$periodo['periodo']}";
            $propinaId = $periodo['propina_id'];
        }

        return Inertia::render('Financeiro/Pagamentos/Recibo', [
            'pagamento' => $this->consulta->detalhe($pagamento),
            'recibo' => $recibo,
            'moeda' => Moeda::de($pagamento->moeda)->paraFrontend(),
            'escola' => $this->consulta->escola(),
            'aluno' => $this->consulta->alunoDoRecibo($pagamento),
            'numero' => $numero,
            'rotulo' => $rotulo,
            'propinaId' => $propinaId,
        ]);
    }

    public function anular(AnularPagamentoRequest $request, Pagamento $pagamento)
    {
        $this->authorize('pagamento.anular');

        $this->anularAction->executar($pagamento, MotivoDeAnulacaoDTO::fromRequest($request), $request->user()?->id);

        return redirect()->back()->with('success', 'Pagamento anulado.');
    }

    public function anexarComprovativo(AnexarComprovativoRequest $request, Pagamento $pagamento)
    {
        $this->authorize('pagamento.criar');

        $this->anexarComprovativo->executar($pagamento, $request->file('comprovativo'));

        return redirect()->back()->with('success', 'Comprovativo anexado.');
    }

    public function comprovativo(Pagamento $pagamento)
    {
        $this->authorize('pagamento.ver');

        return $this->comprovativo->visualizar($pagamento);
    }
}
