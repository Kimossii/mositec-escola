<?php

namespace Modules\Financeiro\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Financeiro\Services\VerificacaoDeReciboService;

/** Página PÚBLICA (sem login) para quem lê o QR de um recibo: confirma que é autêntico e mostra o mínimo necessário. */
class VerificacaoDeReciboController extends Controller
{
    public function __construct(private VerificacaoDeReciboService $verificacao)
    {
    }

    public function show(Request $request, string $token)
    {
        $pagina = $this->verificacao->pagina($token);

        $resposta = Inertia::render('Financeiro/Recibos/Verificar', $pagina['props'])->rootView('layouts.guest')->toResponse($request);

        // Um token inválido responde 404 (a página explica que o recibo não foi encontrado).
        return $pagina['encontrado'] ? $resposta : $resposta->setStatusCode(404);
    }
}
