<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\Core\Enums\Estado;
use Modules\Financeiro\Http\Requests\AlterarEstadoServicoRequest;
use Modules\Financeiro\Http\Requests\AtualizarServicoRequest;
use Modules\Financeiro\Http\Requests\CriarServicoRequest;
use Modules\Financeiro\Models\Servico;
use Modules\Financeiro\Services\GestaoServicoService;

class ServicoController extends Controller
{
    public function __construct(
        private GestaoServicoService $service,
    ) {
    }

    public function store(CriarServicoRequest $request)
    {
        $this->authorize('catalogo-financeiro.criar');

        $this->service->criar($request);

        return redirect()->back()->with('success', 'Serviço criado com sucesso.');
    }

    public function update(AtualizarServicoRequest $request, Servico $servico)
    {
        $this->authorize('catalogo-financeiro.editar');

        $this->service->atualizar($servico, $request);

        return redirect()->back()->with('success', 'Serviço atualizado com sucesso.');
    }

    public function alterarEstado(AlterarEstadoServicoRequest $request, Servico $servico)
    {
        $this->authorize('catalogo-financeiro.editar');

        $this->service->alterarEstado($servico, Estado::from((int) $request->validated('estado')));

        return redirect()->back()->with('success', 'Estado do serviço atualizado com sucesso.');
    }

    public function destroy(Servico $servico)
    {
        $this->authorize('catalogo-financeiro.eliminar');

        $this->service->eliminar($servico);

        return redirect()->back()->with('success', 'Serviço eliminado com sucesso.');
    }
}
