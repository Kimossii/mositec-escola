<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Modules\Financeiro\Http\Requests\AtualizarRegraCobrancaRequest;
use Modules\Financeiro\Services\GestaoRegraCobrancaService;

class RegraCobrancaController extends Controller
{
    public function __construct(
        private GestaoRegraCobrancaService $service,
    ) {
    }

    public function show()
    {
        $this->authorize('regra-cobranca.ver');

        return Inertia::render('Financeiro/RegrasCobranca/Edit', [
            'regra' => $this->service->obterAtual(),
        ]);
    }

    public function update(AtualizarRegraCobrancaRequest $request)
    {
        $this->authorize('regra-cobranca.editar');

        $this->service->atualizar($request);

        return redirect()->back()->with('success', 'Regras de cobrança atualizadas com sucesso.');
    }
}
