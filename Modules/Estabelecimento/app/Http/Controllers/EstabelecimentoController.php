<?php

namespace Modules\Estabelecimento\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Modules\Estabelecimento\Http\Requests\AtualizarDadosRequest;
use Modules\Estabelecimento\Http\Requests\AtualizarLogotipoRequest;
use Modules\Estabelecimento\Services\GestaoEstabelecimentoService;

class EstabelecimentoController extends Controller
{
    public function __construct(
        private GestaoEstabelecimentoService $service,
    ) {}

    public function dados()
    {
        $this->authorize('estabelecimento.ver');

        return Inertia::render('Estabelecimento/DadosDaEscola', [
            'estabelecimento' => $this->service->obterAtual(),
        ]);
    }

    public function aparencia()
    {
        $this->authorize('estabelecimento.ver');

        return Inertia::render('Estabelecimento/Aparencia', [
            'estabelecimento' => $this->service->obterAtual(),
        ]);
    }

    public function update(AtualizarDadosRequest $request)
    {
        $this->authorize('estabelecimento.editar');

        $this->service->atualizarDados($request);

        return redirect()->back()->with('success', 'Dados do estabelecimento atualizados com sucesso.');
    }

    public function updateLogotipo(AtualizarLogotipoRequest $request)
    {
        $this->authorize('estabelecimento.editar');

        $this->service->atualizarLogotipo($request->file('logotipo'));

        return redirect()->back()->with('success', 'Logótipo atualizado com sucesso.');
    }
}
