<?php

namespace Modules\Autenticacao\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Autenticacao\Actions\AtualizarFotoDoUtilizadorAction;
use Modules\Autenticacao\Actions\RemoverFotoDoUtilizadorAction;
use Modules\Autenticacao\Http\Requests\AtualizarFotoRequest;
use Modules\Autenticacao\Service\FotoDoUtilizadorService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FotoDoUtilizadorController extends Controller
{
    public function show(Request $request, FotoDoUtilizadorService $fotos): StreamedResponse
    {
        return $fotos->servir($request->user());
    }

    public function update(AtualizarFotoRequest $request, AtualizarFotoDoUtilizadorAction $acao)
    {
        $acao->executar($request->user(), $request->file('foto'));

        return back();
    }

    public function destroy(Request $request, RemoverFotoDoUtilizadorAction $acao)
    {
        $acao->executar($request->user());

        return back();
    }
}
