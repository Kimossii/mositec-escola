<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Core\Enums\Estado;
use Modules\Financeiro\Enums\TipoMetodoPagamento;
use Modules\Financeiro\Http\Requests\AlterarEstadoMetodoPagamentoRequest;
use Modules\Financeiro\Http\Requests\AtualizarMetodoPagamentoRequest;
use Modules\Financeiro\Http\Requests\CriarMetodoPagamentoRequest;
use Modules\Financeiro\Models\MetodoPagamento;
use Modules\Financeiro\Services\GestaoMetodoPagamentoService;
use Modules\Financeiro\Services\MetodoPagamentoConsultaService;

class MetodoPagamentoController extends Controller
{
    public function __construct(
        private GestaoMetodoPagamentoService $service,
        private MetodoPagamentoConsultaService $consulta,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorize('metodo-pagamento.ver');

        $filtros = $request->only(['pesquisa', 'estado']);

        return Inertia::render('Financeiro/MetodosPagamento/Index', [
            'metodos' => $this->consulta->listar($filtros),
            'filtros' => $filtros,
            'tipos' => TipoMetodoPagamento::opcoes(),
        ]);
    }

    public function store(CriarMetodoPagamentoRequest $request)
    {
        $this->authorize('metodo-pagamento.criar');

        $this->service->criar($request);

        return redirect()->back()->with('success', 'Método de pagamento criado com sucesso.');
    }

    public function update(AtualizarMetodoPagamentoRequest $request, MetodoPagamento $metodo)
    {
        $this->authorize('metodo-pagamento.editar');

        $this->service->atualizar($metodo, $request);

        return redirect()->back()->with('success', 'Método de pagamento atualizado com sucesso.');
    }

    public function alterarEstado(AlterarEstadoMetodoPagamentoRequest $request, MetodoPagamento $metodo)
    {
        $this->authorize('metodo-pagamento.editar');

        $this->service->alterarEstado($metodo, Estado::from((int) $request->validated('estado')));

        return redirect()->back()->with('success', 'Estado do método de pagamento atualizado com sucesso.');
    }

    public function destroy(MetodoPagamento $metodo)
    {
        $this->authorize('metodo-pagamento.eliminar');

        $this->service->eliminar($metodo);

        return redirect()->back()->with('success', 'Método de pagamento eliminado com sucesso.');
    }
}
