<?php

namespace Modules\Autenticacao\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Autenticacao\Actions\ConfirmarEmailAction;
use Modules\Autenticacao\Actions\EnviarConfirmacaoDeEmailAction;
use Modules\Autenticacao\Exceptions\ConfirmacaoDeEmailRecusada;

class ConfirmacaoDeEmailController extends Controller
{
    /** O utilizador (autenticado) pede que lhe enviem a ligação de confirmação do seu email de acesso. */
    public function pedir(Request $request, EnviarConfirmacaoDeEmailAction $acao): RedirectResponse
    {
        try {
            $acao->executar($request->user());
        } catch (ConfirmacaoDeEmailRecusada $e) {
            return back()->withErrors(['email' => $e->getMessage()]);
        }

        return back();
    }

    /** Destino da ligação do email. Não exige sessão (abre-se noutro aparelho); a assinatura é verificada pelo middleware. */
    public function confirmar(int $id, string $hash, ConfirmarEmailAction $acao): Response
    {
        // Página pública (sem barra lateral nem cabeçalho da aplicação): vai no layout de visitante.
        return Inertia::render('Autenticacao/EmailConfirmado', [
            'resultado' => $acao->executar($id, $hash)->value,
        ])->rootView('layouts.guest');
    }
}
