<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Modules\Financeiro\Enums\TipoMulta;
use Modules\Financeiro\Http\Requests\AtualizarRegraCobrancaRequest;
use Modules\Financeiro\Services\GestaoRegraCobrancaService;
use Modules\Financeiro\Support\Multa;

class RegraCobrancaController extends Controller
{
    public function __construct(
        private GestaoRegraCobrancaService $service,
    ) {
    }

    public function show()
    {
        $this->authorize('regra-cobranca.ver');

        $regra = $this->service->obterAtual();

        return Inertia::render('Financeiro/RegrasCobranca/Edit', [
            'regra' => $regra,
            'escaloes' => $this->service->escaloesParaEdicao($regra),
            'moeda' => $this->service->moeda(),
            'tiposMulta' => TipoMulta::opcoes(),
            'maxEscaloes' => Multa::MAX_ESCALOES,
        ]);
    }

    public function update(AtualizarRegraCobrancaRequest $request)
    {
        $this->authorize('regra-cobranca.editar');

        $this->service->atualizar($request);

        return redirect()->back()->with('success', 'Regras de cobrança atualizadas com sucesso.');
    }
}
